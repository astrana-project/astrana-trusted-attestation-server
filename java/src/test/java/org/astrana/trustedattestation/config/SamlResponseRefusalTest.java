package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.ByteArrayInputStream;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.security.KeyPair;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.time.temporal.ChronoUnit;
import java.util.Base64;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;
import java.util.function.UnaryOperator;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import net.shibboleth.shared.xml.SerializeSupport;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Protocol;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.junit.jupiter.api.Test;
import org.opensaml.core.xml.config.XMLObjectProviderRegistrySupport;
import org.opensaml.core.xml.util.XMLObjectSupport;
import org.opensaml.saml.saml2.core.Response;
import org.opensaml.security.x509.BasicX509Credential;
import org.opensaml.xmlsec.SignatureSigningParameters;
import org.opensaml.xmlsec.signature.support.SignatureConstants;
import org.opensaml.xmlsec.signature.support.SignatureSupport;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.security.saml2.core.OpenSamlInitializationService;
import org.springframework.security.saml2.core.Saml2X509Credential;
import org.springframework.security.saml2.provider.service.registration.InMemoryRelyingPartyRegistrationRepository;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistration;
import org.springframework.security.saml2.provider.service.registration.RelyingPartyRegistrationRepository;
import org.springframework.security.web.FilterChainProxy;
import org.springframework.web.servlet.config.annotation.EnableWebMvc;

/**
 * Which SAML responses the sign-in refuses, through the real security filter chain.
 *
 * <p>Spring Security accepts a response that answers no request, which is how it supports a sign-in started
 * at the identity provider, and OpenSAML accepts a signature made with SHA-1. This server refuses both. Each
 * test starts a sign-in to get a real request to answer, then posts a response signed with the identity
 * provider's key. A response the server takes signs the member in and lands on {@code /me}. One it refuses
 * lands on {@code /me?error=login}.
 */
class SamlResponseRefusalTest {

    private static final String PROVIDER = "https://idp.example";

    private static final String SERVICE_PROVIDER = "https://sp.example/saml2/service-provider-metadata/keycloak";

    /** Where the response is posted. A mock request's base address is http://localhost. */
    private static final String CONSUMER = "http://localhost/login/saml2/sso/keycloak";

    private static final String RSA_SHA256 = SignatureConstants.ALGO_ID_SIGNATURE_RSA_SHA256;

    private static final String RSA_SHA1 = SignatureConstants.ALGO_ID_SIGNATURE_RSA_SHA1;

    private static final String SHA256 = SignatureConstants.ALGO_ID_DIGEST_SHA256;

    private static final String SHA1 = SignatureConstants.ALGO_ID_DIGEST_SHA1;

    private static final String SIGNED_IN = "/me";

    private static final String REFUSED = "/me?error=login";

    private static final KeyPair PROVIDER_KEY = TestCredentials.keyPair();

    private static final X509Certificate PROVIDER_CERTIFICATE =
            TestCredentials.certificate("idp.example", PROVIDER_KEY);

    private static final KeyPair OWN_KEY = TestCredentials.keyPair();

    private static final X509Certificate OWN_CERTIFICATE = TestCredentials.certificate("sp.example", OWN_KEY);

    private static final Pattern REQUEST_ID = Pattern.compile("ID=\"([^\"]+)\"");

    @Test
    void aSolicitedResponseSignedWithSha256SignsTheMemberIn() {
        assertThat(signInLandsOn(requestId -> signedResponse(requestId, RSA_SHA256, SHA256)))
                .isEqualTo(SIGNED_IN);
    }

    @Test
    void aResponseThatAnswersNoRequestIsRefused() {
        assertThat(signInLandsOn(requestId -> signedResponse(null, RSA_SHA256, SHA256)))
                .isEqualTo(REFUSED);
    }

    @Test
    void aResponseSignedWithSha1IsRefused() {
        assertThat(signInLandsOn(requestId -> signedResponse(requestId, RSA_SHA1, SHA256)))
                .isEqualTo(REFUSED);
    }

    @Test
    void aSha256SignatureOverASha1DigestIsRefused() {
        assertThat(signInLandsOn(requestId -> signedResponse(requestId, RSA_SHA256, SHA1)))
                .isEqualTo(REFUSED);
    }

    @Test
    void aResponseWithADoctypeIsRefused() {
        // OpenSAML refuses it before the response validator runs, which is why SamlSignatureStrength can
        // report nothing for a response its own parser refuses.
        assertThat(signInLandsOn(requestId -> withDoctype(signedResponse(requestId, RSA_SHA256, SHA256))))
                .isEqualTo(REFUSED);
    }

