package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.ByteArrayOutputStream;
import java.math.BigInteger;
import java.net.URLDecoder;
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
import java.util.Map;
import java.util.UUID;
import java.util.function.Consumer;
import java.util.zip.Deflater;
import java.util.zip.DeflaterOutputStream;
import java.util.zip.Inflater;
import java.util.zip.InflaterOutputStream;
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
import org.springframework.security.saml2.provider.service.authentication.DefaultSaml2AuthenticatedPrincipal;
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
 * A logout request the identity provider signs ends the session only when its NameID names the member whose
 * session the browser holds. One naming anyone else is answered with a signed logout response whose status is
 * Requester, and the session stays, as does one whose NotOnOrAfter has passed. With no session at all there is
 * nobody to compare against, so the request is answered with Success. The session index is not compared. This runs the real security filter chain with
 * Spring Security's own single logout filter.
 */
class SamlProviderLogoutTest {

    private static final String PROVIDER = "https://idp.example";

    private static final String PROVIDER_SINGLE_LOGOUT = "https://idp.example/slo";

    /** Where the logout request is addressed. A mock request's base address is http://localhost. */
    private static final String OWN_SINGLE_LOGOUT = "http://localhost" + SamlRequestParameterScope.SINGLE_LOGOUT_PATH;

    private static final String SIGNATURE_ALGORITHM = "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256";

    private static final String SUCCESS = "urn:oasis:names:tc:SAML:2.0:status:Success";

    private static final String REQUESTER = "urn:oasis:names:tc:SAML:2.0:status:Requester";

    private static final KeyPair PROVIDER_KEY = keyPair();

    private static final X509Certificate PROVIDER_CERTIFICATE = certificate("idp.example", PROVIDER_KEY);

    private static final KeyPair OWN_KEY = keyPair();

    private static final X509Certificate OWN_CERTIFICATE = certificate("sp.example", OWN_KEY);

    @Test
    void aLogoutRequestNamingAnotherMemberKeepsTheSessionAndIsAnsweredRequester() {
        MockHttpSession session = signedIn("member-a", "session-a");

        MockHttpServletResponse response = send(logoutRequest("member-b", "session-a"), session);

        assertThat(response.getStatus()).isEqualTo(302);
        assertThat(answeredStatus(response)).isEqualTo(REQUESTER);
        assertThat(response.getContentAsByteArray()).isEmpty();
        assertThat(session.isInvalid()).isFalse();
        assertThat(session.getAttribute(HttpSessionSecurityContextRepository.SPRING_SECURITY_CONTEXT_KEY))
                .isNotNull();
        assertThat(response.getCookie("ata_session")).isNull();
    }

    @Test
    void aLogoutRequestNamingTheSignedInMemberEndsTheSessionWhateverItsSessionIndex() {
        MockHttpSession session = signedIn("member-a", "session-a");

        MockHttpServletResponse response = send(logoutRequest("member-a", "another-session"), session);

        assertThat(response.getStatus()).isEqualTo(302);
        assertThat(answeredStatus(response)).isEqualTo(SUCCESS);
        assertThat(session.isInvalid()).isTrue();
        assertThat(response.getCookie("ata_session").getMaxAge()).isZero();
    }

    @Test
    void anExpiredLogoutRequestKeepsTheSessionAndIsAnsweredRequester() {
        MockHttpSession session = signedIn("member-a", "session-a");

        MockHttpServletResponse response = send(
                logoutRequest(
                        "member-a", "session-a", notOnOrAfter(Instant.now().minus(1, ChronoUnit.MINUTES))),
                session);

        assertThat(response.getStatus()).isEqualTo(302);
        assertThat(answeredStatus(response)).isEqualTo(REQUESTER);
        assertThat(session.isInvalid()).isFalse();
        assertThat(response.getCookie("ata_session")).isNull();
    }

    @Test
    void aLogoutRequestStillWithinItsNotOnOrAfterEndsTheSession() {
        MockHttpSession session = signedIn("member-a", "session-a");

        MockHttpServletResponse response = send(
                logoutRequest(
                        "member-a", "session-a", notOnOrAfter(Instant.now().plus(5, ChronoUnit.MINUTES))),
                session);

        assertThat(answeredStatus(response)).isEqualTo(SUCCESS);
        assertThat(session.isInvalid()).isTrue();
    }

