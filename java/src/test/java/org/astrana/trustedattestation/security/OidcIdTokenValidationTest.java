package org.astrana.trustedattestation.security;

import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.nimbusds.jose.JWSAlgorithm;
import com.nimbusds.jose.JWSHeader;
import com.nimbusds.jose.crypto.RSASSASigner;
import com.nimbusds.jose.jwk.JWKSet;
import com.nimbusds.jose.jwk.RSAKey;
import com.nimbusds.jwt.JWTClaimsSet;
import com.nimbusds.jwt.SignedJWT;
import com.sun.net.httpserver.HttpServer;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.PrivateKey;
import java.security.interfaces.RSAPublicKey;
import java.time.Instant;
import java.util.Date;
import java.util.function.Consumer;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;
import org.springframework.security.oauth2.client.oidc.authentication.OidcIdTokenDecoderFactory;
import org.springframework.security.oauth2.client.registration.ClientRegistration;
import org.springframework.security.oauth2.core.AuthorizationGrantType;
import org.springframework.security.oauth2.jwt.JwtDecoder;
import org.springframework.security.oauth2.jwt.JwtException;

/**
 * What the relying party accepts and refuses when the IdP hands it an ID token.
 *
 * <p>The conformance suite drives a real login against the dev Keycloak, so it only ever sees the valid
 * tokens that provider mints. The tokens that matter for security are the ones an attacker sends -- for
 * another audience, expired, signed with a key this client does not trust -- and a real IdP will not
 * produce those. This exercises them against a mock: a signing key the test owns, its public half served
 * as a JWK set from a throwaway HTTP server, and tokens it forges.
 *
 * <p>The validation is Spring Security's, configured the way this application configures it, issuer and
 * client id, nothing custom. So this test builds the very decoder Spring builds for an ID token
 * ({@link OidcIdTokenDecoderFactory}) from a registration shaped like this server's, and confirms the
 * security contract holds through it: a genuine provider is trusted, an impostor is not.
 */
class OidcIdTokenValidationTest {

    private static final String ISSUER = "https://idp.example/realms/trusted-attestation";
    private static final String CLIENT_ID = "trusted-attestation";
    private static final String KID = "test-signing-key";

    private static KeyPair keyPair;
    private static HttpServer jwksServer;
    private static String jwkSetUri;

    @BeforeAll
    static void publishSigningKey() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
        generator.initialize(2048);
        keyPair = generator.generateKeyPair();

        // The public half, as the JWK set a provider would publish, served from a throwaway local server
        // so the decoder fetches it exactly as it would fetch a real provider's keys.
        String jwks = new JWKSet(new RSAKey.Builder((RSAPublicKey) keyPair.getPublic())
                        .keyID(KID)
                        .build())
                .toString();

        jwksServer = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        jwksServer.createContext("/jwks", exchange -> {
            byte[] body = jwks.getBytes(StandardCharsets.UTF_8);
            exchange.getResponseHeaders().add("Content-Type", "application/json");
            exchange.sendResponseHeaders(200, body.length);
            exchange.getResponseBody().write(body);
            exchange.close();
        });
        jwksServer.start();

