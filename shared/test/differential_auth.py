#!/usr/bin/env python3
"""Differential conformance for the AUTHENTICATED write path.

The companion to ``shared/test/differential.py``. That one diffs the anonymous surface. This one signs in as
the same member to every implementation and diffs the signed-in write path, set-key, delete, self-revoke,
and the /me projection those produce. Same premise. A verifying Astrana instance must not be able to tell the
stacks apart, so any request that gets a materially different answer from two of them is a bug in at least
one.

Its headline is the /me ``expires_at`` value. The functional suite (``shared/test/conformance/check.py``) checks
that /me carries the right field *keys*, not the string each field serialises to -- which is exactly how a
timezone-format drift (SQL Server and MySQL hand .NET a Kind=Unspecified DateTime that serialises without
the trailing ``Z`` Java and PHP always emit) slips through it. This compares the values, across all three
at once, and so would catch that class of divergence on sight.

Because two implementations may be pointed at the same database, state is reset and reseeded immediately
before each app is hit, so every one sees identical input regardless of what the previous app did to shared
rows. It reaches the organisation-only stored procedures over a supplied SQL command, the same way check.py
grants its fixtures -- so, like check.py, it needs one SQL command per implementation but stays
implementation-blind over HTTP.

Each implementation is named with one --app, at least two of them. For example, the three applications as
shared/test/integration-matrix.sh runs them, each on its own database engine:

    python shared/test/differential_auth.py \\
        --app dotnet https://localhost:15443 mssql    "docker exec -i trusted-attestation-dev-mssql-1 ...sqlcmd..." \\
        --app java   https://localhost:16443 postgres "docker exec -i trusted-attestation-dev-postgres-1 psql ..." \\
        --app php    https://localhost:17443 mysql    "docker exec -i trusted-attestation-dev-mysql-1 mysql ..."

Login uses the built-in Keycloak form login. Pass --browser-login for a JavaScript IdP, as check.py does.
"""

import argparse
import base64
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent / "conformance"))

import check  # noqa: E402
from check import Client, Database, csrf_token, login, quoted  # noqa: E402
from engines import Engine  # noqa: E402

KEY_A_HEX = "".join(f"{b:02x}" for b in range(1, 33))
KEY_B_HEX = "".join(f"{b:02x}" for b in range(40, 72))
KEY_A = base64.b64encode(bytes(range(1, 33))).decode()
KEY_B = base64.b64encode(bytes(range(40, 72))).decode()
ALLZERO = base64.b64encode(bytes(32)).decode()
SHORT = base64.b64encode(b"tooshort").decode()
URLSAFE = base64.urlsafe_b64encode(bytes(range(1, 33))).decode().replace("=", "")
FUTURE = "2027-01-01T00:00:00"

ME = "/api/v1/me"
EMPLOYEE_KEY = "/api/v1/me/relationships/employee/key"
EMPLOYEE_REVOKE = "/api/v1/me/relationships/employee/revoke"
JSON_TYPE = {"Content-Type": "application/json"}

# A body over the 64 kilobyte cap that PUT key and POST /attest place on what they read.
OVERSIZED = b'{"public_key":"' + b"A" * (80 * 1024) + b'"}'

# Stands for the anti-forgery token the self-service page carries, fetched afresh from each app.
PAGE_TOKEN = object()


def employee_entry(public_key, status):
    """alice's employee relationship as /me projects it, normalised the way norm_me returns it."""
    return 200, json.dumps({"expires_at": None, "public_key": public_key, "relationship_subtype": None,
                            "relationship_type": "employee", "status": status}, sort_keys=True)


UNKEYED_ENTRY = employee_entry(None, "unkeyed")
KEYED_ENTRY = employee_entry(KEY_A, "active")
REVOKED_ENTRY = employee_entry(KEY_A, "revoked")


class App:
    def __init__(self, name, base_url, engine, sql_command):
        self.name = name
        self.base_url = base_url
        self.db = Database(sql_command, Engine(engine))
        self.client = Client(verify_tls=False)

    def login(self):
        return login(self.client, self.base_url, "alice", "password")

    def send(self, method, path, body=None, headers=None):
        return self.client.request(method, self.base_url + path, body, headers or {})

    def page_token(self):
        """The anti-forgery token on the self-service page, read from the page as its script reads it."""
        _, body, _ = self.client.get(self.base_url + "/me")
        return csrf_token(body) or ""


def set_key(db, who, rel_type, hex_key):
    db.run(f"UPDATE member_relationships SET public_key = {db.engine.binary(hex_key)} "
           f"WHERE iam_subject_id = {quoted(check.SUBJECTS[who])} "
           f"AND relationship_type = {quoted(rel_type)};")


def unkeyed(db):
    db.grant("alice", "employee")


def keyed(db):
    db.grant("alice", "employee")
    set_key(db, "alice", "employee", KEY_A_HEX)


def expiring(db):
    db.grant("alice", "employee")
    db.org_extend("alice", "employee", FUTURE)


def keyed_expiring(db):
    db.grant("alice", "employee")
    set_key(db, "alice", "employee", KEY_A_HEX)
    db.org_extend("alice", "employee", FUTURE)


