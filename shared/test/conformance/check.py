#!/usr/bin/env python3
"""Conformance check for any Astrana Trusted Attestation instance.

Drives a real browser-shaped login against the dev Keycloak realm, then exercises every operation in
shared/contract/openapi.yaml and asserts the behaviour the records in docs/adr and the contract files under
shared/contract require.

The point of this script is that it is implementation-blind. A verifying Astrana instance cannot tell
whether it is talking to the .NET, Java or PHP implementation, so neither should this. The same run must
pass against all three, or they have drifted.

    python shared/test/conformance/check.py --base-url https://localhost:15443 \\
        --sql-command "docker exec -i trusted-attestation-dev-postgres-1 psql -q -U u -d d"

It needs the database as well as the HTTP endpoint. Relationships come into
existence only through grant_member_relationship -- organisation-only, and deliberately not exposed
through the API, because a member who could grant themselves a relationship would defeat the premise
that the organisation vouches for it. So the suite grants the fixtures the way an organisation would,
then exercises what a member can do with them. That does not weaken the blindness: the schema and its
procedures are shared by all three implementations, and nothing here can learn which one is answering.

Destructive. It grants, keys and deletes relationships for its four named test members, and clears
their rows before and after a run so that a run does not inherit the last one's state. Point it at a
development database.

Standard library only, deliberately -- it has to run anywhere any of the three stacks does, without a
package manager.
"""

from __future__ import annotations

import argparse
import base64
import html
import http.cookiejar
import pathlib
import re
import json
import os
import ssl
import threading
import sys
import urllib.error
import urllib.parse
import urllib.request
import zlib
from html.parser import HTMLParser

DEFAULT_BASE_URL = "https://localhost:15443"
DEFAULT_KEYCLOAK = "http://localhost:8081/realms/trusted-attestation-dev"

# Relationships exist only once the organisation has granted them, through a stored procedure with no
# API in front of it, so the suite has to speak to the database as well as over HTTP. How the three
# engines differ lives in shared/test/engines.py, shared with the other suites that reach the database.
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))
from engines import Engine, NAMES, run_sql  # noqa: E402

# The shared strings file every implementation renders its pages from (decision record 34).
# Read here so that a check for "the page says X in French" asks the same file the pages do, rather than
# carrying a copy of a translation that a reviewer may change.
UI_STRINGS = pathlib.Path(__file__).resolve().parents[2] / "ui" / "ui-strings.json"
_ui_strings: dict | None = None


def _ui_string(locale: str, key: str) -> str | None:
    """One UI string, English-backfilled the way the pages backfill it. None when the file is unreadable,
    so a caller can fail its check with that reason rather than quietly matching an empty string."""
    global _ui_strings
    if _ui_strings is None:
        try:
            _ui_strings = json.loads(UI_STRINGS.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            _ui_strings = {}
    if not _ui_strings:
        return None
    return (_ui_strings.get(locale) or {}).get(key) or (_ui_strings.get("en") or {}).get(key)


def _unescaped(body: bytes) -> str:
    """The page as text, with character references resolved. Razor encodes anything outside Basic Latin
    as a numeric reference where Thymeleaf and Blade emit the UTF-8 character, so a French or Arabic
    string has to be looked for after unescaping or the comparison is about encoders, not content."""
    return html.unescape(body.decode("utf-8", "replace"))


def _page_says(body: bytes, locale: str, key: str) -> tuple[bool, str]:
    """Whether the page carries the shared string ``key`` in ``locale``, plus the reason if not. Built to be
    splatted into check_that."""
    text = _ui_string(locale, key)
    if text is None:
        return False, f"{UI_STRINGS} could not be read, so the expected text is unknown"
    return text in _unescaped(body), f"{text!r} is not on the page"


def _html_attribute(body: bytes, name: str) -> str | None:
    """The value of one attribute on the page's <html> element, or None."""
    match = re.search(rb'<html[^>]*\b' + name.encode() + rb'="([^"]*)"', body)
    return match.group(1).decode() if match else None


def csrf_token(body: bytes) -> str | None:
    """The anti-forgery token the self-service page carries in <meta name="csrf-token" content="...">, the
    one its script sends in the X-CSRF-TOKEN header, or None when the page carries none."""
    for tag in re.findall(rb"<meta\b[^>]*>", body):
        if re.search(rb'\bname="csrf-token"', tag):
            content = re.search(rb'\bcontent="([^"]*)"', tag)
            return html.unescape(content.group(1).decode()) if content else None
    return None


def _key_inputs(body: bytes) -> list[dict]:
    """The attributes of every attestation key field on the page: the inputs its script reads a key from,
    which all three implementations mark with data-key-input."""
    return [attributes for attributes in _start_tags(body, "input") if "data-key-input" in attributes]


def _start_tags(body: bytes, name: str) -> list[dict]:
    """The attributes of every start tag of one element name, as a dictionary each."""
    finder = _TagFinder(name)
    finder.feed(body.decode("utf-8", errors="replace"))
    return finder.found


def _header_named(headers: dict, name: str) -> str | None:
    """A response header by name, case-insensitively: the spelling on the wire is the framework's own."""
    return next((value for key, value in headers.items() if key.lower() == name.lower()), None)


# ---------------------------------------------------------------------------------------------------
# Tiny HTTP + HTML plumbing
# ---------------------------------------------------------------------------------------------------


class _FormFinder(HTMLParser):
    """Pulls out every form's action and its input fields. Enough for a Keycloak login page and for the
    self-submitting form an OIDC form_post response mode returns."""

    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict] = []

    def handle_starttag(self, tag: str, attrs: list) -> None:
        attributes = dict(attrs)

        if tag == "form":
            self.forms.append({"action": attributes.get("action", ""), "fields": {}})
        elif tag in ("input", "button") and self.forms:
            name = attributes.get("name")
            if name:
                # <button name=... value=...> is a form control too, and some IdPs (IdentityServer)
                # submit the login with one rather than an <input type=submit>; without capturing it the
                # form posts with no button and the handler reads that as "cancel".
                self.forms[-1]["fields"][name] = attributes.get("value", "")


class _TagFinder(HTMLParser):
    """Collects the attributes of every start tag of one element name. An attribute written without a value,
    such as data-key-input, maps to None."""

    def __init__(self, name: str) -> None:
        super().__init__()
        self.name = name
        self.found: list[dict] = []

    def handle_starttag(self, tag: str, attrs: list) -> None:
        if tag == self.name:
            self.found.append(dict(attrs))


class _LoopbackCookiePolicy(http.cookiejar.DefaultCookiePolicy):
    """Treats loopback as a secure context, the way a browser does.

    The dev Keycloak is served over plain HTTP but marks its session cookies ``Secure`` (it has to --
    they are ``SameSite=None``, which browsers only accept alongside ``Secure``). Browsers make an
    explicit exception for http://localhost and send them anyway; the standard library's cookie jar does
    not, so without this the IdP never sees its own session cookie and answers "Cookie not found".

    Scoped to loopback only, so it cannot mask a genuinely insecure cookie exchange anywhere else.
    """

    _LOOPBACK = {"localhost", "127.0.0.1", "::1"}

    def return_ok_secure(self, cookie, request) -> bool:
        if super().return_ok_secure(cookie, request):
            return True

        return urllib.parse.urlsplit(request.full_url).hostname in self._LOOPBACK


class Unreachable(Exception):
    """The instance did not answer at all. Distinct from answering something unexpected."""


_REDIRECTS = frozenset((301, 302, 303, 307, 308))


def _set_cookie_named(set_cookies: list[str], name: str) -> str | None:
    """The most recent Set-Cookie header line for a cookie of the given name, or None. The last one wins:
    a login can set a placeholder and then the real cookie, and it is the real one that matters."""
    prefix = f"{name}="
    for header in reversed(set_cookies):
        if header.lstrip().startswith(prefix):
            return header
    return None


def _parse_set_cookie(header: str) -> tuple[str, dict[str, str | None]]:
    """One Set-Cookie line as (value, attributes). Attribute names are lower-cased, because each framework
    spells them its own way (ASP.NET writes ``samesite=lax``, Spring ``SameSite=Lax``); values are kept as
    sent; a bare flag such as HttpOnly maps to None."""
    first, *rest = header.split(";")
    value = first.split("=", 1)[1].strip() if "=" in first else ""
    attributes: dict[str, str | None] = {}
    for part in rest:
        name, separator, attribute_value = part.strip().partition("=")
        if name:
            attributes[name.lower()] = attribute_value.strip() if separator else None
    return value, attributes


def _forget_cookie(client: "Client", name: str) -> None:
    """Drops one cookie from a client's jar, so a choice made for one section does not colour the next."""
    for cookie in list(client.jar):
        if cookie.name == name:
            client.jar.clear(cookie.domain, cookie.path, cookie.name)


def _language_switcher(body: bytes, page_url: str) -> dict | None:
    """The language switcher's form, if the page carries one: a form that posts to /set-language on this
    site and offers a ``locale`` control. Found by what the form does rather than by any class name, so a
    template may lay it out as it likes."""
    for form in _forms(body):
        action = urllib.parse.urlsplit(urllib.parse.urljoin(page_url, form["action"]))
        if action.path == "/set-language" and "locale" in form["fields"]:
            return form
    return None


class _SetCookieRecorder(urllib.request.BaseHandler):
    """Records every Set-Cookie header the server sends, across the redirects the opener follows on its
    own. It is the only way to see the session cookie's own attributes -- HttpOnly and SameSite are not
    modelled by the cookie jar, and the response that sets the cookie is usually a redirect the jar
    consumes before any caller sees it."""

    # After the cookie processor, so the header is still present when this runs.
    handler_order = 920

    def __init__(self) -> None:
        self.set_cookies: list[str] = []
        # Every redirect Location the opener followed. Used to spot a SAML AuthnRequest even when the IdP
        # redirects on to its own login page and drops the SAMLRequest from the URL the flow finally lands
        # on (SimpleSAMLphp does this; Keycloak keeps it in the SSO URL) -- the request was still there, one
        # hop earlier, and this is where that hop is visible.
        self.locations: list[str] = []

    def http_response(self, request, response):
        self.set_cookies.extend(response.headers.get_all("Set-Cookie") or [])
        location = response.headers.get("Location")
        if location:
            self.locations.append(location)
        return response

    https_response = http_response


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """A redirect handler that does not redirect, so a single hop can be inspected before it is taken."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


# Every HTTP request is bounded: without a timeout, urllib blocks on the default socket timeout, which is
# None -- forever. A stalled response (a single-threaded dev server wedged on a slow backchannel call, a
# half-open connection) then hangs the whole run until the operating system gives up on the socket, which
# can take over an hour. A request that has not answered in this many seconds is not a slow answer, it is a
# dead one: let it raise so the caller fails fast and, in the matrix, the attempt is retried in seconds.
REQUEST_TIMEOUT = 30


class Client:
    """A cookie-keeping HTTP client that follows redirects, like a browser would."""

    def __init__(self, verify_tls: bool) -> None:
        self.verify_tls = verify_tls
        self.jar = http.cookiejar.CookieJar(policy=_LoopbackCookiePolicy())

        context = ssl.create_default_context()
        if not verify_tls:
            # Local development uses the ASP.NET dev certificate and self-signed certificates for the
            # other two stacks. Never pass --insecure at anything but a dev instance.
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE

        self.recorder = _SetCookieRecorder()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            urllib.request.HTTPSHandler(context=context),
            self.recorder,
        )

        # A second opener that stops on the first redirect rather than following it, sharing this
        # client's cookies, so the login flow's first hop can be read before it is walked.
        self.no_redirect = urllib.request.build_opener(
            _NoRedirect,
            urllib.request.HTTPCookieProcessor(self.jar),
            urllib.request.HTTPSHandler(context=context),
        )
        self.last_url = ""

        # Set during login when the instance turns out to speak SAML; see _saml_acs_url.
        self.saml_acs_url: str | None = None

    def request(self, method: str, url: str, body: bytes | None = None,
                headers: dict | None = None) -> tuple[int, bytes, dict]:
        request = urllib.request.Request(url, data=body, method=method, headers=headers or {})

        try:
            with self.opener.open(request, timeout=REQUEST_TIMEOUT) as response:
                self.last_url = response.geturl()
                return response.status, response.read(), dict(response.headers)
        except urllib.error.HTTPError as error:
            self.last_url = error.geturl()
            return error.code, error.read(), dict(error.headers)
        except urllib.error.URLError as error:
            # Nothing listening, TLS refused, DNS gone. Not an answer from the instance, so it gets a
            # code no instance can return -- and the run stops on it, rather than raising through a
            # hundred checks and ending in a stack trace that says nothing about what to do next.
            raise Unreachable(f"{url}: {error.reason}") from error

    def get(self, url: str) -> tuple[int, bytes, dict]:
        return self.request("GET", url)

    def post_form(self, url: str, fields: dict) -> tuple[int, bytes, dict]:
        body = urllib.parse.urlencode(fields).encode()
        return self.request("POST", url, body,
                            {"Content-Type": "application/x-www-form-urlencoded"})

    def json_request(self, method: str, url: str, payload: dict | None = None) -> tuple[int, dict | None]:
        body = json.dumps(payload).encode() if payload is not None else None
        headers = {"Content-Type": "application/json"} if payload is not None else {}
        status, raw, _ = self.request(method, url, body, headers)

        if not raw:
            return status, None

        try:
            return status, json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return status, None

    def redirect_chain(self, url: str, max_hops: int = 10) -> list[str]:
        """Every URL a GET of ``url`` passes through, taken one hop at a time so each is visible before
        it is followed -- which is how the login flow's redirect to the IdP can be inspected for the
        parameters it carries. Stops at the first non-redirect response, or after ``max_hops``."""
        visited = [url]
        for _ in range(max_hops):
            try:
                with self.no_redirect.open(url, timeout=REQUEST_TIMEOUT) as response:
                    location = response.headers.get("Location") if response.status in _REDIRECTS else None
            except urllib.error.HTTPError as error:
                location = error.headers.get("Location") if error.code in _REDIRECTS else None
            except urllib.error.URLError as error:
                raise Unreachable(f"{url}: {error.reason}") from error

            if not location:
                return visited

            url = urllib.parse.urljoin(url, location)
            visited.append(url)

        return visited

    def request_without_following(self, method: str, url: str, body: bytes | None = None,
                                  headers: dict | None = None) -> tuple[int, dict, list[str]]:
        """One hop only: the status, the headers and every Set-Cookie line of the response as the server
        sent it, before any redirect is taken. The language switcher answers with a redirect that carries
        its cookie, and both the redirect and the cookie are what is under test, so neither may be
        consumed on the way. Cookies still land in this client's jar, as they would in a browser."""
        request = urllib.request.Request(url, data=body, method=method, headers=headers or {})

        try:
            with self.no_redirect.open(request, timeout=REQUEST_TIMEOUT) as response:
                return (response.status, dict(response.headers),
                        response.headers.get_all("Set-Cookie") or [])
        except urllib.error.HTTPError as error:
            return error.code, dict(error.headers), error.headers.get_all("Set-Cookie") or []
        except urllib.error.URLError as error:
            raise Unreachable(f"{url}: {error.reason}") from error


