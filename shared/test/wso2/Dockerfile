# WSO2 Identity Server for the dev conformance matrix, with a TLS certificate that is not expired.
#
# The stock wso2/wso2is:7.1.0 image ships a demo certificate that expired in January 2026, and serves TLS
# from the primary keystore (the [keystore.tls] section is commented out). A .NET/Java/PHP app validating
# WSO2's certificate on the OIDC/SAML backchannel refuses an expired one, so the matrix could never reach it.
#
# This image drops in a throwaway certificate created on each machine by shared/test/generate-idp-material.sh into
# generated/, which Git ignores, so run that before building. generated/tls.p12 holds the key and certificate for
# CN=localhost with a SAN, valid ten years, under the alias and store password "wso2carbon" so nothing else has to
# change. The image points the TLS listener at it via [keystore.tls], and trusts that certificate in WSO2's own
# client truststore so the server accepts it on its internal HTTPS calls. The matrix mounts the matching public
# certificate, generated/wso2-tls.crt, into the three implementations so they trust it on the backchannel.
# The digest pins the exact base image, and Dependabot moves it when the tag gets a new image.
FROM wso2/wso2is:7.1.0@sha256:b874455a8add57a0377d3963427a694c700649933b9169a00fb6eb308a502947

USER root

ARG HOME=/home/wso2carbon
ARG SEC=/home/wso2carbon/wso2is-7.1.0/repository/resources/security
ARG TOML=/home/wso2carbon/wso2is-7.1.0/repository/conf/deployment.toml

# The fresh TLS keystore, and the public certificate WSO2's internal clients (and the three implementations) trust.
COPY generated/tls.p12 $SEC/tls.p12
COPY generated/wso2-tls.crt $HOME/wso2-tls.crt

RUN set -eu; \
    # Point the HTTPS listener at the fresh keystore. The stock [keystore.tls] block is commented out, so the
    # server would otherwise fall back to the (expired) primary keystore; append an active section. file_name
    # is enough -- type/password/alias inherit the primary keystore's values, which tls.p12 matches.
    printf '\n[keystore.tls]\nfile_name = "tls.p12"\ntype = "PKCS12"\npassword = "wso2carbon"\n' >> "$TOML"; \
    # SAML responses are signed with the PRIMARY keystore (wso2carbon.p12), whose shipped cert also expired in
    # January 2026 -- so a signed assertion would carry an expired certificate. Replace its key entry with the
    # same fresh keypair, keeping the alias and passwords the deployment.toml already references.
    keytool -delete -alias wso2carbon -keystore "$SEC/wso2carbon.p12" -storepass wso2carbon -storetype PKCS12 2>/dev/null || true; \
    keytool -importkeystore -noprompt \
        -srckeystore "$SEC/tls.p12" -srcstoretype PKCS12 -srcstorepass wso2carbon -srcalias wso2carbon -srckeypass wso2carbon \
        -destkeystore "$SEC/wso2carbon.p12" -deststoretype PKCS12 -deststorepass wso2carbon -destalias wso2carbon -destkeypass wso2carbon; \
    # Trust the new certificate in the client truststore, replacing any prior entry under this alias, so the
    # server does not reject its own certificate on internal HTTPS calls.
    keytool -delete -alias wso2carbon-tls -keystore "$SEC/client-truststore.p12" -storepass wso2carbon -storetype PKCS12 2>/dev/null || true; \
    keytool -importcert -noprompt -alias wso2carbon-tls -file "$HOME/wso2-tls.crt" \
        -keystore "$SEC/client-truststore.p12" -storepass wso2carbon -storetype PKCS12; \
    chown wso2carbon:wso2 "$SEC/tls.p12" "$SEC/wso2carbon.p12" "$SEC/client-truststore.p12" "$HOME/wso2-tls.crt"

USER wso2carbon