def conflict(db):
    db.grant("alice", "employee")
    db.grant("bob", "client")
    set_key(db, "bob", "client", KEY_B_HEX)


def exchange(app, setup, method, path, payload=None, body=None, headers=None, csrf=None):
    """Resets and seeds the app's database, then sends one request as alice. ``payload`` goes as a JSON
    body, ``body`` as raw bytes. ``csrf`` adds an X-CSRF-TOKEN header: PAGE_TOKEN sends the page's own
    token, and any string is sent as it is."""
    app.db.reset()
    setup(app.db)
    sent = dict(headers or {})
    if payload is not None:
        body = json.dumps(payload).encode()
        sent = {**JSON_TYPE, **sent}
    if csrf is not None:
        sent["X-CSRF-TOKEN"] = app.page_token() if csrf is PAGE_TOKEN else csrf
    return app.send(method, path, body, sent)


def norm_write(status, raw, headers):
    ct = (headers.get("Content-Type", "") or "").split(";")[0].strip().lower()
    text = (raw or b"").decode("utf-8", "replace")
    try:
        text = json.dumps(json.loads(text), sort_keys=True) if text else ""
    except Exception:  # noqa: BLE001
        text = text[:120]
    return status, ct, text


def norm_me(status, raw, rel_type):
    if status != 200 or not raw:
        return status, None
    try:
        me = json.loads(raw)
    except Exception:  # noqa: BLE001
        return status, "unparseable"
    for entry in me.get("relationships", []):
        if entry.get("relationship_type") == rel_type:
            return status, json.dumps(entry, sort_keys=True)
    return status, "absent"


