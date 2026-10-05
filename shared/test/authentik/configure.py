#!/usr/bin/env python3
"""Configures the dev Authentik instance to stand in for Keycloak against an Astrana Trusted Attestation implementation.

Authentik is another identity provider for the conformance suite. It is self-hosted, so no paid tenant, and
enough unlike Keycloak (a JavaScript sign-in flow, a different discovery layout, its own claim handling) to shake out any
assumption an implementation makes about one provider. Run it after `docker compose up`:

    python shared/test/authentik/configure.py

It is idempotent -- it replaces what it created on a previous run -- and needs only the bootstrap token the
compose file sets. It creates the four test members (alice/bob/carol/dave, password `password`), an OIDC
application, and a scope mapping that pins each member's `sub` to the fixed UUID the conformance fixtures
grant against (SUBJECTS in shared/test/conformance/check.py), so the same suite runs unchanged.

Pointing each implementation at it, then running `check.py ... --browser-login` (Authentik's sign-in is a
JavaScript app, so the built-in form login cannot drive it):

  .NET (host process, so localhost reaches Authentik directly):
    TrustedAttestation__Iam__Authority=http://localhost:9002/application/o/trusted-attestation
    TrustedAttestation__Iam__ClientId=trusted-attestation  ClientSecret=trusted-attestation-dev-secret

Two things Authentik does differently from Keycloak, both real deployment notes rather than bugs:
  - Its `sub` and issuer carry a trailing slash (http://localhost:9002/application/o/trusted-attestation/).
    All three implementations accept an issuer that differs from the provider's by a trailing slash.
  - It derives ALL its endpoint URLs from one request host, where Keycloak splits a fixed frontend
    (KC_HOSTNAME, for the browser) from a dynamic backchannel (KC_HOSTNAME_BACKCHANNEL_DYNAMIC, for the
    server). So a containerised implementation cannot have the browser reach localhost:9002 while it
    reaches host.docker.internal:9002 -- it needs one consistent host. In dev, forward localhost:9002
    into the container (a socat, as the Java gateway already does for Keycloak).

The suite's four Set-Cookie-attribute checks fail under --browser-login for ANY IdP (Keycloak included),
because the browser consumes the header -- a limit of observing through a browser, not an implementation
difference.
"""

import json
import urllib.error
import urllib.request

BASE = "http://localhost:9002/api/v3"
HEAD = {"Authorization": "Bearer authentik-bootstrap-token", "Content-Type": "application/json"}

CLIENT_ID = "trusted-attestation"
CLIENT_SECRET = "trusted-attestation-dev-secret"
REDIRECT_URIS = [
    "https://localhost:15443/signin-oidc",                 # .NET
    "https://localhost:16443/login/oauth2/code/keycloak",  # Java (Spring registration id is "keycloak")
    "https://localhost:17443/auth/callback",               # PHP
]

# The same identifiers the conformance suite grants relationships against, keyed by login username.
SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}

SUB_EXPRESSION = "return {\"sub\": %s.get(user.username, user.username)}" % json.dumps(SUBJECTS)


def call(method, path, body=None):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers=HEAD, method=method)
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:500]


def first(path):
    status, body = call("GET", path)
    return body["results"][0] if status == 200 and isinstance(body, dict) and body.get("results") else None


def main():
    auth_flow = first("/flows/instances/?slug=default-provider-authorization-implicit-consent")
    inval_flow = first("/flows/instances/?designation=invalidation")
    cert = first("/crypto/certificatekeypairs/?has_key=true")
    scopes = [s["pk"] for s in call("GET", "/propertymappings/provider/scope/?managed__isnull=false")[1].get("results", [])
              if s.get("scope_name") in ("openid", "email", "profile")]
    if not (auth_flow and inval_flow and cert):
        raise SystemExit("Authentik is missing its bootstrap defaults -- is it fully started?")

    # A scope mapping that pins sub to the fixture UUID for each member.
    for m in call("GET", "/propertymappings/provider/scope/?search=ata-sub")[1].get("results", []):
        if m["name"] == "ata-sub":
            call("DELETE", f"/propertymappings/provider/scope/{m['pk']}/")
    _, mapping = call("POST", "/propertymappings/provider/scope/",
                      {"name": "ata-sub", "scope_name": "openid",
                       "description": "pin sub to the conformance fixture UUIDs", "expression": SUB_EXPRESSION})

    # The OIDC provider, replacing any earlier one.
    existing = first("/providers/oauth2/?name=trusted-attestation")
    if existing:
        app = first("/core/applications/?slug=trusted-attestation")
        if app:
            call("DELETE", f"/core/applications/{app['slug']}/")
        call("DELETE", f"/providers/oauth2/{existing['pk']}/")
    status, provider = call("POST", "/providers/oauth2/", {
        "name": "trusted-attestation",
        "authorization_flow": auth_flow["pk"],
        "invalidation_flow": inval_flow["pk"],
        "client_type": "confidential",
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "redirect_uris": [{"matching_mode": "strict", "url": u} for u in REDIRECT_URIS],
        "sub_mode": "user_username",
        "signing_key": cert["pk"],
        "property_mappings": scopes + [mapping["pk"]],
        "access_token_validity": "minutes=10",
    })
    if status not in (200, 201):
        raise SystemExit(f"could not create provider: {status} {provider}")
    call("POST", "/core/applications/", {"name": "Trusted Attestation", "slug": "trusted-attestation",
                                         "provider": provider["pk"]})
    print(f"provider + application created (client_id={CLIENT_ID})")

    # The four test members.
    for username in SUBJECTS:
        status, user = call("POST", "/core/users/", {"username": username, "name": username.capitalize(),
                                                     "path": "users", "type": "internal"})
        pk = user["pk"] if status in (200, 201) else (first(f"/core/users/?username={username}") or {}).get("pk")
        if pk:
            call("POST", f"/core/users/{pk}/set_password/", {"password": "password"})
    print("members created: " + ", ".join(SUBJECTS))
    print("\nissuer: http://localhost:9002/application/o/trusted-attestation")


if __name__ == "__main__":
    main()
