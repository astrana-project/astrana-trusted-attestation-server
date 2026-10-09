#!/usr/bin/env python3
"""Differential conformance: fire the same requests at every implementation and diff the answers.

The functional conformance suite (``shared/test/conformance/check.py``) asks whether one implementation obeys
the contract. This asks a different question, whether the implementations obey it *identically*. A verifying
Astrana instance must not be able to tell which of the three stacks (.NET, Java, PHP) answered, so any request
that gets a materially different answer from two of them is a bug in at least one, even when each answer would
pass the functional suite on its own.

It works by sending a battery of ordinary, edge, and deliberately malformed requests to every base URL at
once and comparing the normalised responses. It is implementation-blind: it speaks only HTTP, knows
nothing of any stack's internals, and needs no database access, because every case here is anonymous.
The authenticated write path is checked against each implementation on its own by check.py, and compared
across them by shared/test/differential_auth.py, which signs in to each and diffs set-key, delete and revoke.

    python shared/test/differential.py
    python shared/test/differential.py --base-url https://localhost:15443 --base-url https://localhost:16443 ...

Normalisation masks the two things that are *meant* to differ between instances: the host in any URL a
response carries (each instance advertises its own ``attestation_url``), and how a template engine lays
out otherwise-identical HTML (whitespace, attribute quoting, comments). For the two localisable pages what
is compared is therefore the resolved ``lang``/``dir`` -- the language the page renders in, which the
contract does require to match -- not the byte-for-byte markup. The language switcher's POST is compared
as the one redirect it answers with: its status, where it sends the browser, and the cookie it sets, with
the cookie's attributes sorted and lower-cased because each framework writes them in its own order.

A handful of cases exercise behaviour the contract deliberately leaves to the implementation: what an
unusual HTTP method, an uppercased path, a trailing or doubled slash, or a syntactically broken
Accept-Language does. The frameworks answer these their own way, and forcing three routers to converge on
undefined input buys nothing a real consumer would ever see. Those cases are listed in ACCEPTED, with the
reason, so the tool stays green on them while still *reporting* them -- and so the day one is deliberately
harmonised, its line is removed and the gate starts holding the new agreement. Any divergence NOT in
ACCEPTED fails the run.

One rule cannot be reached through a proxy, because the proxy overwrites the header it is about. Behind a
TLS-terminating proxy each application refuses, with a body-less 421, any request whose X-Forwarded-Proto
is not exactly one value of exactly "https", and serves a request with no such header. It judges no other
forwarded header, so an RFC 7239 Forwarded header neither refuses a request nor rescues one. The
forwarded-scheme cases send those headers straight to each application's own plain HTTP address, given with
--direct-url or in DIFFERENTIAL_DIRECT_URLS, and each carries the answer it expects, so three
implementations agreeing on the wrong answer fail as well. Those answers are the applications' own, so they
are also checked for a Server or X-Powered-By header, which would name the runtime and which no
implementation sends. Without those addresses the cases are skipped and the run says so.

    python shared/test/differential.py --direct-url http://dotnet-app:8080 --direct-url http://java-app:8080 ...
"""

import argparse
import base64
import http.client
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

