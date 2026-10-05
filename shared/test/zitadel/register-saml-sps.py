#!/usr/bin/env python3
"""Registers the three implementations as SAML service providers on the dev Zitadel instance.

Run after configure.py (which creates the project and the members) and after the apps are up in SAML mode.
Zitadel needs each SP's metadata to accept its AuthnRequests and to know where to post the assertion, so this
builds a minimal metadata document for each from its fixed entity id, assertion-consumer URL, and dev
signing certificate, and registers it. Idempotent: a SAML app that already exists (same name) is left as is.

The subject the members are granted against is Zitadel's user id (the fixture UUID). Over SAML, Zitadel
carries that in a `UserID` attribute rather than the NameID (which is the login name), so an implementation
run against Zitadel-SAML sets its subject-claim to `UserID`. That is a deployment setting, not a code
change -- the same knob that points an OIDC deployment at a non-default subject claim.

    python shared/test/zitadel/register-saml-sps.py
"""

import argparse
import base64
import json
import pathlib
import subprocess
import urllib.error
import urllib.request

BASE = "http://localhost:9003"
PAT_FILE = pathlib.Path(__file__).parent / "machinekey" / "pat.txt"
HEAD = {"Content-Type": "application/json"}
ROOT = pathlib.Path(__file__).resolve().parents[3]

# Each SP: its entity id, assertion-consumer URL, and where to find its dev signing certificate under this
# repository. The .NET certificate lives inside a PKCS#12 bundle and is extracted with openssl, and the
# other two are PEM files.
SPS = {
    "dotnet-saml": {
        "entity_id": "https://localhost:15443/Saml2",
        "acs": "https://localhost:15443/Saml2/Acs",
        "pfx": ROOT / "dotnet" / "src" / "Astrana.TrustedAttestation.Server" / "saml" / "sp-dev.pfx",
        "pfx_password": "changeit",
    },
    "java-saml": {
        "entity_id": "https://localhost:16443/saml2/service-provider-metadata/keycloak",
        "acs": "https://localhost:16443/login/saml2/sso/keycloak",
        "crt": ROOT / "java" / "saml" / "sp-certificate.crt",
    },
    "php-saml": {
        "entity_id": "https://localhost:17443/auth/saml/metadata",
        "acs": "https://localhost:17443/auth/saml/acs",
        "crt": ROOT / "php" / "saml" / "sp-certificate.crt",
    },
}


def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, headers=HEAD, method=method)
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()[:200]


def certificate_body(sp) -> str:
    """The base64 certificate body (no PEM header/footer, no newlines) for the SP's signing cert.

    Only the base64 strictly between the BEGIN/END CERTIFICATE markers counts. `openssl pkcs12` prints a
    preamble -- Bag Attributes, localKeyID, subject=, issuer= -- ahead of the certificate, and those lines
    are not base64: concatenated into the body they make an undecodable blob, which Zitadel rejects with
    "failed to decode certificate", denying the SP and every login through it. A PEM file (Java, PHP) has
    no such preamble, so only the .NET SP -- extracted from a PKCS#12 bundle -- was affected."""
    if "crt" in sp:
        pem = sp["crt"].read_text()
    else:
        pem = subprocess.run(
            ["openssl", "pkcs12", "-in", str(sp["pfx"]), "-nokeys", "-clcerts",
             "-passin", f"pass:{sp['pfx_password']}"],
            capture_output=True, text=True, check=True).stdout
    body, keep = [], False
    for ln in pem.splitlines():
        if "BEGIN CERTIFICATE" in ln:
            keep = True
        elif "END CERTIFICATE" in ln:
            break
        elif keep and ln.strip():
            body.append(ln.strip())
    return "".join(body)


def metadata_xml(sp) -> str:
    cert = certificate_body(sp)
    return (
        '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" '
        'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '
        f'entityID="{sp["entity_id"]}">'
        '<md:SPSSODescriptor AuthnRequestsSigned="true" WantAssertionsSigned="true" '
        'protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
        f'<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data>'
        f'<ds:X509Certificate>{cert}</ds:X509Certificate>'
        '</ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
        '<md:AssertionConsumerService '
        'Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" '
        f'Location="{sp["acs"]}" index="0"/>'
        '</md:SPSSODescriptor></md:EntityDescriptor>'
    )


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.parse_args()

    # Read here rather than at import, so --help works on a machine that has never started Zitadel. The
    # token is written by the Zitadel compose stack's first start (see docker-compose.yml).
    if not PAT_FILE.exists():
        raise SystemExit(f"{PAT_FILE} not found. Start the Zitadel stack first, it writes the token")
    HEAD["Authorization"] = f"Bearer {PAT_FILE.read_text().strip()}"

    for name, sp in SPS.items():
        material = sp.get("crt") or sp.get("pfx")
        if not material.exists():
            raise SystemExit(f"{name}: signing material {material} not found")

    _, res = call("POST", "/management/v1/projects/_search", {})
    project_id = next((p["id"] for p in (res.get("result") or []) if p.get("name") == "trusted-attestation"), None)
    if not project_id:
        raise SystemExit("trusted-attestation project not found; run configure.py first")

    _, apps = call("POST", f"/management/v1/projects/{project_id}/apps/_search", {})
    existing = {a.get("name"): a.get("id") for a in (apps.get("result") or [])}

    for name, sp in SPS.items():
        meta = base64.b64encode(metadata_xml(sp).encode()).decode()
        if name in existing:
            # Reconcile rather than skip: push the current metadata so a corrected certificate or ACS URL
            # takes effect on a re-run, instead of leaving a stale -- possibly broken -- registration in
            # place. (A malformed SP certificate here made Zitadel deny every login through the SP.)
            status, resp = call("PUT", f"/management/v1/projects/{project_id}/apps/{existing[name]}/saml_config",
                                {"metadataXml": meta})
            if status in (200, 201):
                print(f"  updated SAML SP: {name}")
            else:
                raise SystemExit(f"could not update {name}: {status} {resp}")
        else:
            status, app = call("POST", f"/management/v1/projects/{project_id}/apps/saml",
                               {"name": name, "metadataXml": meta})
            if status in (200, 201):
                print(f"  registered SAML SP: {name}")
            else:
                raise SystemExit(f"could not register {name}: {status} {app}")

    print("subject-claim for a Zitadel SAML deployment: UserID")


if __name__ == "__main__":
    main()
