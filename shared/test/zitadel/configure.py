#!/usr/bin/env python3
"""Configures the dev Zitadel instance to stand in for Keycloak against an Astrana Trusted Attestation implementation.

Zitadel is another identity provider for the conformance suite. It is self-hosted (Go, open source), so no
paid tenant, and different enough from Keycloak and Authentik (its own login UI, its own claim handling, snowflake user ids)
to shake out any assumption an implementation makes about one provider. Run it after `docker compose up`:

    python shared/test/zitadel/configure.py

It is idempotent -- it deletes what it created on a previous run and recreates it -- and needs only the
service-account token Zitadel wrote to ./machinekey/pat.txt on first start. It:

  - relaxes the password policy so the fixtures' password ("password") is allowed, and turns off
    login-name-must-include-domain so a member logs in as "alice" rather than "alice@<org>";
  - creates a project and a confidential OIDC web app with the three implementations' redirect URIs;
  - creates the four test members (alice/bob/carol/dave, password `password`), each with its Zitadel user
    id set to the fixed UUID the conformance fixtures grant against (SUBJECTS in shared/test/conformance/check.py) --
    Zitadel's `sub` is the user id, so this pins the subject the same way Authentik's scope mapping does.

Then it prints the issuer and the generated client id. The secret is not printed. With --credentials-file
PATH it is written to PATH, readable only by you, which is how the integration matrix reads it. Point each
implementation at it and run
`check.py ... --browser-login` (Zitadel's sign-in is a JavaScript app, so the built-in form login cannot
drive it):

  .NET (host process, so localhost reaches Zitadel directly):
    TrustedAttestation__Iam__Authority=http://localhost:9003
    TrustedAttestation__Iam__ClientId=<printed>  ClientSecret=<from the credentials file>

  Java (Spring): the issuer has NO trailing slash (http://localhost:9003), so set
    SPRING_SECURITY_OAUTH2_CLIENT_PROVIDER_KEYCLOAK_ISSUER_URI=http://localhost:9003 exactly.

Like Authentik, Zitadel derives all its endpoints from one request host, so a containerised implementation
needs localhost:9003 reachable from both the browser and the container -- forward it in (a socat, as the
Java gateway already does for Keycloak). The suite's four Set-Cookie-attribute checks fail under
--browser-login for any IdP (the browser consumes the header), not a Zitadel difference.
"""

import argparse
import json
import os
import pathlib
import urllib.error
import urllib.request

BASE = "http://localhost:9003"
PAT = (pathlib.Path(__file__).parent / "machinekey" / "pat.txt").read_text().strip()
HEAD = {"Authorization": f"Bearer {PAT}", "Content-Type": "application/json"}

PROJECT_NAME = "trusted-attestation"
REDIRECT_URIS = [
    "https://localhost:15443/signin-oidc",                 # .NET
    "https://localhost:16443/login/oauth2/code/keycloak",  # Java (Spring registration id is "keycloak")
    "https://localhost:17443/auth/callback",               # PHP
]
POST_LOGOUT_URIS = [
    "https://localhost:15443/signed-out",                  # .NET
    "https://localhost:16443/signed-out",                  # Java
    "https://localhost:17443/signed-out",                  # PHP
]

# The same identifiers the conformance suite grants relationships against, keyed by login username.
SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}


def call(method, path, body=None, expect=(200, 201)):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, headers=HEAD, method=method)
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:400]


def write_credentials(path, client_id, client_secret):
    """Writes the client id and secret to a file only the current user can read. The matrix passes a
    temporary file, reads it and removes it, so the secret never appears in this script's output."""
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.chmod(path, 0o600)  # the mode above applies only to a new file, and the matrix creates it first
    with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(f"client_id: {client_id}\nclient_secret: {client_secret}\n")


def report_client(client_id, client_secret, credentials_file):
    """Prints the client id and says where the secret went, without printing the secret itself."""
    print(f"client_id: {client_id}")
    if credentials_file:
        write_credentials(credentials_file, client_id, client_secret)
        print(f"client_secret: written to {credentials_file}")
    else:
        print("client_secret: not shown. Run with --credentials-file PATH to write it to a file only you can read.")


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--credentials-file", metavar="PATH",
                        help="write the OIDC client id and secret to PATH, readable only by you")
    return parser.parse_args()


def main():
    args = parse_args()

    # 1. Policies: allow the weak fixture password, and let members log in by bare username.
    call("PUT", "/admin/v1/policies/password/complexity",
         {"minLength": "1", "hasUppercase": False, "hasLowercase": False, "hasNumber": False, "hasSymbol": False})
    call("PUT", "/admin/v1/policies/domain",
         {"userLoginMustBeDomain": False, "validateOrgDomains": False,
          "smtpSenderAddressMatchesInstanceDomain": False})

    # 2. Project + OIDC app, replacing any from a previous run.
    _, existing = call("POST", "/management/v1/projects/_search", {})
    for p in (existing.get("result") or []) if isinstance(existing, dict) else []:
        if p.get("name") == PROJECT_NAME:
            call("DELETE", f"/management/v1/projects/{p['id']}")
    status, project = call("POST", "/management/v1/projects", {"name": PROJECT_NAME})
    if status not in (200, 201):
        raise SystemExit(f"could not create project: {status} {project}")
    project_id = project["id"]

    status, app = call("POST", f"/management/v1/projects/{project_id}/apps/oidc", {
        "name": PROJECT_NAME,
        "redirectUris": REDIRECT_URIS,
        "postLogoutRedirectUris": POST_LOGOUT_URIS,
        "responseTypes": ["OIDC_RESPONSE_TYPE_CODE"],
        "grantTypes": ["OIDC_GRANT_TYPE_AUTHORIZATION_CODE"],
        "appType": "OIDC_APP_TYPE_WEB",
        "authMethodType": "OIDC_AUTH_METHOD_TYPE_BASIC",
        "version": "OIDC_VERSION_1_0",
        "devMode": True,
        "accessTokenType": "OIDC_TOKEN_TYPE_BEARER",
        "idTokenUserinfoAssertion": True,
    })
    if status not in (200, 201):
        raise SystemExit(f"could not create OIDC app: {status} {app}")
    print(f"project + OIDC app created (client_id={app['clientId']})")

    # 3. The four members, each with its user id pinned to the fixture UUID so `sub` matches.
    for username, user_id in SUBJECTS.items():
        call("DELETE", f"/v2/users/{user_id}")  # idempotent: ignore if absent
        status, user = call("POST", "/v2/users/human", {
            "userId": user_id,
            "username": username,
            "profile": {"givenName": username.capitalize(), "familyName": "Member"},
            "email": {"email": f"{username}@example.test", "isVerified": True},
            "password": {"password": "password", "changeRequired": False},
        })
        if status not in (200, 201):
            raise SystemExit(f"could not create user {username}: {status} {user}")
    print("members created: " + ", ".join(SUBJECTS))

    print(f"\nissuer:        {BASE}")
    report_client(app["clientId"], app["clientSecret"], args.credentials_file)


if __name__ == "__main__":
    main()
