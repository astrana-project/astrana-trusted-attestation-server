using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Microsoft.AspNetCore.Antiforgery;

namespace Astrana.TrustedAttestation.Server.Endpoints;

/// <summary>
/// The four operations, and nothing else.
///
/// Every path is <c>/me</c>-scoped: there is no way to address any record but your own. Which member is
/// acted on comes entirely from the authenticated session, so there is no member identifier in any route
/// to tamper with -- not an authorization check layered on top, an absence of the mechanism. The
/// <c>{relationship_type}</c> segment says which of the caller's own relationships to act on, never
/// whose.
///
/// Granting is deliberately not here. It exists only as a stored procedure the organisation calls, and
/// keeping it out of this file is what stops a member self-granting a relationship nobody vouched for.
/// </summary>
public static class ApiEndpoints
{
    /// <summary>
    /// Authorises against the cookie scheme specifically, rather than the application default.
    ///
    /// The default challenge scheme is OpenID Connect, which answers an unauthenticated request by
    /// redirecting to the IdP -- right for the browser-facing page, wrong for the API, which the contract
    /// says answers 401. Naming the cookie scheme here routes the challenge through the cookie handler's
    /// events, where it is turned into a bare status code.
    /// </summary>
    public const string PolicyName = "TrustedAttestationApi";

    /// <summary>The request header that carries the anti-forgery token, the same name in all three implementations.</summary>
    public const string AntiforgeryHeader = "X-CSRF-TOKEN";

    public static void ConfigureAntiforgery(AntiforgeryOptions options) => options.HeaderName = AntiforgeryHeader;

    public static void MapTrustedAttestationApi(this IEndpointRouteBuilder app)
    {
        // Versioned in the route so a breaking change can introduce /api/v2 alongside this without forcing
        // every deployment to upgrade in lockstep. /api with no version segment resolves to the latest, so
        // a caller that does not care about pinning never has to track version numbers.
        MapVersion(app.MapGroup("/api/v1"));
        MapVersion(app.MapGroup("/api"));
    }

    private static void MapVersion(RouteGroupBuilder group)
    {
        // Every API body is capped at 64 kilobytes, with HTTP 413 and no body above it, in all three
        // implementations. The metadata is what Kestrel reads; PublicKeyBody enforces the same cap as it reads.
        group.WithMetadata(ApiBodyLimit.Instance);

        group.MapGet("/me", GetMe).RequireAuthorization(PolicyName);
        group.MapPut("/me/relationships/{relationshipType}/key", SetKey).RequireAuthorization(PolicyName);
        group.MapDelete("/me/relationships/{relationshipType}/key", DeleteRelationship).RequireAuthorization(PolicyName);
        group.MapPost("/me/relationships/{relationshipType}/revoke", SelfRevoke).RequireAuthorization(PolicyName);

        // Anonymous by design. A 32-byte random key cannot be guessed, so holding one is itself the access
        // control; requiring callers to identify themselves would only let the organisation learn who is
        // asking.
        group.MapPost("/attest", Attest).AllowAnonymous();
    }

    internal static async Task<IResult> GetMe(
        HttpContext http,
        MemberIdentityResolver resolver,
        RelationshipService relationships,
        TimeProvider timeProvider,
        CancellationToken cancellationToken)
    {
        if (!resolver.TryResolve(http.User, out var member))
        {
            return Results.Unauthorized();
        }

        var rows = await relationships.FindAllHeldByAsync(member.IamSubjectId, cancellationToken);
        var now = timeProvider.GetUtcNow().UtcDateTime;

        // An empty list is a real answer, not an error. A member the organisation has granted nothing is
        // exactly what everyone looks like before their first grant.
        return Results.Ok(new MeResponse
        {
            Name = member.Name,
            Relationships = [.. rows.Select(row => RelationshipWithKey.From(row, now))],
        });
    }