    /**
     * Starts a sign-in, then posts the response that {@code responseTo} makes for the sign-in's request ID.
     * Returns where the sign-in lands.
     */
    private static String signInLandsOn(UnaryOperator<String> responseTo) {
        AtomicReference<String> landing = new AtomicReference<>();
        new WebApplicationContextRunner()
                .withUserConfiguration(Context.class, SecurityConfig.class)
                .withPropertyValues("trusted-attestation.iam.protocol=SAML")
                .withBean(TrustedAttestationProperties.class, SamlResponseRefusalTest::properties)
                .run(context -> {
                    FilterChainProxy chain = context.getBean(FilterChainProxy.class);

                    MockHttpServletResponse started = new MockHttpServletResponse();
                    chain.doFilter(
                            new MockHttpServletRequest("GET", "/saml2/authenticate/keycloak"),
                            started,
                            (passed, answered) -> {});

                    String response = responseTo.apply(requestId(started.getRedirectedUrl()));
                    MockHttpServletRequest posted = new MockHttpServletRequest("POST", "/login/saml2/sso/keycloak");
                    posted.setParameter(
                            "SAMLResponse",
                            Base64.getEncoder().encodeToString(response.getBytes(StandardCharsets.UTF_8)));
                    posted.setCookies(started.getCookies());
                    MockHttpServletResponse answered = new MockHttpServletResponse();
                    chain.doFilter(posted, answered, (passed, sent) -> {});

                    landing.set(answered.getRedirectedUrl());
                });
        return landing.get();
    }

    /** The document with a DOCTYPE after its XML declaration, which is where a DOCTYPE stands. */
    private static String withDoctype(String xml) {
        int body = xml.startsWith("<?xml") ? xml.indexOf("?>") + 2 : 0;
        return xml.substring(0, body) + "<!DOCTYPE samlp:Response>" + xml.substring(body);
    }

    /** The ID of the authentication request the sign-in sent, read from its redirect to the provider. */
    private static String requestId(String redirect) {
        String encoded = redirect.substring(redirect.indexOf("SAMLRequest=") + "SAMLRequest=".length());
        encoded = URLDecoder.decode(encoded.split("&")[0], StandardCharsets.UTF_8);
        Matcher id = REQUEST_ID.matcher(
                SamlProviderLogoutTest.inflate(Base64.getDecoder().decode(encoded)));
        assertThat(id.find()).as("an ID in the authentication request").isTrue();
        return id.group(1);
    }

    /** A response signing member-a in, answering {@code requestId} when it is not null, signed by the provider. */
    private static String signedResponse(String requestId, String signatureAlgorithm, String digestAlgorithm) {
        Instant now = Instant.now().truncatedTo(ChronoUnit.SECONDS);
        String answers = requestId == null ? "" : " InResponseTo=\"" + requestId + "\"";
        String xml = """
                <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
                    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
                    ID="_%1$s" Version="2.0" IssueInstant="%2$s" Destination="%3$s"%4$s>
                  <saml:Issuer>%5$s</saml:Issuer>
                  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>
                  <saml:Assertion ID="_a%1$s" Version="2.0" IssueInstant="%2$s">
                    <saml:Issuer>%5$s</saml:Issuer>
                    <saml:Subject>
                      <saml:NameID>member-a</saml:NameID>
                      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
                        <saml:SubjectConfirmationData Recipient="%3$s" NotOnOrAfter="%6$s"%4$s/>
                      </saml:SubjectConfirmation>
                    </saml:Subject>
                    <saml:Conditions NotBefore="%7$s" NotOnOrAfter="%6$s">
                      <saml:AudienceRestriction><saml:Audience>%8$s</saml:Audience></saml:AudienceRestriction>
                    </saml:Conditions>
                    <saml:AuthnStatement AuthnInstant="%2$s" SessionIndex="session-a">
                      <saml:AuthnContext>
                        <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
                      </saml:AuthnContext>
                    </saml:AuthnStatement>
                  </saml:Assertion>
                </samlp:Response>
                """.formatted(
                        UUID.randomUUID(),
                        now,
                        CONSUMER,
                        answers,
                        PROVIDER,
                        now.plus(5, ChronoUnit.MINUTES),
                        now.minus(1, ChronoUnit.MINUTES),
                        SERVICE_PROVIDER);
        try {
            OpenSamlInitializationService.initialize();
            Response response = (Response) XMLObjectSupport.unmarshallFromInputStream(
                    XMLObjectProviderRegistrySupport.getParserPool(),
                    new ByteArrayInputStream(xml.getBytes(StandardCharsets.UTF_8)));
            SignatureSigningParameters parameters = new SignatureSigningParameters();
            parameters.setSigningCredential(new BasicX509Credential(PROVIDER_CERTIFICATE, PROVIDER_KEY.getPrivate()));
            parameters.setSignatureAlgorithm(signatureAlgorithm);
            parameters.setSignatureReferenceDigestMethod(digestAlgorithm);
            parameters.setSignatureCanonicalizationAlgorithm(SignatureConstants.ALGO_ID_C14N_EXCL_OMIT_COMMENTS);
            SignatureSupport.signObject(response, parameters);
            return SerializeSupport.nodeToString(response.getDOM());
        } catch (Exception exception) {
            throw new IllegalStateException(exception);
        }
    }

    private static TrustedAttestationProperties properties() {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getIam().setProtocol(Protocol.SAML);
        return properties;
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
                    .entityId(SERVICE_PROVIDER)
                    .signingX509Credentials(credentials ->
                            credentials.add(Saml2X509Credential.signing(OWN_KEY.getPrivate(), OWN_CERTIFICATE)))
                    .assertingPartyMetadata(party -> party.entityId(PROVIDER)
                            .singleSignOnServiceLocation("https://idp.example/sso")
                            .verificationX509Credentials(credentials ->
                                    credentials.add(Saml2X509Credential.verification(PROVIDER_CERTIFICATE))))
                    .build());
        }
    }
}