# ---------------------------------------------------------------------------------------------------
# Assertions
# ---------------------------------------------------------------------------------------------------


# Every section the suite runs, in order. Declared rather than inferred, so that a section which never
# starts is a detectable event instead of an absence nobody can see. A run that skips half its sections
# otherwise reports a small, healthy-looking total, so "19 passed, 1 failed" can mean 47 checks never ran.
#
# The assertion consumer service is legitimately conditional: it exists only on a SAML deployment. It is
# the one section allowed to be absent, and it announces itself when it skips.
REQUIRED_SECTIONS = (
    "Manifest (public, no auth)",
    "the public landing page",
    "the language switcher and the signed-out page",
    "what the organisation has granted",
    "Anonymous callers",
    "alice: two relationships, each with its own key",
    "PUT is not an upsert",
    "bob: one key, one relationship",
    "carol: relationship_subtype",
    "dave: signed in, holding nothing",
    "alice: removing one relationship leaves the other",
    "the application records what it did",
    "carol: self-revoke is one-directional",
    "the organisation revokes, extends, and lets a relationship lapse",
    "malformed input is answered, not crashed on",
    "two members registering the same key at once",
    "the self-service page in the member's chosen language",
    "the login, the session cookie, and signing out",
    "response hardening",
)

OPTIONAL_SECTIONS = ("the assertion consumer service",)



class Results:
    def __init__(self) -> None:
        self.passed = 0
        self.failures: list[str] = []

        # Sections that actually began, and why the run stopped early if it did.
        self.sections: list[str] = []
        self.aborted: str | None = None

        # Every check, in order, as {section, name, status}. Kept alongside the counts above so a run can
        # be written out for the matrix report (shared/test/integration-report.py). The printed output is
        # unchanged.
        self.records: list[dict] = []
        self._section: str | None = None

    def _record(self, name: str, status: str, detail: str = "") -> None:
        # detail carries why a check failed, so a consumer of the JSON (the matrix) can tell a login flake
        # ("login failed") from a behavioural divergence and decide whether re-running could change it.
        self.records.append({"section": self._section, "name": name, "status": status, "detail": detail})

    def section(self, title: str, detail: str = "") -> None:
        """Starts a section, and records that it started."""
        self.sections.append(title)
        self._section = title
        print()
        print(f"{title}{detail}")

    def abort(self, reason: str) -> None:
        """The run could not continue. Distinct from a check failing: the rest never ran."""
        self.aborted = reason

    def missing_sections(self) -> list[str]:
        return [title for title in REQUIRED_SECTIONS if title not in self.sections]

    def check(self, description: str, actual, expected) -> None:
        if actual == expected:
            self.passed += 1
            self._record(description, "pass")
            print(f"  PASS  {description}")
        else:
            self.failures.append(f"{description}: expected {expected!r}, got {actual!r}")
            self._record(description, "fail", f"expected {expected!r}, got {actual!r}")
            print(f"  FAIL  {description}: expected {expected!r}, got {actual!r}")

    def check_that(self, description: str, condition: bool, detail: str = "") -> None:
        if condition:
            self.passed += 1
            self._record(description, "pass")
            print(f"  PASS  {description}")
        else:
            self.failures.append(f"{description}{': ' + detail if detail else ''}")
            self._record(description, "fail", detail)
            print(f"  FAIL  {description}{': ' + detail if detail else ''}")


# ---------------------------------------------------------------------------------------------------
# Login
# ---------------------------------------------------------------------------------------------------


# Fields that mark a form as one the protocol expects the browser to post on automatically, rather than
# one a person fills in. SAML uses them in both directions: SAMLRequest on the way to the IdP under the
# HTTP-POST binding, SAMLResponse on the way back. OIDC uses them only on the way back, and only when the
# response mode is form_post.
AUTO_SUBMIT_FIELDS = ("SAMLRequest", "SAMLResponse", "code", "id_token")

MAX_LOGIN_STEPS = 8


def _forms(body: bytes) -> list[dict]:
    parser = _FormFinder()
    parser.feed(body.decode("utf-8", errors="replace"))
    return parser.forms



def _acs_from_authn_request(encoded: str | None) -> str | None:
    """Reads the assertion consumer URL out of an encoded AuthnRequest.

    The suite must not know any implementation's URL layout, and does not have to: SAML puts the return
    address inside the request itself, so asking the request is both implementation-blind and the same
    thing the IdP does with it.

    Returns None for anything that is not a readable AuthnRequest, which is also how the caller tells an
    OIDC deployment from a SAML one.
    """
    if not encoded:
        return None

    try:
        decoded = base64.b64decode(encoded)
    except Exception:
        return None

    # Redirect binding deflates the request; POST binding sends it as plain base64.
    try:
        xml = zlib.decompress(decoded, -15)
    except zlib.error:
        xml = decoded

    match = re.search(rb'AssertionConsumerServiceURL="([^"]+)"', xml)
    return match.group(1).decode("utf-8", "replace") if match else None


def _acs_from_redirect(idp_url: str) -> str | None:
    """The HTTP-Redirect binding: the request rides in the query string."""
    query = urllib.parse.parse_qs(urllib.parse.urlparse(idp_url).query)
    return _acs_from_authn_request(query.get("SAMLRequest", [None])[0])


# Set from --browser-login. Off by default: the built-in login below needs nothing installed, which is
# what lets this suite run anywhere the three stacks do.
BROWSER_LOGIN = False


def _verify_session(client: Client, base_url: str) -> bool:
    # Reaching a 200 back on the application is not proof of a session. A SAML assertion the app rejects
    # -- a stale IdP certificate after the realm is reimported is the everyday cause -- still lands the
    # browser on a page that renders, and every later call then answers 401. That reads as fifty broken
    # endpoints rather than one failed login, which is how an afternoon gets spent looking in the wrong
    # place. Asking the API settles it: 401 here means there is no session, whatever the page said.
    probe, _ = client.json_request("GET", f"{base_url}/api/v1/me")
    if probe == 401:
        print("    login: the flow completed but no session was established -- the app rejected the "
              "assertion or token. If the IdP was recreated, its signing key changed and the app needs "
              "restarting to pick up the new one.")
        return False
    return True


def _cookie_from_playwright(cookie: dict) -> http.cookiejar.Cookie:
    """Turns one browser cookie into the standard library's cookie type, so the application session the
    browser established carries on the urllib client every check below already uses."""
    domain = cookie.get("domain", "")
    # http.cookiejar files a host-only cookie under the "effective request host", and for a single-label
    # host like "localhost" that is "localhost.local" -- the same transform it applies to the request
    # when matching. Store the bare name and the cookie is never returned, and the result is a 401 that
    # looks like a rejected session rather than a cookie that was never sent.
    if domain and "." not in domain and not domain.replace(".", "").isdigit():
        domain += ".local"
    expires = cookie.get("expires")
    if expires is not None and expires < 0:   # Playwright uses -1 for a session cookie
        expires = None
    return http.cookiejar.Cookie(
        version=0, name=cookie["name"], value=cookie.get("value", ""),
        port=None, port_specified=False,
        domain=domain, domain_specified=False, domain_initial_dot=domain.startswith("."),
        path=cookie.get("path", "/"), path_specified=True,
        secure=bool(cookie.get("secure")),
        expires=int(expires) if expires else None,
        discard=expires is None, comment=None, comment_url=None,
        rest={"HttpOnly": None} if cookie.get("httpOnly") else {}, rfc2109=False,
    )


def _browser_login(client: Client, base_url: str, username: str, password: str) -> bool:
    """Login through a real browser, for IdPs whose sign-in page is a JavaScript application rather than
    a server-rendered form.

    Opt-in with --browser-login, because the built-in form login's whole virtue is needing nothing
    installed. This trades that for a browser -- Playwright driving the system's own Edge, so nothing is
    downloaded -- which is the only thing that can drive a provider like Authentik or Zitadel, whose form
    does not exist until its script has built it. What it produces is exactly what the form login does:
    the application's own session cookies, handed to the same jar, so no check after this can tell which
    way the session was established.
    """
    try:
        from playwright.sync_api import (sync_playwright, Error as BrowserError,
                                         TimeoutError as BrowserTimeout)
    except ImportError:
        print("    login: --browser-login needs Playwright -- `pip install playwright`. It drives the "
              "system Edge, so there is no browser to download. Without the flag, the built-in form "
              "login is used instead.")
        return False

    app = base_url.rstrip("/")
    app_host = urllib.parse.urlsplit(base_url).hostname
    text_field = ("input[type=text]:visible, input[type=email]:visible, input:not([type]):visible, "
                  "input[name*=user i]:visible, input[name*=login i]:visible, input[name*=email i]:visible")

    def settle(page) -> None:
        try:
            page.wait_for_load_state("networkidle", timeout=8000)
        except BrowserTimeout:
            pass

    def onscreen(locator):
        # The first match that is actually on the page. A provider often plants off-screen copies of the
        # username and password fields for password managers to find -- Authentik parks them at
        # (-2000, -2000) -- and they answer :visible, so filling the first match fills a decoy and the
        # real field stays empty. Pick the one with a box inside the viewport instead.
        for index in range(min(locator.count(), 12)):
            item = locator.nth(index)
            box = item.bounding_box()
            if box and box["x"] >= 0 and box["y"] >= 0:
                return item
        return None

    with sync_playwright() as pw:
        # Playwright's own bundled Chromium, not the system Edge (channel="msedge"). Same Chromium engine,
        # so the cookie and SameSite semantics the SAML correlation depends on are identical, but without
        # Edge's background services (updater, telemetry, its own helper processes), which matters when
        # several of these logins run while all three apps and an IdP are resident: the lighter browser is
        # far less likely to be starved and time the login out.
        browser = pw.chromium.launch(headless=True)
        context = browser.new_context(ignore_https_errors=not client.verify_tls)
        page = context.new_page()

        # Detect a SAML deployment the same way the form login does: the redirect to the IdP carries the
        # AuthnRequest as a SAMLRequest query parameter. Capturing the ACS from it here means the
        # SAML-only sections (and the PKCE skip) apply under --browser-login too, not only under form login.
        def capture_saml(request):
            if client.saml_acs_url:
                return
            # The AuthnRequest may go to the IdP as a redirect-binding query parameter or, as Spring does,
            # as a POST-binding form field; look in both.
            encoded = urllib.parse.parse_qs(urllib.parse.urlsplit(request.url).query).get("SAMLRequest", [None])[0]
            if encoded is None and request.post_data and "SAMLRequest=" in request.post_data:
                encoded = urllib.parse.parse_qs(request.post_data).get("SAMLRequest", [None])[0]
            acs = _acs_from_authn_request(encoded)
            if acs:
                client.saml_acs_url = acs
        page.on("request", capture_saml)

        try:
            page.goto(f"{app}/me", wait_until="domcontentloaded")
        except BrowserError as error:
            print(f"    login: the browser could not reach the application -- {error}")
            browser.close()
            return False

        # Walk whatever the provider puts up -- an identification step, then a password step, maybe a
        # consent screen, each possibly its own page -- filling what can be filled and submitting, until
        # the browser is back on the application. A browser is precisely the thing that can do this
        # without being told the provider's shape in advance.
        for _ in range(MAX_LOGIN_STEPS):
            if page.url.startswith(app):
                break
            settle(page)
            filled = None
            acted = False
            try:
                password_box = onscreen(page.locator("input[type=password]:visible"))
                user_box = onscreen(page.locator(text_field))
                if password_box is not None:
                    # A password step -- fill the username too if the provider shows both together.
                    if user_box is not None:
                        user_box.fill(username)
                    password_box.fill(password)
                    filled = password_box
                elif user_box is not None:
                    # An identification step on its own: username now, password on the next page.
                    user_box.fill(username)
                    filled = user_box

                # Submit with a real submit control -- <button type=submit> (Authentik) or <input
                # type=submit> (Keycloak) -- and only Enter as a fallback for a plain form that has none.
                # The restriction to type=submit is deliberate: a broader "any button" click lands on the
                # language switch and gets stuck, while Authentik's form does not submit on Enter at all, so
                # neither approach alone is enough.
                control = onscreen(page.locator("button[type=submit]:visible, input[type=submit]:visible"))
                if control is not None:
                    control.click()
                    acted = True
                elif filled is not None:
                    filled.press("Enter")
                    acted = True
            except BrowserError:
                pass

            # Wait for the hand-back to the application, or for the next stage to render. wait_for_url
            # returns the instant the app is reached, so the final redirect is not slowed by a fixed sleep.
            # This also waits out the provider's own post-login step -- Authentik runs an authorization
            # stage that has nothing to fill and redirects on its own, sometimes past a short wait -- so a
            # slow hand-back is not mistaken for a dead end. Only a wait that expires with nothing here to
            # have acted on is a real dead end; anything else is a stage still in flight.
            try:
                page.wait_for_url(lambda url: url.startswith(app), timeout=12000)
            except BrowserTimeout:
                if not acted:
                    break

        # The credentials are in; what remains is the provider's own post-login hand-back -- an
        # authorization stage that redirects on its own, then (for SAML) the assertion POST to the ACS and
        # the application validating it, creating the session, and redirecting to the signed-in page. That
        # stage has nothing to fill, so the loop above stops acting on it, and on a cold or busy app it can
        # take longer than a single step's wait -- at which point the loop reads a redirect still in flight
        # as a dead end. It is not: a login that has authenticated will hand back if given time. Wait for it
        # once more, generously, before concluding the browser never returned. A genuine dead end simply
        # lets this expire (only ever on a real failure), while a slow-but-valid login now completes rather
        # than being lost to a 12-second race -- the intermittent "signed in, but never returned" this used
        # to produce, hitting whichever member's login happened to fall the wrong side of the window.
        returned = page.url.startswith(app)
        if not returned:
            try:
                page.wait_for_url(lambda url: url.startswith(app), timeout=30000)
                returned = True
            except BrowserTimeout:
                pass
        cookies = context.cookies() if returned else []
        browser.close()

    if not returned:
        print("    login: signed in, but the browser never returned to the application. The provider "
              "may want a field this does not fill, or a consent this does not grant.")
        return False

    # Keep the browser's own view of the app-host cookies (name, HttpOnly, Secure, SameSite, and
    # session-vs-persistent) so the session-cookie attribute checks can read them. A browser login never
    # sees the raw Set-Cookie headers those checks normally parse, but Playwright parsed the very same
    # attributes off them -- so this is the same fact, from the browser that received it.
    client.browser_cookies = [c for c in cookies if c.get("domain", "").lstrip(".") == app_host]
    for cookie in client.browser_cookies:
        client.jar.set_cookie(_cookie_from_playwright(cookie))
    return _verify_session(client, base_url)


