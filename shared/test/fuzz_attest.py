#!/usr/bin/env python3
"""Property-based fuzzing of the anonymous attestation endpoint, POST /api/v1/attest, on every implementation.

The differential suite's second part. differential.py sends a fixed list of malformed bodies to the three
implementations. This generates hundreds of requests with Hypothesis, or many thousands in a long run, sends each
one to every implementation, and checks the properties that decision records 4, 28 and 49 in docs/adr and the
contract require of any request body:

- the answer is never HTTP 500 - Internal Server Error
- a body over the 64 kilobyte limit is answered HTTP 413 - Content Too Large, with no body and no Content-Type
- a body within the limit that is not an on-record key is answered HTTP 200 - OK, as application/json, with
  exactly {"valid":false}
- an on-record key under public_key in a well-formed JSON object is answered with its relationship, whatever
  the Content-Type
- no answer carries any part of the request back in a header, and the bodies are pinned exactly, so they
  cannot carry it either
- every implementation gives the same answer, byte for byte

How long an answer takes is not checked here.

The requests are the ones worth sending to a parser. They include random bytes, malformed and deeply nested
JSON, keys of the wrong type, base64 edge cases (padding, the URL-safe alphabet, whitespace of every kind, 31
and 33 bytes, all zeros, Unicode lookalikes), duplicate names, huge numbers, invalid UTF-8, byte order marks,
sizes either side of the limit, chunked bodies of several chunk sizes with no declared length, and unusual
Content-Type headers.

A body is read as JSON text by RFC 8259 whatever its Content-Type says. It is malformed, and so holds no key,
when it is not valid UTF-8, starts with a byte order mark or holds anything after its one value. Decision
record 47 adds three limits. A body is also malformed when, anywhere in it, arrays and objects nest more than 64
deep, counting the outermost, a number has more than 1,000 digits, or a string or a name holds a lone surrogate
escape. Many of the requests hold an on-record key in a malformed body, and each of those must be answered
{"valid":false}. Where public_key appears twice, its last value is the one read.

The on-record keys are fixtures this run grants and registers in each implementation's database, for a
subject identifier of its own, and removes when it finishes. Each implementation is named with one --app,
which gives its address, its database engine and a command that reads SQL on standard input, as
differential_auth.py takes them. Given only --base-url, the run has no on-record keys and checks every other
property.

    python shared/test/fuzz_attest.py \\
        --app dotnet https://localhost:15443 postgres "psql -q -v ON_ERROR_STOP=1 -h postgres ... -d ..._dotnet" \\
        --app java   https://localhost:16443 postgres "psql -q -v ON_ERROR_STOP=1 -h postgres ... -d ..._java" \\
        --app php    https://localhost:17443 postgres "psql -q -v ON_ERROR_STOP=1 -h postgres ... -d ..._php"
    python shared/test/fuzz_attest.py --base-url https://localhost:15443 --base-url https://localhost:16443

A run is repeatable. The same --seed and --examples send the same requests, and the defaults are the short
run continuous integration makes. --long makes a much longer run from a new seed, which it prints so that a
failure can be replayed with --seed. --engine atheris hands generation to Atheris, the Python front end to
libFuzzer, which runs until it finds a violation or is stopped and steers towards requests that reach new
branches of this file. Arguments this file does not know are passed to libFuzzer, for example
-max_total_time=3600.

A violation is reported with the smallest request Hypothesis could shrink it to, or in a long run the smallest
request that met it, written as a Python expression that rebuilds the body exactly.
"""

from __future__ import annotations

import argparse
import base64
import dataclasses
import hashlib
import http.client
import json
import pathlib
import random
import ssl
import subprocess
import sys
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from engines import NAMES, Engine, run_sql  # noqa: E402

try:
    from hypothesis import HealthCheck, given, seed, settings
    from hypothesis import strategies as st
except ImportError:
    sys.exit("fuzz_attest.py needs Hypothesis, which shared/test/ci/suite-requirements.txt pins")

CTX = ssl._create_unverified_context()

PATH = "/api/v1/attest"
LIMIT = 64 * 1024
INVALID = b'{"valid":false}'
JSON_TYPE = "application/json"

DEFAULT_BASE_URLS = [
    "https://localhost:15443",  # .NET
    "https://localhost:16443",  # Java
    "https://localhost:17443",  # PHP
]

# The run continuous integration makes. Each example goes to every implementation, so this is the number of
# requests each one answers, which takes about two minutes for the three together. --long is for a machine left
# to it.
CI_EXAMPLES = 300
CI_SEED = 20261006
LONG_EXAMPLES = 100_000

# The fixtures' own subject identifier and audit actor, which no other suite uses, so a run never touches
# another suite's relationships and its audit entries say where they came from.
SUBJECT = "f0220000-a77e-4000-8000-000000000000"
ACTOR = "attestation-fuzzing"
PAST = "2000-01-01T00:00:00"


@dataclasses.dataclass(frozen=True)
class Fixture:
    """One on-record relationship, with a key no other suite or seed plants."""

    relationship_type: str
    subtype: str | None
    status: str

    @property
    def key(self) -> bytes:
        return hashlib.sha256(f"fuzz_attest {self.relationship_type}".encode()).digest()

    @property
    def spelling(self) -> str:
        return base64.b64encode(self.key).decode()

    @property
    def answer(self) -> dict:
        answer = {"valid": True, "relationship_type": self.relationship_type}
        if self.subtype is not None:
            answer["relationship_subtype"] = self.subtype
        answer["status"] = self.status
        return answer


