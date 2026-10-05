<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MemberRelationship;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipService;
use App\Support\PublicKeys;
use App\Support\Timestamps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The four operations, and nothing else.
 *
 * Every path is /me-scoped: there is no way to address any record but your own. Which member is acted on
 * comes entirely from the authenticated session, so there is no member identifier in any route to tamper
 * with -- not an authorization check layered on top, an absence of the mechanism. The relationship type
 * in the path says which of the caller's own relationships to act on, never whose.
 *
 * Granting is deliberately not here. It exists only as a stored procedure the organisation calls, and
 * keeping it out of this file is what stops a member self-granting a relationship nobody vouched for.
 *
 * Errors are status-code-only, with no body: response bodies are reserved for actual data, not error
 * explanation.
 */
final class ApiController extends Controller
{
    /** The only characters a key-clearing public_key may contain: space, tab, carriage return, line feed. */
    private const BLANK_KEY_CHARACTERS = " \t\r\n";

    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly MemberIdentityResolver $resolver,
    ) {}

    public function me(Request $request): JsonResponse|Response
    {
        $member = $this->resolver->resolve($request);
        if ($member === null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        $now = Carbon::now();

        // An empty array is a real answer, not an error. A member the organisation has granted nothing is
        // exactly what everyone looks like before their first grant.
        return response()->json([
            // From the organisation's own sign-in session. Astrana Trusted Attestation does not store this.
            'name' => $member->name,
            'relationships' => $this->relationships->findAllHeldBy($member->iamSubjectId)
                ->map(fn (MemberRelationship $row): array => [
                    'relationship_type' => $row->relationship_type,
                    'relationship_subtype' => $row->relationship_subtype,
                    'status' => $row->statusAt($now)->value,
                    'public_key' => $row->public_key === null
                        ? null
                        : PublicKeys::toBase64($row->public_key),
                    // The one format all three implementations write (see Timestamps), the same string
                    // the self-service page shows.
                    'expires_at' => $row->expires_at === null ? null : Timestamps::iso8601Utc($row->expires_at),
                ])
                ->values(),
        ]);
    }

    public function setKey(Request $request, string $relationshipType): JsonResponse|Response
    {
        $subject = $this->resolver->subject($request);
        if ($subject === null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        // A public_key that is empty or holds only spaces, tabs, carriage returns and line feeds means
        // "clear the key". The member pauses the relationship while keeping the grant, and the service
        // receives null. Any other whitespace character, NUL and vertical tab included, is a malformed
        // key, the same test the other two implementations apply. An absent, JSON-null or non-string
        // public_key stays a 400, so a garbled body never wipes a key. A present string that will not
        // parse is a mistyped key, also 400.
        $raw = $request->json('public_key');
        if (! is_string($raw)) {
            return response('', Response::HTTP_BAD_REQUEST);
        }

        $key = null;
        if (trim($raw, self::BLANK_KEY_CHARACTERS) !== '') {
            $key = PublicKeys::parse($raw);
            if ($key === null) {
                return response('', Response::HTTP_BAD_REQUEST);
            }
        }

        $result = $this->relationships->setKey($subject, $relationshipType, $key);

        // Never created. A PUT that could create would let any member self-grant any type they liked,
        // with no organisation involvement at all.
        if ($result === RelationshipService::NOT_GRANTED) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        if ($result === RelationshipService::CONFLICT) {
            return response('', Response::HTTP_CONFLICT);
        }

        // Re-read so the status reflects what is actually on the row: setting a key on a revoked
        // relationship succeeds without restoring it, and the member has to be told that. public_key comes
        // from the row, so a cleared key comes back as null.
        $row = $this->relationships->find($subject, $relationshipType);
        if ($row === null) {
            return response('', Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'public_key' => $row->public_key === null ? null : PublicKeys::toBase64($row->public_key),
            'relationship_type' => $row->relationship_type,
            'relationship_subtype' => $row->relationship_subtype,
            'status' => $row->statusAt(Carbon::now())->value,
        ]);
    }

    public function delete(Request $request, string $relationshipType): Response
    {
        $subject = $this->resolver->subject($request);
        if ($subject === null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return $this->relationships->delete($subject, $relationshipType)
            ? response('', Response::HTTP_NO_CONTENT)
            : response('', Response::HTTP_NOT_FOUND);
    }

    public function selfRevoke(Request $request, string $relationshipType): Response
    {
        // Idempotent: revoking an already-revoked relationship is 204 too, and writes no second audit
        // entry. 404 means only that the caller does not hold this relationship at all.
        $subject = $this->resolver->subject($request);
        if ($subject === null) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return $this->relationships->selfRevoke($subject, $relationshipType)
            ? response('', Response::HTTP_NO_CONTENT)
            : response('', Response::HTTP_NOT_FOUND);
    }

    /**
     * Anonymous by design. A 32-byte random key cannot be guessed, so holding one is itself the access
     * control; requiring callers to identify themselves would only let the organisation learn who is
     * asking.
     *
     * POST rather than GET so the key stays out of URLs, and therefore out of access logs, proxy logs and
     * browser history, even though the key itself is not secret.
     *
     * A point lookup only: one specific key in, the one relationship it belongs to out. There is no
     * operation to list or enumerate keys on record.
     */
    public function attest(Request $request): JsonResponse
    {
        $key = PublicKeys::parse($request->json('public_key'));

        if ($key === null) {
            // A malformed key is answered the same way an unknown one is. Distinguishing the two would
            // leak shape information for no benefit.
            return response()->json(['valid' => false]);
        }

        $row = $this->relationships->attest($key);

        if ($row === null) {
            // Not on record at all: exactly a bare invalid, with nothing that would tell a caller probing
            // at random whether they got close.
            return response()->json(['valid' => false]);
        }

        // On record. The caller can only hold this key because the member gave it to them, so they are
        // told the standing -- including that it has been revoked, which is what they came to find out.
        $body = [
            'valid' => true,
            'relationship_type' => $row->relationship_type,
        ];

        if ($row->relationship_subtype !== null) {
            $body['relationship_subtype'] = $row->relationship_subtype;
        }

        $body['status'] = $row->statusAt(Carbon::now())->value;

        return response()->json($body);
    }
}