    @Test
    void aLogoutRequestWithNoSessionIsAnsweredSuccess() {
        MockHttpServletResponse response = send(logoutRequest("member-b", "session-b"), null);

        assertThat(response.getStatus()).isEqualTo(302);
        assertThat(answeredStatus(response)).isEqualTo(SUCCESS);
    }

    // -- Helpers ----------------------------------------------------------------------------------------

    private static MockHttpServletResponse send(MockHttpServletRequest request, MockHttpSession session) {
        if (session != null) {
            request.setSession(session);
        }
        MockHttpServletResponse response = new MockHttpServletResponse();
        new WebApplicationContextRunner()
                .withUserConfiguration(Context.class, SecurityConfig.class)
                .withPropertyValues("trusted-attestation.iam.protocol=SAML")
                .withBean(TrustedAttestationProperties.class, SamlProviderLogoutTest::properties)
                .run(context ->
                        context.getBean(FilterChainProxy.class).doFilter(request, response, (passed, answered) -> {}));
        return response;
    }

    /** A session holding a SAML sign-in, in the shape SessionContents leaves it. */
    private static MockHttpSession signedIn(String nameId, String sessionIndex) {
        DefaultSaml2AuthenticatedPrincipal principal =
                new DefaultSaml2AuthenticatedPrincipal(nameId, Map.of(), List.of(sessionIndex));
        principal.setRelyingPartyRegistrationId("keycloak");
        Saml2AssertionAuthentication authentication = new Saml2AssertionAuthentication(
                principal,
                Saml2ResponseAssertion.withResponseValue("")
                        .nameId(nameId)
                        .sessionIndexes(List.of(sessionIndex))
                        .build(),
                List.of(),
                "keycloak");
        MockHttpSession session = new MockHttpSession();
        session.setAttribute(
                HttpSessionSecurityContextRepository.SPRING_SECURITY_CONTEXT_KEY,
                new SecurityContextImpl(authentication));
        return session;
    }

    /** A logout request over the HTTP-Redirect binding, signed over the query string with the provider's key. */
    private static MockHttpServletRequest logoutRequest(String nameId, String sessionIndex) {
        return logoutRequest(nameId, sessionIndex, "");
    }

    /** The same, with {@code attributes} added to the LogoutRequest element. */
    private static MockHttpServletRequest logoutRequest(String nameId, String sessionIndex, String attributes) {
        String xml = "<samlp:LogoutRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\""
                + " xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\""
                + " ID=\"_" + UUID.randomUUID() + "\" Version=\"2.0\""
                + " IssueInstant=\"" + Instant.now().truncatedTo(ChronoUnit.SECONDS) + "\""
                + " Destination=\"" + OWN_SINGLE_LOGOUT + "\"" + attributes + ">"
                + "<saml:Issuer>" + PROVIDER + "</saml:Issuer>"
                + "<saml:NameID>" + nameId + "</saml:NameID>"
                + "<samlp:SessionIndex>" + sessionIndex + "</samlp:SessionIndex>"
                + "</samlp:LogoutRequest>";
        String encoded = Base64.getEncoder().encodeToString(deflate(xml));
        String query = "SAMLRequest=" + urlEncode(encoded) + "&SigAlg=" + urlEncode(SIGNATURE_ALGORITHM);
        String signature = Base64.getEncoder().encodeToString(sign(query));

        MockHttpServletRequest request =
                new MockHttpServletRequest("GET", SamlRequestParameterScope.SINGLE_LOGOUT_PATH);
        request.setQueryString(query + "&Signature=" + urlEncode(signature));
        request.setParameter("SAMLRequest", encoded);
        request.setParameter("SigAlg", SIGNATURE_ALGORITHM);
        request.setParameter("Signature", signature);
        return request;
    }

    private static String notOnOrAfter(Instant instant) {
        return " NotOnOrAfter=\"" + instant.truncatedTo(ChronoUnit.SECONDS) + "\"";
    }

    /** The status the logout response sent back to the provider carries. */
    private static String answeredStatus(MockHttpServletResponse response) {
        String location = response.getRedirectedUrl();
        assertThat(location)
                .startsWith(PROVIDER_SINGLE_LOGOUT + "?SAMLResponse=")
                .contains("&Signature=");
        String encoded = location.substring(location.indexOf("SAMLResponse=") + "SAMLResponse=".length());
        encoded = encoded.substring(0, encoded.indexOf('&'));
        String xml = inflate(Base64.getDecoder().decode(URLDecoder.decode(encoded, StandardCharsets.UTF_8)));
        String marker = "StatusCode Value=\"";
        int start = xml.indexOf(marker) + marker.length();
        return xml.substring(start, xml.indexOf('"', start));
    }