def login(client: Client, base_url: str, username: str, password: str) -> bool:
    """Completes a login the way a member's browser would.

    Protocol-agnostic on purpose, because which protocol an instance speaks is a deployment choice and
    the rest of the suite must not be able to tell. This walks whatever sequence the IdP puts in front of
    it: auto-submitting forms get posted on, a credential form gets filled in once, and anything else
    means the flow is finished or has gone wrong.

    That loop is what a browser does. Writing it as a fixed sequence of steps instead would bake in one
    protocol's shape -- OIDC redirects to the IdP, while SAML under the HTTP-POST binding gets there by
    posting a form, and neither is more correct than the other.

    A provider whose sign-in page is a JavaScript application rather than a server-rendered form has no
    form for this to walk until its script has run, so with --browser-login the whole login is handed to
    a real browser instead (see _browser_login). Everything after login is untouched either way.
    """
    if BROWSER_LOGIN:
        return _browser_login(client, base_url, username, password)

    status, body, _ = client.get(f"{base_url}/me")

    if status != 200:
        print(f"    login: unexpected status {status} fetching the self-service page")
        return False

    # The app has just sent this browser to its IdP. If it did so with a SAML AuthnRequest, that request
    # names the endpoint the assertion comes back to -- which is the one endpoint a SAML deployment
    # exposes to anybody who can post to it.
    client.saml_acs_url = _acs_from_redirect(client.last_url)

    # An IdP that hands off to its own login page (SimpleSAMLphp redirects to a loginuserpass URL keyed by
    # an AuthState) has dropped the SAMLRequest from the URL the flow landed on, so the check above misses
    # it. It was there one redirect earlier; scan the whole chain the opener just followed.
    if not client.saml_acs_url:
        for location in client.recorder.locations:
            client.saml_acs_url = _acs_from_redirect(location)
            if client.saml_acs_url:
                break

    credentials_submitted = False

    for _ in range(MAX_LOGIN_STEPS):
        forms = _forms(body)

        automatic = [f for f in forms if any(k in f["fields"] for k in AUTO_SUBMIT_FIELDS)]
        if automatic:
            form = automatic[0]

            # The other SAML binding. Spring Security posts its AuthnRequest as a self-submitting form
            # rather than putting it in a redirect, so a suite that only looked at the query string would
            # decide this deployment was not SAML and skip the checks that only apply to one -- passing
            # by not running, which is worse than failing.
            if "SAMLRequest" in form["fields"] and not client.saml_acs_url:
                client.saml_acs_url = _acs_from_authn_request(form["fields"]["SAMLRequest"])

            action = urllib.parse.urljoin(client.last_url, form["action"])
            status, body, _ = client.post_form(action, form["fields"])
            continue

        credential_forms = [
            f for f in forms
            if any(name.lower().rsplit(".", 1)[-1] == "password" for name in f["fields"])
            or "authenticate" in f["action"] or "login-actions" in f["action"]
        ]

        if credential_forms and not credentials_submitted:
            form = credential_forms[0]
            action = urllib.parse.urljoin(client.last_url, form["action"])

            fields = dict(form["fields"])
            # The username field's name is the IdP's choice, not a standard, and its case is too: Keycloak
            # uses "username", Dex "login", IdentityServer "Username", others "email" or "j_username". Fill
            # the form's own field whatever it is called -- overwriting it, so an empty rendered value does
            # not win -- and add the common spellings as a fallback for a form whose empty inputs were not
            # captured. An IdP ignores the names it does not have; the suite is not wired to one provider.
            username_names = {"username", "login", "email", "j_username", "user"}
            matched_username = False
            for name in list(fields):
                # Match on the last dotted segment: IdentityServer names its fields Input.Username /
                # Input.Password after a nested model, where Keycloak and Dex use the bare name.
                leaf = name.lower().rsplit(".", 1)[-1]
                if leaf in username_names:
                    fields[name] = username
                    matched_username = True
                elif leaf == "password":
                    fields[name] = password
                elif leaf == "button":
                    # A form with an explicit submit button expects its value: IdentityServer's login
                    # button is "login", and its cancel button shares the name, so pin it rather than let
                    # whichever the parser saw last decide.
                    fields[name] = "login"
            if not matched_username:
                for name in username_names:
                    fields[name] = username
            if not any(n.lower().rsplit(".", 1)[-1] == "password" for n in fields):
                fields["password"] = password

            status, body, _ = client.post_form(action, fields)
            credentials_submitted = True
            continue

        # Nothing left to submit: either we are back on the app, or the IdP is showing us something it
        # does not expect a browser to post on -- an error page.
        break

    if "/realms/" in client.last_url:
        print("    login: still at the IdP after submitting credentials -- wrong password, or the realm "
              "did not import as expected")
        return False

    if status != 200:
        print(f"    login: finished with status {status} at {client.last_url}")
        return False

    # Reaching a 200 back on the application is not proof of a session. A SAML assertion the app rejects
    # -- a stale IdP certificate after the realm is reimported is the everyday cause -- still lands the
    # browser on a page that renders, and every later call then answers 401. That reads as fifty broken
    # endpoints rather than one failed login, which is how an afternoon gets spent looking in the wrong
    # place. Asking the API settles it: 401 here means there is no session, whatever the page said.
    return _verify_session(client, base_url)


def random_key() -> str:
    return base64.b64encode(os.urandom(32)).decode()


# ---------------------------------------------------------------------------------------------------
# The checks
# ---------------------------------------------------------------------------------------------------

# The members this suite works with, and the identifiers the IAM knows them by.
#
# Pinned in shared/test/keycloak/trusted-attestation-dev-realm.json rather than discovered, because the suite has
# to grant relationships before anyone logs in and there is no API that would tell it these afterwards.
# Keycloak uses the same value for the OIDC `sub` and the SAML persistent NameID, so one constant serves
# both and the suite stays unable to tell which protocol an instance speaks.
#
# If an implementation keys members by something else -- a username, an email -- the first check in
# alice's section fails with her relationships missing, which is the intended way to find that out.
SUBJECTS = {
    "alice": "11111111-1111-4111-8111-111111111111",
    "bob": "22222222-2222-4222-8222-222222222222",
    "carol": "33333333-3333-4333-8333-333333333333",
    "dave": "44444444-4444-4444-8444-444444444444",
}

# What the organisation has granted, before any of these people has ever logged in.
#
# alice holds two at once, on purpose: it is the case the whole data model is built around, and the
# one where a mistake is invisible from a single-relationship test. dave holds nothing, also on purpose.
FIXTURES = (
    ("alice", "employee", None),
    ("alice", "client", None),
    ("bob", "client", None),
    ("carol", "licensed_professional", "Fellow"),
)


def quoted(value: str | None) -> str:
    """A SQL string literal, or NULL. Values here are the suite's own constants, never caller input."""
    if value is None:
        return "NULL"
    return "'" + value.replace("'", "''") + "'"


class Database:
    """The organisation's side of the system.

    Granting, revoking and extending are deliberately not in the API -- a member who could grant
    themselves a relationship would defeat the premise that the organisation vouches for it (decision record 16).
    So the suite reaches them the way an organisation does: through the
    stored procedures, over a client the caller supplied.

    This is still implementation-blind. The schema and its procedures are shared by all three
    implementations; nothing here knows or can learn which one is answering the HTTP requests.
    """

    def __init__(self, sql_command: str, engine: Engine) -> None:
        self.sql_command = sql_command
        self.engine = engine

    def audit_rows(self, who: str, event_type: str, relationship_type: str) -> int:
        """How many audit entries exist for one member, event and relationship.

        Read rather than inferred, because the application writes these itself -- key_registered,
        key_cleared and key_removed are the events no stored procedure produces -- and nothing else in
        this suite looks at the trail. A mutation that simply skipped the insert went unnoticed until the mutation
        harness pointed it out: the API answered correctly, the key was stored, and the record of it
        happening was gone.
        """
        code, out = run_sql(self.sql_command, self.engine.prelude + (
            "SELECT COUNT(*) FROM audit_log "
            f"WHERE iam_subject_id = {quoted(SUBJECTS[who])} "
            f"AND event_type = {quoted(event_type)} "
            f"AND relationship_type = {quoted(relationship_type)};"))

        if code != 0:
            return -1

        numbers = re.findall(r"^\s*(\d+)\s*$", out, re.M)
        return int(numbers[-1]) if numbers else -1

    def run(self, sql: str) -> bool:
        code, output = run_sql(self.sql_command, self.engine.prelude + sql)
        if code != 0:
            print(f"    sql failed: {output.strip()[:200]}")
        return code == 0

    def reset(self) -> bool:
        """Back to nothing granted, so a run does not inherit the last one's state.

        audit_log is left alone. It is append-only by design, and a suite that tidied up after itself
        there would be modelling something no deployment is allowed to do.
        """
        subjects = ", ".join(quoted(s) for s in SUBJECTS.values())
        return self.run(f"DELETE FROM member_relationships WHERE iam_subject_id IN ({subjects});")

    def grant(self, who: str, relationship_type: str, subtype: str | None = None) -> bool:
        return self.run(self.engine.call(
            "grant_member_relationship",
            quoted(SUBJECTS[who]), quoted(relationship_type), quoted(subtype),
            quoted("conformance-suite"),
        ))

    def regrant(self, who: str, relationship_type: str, subtype: str | None = None) -> bool:
        """A fresh, unkeyed row, whatever state the previous section left behind."""
        self.run(f"DELETE FROM member_relationships WHERE iam_subject_id = {quoted(SUBJECTS[who])} "
                 f"AND relationship_type = {quoted(relationship_type)};")
        return self.grant(who, relationship_type, subtype)

    def org_revoke(self, who: str, relationship_type: str) -> bool:
        return self.run(self.engine.call(
            "revoke_member_relationship",
            quoted(SUBJECTS[who]), quoted(relationship_type), quoted("conformance-suite"),
        ))

    def org_extend(self, who: str, relationship_type: str, expires_at: str | None) -> bool:
        return self.run(self.engine.call(
            "extend_member_relationship",
            quoted(SUBJECTS[who]), quoted(relationship_type),
            self.engine.timestamp(expires_at) if expires_at else "NULL",
            quoted("conformance-suite"),
        ))


def relationship(me: dict | None, relationship_type: str) -> dict | None:
    """One entry out of a MeResponse, by type."""
    if not me:
        return None
    for entry in me.get("relationships") or []:
        if entry.get("relationship_type") == relationship_type:
            return entry
    return None


def types_held(me: dict | None) -> list[str]:
    if not me:
        return []
    return sorted(e.get("relationship_type") for e in me.get("relationships") or [])



