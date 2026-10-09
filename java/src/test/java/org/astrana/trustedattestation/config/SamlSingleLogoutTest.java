package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.ByteArrayOutputStream;
import java.math.BigInteger;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.Signature;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.Base64;
import java.util.Date;
import java.util.List;
import java.util.UUID;
import java.util.function.Consumer;
import java.util.zip.Deflater;
import java.util.zip.DeflaterOutputStream;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Protocol;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cert.jcajce.JcaX509v3CertificateBuilder;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.mock.web.MockHttpSession;
import org.springframework.security.core.context.SecurityContextImpl;
import org.springframework.security.saml2.core.Saml2X509Credential;
import org.springframework.security.saml2.provider.service.authentication.Saml2AssertionAuthentication;
import org.springframework.security.saml2.provider.service.authentication.Saml2ResponseAssertion;
import org.springframework.security.saml2.provider.service.registration.InMemoryRelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.Saml2MessageBinding;
import org.springframework.security.web.FilterChainProxy;
import org.springframework.security.web.context.HttpSessionSecurityContextRepository;
import org.springframework.web.servlet.config.annotation.EnableWebMvc;

/**
 * SAML single logout needs a signing key, in both directions. Without one the server offers no single
 * logout: its metadata advertises none, and a logout request the identity provider sends anyway is neither
 * answered nor acted on, since the answer would have to be signed. The same as the other two
 * implementations. Each case runs through the real security filter chain, against an identity provider
 * that advertises single logout, and each is paired with the same request made with a key, so a test
 * cannot pass because the request itself was wrong.
 */
class SamlSingleLogoutTest {

    private static final String PROVIDER = "https://idp.example";

    private static final String PROVIDER_SINGLE_LOGOUT = PROVIDER + "/slo";

    private static final String OWN_SINGLE_LOGOUT = "https://sp.example" + SamlRequestParameterScope.SINGLE_LOGOUT_PATH;

    private static final String SIGNATURE_ALGORITHM = "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256";

    private static final KeyPair PROVIDER_KEY = keyPair();

    private static final X509Certificate PROVIDER_CERTIFICATE = certificate(PROVIDER_KEY, "CN=idp.example");

    // -- The service provider's metadata --------------------------------------------------------------

    @Test
    void withoutASigningKeyTheMetadataAdvertisesNoSingleLogout() {
        MockHttpServletResponse response = send(false, metadataRequest());

        assertThat(response.getStatus()).isEqualTo(200);
        assertThat(body(response)).contains("AssertionConsumerService").doesNotContain("SingleLogoutService");
    }

    @Test
    void withASigningKeyTheMetadataAdvertisesSingleLogout() {
        assertThat(body(send(true, metadataRequest()))).contains("SingleLogoutService");
    }

    // -- A logout request the identity provider sends -------------------------------------------------

    @Test
    void withoutASigningKeyAProviderLogoutRequestIsAnsweredAsAbsent() {
        // Single logout is not offered, so its address behaves as absent: HTTP 404 with no body and no content
        // type, and the member stays signed in. The same answer as the other two implementations.
        MockHttpServletRequest request = signedLogoutRequest();
        MockHttpSession session = (MockHttpSession) request.getSession(false);

        assertAnsweredAsAbsent(send(false, request), session);
    }

    @Test
    void withoutASigningKeyAPostedProviderLogoutRequestIsAnsweredAsAbsent() {
        MockHttpServletRequest request = postedLogoutRequest();
        MockHttpSession session = (MockHttpSession) request.getSession(false);

        assertAnsweredAsAbsent(send(false, request), session);
    }

    @Test
    void withoutASigningKeyOrASingleLogoutAddressAProviderLogoutRequestIsAnsweredAsAbsent() {
        // The usual shape of a deployment without a key, which has no single logout address configured either.
        MockHttpServletRequest request = signedLogoutRequest();
        MockHttpSession session = (MockHttpSession) request.getSession(false);

        assertAnsweredAsAbsent(send(false, false, request), session);
    }