CTX = ssl._create_unverified_context()


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """A redirect handler that does not redirect, so the hop itself can be compared."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


# Two openers: one that follows redirects the way urlopen does, for every case that compares a final
# page or API answer, and one that stops on the first hop, for the cases where the redirect is the answer.
FOLLOWING = urllib.request.build_opener(urllib.request.HTTPSHandler(context=CTX))
ONE_HOP = urllib.request.build_opener(_NoRedirect, urllib.request.HTTPSHandler(context=CTX))

DEFAULT_BASE_URLS = [
    "https://localhost:15443",  # .NET
    "https://localhost:16443",  # Java
    "https://localhost:17443",  # PHP
]

# A valid 32-byte key that nothing registers. The demonstration seeds plant bytes 1 to 128 as four keys,
# so this starts past them, and its standard spelling carries a "+", so its url-safe spelling below is a
# different string rather than the same one without padding.
UNSEEDED = bytes(range(161, 193))
VALID_UNREG = base64.b64encode(UNSEEDED).decode()
SHORT = base64.b64encode(b"tooshort").decode()
LONG = base64.b64encode(bytes(64)).decode()
ALLZERO = base64.b64encode(bytes(32)).decode()
URLSAFE = base64.urlsafe_b64encode(UNSEEDED).decode().replace("=", "")
# A body over the 64 kilobyte cap that PUT key and POST /attest place on what they read.
OVERSIZED = b'{"public_key":"' + b"A" * (80 * 1024) + b'"}'

# Divergences the contract leaves undefined and we have consciously not harmonised: framework defaults on
# input no conforming consumer sends. Keyed by case name, valued by the reason, which prints alongside.
# Removing a line turns that case back into a hard failure -- which is exactly what you want the moment you
# decide the three should agree on it.
ACCEPTED = {
    "OPTIONS on attest": "CORS-preflight handling is a framework default; no consumer sends OPTIONS here",
    "HEAD on me": "HEAD on an authenticated endpoint: 405 vs a GET-shaped 401 is a router default",
    "PATCH on me key": "an unsupported method on a real path; 405 vs auth-first 401 is a router default",
    "POST on manifest": "an unsupported method on a public GET; 405 vs a CSRF 403 is a framework default",
    "attest trailing slash": "trailing-slash matching is a router policy the frameworks set differently",
    "attest uppercase": ".NET routes case-insensitively; the others 404. Undefined for an uppercased path",
    "double slash path": "a doubled slash is normalised differently in each connector (400 vs 404)",
    "landing malformed accept-lang": "quality-value parsing of a syntactically broken header is undefined",
}


def attest(body_bytes, ct="application/json"):
    return dict(method="POST", path="/api/v1/attest",
                headers={"Content-Type": ct} if ct else {}, body=body_bytes, kind="api")


def api(name, method, path, headers=None, body=None, expect=None):
    return dict(name=name, method=method, path=path, headers=headers or {}, body=body, kind="api", expect=expect)


def page(name, method, path, headers=None):
    return dict(name=name, method=method, path=path, headers=headers or {}, body=None, kind="page")


def set_language(name, fields):
    """A POST of the language switcher's form, compared as the one hop it answers with: status, where it
    sends the browser, and the cookie it sets. The redirect's body, if a framework writes one, is a
    courtesy page no browser shows and is not compared."""
    body = urllib.parse.urlencode(fields).encode()
    return dict(name=name, method="POST", path="/set-language",
                headers={"Content-Type": "application/x-www-form-urlencoded"}, body=body,
                kind="redirect", follow=False)


def landing_hop(name, method, path):
    """A request whose answer is a redirect, compared as where it sends the browser. The redirect's exact
    status and any cookie on it are each framework's own and not what the case pins, so a redirect of any
    kind compares equal and only the Location is held to agreement."""
    return dict(name=name, method=method, path=path,
                headers={"Content-Type": "application/x-www-form-urlencoded"}, body=b"",
                kind="landing", follow=False)


def j(obj):
    return json.dumps(obj).encode()


def cases():
    out = []

    def add(name, **spec):
        out.append(dict(name=name, **spec))

    # -- /attest body shapes: every one must answer 200 {"valid": false}, identically lenient --------
    add("attest valid-unregistered", **attest(j({"public_key": VALID_UNREG})))
    add("attest number key", **attest(j({"public_key": 1234})))
    add("attest bool key", **attest(j({"public_key": True})))
    add("attest array key", **attest(j({"public_key": ["a", "b"]})))
    add("attest object key", **attest(j({"public_key": {"x": 1}})))
    add("attest null key", **attest(j({"public_key": None})))
    add("attest empty-string key", **attest(j({"public_key": ""})))
    add("attest missing key", **attest(j({"other": "x"})))
    add("attest empty object", **attest(j({})))
    add("attest short key", **attest(j({"public_key": SHORT})))
    add("attest 64-byte key", **attest(j({"public_key": LONG})))
    add("attest all-zero key", **attest(j({"public_key": ALLZERO})))
    add("attest urlsafe-base64 key", **attest(j({"public_key": URLSAFE})))
    add("attest key with whitespace", **attest(j({"public_key": "  " + VALID_UNREG + "  "})))
    add("attest key inner newline", **attest(j({"public_key": VALID_UNREG[:20] + "\n" + VALID_UNREG[20:]})))
    add("attest unicode key", **attest(j({"public_key": "éèê" * 11})))
    add("attest very long key", **attest(j({"public_key": "A" * 10000})))
    add("attest extra fields", **attest(j({"public_key": VALID_UNREG, "extra": [1, 2, 3], "nested": {"a": 1}})))
    add("attest duplicate json keys",
        **attest(b'{"public_key":"' + VALID_UNREG.encode() + b'","public_key":"' + ALLZERO.encode() + b'"}'))
    add("attest not json", **attest(b"this is not json at all"))
    add("attest empty body", **attest(b""))
    add("attest truncated json", **attest(b'{"public_key":'))
    add("attest json array top", **attest(b'["a","b"]'))
    add("attest json string top", **attest(b'"just a string"'))
    add("attest json number top", **attest(b"12345"))
    add("attest bom + json", **attest(b'\xef\xbb\xbf{"public_key":"' + VALID_UNREG.encode() + b'"}'))
    add("attest trailing tokens", **attest(j({"public_key": VALID_UNREG}) + b" {}"))
    add("attest invalid utf-8 in key", **attest(b'{"public_key":"\xff\xfe' + VALID_UNREG.encode() + b'"}'))
    # Over the 64 kilobyte cap on the request body: 413 with no body, before the JSON is looked at.
    add("attest oversized body", **attest(OVERSIZED))
    add("attest wrong content-type", **attest(j({"public_key": VALID_UNREG}), "text/plain"))
    add("attest no content-type", **attest(j({"public_key": VALID_UNREG}), None))
    add("attest charset ct", **attest(j({"public_key": VALID_UNREG}), "application/json; charset=utf-8"))
    add("attest form content-type",
        **attest(b"public_key=" + VALID_UNREG.encode(), "application/x-www-form-urlencoded"))

    # -- methods: an unsupported method is a body-less status; the exact status on undefined ones is
    #    framework-shaped (see ACCEPTED), but none may answer with a body --------------------------------
    out.append(api("GET on attest", "GET", "/api/v1/attest"))
    out.append(api("PUT on attest", "PUT", "/api/v1/attest",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    out.append(api("DELETE on attest", "DELETE", "/api/v1/attest"))
    out.append(api("OPTIONS on attest", "OPTIONS", "/api/v1/attest"))
    out.append(api("HEAD on me", "HEAD", "/api/v1/me"))
    out.append(api("PATCH on me key", "PATCH", "/api/v1/me/relationships/employee/key"))
    out.append(api("GET on me (no auth)", "GET", "/api/v1/me"))

    # -- the body cap: only PUT key and POST /attest read a body, so only they answer 413 for one over
    #    64 kilobytes, and PUT key only after the session check. Without a session, a member call with a
    #    body of any size answers 401. --------------------------------------------------------------------
    json_type = {"Content-Type": "application/json"}
    out.append(api("PUT on me key with an oversized body (no auth) is 401, not 413", "PUT",
                   "/api/v1/me/relationships/employee/key", json_type, OVERSIZED, expect=(401,)))
    out.append(api("GET on me with an oversized body (no auth) is 401, not 413", "GET",
                   "/api/v1/me", json_type, OVERSIZED, expect=(401,)))
    out.append(api("POST revoke with an oversized body (no auth) is 401, not 413", "POST",
                   "/api/v1/me/relationships/employee/revoke", json_type, OVERSIZED, expect=(401,)))
    out.append(api("POST on manifest", "POST", "/.well-known/ata-manifest.json"))
    out.append(api("HEAD on manifest", "HEAD", "/.well-known/ata-manifest.json"))

    # -- paths / routing --------------------------------------------------------------------------------
    out.append(api("unversioned attest", "POST", "/api/attest",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    out.append(api("unknown version", "POST", "/api/v2/attest",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    out.append(api("attest trailing slash", "POST", "/api/v1/attest/",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    out.append(api("attest uppercase", "POST", "/api/v1/ATTEST",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    out.append(api("me empty rel-type", "GET", "/api/v1/me/relationships//key"))
    out.append(api("me path traversal", "GET", "/api/v1/me/relationships/..%2f..%2fkey"))
    out.append(api("me extra segment", "DELETE", "/api/v1/me/relationships/employee/key/extra"))
    out.append(api("unicode rel-type", "DELETE", "/api/v1/me/relationships/%e5%93%a1%e5%b7%a5/key"))
    out.append(api("unknown api path", "GET", "/api/v1/nonsense"))
    out.append(api("double slash path", "POST", "//api/v1/attest",
                   {"Content-Type": "application/json"}, j({"public_key": VALID_UNREG})))
    # Paths a framework may answer for its own reasons: an error page, or sign-in routes under a prefix
    # this deployment does not use. Nothing here serves them, so every implementation answers 404 with
    # no body, like any other unknown path.
    out.append(api("framework error page path", "GET", "/error"))
    out.append(api("oauth2 path nothing serves", "GET", "/oauth2/anything"))
    out.append(api("login path nothing serves", "GET", "/login/anything"))

    # -- manifest (host-masked in normalisation) --------------------------------------------------------
    out.append(api("manifest", "GET", "/.well-known/ata-manifest.json"))

    # -- localisation: the language the page renders in must match. Full markup is not compared (template
    #    engines lay out identical content differently); the resolved lang/dir is. ----------------------
    out.append(page("licence page", "GET", "/license"))
    out.append(page("landing default", "GET", "/"))
    out.append(page("landing accept xml", "GET", "/", {"Accept": "application/xml"}))
    out.append(page("landing supported lang", "GET", "/", {"Accept-Language": "fr"}))
    out.append(page("landing chinese by region", "GET", "/", {"Accept-Language": "zh-TW"}))
    out.append(page("landing bare chinese", "GET", "/", {"Accept-Language": "zh"}))
    out.append(page("landing chinese script over region", "GET", "/", {"Accept-Language": "zh-Hans-TW"}))
    out.append(page("landing 4th-position supported lang", "GET", "/",
                    {"Accept-Language": "es;q=0.9, it;q=0.8, pt;q=0.7, de;q=0.6"}))
    out.append(page("landing query culture ignored", "GET", "/?culture=de&ui-culture=de",
                    {"Accept-Language": "en"}))
    out.append(page("landing malformed accept-lang", "GET", "/", {"Accept-Language": "en;q=zzz, ,fr;q=, de;;;"}))
    out.append(page("landing huge accept-lang", "GET", "/",
                    {"Accept-Language": ",".join(f"l{i};q=0.{i % 10}" for i in range(200))}))
    out.append(page("landing accept-lang header injection", "GET", "/",
                    {"Accept-Language": "en\r\nX-Injected: 1"}))

    # -- the signed-out landing page: one fixed address every implementation shares, so the provider's
    #    post-logout redirect is registered once ------------------------------------------------------
    out.append(page("signed-out page", "GET", "/signed-out"))
    out.append(page("signed-out page supported lang", "GET", "/signed-out", {"Accept-Language": "fr"}))

    # -- signing out with no session: nothing to end, so every implementation sends the browser to the
    #    signed-out page, whatever the anti-forgery token says (decision record 42) --------------------------------
    out.append(landing_hop("sign-out with no session goes to the signed-out page", "POST", "/signout"))

    # -- the language switcher: the cookie it sets, and the cookie's effect. The chosen locale outranks
    #    Accept-Language; a value that is not shipped is ignored and resolution falls through -------------
    out.append(set_language("set-language supported", {"locale": "fr", "next": "/"}))
    out.append(set_language("set-language unsupported", {"locale": "zz", "next": "/"}))
    out.append(set_language("set-language offsite next", {"locale": "fr", "next": "https://evil.example"}))
    out.append(set_language("set-language protocol-relative next", {"locale": "fr", "next": "//evil.example"}))
    out.append(set_language("set-language backslash next", {"locale": "fr", "next": "/\\evil.example"}))
    out.append(set_language("set-language next with a tab", {"locale": "fr", "next": "/me\tevil"}))
    # The switcher reads a form body only. With no body, or a JSON one, there is no locale to store, so the
    # answer is the redirect to the fallback with no cookie, and never a 500.
    out.append(dict(name="set-language with no body", method="POST", path="/set-language", headers={},
                    body=b"", kind="redirect", follow=False))
    out.append(dict(name="set-language with a JSON body", method="POST", path="/set-language",
                    headers={"Content-Type": "application/json"}, body=j({"locale": "fr", "next": "/"}),
                    kind="redirect", follow=False))
    out.append(page("landing cookie locale", "GET", "/", {"Cookie": "ata_locale=fr", "Accept-Language": "de"}))
    out.append(page("landing unsupported cookie locale", "GET", "/",
                    {"Cookie": "ata_locale=zz", "Accept-Language": "fr"}))

    return out


# What a refusal must look like in full: 421, no Content-Type and no body. A served request is pinned on
# its status only, and its body is held to agreement like any other answer.
SERVED = (200,)
REFUSED = (421, "", "")


def forwarded(name, lines, expect, others=()):
    """A GET of the manifest sent straight to an application, carrying one X-Forwarded-Proto header line
    per entry in ``lines``, sent as written, after any other header lines given in ``others`` as name and
    value pairs. An empty value and a repeated line both go out on the wire, which is why these are sent
    with http.client and not urllib."""
    return dict(name=name, method="GET", path="/.well-known/ata-manifest.json", kind="forwarded", expect=expect,
                lines=list(others) + [("X-Forwarded-Proto", value) for value in lines])


def forwarded_scheme_cases():
    return [
        forwarded("forwarded scheme absent is served", [], SERVED),
        forwarded("forwarded scheme https is served", ["https"], SERVED),
        forwarded("forwarded scheme http is refused", ["http"], REFUSED),
        forwarded("forwarded scheme in capitals is refused", ["HTTPS"], REFUSED),
        forwarded("forwarded scheme empty is refused", [""], REFUSED),
        forwarded("forwarded scheme blank is refused", [" "], REFUSED),
        forwarded("forwarded scheme https then http is refused", ["https,http"], REFUSED),
        forwarded("forwarded scheme http then https is refused", ["http,https"], REFUSED),
        forwarded("forwarded scheme https twice in one line is refused", ["https,https"], REFUSED),
        forwarded("forwarded scheme https with a trailing comma is refused", ["https,"], REFUSED),
        forwarded("forwarded scheme on two header lines is refused", ["https", "https"], REFUSED),
        # Only X-Forwarded-Proto is judged. An RFC 7239 Forwarded header is neither a forwarded scheme nor
        # a reason to overlook one.
        forwarded("a Forwarded header saying http, alone, is served", [], SERVED,
                  others=[("Forwarded", "proto=http")]),
        forwarded("a Forwarded header beside forwarded scheme http is refused", ["http"], REFUSED,
                  others=[("Forwarded", "for=1.2.3.4")]),
    ]


# Response headers that would name the runtime that answered. No implementation sends either, so a
# verifying instance cannot tell the stacks apart by them. Only the direct batch sees each application's own
# headers: through the proxy, the proxy's own Server header stands in front of them.
RUNTIME_HEADERS = ("Server", "X-Powered-By")


def runtime_headers(headers):
    """The header lines in a response that name the runtime, each as "Name: value"."""
    if headers is None:
        return []
    return [f"{name}: {value}" for name in RUNTIME_HEADERS for value in headers.get_all(name) or []]


def runtime_named(spec, answers):
    """Reports every application whose response to this case names its runtime, and answers 1 when any
    did, so the case counts as one unexpected result, otherwise 0."""
    named = {base: runtime_headers(answer[3]) for base, answer in answers.items()}
    named = {base: lines for base, lines in named.items() if lines}
    if not named:
        return 0
    print(f"\n[RUNTIME] {spec['name']}  [{spec['method']} {spec['path']}]")
    for base, lines in named.items():
        print(f"          {base:32} {', '.join(lines)}")
    return 1


def fire_lines(base, spec):
    """Sends a request whose header lines go out exactly as listed, empty values and repeats included."""
    target = urllib.parse.urlsplit(base)
    connection = http.client.HTTPConnection(target.hostname, target.port, timeout=15)
    try:
        connection.putrequest(spec["method"], spec["path"])
        for name, value in spec["lines"]:
            connection.putheader(name, value)
        connection.endheaders()
        r = connection.getresponse()
        return r.status, r.getheader("Content-Type", ""), r.read(), r.msg
    except Exception as e:  # noqa: BLE001 -- a transport failure is itself an answer to surface
        return "ERR", type(e).__name__, str(e).encode()[:160], None
    finally:
        connection.close()


def fire(base, spec):
    req = urllib.request.Request(base + spec["path"], data=spec.get("body"),
                                 headers=spec.get("headers", {}), method=spec["method"])
    opener = FOLLOWING if spec.get("follow", True) else ONE_HOP
    try:
        with opener.open(req, timeout=15) as r:
            return r.status, r.headers.get("Content-Type", ""), r.read(), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Content-Type", ""), e.read(), e.headers
    except Exception as e:  # noqa: BLE001 -- a transport failure is itself a divergence to surface
        return "ERR", type(e).__name__, str(e).encode()[:160], None


HOST = re.compile(r"https?://[^/\"' ]+")
LANG_DIR = re.compile(rb'<html[^>]*\blang="([^"]*)"[^>]*\bdir="([^"]*)"', re.IGNORECASE)


def cookie_signature(set_cookie_lines):
    """Every Set-Cookie line reduced to what the contract pins: the cookie's name and value and its
    attributes, lower-cased and sorted, since each framework writes them in its own order and case
    (ASP.NET ``samesite=lax``, Spring ``SameSite=Lax``). ``Expires`` is dropped. It is the clock-dependent
    twin of ``Max-Age`` that Symfony adds alongside it, and RFC 6265 has Max-Age win when both are
    present, so it carries nothing the comparison needs and would differ by the second."""
    signatures = []
    for line in set_cookie_lines:
        first, *rest = line.split(";")
        attributes = sorted(part.strip().lower() for part in rest
                            if part.strip() and not part.strip().lower().startswith("expires="))
        signatures.append("; ".join([first.strip()] + attributes))
    return " | ".join(sorted(signatures)) or "(no cookie)"


def relative_location(base, headers):
    """Where a redirect sends the browser. A Location written absolute by one framework and relative by
    another is the same instruction to a browser, so it is compared relative to the instance that sent it.
    An off-site Location stays visible as such."""
    location = headers.get("Location", "") if headers is not None else ""
    if location.startswith(base):
        location = location[len(base):] or "/"
    return f"-> {location}"


def normalise(kind, base, status, content_type, body, headers):
    ct = (content_type or "").split(";")[0].strip().lower()

    if kind == "page":
        # Compare what the contract requires to match -- the resolved language and direction -- not the
        # template engine's byte layout.
        match = LANG_DIR.search(body or b"")
        rendered = f'lang={match.group(1).decode()} dir={match.group(2).decode()}' if match else "(no <html>)"
        return status, ct, rendered

    if kind == "landing":
        # Any redirect status compares equal, and only where it sends the browser is held to agreement.
        answer = "redirect" if isinstance(status, int) and 300 <= status < 400 else status
        return answer, relative_location(base, headers), ""

    if kind == "redirect":
        # The hop itself: status, where it sends the browser, and the cookie it sets.
        cookies = cookie_signature(headers.get_all("Set-Cookie") or []) if headers is not None else "(no cookie)"
        return status, relative_location(base, headers), cookies

    try:
        text = json.dumps(json.loads(body), sort_keys=True) if body else ""
    except Exception:  # noqa: BLE001 -- a non-JSON body compares as its bytes
        text = (body or b"").decode("utf-8", "replace")[:200]

    return status, ct, HOST.sub("HOST", text)


def verdict(spec, normed):
    """None when the case passes, otherwise the label it is reported under and its accepted reason, if
    any. A case that pins an answer fails when any implementation gives another, even if all three agree,
    and no reason in ACCEPTED excuses that."""
    expect = spec.get("expect")
    if expect and any(answer[:len(expect)] != expect for answer in normed.values()):
        return "WRONG  ", None
    if len(set(normed.values())) == 1:
        return None
    reason = ACCEPTED.get(spec["name"])
    return ("KNOWN " if reason else "DIVERGE"), reason


def report(spec, normed, label, reason):
    print(f"\n[{label}] {spec['name']}  [{spec['method']} {spec['path']}]")
    if reason:
        print(f"          accepted: {reason}")
    if spec.get("expect"):
        # Laid out like the answers below it, with an empty part shown as (none) and an unpinned one as (any).
        status, ct, text = ([str(part) or "(none)" for part in spec["expect"]] + ["(any)"] * 3)[:3]
        print(f"          {'expected':32} {status} {ct} | {text}")
    for base, (status, ct, text) in normed.items():
        shown = text if len(text) <= 160 else text[:157] + "..."
        print(f"          {base:32} {status} {ct} | {shown}")


def run(base_urls, direct_urls):
    unexpected = 0
    known = 0
    # Each batch is a set of cases, the addresses they go to, how they are sent, and whether its answers
    # are the applications' own, with no proxy in front. The forwarded-scheme cases go past the proxy, so
    # they run only when the applications' own addresses are known, and only they can show that no
    # application names its runtime.
    batches = [(cases(), base_urls, fire, False)]
    if direct_urls:
        batches.append((forwarded_scheme_cases(), direct_urls, fire_lines, True))

    for specs, targets, send, own_answers in batches:
        for spec in specs:
            answers = {base: send(base, spec) for base in targets}
            if own_answers:
                unexpected += runtime_named(spec, answers)
            normed = {base: normalise(spec["kind"], base, *answer) for base, answer in answers.items()}
            outcome = verdict(spec, normed)
            if outcome is None:
                continue

            label, reason = outcome
            if reason:
                known += 1
            else:
                unexpected += 1
            report(spec, normed, label, reason)

    if not direct_urls:
        print("\nThe forwarded-scheme cases were skipped: no --direct-url or DIFFERENTIAL_DIRECT_URLS given.")
    summary = ", ".join(f"{len(specs)} cases x {len(targets)} implementations" for specs, targets, _, _ in batches)
    print(f"\n{summary}: {unexpected} unexpected divergence(s), {known} known/accepted.")
    return unexpected


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", action="append", dest="base_urls", metavar="URL",
                        help="An implementation to include (repeat). Defaults to the three dev ports.")
    parser.add_argument("--direct-url", action="append", dest="direct_urls", metavar="URL",
                        help="An application's own plain HTTP address, past the proxy, for the forwarded-scheme "
                             "cases (repeat). Defaults to the space-separated list in DIFFERENTIAL_DIRECT_URLS. "
                             "Without either, those cases are skipped.")
    args = parser.parse_args()

    base_urls = args.base_urls or DEFAULT_BASE_URLS
    direct_urls = args.direct_urls or os.environ.get("DIFFERENTIAL_DIRECT_URLS", "").split()
    if len(base_urls) < 2 or len(direct_urls) == 1:
        parser.error("differential testing needs at least two implementations to compare")

    print("Differential conformance across:")
    for base in base_urls + direct_urls:
        print(f"  {base}")

    unexpected = run(base_urls, direct_urls)
    sys.exit(1 if unexpected else 0)


if __name__ == "__main__":
    main()