def run(base_url: str, verify_tls: bool, sql_command: str, database: str) -> Results:
    results = Results()
    base_url = base_url.rstrip("/")
    api = f"{base_url}/api/v1"

    db = Database(sql_command, Engine(database))
    anonymous = Client(verify_tls)

    def key_url(relationship_type: str) -> str:
        return f"{api}/me/relationships/{relationship_type}/key"

    def revoke_url(relationship_type: str) -> str:
        return f"{api}/me/relationships/{relationship_type}/revoke"

    def page_token(client: Client) -> str:
        """The anti-forgery token on the client's self-service page, read the way the page's script reads it."""
        _, body, _ = client.get(f"{base_url}/me")
        return csrf_token(body) or ""

    def self_revoke(client: Client, relationship_type: str, token: str | None = None) -> tuple[int, bytes, dict]:
        """A member's self-revoke as the self-service page sends it: a plain POST carrying the page's
        anti-forgery token in the X-CSRF-TOKEN header. ``token`` sends a token of the caller's choosing
        instead, and an empty one sends no header at all."""
        token = page_token(client) if token is None else token
        return client.request("POST", revoke_url(relationship_type), None,
                              {"X-CSRF-TOKEN": token} if token else {})

    def attest(key: str) -> tuple[int, dict | None]:
        return anonymous.json_request("POST", f"{api}/attest", {"public_key": key})

    # -- Manifest -----------------------------------------------------------------------------------
    results.section("Manifest (public, no auth)")
    status, manifest = anonymous.json_request("GET", f"{base_url}/.well-known/ata-manifest.json")
    results.check("manifest is served", status, 200)

    # The JSON content type is exactly application/json, with no charset parameter -- JSON is UTF-8 by
    # definition, so the parameter carries nothing, and one stack appending it while the others do not is
    # a byte-level split a peer could trip over.
    _, _, manifest_headers = anonymous.request("GET", f"{base_url}/.well-known/ata-manifest.json")
    manifest_content_type = {k.lower(): v for k, v in manifest_headers.items()}.get("content-type")
    results.check("the manifest content type is exactly application/json, no charset parameter",
                  manifest_content_type, "application/json")

    if manifest:
        results.check("manifest_version is 1", manifest.get("manifest_version"), 1)
        for field in ("default_locale", "name", "relationship_types", "enrollment_url", "attestation_url"):
            results.check_that(f"manifest has {field}", field in manifest)
        results.check_that("attestation_url is https",
                           str(manifest.get("attestation_url", "")).startswith("https://"))
        results.check_that("name is locale-keyed", isinstance(manifest.get("name"), dict))

        # Optional, but when present it must be embedded rather than linked: a URL would make every
        # consumer fetch it from the org, which is the side-channel the anonymous /attest design avoids.
        logo = manifest.get("logo_data")
        if logo is not None:
            results.check_that("logo_data is a string, not locale-keyed", isinstance(logo, str))
            results.check_that("logo_data is an embedded data URI, not a URL",
                               isinstance(logo, str) and logo.startswith("data:image/"))
            results.check_that("logo_data is base64", isinstance(logo, str) and ";base64," in logo)
        default_locale = manifest.get("default_locale")
        results.check_that("name has an entry for default_locale",
                           isinstance(manifest.get("name"), dict) and default_locale in manifest["name"])

        # The manifest is built from an allowlist of public fields, never from the configuration object.
        # Public and private settings share one file, so a blanket serialisation would publish the
        # identity-provider secret and the database connection. Two checks: no key outside the public
        # set, and nothing credential-shaped anywhere in the document.
        public_fields = {
            "manifest_version", "default_locale", "name", "description", "website", "support_url",
            "logo_data", "logo_data_dark", "privacy_notice_url", "jurisdictions", "relationship_types",
            "enrollment_url", "attestation_url",
        }
        unexpected = sorted(set(manifest) - public_fields)
        results.check_that("the manifest carries only the public fields", not unexpected,
                           f"unexpected fields {unexpected}")
        # Named on its own, although the allowlist above already refuses it: the locales an instance offers
        # are the pages' concern (decision record 34), and a consumer reads which locales the
        # organisation's text comes in from the locale-keyed fields themselves, not from a list.
        results.check_that("supported_locales is not a manifest field",
                           "supported_locales" not in manifest, "the manifest lists supported_locales")
        serialised = json.dumps(manifest).lower()
        credential_shaped = [w for w in ("secret", "password", "connectionstring", "connection_string",
                                         "client_id", "clientid", "authority", "issuer")
                             if w in serialised]
        results.check_that("the manifest contains nothing credential-shaped", not credential_shaped,
                           f"found {credential_shaped}")

    # The manifest is the liveness and readiness probe (decision record 32): a server that answers it
    # with 200 started with everything it needs. No separate health endpoint exists, so a framework's own
    # one (Laravel registers /up by default) must not be left answering, or an operator is handed a second
    # probe that says nothing about the manifest and differs between implementations.
    status, _, _ = anonymous.request("GET", f"{base_url}/up")
    results.check_that("there is no separate health endpoint: /up is not 200, the manifest is the probe",
                       status != 200, "GET /up answered 200")

    # -- The landing page ---------------------------------------------------------------------------
    #
    # What enrollment_url points at, and the first thing a prospective member meets. It has to work with
    # no session at all, and it is the one page that SHOULD be indexable -- the authenticated page is
    # exactly the opposite, since its URL appearing in a search index would say this person has a
    # relationship here.
    results.section("the public landing page")

    status, landing_body, _ = anonymous.request("GET", f"{base_url}/")
    results.check("/ serves a page without a session, not a login redirect", status, 200)
    results.check_that("it links the member to sign in",
                       b"/me" in landing_body, "no path to /me anywhere on the page")
    results.check_that("it is not marked noindex -- being findable is its purpose",
                       b'name="robots"' not in landing_body,
                       "the landing page carries a robots meta tag")
    results.check_that("it carries the shared styling",
                       b"trusted-attestation.css" in landing_body)
    results.check_that("it loads the organisation override stylesheet",
                       b"theme-overrides.css" in landing_body)
    results.check("the landing page carries exactly one h1, the page's heading",
                  landing_body.count(b"<h1"), 1)

    # The served language follows the whole q-ranked Accept-Language, not just its first entry, and falls
    # back to English -- never to the server's own machine locale -- when nothing is acceptable or the
    # header is absent. The page must advertise the language it is actually rendered in via <html lang>.
    def landing_lang(accept_language: str | None) -> str | None:
        headers = {"Accept-Language": accept_language} if accept_language is not None else {}
        _, body, _ = anonymous.request("GET", f"{base_url}/", headers=headers)
        match = re.search(rb'<html[^>]*\blang="([^"]*)"', body)
        return match.group(1).decode() if match else None

    # Welsh (cy) is not a UI locale, so the first choice cannot be served and the ranking falls to French.
    results.check("a supported language lower in the q-ranking is still honoured (cy, fr;q=0.8 -> fr)",
                  landing_lang("cy, fr;q=0.8"), "fr")
    results.check("a directly requested supported language is served (de -> de)",
                  landing_lang("de"), "de")
    results.check("an unsupported language falls back to English (zz -> en)",
                  landing_lang("zz"), "en")
    results.check("no Accept-Language at all falls back to English, not the server's locale",
                  landing_lang(None), "en")

    # Text direction comes from the fixed list of right-to-left languages (ar, he, fa, ur, ps), never from
    # the runtime's culture data, and is advertised on <html dir> beside the language. Pashto is the one
    # the runtimes most often disagree on, so it is checked by name alongside Arabic.
    def landing_dir(accept_language: str) -> str | None:
        _, body, _ = anonymous.request("GET", f"{base_url}/", headers={"Accept-Language": accept_language})
        return _html_attribute(body, "dir")

    results.check("Arabic renders right-to-left (ar -> dir=rtl)", landing_dir("ar"), "rtl")
    results.check("Pashto renders right-to-left (ps -> dir=rtl)", landing_dir("ps"), "rtl")
    results.check("...and is served as Pashto, not a fallback (ps -> ps)", landing_lang("ps"), "ps")
    results.check("a left-to-right language says so (fr -> dir=ltr)", landing_dir("fr"), "ltr")

    # Chinese is the one language shipped in two scripts, so a tag without a script subtag is resolved by
    # region (Taiwan, Hong Kong and Macao traditional, anything else simplified), a script subtag wins over
    # the region, and a bare "zh" is simplified. One rule in all three, pinned in decision record 34.
    results.check("Chinese for Taiwan is the traditional script (zh-TW -> zh-Hant)", landing_lang("zh-TW"), "zh-Hant")
    results.check("Chinese for Hong Kong is the traditional script (zh-HK -> zh-Hant)", landing_lang("zh-HK"), "zh-Hant")
    results.check("Chinese for the mainland is the simplified script (zh-CN -> zh-Hans)", landing_lang("zh-CN"), "zh-Hans")
    results.check("bare Chinese is the simplified script (zh -> zh-Hans)", landing_lang("zh"), "zh-Hans")
    results.check("a script subtag wins over the region (zh-Hant-SG -> zh-Hant)", landing_lang("zh-Hant-SG"), "zh-Hant")
    results.check("...and in the other direction (zh-Hans-TW -> zh-Hans)", landing_lang("zh-Hans-TW"), "zh-Hans")

    if manifest:
        results.check_that("enrollment_url points at a public page, not the authenticated one",
                           not str(manifest.get("enrollment_url", "")).rstrip("/").endswith("/me"),
                           f"enrollment_url is {manifest.get('enrollment_url')!r}")

    status, _, headers = anonymous.request("GET", f"{base_url}/theme-overrides.css")
    results.check("the override stylesheet is served without a session", status, 200)
    results.check_that("...as CSS", headers.get("Content-Type", "").startswith("text/css"),
                       f"got {headers.get('Content-Type')!r}")

    status, _, _ = anonymous.request("GET", f"{base_url}/favicon.svg")
    results.check("the favicon is served without a session", status, 200)

    # -- The language switcher and the signed-out page ----------------------------------------------
    #
    # A member who picks a language has said what they want, and that choice outranks the IAM locale claim
    # and the browser's Accept-Language (decision records 21 and 34). It travels in
    # ata_locale, the one cookie an anonymous page may set, and only a same-site POST of an offered value
    # may set it: a crafted link cannot change the language, and a forged value cannot render a language
    # the instance does not ship. The POST lands the member back where they were, and never off this site.
    #
    # Everything here is anonymous, so it runs before the database is needed and whether or not a login
    # can be completed. The same cookie on the signed-in page is checked later, with a session in hand.
    results.section("the language switcher and the signed-out page")

    form_encoded = {"Content-Type": "application/x-www-form-urlencoded"}
    site = urllib.parse.urlsplit(base_url).netloc

    def set_language(client: Client, fields: dict) -> tuple[int, dict, list[str]]:
        return client.request_without_following(
            "POST", f"{base_url}/set-language", urllib.parse.urlencode(fields).encode(), form_encoded)

    def lands_on(headers: dict, path: str) -> bool:
        """Whether the redirect's Location is ``path`` on this site, written relative or absolute."""
        location = _header_named(headers, "Location")
        if not location:
            return False
        target = urllib.parse.urlsplit(urllib.parse.urljoin(f"{base_url}/", location))
        return target.netloc == site and target.path == path

    def page_lang(client: Client, path: str, headers: dict) -> str | None:
        _, body, _ = client.request("GET", f"{base_url}{path}", headers=headers)
        return _html_attribute(body, "lang")

    # A fresh client, so the only cookie it can hold is the one this POST sets.
    switcher = Client(verify_tls)
    status, headers, set_cookies = set_language(switcher, {"locale": "fr", "next": "/"})
    results.check_that("choosing a language answers a redirect", status in _REDIRECTS, f"got {status}")
    results.check_that("...back to the page the member was on (next=/ -> /)", lands_on(headers, "/"),
                       f"Location was {_header_named(headers, 'Location')!r}")

    header = _set_cookie_named(set_cookies, "ata_locale")
    results.check_that("...and sets a cookie named ata_locale", header is not None, "no Set-Cookie for ata_locale")
    results.check_that("...and no other cookie rides along with it",
                       {line.split("=", 1)[0].strip() for line in set_cookies} <= {"ata_locale"},
                       f"Set-Cookie lines: {set_cookies}")

    value, attributes = _parse_set_cookie(header or "")
    results.check("the language cookie holds only the locale code, nothing about who chose it", value, "fr")
    results.check_that("the language cookie is HttpOnly, so a script cannot read it", "httponly" in attributes)
    results.check_that("the language cookie is Secure, so it never travels over plain HTTP", "secure" in attributes)
    results.check_that("the language cookie is SameSite=Lax, so another site's request carries it only on a "
                       "top-level navigation",
                       (attributes.get("samesite") or "").lower() == "lax",
                       f"SameSite was {attributes.get('samesite')!r}")
    results.check("the language cookie is scoped to the whole site (Path=/)", attributes.get("path"), "/")
    max_age = attributes.get("max-age") or ""
    results.check_that("the language cookie lasts about a year (Max-Age)",
                       max_age.isdigit() and 350 * 86400 <= int(max_age) <= 380 * 86400,
                       f"Max-Age was {max_age!r}")

    # The cookie the server just set now rides in this client's jar, as it would in a browser, and must
    # outrank what the browser says it would prefer.
    results.check("with the cookie, the landing page renders in the chosen language even when "
                  "Accept-Language asks for another (cookie fr, header de -> fr)",
                  page_lang(switcher, "/", {"Accept-Language": "de"}), "fr")

    # Back where the member was, and only there: the value is a path on this site or it is ignored. A
    # next is followed only when it starts with one slash, not two and not a slash and a backslash, and
    # carries no backslash and no control character. Anything else lands on the fallback, the landing
    # page, which is where all three send a member whose next cannot be trusted.
    fallback = "/"
    status, headers, _ = set_language(Client(verify_tls), {"locale": "fr", "next": "/me"})
    results.check_that("next names the page to return to (next=/me -> /me)",
                       status in _REDIRECTS and lands_on(headers, "/me"),
                       f"status {status}, Location {_header_named(headers, 'Location')!r}")
    for description, next_value in (("a protocol-relative next (//evil.example)", "//evil.example"),
                                    ("an absolute next (https://evil.example)", "https://evil.example"),
                                    ("an empty next", ""),
                                    ("a next with a backslash (/\\evil.example)", "/\\evil.example"),
                                    ("a next with a tab in it", "/me\tevil"),
                                    ("a next with a carriage return and line feed in it", "/me\r\nevil")):
        status, headers, _ = set_language(Client(verify_tls), {"locale": "fr", "next": next_value})
        results.check_that(f"{description} lands on {fallback}, never off site",
                           status in _REDIRECTS and lands_on(headers, fallback),
                           f"status {status}, Location {_header_named(headers, 'Location')!r}")

    # The switcher reads a form body and nothing else. A POST with no body, or with a JSON body, carries
    # no locale it can read, so it sets no cookie and still answers the redirect to the fallback. It never
    # answers a 500, because a member who posts an odd body is a member to send back to the page.
    for description, body, content_type in (("no body at all", b"", None),
                                            ("a JSON body", json.dumps({"locale": "fr", "next": "/"}).encode(),
                                             "application/json")):
        status, headers, set_cookies = Client(verify_tls).request_without_following(
            "POST", f"{base_url}/set-language", body, {"Content-Type": content_type} if content_type else {})
        results.check_that(f"POST /set-language with {description} answers a redirect, not a 500",
                           status in _REDIRECTS, f"got {status}")
        results.check_that(f"...to {fallback}", lands_on(headers, fallback),
                           f"Location was {_header_named(headers, 'Location')!r}")
        results.check_that("...and sets no cookie", not set_cookies, f"Set-Cookie lines: {set_cookies}")

    # A value that is not a shipped locale is dropped, not stored: the redirect still happens, so the
    # member is not stranded, but nothing is set. And a forged cookie carrying such a value, which only a
    # non-browser can send since no same-site POST would set it, is ignored on the way back in, so
    # resolution falls through to Accept-Language.
    stranger = Client(verify_tls)
    status, headers, set_cookies = set_language(stranger, {"locale": "zz", "next": "/"})
    results.check_that("a locale that is not offered still redirects", status in _REDIRECTS, f"got {status}")
    results.check_that("...but sets no cookie at all", not set_cookies, f"Set-Cookie lines: {set_cookies}")
    results.check("an unsupported cookie value sent by hand is ignored and resolution falls through to "
                  "Accept-Language (cookie zz, header fr -> fr)",
                  page_lang(stranger, "/", {"Cookie": "ata_locale=zz", "Accept-Language": "fr"}), "fr")

    # The switcher itself, on the pages: the form that posts to /set-language, naming the current language
    # by its own name (language_endonym for the locale the page actually rendered in).
    def carries_switcher(path: str, accept_language: str) -> None:
        status, body, _ = anonymous.request("GET", f"{base_url}{path}", headers={"Accept-Language": accept_language})
        results.check(f"{path} is served", status, 200)
        results.check_that(f"{path} carries the language switcher (a form posting to /set-language)",
                           _language_switcher(body, f"{base_url}{path}") is not None)
        results.check_that("...naming the current language by its own name",
                           *_page_says(body, _html_attribute(body, "lang") or "en", "language_endonym"))

    carries_switcher("/", "fr")

    # Signing out lands on the landing page with a confirmation (decision record 24), at one fixed address
    # every implementation shares, so the provider's post-logout redirect can be registered once. The
    # confirmation is a shared string in the resolved locale, and it appears only there: the plain landing
    # page says nothing about a sign-out that did not happen.
    for accept_language, locale in (("fr", "fr"), (None, "en")):
        request_headers = {"Accept-Language": accept_language} if accept_language else {}
        status, body, _ = anonymous.request("GET", f"{base_url}/signed-out", headers=request_headers)
        results.check(f"GET /signed-out renders a page ({locale})", status, 200)
        results.check_that(f"...which is the landing page: it links the member to sign in ({locale})",
                           b"/me" in body, "no path to /me anywhere on the page")
        results.check(f"...in the resolved language ({locale})", _html_attribute(body, "lang"), locale)
        results.check_that(f"...carrying the signed-out confirmation in that language ({locale})",
                           *_page_says(body, locale, "signed_out"))

    said, _ = _page_says(landing_body, "en", "signed_out")
    results.check_that("the plain landing page carries no signed-out confirmation", not said,
                       "GET / shows the signed-out confirmation without a sign-out")

    # -- Seeding ------------------------------------------------------------------------------------
    #
    # Everything below depends on the organisation having granted these relationships first, so a
    # failure here stops the run rather than turning every later section into a puzzling 404.
    results.section("what the organisation has granted")
    if not db.reset():
        results.check_that("the suite can reach the database", False,
                           "--sql-command did not run; relationships cannot be granted")
        results.abort("the database was unreachable, so nothing could be granted and no section ran")
        return results

    results.check_that("the suite can reach the database", True)

    granted = all(db.grant(who, rel, subtype) for who, rel, subtype in FIXTURES)
    results.check_that("grant_member_relationship created the fixtures", granted)
    if not granted:
        results.abort("the fixtures could not be granted, so every section that needs one was skipped")
        return results

    # -- Anonymous access ---------------------------------------------------------------------------
    results.section("Anonymous callers")
    status, _ = anonymous.json_request("GET", f"{api}/me")
    results.check("GET /me without a session is 401", status, 401)

    status, _ = anonymous.json_request("PUT", key_url("employee"), {"public_key": random_key()})
    results.check("PUT on a relationship key without a session is 401", status, 401)

    status, _ = anonymous.json_request("POST", revoke_url("employee"))
    results.check("POST revoke without a session is 401", status, 401)

    # The session is looked at before the anti-forgery token, so a caller with no session is told 401
    # whatever token it sends, never the 403 a signed-in member gets for a wrong one.
    status, _, _ = self_revoke(anonymous, "employee", "not-the-page-token")
    results.check("POST revoke without a session is 401 even with a token in X-CSRF-TOKEN", status, 401)

    # The stylesheet has to be readable before anyone signs in, because the pages a member sees on the
    # way in -- the login redirect, and any error along the way -- are served before there is a session.
    # Behind the authentication filter, the stylesheet would redirect to the identity provider and those
    # pages would render unstyled: a working system that looks broken exactly when someone is deciding
    # whether to trust it.
    status, body, headers = anonymous.request("GET", f"{base_url}/trusted-attestation.css")
    results.check("the stylesheet is served without a session", status, 200)
    results.check_that("it is served as CSS",
                       headers.get("Content-Type", "").startswith("text/css"),
                       f"got {headers.get('Content-Type')!r}")
    results.check_that("it is the shared build, not a per-implementation copy",
                       b"ata-brand" in body and b".btn" in body,
                       "the stylesheet does not look like the one in ui/dist")

    status, payload = attest(random_key())
    results.check("attest on an unregistered key is 200", status, 200)
    results.check("...and is exactly {valid: false}, with no reason given", payload, {"valid": False})

    status, payload = attest("not-valid-base64!!")
    results.check("attest on a malformed key is 200", status, 200)
    results.check("...and is answered the same way", payload, {"valid": False})

    status, _ = anonymous.json_request("POST", f"{base_url}/api/attest", {"public_key": random_key()})
    results.check("unversioned /api resolves to the latest version", status, 200)

    # A path under /api that no endpoint serves is 404 -- the route not existing, decided before auth --
    # not 401. A 401 there would mask "this isn't a thing" behind "you're not signed in", and would only
    # happen on a stack whose auth layer runs ahead of routing.
    status, _ = anonymous.json_request("GET", f"{api}/does-not-exist")
    results.check("an unknown /api/v1 path is 404, not 401", status, 404)
    status, _ = anonymous.json_request("GET", f"{base_url}/api/does-not-exist")
    results.check("an unknown unversioned /api path is 404 too", status, 404)

    # -- alice: more than one relationship at a time ------------------------------------------------
    #
    # The case the data model is built around. A member can hold several relationships at the same
    # organisation, each with its own independent key, and presenting one must reveal nothing about the
    # others -- not their existence, not their type. A single-relationship test cannot see any of that
    # go wrong.
    results.section("alice: two relationships, each with its own key")
    alice = Client(verify_tls)
    if not login(alice, base_url, "alice", "password"):
        results.check_that("alice can sign in", False, "login failed")
        results.abort("alice could not sign in, so every check that needs a session was skipped")
        return results

    results.check_that("alice can sign in", True)

    status, me = alice.json_request("GET", f"{api}/me")
    results.check("GET /me is 200", status, 200)
    results.check_that("name comes from the identity system's session, and is not stored by the server",
                       bool(me and me.get("name")))

    # The page a signed-in member actually sees must carry exactly one heading -- the organisation's
    # name as an <h1> -- so assistive technology and the document outline start where they should. That
    # heading is easy to lose: if it lives only in the branch that runs when no logo is set, an
    # organisation with a logo gets a page whose first heading is the <h2> below it. Asserting it here,
    # implementation-blind, keeps that from regressing silently in one stack while the others stay right.
    status, me_html, _ = alice.get(f"{base_url}/me")
    results.check("the signed-in page is served as HTML", status, 200)
    results.check("the signed-in page carries exactly one h1", me_html.count(b"<h1"), 1)
    results.check_that("the h1 comes before any deeper heading",
                       b"<h1" in me_html
                       and (b"<h2" not in me_html or me_html.index(b"<h1") < me_html.index(b"<h2")),
                       "a level-2 heading appears before the page's h1")
    # The page's script sends this token with a self-revoke. Without it every revoke below is refused 403,
    # so its absence is named here rather than left to read as several unrelated failures.
    results.check_that("the signed-in page carries the anti-forgery token its script sends "
                       '(<meta name="csrf-token">)', bool(csrf_token(me_html)), "no csrf-token meta on /me")

    # Also the check that finds a mismatch between the identifier the organisation granted against and
    # the one the session presents. If an implementation keys members by username or email rather than
    # the IAM subject, alice signs in successfully and holds nothing, and this is where that shows up.
    results.check("both granted relationships are listed", types_held(me), ["client", "employee"])

    # Named for the employment relationship rather than "employee", because code scanning reads any name
    # containing "employee" as a person's private data. These hold only a test key and its relationship.
    employment = relationship(me, "employee")
    results.check("a granted relationship with no key yet is 'unkeyed'",
                  employment and employment.get("status"), "unkeyed")
    results.check("...and carries no key", employment and employment.get("public_key"), None)

    employment_key = random_key()
    status, saved = alice.json_request("PUT", key_url("employee"), {"public_key": employment_key})
    results.check("PUT on the employee relationship is 200", status, 200)
    if saved:
        results.check("...and echoes the key back", saved.get("public_key"), employment_key)
        results.check("...names the relationship it applied to", saved.get("relationship_type"), "employee")
        results.check("...and reports it active", saved.get("status"), "active")

    # The independence check. Setting one relationship's key must leave the other exactly as it was.
    status, me = alice.json_request("GET", f"{api}/me")
    results.check("the keyed relationship is now active",
                  (relationship(me, "employee") or {}).get("status"), "active")
    results.check("the other relationship is untouched, still unkeyed",
                  (relationship(me, "client") or {}).get("status"), "unkeyed")
    results.check("...and still has no key of its own",
                  (relationship(me, "client") or {}).get("public_key"), None)

    client_key = random_key()
    status, saved = alice.json_request("PUT", key_url("client"), {"public_key": client_key})
    results.check("alice can key her second relationship too", status, 200)

    # What a verifying peer sees. Each key resolves to exactly the one relationship it belongs to, and
    # says nothing about the other -- which is the property that lets a member present one relationship
    # without disclosing the rest.
    status, payload = attest(employment_key)
    results.check("the employee key attests as employee, and nothing else", payload,
                  {"valid": True, "relationship_type": "employee", "status": "active"})

    status, payload = attest(client_key)
    results.check("the client key attests as client, and nothing else", payload,
                  {"valid": True, "relationship_type": "client", "status": "active"})

    # The key is read from the JSON request body only. A registered key placed in the query string or in
    # a form-encoded body does not attest -- it is ignored, not honoured. POST-not-GET keeps the key out
    # of access and proxy logs, and that only holds if the query string is genuinely not a way in; and a
    # key that attests through one framing on one stack but not another is a behavioural split.
    status, raw, _ = anonymous.request(
        "POST", f"{api}/attest?public_key=" + urllib.parse.quote(employment_key))
    query_body = json.loads(raw) if raw else None
    results.check("a registered key in the query string is 200 and does not attest", status, 200)
    results.check("...it is ignored, answered exactly {valid: false}", query_body, {"valid": False})

    status, raw, _ = anonymous.post_form(f"{api}/attest", {"public_key": employment_key})
    form_body = json.loads(raw) if raw else None
    results.check("a registered key in a form-encoded body is 200 and does not attest", status, 200)
    results.check("...it is ignored, answered exactly {valid: false}", form_body, {"valid": False})

    status, _ = alice.json_request("PUT", key_url("employee"), {"public_key": "AAAA"})
    results.check("a short key is rejected with 400", status, 400)

    status, _ = alice.json_request("PUT", key_url("employee"),
                                   {"public_key": base64.b64encode(bytes(32)).decode()})
    results.check("the all-zero key is rejected with 400", status, 400)

    # -- Clearing a key: an empty PUT unsets it, reversibly -----------------------------------------
    #
    # The member pausing attestation on a relationship they hold without asking the organisation to do
    # anything. An empty public_key on the same PUT clears the key, the relationship keeps its grant and
    # returns to unkeyed, and the old key stops attesting. It is the member's own act, so saving a key
    # again restores it, unlike an organisation revocation. Only a string that is empty or holds nothing
    # but spaces, tabs, carriage returns and line feeds clears. A malformed body must not, or a garbled
    # request would silently wipe a key.
    results.section("clearing a key unsets it, reversibly")

    cleared_before = db.audit_rows("alice", "key_cleared", "employee")
    status, cleared = alice.json_request("PUT", key_url("employee"), {"public_key": ""})
    results.check("an empty public_key clears the key and is 200", status, 200)
    results.check("...the response carries a null key", cleared and cleared.get("public_key"), None)
    results.check("...and reports the relationship unkeyed again",
                  cleared and cleared.get("status"), "unkeyed")
    results.check("...and wrote exactly one key_cleared audit entry",
                  db.audit_rows("alice", "key_cleared", "employee"), cleared_before + 1)

    status, me = alice.json_request("GET", f"{api}/me")
    employment_cleared = relationship(me, "employee")
    results.check("/me shows the cleared relationship carrying no key",
                  employment_cleared and employment_cleared.get("public_key"), None)
    results.check("...and unkeyed", employment_cleared and employment_cleared.get("status"), "unkeyed")

    status, payload = attest(employment_key)
    results.check("the cleared key no longer attests", payload, {"valid": False})

    # Re-saving the already-empty field is a quiet no-op: still 200, but no second audit entry.
    status, _ = alice.json_request("PUT", key_url("employee"), {"public_key": "   "})
    results.check("clearing an already-empty key is still 200", status, 200)
    results.check("...and writes no second key_cleared entry",
                  db.audit_rows("alice", "key_cleared", "employee"), cleared_before + 1)

    # Reversible: saving a key again restores the relationship to active.
    status, _ = alice.json_request("PUT", key_url("employee"), {"public_key": employment_key})
    results.check("saving a key again restores the relationship", status, 200)
    status, payload = attest(employment_key)
    results.check("...and it attests as active once more", payload,
                  {"valid": True, "relationship_type": "employee", "status": "active"})

    # A malformed body is not a clear: an absent public_key is a 400 and must leave the set key alone.
    status, raw, _ = alice.request("PUT", key_url("employee"), b"{}", {"Content-Type": "application/json"})
    results.check("an absent public_key is 400, not a silent clear", status, 400)
    status, payload = attest(employment_key)
    results.check("...and the key it would have cleared is untouched", payload,
                  {"valid": True, "relationship_type": "employee", "status": "active"})

    # Any other whitespace character makes the key malformed rather than blank. A non-breaking space is
    # the one a copy from a web page or a word processor most often carries.
    status, _ = alice.json_request("PUT", key_url("employee"), {"public_key": " "})
    results.check("a public_key of one non-breaking space is 400, not a silent clear", status, 400)
    status, payload = attest(employment_key)
    results.check("...and the key it would have cleared is still on record", payload,
                  {"valid": True, "relationship_type": "employee", "status": "active"})

    # The authenticated page must never be indexed: its URL existing in a search index says this
    # person has a relationship here, which is exactly what /attest is designed not to leak.
    status, me_body, _ = alice.request("GET", f"{base_url}/me")
    results.check_that("the authenticated page is marked noindex",
                       b"noindex" in me_body, "no noindex meta on /me")

    # -- PUT is not an upsert -----------------------------------------------------------------------
    #
    # The most load-bearing rule in the API. If PUT could create the relationship it names, any member
    # could self-grant any type they liked and the organisation would have vouched for nothing. The 404
    # is what keeps grant_member_relationship organisation-only in practice rather than only on paper.
    results.section("PUT is not an upsert")

    ungranted_key = random_key()
    status, _ = alice.json_request("PUT", key_url("director"), {"public_key": ungranted_key})
    results.check("PUT on a relationship the organisation never granted is 404", status, 404)

    status, payload = attest(ungranted_key)
    results.check("...and nothing was created: the key does not attest", payload, {"valid": False})

    status, _ = alice.json_request("DELETE", key_url("director"))
    results.check("DELETE on a relationship she does not hold is 404", status, 404)

    status, _, _ = self_revoke(alice, "director")
    results.check("self-revoke on a relationship she does not hold is 404", status, 404)

    status, me = alice.json_request("GET", f"{api}/me")
    results.check("none of that added anything to her record", types_held(me), ["client", "employee"])

    # A type outside the governed vocabulary altogether. The contract names no status for this case, so
    # this asserts only what every reading agrees on: it is refused, and refusing is not a crash.
    status, _ = alice.json_request("PUT", key_url("wizard"), {"public_key": random_key()})
    results.check_that("a relationship_type outside the vocabulary is refused, not accepted",
                       status in (400, 404), f"got {status}")

    # The type in the path must equal the row's type exactly. alice holds employee, and EMPLOYEE is not
    # it, whatever the database's collation thinks: on an engine that compares case-insensitively the row
    # would be found, and the audit trail would then carry the path's spelling of a type the vocabulary
    # does not have. So the spelling is refused, the lower-case relationship is untouched, and the trail
    # holds nothing under the upper-case name.
    uppercase_key = random_key()
    status, _ = alice.json_request("PUT", key_url("EMPLOYEE"), {"public_key": uppercase_key})
    results.check("PUT with EMPLOYEE in the path is 404: the type must match exactly", status, 404)
    status, _ = alice.json_request("DELETE", key_url("EMPLOYEE"))
    results.check("DELETE with EMPLOYEE in the path is 404", status, 404)
    status, _, _ = self_revoke(alice, "EMPLOYEE")
    results.check("self-revoke with EMPLOYEE in the path is 404", status, 404)
    status, payload = attest(uppercase_key)
    results.check("...the key it carried was not registered", payload, {"valid": False})
    status, payload = attest(employment_key)
    results.check("...her employee relationship is untouched, still active under its own key", payload,
                  {"valid": True, "relationship_type": "employee", "status": "active"})
    results.check("...and the audit trail holds no key_registered entry under EMPLOYEE",
                  db.audit_rows("alice", "key_registered", "EMPLOYEE"), 0)
    results.check("...nor a key_removed entry under it", db.audit_rows("alice", "key_removed", "EMPLOYEE"), 0)


    # -- bob: one key, one relationship -------------------------------------------------------------
    #
    # public_key is unique across the whole table, not per member and not per organisation. That is a
    # security property rather than data hygiene: keys are not secret -- a peer receives one during a
    # connection -- so without it, anyone who saw a key could register it against a relationship of
    # their own and claim the standing that goes with it.
    results.section("bob: one key, one relationship")
    bob = Client(verify_tls)
    if not login(bob, base_url, "bob", "password"):
        results.check_that("bob can sign in", False, "login failed")
        results.abort("bob could not sign in, so the sections that need his session were skipped")
        return results

    results.check_that("bob can sign in", True)

    status, _ = bob.json_request("PUT", key_url("client"), {"public_key": employment_key})
    results.check("registering another member's key is 409", status, 409)

    # The same rule inside one member's own record. alice's client relationship holds this key, and
    # bob's client relationship is a different relationship even though the type matches.
    status, _ = bob.json_request("PUT", key_url("client"), {"public_key": client_key})
    results.check("...including a key held by another member's relationship of the same type",
                  status, 409)

    bob_key = random_key()
    status, _ = bob.json_request("PUT", key_url("client"), {"public_key": bob_key})
    results.check("bob can register a key of his own", status, 200)

    # An idempotent retry -- a lost response, a double-clicked button -- must not read as a collision
    # with itself. The uniqueness check has to exclude the row being updated, or a retry that changes
    # nothing is answered with a conflict that never happened.
    status, _ = bob.json_request("PUT", key_url("client"), {"public_key": bob_key})
    results.check("re-submitting the same key on the same relationship is 200, not 409", status, 200)

    status, payload = attest(bob_key)
    results.check("his key attests as his own relationship", payload,
                  {"valid": True, "relationship_type": "client", "status": "active"})

    # -- carol: the ungoverned subtype --------------------------------------------------------------
    results.section("carol: relationship_subtype")
    carol = Client(verify_tls)
    if not login(carol, base_url, "carol", "password"):
        results.check_that("carol can sign in", False, "login failed")
        results.abort("carol could not sign in, so the sections that need her session were skipped")
        return results

    results.check_that("carol can sign in", True)

    status, me = carol.json_request("GET", f"{api}/me")
    held = relationship(me, "licensed_professional")
    results.check("the relationship the organisation granted her is listed",
                  types_held(me), ["licensed_professional"])
    results.check("relationship_subtype passes through as free text",
                  held and held.get("relationship_subtype"), "Fellow")

    carol_key = random_key()
    status, saved = carol.json_request("PUT", key_url("licensed_professional"),
                                       {"public_key": carol_key})
    results.check("carol can register her key", status, 200)
    results.check("the subtype comes back with the key too",
                  saved and saved.get("relationship_subtype"), "Fellow")

    status, payload = attest(carol_key)
    results.check("attest returns the subtype alongside the type", payload,
                  {"valid": True, "relationship_type": "licensed_professional",
                   "relationship_subtype": "Fellow", "status": "active"})

    # -- dave: signed in, holding nothing -----------------------------------------------------------
    #
    # Authenticated and granted nothing is an ordinary state, not an error: it is what every member
    # looks like before the organisation grants them anything. The page has to render for him.
    results.section("dave: signed in, holding nothing")
    dave = Client(verify_tls)
    if login(dave, base_url, "dave", "password"):
        results.check_that("dave can sign in", True)

        status, me = dave.json_request("GET", f"{api}/me")
        results.check("GET /me is 200, not an error", status, 200)
        results.check_that("relationships is present and empty, not absent",
                           isinstance(me, dict) and me.get("relationships") == [],
                           f"got {me!r}")

        status, _ = dave.json_request("PUT", key_url("employee"), {"public_key": random_key()})
        results.check("PUT is 404: he holds no such relationship to key", status, 404)

        status, _, _ = self_revoke(dave, "employee")
        results.check("self-revoke is 404 for the same reason", status, 404)
    else:
        results.check_that("dave can sign in", False, "login failed")

    # -- Removal ------------------------------------------------------------------------------------
    #
    # DELETE is a hard delete of that one relationship row -- not a revoke, and not a return to unkeyed.
    # A member removing themselves is closer to erasure than to a status change, so the row goes; the
    # organisation can grant it again if the member should be re-invited.
    results.section("alice: removing one relationship leaves the other")

    status, _ = alice.json_request("DELETE", key_url("employee"))
    results.check("DELETE is 204", status, 204)

    status, payload = attest(employment_key)
    results.check("the removed relationship's key stops attesting entirely", payload, {"valid": False})

    status, me = alice.json_request("GET", f"{api}/me")
    results.check("the row is gone, not returned to unkeyed", types_held(me), ["client"])

    status, payload = attest(client_key)
    results.check("her other relationship is untouched", payload,
                  {"valid": True, "relationship_type": "client", "status": "active"})

    status, _ = alice.json_request("DELETE", key_url("employee"))
    results.check("deleting it again is 404", status, 404)

    # Deletability does not depend on a key existing. This one was granted and never keyed.
    db.regrant("alice", "employee")
    status, _ = alice.json_request("DELETE", key_url("employee"))
    results.check("an unkeyed relationship can be deleted too", status, 204)

    # -- Self-revoke ---------------------------------------------------------------------------------
    #
    # One way only. A member can revoke a relationship from any status except revoked without asking the
    # organisation, but only the organisation can restore it. Distinct from DELETE -- the row survives, so the grant is
    # still there to be extended back.
    #
    # On what /attest answers for a revoked key: valid means the key is on record, and status says what
    # its standing is. A key that was never registered gets exactly {"valid": false} and nothing more,
    # so an anonymous caller fishing for keys learns nothing; but a caller holding a real key already
    # received it from the peer, and telling them it has been revoked is the entire point of asking
    # (decision record 4). Astrana's own three outcomes --
    # valid, invalid, unreachable (the README, How it works) -- are derived from both fields together: any
    # status other than active is invalid to the peer, even though the lookup succeeded.
    results.section("carol: self-revoke is one-directional")

    # Self-revoke is a plain POST, the one request a form on another site, or on a sibling subdomain that
    # counts as the same site for the session cookie, could send without a preflight. So with a live
    # session it needs the anti-forgery token the self-service page carries, and a missing or wrong token
    # is refused with 403 and nothing else, leaving the relationship as it was.
    status, raw, headers = self_revoke(carol, "licensed_professional", "")
    results.check("signed in, self-revoke without the anti-forgery token is 403", status, 403)
    results.check_that("...and the refusal carries no body", not raw, f"got {len(raw)} bytes")
    results.check_that("...and no Content-Type", _header_named(headers, "Content-Type") is None,
                       f"got {_header_named(headers, 'Content-Type')!r}")
    status, raw, headers = self_revoke(carol, "licensed_professional", "not-the-page-token")
    results.check("signed in, self-revoke with a wrong anti-forgery token is 403", status, 403)
    results.check_that("...and the refusal carries no body", not raw, f"got {len(raw)} bytes")
    results.check_that("...and no Content-Type", _header_named(headers, "Content-Type") is None,
                       f"got {_header_named(headers, 'Content-Type')!r}")
    status, payload = attest(carol_key)
    results.check("...and neither refusal revoked anything: her key still attests as active", payload,
                  {"valid": True, "relationship_type": "licensed_professional",
                   "relationship_subtype": "Fellow", "status": "active"})

    status, _, _ = self_revoke(carol, "licensed_professional")
    results.check("self-revoke with the page's anti-forgery token is 204", status, 204)

    status, me = carol.json_request("GET", f"{api}/me")
    results.check("the relationship is still held, now revoked",
                  (relationship(me, "licensed_professional") or {}).get("status"), "revoked")

    status, payload = attest(carol_key)
    results.check("a peer holding the key is told it is on record and revoked", payload,
                  {"valid": True, "relationship_type": "licensed_professional",
                   "relationship_subtype": "Fellow", "status": "revoked"})

    status, _, _ = self_revoke(carol, "licensed_professional")
    results.check("revoking an already-revoked relationship is 204, not an error", status, 204)

    # Rotating a key must not quietly restore standing. If it did, revocation would be advisory.
    rotated = random_key()
    status, saved = carol.json_request("PUT", key_url("licensed_professional"),
                                       {"public_key": rotated})
    results.check("she can still set a key on it", status, 200)
    results.check("...and is told plainly that it is still revoked",
                  saved and saved.get("status"), "revoked")

    status, payload = attest(rotated)
    results.check("the rotated key does not attest as active either",
                  payload and payload.get("status"), "revoked")

    status, _ = carol.json_request("DELETE", key_url("licensed_professional"))
    results.check("a revoked relationship can still be deleted by its member", status, 204)

    # -- The trail the application writes itself ----------------------------------------------------
    #
    # key_registered, key_cleared and key_removed are the events no stored procedure produces: the
    # application writes them, in the same transaction as the change they record. Nothing else here looks
    # at the audit log, so without these checks a change that skipped the insert would pass every other
    # one: the API answers correctly, the key is stored, and the record of it happening is gone.
    #
    # An audit trail is only worth having if something
    # notices when it stops being written, and an append-only log cannot be reconstructed afterwards.
    results.section("the application records what it did")

    db.regrant("alice", "employee")

    before = db.audit_rows("alice", "key_registered", "employee")
    audited_key = random_key()
    status, _ = alice.json_request("PUT", key_url("employee"), {"public_key": audited_key})
    results.check("a key is registered for this section", status, 200)
    results.check("...and registering it wrote exactly one audit entry",
                  db.audit_rows("alice", "key_registered", "employee"), before + 1)

    # Named, not just counted. A member can hold several relationships, so an entry that does not say
    # which one is a record that something happened to somebody.
    results.check("the entry names the relationship it was about",
                  db.audit_rows("alice", "key_registered", "employee") > 0, True)

    removed_before = db.audit_rows("alice", "key_removed", "employee")
    status, _ = alice.json_request("DELETE", key_url("employee"))
    results.check("the relationship is removed", status, 204)
    results.check("...and removing it wrote its own entry",
                  db.audit_rows("alice", "key_removed", "employee"), removed_before + 1)

    # The row is gone from member_relationships; the trail is not. It records that events happened,
    # which is not the same personal data as the key itself.
    results.check("the trail survives the relationship it describes",
                  db.audit_rows("alice", "key_registered", "employee"), before + 1)

    # -- Organisation-initiated revocation and expiry -----------------------------------------------
    #
    # What "valid" means, and the reason the whole system is a live lookup rather than a signature
    # checked once: standing is point-in-time and re-evaluated on every check. None of this is reachable
    # through the API by design -- there is no endpoint through which a member can restore or extend
    # themselves -- so it goes through the same procedures an organisation would call.
    results.section("the organisation revokes, extends, and lets a relationship lapse")

    results.check_that("the organisation can revoke bob's relationship", db.org_revoke("bob", "client"))

    status, payload = attest(bob_key)
    results.check("a revoked relationship reports revoked, not active",
                  payload and payload.get("status"), "revoked")

    # The member registering a key again must not lift the organisation's revocation.
    bob_rotated = random_key()
    status, saved = bob.json_request("PUT", key_url("client"), {"public_key": bob_rotated})
    results.check("bob can register a new key while revoked", status, 200)
    results.check("...and it does not clear the revocation", saved and saved.get("status"), "revoked")

    results.check_that("the organisation can extend it again",
                       db.org_extend("bob", "client", "2999-01-01 00:00:00"))

    status, payload = attest(bob_rotated)
    results.check("an extended relationship attests as active again",
                  payload, {"valid": True, "relationship_type": "client", "status": "active"})

    results.check_that("the organisation can set an expiry in the past",
                       db.org_extend("bob", "client", "2020-01-01 00:00:00"))

    status, payload = attest(bob_rotated)
    results.check("a relationship whose standing has lapsed reports expired",
                  payload and payload.get("status"), "expired")

    results.check_that("expiry can be cleared", db.org_extend("bob", "client", None))
    status, payload = attest(bob_rotated)
    results.check("...and it is active once more", payload and payload.get("status"), "active")

    # -- Hostile input ------------------------------------------------------------------------------
    #
    # public_key arrives as JSON and can be any JSON type, not just the string the contract describes.
    # The contract defines no 500 for either endpoint, so a wrong type has to be answered, not crashed
    # on -- and /attest is the anonymous one, reachable by anybody.
    results.section("malformed input is answered, not crashed on")

    for description, raw in (
        ("a number", '{"public_key": 12345}'),
        ("an object", '{"public_key": {"a": 1}}'),
        ("an array", '{"public_key": [1, 2, 3]}'),
        ("a boolean", '{"public_key": true}'),
        ("no public_key at all", '{}'),
        ("malformed JSON", "{not json"),
    ):
        # /attest always answers 200, whatever the body -- that is the contract: valid/invalid is the
        # only distinction it draws, and a malformed key is simply not valid. A framework that lets a
        # wrong-typed public_key fail model binding and return 400 has broken that from underneath the
        # handler, and it does so inconsistently -- a number rejected where a boolean is coerced -- so
        # "not a 500" is too weak a check to notice three implementations disagreeing.
        status, payload, _ = anonymous.request(
            "POST", f"{api}/attest", raw.encode(), {"Content-Type": "application/json"})
        results.check(f"POST /attest with {description} is 200", status, 200)

        try:
            body = json.loads(payload.decode("utf-8")) if payload else None
        except (UnicodeDecodeError, json.JSONDecodeError):
            body = None
        results.check(f"...and is exactly valid:false", body, {"valid": False})

        # PUT is allowed to refuse a malformed key -- the contract gives it 400 for an invalid key
        # format. What it must not do is leak: a 400 carrying a framework exception in its body fails
        # the status-code-only rule and hands an anonymous shape of the internals. So: not a 500, and
        # nothing that looks like a stack trace or an exception type.
        status, put_body, _ = alice.request(
            "PUT", key_url("client"), raw.encode(), {"Content-Type": "application/json"})
        results.check_that(f"PUT on a relationship key with {description} is not a 500",
                           status != 500, f"got {status}")
        leak = next((marker for marker in (b"Exception", b"    at ", b"Traceback", b"/src/", b"/app/vendor")
                     if put_body and marker in put_body), None)
        results.check_that(f"...and its response leaks no internals",
                           leak is None, f"body contained {leak!r}")

    # -- The body is JSON, exactly, whatever the Content-Type says ----------------------------------
    #
    # A body is malformed when it is not one JSON value in UTF-8 and nothing else: a byte order mark in
    # front of it, a second token after it, or bytes that are not UTF-8 inside it. The key endpoint
    # answers 400 and changes nothing, and /attest answers {valid: false}, in all three. Each malformed
    # body here carries a key that would otherwise be accepted or recognised, so a parser that quietly
    # tolerated the fault would show up as a 200 or a valid: true rather than pass by accident.
    fresh_key = random_key()
    well_formed = json.dumps({"public_key": fresh_key}).encode()
    registered = json.dumps({"public_key": client_key}).encode()
    for description, malformed, recognised in (
            ("a byte order mark in front of the JSON", b"\xef\xbb\xbf" + well_formed, b"\xef\xbb\xbf" + registered),
            ("a second token after the JSON", well_formed + b" {}", registered + b" {}"),
            ("bytes that are not UTF-8 inside the key", b'{"public_key": "\xff\xfe' + fresh_key.encode() + b'"}',
             b'{"public_key": "\xff\xfe' + client_key.encode() + b'"}')):
        status, put_body, _ = alice.request("PUT", key_url("client"), malformed, {"Content-Type": "application/json"})
        results.check(f"PUT on a relationship key with {description} is 400", status, 400)
        results.check_that("...and the refusal carries no body", not put_body, f"got {len(put_body)} bytes")
        status, payload = attest(client_key)
        results.check("...and the key on record is untouched", payload,
                      {"valid": True, "relationship_type": "client", "status": "active"})

        status, raw, _ = anonymous.request("POST", f"{api}/attest", recognised, {"Content-Type": "application/json"})
        try:
            body = json.loads(raw.decode("utf-8")) if raw else None
        except (UnicodeDecodeError, json.JSONDecodeError):
            body = None
        results.check(f"POST /attest with {description} is 200 and exactly valid:false, the registered key "
                      "inside it notwithstanding", (status, body), (200, {"valid": False}))

    # The body is read as JSON whatever the Content-Type header says, so a form-encoded body is a
    # malformed body, and a JSON body under a form Content-Type is read all the same.
    status, put_body, _ = alice.request("PUT", key_url("client"), urllib.parse.urlencode({"public_key": fresh_key}).encode(),
                                        {"Content-Type": "application/x-www-form-urlencoded"})
    results.check("PUT on a relationship key with a form-encoded body is 400: the body is JSON or nothing", status, 400)
    status, payload = attest(client_key)
    results.check("...and the key on record is untouched", payload,
                  {"valid": True, "relationship_type": "client", "status": "active"})

    status, raw, _ = alice.request("PUT", key_url("client"), well_formed,
                                   {"Content-Type": "application/x-www-form-urlencoded"})
    results.check("PUT with a JSON body under a form Content-Type is read as JSON and is 200", status, 200)
    status, payload = attest(fresh_key)
    results.check("...and the key it carried is now on record", payload,
                  {"valid": True, "relationship_type": "client", "status": "active"})
    status, raw, _ = anonymous.request("POST", f"{api}/attest", json.dumps({"public_key": fresh_key}).encode(),
                                       {"Content-Type": "text/plain"})
    results.check("POST /attest with a JSON body under text/plain is read as JSON and recognises the key",
                  (status, json.loads(raw.decode()) if raw else None),
                  (200, {"valid": True, "relationship_type": "client", "status": "active"}))

    # Put the key the rest of the run expects back.
    status, _ = alice.json_request("PUT", key_url("client"), {"public_key": client_key})
    results.check("the key is restored for the sections that follow", status, 200)

    # A body over 64 kilobytes is refused before it is read, with 413 and nothing else, on both
    # endpoints. A key is 44 characters, so no well-formed request comes anywhere near the cap.
    oversized = b'{"public_key": "' + b"A" * (80 * 1024) + b'"}'

    def send_oversized(client: Client, method: str, url: str) -> tuple[int | str, bytes]:
        # A server that drops the connection instead of answering is a failed check here, not a dead
        # instance, so it must not stop the run the way an unreachable server does.
        try:
            status, body, _ = client.request(method, url, oversized, {"Content-Type": "application/json"})
            return status, body
        except Unreachable as dropped:
            return f"connection dropped ({dropped})", b""

    status, put_body = send_oversized(alice, "PUT", key_url("client"))
    results.check("PUT on a relationship key with a body over 64 kilobytes is 413", status, 413)
    results.check_that("...and the refusal carries no body", not put_body, f"got {len(put_body)} bytes")
    status, raw = send_oversized(anonymous, "POST", f"{api}/attest")
    results.check("POST /attest with a body over 64 kilobytes is 413", status, 413)
    results.check_that("...and the refusal carries no body", not raw, f"got {len(raw)} bytes")

    # -- One key, one canonical encoding ------------------------------------------------------------
    #
    # A public key is an exact 32-byte value, and standard base64 gives it exactly one padded spelling.
    # The decoders disagree on the sloppy ones -- one accepts an unpadded key, another silently skips
    # whitespace inside it, a third takes url-safe -- so a key stored by one implementation could be
    # rejected as unregisterable by the next. Every non-canonical spelling of an otherwise-valid key is
    # refused, the same way, by all three.
    canonical = "+/A/ECAwQFBgcICQoLDA0ODwAQIDBAUGBwgJCgsMDf8="
    for description, spelling in (
            ("unpadded", canonical.rstrip("=")),
            ("url-safe", canonical.replace("+", "-").replace("/", "_")),
            ("with internal whitespace", canonical[:8] + " " + canonical[8:])):
        status, _, _ = alice.request(
            "PUT", key_url("client"),
            json.dumps({"public_key": spelling}).encode(), {"Content-Type": "application/json"})
        results.check(f"a {description} key is refused with 400", status, 400)

        # /attest still answers 200 -- a non-canonical key is simply not a key on record, not an error.
        status, payload, _ = anonymous.request(
            "POST", f"{api}/attest",
            json.dumps({"public_key": spelling}).encode(), {"Content-Type": "application/json"})
        results.check(f"...and /attest with the {description} key is 200 valid:false",
                      (status, json.loads(payload.decode())), (200, {"valid": False}))

    # -- Every relationship carries the same shape --------------------------------------------------
    #
    # The /me relationship object has a fixed shape: the nullable fields are present as null rather than
    # dropped, so a member's page and any client reading it see the same keys whether or not a
    # relationship is keyed or expiring. Omitting them would be a different JSON object for the same state.
    _, raw, _ = alice.request("GET", f"{api}/me")
    me_body = json.loads(raw.decode())
    relationship_row = relationship(me_body, "client")
    results.check_that("the client relationship is present on /me", relationship_row is not None)
    if relationship_row is not None:
        # Exactly the contract's fields, no more and no fewer. This is the null-inclusion guard: the
        # nullable fields (relationship_subtype, public_key, expires_at) are present as null rather than
        # dropped, so an implementation that omits its null fields -- a different JSON object for the
        # same state -- fails here on the missing key. Alice's client carries at least one such null.
        results.check("the relationship object carries exactly the contract's fields, nulls not dropped",
                      sorted(relationship_row.keys()),
                      sorted(["relationship_type", "relationship_subtype", "status",
                              "public_key", "expires_at"]))

    # -- Concurrency --------------------------------------------------------------------------------
    #
    # Two members registering the same key at the same moment. One must win and the other must be told
    # 409, the same answer the sequential case gives. A pre-flight check alone cannot deliver that: the
    # unique index is what actually decides it, and the loser's request has to survive being refused by
    # the database rather than turning into a 500.
    results.section("two members registering the same key at once")

    db.regrant("bob", "client")
    db.regrant("carol", "licensed_professional", "Fellow")

    contested = random_key()
    outcomes: dict[str, int] = {}

    def register(name: str, client: Client, relationship_type: str) -> None:
        outcomes[name] = client.json_request(
            "PUT", key_url(relationship_type), {"public_key": contested})[0]

    racers = [threading.Thread(target=register, args=args) for args in
              (("bob", bob, "client"), ("carol", carol, "licensed_professional"))]
    for thread in racers:
        thread.start()
    for thread in racers:
        thread.join()

    codes = sorted(outcomes.values())
    results.check_that("one registration wins and the other is refused with 409",
                       codes == [200, 409], f"got {outcomes}")

    if codes == [200, 409]:
        winner = next(name for name, code in outcomes.items() if code == 200)
        status, payload = attest(contested)
        results.check_that("the contested key attests as exactly one relationship",
                           bool(payload and payload.get("valid") is True),
                           f"{winner} won but the key does not attest: {payload!r}")

    # -- The self-service page in the member's chosen language --------------------------------------
    #
    # The switcher's cookie outranks everything on the signed-in page too: the IAM locale claim, when the
    # provider issues one, and Accept-Language. The cookie is set the way a browser sets it, by posting the
    # switcher's form from a signed-in client, so what is checked is the cookie this instance actually
    # issued, carried back on the member's own session. The dev Keycloak realm issues no locale claim (its
    # users carry no locale attribute), so against that provider the cookie is contested only by
    # Accept-Language. Against a provider that does issue one, the same check covers the claim as well,
    # which is why the chosen locale is neither English nor anything the fixtures would carry.
    results.section("the self-service page in the member's chosen language")

    def choose_language(locale: str) -> int:
        status, _, _ = alice.request_without_following(
            "POST", f"{base_url}/set-language",
            urllib.parse.urlencode({"locale": locale, "next": "/me"}).encode(),
            {"Content-Type": "application/x-www-form-urlencoded"})
        return status

    status = choose_language("fr")
    results.check_that("a signed-in member can choose a language", status in _REDIRECTS, f"got {status}")

    status, me_page, _ = alice.request("GET", f"{base_url}/me", headers={"Accept-Language": "de"})
    results.check("the self-service page still renders on the same session", status, 200)
    results.check("...in the chosen language, over Accept-Language and any locale claim "
                  "(cookie fr, header de -> fr)", _html_attribute(me_page, "lang"), "fr")
    results.check_that("the self-service page carries the language switcher (a form posting to /set-language)",
                       _language_switcher(me_page, f"{base_url}/me") is not None)
    results.check_that("...naming the current language by its own name",
                       *_page_says(me_page, _html_attribute(me_page, "lang") or "en", "language_endonym"))

    # A key is base64, which reads left to right in any language, so on a right-to-left page the key field
    # says so itself. Otherwise the browser's bidirectional layout can move the key's +, / and = signs to
    # the wrong end, and the key on screen no longer matches the one on the member's device. Arabic is
    # chosen through the switcher, which outranks any locale claim, and alice still holds her client
    # relationship, so the page has a key field to show.
    status = choose_language("ar")
    results.check_that("a signed-in member can choose Arabic", status in _REDIRECTS, f"got {status}")
    status, rtl_page, _ = alice.request("GET", f"{base_url}/me")
    results.check("the self-service page in Arabic renders right-to-left", _html_attribute(rtl_page, "dir"), "rtl")
    key_fields = _key_inputs(rtl_page)
    results.check_that("...and shows an attestation key field (an input marked data-key-input)", bool(key_fields),
                       "no key field on the page")
    results.check_that('...and every key field reads left to right (dir="ltr")',
                       bool(key_fields) and all(field.get("dir") == "ltr" for field in key_fields),
                       f"dir on the key fields: {[field.get('dir') for field in key_fields]}")

    # The choice was this section's, not alice's: drop it so later sections see her pages as before.
    _forget_cookie(alice, "ata_locale")

    # -- The login, the session cookie, and signing out ---------------------------------------------
    #
    # None of this is visible to the API sections above, which start from a session already in hand -- yet
    # it is where a login's security actually lives: the flow that protects the authorization code, the
    # attributes that keep the session cookie from leaking or lingering, and a sign-out that genuinely
    # ends the session rather than only appearing to.
    results.section("the login, the session cookie, and signing out")

    # PKCE protects the OAuth authorization code against interception, and the OAuth 2.1 profile in
    # decision record 22 requires it. An OIDC concern only -- SAML has no equivalent -- so it is checked only on an OIDC
    # deployment, and every reason this can be skipped is said out loud rather than passed over.
    if alice.saml_acs_url:
        print("    PKCE: skipped, this is a SAML deployment")
    else:
        app_netloc = urllib.parse.urlparse(base_url).netloc
        chain = Client(verify_tls).redirect_chain(f"{base_url}/me")
        authz = next((url for url in chain if urllib.parse.urlparse(url).netloc != app_netloc), None)

        if authz is None:
            results.check_that("the login redirects to the IdP so the request can be inspected",
                               False, f"the flow never left the application: {chain}")
        elif "request_uri=" in authz and "code_challenge=" not in authz:
            # Pushed Authorization Requests: the parameters, PKCE among them, are sent to the IdP over a
            # back channel and referenced by a one-time URI, so they are not on the wire here. That is a
            # stronger delivery than putting them in the redirect, but it means this check cannot see the
            # code_challenge, so it says so rather than failing.
            print("    PKCE: skipped, the authorization request is pushed (PAR) and not observable here")
        else:
            results.check_that(
                "the authorization request carries a PKCE code_challenge",
                "code_challenge=" in authz,
                "the authorization request reached the IdP with neither a code_challenge nor a pushed "
                "request: the authorization code is unprotected against interception, which the OAuth "
                "2.1 profile does not allow")
            results.check_that("...using S256, not the plain method",
                               "code_challenge_method=S256" in authz)

    # The session cookie's own attributes, read from the Set-Cookie the login actually sent. A fresh
    # client, so nothing else has touched its jar.
    member = Client(verify_tls)
    if not login(member, base_url, "alice", "password"):
        results.check_that("a member can sign in for the session checks", False, "login failed")
    else:
        browser_cookies = getattr(member, "browser_cookies", None)
        if browser_cookies is not None:
            # Browser login: the Set-Cookie headers never reached this client, so read the same attributes
            # from the browser's own cookie store instead of parsing a header that is not here.
            session = next((c for c in browser_cookies if c["name"] == "ata_session"), None)
            results.check_that("signing in sets a session cookie named ata_session", session is not None)
            session = session or {}
            results.check_that("the session cookie is HttpOnly, so a script cannot read it",
                               bool(session.get("httpOnly")))
            results.check_that("the session cookie is Secure, so it never travels over plain HTTP",
                               bool(session.get("secure")))
            results.check_that("the session cookie is SameSite=Lax, so a cross-site page cannot send it on a "
                               "state-changing request", str(session.get("sameSite", "")).lower() == "lax")
            # Playwright reports -1 for a session cookie; a real expiry is a positive epoch second.
            results.check_that("the session cookie is not persistent (no Max-Age or Expires)",
                               float(session.get("expires", -1)) < 0)
        else:
            header = _set_cookie_named(member.recorder.set_cookies, "ata_session")
            results.check_that("signing in sets a session cookie named ata_session", header is not None)

            attributes = (header or "").lower()
            results.check_that("the session cookie is HttpOnly, so a script cannot read it",
                               "httponly" in attributes)
            results.check_that("the session cookie is Secure, so it never travels over plain HTTP",
                               "secure" in attributes)
            results.check_that("the session cookie is SameSite=Lax, so a cross-site page cannot send it on a "
                               "state-changing request", "samesite=lax" in attributes)
            # No Max-Age or Expires: a session cookie, dropped when the browser closes, so a member is not
            # left signed in on a shared machine after they have gone. All three agreed on this.
            results.check_that("the session cookie is not persistent (no Max-Age or Expires)",
                               "max-age=" not in attributes and "expires=" not in attributes)

        # Signing out ends the session. The suite walks whatever sign-out form the page renders -- its
        # method, its action and its CSRF field are the implementation's own to name -- rather than
        # knowing any of them, then asks the API whether the session is really gone.
        _, body, _ = member.request("GET", f"{base_url}/me")
        signout = [form for form in _forms(body) if "signout" in form["action"].lower()]
        results.check_that("the self-service page offers a sign-out control", len(signout) == 1)
        if signout:
            action = urllib.parse.urljoin(f"{base_url}/me", signout[0]["action"])

            # With a live session the sign-out needs the form's anti-forgery token, so another site cannot
            # sign a member out. The form is posted with no fields at all, then with every field it carries
            # set to a value that is not the token, whatever the implementation names its token field.
            # Each is refused with 403, and the session survives both.
            forged = {name: "not-the-page-token" for name in signout[0]["fields"]}
            for description, fields in (("without the anti-forgery token", {}),
                                        ("with a wrong anti-forgery token", forged)):
                status, _, _ = member.request_without_following(
                    "POST", action, urllib.parse.urlencode(fields).encode(),
                    {"Content-Type": "application/x-www-form-urlencoded"})
                results.check(f"signed in, signing out {description} is 403", status, 403)
            still, _ = member.json_request("GET", f"{api}/me")
            results.check("...and neither refusal ended the session", still, 200)

            member.post_form(action, signout[0]["fields"])
            after, _ = member.json_request("GET", f"{api}/me")
            results.check("after signing out, the API no longer sees a session", after, 401)

    # Signing out with no session has nothing to end, so it answers the redirect to /signed-out whatever
    # the anti-forgery token says (decision record 24). A fresh client, so there is no session and no token at all.
    status, signout_headers, _ = Client(verify_tls).request_without_following(
        "POST", f"{base_url}/signout", b"", {"Content-Type": "application/x-www-form-urlencoded"})
    results.check_that("signing out with no session answers a redirect", status in _REDIRECTS, f"got {status}")
    location = _header_named(signout_headers, "Location") or ""
    results.check_that("...to /signed-out",
                       urllib.parse.urlsplit(urllib.parse.urljoin(f"{base_url}/", location)).path == "/signed-out",
                       f"got Location {location!r}")

    # -- Response hardening --------------------------------------------------------------------------
    #
    # Checked here rather than in each implementation's own tests because it is exactly the sort of thing
    # that drifts between them: left to the frameworks, all three send different headers, and only
    # comparing them side by side shows it. A header that silently stops being sent breaks nothing and
    # fails no test that is not looking for it.
    results.section("response hardening")

    _, _, headers = anonymous.request("GET", f"{base_url}/.well-known/ata-manifest.json")

    # Case-insensitive, because the header name on the wire is the framework's own spelling: Symfony
    # title-cases, so the same header arrives as X-Xss-Protection from one implementation and
    # X-XSS-Protection from another. HTTP header names are case-insensitive; asserting on an exact
    # spelling would fail an implementation that is doing nothing wrong.
    def header_of(hdrs, name):
        return next((value for key, value in hdrs.items() if key.lower() == name.lower()), None)

    def sets_cookie(hdrs):
        return any(key.lower() == "set-cookie" for key in hdrs)

    security_headers = (("X-Content-Type-Options", "nosniff"),
                        ("X-Frame-Options", "DENY"),
                        ("Referrer-Policy", "no-referrer"),
                        # Off explicitly, on all three. The legacy XSS auditor this once enabled is a
                        # source of bugs in the browsers that still carry it, and 0 turns it off rather
                        # than leaving it to the browser's default. Three implementations of one
                        # contract (decision record 35) should not disagree on how a browser is told to treat a page.
                        ("X-XSS-Protection", "0"))

    for header, expected in security_headers:
        actual = header_of(headers, header)
        results.check_that(f"{header} is {expected}", actual == expected, f"got {actual!r}")

    # The headers ride every response, the framework's own error paths included. A path nothing routes is
    # answered by the part of each stack most likely to skip the middleware that sets them.
    status, _, missing_headers = anonymous.request("GET", f"{base_url}/no-such-page")
    results.check("a path nothing serves is 404", status, 404)
    for header, expected in security_headers:
        actual = header_of(missing_headers, header)
        results.check_that(f"...and the 404 still sends {header} {expected}", actual == expected,
                           f"got {actual!r}")

    # Nothing this server returns should be cached -- the self-service page is per-member and the API answers
    # are point-in-time. The exact directive string is each framework's own (Symfony sorts them and adds
    # private; the others do not), so the invariant is no-store's presence, not the whole header.
    cache_control = header_of(headers, "Cache-Control") or ""
    results.check_that("Cache-Control forbids caching (contains no-store)",
                       "no-store" in cache_control.lower(), f"got {cache_control!r}")

    # The anonymous, stateless surfaces plant no session or CSRF cookie. A verifying Astrana instance
    # calling /attest, anything reading the machine-facing manifest, or a reader of the licence page is
    # not a browser building a session.
    for description, method, url, body, content_type in (
            ("the manifest", "GET", f"{base_url}/.well-known/ata-manifest.json", None, None),
            ("the landing page", "GET", f"{base_url}/", None, None),
            ("the licence page", "GET", f"{base_url}/license", None, None),
            ("attest", "POST", f"{api}/attest", b'{"public_key": "AAAA"}', "application/json")):
        request_headers = {"Content-Type": content_type} if content_type else None
        _, _, response_headers = anonymous.request(method, url, body, request_headers)
        results.check_that(f"{description} sets no cookie for an anonymous caller",
                           not sets_cookie(response_headers), "a Set-Cookie was returned")

    # An unauthenticated request that is refused has nothing to persist, so it plants no session either.
    _, _, unauth_headers = anonymous.request("GET", f"{api}/me")
    results.check_that("an unauthenticated /me plants no cookie",
                       not sets_cookie(unauth_headers), "a Set-Cookie was returned")

    # Errors carry a status code and nothing else: response bodies are reserved for data. A wrong method
    # on a real endpoint is the framework's own error path, the one most likely to answer with a page.
    status, body, _ = anonymous.request("DELETE", f"{api}/attest")
    results.check("a wrong method is refused with 405", status, 405)
    results.check_that("...and the refusal carries no body", not body, f"got {len(body)} bytes")

    # HEAD is GET without the body, and a monitor or a cache asks it of the manifest.
    status, _, _ = anonymous.request("HEAD", f"{base_url}/.well-known/ata-manifest.json")
    results.check("HEAD on the manifest is 200", status, 200)

    # Paths a framework may answer for its own reasons, an error page or a sign-in route that this
    # deployment does not use, are nothing to a member and answer 404 like any other path nothing serves.
    for path in ("/error", "/oauth2/anything", "/login/anything"):
        status, body, _ = anonymous.request("GET", f"{base_url}{path}")
        results.check(f"GET {path} is 404: a framework route nothing here uses is not served", status, 404)

    # -- The assertion consumer service -------------------------------------------------------------
    #
    # Only meaningful on a SAML deployment, and announced when it skips: which protocol an instance
    # speaks is a deployment choice the rest of this suite deliberately cannot see.
    #
    # The ACS accepts unauthenticated input by nature -- the IdP posts to it cross-site, so anyone can.
    # Refusing bad input is the whole job, and refusing it is not the same as falling over: a 500 means
    # an unhandled exception, which is the wrong answer, buries real faults in error monitoring, and
    # hands any passer-by a way to generate them.
    if alice.saml_acs_url:
        results.section("the assertion consumer service", f" ({alice.saml_acs_url})")

        for description, raw in (
            ("a SAMLResponse that is not XML", "SAMLResponse=bm90LXhtbA%3D%3D&RelayState=x"),
            ("a SAMLResponse that is not base64", "SAMLResponse=%21%21%21%21&RelayState=x"),
            ("an empty POST", ""),
            ("a form with no SAMLResponse at all", "RelayState=x"),
        ):
            status, _, _ = anonymous.request(
                "POST", alice.saml_acs_url, raw.encode(),
                {"Content-Type": "application/x-www-form-urlencoded"})

            results.check_that(f"the ACS refuses {description} without a 500",
                               status != 500, f"got {status}")
    else:
        # Said out loud rather than passed over. A section that skips itself in silence reads exactly
        # like a section that ran and was happy, and the difference matters when the reason it skipped
        # is a bug in the detection rather than an OIDC deployment.
        print()
        print("the assertion consumer service: skipped, no SAML AuthnRequest seen during login")

    db.reset()
    return results


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL,
                        help=f"Astrana Trusted Attestation instance to check (default: {DEFAULT_BASE_URL})")
    parser.add_argument("--verify-tls", action="store_true",
                        help="Verify the instance's TLS certificate. Off by default because the dev "
                             "instances use self-signed certificates.")
    # Required: relationships exist only once grant_member_relationship has created them, and that
    # procedure is deliberately not exposed through the API, so without a way into the database there is
    # nothing for a member to key and almost nothing to check.
    parser.add_argument("--sql-command", required=True,
                        help="Shell command that reads SQL on standard input and runs it against the "
                             "instance's database, e.g. 'docker exec -i my-postgres psql -U user -d db'. "
                             "Required: the suite grants the relationships it then exercises, because "
                             "granting is organisation-only and has no API.")
    parser.add_argument("--database", default="postgres", choices=sorted(NAMES),
                        help="Which engine --sql-command talks to (default: postgres).")
    parser.add_argument("--browser-login", action="store_true",
                        help="Sign in through a real browser (Playwright driving the system Edge) rather "
                             "than by walking HTML forms. Needed only for an IdP whose login page is a "
                             "JavaScript application -- Authentik, Zitadel -- with no form to walk until "
                             "its script runs. Requires `pip install playwright`; everything after login "
                             "is identical either way.")
    parser.add_argument("--json", metavar="PATH",
                        help="Also write the run's results to PATH as JSON: the metadata below plus every "
                             "check as {section, name, status}. shared/test/integration-report.py turns one or more "
                             "of these into a shareable pass/fail matrix across IdPs and implementations.")
    parser.add_argument("--idp", default="",
                        help="A label for the IdP this run used (e.g. Keycloak, Authentik, Zitadel), for "
                             "the JSON report's metadata and the matrix columns. Free text; check.py cannot "
                             "tell which IdP answered, by design.")
    parser.add_argument("--implementation", default="",
                        help="A label for the implementation under test (e.g. dotnet, java, php), for the "
                             "JSON report's metadata and the matrix's column grouping.")
    args = parser.parse_args()

    global BROWSER_LOGIN
    BROWSER_LOGIN = args.browser_login

    print(f"Checking {args.base_url} against shared/contract/openapi.yaml")
    print("Requires the dev Keycloak realm and a database from shared/test/docker-compose.yml.")

    try:
        results = run(args.base_url, args.verify_tls, args.sql_command, args.database)
    except Unreachable as unreachable:
        print()
        print(f"Nothing answered at {unreachable}.")
        print("The instance is not running, or is not serving that URL.")
        return 2

    missing = results.missing_sections()

    print()
    required_run = sum(1 for title in REQUIRED_SECTIONS if title in results.sections)
    optional_run = sum(1 for title in OPTIONAL_SECTIONS if title in results.sections)

    print(f"{results.passed} passed, {len(results.failures)} failed, "
          f"{required_run}/{len(REQUIRED_SECTIONS)} sections run"
          + (f" (+{optional_run} optional)" if optional_run else ""))

    if args.json:
        import datetime
        report = {
            "idp": args.idp,
            "implementation": args.implementation,
            "base_url": args.base_url,
            "database": args.database,
            "browser_login": args.browser_login,
            "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
            "passed": results.passed,
            "failed": len(results.failures),
            "sections_run": f"{required_run}/{len(REQUIRED_SECTIONS)}",
            "aborted": results.aborted,
            "missing_sections": missing,
            "checks": results.records,
        }
        pathlib.Path(args.json).write_text(json.dumps(report, indent=2))
        print(f"wrote {args.json}")

    if results.failures:
        print()
        print("Failures:")
        for failure in results.failures:
            print(f"  - {failure}")

    # Reported separately from the failures, and last, because it changes what the numbers above
    # mean. A count of passes only reassures if everything that should have run did run.
    if results.aborted or missing:
        print()
        print("THE RUN DID NOT COMPLETE. The totals above cover only part of the suite.")
        if results.aborted:
            print(f"  stopped: {results.aborted}")
        for title in missing:
            print(f"  never ran: {title}")

        # A distinct code from an ordinary failure: a caller that only knows pass/fail would
        # otherwise treat 'the suite could not run' the same as 'the suite ran and found a bug'.
        return 2

    if results.failures:
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
