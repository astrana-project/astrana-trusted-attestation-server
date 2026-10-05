package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.ArgumentMatchers.isNull;
import static org.mockito.Mockito.verifyNoInteractions;
import static org.mockito.Mockito.when;

import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.Arrays;
import java.util.Base64;
import java.util.List;
import java.util.Optional;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.security.MemberIdentity;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.service.RelationshipService;
import org.astrana.trustedattestation.service.RelationshipService.SetKeyResult;
import org.astrana.trustedattestation.web.ApiContracts.AttestResponse;
import org.astrana.trustedattestation.web.ApiContracts.MeResponse;
import org.astrana.trustedattestation.web.ApiContracts.SetKeyResponse;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.security.core.Authentication;

/**
 * How each of the four operations maps a service result to an HTTP answer, and how the request body is
 * read -- both isolated from the service and the database.
 *
 * <p>The conformance suite drives these end to end; this pins the controller's own decisions: which
 * result becomes 401 / 400 / 404 / 409 / 200, the body reading that lets a public_key which is a number,
 * an object or an array reach the handler as "no key" rather than a framework 400 (each framework's
 * default binding reads that case differently), and the byte-level rules every implementation applies: a byte
 * order mark, trailing tokens and invalid UTF-8 are a malformed body, the body is read whatever its
 * Content-Type, and a body over 64 kilobytes is refused with 413.
 */
@ExtendWith(MockitoExtension.class)
class ApiControllerTest {

    private static final Instant NOW = Instant.parse("2026-01-01T12:00:00Z");
    private static final byte[] RAW_KEY = rawKey();
    private static final String VALID_BODY =
            "{\"public_key\":\"" + Base64.getEncoder().encodeToString(RAW_KEY) + "\"}";

    @Mock
    private RelationshipService relationships;

    @Mock
    private MemberIdentityResolver resolver;

    @Mock
    private Authentication authentication;

    private ApiController controller;

    @BeforeEach
    void setUp() {
        controller = new ApiController(relationships, resolver, Clock.fixed(NOW, ZoneOffset.UTC));
    }

    private static byte[] rawKey() {
        byte[] key = new byte[32];
        key[0] = 7; // non-zero: the all-zero key is refused by PublicKeys.parse
        return key;
    }

    /** A request carrying these bytes as its body, declared as JSON unless a test says otherwise. */
    private static MockHttpServletRequest body(byte[] content, String contentType) {
        MockHttpServletRequest request = new MockHttpServletRequest("PUT", "/api/v1/me/relationships/employee/key");
        request.setContent(content);
        if (contentType != null) {
            request.setContentType(contentType);
        }
        return request;
    }

    private static MockHttpServletRequest body(String content) {
        return body(content == null ? null : content.getBytes(StandardCharsets.UTF_8), "application/json");
    }

    private ResponseEntity<SetKeyResponse> setKey(String content) {
        return controller.setKey(authentication, "employee", body(content));
    }

    private ResponseEntity<AttestResponse> attest(String content) {
        return controller.attest(body(content));
    }

    // -- GET /me -------------------------------------------------------------------------------------

    @Test
    void getMeWithoutASessionIsUnauthorized() {
        when(resolver.resolve(authentication)).thenReturn(Optional.empty());

        assertThat(controller.getMe(authentication).getStatusCode()).isEqualTo(HttpStatus.UNAUTHORIZED);
        verifyNoInteractions(relationships);
    }