# One of each status. Every key's standard spelling holds a "+" or a "/", so its URL-safe spelling is a
# different string. The subtype with an ampersand and a slash shows whether the three escape them alike.
FIXTURES = (
    Fixture("employee", "Night shift", "active"),
    Fixture("client", None, "revoked"),
    Fixture("licensed_professional", "R&D / Lab", "expired"),
)


# -- the fixtures in each database ---------------------------------------------------------------------------


def quoted(value: str | None) -> str:
    return "NULL" if value is None else "'" + value.replace("'", "''") + "'"


def removal_sql() -> str:
    return f"DELETE FROM member_relationships WHERE iam_subject_id = {quoted(SUBJECT)};"


def planting_sql(engine: Engine) -> str:
    """Grants each fixture, registers its key and gives it its status, the way the organisation and the
    member would, starting from none of them."""
    statements = [removal_sql()]
    for fixture in FIXTURES:
        relationship = quoted(fixture.relationship_type)
        statements.append(engine.call("grant_member_relationship", quoted(SUBJECT), relationship,
                                      quoted(fixture.subtype), quoted(ACTOR)))
        statements.append(f"UPDATE member_relationships SET public_key = {engine.binary(fixture.key.hex())} "
                          f"WHERE iam_subject_id = {quoted(SUBJECT)} AND relationship_type = {relationship};")
        if fixture.status == "revoked":
            statements.append(engine.call("revoke_member_relationship", quoted(SUBJECT), relationship,
                                          quoted(ACTOR)))
        if fixture.status == "expired":
            statements.append(engine.call("extend_member_relationship", quoted(SUBJECT), relationship,
                                          engine.timestamp(PAST), quoted(ACTOR)))
    return engine.prelude + "\n".join(statements) + "\n"


# -- requests -----------------------------------------------------------------------------------------------


@dataclasses.dataclass(frozen=True)
class Request:
    body: bytes
    content_type: str | None = JSON_TYPE
    chunked: int = 0  # 0 declares the body's length, any other number sends it in chunks of that many bytes

    def __repr__(self) -> str:
        return f"Request(body={shown(self.body)}, content_type={self.content_type!r}, chunked={self.chunked})"


def shown(body: bytes) -> str:
    """A Python expression that rebuilds the body exactly, with long runs written as repetitions, so that a
    64 kilobyte body still fits in a report."""
    parts, literal, i = [], bytearray(), 0
    while i < len(body):
        unit, count = longest_run(body, i)
        if len(unit) * count >= 24:
            if literal:
                parts.append(repr(bytes(literal)))
                literal.clear()
            parts.append(f"{unit!r} * {count}")
            i += len(unit) * count
        else:
            literal.append(body[i])
            i += 1
    if literal:
        parts.append(repr(bytes(literal)))
    return " + ".join(parts) or "b''"


def longest_run(body: bytes, start: int) -> tuple[bytes, int]:
    """The unit of up to sixteen bytes that repeats furthest from start, and how many times it does."""
    best, best_count = b"", 0
    for size in range(1, 17):
        unit = body[start:start + size]
        if len(unit) < size:
            break
        count = 1
        while body.startswith(unit, start + count * size):
            count += 1
        if size * count > len(best) * best_count:
            best, best_count = unit, count
    return best, best_count


# -- generating requests ------------------------------------------------------------------------------------

ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"
JSON_WHITESPACE = " \t\n\r"
# Whitespace a parser or a base64 decoder might skip although JSON and the key format do not allow it.
OTHER_WHITESPACE = ("\f", "\v", "\x85", "\xa0", "\u1680", "\u180e", "\u2000", "\u2003", "\u200a", "\u200b",
                    "\u2028", "\u2029", "\u202f", "\u205f", "\u3000", "\ufeff")
WHITESPACE = tuple(JSON_WHITESPACE) + OTHER_WHITESPACE
# Letters from other scripts that look like base64 characters.
LOOKALIKES = {
    "A": "\u0391\u0410", "B": "\u0392\u0412", "C": "\u0421\u216d", "E": "\u0395\u0415", "H": "\u0397\u041d",
    "I": "\u0399\u0406", "K": "\u039a\u041a", "M": "\u039c\u041c", "N": "\u039d", "O": "\u039f\u041e",
    "P": "\u03a1\u0420", "S": "\u0405", "T": "\u03a4\u0422", "X": "\u03a7\u0425", "Y": "\u03a5\u04ae",
    "Z": "\u0396", "a": "\u0430", "c": "\u0441", "e": "\u0435", "i": "\u0456", "j": "\u0458",
    "o": "\u03bf\u043e", "p": "\u0440", "s": "\u0455", "x": "\u0445", "y": "\u0443", "0": "\u041e\u039f",
    "1": "\u0406\u04c0", "+": "\u02d6\u2795", "/": "\u2044\u2215\u29f8", "=": "\ua78a\u30a0",
}


def lookalikes(char: str) -> list[str]:
    """Characters that look like char, from other scripts, in fullwidth form and in mathematical bold."""
    found = list(LOOKALIKES.get(char, ""))
    if "!" <= char <= "~":
        found.append(chr(ord(char) + 0xFEE0))
    for first, bold in (("A", 0x1D400), ("a", 0x1D41A), ("0", 0x1D7CE)):
        offset = ord(char) - ord(first)
        if 0 <= offset < (10 if first == "0" else 26):
            found.append(chr(bold + offset))
    return found