def run(apps):
    divergences = 0

    def compare(desc, observe, expect=None):
        """Runs ``observe`` against every app and reports whether the answers agree. With ``expect``, a
        prefix of the answer every app must give, three apps agreeing on another answer fail as well."""
        nonlocal divergences
        normed = {app.name: observe(app) for app in apps}
        same = len({str(v) for v in normed.values()}) == 1
        wrong = expect is not None and any(v[:len(expect)] != expect for v in normed.values())
        if wrong or not same:
            divergences += 1
        print(f"[{'WRONG!  ' if wrong else 'OK      ' if same else 'DIVERGE!'}] {desc}")
        if wrong:
            print(f"           {'expected':7} {expect}")
        if wrong or not same:
            for app in apps:
                print(f"           {app.name:7} {normed[app.name]}")

    def scenario(desc, setup, method, path, payload=None, read_me=None, expect=None, **request):
        def observe(app):
            status, raw, headers = exchange(app, setup, method, path, payload, **request)
            if read_me:
                mstatus, mraw, _ = app.send("GET", ME)
                return "write", status, norm_me(mstatus, mraw, read_me)
            return norm_write(status, raw, headers)
        compare(desc, observe, expect)

    def untouched(desc, setup, method, path, expect, **request):
        """A request that must leave alice's employee relationship as ``setup`` made it. The answer is
        compared as its status, the relationship as /me shows it afterwards, then the answer's Content-Type
        and body, so ``expect`` can pin the status and the relationship without pinning the rest."""
        def observe(app):
            status, ct, text = norm_write(*exchange(app, setup, method, path, **request))
            mstatus, mraw, _ = app.send("GET", ME)
            return status, norm_me(mstatus, mraw, "employee"), ct, text
        compare(desc, observe, expect)

    # /me projection -- the value-format parity the functional suite does not check
    scenario("/me employee unkeyed: object identical", unkeyed, "GET", ME, read_me="employee")
    scenario("/me employee expiring: expires_at identical (the Z parity)",
             expiring, "GET", ME, read_me="employee")
    scenario("/me employee keyed + expiring: public_key + expires_at identical",
             keyed_expiring, "GET", ME, read_me="employee")

    # set-key
    scenario("PUT valid key on unkeyed -> 200 with the key echoed",
             unkeyed, "PUT", EMPLOYEE_KEY, {"public_key": KEY_A})
    scenario("PUT idempotent (same key) -> 200",
             keyed, "PUT", EMPLOYEE_KEY, {"public_key": KEY_A})
    # Blank means empty or nothing but spaces, tabs, carriage returns and line feeds, and clears the key.
    # Any other whitespace character is a malformed key, and the three must agree on where that line is.
    scenario("A key of spaces, tabs, carriage returns and line feeds clears the key",
             keyed, "PUT", EMPLOYEE_KEY, {"public_key": " \t\r\n "})
    scenario("A key of one non-breaking space is refused as malformed",
             keyed, "PUT", EMPLOYEE_KEY, {"public_key": " "})
    scenario("A key of one form feed is refused as malformed",
             keyed, "PUT", EMPLOYEE_KEY, {"public_key": "\f"})
    scenario("PUT short key -> 400",
             unkeyed, "PUT", EMPLOYEE_KEY, {"public_key": SHORT})
    scenario("PUT all-zero key -> 400",
             unkeyed, "PUT", EMPLOYEE_KEY, {"public_key": ALLZERO})
    scenario("PUT url-safe key -> 400",
             unkeyed, "PUT", EMPLOYEE_KEY, {"public_key": URLSAFE})
    scenario("PUT number key -> identical shape",
             unkeyed, "PUT", EMPLOYEE_KEY, {"public_key": 1234})
    scenario("PUT missing public_key -> identical shape",
             unkeyed, "PUT", EMPLOYEE_KEY, {"other": "x"})
    scenario("PUT key on a relationship not held -> 404",
             unkeyed, "PUT", "/api/v1/me/relationships/client/key", {"public_key": KEY_A})
    scenario("PUT a key another member already holds -> 409",
             conflict, "PUT", EMPLOYEE_KEY, {"public_key": KEY_B})

    # delete
    scenario("DELETE keyed relationship -> 204", keyed, "DELETE", EMPLOYEE_KEY)
    scenario("DELETE unkeyed relationship -> 204", unkeyed, "DELETE", EMPLOYEE_KEY)
    scenario("DELETE relationship not held -> 404", unkeyed, "DELETE", "/api/v1/me/relationships/client/key")

    # self-revoke, which a signed-in member sends with the page's anti-forgery token in X-CSRF-TOKEN
    scenario("POST self-revoke on keyed -> identical shape",
             keyed, "POST", EMPLOYEE_REVOKE, csrf=PAGE_TOKEN)
    untouched("POST self-revoke without the anti-forgery token -> 403, no body, no Content-Type, not revoked",
              keyed, "POST", EMPLOYEE_REVOKE, expect=(403, KEYED_ENTRY, "", ""))
    untouched("POST self-revoke with a wrong anti-forgery token -> 403, no body, no Content-Type, not revoked",
              keyed, "POST", EMPLOYEE_REVOKE, csrf="not-the-page-token", expect=(403, KEYED_ENTRY, "", ""))

    # A POST naming another method is answered as the POST it is. No implementation reads a _method field
    # in the query string or a form body, or an X-HTTP-Method-Override header, so a plain form or a text
    # body from another site cannot reach PUT or DELETE on the key without the preflight a real PUT or
    # DELETE would need. The key has no POST, so the answer is 405 and the key on record stays.
    untouched("POST key with ?_method=PUT and a text/plain JSON body -> 405, the key unchanged",
              keyed, "POST", EMPLOYEE_KEY + "?_method=PUT", expect=(405, KEYED_ENTRY),
              body=json.dumps({"public_key": KEY_B}).encode(), headers={"Content-Type": "text/plain"})
    untouched("POST key with ?_method=DELETE -> 405, the relationship kept",
              keyed, "POST", EMPLOYEE_KEY + "?_method=DELETE", expect=(405, KEYED_ENTRY))
    untouched("POST key with a _method=DELETE form field -> 405, the relationship kept",
              keyed, "POST", EMPLOYEE_KEY, expect=(405, KEYED_ENTRY),
              body=b"_method=DELETE", headers={"Content-Type": "application/x-www-form-urlencoded"})
    untouched("POST key with an X-HTTP-Method-Override: DELETE header -> 405, the relationship kept",
              keyed, "POST", EMPLOYEE_KEY, expect=(405, KEYED_ENTRY),
              headers={"X-HTTP-Method-Override": "DELETE"})

    # The 64 kilobyte cap belongs to PUT key and POST /attest alone. A body on any other call is not read,
    # so its size cannot turn the answer into 413.
    scenario("GET /me with a body over 64 kilobytes -> 200, not 413",
             unkeyed, "GET", ME, read_me="employee", expect=("write", 200, UNKEYED_ENTRY),
             body=OVERSIZED, headers=JSON_TYPE)
    scenario("POST self-revoke with a body over 64 kilobytes -> 204 and revoked, not 413",
             keyed, "POST", EMPLOYEE_REVOKE, read_me="employee", expect=("write", 204, REVOKED_ENTRY),
             body=OVERSIZED, headers=JSON_TYPE, csrf=PAGE_TOKEN)

    print(f"\n{divergences} divergence(s) on the authenticated write path.")
    return divergences


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--app", nargs=4, action="append", required=True,
                        metavar=("NAME", "URL", "ENGINE", "SQL"),
                        help="An implementation: name, base URL, db engine (postgres|mysql|mssql), and the "
                             "SQL command that reaches its database. Repeat, once per implementation.")
    parser.add_argument("--browser-login", action="store_true",
                        help="Drive a JavaScript IdP's sign-in with a real browser, as check.py does.")
    args = parser.parse_args()

    check.BROWSER_LOGIN = args.browser_login
    if len(args.app) < 2:
        parser.error("differential testing needs at least two implementations to compare")

    apps = [App(*spec) for spec in args.app]

    print("Logging in as alice to:")
    for app in apps:
        if not app.login():
            print(f"  {app.name} ({app.base_url}): LOGIN FAILED -- aborting")
            return 2
        print(f"  {app.name} ({app.base_url}): ok")
    print()

    sys.exit(1 if run(apps) else 0)


if __name__ == "__main__":
    sys.exit(main())