    @Test
    void withASigningKeyAProviderLogoutRequestEndsTheSessionAndIsAnswered() {
        MockHttpServletRequest request = signedLogoutRequest();
        MockHttpSession session = (MockHttpSession) request.getSession(false);

        MockHttpServletResponse response = send(true, request);

        assertThat(session.isInvalid()).as("the session was not ended").isTrue();
        assertThat(response.getRedirectedUrl())
                .startsWith(PROVIDER_SINGLE_LOGOUT + "?SAMLResponse=")
                .contains("&Signature=");
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    /** Sends one request through the real SAML security chain, with or without a signing key configured. */
    private static MockHttpServletResponse send(boolean withSigningKey, MockHttpServletRequest request) {
        return send(withSigningKey, true, request);
    }

    /** As above, with or without this service provider's own single logout address configured as well. */
    private static MockHttpServletResponse send(
            boolean withSigningKey, boolean withOwnSingleLogout, MockHttpServletRequest request) {
        MockHttpServletResponse response = new MockHttpServletResponse();
        new WebApplicationContextRunner()
                .withUserConfiguration(Context.class, SecurityConfig.class)
                .withPropertyValues("trusted-attestation.iam.protocol=SAML")
                .withBean(TrustedAttestationProperties.class, SamlSingleLogoutTest::properties)
                .withBean(
                        RelyingPartyRegistrationRepository.class,
                        () -> relyingParties(withSigningKey, withOwnSingleLogout))
                .run(context ->
                        context.getBean(FilterChainProxy.class).doFilter(request, response, (passed, answered) -> {}));
        return response;
    }

    private static TrustedAttestationProperties properties() {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getIam().setProtocol(Protocol.SAML);
        return properties;
    }

    private static RelyingPartyRegistrationRepository relyingParties(
            boolean withSigningKey, boolean withOwnSingleLogout) {
        Consumer<java.util.Collection<Saml2X509Credential>> signing = credentials -> {
            if (withSigningKey) {
                KeyPair key = keyPair();
                credentials.add(Saml2X509Credential.signing(key.getPrivate(), certificate(key, "CN=sp.example")));
            }
        };

        return new InMemoryRelyingPartyRegistrationRepository(RelyingPartyRegistration.withRegistrationId("keycloak")
                .entityId("https://sp.example/saml2/service-provider-metadata/keycloak")
                .signingX509Credentials(signing)
                .singleLogoutServiceLocation(withOwnSingleLogout ? OWN_SINGLE_LOGOUT : null)
                .singleLogoutServiceResponseLocation(withOwnSingleLogout ? OWN_SINGLE_LOGOUT : null)
                .singleLogoutServiceBinding(Saml2MessageBinding.REDIRECT)
                .assertingPartyMetadata(party -> party.entityId(PROVIDER)
                        .singleSignOnServiceLocation(PROVIDER + "/sso")
                        .singleLogoutServiceLocation(PROVIDER_SINGLE_LOGOUT)
                        .singleLogoutServiceResponseLocation(PROVIDER_SINGLE_LOGOUT)
                        .singleLogoutServiceBinding(Saml2MessageBinding.REDIRECT)
                        .verificationX509Credentials(
                                credentials -> credentials.add(Saml2X509Credential.verification(PROVIDER_CERTIFICATE))))
                .build());
    }

    private static MockHttpServletRequest metadataRequest() {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/saml2/service-provider-metadata/keycloak");
        request.setSecure(true);
        return request;
    }

    private static void assertAnsweredAsAbsent(MockHttpServletResponse response, MockHttpSession session) {
        assertThat(response.getStatus()).isEqualTo(404);
        assertThat(body(response)).isEmpty();
        assertThat(response.getContentType()).isNull();
        assertThat(response.getRedirectedUrl()).isNull();
        assertThat(session.isInvalid()).as("the session was ended").isFalse();
    }

    /** A logout request from the identity provider, naming the member signed in here. */
    private static String logoutRequestXml() {
        return "<samlp:LogoutRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" "
                + "xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" "
                + "ID=\"_" + UUID.randomUUID() + "\" Version=\"2.0\" IssueInstant=\"" + Instant.now() + "\" "
                + "Destination=\"" + OWN_SINGLE_LOGOUT + "\">"
                + "<saml:Issuer>" + PROVIDER + "</saml:Issuer>"
                + "<saml:NameID>alice</saml:NameID>"
                + "<samlp:SessionIndex>the-session-index</samlp:SessionIndex>"
                + "</samlp:LogoutRequest>";
    }

    /**
     * The logout request over the HTTP-Redirect binding, deflated, encoded and signed over the query string
     * with the identity provider's key, from a browser whose session belongs to the member it names.
     */
    private static MockHttpServletRequest signedLogoutRequest() {
        String samlRequest = Base64.getEncoder().encodeToString(deflated(logoutRequestXml()));
        String signed = "SAMLRequest=" + encoded(samlRequest) + "&SigAlg=" + encoded(SIGNATURE_ALGORITHM);
        String signature = Base64.getEncoder().encodeToString(signedWithProviderKey(signed));

        MockHttpServletRequest request =
                new MockHttpServletRequest("GET", SamlRequestParameterScope.SINGLE_LOGOUT_PATH);
        request.setSecure(true);
        request.setQueryString(signed + "&Signature=" + encoded(signature));
        request.addParameter("SAMLRequest", samlRequest);
        request.addParameter("SigAlg", SIGNATURE_ALGORITHM);
        request.addParameter("Signature", signature);
        request.setSession(signedInSession());
        return request;
    }

    /** The logout request over the HTTP-POST binding, from a browser whose session belongs to the member. */
    private static MockHttpServletRequest postedLogoutRequest() {
        MockHttpServletRequest request =
                new MockHttpServletRequest("POST", SamlRequestParameterScope.SINGLE_LOGOUT_PATH);
        request.setSecure(true);
        request.setContentType("application/x-www-form-urlencoded");
        request.addParameter(
                "SAMLRequest",
                Base64.getEncoder().encodeToString(logoutRequestXml().getBytes(StandardCharsets.UTF_8)));
        request.setSession(signedInSession());
        return request;
    }

    private static MockHttpSession signedInSession() {
        MockHttpSession session = new MockHttpSession();
        session.setAttribute(
                HttpSessionSecurityContextRepository.SPRING_SECURITY_CONTEXT_KEY,
                new SecurityContextImpl(new Saml2AssertionAuthentication(
                        Saml2ResponseAssertion.withResponseValue("response")
                                .nameId("alice")
                                .build(),
                        List.of(),
                        "keycloak")));
        return session;
    }

    private static String body(MockHttpServletResponse response) {
        try {
            return response.getContentAsString();
        } catch (java.io.UnsupportedEncodingException ex) {
            throw new IllegalStateException(ex);
        }
    }

    private static String encoded(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8);
    }