def b64(raw: bytes) -> str:
    return base64.b64encode(raw).decode()


def non_canonical_endings(spelling: str) -> list[str]:
    """The same 32 bytes with the unused low bits of the last character set, which lenient decoders accept
    and a canonical round trip refuses."""
    value = ALPHABET.index(spelling[42])
    return [spelling[:42] + ALPHABET[value | bits] + "=" for bits in (1, 2, 3)]


def key_spellings(raw: bytes) -> st.SearchStrategy[str]:
    """The canonical spelling of a 32-byte key, half the time, and otherwise one of the near misses around it."""
    spelling = b64(raw)
    urlsafe = spelling.replace("+", "-").replace("/", "_")
    position = st.integers(0, len(spelling) - 1)
    near_misses = st.one_of(
        st.sampled_from([spelling.rstrip("="), spelling + "=", spelling + "==", spelling[:-1] + "%3D",
                         urlsafe, urlsafe.rstrip("="), spelling.lower(), spelling.upper(),
                         b64(raw[:31]), b64(raw + b"\0"), b64(raw + raw), b64(raw)[:-4]]),
        st.sampled_from(non_canonical_endings(spelling)),
        st.tuples(st.integers(0, len(spelling)), st.sampled_from(WHITESPACE))
        .map(lambda p: spelling[:p[0]] + p[1] + spelling[p[0]:]),
        st.tuples(st.sampled_from(WHITESPACE), st.sampled_from(WHITESPACE)).map(lambda w: w[0] + spelling + w[1]),
        st.sampled_from(WHITESPACE).map(lambda space: space.join(spelling[i:i + 4] for i in range(0, 44, 4))),
        position.flatmap(lambda p: st.sampled_from(lookalikes(spelling[p]))
                         .map(lambda c: spelling[:p] + c + spelling[p + 1:])),
        position.map(lambda p: spelling[:p] + spelling[p + 1:]),
        position.map(lambda p: spelling[:p] + spelling[p] + spelling[p:]),
        position.map(lambda p: spelling[:p] + spelling[p].swapcase() + spelling[p + 1:]),
    )
    return st.booleans().flatmap(lambda canonical: st.just(spelling) if canonical else near_misses)


def mostly(usual: st.SearchStrategy, unusual: st.SearchStrategy) -> st.SearchStrategy:
    """usual three times in four, otherwise unusual."""
    return st.integers(0, 3).flatmap(lambda n: usual if n else unusual)


def json_values(max_leaves: int = 8) -> st.SearchStrategy:
    """Any JSON value Python can write, nested a little."""
    scalars = st.one_of(st.none(), st.booleans(), st.integers(), st.floats(allow_nan=False, allow_infinity=False),
                        st.text(max_size=20))
    return st.recursive(scalars, lambda inner: st.one_of(st.lists(inner, max_size=4),
                                                         st.dictionaries(st.text(max_size=8), inner, max_size=4)),
                        max_leaves=max_leaves)


def key_values() -> st.SearchStrategy:
    """What a request puts under public_key, which is most often an on-record key or a near miss of one, and
    otherwise another key, text, or a value that is not a string at all."""
    on_record = st.sampled_from(FIXTURES).flatmap(lambda fixture: key_spellings(fixture.key))
    return mostly(on_record, st.one_of(
        st.binary(min_size=32, max_size=32).flatmap(key_spellings),
        key_spellings(bytes(32)),
        st.binary(max_size=48).map(b64),
        st.text(max_size=64),
        st.sampled_from(["", " ", " \t\r\n", "\xa0", "\u3000", "null", "true", "=", "===="]),
        json_values(),
    ))


@st.composite
def string_literals(draw, text: str) -> str:
    """text as a JSON string literal, with some of its characters written as escapes, which must read the same."""
    style = draw(st.sampled_from(["plain", "ascii", "escaped"]))
    if style != "escaped":
        return json.dumps(text, ensure_ascii=style == "ascii")
    chosen = set(draw(st.lists(st.integers(0, max(len(text) - 1, 0)), max_size=4))) if text else set()
    upper = draw(st.booleans())
    pieces = []
    for i, char in enumerate(text):
        if i not in chosen:
            pieces.append(json.dumps(char, ensure_ascii=False)[1:-1])
        elif char == "/":
            pieces.append("\\/")
        else:
            units = char.encode("utf-16-be")
            for unit in range(0, len(units), 2):
                escape = f"\\u{units[unit] << 8 | units[unit + 1]:04x}"
                pieces.append(escape.upper().replace("\\U", "\\u") if upper else escape)
    return '"' + "".join(pieces) + '"'


def value_literals(value) -> st.SearchStrategy[str]:
    if isinstance(value, str):
        return string_literals(value)
    return st.just(json.dumps(value))


# Ways to write the member's name. Some are the right name, plain or escaped, which must read the same, and the
# rest are names that only look like it.
NAME_LITERALS = ['"public_key"', '"public_key"', '"public\\u005fkey"', '"\\u0070ublic_key"', '"Public_Key"',
                 '"PUBLIC_KEY"', '"public_key "', '" public_key"', '"publicKey"', '"public-key"',
                 '"public_key\\u0000"', '"public_\\u212aey"']