    internal static async Task<IResult> SetKey(
        HttpContext http,
        string relationshipType,
        SetKeyRequest request,
        MemberIdentityResolver resolver,
        RelationshipService relationships,
        TimeProvider timeProvider,
        CancellationToken cancellationToken)
    {
        if (!resolver.TryResolve(http.User, out var member))
        {
            return Results.Unauthorized();
        }

        // A present but blank public_key, empty or only spaces, tabs, carriage returns and line feeds,
        // means "clear the key". The member pauses the relationship while keeping the grant, and the
        // service receives null. The lenient body reader also turns an absent, JSON-null or wrong-typed
        // public_key into a null string, and that stays 400, so a garbled body never wipes a key. Any
        // other string that will not parse, including one holding other whitespace, is a malformed key
        // and also 400.
        if (request.PublicKey is null)
        {
            return Results.BadRequest();
        }

        byte[]? key = null;
        if (!PublicKey.IsBlank(request.PublicKey))
        {
            if (!PublicKey.TryParse(request.PublicKey, out var parsed))
            {
                return Results.BadRequest();
            }

            key = parsed;
        }

        var outcome = await relationships.SetKeyAsync(member.IamSubjectId, relationshipType, key, cancellationToken);

        return outcome.Status switch
        {
            // Not an upsert: a relationship nobody granted is refused, never created.
            SetKeyStatus.NotGranted => Results.NotFound(),

            // The key belongs to another relationship, whether the up-front check or the unique index saw it.
            SetKeyStatus.Conflict => Results.Conflict(),

            // Stored or cleared -- but the status may still read revoked, because neither registering nor
            // clearing a key is an appeal. public_key is null in the response when the key was cleared.
            _ => Results.Ok(new SetKeyResponse
            {
                PublicKey = outcome.Row!.PublicKey is null ? null : PublicKey.ToBase64(outcome.Row.PublicKey),
                RelationshipType = outcome.Row.RelationshipType,
                RelationshipSubtype = outcome.Row.RelationshipSubtype,
                Status = outcome.Row.StatusAt(timeProvider.GetUtcNow().UtcDateTime).ToWireValue(),
            }),
        };
    }

    internal static async Task<IResult> DeleteRelationship(
        HttpContext http,
        string relationshipType,
        MemberIdentityResolver resolver,
        RelationshipService relationships,
        CancellationToken cancellationToken)
    {
        if (!resolver.TryGetSubject(http.User, out var subject))
        {
            return Results.Unauthorized();
        }

        // A hard delete of this one relationship, not a soft revoke and not a return to unkeyed: a member
        // exercising this themselves is closer to a right-to-erasure action than an organisation-initiated
        // status change. Not held at all is a 404 rather than a silent success.
        return await relationships.DeleteAsync(subject, relationshipType, cancellationToken)
            ? Results.NoContent()
            : Results.NotFound();
    }

    internal static async Task<IResult> SelfRevoke(
        HttpContext http,
        string relationshipType,
        MemberIdentityResolver resolver,
        IAntiforgery antiforgery,
        RelationshipService relationships,
        CancellationToken cancellationToken)
    {
        if (!resolver.TryGetSubject(http.User, out var subject))
        {
            return Results.Unauthorized();
        }

        // A browser preflights a cross-origin PUT or DELETE, but not a plain POST, so a form on a sibling
        // subdomain, which counts as the same site for the session cookie, could otherwise revoke. The
        // self-service page sends the token in the X-CSRF-TOKEN header.
        if (!await antiforgery.IsRequestValidAsync(http))
        {
            return Results.StatusCode(StatusCodes.Status403Forbidden);
        }

        // A relationship the caller does not hold is a 404 rather than the procedure's silent no-op: the
        // procedure cannot tell "not granted" from "already revoked", which is right for it but wrong as an
        // HTTP answer.
        return await relationships.SelfRevokeAsync(subject, relationshipType, cancellationToken)
            ? Results.NoContent()
            : Results.NotFound();
    }

    internal static async Task<IResult> Attest(
        AttestRequest request,
        RelationshipService relationships,
        TimeProvider timeProvider,
        CancellationToken cancellationToken)
    {
        // POST rather than GET so the key stays out of URLs, and therefore out of access logs, proxy logs
        // and browser history, even though the key itself is not secret.
        //
        // A point lookup only: one specific key in, one relationship out. There is no operation to list or
        // enumerate keys on record, which is what makes anonymous access safe here. The key identifies
        // exactly one relationship, so there is never an array to return and never anything to say about
        // the member's other relationships.
        if (!PublicKey.TryParse(request.PublicKey, out var key))
        {
            // A malformed key is answered the same way an unknown one is. Distinguishing the two would
            // leak shape information for no benefit.
            return Results.Ok(AttestResponse.Invalid);
        }

        var row = await relationships.AttestAsync(key, cancellationToken);

        // Not on record at all: exactly { "valid": false }, and nothing that would tell a caller probing
        // at random whether they got close. On record, the caller can only hold this key because the
        // member gave it to them, so they are told the standing -- including that it has been revoked,
        // which is what they came to find out.
        return row is null
            ? Results.Ok(AttestResponse.Invalid)
            : Results.Ok(AttestResponse.For(row, timeProvider.GetUtcNow().UtcDateTime));
    }
}