    private static byte[] deflated(String xml) {
        ByteArrayOutputStream bytes = new ByteArrayOutputStream();
        try (DeflaterOutputStream deflate =
                new DeflaterOutputStream(bytes, new Deflater(Deflater.DEFAULT_COMPRESSION, true))) {
            deflate.write(xml.getBytes(StandardCharsets.UTF_8));
        } catch (java.io.IOException ex) {
            throw new IllegalStateException(ex);
        }
        return bytes.toByteArray();
    }

    private static byte[] signedWithProviderKey(String content) {
        try {
            Signature signature = Signature.getInstance("SHA256withRSA");
            signature.initSign(PROVIDER_KEY.getPrivate());
            signature.update(content.getBytes(StandardCharsets.UTF_8));
            return signature.sign();
        } catch (java.security.GeneralSecurityException ex) {
            throw new IllegalStateException(ex);
        }
    }

    private static KeyPair keyPair() {
        try {
            KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
            generator.initialize(2048);
            return generator.generateKeyPair();
        } catch (java.security.GeneralSecurityException ex) {
            throw new IllegalStateException(ex);
        }
    }

    private static X509Certificate certificate(KeyPair key, String subject) {
        try {
            X500Name name = new X500Name(subject);
            Instant now = Instant.now();
            return new JcaX509CertificateConverter()
                    .getCertificate(new JcaX509v3CertificateBuilder(
                                    name,
                                    BigInteger.ONE,
                                    Date.from(now.minus(1, ChronoUnit.DAYS)),
                                    Date.from(now.plus(1, ChronoUnit.DAYS)),
                                    name,
                                    key.getPublic())
                            .build(new JcaContentSignerBuilder("SHA256withRSA").build(key.getPrivate())));
        } catch (Exception ex) {
            throw new IllegalStateException(ex);
        }
    }

    @Configuration
    @EnableWebMvc
    static class Context {

        @Bean
        MemberIdentityResolver identities(TrustedAttestationProperties properties) {
            return new MemberIdentityResolver(properties);
        }
    }
}