# What a document is sometimes wrapped in, to move public_key off the top level or to put a second value after
# the first.
WRAPPERS = ("[{}]", '{"outer":{}}', "{} {}", "{}\n", "{},")


@st.composite
def documents(draw) -> bytes:
    """A JSON document, most often an object with one public_key among other members, in any order, written
    with any JSON whitespace. Sometimes the name is spelt another way, sometimes it is missing, and sometimes
    the document is not an object at all."""
    if not draw(st.integers(0, 19)):
        return draw(encodings(json.dumps(draw(json_values()))))
    space = st.text(alphabet=JSON_WHITESPACE, max_size=3)
    members = []
    if draw(st.integers(0, 9)):
        name = draw(mostly(st.just('"public_key"'), st.sampled_from(NAME_LITERALS)))
        members.append(name + draw(space) + ":" + draw(space) + draw(key_values().flatmap(value_literals)))
    extras = draw(st.dictionaries(st.text(max_size=10), json_values(), max_size=3))
    for name, value in extras.items():
        members.insert(draw(st.integers(0, len(members))), json.dumps(name) + ":" + json.dumps(value))
    text = "{" + draw(space) + ("," + draw(space)).join(members) + draw(space) + "}"
    return draw(encodings(draw(mostly(st.just("{}"), st.sampled_from(WRAPPERS))).replace("{}", text)))


@st.composite
def encodings(draw, text: str) -> bytes:
    """The document's bytes, almost always UTF-8, sometimes in another encoding JSON does not allow."""
    encoding = draw(mostly(st.just("utf-8"), st.sampled_from(["utf-8", "utf-16", "utf-16-le", "utf-32", "latin-1"])))
    return text.encode(encoding, errors="replace" if encoding == "latin-1" else "surrogatepass")


# Bytes that break UTF-8 in different ways, which are a byte that never appears, an overlong slash, an encoded
# surrogate, a code point past U+10FFFF, a lone continuation byte and a truncated sequence.
INVALID_UTF8 = (b"\xff", b"\xfe", b"\xc0\xaf", b"\xed\xa0\x80", b"\xf4\x90\x80\x80", b"\x80", b"\xe2\x82")
# Pieces that make a JSON string malformed, and escapes that are unusual but valid, such as a whole surrogate pair.
STRING_BREAKERS = INVALID_UTF8 + (b"\x00", b"\x1f", b"\x7f", b"\\ud800", b"\\udc00", b"\\uDBFF\\uDFFF",
                                  b"\\u0000", b"\\x41", b"\\", b"\\/", b"\\'", b'\\"', b"\\u00", b"\\uZZZZ")
BOMS = (b"\xef\xbb\xbf", b"\xfe\xff", b"\xff\xfe", b"\xef\xbb\xbf\xef\xbb\xbf")


@st.composite
def mutated(draw, bodies: st.SearchStrategy[bytes]) -> bytes:
    """A body with one or two byte-level faults, each of them a byte order mark, something after the value, a
    cut, a broken UTF-8 sequence, a changed byte, or extra nesting around it."""
    body = draw(bodies)
    for _ in range(draw(st.integers(1, 2))):
        fault = draw(st.sampled_from(["bom", "trailing", "cut", "utf-8", "byte", "nest"]))
        at = draw(st.integers(0, len(body)))
        if fault == "bom":
            body = draw(st.sampled_from(BOMS)) + body
        elif fault == "trailing":
            body += draw(st.sampled_from([b" {}", b"x", b"\x00", b"\n", b" ", b",", b"]", b"}", b"//", b"\r\n\r\n"]))
        elif fault == "cut":
            body = body[:at]
        elif fault == "utf-8":
            body = body[:at] + draw(st.sampled_from(INVALID_UTF8)) + body[at:]
        elif fault == "byte" and body:
            at = min(at, len(body) - 1)
            body = body[:at] + bytes([draw(st.integers(0, 255))]) + body[at + 1:]
        else:
            depth = draw(st.integers(1, 100))
            body = b"[" * depth + body + b"]" * depth
    return body


@st.composite
def broken_strings(draw) -> bytes:
    """An on-record key with a malformed or unusual piece inside its string, or beside it in another one."""
    spelling = draw(st.sampled_from(FIXTURES)).spelling.encode()
    piece = draw(st.sampled_from(STRING_BREAKERS))
    if draw(st.booleans()):
        at = draw(st.integers(0, len(spelling)))
        return b'{"public_key":"' + spelling[:at] + piece + spelling[at:] + b'"}'
    sibling = b'"other":"' + piece + b'"'
    if draw(st.booleans()):
        return b'{"public_key":"' + spelling + b'",' + sibling + b"}"
    return b"{" + sibling + b',"public_key":"' + spelling + b'"}'


def on_record_member(draw) -> bytes:
    return b'"public_key":' + json.dumps(draw(st.sampled_from(FIXTURES)).spelling).encode()


