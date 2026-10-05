#!/usr/bin/env python3
"""Configures the dev Casdoor instance to serve the three implementations over OIDC.

Run after Casdoor is up (shared/test/casdoor/docker-compose.yml):

    python shared/test/casdoor/configure.py

Creates the four test members (alice/bob/carol/dave, password `password`) in the built-in organization,
each with its Casdoor user id set to the fixed UUID the conformance fixtures grant against (SUBJECTS in
shared/test/conformance/check.py) -- Casdoor's OIDC `sub` is the user id, so no subject-claim override is needed --
and a confidential OIDC application carrying the three implementations' redirect URIs, with a fixed client
id/secret. Idempotent: existing members and app are updated in place.

Prints the issuer and the client id. The secret is not printed. With --credentials-file PATH it is written to
PATH, readable only by you, which is how the integration matrix reads it.

Authenticates as the built-in admin (admin/123) for a session cookie; touches no database and hardcodes no
per-instance secret.
"""

import argparse
import http.cookiejar
import json
import os
import urllib.error
import urllib.request

BASE = "http://localhost:8010"
ADMIN_USER, ADMIN_PASS = "admin", "123"

SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}

REDIRECT_URIS = [
    "https://localhost:15443/signin-oidc",                 # .NET
    "https://localhost:16443/login/oauth2/code/keycloak",  # Java (registrationId "keycloak")
    "https://localhost:17443/auth/callback",               # PHP
]

ORG = "ata"  # a dedicated org: Casdoor forbids adding members to the global "built-in" org
CLIENT_ID = "ata-casdoor-client"
CLIENT_SECRET = "ata-casdoor-secret-change-in-real-deployments"

_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def api(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with _opener.open(req) as r:
            raw = r.read().decode()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        return {"status": "error", "msg": f"{e.code} {e.read().decode()[:200]}"}


def login():
    resp = api("POST", "/api/login", {"application": "app-built-in", "organization": "built-in",
                                      "username": ADMIN_USER, "password": ADMIN_PASS,
                                      "type": "login", "autoSignin": True})
    if resp.get("status") != "ok":
        raise SystemExit(f"admin login failed: {resp}")


def ensure_org():
    if api("GET", f"/api/get-organization?id=admin/{ORG}").get("data"):
        print(f"  org present: {ORG}")
        return
    resp = api("POST", "/api/add-organization",
               {"owner": "admin", "name": ORG, "displayName": "ATA",
                "passwordType": "plain", "defaultApplication": "ata"})
    if resp.get("status") != "ok":
        raise SystemExit(f"could not create org {ORG}: {resp}")
    print(f"  created org: {ORG}")


def ensure_user(username):
    uuid = SUBJECTS[username]
    existing = api("GET", f"/api/get-user?id={ORG}/{username}")
    user = {
        "owner": ORG, "name": username, "id": uuid,
        "type": "normal-user", "password": "password",
        "displayName": username.capitalize(), "email": f"{username}@ata.example",
        "signupApplication": "ata",
    }
    if existing.get("data"):
        # Preserve Casdoor's own bookkeeping fields, override identity + password.
        merged = existing["data"]
        merged.update({"id": uuid, "password": "password", "type": "normal-user"})
        resp = api("POST", f"/api/update-user?id={ORG}/{username}", merged)
        print(f"  updated member: {username} ({uuid})" if resp.get("status") == "ok"
              else f"  update {username}: {resp}")
    else:
        resp = api("POST", "/api/add-user", user)
        if resp.get("status") != "ok":
            raise SystemExit(f"could not create member {username}: {resp}")
        print(f"  created member: {username} ({uuid})")


def ensure_app():
    app = {
        "owner": "admin", "name": "ata", "displayName": "ATA",
        "organization": ORG, "cert": "cert-built-in",
        "clientId": CLIENT_ID, "clientSecret": CLIENT_SECRET,
        "redirectUris": REDIRECT_URIS,
        "grantTypes": ["authorization_code", "refresh_token"],
        "tokenFormat": "JWT", "expireInHours": 168,
        "enablePassword": True, "enableSignUp": False,
    }
    existing = api("GET", "/api/get-application?id=admin/ata")
    if existing.get("data"):
        merged = existing["data"]
        merged.update({"clientId": CLIENT_ID, "clientSecret": CLIENT_SECRET,
                       "redirectUris": REDIRECT_URIS, "grantTypes": ["authorization_code", "refresh_token"]})
        resp = api("POST", "/api/update-application?id=admin/ata", merged)
        print("  updated OIDC app: ata" if resp.get("status") == "ok" else f"  update app: {resp}")
    else:
        resp = api("POST", "/api/add-application", app)
        if resp.get("status") != "ok":
            raise SystemExit(f"could not create OIDC app: {resp}")
        print("  created OIDC app: ata")


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
    login()
    ensure_org()
    ensure_app()
    for username in SUBJECTS:
        ensure_user(username)
    print()
    print(f"issuer: {BASE}")
    report_client(CLIENT_ID, CLIENT_SECRET, args.credentials_file)
    print("subject-claim for a Casdoor deployment: sub (the OIDC sub is the user id = the fixture UUID)")


if __name__ == "__main__":
    main()
