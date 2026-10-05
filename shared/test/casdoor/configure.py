#!/usr/bin/env python3
"""Configures the dev Casdoor instance to serve the three implementations over OIDC.

Run after Casdoor is up (shared/test/casdoor/docker-compose.yml):

    python shared/test/casdoor/configure.py

Creates the four test members (alice/bob/carol/dave, password `password`) in the built-in organization,
each with its Casdoor user id set to the fixed UUID the conformance fixtures grant against (SUBJECTS in
shared/test/conformance/check.py) -- Casdoor's OIDC `sub` is the user id, so no subject-claim override is needed --
and a confidential OIDC application carrying the three implementations' redirect URIs, with a fixed client
id/secret so the matrix needs nothing extracted. Idempotent: existing members and app are updated in place.

Authenticates as the built-in admin (admin/123) for a session cookie; touches no database and hardcodes no
per-instance secret.
"""

import http.cookiejar
import json
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


def main():
    login()
    ensure_org()
    ensure_app()
    for username in SUBJECTS:
        ensure_user(username)
    print()
    print(f"issuer: {BASE}")
    print(f"client_id: {CLIENT_ID}")
    print(f"client_secret: {CLIENT_SECRET}")
    print("subject-claim for a Casdoor deployment: sub (the OIDC sub is the user id = the fixture UUID)")


if __name__ == "__main__":
    main()
