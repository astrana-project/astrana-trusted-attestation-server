package org.astrana.trustedattestation.web;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.json.JsonMapper;
import jakarta.servlet.http.HttpServletRequest;
import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.ByteBuffer;
import java.nio.charset.CharacterCodingException;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Instant;
import java.util.List;
import java.util.Optional;
import org.astrana.trustedattestation.data.MemberRelationship;
import org.astrana.trustedattestation.security.MemberIdentity;
import org.astrana.trustedattestation.security.MemberIdentityResolver;
import org.astrana.trustedattestation.security.PublicKeys;
import org.astrana.trustedattestation.service.RelationshipService;
import org.astrana.trustedattestation.service.RelationshipService.SetKeyResult;
import org.astrana.trustedattestation.web.ApiContracts.AttestResponse;
import org.astrana.trustedattestation.web.ApiContracts.MeResponse;
import org.astrana.trustedattestation.web.ApiContracts.RelationshipWithKey;
import org.astrana.trustedattestation.web.ApiContracts.SetKeyResponse;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.DeleteMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.PutMapping;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

/**
 * The four operations, and nothing else.
 *
 * <p>Every path is {@code /me}-scoped: there is no way to address any record but your own. Which member
 * is acted on comes entirely from the authenticated session, so there is no member identifier in any
 * route to tamper with, not an authorisation check layered on top but an absence of the mechanism. The
 * relationship type in the path says which of the caller's own relationships to act on, never whose.
 *
 * <p>Granting is not here. It exists only as a stored procedure the organisation calls, and keeping it out
 * of this file is what stops a member self-granting a relationship nobody vouched for.
 *
 * <p>Mapped under both prefixes. The version is pinned in the route so a breaking change can introduce
 * {@code /api/v2} alongside this without forcing every deployment to upgrade in lockstep, while {@code
 * /api} with no version segment resolves to the latest, so a caller that does not care about pinning
 * never has to track version numbers.
 *
 * <p>Errors are status-code-only, with no body: response bodies are reserved for actual data, not error
 * explanation.
 */
@RestController
@RequestMapping({"/api/v1", "/api"})
public class ApiController {

    /**
     * The most a request body may be, in bytes: 64 kilobytes, the same cap on all three implementations.
     * The two bodies this API takes carry one 44-character key, so anything near the cap is not a request
     * this server can use, and reading it would only spend memory on it.
     */
    static final int MAX_BODY_BYTES = 64 * 1024;

    /** HTTP 413 - Content Too Large, by number: the constant's name has moved between Spring releases. */
    private static final int CONTENT_TOO_LARGE = 413;

    // Reads request bodies by hand (see readPublicKey). Its own instance rather than the application's
    // configured ObjectMapper, because it only ever walks a JSON tree looking for one literal wire key and
    // so needs none of that configuration, and because it must exist whether or not the context publishes
    // an ObjectMapper bean to inject. Trailing tokens after the JSON value make the body malformed, as on
    // the other two implementations, rather than being ignored.
    private static final JsonMapper JSON = JsonMapper.builder()
            .enable(DeserializationFeature.FAIL_ON_TRAILING_TOKENS)
            .build();

    private final RelationshipService relationships;
    private final MemberIdentityResolver resolver;
    private final Clock clock;

    public ApiController(RelationshipService relationships, MemberIdentityResolver resolver, Clock clock) {
        this.relationships = relationships;
        this.resolver = resolver;
        this.clock = clock;
    }

    @GetMapping("/me")
    public ResponseEntity<MeResponse> getMe(Authentication authentication) {
        Optional<MemberIdentity> member = resolver.resolve(authentication);
        if (member.isEmpty()) {
            return unauthorized();
        }

        Instant now = Instant.now(clock);
        List<RelationshipWithKey> held = relationships
                .findAllHeldBy(member.get().iamSubjectId())
                .stream()
                .map(row -> RelationshipWithKey.from(row, now))
                .toList();

        // An empty list is a real answer, not an error. A member the organisation has granted nothing is
        // exactly what everyone looks like before their first grant.
        return ResponseEntity.ok(new MeResponse(member.get().name(), held));
    }

    @PutMapping("/me/relationships/{relationshipType}/key")
    public ResponseEntity<SetKeyResponse> setKey(
            Authentication authentication, @PathVariable String relationshipType, HttpServletRequest request) {

        Optional<String> subject = resolver.subject(authentication);
        if (subject.isEmpty()) {
            return unauthorized();
        }

        byte[] body = readBody(request);
        if (body == null) {
            return ResponseEntity.status(CONTENT_TOO_LARGE).build();
        }

        // The reader (see readPublicKey) turns an absent, JSON-null or wrong-typed public_key, and a body
        // that is not well-formed JSON in valid UTF-8, into a null string. That is a malformed request,
        // answered with a clean 400 and no body, and never a silent clear, because a garbled body must not
        // wipe a key. A present string that is empty or holds only spaces, tabs, carriage returns and line
        // feeds is different. It is the member clearing their key to pause the relationship, and it reaches
        // the service as a null key. Any other string that will not parse is a mistyped key, also 400.
        String raw = readPublicKey(body);
        if (raw == null) {
            return ResponseEntity.badRequest().build();
        }

        byte[] key = null;
        if (!PublicKeys.isClearing(raw)) {
            Optional<byte[]> parsed = PublicKeys.parse(raw);
            if (parsed.isEmpty()) {
                return ResponseEntity.badRequest().build();
            }
            key = parsed.get();
        }

        SetKeyResult result = relationships.setKey(subject.get(), relationshipType, key);

        // Never created. A PUT that could create would let any member self-grant any type they liked,
        // with no organisation involvement at all.
        if (result == SetKeyResult.NOT_GRANTED) {
            return ResponseEntity.notFound().build();
        }

        if (result == SetKeyResult.CONFLICT) {
            return ResponseEntity.status(HttpStatus.CONFLICT).build();
        }

        // Re-read so the status reflects what is actually on the row: setting a key on a revoked
        // relationship succeeds without restoring it, and the member has to be told that.
        return relationships
                .find(subject.get(), relationshipType)
                .map(row -> ResponseEntity.ok(SetKeyResponse.from(row, Instant.now(clock))))
                .orElseGet(() -> ResponseEntity.notFound().build());
    }