@st.composite
def deeply_nested(draw) -> bytes:
    """Nesting from a few levels to tens of thousands, alone, beside an on-record key, around it, or unclosed."""
    depth = draw(st.one_of(st.integers(1, 80), st.integers(60, 1100), st.integers(1000, 40000)))
    opener, closer = draw(st.sampled_from([(b"[", b"]"), (b'{"a":', b"}"), (b'[{"a":', b"}]")]))
    inner = draw(st.sampled_from([b"0", b'"x"', b"null", b"[]", b"{}"]))
    nested = opener * depth + inner + closer * depth
    placement = draw(st.sampled_from(["alone", "after", "before", "around", "unclosed"]))
    member = on_record_member(draw)
    if placement == "alone":
        return nested
    if placement == "after":
        return b"{" + member + b',"nested":' + nested + b"}"
    if placement == "before":
        return b'{"nested":' + nested + b"," + member + b"}"
    if placement == "around":
        return opener * depth + b"{" + member + b"}" + closer * depth
    return b"{" + member + b',"nested":' + opener * depth + b"}"


@st.composite
def huge_numbers(draw) -> bytes:
    """Numbers short, either side of the 1,000 digit limit and far past it, as the key or beside it."""
    length = draw(st.one_of(st.integers(1, 40), st.integers(300, 1100), st.integers(1000, 70000)))
    digit = draw(st.sampled_from(b"123456789")).to_bytes(1, "big")
    number = draw(st.sampled_from([
        digit * length, b"-" + digit * length, b"0." + b"0" * length + b"1", b"1e" + digit * min(length, 400),
        b"1E-" + digit * min(length, 400), b"-0", b"0e0", b"1" + b"0" * length + b".0", b"9" * length + b"e9999",
    ]))
    placement = draw(st.sampled_from(["beside", "as the key", "alone", "in an array"]))
    if placement == "beside":
        return b"{" + on_record_member(draw) + b',"n":' + number + b"}"
    if placement == "as the key":
        return b'{"public_key":' + number + b"}"
    if placement == "alone":
        return number
    return b"{" + on_record_member(draw) + b',"n":[' + number + b"," + number + b"]}"


@st.composite
def duplicate_names(draw) -> bytes:
    """Two public_key members, each spelt in any of the ways that read as public_key, holding any key."""
    names = st.sampled_from(['"public_key"', '"public\\u005fkey"', '"\\u0070ublic_key"'])
    first = draw(names) + ":" + draw(key_values().flatmap(value_literals))
    second = draw(names) + ":" + draw(key_values().flatmap(value_literals))
    return ("{" + first + "," + second + "}").encode("utf-8", errors="surrogatepass")


@st.composite
def near_the_limit(draw) -> bytes:
    """A body padded to a size either side of the 64 kilobyte limit, with the padding in different places."""
    size = LIMIT + draw(st.one_of(st.integers(-3, 3), st.integers(-600, 600), st.integers(-LIMIT + 100, 8192)))
    pad = draw(st.sampled_from(JSON_WHITESPACE)).encode()
    if draw(st.booleans()):
        member = on_record_member(draw)
    else:
        member = b'"public_key":' + draw(key_values().flatmap(value_literals)).encode("utf-8", "surrogatepass")
    place = draw(st.sampled_from(["before", "after", "inside", "sibling", "nested", "in the key"]))
    if place == "in the key":
        member = b'"public_key":"' + FIXTURES[0].spelling.encode()
    # The padding goes between head and tail, so the body is exactly the size drawn, or the core alone when
    # that is already larger.
    head, tail = {
        "before": (b"", b"{" + member + b"}"),
        "after": (b"{" + member + b"}", b""),
        "inside": (b"{", member + b"}"),
        "sibling": (b"{" + member + b',"pad":"', b'"}'),
        "nested": (b"{" + member + b',"pad":', b"}"),
        "in the key": (b"{" + member, b'"}'),
    }[place]
    room = max(size - len(head) - len(tail), 0)
    if place == "sibling":
        return head + b"x" * room + tail
    if place == "nested":
        half, odd = divmod(room, 2)
        return head + b"[" * half + b"]" * half + b" " * odd + tail
    return head + pad * room + tail


