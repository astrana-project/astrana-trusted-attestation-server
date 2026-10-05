#!/usr/bin/env python3
"""Configures the dev Authentik instance to serve the three implementations over SAML 2.0.

Run after configure.py (which creates the members) has been run at least once, and after Authentik is up:

    python shared/test/authentik/configure-saml.py

Authentik's SAML is per-application, so this registers three SAML providers -- one per implementation SP,
each with that SP's assertion-consumer URL and entity id -- and a NameID property mapping that pins the
NameID to the fixed UUID the conformance fixtures grant against (SUBJECTS in shared/test/conformance/check.py).
Because the NameID *is* the subject, an implementation run against Authentik-SAML needs no subject-claim
override: the default (the assertion's NameID) already carries the fixture subject.

Each provider publishes its own metadata at:
    http://localhost:9002/application/saml/<slug>/metadata/
which is what the matching implementation points its SAML metadata URL at.
"""

import json
import urllib.error
import urllib.request

BASE = "http://localhost:9002/api/v3"
HEAD = {"Authorization": "Bearer authentik-bootstrap-token", "Content-Type": "application/json"}

SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}
# A SAML property mapping returns the value directly (unlike an OIDC scope mapping, which returns a dict).
NAME_ID_EXPRESSION = "return %s.get(request.user.username, request.user.username)" % json.dumps(SUBJECTS)

# One SAML provider + application per implementation SP: slug, that SP's assertion-consumer URL, and its
# entity id (the SAML audience).
SPS = {
    "ata-saml-dotnet": {"acs": "https://localhost:15443/Saml2/Acs",
                        "audience": "https://localhost:15443/Saml2"},
    "ata-saml-java": {"acs": "https://localhost:16443/login/saml2/sso/keycloak",
                      "audience": "https://localhost:16443/saml2/service-provider-metadata/keycloak"},
    "ata-saml-php": {"acs": "https://localhost:17443/auth/saml/acs",
                     "audience": "https://localhost:17443/auth/saml/metadata"},
}


def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, headers=HEAD, method=method)
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:300]


def first(path):
    results = call("GET", path)[1].get("results", [])
    return results[0] if results else None


def signing_cert():
    """A dedicated signing keypair for the SAML assertions, generated once and reused. Without a signing
    keypair Authentik neither signs the assertion nor advertises a certificate in its metadata, and the
    implementations refuse an unsigned or unverifiable assertion."""
    existing = first("/crypto/certificatekeypairs/?name=ata-saml-signing")
    if existing:
        return existing["pk"]
    status, cert = call("POST", "/crypto/certificatekeypairs/generate/",
                        {"common_name": "ata-saml-signing", "validity_days": 3650, "alg": "rsa"})
    if status not in (200, 201):
        raise SystemExit(f"could not generate signing cert: {status} {cert}")
    return (first("/crypto/certificatekeypairs/?name=ata-saml-signing") or {}).get("pk")


def main():
    auth_flow = first("/flows/instances/?slug=default-provider-authorization-implicit-consent")
    inval_flow = first("/flows/instances/?designation=invalidation")
    if not (auth_flow and inval_flow):
        raise SystemExit("Authentik is missing its bootstrap defaults -- is it fully started?")
    signing_kp = signing_cert()
    # The built-in attribute mappings, so the assertion carries an AttributeStatement. The .NET stack
    # (Microsoft.IdentityModel) refuses an assertion whose AttributeStatement is empty, even though the
    # subject travels in the NameID -- so a NameID mapping alone is not enough.
    attribute_mappings = [m["pk"] for m in
                          call("GET", "/propertymappings/provider/saml/?managed__isnull=false")[1].get("results", [])]

    # A NameID mapping that resolves to the fixture UUID, replacing any earlier one.
    for m in call("GET", "/propertymappings/provider/saml/?search=ata-saml-nameid")[1].get("results", []):
        if m["name"] == "ata-saml-nameid":
            call("DELETE", f"/propertymappings/provider/saml/{m['pk']}/")
    status, mapping = call("POST", "/propertymappings/provider/saml/",
                           {"name": "ata-saml-nameid", "saml_name": "nameid",
                            "expression": NAME_ID_EXPRESSION})
    if status not in (200, 201):
        raise SystemExit(f"could not create NameID mapping: {status} {mapping}")

    for slug, sp in SPS.items():
        # Replace any earlier provider/application under this slug.
        app = first(f"/core/applications/?slug={slug}")
        if app:
            call("DELETE", f"/core/applications/{app['slug']}/")
        existing = first(f"/providers/saml/?name={slug}")
        if existing:
            call("DELETE", f"/providers/saml/{existing['pk']}/")

        status, provider = call("POST", "/providers/saml/", {
            "name": slug,
            "authorization_flow": auth_flow["pk"],
            "invalidation_flow": inval_flow["pk"],
            "acs_url": sp["acs"],
            "audience": sp["audience"],
            "sp_binding": "post",
            # signing_kp (not signing_key) is the SAML provider's signing certificate. With it set,
            # Authentik signs the assertion and publishes the certificate in its metadata, which is what the
            # implementations verify against. Sign the assertion only, not the response: a response+assertion
            # double signature produces a two-reference signature that the OpenSAML profile validator rejects.
            "signing_kp": signing_kp,
            "sign_assertion": True,
            "sign_response": False,
            "name_id_mapping": mapping["pk"],
            "property_mappings": attribute_mappings,
        })
        if status not in (200, 201):
            raise SystemExit(f"could not create SAML provider {slug}: {status} {provider}")
        call("POST", "/core/applications/", {"name": slug, "slug": slug, "provider": provider["pk"]})
        print(f"  {slug}: http://localhost:9002/application/saml/{slug}/metadata/")

    print("subject-claim for an Authentik SAML deployment: sub (the default -- NameID carries the subject)")


if __name__ == "__main__":
    main()
