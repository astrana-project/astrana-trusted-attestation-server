#!/usr/bin/env python3
"""Configures the dev WSO2 Identity Server to serve the three implementations over SAML 2.0.

Run after WSO2 is up and shared/test/wso2/configure.py has created the members:

    python shared/test/wso2/configure.py       # members (externalId = fixture UUID) + password policy
    python shared/test/wso2/configure-saml.py  # one SAML service provider per implementation

Registers one SAML service provider per implementation (ata-saml-dotnet/java/php), each with that app's own
entity id and assertion-consumer URL, and each pinning the SAML NameID to the member's SCIM externalId (the
fixture UUID the conformance fixtures grant against) so the emitted subject is the UUID -- the same subject
the OIDC app uses. WSO2 signs the assertion with its primary keystore certificate, which the apps trust from
the IdP metadata; request-signature validation is off, so no SP certificate has to be registered.

The response is signed with RSA-SHA256, and the SAML config is kept deliberately MINIMAL. This WSO2 build
signs the SAML signature's reference with a SHA-1 digest -- which Sustainsys (.NET) refuses as too weak --
whenever the SP's SAML config carries a singleSignOnProfile / singleLogoutProfile block; with only the
issuer, ACS, request-validation, and response-signing fields set, it uses a SHA-256 digest instead, which
all three implementations accept. (SAMLDefaultDigestAlgorithmURI in identity.xml does not affect this; the
digest is taken from the service provider's own stored setting.) So the extra profile blocks are left off
and WSO2's defaults for them apply.

Idempotent: an existing SP of each name has its SAML config and subject reconciled in place.
"""

import json
import ssl
import urllib.error
import urllib.request

BASE = "https://localhost:9443"
AUTH = "Basic YWRtaW46YWRtaW4="  # admin:admin
CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE

# The subject rides the SCIM externalId (configure.py sets it to the fixture UUID); pinning it as the SP's
# subject makes the SAML NameID the UUID, so the apps -- whose subject claim falls back to the NameID -- key
# the member by the UUID the fixtures granted against. Same claim the OIDC app uses.
SUBJECT_CLAIM = "http://wso2.org/claims/externalid"

# Each implementation's SAML entity id and assertion-consumer URL (the same values register-saml-sps.py uses
# for Zitadel). One SP per implementation because each has its own entity id.
SPS = {
    "ata-saml-dotnet": {
        "issuer": "https://localhost:15443/Saml2",
        "acs": "https://localhost:15443/Saml2/Acs",
    },
    "ata-saml-java": {
        "issuer": "https://localhost:16443/saml2/service-provider-metadata/keycloak",
        "acs": "https://localhost:16443/login/saml2/sso/keycloak",
    },
    "ata-saml-php": {
        "issuer": "https://localhost:17443/auth/saml/metadata",
        "acs": "https://localhost:17443/auth/saml/acs",
    },
}


def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, method=method,
                                 headers={"Authorization": AUTH, "Content-Type": "application/json",
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


def saml_inbound(issuer, acs):
    return {
        "manualConfiguration": {
            "issuer": issuer,
            # WSO2's field names are assertionConsumerUrls / defaultAssertionConsumerUrl (no "Service").
            "assertionConsumerUrls": [acs],
            "defaultAssertionConsumerUrl": acs,
            # No AuthnRequest signature check, so no SP certificate has to be registered here; the apps still
            # sign their requests, WSO2 just does not require it.
            "requestValidation": {"enableSignatureValidation": False},
            # Sign the response with RSA-SHA256. Keep this config minimal (no singleSignOnProfile /
            # singleLogoutProfile blocks): with those present this WSO2 build falls back to a SHA-1 signature
            # digest that Sustainsys (.NET) refuses; without them it uses SHA-256, which all three accept.
            "responseSigning": {"enabled": True,
                                "signingAlgorithm": "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"},
        }
    }


def claim_config():
    return {"subject": {"claim": {"uri": SUBJECT_CLAIM},
                        "includeUserDomain": False, "includeTenantDomain": False}}


def find_app(name):
    status, apps = call("GET", f"/api/server/v1/applications?filter=name+eq+{name}")
    if isinstance(apps, dict):
        for a in apps.get("applications", []):
            if a.get("name") == name:
                return a["id"]
    return None


def ensure_sp(name, issuer, acs):
    # WSO2 rejects an inline SAML inbound in the app-create body, so create the app first (subject + consent)
    # and attach the SAML protocol with a separate PUT -- the same path the reconcile branch takes.
    app_id = find_app(name)
    verb = "updated"
    if app_id is None:
        status, resp = call("POST", "/api/server/v1/applications",
                            {"name": name,
                             "advancedConfigurations": {"skipLoginConsent": True, "skipLogoutConsent": True},
                             "claimConfiguration": claim_config()})
        if status not in (200, 201):
            raise SystemExit(f"could not create app {name}: {status} {resp}")
        app_id = find_app(name)
        if app_id is None:
            raise SystemExit(f"created app {name} but could not find its id")
        verb = "created"
    else:
        call("PATCH", f"/api/server/v1/applications/{app_id}",
             {"claimConfiguration": claim_config(),
              "advancedConfigurations": {"skipLoginConsent": True, "skipLogoutConsent": True}})

    status, resp = call("PUT", f"/api/server/v1/applications/{app_id}/inbound-protocols/saml",
                        saml_inbound(issuer, acs))
    if status not in (200, 201):
        raise SystemExit(f"could not configure SAML on {name}: {status} {resp}")
    print(f"  {verb} SAML SP: {name} ({issuer})")


def main():
    for name, sp in SPS.items():
        ensure_sp(name, sp["issuer"], sp["acs"])
    print()
    print(f"idp entity id: localhost")
    print(f"idp metadata:  {BASE}/identity/metadata/saml2")
    print("subject: the SAML NameID is the SCIM externalId = the fixture UUID (apps fall back to NameID)")


if __name__ == "__main__":
    main()