    @DeleteMapping("/me/relationships/{relationshipType}/key")
    public ResponseEntity<Void> delete(Authentication authentication, @PathVariable String relationshipType) {
        return resolver.subject(authentication)
                .map(subject -> relationships.delete(subject, relationshipType)
                        ? ResponseEntity.noContent().<Void>build()
                        : ResponseEntity.notFound().<Void>build())
                .orElseGet(() -> ResponseEntity.status(HttpStatus.UNAUTHORIZED).build());
    }

    @PostMapping("/me/relationships/{relationshipType}/revoke")
    public ResponseEntity<Void> selfRevoke(Authentication authentication, @PathVariable String relationshipType) {
        // Idempotent: revoking an already-revoked relationship is 204 too, and writes no second audit
        // entry. 404 means only that the caller does not hold this relationship at all.
        return resolver.subject(authentication)
                .map(subject -> relationships.selfRevoke(subject, relationshipType)
                        ? ResponseEntity.noContent().<Void>build()
                        : ResponseEntity.notFound().<Void>build())
                .orElseGet(() -> ResponseEntity.status(HttpStatus.UNAUTHORIZED).build());
    }

    @PostMapping("/attest")
    public ResponseEntity<AttestResponse> attest(HttpServletRequest request) {
        // POST rather than GET so the key stays out of URLs, and therefore out of access logs, proxy logs
        // and browser history, even though the key itself is not secret.
        //
        // A point lookup only: one specific key in, the one relationship it belongs to out. There is no
        // operation to list or enumerate keys on record, which is what makes anonymous access safe here.
        // /attest answers 200 whatever the body, short of one over the cap: valid or not is the only
        // distinction it draws, and a malformed key is not valid. Read by hand (see readPublicKey) so that
        // a public_key that is a number, an object or an array reaches this handler as "no key" rather
        // than being turned into a 400 by the JSON layer before the handler runs, which keeps the three
        // implementations in step.
        byte[] body = readBody(request);
        if (body == null) {
            return ResponseEntity.status(CONTENT_TOO_LARGE).build();
        }

        Optional<byte[]> key = PublicKeys.parse(readPublicKey(body));

        if (key.isEmpty()) {
            // A malformed key is answered the same way an unknown one is. Distinguishing the two would
            // leak shape information for no benefit.
            return ResponseEntity.ok(AttestResponse.INVALID);
        }

        Optional<MemberRelationship> row = relationships.attest(key.get());

        // Not on record at all: exactly a bare invalid, with nothing that would tell a caller probing at
        // random whether they got close. On record: the caller can only hold this key because the member
        // gave it to them, so they are told the standing, including that it has been revoked, which is
        // what they came to find out.
        return ResponseEntity.ok(
                row.map(found -> AttestResponse.from(found, Instant.now(clock))).orElse(AttestResponse.INVALID));
    }

    /**
     * The request body as sent, whatever its Content-Type, or null when it is over {@link #MAX_BODY_BYTES}.
     *
     * <p>Read from the servlet input stream rather than bound by the framework, so a body declared as a
     * form, as text or as nothing at all reaches the same reader as one declared as JSON, and the three
     * implementations read the same bytes. A declared length over the cap is refused before a byte is
     * read, and an undeclared one is read only one byte past the cap, enough to know it is over.
     */
    static byte[] readBody(HttpServletRequest request) {
        if (request.getContentLengthLong() > MAX_BODY_BYTES) {
            return null;
        }

        try {
            byte[] body = request.getInputStream().readNBytes(MAX_BODY_BYTES + 1);
            return body.length > MAX_BODY_BYTES ? null : body;
        } catch (IOException unreadable) {
            throw new UncheckedIOException(unreadable);
        }
    }

    /**
     * Reads {@code public_key} from a request body as a string, or nothing. Shared by /attest and the key
     * PUT, the two bodies that carry only that one field.
     *
     * <p>A body that is not valid UTF-8, that starts with a byte order mark, that carries anything after
     * the JSON value, that is not a JSON object, or whose public_key is not a JSON string yields null,
     * treated as a malformed body by both callers rather than as a reason to fail before the handler runs.
     * The same three byte-level rules on all three implementations: a byte order mark is not part of JSON,
     * a second value is not one document, and invalid UTF-8 is not text.
     */
    static String readPublicKey(byte[] body) {
        if (body == null || body.length == 0) {
            return null;
        }

        String text;
        try {
            text = StandardCharsets.UTF_8
                    .newDecoder()
                    .onMalformedInput(CodingErrorAction.REPORT)
                    .onUnmappableCharacter(CodingErrorAction.REPORT)
                    .decode(ByteBuffer.wrap(body))
                    .toString();
        } catch (CharacterCodingException invalidUtf8) {
            return null;
        }

        if (text.isBlank() || text.startsWith("﻿")) {
            return null;
        }

        try {
            JsonNode value = JSON.readTree(text).get("public_key");
            return value != null && value.isTextual() ? value.asText() : null;
        } catch (JsonProcessingException notJson) {
            return null;
        }
    }

    private static <T> ResponseEntity<T> unauthorized() {
        return ResponseEntity.status(HttpStatus.UNAUTHORIZED).build();
    }
}