    private static TrustedAttestationProperties properties() {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getIam().setProtocol(Protocol.SAML);
        return properties;
    }

    private static byte[] deflate(String xml) {
        return transform(stream -> {
            try (DeflaterOutputStream deflater =
                    new DeflaterOutputStream(stream, new Deflater(Deflater.DEFAULT_COMPRESSION, true))) {
                deflater.write(xml.getBytes(StandardCharsets.UTF_8));
            } catch (java.io.IOException exception) {
                throw new IllegalStateException(exception);
            }
        });
    }

    private static String inflate(byte[] deflated) {
        return new String(
                transform(stream -> {
                    try (InflaterOutputStream inflater = new InflaterOutputStream(stream, new Inflater(true))) {
                        inflater.write(deflated);
                    } catch (java.io.IOException exception) {
                        throw new IllegalStateException(exception);
                    }
                }),
                StandardCharsets.UTF_8);
    }

    private static byte[] transform(Consumer<ByteArrayOutputStream> writer) {
        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        writer.accept(stream);
        return stream.toByteArray();
    }

    private static String urlEncode(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8);
    }

    private static byte[] sign(String content) {
        try {
            Signature signature = Signature.getInstance("SHA256withRSA");
            signature.initSign(PROVIDER_KEY.getPrivate());
            signature.update(content.getBytes(StandardCharsets.UTF_8));
            return signature.sign();
        } catch (java.security.GeneralSecurityException exception) {
            throw new IllegalStateException(exception);
        }
    }

    private static KeyPair keyPair() {
        try {
            KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
            generator.initialize(2048);
            return generator.generateKeyPair();
        } catch (java.security.GeneralSecurityException exception) {
            throw new IllegalStateException(exception);
        }
    }

    private static X509Certificate certificate(String commonName, KeyPair keys) {
        try {
            X500Name name = new X500Name("CN=" + commonName);
            Instant now = Instant.now();
            return new JcaX509CertificateConverter()
                    .getCertificate(new JcaX509v3CertificateBuilder(
                                    name,
                                    BigInteger.ONE,
                                    Date.from(now.minus(1, ChronoUnit.DAYS)),
                                    Date.from(now.plus(1, ChronoUnit.DAYS)),
                                    name,
                                    keys.getPublic())
                            .build(new JcaContentSignerBuilder("SHA256withRSA").build(keys.getPrivate())));
        } catch (Exception exception) {
            throw new IllegalStateException(exception);
        }
    }

    @Configuration
    @EnableWebMvc
    static class Context {

        @Bean
        MemberIdentityResolver identities(TrustedAttestationProperties properties) {
            return new MemberIdentityResolver(properties);
        }

        @Bean
        RelyingPartyRegistrationRepository relyingParties() {
            return new InMemoryRelyingPartyRegistrationRepository(RelyingPartyRegistration.withRegistrationId(
                            "keycloak")
                    .entityId("https://sp.example/saml2/service-provider-metadata/keycloak")
                    .signingX509Credentials(credentials ->
                            credentials.add(Saml2X509Credential.signing(OWN_KEY.getPrivate(), OWN_CERTIFICATE)))
                    .singleLogoutServiceLocation("{baseUrl}" + SamlRequestParameterScope.SINGLE_LOGOUT_PATH)
                    .singleLogoutServiceResponseLocation("{baseUrl}" + SamlRequestParameterScope.SINGLE_LOGOUT_PATH)
                    // The Redirect binding, which carries its signature over the query string, so the test
                    // signs it without an XML signature. The NameID is compared the same way on either binding.
                    .singleLogoutServiceBinding(Saml2MessageBinding.REDIRECT)
                    .assertingPartyMetadata(party -> party.entityId(PROVIDER)
                            .singleSignOnServiceLocation("https://idp.example/sso")
                            .singleLogoutServiceLocation(PROVIDER_SINGLE_LOGOUT)
                            .singleLogoutServiceResponseLocation(PROVIDER_SINGLE_LOGOUT)
                            .singleLogoutServiceBinding(Saml2MessageBinding.REDIRECT)
                            .verificationX509Credentials(credentials ->
                                    credentials.add(Saml2X509Credential.verification(PROVIDER_CERTIFICATE))))
                    .build());
        }
    }
}