        jwkSetUri = "http://127.0.0.1:" + jwksServer.getAddress().getPort() + "/jwks";
    }

    @AfterAll
    static void stopServer() {
        jwksServer.stop(0);
    }

    // ------------------------------------------------------------------------------------------------
    // Accepted
    // ------------------------------------------------------------------------------------------------

    @Test
    void aCorrectlySignedCurrentTokenForThisClientIsAccepted() {
        assertThatCode(() -> decoder().decode(token(expiringAt(Instant.now().plusSeconds(300)))))
                .doesNotThrowAnyException();
    }

    @Test
    void anAudienceArrayThatIncludesThisClientIsAccepted() {
        // With more than one audience, OpenID Connect requires an azp (authorized party) claim naming
        // this client -- Spring enforces it, so a genuine multi-audience token carries one.
        assertThatCode(() -> decoder()
                        .decode(token(
                                current(claims -> claims.audience(java.util.List.of("some-other-client", CLIENT_ID))
                                        .claim("azp", CLIENT_ID)))))
                .doesNotThrowAnyException();
    }

    @Test
    void aMultiAudienceTokenWithoutAnAuthorizedPartyIsRefused() {
        // The other half of the same rule: several audiences and no azp is not a valid ID token.
        assertThatThrownBy(() -> decoder()
                        .decode(token(
                                current(claims -> claims.audience(java.util.List.of("some-other-client", CLIENT_ID))))))
                .isInstanceOf(JwtException.class);
    }

    @Test
    void aTokenExpiredWithinTheClockSkewAllowanceIsAccepted() {
        // 30 seconds past expiry, inside the 60-second leeway Spring's validator allows by default --
        // the same tolerance the other two implementations use.
        assertThatCode(() -> decoder().decode(token(expiringAt(Instant.now().minusSeconds(30)))))
                .doesNotThrowAnyException();
    }

    // ------------------------------------------------------------------------------------------------
    // Refused
    // ------------------------------------------------------------------------------------------------

    @Test
    void aTokenFromAnotherIssuerIsRefused() {
        assertThatThrownBy(() ->
                        decoder().decode(token(current(claims -> claims.issuer("https://evil.example/realms/x")))))
                .isInstanceOf(JwtException.class);
    }

    @Test
    void aTokenForAnotherAudienceIsRefused() {
        assertThatThrownBy(() -> decoder().decode(token(current(claims -> claims.audience("a-different-client")))))
                .isInstanceOf(JwtException.class);
    }

    @Test
    void aTokenExpiredBeyondTheClockSkewAllowanceIsRefused() {
        // Two minutes past expiry, well beyond the leeway.
        assertThatThrownBy(() -> decoder().decode(token(expiringAt(Instant.now().minusSeconds(120)))))
                .isInstanceOf(JwtException.class);
    }

    @Test
    void aTokenSignedWithAKeyTheProviderDoesNotPublishIsRefused() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
        generator.initialize(2048);
        PrivateKey forgedKey = generator.generateKeyPair().getPrivate();

        String forged = sign(expiringAt(Instant.now().plusSeconds(300)).build(), forgedKey);

        assertThatThrownBy(() -> decoder().decode(forged)).isInstanceOf(JwtException.class);
    }

    // ------------------------------------------------------------------------------------------------
    // The decoder Spring builds for an ID token, and forged tokens for it.
    // ------------------------------------------------------------------------------------------------

    private JwtDecoder decoder() {
        ClientRegistration registration = ClientRegistration.withRegistrationId("keycloak")
                .clientId(CLIENT_ID)
                .clientSecret("the-client-secret")
                .authorizationGrantType(AuthorizationGrantType.AUTHORIZATION_CODE)
                .redirectUri("https://app.example/login/oauth2/code/keycloak")
                .scope("openid")
                .issuerUri(ISSUER)
                .authorizationUri(ISSUER + "/auth")
                .tokenUri(ISSUER + "/token")
                .jwkSetUri(jwkSetUri)
                .build();

        return new OidcIdTokenDecoderFactory().createDecoder(registration);
    }

    /** A claim set that is valid in every respect, before an override is applied. */
    private static JWTClaimsSet.Builder current(Consumer<JWTClaimsSet.Builder> override) {
        JWTClaimsSet.Builder claims = expiringAt(Instant.now().plusSeconds(300));
        override.accept(claims);
        return claims;
    }

    /**
     * A claim set expiring at the given instant, issued five minutes before it -- so an "expired" token
     * is one issued before it expired, refused (if at all) on the clock-skew boundary rather than because
     * its issue time follows its expiry.
     */
    private static JWTClaimsSet.Builder expiringAt(Instant expiry) {
        return new JWTClaimsSet.Builder()
                .issuer(ISSUER)
                .audience(CLIENT_ID)
                .subject("member-1")
                .issueTime(Date.from(expiry.minusSeconds(300)))
                .notBeforeTime(Date.from(expiry.minusSeconds(300)))
                .expirationTime(Date.from(expiry));
    }

    private String token(JWTClaimsSet.Builder claims) throws Exception {
        return sign(claims.build(), keyPair.getPrivate());
    }

    private static String sign(JWTClaimsSet claims, PrivateKey key) throws Exception {
        SignedJWT jwt = new SignedJWT(
                new JWSHeader.Builder(JWSAlgorithm.RS256).keyID(KID).build(), claims);
        jwt.sign(new RSASSASigner(key));

        return jwt.serialize();
    }
}