    @Test
    void getMeReturnsTheMembersNameAndTheRelationshipsTheyHold() {
        when(resolver.resolve(authentication)).thenReturn(Optional.of(new MemberIdentity("alice", "Alice Anderson")));
        when(relationships.findAllHeldBy("alice")).thenReturn(List.of(new MemberRelationship("alice", "employee")));

        ResponseEntity<MeResponse> response = controller.getMe(authentication);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().name()).isEqualTo("Alice Anderson");
        assertThat(response.getBody().relationships()).hasSize(1);
    }

    @Test
    void getMeForAMemberHoldingNothingIsAnOkEmptyList() {
        when(resolver.resolve(authentication)).thenReturn(Optional.of(new MemberIdentity("dave", "Dave")));
        when(relationships.findAllHeldBy("dave")).thenReturn(List.of());

        ResponseEntity<MeResponse> response = controller.getMe(authentication);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().relationships()).isEmpty();
    }

    // -- PUT .../key ---------------------------------------------------------------------------------

    @Test
    void setKeyWithoutASessionIsUnauthorized() {
        when(resolver.subject(authentication)).thenReturn(Optional.empty());

        assertThat(setKey(VALID_BODY).getStatusCode()).isEqualTo(HttpStatus.UNAUTHORIZED);
        verifyNoInteractions(relationships);
    }

    @Test
    void setKeyWithAMalformedKeyIsBadRequestAndTouchesNoData() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));

        assertThat(setKey("{\"public_key\":\"too-short\"}").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
        verifyNoInteractions(relationships);
    }

    @Test
    void setKeyWithANonStringPublicKeyIsBadRequest() {
        // A number where a string was expected reaches the handler as "no key", answered 400 here rather
        // than by the JSON layer.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));

        assertThat(setKey("{\"public_key\": 1234}").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
    }

    @Test
    void setKeyOnARelationshipNotGrantedIsNotFound() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), any())).thenReturn(SetKeyResult.NOT_GRANTED);

        assertThat(setKey(VALID_BODY).getStatusCode()).isEqualTo(HttpStatus.NOT_FOUND);
    }

    @Test
    void setKeyWithAConflictingKeyIsConflict() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), any())).thenReturn(SetKeyResult.CONFLICT);

        assertThat(setKey(VALID_BODY).getStatusCode()).isEqualTo(HttpStatus.CONFLICT);
    }

    @Test
    void setKeySavedRereadsTheRowAndReturnsItsStatus() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), any())).thenReturn(SetKeyResult.SAVED);
        // The re-read row carries the key that was just saved, which the response echoes back.
        MemberRelationship saved = new MemberRelationship("alice", "employee");
        saved.setPublicKey(RAW_KEY);
        when(relationships.find("alice", "employee")).thenReturn(Optional.of(saved));

        ResponseEntity<SetKeyResponse> response = setKey(VALID_BODY);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().publicKey()).isEqualTo(Base64.getEncoder().encodeToString(RAW_KEY));
    }

    @Test
    void setKeyWithAnEmptyStringClearsTheKeyAndReportsUnkeyed() {
        // A present but empty public_key reaches the service as a null key -- the clear path. The re-read
        // row carries no key, so the response reports a null key and an unkeyed standing.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), isNull())).thenReturn(SetKeyResult.SAVED);
        when(relationships.find("alice", "employee"))
                .thenReturn(Optional.of(new MemberRelationship("alice", "employee")));

        ResponseEntity<SetKeyResponse> response = setKey("{\"public_key\":\"\"}");

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().publicKey()).isNull();
        assertThat(response.getBody().status()).isEqualTo("unkeyed");
    }

    @ParameterizedTest
    @ValueSource(strings = {"   ", " \\t\\r\\n "})
    void setKeyWithOnlySpacesTabsAndLineBreaksIsTreatedAsAClearNotABadRequest(String blank) {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), isNull())).thenReturn(SetKeyResult.SAVED);
        when(relationships.find("alice", "employee"))
                .thenReturn(Optional.of(new MemberRelationship("alice", "employee")));

        ResponseEntity<SetKeyResponse> response = setKey("{\"public_key\":\"" + blank + "\"}");

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().publicKey()).isNull();
    }

    @ParameterizedTest
    @ValueSource(strings = {"\\u00a0", "\\f", " \\f "})
    void setKeyWithAnyOtherWhitespaceIsBadRequestNotAClear(String other) {
        // A non-breaking space or a form feed is not one of the four characters that clear a key, so it
        // is a malformed key, the same answer all three implementations give.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));

        assertThat(setKey("{\"public_key\":\"" + other + "\"}").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
        verifyNoInteractions(relationships);
    }

    @Test
    void setKeyWithNoPublicKeyFieldIsBadRequestNotASilentClear() {
        // An absent public_key comes back null from the reader, indistinguishable from a wrong type: a
        // malformed request, answered 400 without touching the service. Only an explicit empty string
        // clears.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));

        assertThat(setKey("{}").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
        verifyNoInteractions(relationships);
    }

    // -- The body, byte for byte -----------------------------------------------------------------------

    @Test
    void aByteOrderMarkMakesTheBodyMalformed() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        byte[] withBom =
                concat(new byte[] {(byte) 0xEF, (byte) 0xBB, (byte) 0xBF}, VALID_BODY.getBytes(StandardCharsets.UTF_8));

        assertThat(controller
                        .setKey(authentication, "employee", body(withBom, "application/json"))
                        .getStatusCode())
                .isEqualTo(HttpStatus.BAD_REQUEST);
        assertThat(controller.attest(body(withBom, "application/json")).getBody())
                .isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void trailingTokensAfterTheJsonValueMakeTheBodyMalformed() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));

        assertThat(setKey(VALID_BODY + " {}").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
        assertThat(setKey(VALID_BODY + "garbage").getStatusCode()).isEqualTo(HttpStatus.BAD_REQUEST);
        assertThat(attest(VALID_BODY + " {}").getBody()).isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void invalidUtf8MakesTheBodyMalformed() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        // A lone continuation byte inside the string: not text in any encoding the contract allows.
        byte[] invalid = concat(
                "{\"public_key\":\"".getBytes(StandardCharsets.UTF_8),
                new byte[] {(byte) 0xFF},
                "\"}".getBytes(StandardCharsets.UTF_8));

        assertThat(controller
                        .setKey(authentication, "employee", body(invalid, "application/json"))
                        .getStatusCode())
                .isEqualTo(HttpStatus.BAD_REQUEST);
        assertThat(controller.attest(body(invalid, "application/json")).getBody())
                .isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void theBodyIsReadWhateverItsContentType() {
        // A form, text or no declared type at all: the bytes are the same JSON, and are read as such.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.setKey(eq("alice"), eq("employee"), any())).thenReturn(SetKeyResult.NOT_GRANTED);
        byte[] bytes = VALID_BODY.getBytes(StandardCharsets.UTF_8);

        for (String contentType : Arrays.asList("application/x-www-form-urlencoded", "text/plain", null)) {
            assertThat(controller
                            .setKey(authentication, "employee", body(bytes, contentType))
                            .getStatusCode())
                    .as("content type %s", contentType)
                    .isEqualTo(HttpStatus.NOT_FOUND);
        }
    }

    @Test
    void aBodyOverSixtyFourKilobytesIsContentTooLargeWithNoBody() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        byte[] oversized = new byte[ApiController.MAX_BODY_BYTES + 1];
        Arrays.fill(oversized, (byte) ' ');

        ResponseEntity<SetKeyResponse> put =
                controller.setKey(authentication, "employee", body(oversized, "application/json"));
        ResponseEntity<AttestResponse> attest = controller.attest(body(oversized, "application/json"));

        assertThat(put.getStatusCode().value()).isEqualTo(413);
        assertThat(put.getBody()).isNull();
        assertThat(attest.getStatusCode().value()).isEqualTo(413);
        assertThat(attest.getBody()).isNull();
        verifyNoInteractions(relationships);
    }

    @Test
    void aBodyOfExactlySixtyFourKilobytesIsRead() {
        // The cap is inclusive: a body of the cap's size is a body, and this one is merely malformed.
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        byte[] atTheCap = new byte[ApiController.MAX_BODY_BYTES];
        Arrays.fill(atTheCap, (byte) '0');

        assertThat(controller
                        .setKey(authentication, "employee", body(atTheCap, "application/json"))
                        .getStatusCode())
                .isEqualTo(HttpStatus.BAD_REQUEST);
    }

    @Test
    void aDeclaredLengthOverTheCapIsRefusedBeforeTheBodyIsRead() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        // A request that declares more than the cap but whose stream holds a valid body: the declaration
        // alone refuses it, so the stream is never read.
        MockHttpServletRequest request = new MockHttpServletRequest("PUT", "/api/v1/me/relationships/employee/key") {
            @Override
            public long getContentLengthLong() {
                return ApiController.MAX_BODY_BYTES + 1L;
            }
        };
        request.setContent(VALID_BODY.getBytes(StandardCharsets.UTF_8));

        assertThat(controller
                        .setKey(authentication, "employee", request)
                        .getStatusCode()
                        .value())
                .isEqualTo(413);
    }

    // -- DELETE .../key ------------------------------------------------------------------------------

    @Test
    void deleteWithoutASessionIsUnauthorized() {
        when(resolver.subject(authentication)).thenReturn(Optional.empty());

        assertThat(controller.delete(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.UNAUTHORIZED);
    }

    @Test
    void deletingAHeldRelationshipIsNoContent() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.delete("alice", "employee")).thenReturn(true);

        assertThat(controller.delete(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.NO_CONTENT);
    }

    @Test
    void deletingARelationshipNotHeldIsNotFound() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.delete("alice", "employee")).thenReturn(false);

        assertThat(controller.delete(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.NOT_FOUND);
    }

    // -- POST .../revoke -----------------------------------------------------------------------------

    @Test
    void selfRevokeWithoutASessionIsUnauthorized() {
        when(resolver.subject(authentication)).thenReturn(Optional.empty());

        assertThat(controller.selfRevoke(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.UNAUTHORIZED);
    }

    @Test
    void selfRevokingAHeldRelationshipIsNoContent() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.selfRevoke("alice", "employee")).thenReturn(true);

        assertThat(controller.selfRevoke(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.NO_CONTENT);
    }

    @Test
    void selfRevokingARelationshipNotHeldIsNotFound() {
        when(resolver.subject(authentication)).thenReturn(Optional.of("alice"));
        when(relationships.selfRevoke("alice", "employee")).thenReturn(false);

        assertThat(controller.selfRevoke(authentication, "employee").getStatusCode())
                .isEqualTo(HttpStatus.NOT_FOUND);
    }

    // -- POST /attest --------------------------------------------------------------------------------

    @Test
    void attestWithAMalformedKeyIsOkAndInvalid() {
        ResponseEntity<AttestResponse> response = attest("{\"public_key\":\"not-a-key\"}");

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody()).isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void attestWithAValidKeyNotOnRecordIsOkAndInvalid() {
        when(relationships.attest(any())).thenReturn(Optional.empty());

        ResponseEntity<AttestResponse> response = attest(VALID_BODY);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody()).isEqualTo(AttestResponse.INVALID);
    }

    @Test
    void attestWithAKeyOnRecordReturnsItsStanding() {
        when(relationships.attest(any())).thenReturn(Optional.of(new MemberRelationship("alice", "employee")));

        ResponseEntity<AttestResponse> response = attest(VALID_BODY);

        assertThat(response.getStatusCode()).isEqualTo(HttpStatus.OK);
        assertThat(response.getBody().valid()).isTrue();
    }

    @Test
    void attestWithABodyThatIsNotJsonIsOkAndInvalid() {
        ResponseEntity<AttestResponse> response = attest("this is not json");

        assertThat(response.getBody()).isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void attestWithAPublicKeyThatIsAnArrayIsOkAndInvalid() {
        // A shape each framework binds differently by default: an array (or object) public_key is read
        // as no key, answered a flat invalid rather than a 400, so it cannot be told apart from an unknown
        // key.
        ResponseEntity<AttestResponse> response = attest("{\"public_key\":[\"a\",\"b\"]}");

        assertThat(response.getBody()).isEqualTo(AttestResponse.INVALID);
        verifyNoInteractions(relationships);
    }

    @Test
    void attestWithAnEmptyBodyIsOkAndInvalid() {
        assertThat(attest("").getBody()).isEqualTo(AttestResponse.INVALID);
        assertThat(attest(null).getBody()).isEqualTo(AttestResponse.INVALID);
    }

    @Test
    void attestReadsTheBodyWhateverItsContentType() {
        when(relationships.attest(any())).thenReturn(Optional.empty());

        assertThat(controller
                        .attest(body(VALID_BODY.getBytes(StandardCharsets.UTF_8), "application/x-www-form-urlencoded"))
                        .getBody())
                .isEqualTo(AttestResponse.INVALID);
    }

    private static byte[] concat(byte[]... parts) {
        int length = Arrays.stream(parts).mapToInt(part -> part.length).sum();
        byte[] joined = new byte[length];
        int offset = 0;
        for (byte[] part : parts) {
            System.arraycopy(part, 0, joined, offset, part.length);
            offset += part.length;
        }
        return joined;
    }
}
