#!/usr/bin/env python3
"""Configures the dev WSO2 Identity Server to serve the three implementations over OIDC.

Run after WSO2 is up (shared/test/wso2/docker-compose.yml):

    python shared/test/wso2/configure.py

Creates the four test members (alice/bob/carol/dave, password `password`) and a confidential OIDC app
carrying the three implementations' redirect URIs. The conformance suite signs in by name (alice/bob/...),
so each user's SCIM2 userName is that name; the fixed UUID the fixtures grant against (SUBJECTS in
shared/test/conformance/check.py) is carried in the user's SCIM externalId -- SCIM's own field for an external
system's identifier for the user -- and the app's subject is pinned to the matching claim
(http://wso2.org/claims/externalid), without the user-store or tenant domain, so the emitted `sub` is exactly
the fixture UUID while login stays by name. Idempotent: existing members and app are reused.

Prints the issuer and the client id to point the implementations at. The secret is not printed. With
--credentials-file PATH it is written to PATH, readable only by you, which is how the integration matrix
reads it.
"""

import argparse
import json
import os
import ssl
import urllib.error
import urllib.request

BASE = "https://localhost:9443"
AUTH = "Basic YWRtaW46YWRtaW4="  # admin:admin
CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE

SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}

REDIRECT_URIS = [
    "https://localhost:15443/signin-oidc",              # .NET
    "https://localhost:16443/login/oauth2/code/keycloak",  # Java (registrationId "keycloak")
    "https://localhost:17443/auth/callback",            # PHP
]

# The subject is carried in the SCIM externalId, whose local claim this is. Pinning the app's subject here
# makes the emitted OIDC `sub` the fixture UUID while the login identifier stays the member's name.
SUBJECT_CLAIM = "http://wso2.org/claims/externalid"

# Claims the app asks WSO2 to return, so the emitted token carries the standard OIDC profile claims every
# other dev IdP already sends. Chiefly preferred_username: the Java implementation reads it as the session's
# name and rejects a login where it is absent. WSO2's OIDC dialect maps preferred_username to the local
# displayName claim (not username), so that is what is requested, and each member's displayName is set below.
# givenname/lastname come along for parity (-> given_name/family_name).
REQUESTED_CLAIMS = [
    "http://wso2.org/claims/displayName",  # -> preferred_username
    "http://wso2.org/claims/givenname",    # -> given_name
    "http://wso2.org/claims/lastname",     # -> family_name
]


def call(method, path, body=None, ctype="application/json"):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method,
                                 headers={"Authorization": AUTH, "Content-Type": ctype,
                                          "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, context=CTX) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, raw[:300]


def relax_password_policy():
    # The conformance suite signs every member in with the password `password`, as it does against every
    # IdP. WSO2's default rules demand a digit, an uppercase and a special character; keep only the length
    # rule (8-64) so `password` is accepted, leaving the username rules untouched.
    status, rules = call("GET", "/api/server/v1/validation-rules")
    if not isinstance(rules, list):
        raise SystemExit(f"could not read validation rules: {status} {rules}")
    for field in rules:
        if field.get("field") == "password":
            field["rules"] = [r for r in field.get("rules", []) if r.get("validator") == "LengthValidator"]
    status, resp = call("PUT", "/api/server/v1/validation-rules", rules)
    if status not in (200, 201):
        raise SystemExit(f"could not relax password policy: {status} {resp}")
    print("  relaxed password policy to length-only (so `password` is accepted)")


def ensure_member(username):
    uuid = SUBJECTS[username]
    # SCIM2: one user per fixture, its userName the member's name (the suite signs in by name) and its
    # externalId the fixture UUID (the subject the app is pinned to, so `sub` == the UUID).
    status, found = call("GET", f"/scim2/Users?filter=userName+eq+{username}")
    if isinstance(found, dict) and found.get("totalResults", 0) > 0:
        user_id = found["Resources"][0]["id"]
        call("PATCH", f"/scim2/Users/{user_id}",
             {"schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
              "Operations": [{"op": "replace", "value": {"externalId": uuid, "displayName": username}}]},
             ctype="application/scim+json")
        print(f"  member present: {username} (externalId {uuid})")
        return
    body = {
        "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
        "userName": username,
        "externalId": uuid,
        # preferred_username is emitted from displayName (see REQUESTED_CLAIMS); make it the member's name.
        "displayName": username,
        "password": "password",
        "name": {"givenName": username.capitalize(), "familyName": "Test"},
    }
    status, resp = call("POST", "/scim2/Users", body, ctype="application/scim+json")
    if status not in (200, 201):
        raise SystemExit(f"could not create member {username}: {status} {resp}")
    print(f"  created member: {username} (externalId {uuid})")


def ensure_app():
    # Reuse an existing app of this name, else register one via Dynamic Client Registration.
    status, apps = call("GET", "/api/server/v1/applications?filter=name+eq+ata")
    app_id = None
    if isinstance(apps, dict):
        for a in apps.get("applications", []):
            if a.get("name") == "ata":
                app_id = a["id"]
                break
    if app_id is None:
        status, reg = call("POST", "/api/identity/oauth2/dcr/v1.1/register",
                           {"client_name": "ata", "grant_types": ["authorization_code", "refresh_token"],
                            "redirect_uris": REDIRECT_URIS})
        if status not in (200, 201):
            raise SystemExit(f"could not register OIDC app: {status} {reg}")
        # Find the application id the DCR app created.
        _, apps = call("GET", "/api/server/v1/applications?filter=name+eq+ata")
        app_id = next(a["id"] for a in apps.get("applications", []) if a.get("name") == "ata")
        print("  registered OIDC app: ata")
    else:
        print("  OIDC app present: ata")

    # Subject = the username claim, without user-store/tenant domain, so sub == the fixture UUID. And skip
    # the consent screen so the conformance login is the provider's credential prompt and nothing else.
    call("PATCH", f"/api/server/v1/applications/{app_id}",
         {"claimConfiguration": {"subject": {"claim": {"uri": SUBJECT_CLAIM},
                                             "includeUserDomain": False, "includeTenantDomain": False},
                                 "requestedClaims": [{"claim": {"uri": uri}} for uri in REQUESTED_CLAIMS]},
          "advancedConfigurations": {"skipLoginConsent": True, "skipLogoutConsent": True}})

    status, oidc = call("GET", f"/api/server/v1/applications/{app_id}/inbound-protocols/oidc")
    return oidc.get("clientId"), oidc.get("clientSecret")


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
    relax_password_policy()
    for username in SUBJECTS:
        ensure_member(username)
    client_id, client_secret = ensure_app()
    print()
    print(f"issuer: {BASE}/oauth2/token")
    report_client(client_id, client_secret, args.credentials_file)
    print("subject-claim for a WSO2 deployment: sub (the app subject is pinned to externalId = the UUID)")


if __name__ == "__main__":
    main()