@st.composite
def random_bytes(draw) -> bytes:
    """Bytes with no structure, short, or a short pattern repeated to tens of kilobytes."""
    if draw(st.integers(0, 4)):
        return draw(st.binary(max_size=2048))
    pattern = draw(st.binary(min_size=1, max_size=16))
    return (pattern * (LIMIT // len(pattern) + 2))[:draw(st.integers(1, LIMIT + 2048))]


CONTENT_TYPES = (None, "", " ", "application/json; charset=utf-8", "APPLICATION/JSON", "application/json;charset=UTF-8",
                 "application/json; charset=utf-16", "application/json; charset=iso-8859-1", "text/plain",
                 "text/json", "application/x-www-form-urlencoded", "multipart/form-data; boundary=x",
                 "multipart/form-data", "application/xml", "application/octet-stream", "application/problem+json",
                 "application/merge-patch+json", "*/*", "application/json, text/plain", "application/json;;;",
                 "application/json; boundary=\"x\"", "application/jsonx", "json")


def content_types() -> st.SearchStrategy[str | None]:
    printable = st.text(alphabet=st.characters(min_codepoint=0x20, max_codepoint=0x7E) | st.just("\t"), max_size=60)
    return mostly(st.just(JSON_TYPE), st.sampled_from(CONTENT_TYPES) | printable)


def requests() -> st.SearchStrategy[Request]:
    built = documents()
    bodies = st.one_of(built, built, built, mutated(built), broken_strings(), deeply_nested(), huge_numbers(),
                       duplicate_names(), near_the_limit(), random_bytes())
    return st.builds(Request, body=bodies, content_type=content_types(),
                     chunked=mostly(st.just(0), st.sampled_from([1, 100, 4096, LIMIT])))


# -- what the answer must be --------------------------------------------------------------------------------

MAX_DEPTH = 64  # arrays and objects nested in one another, counting the outermost
MAX_DIGITS = 1000  # in one number, counting those of its fraction and exponent


class _Object(dict):
    """A JSON object, which holds the last value of a name used twice and keeps every member it was written with,
    so that the earlier value is checked too."""

    def __init__(self, members: list) -> None:
        super().__init__(members)
        self.members = members


def _number(token: str) -> str:
    if sum(char.isdigit() for char in token) > MAX_DIGITS:
        raise ValueError(f"a number with more than {MAX_DIGITS} digits")
    return token


def _constant(token: str):
    raise ValueError(f"{token} is not JSON")


def within_limits(document) -> bool:
    """Whether the document nests no deeper than MAX_DEPTH and holds no lone surrogate in any string or name."""
    pending = [(document, 1)]
    while pending:
        item, depth = pending.pop()
        if isinstance(item, str):
            # Python joins an escaped pair into one character, so any surrogate left is a lone one.
            if any("\ud800" <= char <= "\udfff" for char in item):
                return False
        elif isinstance(item, (list, _Object)):
            if depth > MAX_DEPTH:
                return False
            children = item if isinstance(item, list) else [part for member in item.members for part in member]
            pending.extend((child, depth + 1) for child in children)
    return True


def reading(body: bytes):
    """The value under public_key, read as the module's description sets out, or None when the body holds none,
    as a malformed body never does."""
    try:
        document = json.loads(body.decode("utf-8"), object_pairs_hook=_Object, parse_int=_number,
                              parse_float=_number, parse_constant=_constant)
    except (RecursionError, ValueError):
        # Nesting too deep for Python to read is deeper than the limit. UnicodeDecodeError and JSONDecodeError
        # are both ValueErrors.
        return None
    if not within_limits(document) or not isinstance(document, dict):
        return None
    return document.get("public_key")


@dataclasses.dataclass(frozen=True)
class Expected:
    kind: str  # "too large", "invalid" or "relationship"
    fixtures: tuple = ()


def expectation(request: Request, planted: tuple) -> Expected:
    if len(request.body) > LIMIT:
        return Expected("too large")
    value = reading(request.body)
    found = tuple(f for f in planted if f.spelling == value)
    return Expected("relationship", found) if found else Expected("invalid")


# -- sending ------------------------------------------------------------------------------------------------


@dataclasses.dataclass(frozen=True)
class Answer:
    status: int | str
    content_type: str | None
    body: bytes
    headers: tuple

    def __str__(self) -> str:
        body = self.body if len(self.body) <= 200 else self.body[:197] + b"..."
        return f"{self.status} {self.content_type or '(no Content-Type)'} {body!r}"


class Target:
    """One implementation, over one connection kept open between requests and reopened when it is lost."""

    def __init__(self, name: str, base_url: str) -> None:
        parts = urllib.parse.urlsplit(base_url)
        self.name, self.parts, self.connection = name, parts, None

    def open(self) -> http.client.HTTPConnection:
        if self.parts.scheme == "https":
            return http.client.HTTPSConnection(self.parts.hostname, self.parts.port, timeout=30, context=CTX)
        return http.client.HTTPConnection(self.parts.hostname, self.parts.port, timeout=30)

    def close(self) -> None:
        if self.connection is not None:
            self.connection.close()
        self.connection = None

    def send(self, request: Request) -> Answer:
        failure = None
        for _ in range(2):
            try:
                return self.exchange(request)
            except (OSError, http.client.HTTPException) as error:
                self.close()
                failure = error
        return Answer("ERR", None, f"{type(failure).__name__}: {failure}".encode()[:160], ())

    def exchange(self, request: Request) -> Answer:
        if self.connection is None:
            self.connection = self.open()
        headers = {} if request.content_type is None else {"Content-Type": request.content_type}
        body = request.body
        if request.chunked:
            body = (request.body[i:i + request.chunked] for i in range(0, len(request.body), request.chunked))
        try:
            self.connection.request("POST", PATH, body=body, headers=headers, encode_chunked=bool(request.chunked))
        except (BrokenPipeError, ConnectionResetError):
            pass  # a server may answer before it has read the whole body, so its answer is still read
        response = self.connection.getresponse()
        answer = Answer(response.status, response.getheader("Content-Type"), response.read(),
                        tuple(response.getheaders()))
        if response.will_close:
            self.close()
        return answer


# -- the properties -----------------------------------------------------------------------------------------


def parsed(body: bytes):
    try:
        return json.loads(body)
    except ValueError:
        return None


def echoes(request: Request, answer: Answer) -> bool:
    """Whether any response header carries sixteen or more bytes of the request body."""
    if len(request.body) < 16:
        return False
    for _, value in answer.headers:
        raw = value.encode("latin-1", errors="replace")
        if any(raw[i:i + 16] in request.body for i in range(0, max(len(raw) - 15, 0))):
            return True
    return False


def answer_violations(request: Request, expected: Expected, answer: Answer) -> list[str]:
    """The properties one implementation's answer breaks, each named as a sentence."""
    found = []
    if answer.status == 500:
        found.append("answered HTTP 500")
    elif answer.status == "ERR":
        found.append("gave no HTTP answer")
    elif expected.kind == "too large":
        if (answer.status, answer.body, answer.content_type) != (413, b"", None):
            found.append("a body over the limit was not answered HTTP 413 with no body and no Content-Type")
    elif (answer.status, answer.content_type) != (200, JSON_TYPE):
        found.append("a body within the limit was not answered HTTP 200 as exactly application/json")
    elif expected.kind == "invalid" and answer.body != INVALID:
        found.append('a body that is not an on-record key was not answered exactly {"valid":false}')
    elif expected.kind == "relationship" and parsed(answer.body) != expected.fixtures[0].answer:
        found.append("an on-record key in a well-formed JSON object was not answered with its relationship")
    if echoes(request, answer):
        found.append("a response header carries part of the request")
    return found


def disagreement(answers: dict, meaning) -> str | None:
    """Which implementations answered alike, when they did not all answer alike, as "a | b, c"."""
    groups: dict = {}
    for name, answer in answers.items():
        groups.setdefault(meaning(answer), []).append(name)
    if len(groups) < 2:
        return None
    return " | ".join(", ".join(names) for names in sorted(groups.values()))


def violations(request: Request, expected: Expected, answers: dict) -> list[tuple[str, str]]:
    found = [(name, where) for where, answer in answers.items()
             for name in answer_violations(request, expected, answer)]
    # Told apart so that a difference in how the same JSON is written does not hide a difference in what it says.
    split = disagreement(answers, meaning)
    written = disagreement(answers, lambda answer: answer.body)
    if split:
        found.append(("the implementations gave different answers", split))
    elif written:
        found.append(("the implementations wrote the same answer in different bytes", written))
    return found


def meaning(answer: Answer) -> tuple:
    body = parsed(answer.body)
    return answer.status, answer.content_type, answer.body if body is None else json.dumps(body, sort_keys=True)


# Hypothesis reports each distinct failure on its own, and tells failures apart by their exception's class, so
# every property on every implementation, for each kind of expected answer, gets a class of its own.
_CLASSES: dict = {}


class PropertyViolation(AssertionError):
    pass


def violation_class(name: str, where: str, kind: str) -> type:
    return _CLASSES.setdefault((name, where, kind), type("PropertyViolation", (PropertyViolation,), {}))


@dataclasses.dataclass
class Seen:
    count: int
    smallest: Request
    answers: dict


def describe(request: Request, expected: Expected, answers: dict) -> str:
    lines = [f"  request   {request!r}", f"  expected  {expected.kind}"
             + "".join(f" {f.relationship_type}" for f in expected.fixtures)]
    lines += [f"  {name:9} {answer}" for name, answer in answers.items()]
    return "\n".join(lines)


def fixed_requests(planted: tuple) -> list:
    """Requests every run sends first. They are each on-record key on its own, and a body exactly at the limit
    and one byte over it, with a declared length and in chunks of several sizes."""
    plain = [Request(json.dumps({"public_key": f.spelling}).encode()) for f in planted]
    padded = b'{"public_key":"' + FIXTURES[0].spelling.encode() + b'"}'
    return plain + [Request(padded[:-1] + b" " * (LIMIT - len(padded) + extra) + b"}", JSON_TYPE, chunked)
                    for extra in (0, 1) for chunked in (0, 100, 4096, LIMIT)]


def smaller(request: Request, than: Request) -> bool:
    if len(request.body) != len(than.body):
        return len(request.body) < len(than.body)
    return repr(request) < repr(than)


def build_check(targets: list, planted: tuple, seen: dict, counter: list, keep_going: bool):
    """Sends one request to every implementation and checks the answers. Every violation is recorded with
    the smallest request seen to cause it. Unless keep_going, the first is also raised, for Hypothesis to
    shrink."""

    def check(request: Request) -> None:
        counter[0] += 1
        expected = expectation(request, planted)
        answers = {target.name: target.send(request) for target in targets}
        found = violations(request, expected, answers)
        for name, where in found:
            # What the answer should have been is part of the key, so that a property broken where the relationship
            # was due and the same property broken where {"valid":false} was due are reported apart.
            key = (name, where, expected.kind)
            entry = seen.get(key)
            if entry is None:
                seen[key] = Seen(1, request, answers)
            else:
                entry.count += 1
                if smaller(request, entry.smallest):
                    entry.smallest, entry.answers = request, answers
        if keep_going and counter[0] % 1000 == 0:
            print(f"{counter[0]} requests, {len(seen)} distinct violation(s) so far", flush=True)
        if found and not keep_going:
            name, where = found[0]
            raise violation_class(name, where, expected.kind)(f"{name} ({where})\n"
                                                             + describe(request, expected, answers))

    return check


def build_test(check, examples: int, chosen_seed: int):
    """The Hypothesis test, which checks one generated request."""
    test = seed(chosen_seed)(given(request=requests())(check))
    return settings(max_examples=examples, deadline=None, database=None, print_blob=False,
                    suppress_health_check=list(HealthCheck), report_multiple_bugs=True)(test)


def run_atheris(test, libfuzzer_arguments: list) -> None:
    """Generation guided by coverage. libFuzzer mutates the bytes Hypothesis turns into requests, and keeps the
    ones that reach new branches of this file, which is where an answer's shape decides the path taken."""
    import atheris

    atheris.instrument_all()
    atheris.Setup([sys.argv[0], *libfuzzer_arguments], test.hypothesis.fuzz_one_input)
    atheris.Fuzz()


def report(seen: dict, planted: tuple) -> None:
    for (name, where, _), entry in sorted(seen.items(), key=lambda item: item[0]):
        print(f"\n[VIOLATION] {name}  [{where}]  in {entry.count} request(s), the smallest:")
        print(describe(entry.smallest, expectation(entry.smallest, planted), entry.answers))


def run_everywhere(apps: list, statements, doing: str) -> bool:
    """Runs each application's statements against its database, and says which could not."""
    succeeded = True
    for name, _, engine, sql_command in apps:
        try:
            code, output = run_sql(sql_command, statements(Engine(engine)))
        except subprocess.TimeoutExpired:
            code, output = 1, "the SQL command did not finish within a minute"
        if code != 0:
            print(f"could not {doing} for {name}: {output.strip()[:300]}")
            succeeded = False
    return succeeded


def plant(apps: list) -> bool:
    return run_everywhere(apps, planting_sql, "plant the fixtures")


def remove(apps: list) -> None:
    run_everywhere(apps, lambda engine: engine.prelude + removal_sql() + "\n", "remove the fixtures")


def arguments() -> tuple[argparse.Namespace, list]:
    """The options, and the arguments left over for libFuzzer."""
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--app", nargs=4, action="append", default=[], metavar=("NAME", "URL", "ENGINE", "SQL"),
                        help="An implementation, its address, its database engine "
                             f"({', '.join(NAMES)}) and a command that reads SQL on standard input (repeat).")
    parser.add_argument("--base-url", action="append", dest="base_urls", default=[], metavar="URL",
                        help="An implementation to fuzz with no on-record keys (repeat). With neither option, "
                             "the three development ports.")
    parser.add_argument("--examples", type=int, help=f"Requests to generate. Default {CI_EXAMPLES}, "
                                                     f"or {LONG_EXAMPLES} with --long.")
    parser.add_argument("--seed", type=int, help=f"The seed to generate from. Default {CI_SEED}, "
                                                 "or a new one with --long.")
    parser.add_argument("--long", action="store_true", help="A long run from a new seed, for a machine left to it.")
    parser.add_argument("--engine", choices=("hypothesis", "atheris"), default="hypothesis",
                        help="Atheris runs until stopped and takes libFuzzer's own arguments.")
    args, libfuzzer_arguments = parser.parse_known_args()
    if libfuzzer_arguments and args.engine != "atheris":
        parser.error(f"unrecognised arguments: {' '.join(libfuzzer_arguments)}")
    for name, _, engine, _ in args.app:
        if engine not in NAMES:
            parser.error(f"--app {name}: the engine must be one of {', '.join(NAMES)}")
    return args, libfuzzer_arguments


def fuzz(args: argparse.Namespace, libfuzzer_arguments: list, targets: list, planted: tuple) -> bool:
    """Sends the fixed requests and then the generated ones. Answers whether any property was broken."""
    examples = args.examples or (LONG_EXAMPLES if args.long else CI_EXAMPLES)
    chosen_seed = args.seed if args.seed is not None else (random.randrange(2**32) if args.long else CI_SEED)
    seen: dict = {}
    counter = [0]
    # A long run goes through every example rather than stopping to shrink the first violation, so that one
    # violation many requests share does not end it early. Each is still reported with its smallest request.
    # Atheris stops at the first violation it meets, so it is always raised there.
    check = build_check(targets, planted, seen, counter, keep_going=args.long and args.engine == "hypothesis")
    test = build_test(check, examples, chosen_seed)
    started = time.monotonic()
    shrunk_any = False
    try:
        for request in fixed_requests(planted):
            build_check(targets, planted, seen, counter, keep_going=True)(request)
        if args.engine == "atheris":
            run_atheris(test, libfuzzer_arguments)
        print(f"{examples} examples from seed {chosen_seed}. Replay with --seed {chosen_seed} --examples {examples}"
              + (" --long." if args.long else "."))
        test()
    except Exception as error:  # noqa: BLE001 -- Hypothesis raises the violations it shrank, one or a group
        shrunk_any = True
        for shrunk in getattr(error, "exceptions", [error]):
            print(f"\n[SHRUNK] {shrunk}")
    report(seen, planted)
    print(f"\n{counter[0]} requests to each of {len(targets)} implementation(s) in {time.monotonic() - started:.0f}"
          f" seconds: {len(seen)} distinct violation(s).")
    return shrunk_any or bool(seen)


def main() -> None:
    args, libfuzzer_arguments = arguments()
    targets = [Target(name, url) for name, url, _, _ in args.app]
    targets += [Target(url, url) for url in args.base_urls or ([] if args.app else DEFAULT_BASE_URLS)]
    print("Fuzzing POST /api/v1/attest on:")
    for target in targets:
        print(f"  {target.name:9} {target.parts.geturl()}")

    planted = FIXTURES if args.app and not args.base_urls else ()
    if not planted:
        print("with no on-record keys, because every implementation needs an --app for them.")
    elif not plant(args.app):
        sys.exit(1)
    else:
        print(f"with {len(FIXTURES)} on-record keys planted in each database.")

    try:
        broken = fuzz(args, libfuzzer_arguments, targets, planted)
    finally:
        if planted:
            remove(args.app)
    sys.exit(1 if broken else 0)


if __name__ == "__main__":
    main()
