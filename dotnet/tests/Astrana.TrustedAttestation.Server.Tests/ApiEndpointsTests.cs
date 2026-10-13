using System.Security.Claims;
using System.Text;
using System.Text.Json;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Contract;
using Astrana.TrustedAttestation.Server.Data;
using Astrana.TrustedAttestation.Server.Endpoints;
using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;
using Astrana.TrustedAttestation.Server.Tests.Support;
using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.DataProtection;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.HttpResults;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The five handlers turn one <see cref="RelationshipService"/> outcome, plus the identity and key guards
/// in front of it, into exactly one HTTP status. The contract is status-code-carrying: a verifying peer
/// or a member's browser acts on the code alone, so a handler that mapped NotGranted to a 409, or dropped
/// the 401 in front of a mutation, would be a real defect rather than a cosmetic one.
///
/// These run the real handlers over a real service built on the shared hand-written doubles -- no
/// database, no HTTP pipeline, no mocking framework -- so each branch is pinned where the mapping is made
/// rather than only through a live login the conformance suite drives. The service's own reasoning is
/// covered separately in <see cref="RelationshipServiceTests"/>; here the question is purely which result
/// each outcome becomes.
/// </summary>
public class ApiEndpointsTests
{
    // A canonical 32-byte key: non-zero (the all-zero key is refused before it reaches a handler) and in
    // the padded, standard-alphabet encoding PublicKey.TryParse insists on.
    private static readonly byte[] KeyBytes = MakeKey();
    private static readonly string KeyBase64 = Convert.ToBase64String(KeyBytes);

    private static byte[] MakeKey()
    {
        var key = new byte[PublicKey.Length];
        for (var i = 0; i < key.Length; i++)
        {
            key[i] = (byte)(i + 1);
        }

        return key;
    }

    private static readonly DateTimeOffset Now = new(2026, 8, 29, 12, 0, 0, TimeSpan.Zero);

    private readonly FakeRelationshipStore _store = new();
    private readonly RecordingAuditWriter _audit = new();
    private readonly RecordingSelfRevoke _selfRevoke = new();

    private RelationshipService Service() =>
        new(_store, new ImmediateTransactionRunner(), _audit, _selfRevoke, RelationshipTypeCatalog.Load());

    private static readonly MemberIdentityResolver Resolver =
        new(Options.Create(new TrustedAttestationOptions()));

    private static readonly TimeProvider Clock = new FixedTimeProvider(Now);

    // The self-service page sends its anti-forgery token in this header with every request that changes
    // something. Whether the token is good is the anti-forgery service's verdict, which each test fixes.
    private static DefaultHttpContext SignedIn(string subject = "alice", string name = "Alice Anderson")
    {
        var context = new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                [new Claim("sub", subject), new Claim("name", name)], "test")),
        };
        context.Request.Headers[ApiEndpoints.AntiforgeryHeader] = "the-page-token";

        return context;
    }

    // No usable subject at all: the anonymous default principal a DefaultHttpContext carries.
    private static DefaultHttpContext Anonymous() => new DefaultHttpContext();

    // Present but unusable: a multivalued "sub" is the shape a provider sending an array produces, and the
    // resolver treats it as absent rather than picking an arbitrary half. It must still be a 401, never a
    // 500 or a member keyed by half an identity.
    private static DefaultHttpContext AmbiguousIdentity() =>
        new DefaultHttpContext
        {
            User = new ClaimsPrincipal(new ClaimsIdentity(
                [new Claim("sub", "a"), new Claim("sub", "b")], "test")),
        };

    private static int Status(IResult result) =>
        Assert.IsType<IStatusCodeHttpResult>(result, exactMatch: false).StatusCode ?? 0;

    private static T Body<T>(IResult result) => Assert.IsType<Ok<T>>(result).Value!;

    private static MemberRelationship Granted(string type = "employee", byte[]? key = null) =>
        new() { Id = 1, IamSubjectId = "alice", RelationshipType = type, PublicKey = key };

    private static FakeAntiforgery ValidToken() => new(valid: true);

    /// <summary>
    /// A key PUT whose body carries <paramref name="publicKey"/> as its public_key, or no public_key at
    /// all when it is null, with a token the anti-forgery service accepts unless another is given.
    /// </summary>
    private Task<IResult> SetKey(DefaultHttpContext http, string type, string? publicKey, FakeAntiforgery? antiforgery = null)
    {
        var body = publicKey is null ? "{}" : JsonSerializer.Serialize(new Dictionary<string, string> { ["public_key"] = publicKey });
        http.Request.Body = new MemoryStream(Encoding.UTF8.GetBytes(body));

        return ApiEndpoints.SetKey(http, type, Resolver, antiforgery ?? ValidToken(), Service(), Clock, default);
    }

    private Task<IResult> Delete(DefaultHttpContext http, string type, FakeAntiforgery? antiforgery = null) =>
        ApiEndpoints.DeleteRelationship(http, type, Resolver, antiforgery ?? ValidToken(), Service(), default);

    private Task<IResult> SelfRevoke(DefaultHttpContext http, string type, FakeAntiforgery? antiforgery = null) =>
        ApiEndpoints.SelfRevoke(http, type, Resolver, antiforgery ?? ValidToken(), Service(), default);

    // -- GetMe ---------------------------------------------------------------------------------------

    [Fact]
    public async Task GetMe_without_a_usable_identity_is_401()
    {
        Assert.Equal(401, Status(await ApiEndpoints.GetMe(Anonymous(), Resolver, Service(), Clock, default)));
        Assert.Equal(401, Status(await ApiEndpoints.GetMe(AmbiguousIdentity(), Resolver, Service(), Clock, default)));
    }

    [Fact]
    public async Task GetMe_returns_the_members_name_and_every_relationship_they_hold()
    {
        _store.Held =
        [
            Granted("employee", KeyBytes),
            Granted("client"),
        ];

        var body = Body<MeResponse>(await ApiEndpoints.GetMe(SignedIn(), Resolver, Service(), Clock, default));

        Assert.Equal("Alice Anderson", body.Name);
        Assert.Collection(body.Relationships,
            r => { Assert.Equal("employee", r.RelationshipType); Assert.Equal("active", r.Status); Assert.Equal(KeyBase64, r.PublicKey); },
            r => { Assert.Equal("client", r.RelationshipType); Assert.Equal("unkeyed", r.Status); Assert.Null(r.PublicKey); });
    }

    [Fact]
    public async Task GetMe_for_a_member_who_holds_nothing_is_an_empty_list_not_an_error()
    {
        // The ordinary state before a first grant. A member has to be able to load the page that tells
        // them there is nothing there yet, so this is a 200 with an empty array, never a 404 or 403.
        _store.Held = [];

        var result = await ApiEndpoints.GetMe(SignedIn(), Resolver, Service(), Clock, default);

        Assert.Equal(200, Status(result));
        Assert.Empty(Body<MeResponse>(result).Relationships);
    }

    // -- SetKey --------------------------------------------------------------------------------------

    [Theory]
    [InlineData("not-base64!!")]
    [InlineData("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=")] // 32 zero bytes: refused, not stored
    public async Task SetKey_with_a_non_empty_unusable_key_is_400_and_never_reaches_the_store(string badKey)
    {
        // The guard sits in front of the service: a non-empty key that will not parse must be a clean 400
        // with no body, and no row must be touched on the way to it. An empty value is a different case --
        // it means "clear the key", covered below.
        _store.Own = Granted();

        var result = await SetKey(SignedIn(), "employee", badKey);

        Assert.Equal(400, Status(result));
        Assert.False(_store.Saved);
    }

    [Fact]
    public async Task SetKey_with_an_absent_or_wrong_typed_key_is_400_not_a_silent_clear()
    {
        // The lenient body reader collapses an absent, JSON-null or wrong-typed public_key to a null
        // string. That is a malformed request, not a request to clear: it must be a 400 and must not touch
        // the row, so a garbled body can never wipe a member's key by accident.
        _store.Own = Granted(key: KeyBytes);

        var result = await SetKey(SignedIn(), "employee", null);

        Assert.Equal(400, Status(result));
        Assert.False(_store.Saved);
    }

    [Theory]
    [InlineData(" ")] // a non-breaking space
    [InlineData("\f")] // a form feed
    [InlineData("   ")]
    public async Task SetKey_with_whitespace_other_than_space_tab_cr_or_lf_is_400_not_a_clear(string badKey)
    {
        // Blank means empty or only spaces, tabs, carriage returns and line feeds, the same test in all
        // three implementations. Any other whitespace is a malformed key, so it must not wipe the key.
        _store.Own = Granted(key: KeyBytes);

        var result = await SetKey(SignedIn(), "employee", badKey);

        Assert.Equal(400, Status(result));
        Assert.False(_store.Saved);
    }

    [Theory]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData(" \t\r\n")]
    public async Task SetKey_with_an_empty_key_clears_it_and_is_200_unkeyed(string emptyKey)
    {
        // A blank public_key is the member clearing their key to pause the relationship, not a malformed
        // request. The key comes off the row, the standing returns to unkeyed, the response carries a null
        // key, and the clearing is recorded.
        _store.Own = Granted(key: KeyBytes);

        var result = await SetKey(SignedIn(), "employee", emptyKey);

        Assert.Equal(200, Status(result));
        var body = Body<SetKeyResponse>(result);
        Assert.Null(body.PublicKey);
        Assert.Equal("unkeyed", body.Status);
        Assert.True(_store.Saved);
        Assert.Equal([AuditEvent.KeyCleared], _audit.Events);
    }

    [Fact]
    public async Task Clearing_a_key_on_a_relationship_that_was_never_granted_is_404()
    {
        _store.Own = null;

        var result = await SetKey(SignedIn(), "employee", "");

        Assert.Equal(404, Status(result));
    }

    [Fact]
    public async Task Clearing_a_key_on_a_revoked_relationship_removes_the_key_but_still_reports_revoked()
    {
        // Clearing is not an appeal any more than registering is: the key is removed but RevokedAt stands,
        // so the member is told the relationship is still revoked rather than back to waiting for a key.
        _store.Own = new MemberRelationship
        {
            Id = 1,
            IamSubjectId = "alice",
            RelationshipType = "employee",
            PublicKey = KeyBytes,
            RevokedAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc),
        };

        var result = await SetKey(SignedIn(), "employee", "");

        Assert.Equal(200, Status(result));
        var body = Body<SetKeyResponse>(result);
        Assert.Null(body.PublicKey);
        Assert.Equal("revoked", body.Status);
    }

    [Fact]
    public async Task SetKey_on_a_relationship_that_was_never_granted_is_404()
    {
        // Not an upsert: NotGranted becomes a 404, never a create.
        _store.Own = null;

        var result = await SetKey(SignedIn(), "employee", KeyBase64);

        Assert.Equal(404, Status(result));
    }

    [Theory]
    [InlineData("EMPLOYEE")]
    [InlineData("patient")]
    public async Task A_path_type_that_is_not_exactly_a_granted_vocabulary_value_is_404_on_every_operation(string pathType)
    {
        // EMPLOYEE is what a case-folding collation would match to the employee row; patient is outside
        // the vocabulary altogether. Both are 404, and neither writes anything.
        _store.Own = Granted("employee", KeyBytes);

        Assert.Equal(404, Status(await SetKey(SignedIn(), pathType, KeyBase64)));
        Assert.Equal(404, Status(await Delete(SignedIn(), pathType)));
        Assert.Equal(404, Status(await SelfRevoke(SignedIn(), pathType)));
        Assert.False(_store.Saved);
        Assert.Empty(_audit.Events);
    }

    [Fact]
    public async Task SetKey_on_a_row_that_vanished_before_the_write_is_404()
    {
        _store.Own = Granted();
        _store.SaveFindsTheRowGone = true;

        var result = await SetKey(SignedIn(), "employee", KeyBase64);

        Assert.Equal(404, Status(result));
    }

    [Fact]
    public async Task SetKey_with_a_key_another_relationship_holds_is_409()
    {
        _store.Own = Granted();
        _store.KeyHeldElsewhere = true;

        var result = await SetKey(SignedIn(), "employee", KeyBase64);

        Assert.Equal(409, Status(result));
    }

    [Fact]
    public async Task SetKey_that_stores_returns_200_with_the_stored_key_and_its_standing()
    {
        _store.Own = Granted();

        var result = await SetKey(SignedIn(), "employee", KeyBase64);

        Assert.Equal(200, Status(result));
        var body = Body<SetKeyResponse>(result);
        Assert.Equal(KeyBase64, body.PublicKey);
        Assert.Equal("employee", body.RelationshipType);
        Assert.Equal("active", body.Status);
    }

    [Fact]
    public async Task SetKey_on_a_revoked_relationship_stores_the_key_but_still_reports_revoked()
    {
        // The security-relevant case: registering a key is not an appeal. The member gets a 200 because
        // the key really was stored, and the reported status is still "revoked" so they are not misled
        // into thinking they have restored themselves.
        _store.Own = new MemberRelationship
        {
            Id = 1,
            IamSubjectId = "alice",
            RelationshipType = "employee",
            RevokedAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc),
        };

        var result = await SetKey(SignedIn(), "employee", KeyBase64);

        Assert.Equal(200, Status(result));
        Assert.Equal("revoked", Body<SetKeyResponse>(result).Status);
    }

    // -- DeleteRelationship --------------------------------------------------------------------------

    [Fact]
    public async Task Delete_without_a_usable_identity_is_401()
    {
        Assert.Equal(401, Status(await Delete(AmbiguousIdentity(), "employee")));
    }

    [Fact]
    public async Task Delete_of_a_held_relationship_is_204()
    {
        _store.Own = Granted();

        Assert.Equal(204, Status(await Delete(SignedIn(), "employee")));
    }

    [Fact]
    public async Task Delete_of_a_relationship_not_held_is_404_not_a_silent_success()
    {
        _store.Own = null;

        Assert.Equal(404, Status(await Delete(SignedIn(), "employee")));
    }

    // -- SelfRevoke ----------------------------------------------------------------------------------

    [Fact]
    public async Task SelfRevoke_of_a_held_relationship_is_204()
    {
        _store.Own = Granted();

        Assert.Equal(204, Status(await SelfRevoke(SignedIn(), "employee")));
    }

    [Fact]
    public async Task SelfRevoke_of_a_relationship_not_held_is_404()
    {
        // A 404 rather than the procedure's silent no-op, which cannot tell "not granted" from "already
        // revoked" -- right for the procedure, wrong as an HTTP answer.
        _store.Own = null;

        Assert.Equal(404, Status(await SelfRevoke(SignedIn(), "employee")));
    }

    // -- The anti-forgery token on every request that changes something ------------------------------

    public static TheoryData<string> StateChangingRequests => ["set key", "delete", "revoke"];

    private Task<IResult> Change(string request, DefaultHttpContext http, FakeAntiforgery antiforgery) => request switch
    {
        "set key" => SetKey(http, "employee", KeyBase64, antiforgery),
        "delete" => Delete(http, "employee", antiforgery),
        _ => SelfRevoke(http, "employee", antiforgery),
    };

    [Theory]
    [MemberData(nameof(StateChangingRequests))]
    public async Task A_missing_or_wrong_token_is_403_with_no_body_and_changes_nothing(string request)
    {
        // A form on a sibling subdomain counts as the same site for the session cookie, so the cookie alone
        // does not show the member asked for the change. Without the token the relationship is not even
        // looked up.
        _store.Own = Granted(key: KeyBytes);
        var antiforgery = new FakeAntiforgery(valid: false);

        var response = await ExecutedResult.Of(await Change(request, SignedIn(), antiforgery));

        Assert.True(antiforgery.Checked);
        Assert.Equal(StatusCodes.Status403Forbidden, response.StatusCode);
        Assert.Equal(0, response.Body.Length);
        Assert.Null(response.ContentType);
        Assert.Equal(0, _store.Lookups);
        Assert.False(_store.Saved);
        Assert.False(_store.Removed);
        Assert.Equal(0, _selfRevoke.Invocations);
        Assert.Empty(_audit.Events);
    }

    [Theory]
    [MemberData(nameof(StateChangingRequests))]
    public async Task Without_the_header_it_is_403_and_the_token_is_looked_for_nowhere_else(string request)
    {
        // The token travels in the X-CSRF-TOKEN header only, so the answer is reached before anything reads
        // the body, and a form body is never parsed for a token field of the framework's own.
        var http = SignedIn();
        http.Request.Headers.Remove(ApiEndpoints.AntiforgeryHeader);
        var antiforgery = ValidToken();

        Assert.Equal(StatusCodes.Status403Forbidden, Status(await Change(request, http, antiforgery)));
        Assert.False(antiforgery.Checked);
    }

    [Theory]
    [MemberData(nameof(StateChangingRequests))]
    public async Task Without_a_session_it_is_401_whatever_the_token(string request)
    {
        // A caller with no session could never have held a token, so the missing session is the answer.
        Assert.Equal(StatusCodes.Status401Unauthorized, Status(await Change(request, Anonymous(), new FakeAntiforgery(valid: false))));
        Assert.Equal(StatusCodes.Status401Unauthorized, Status(await Change(request, Anonymous(), ValidToken())));
    }

    private static DefaultHttpContext SignedInWithAnOversizedBody()
    {
        var http = SignedIn();
        http.Request.Body = new MemoryStream(new byte[PublicKeyBody.MaxBytes + 1]);

        return http;
    }

    [Fact]
    public async Task A_refused_token_is_answered_403_before_an_oversized_body_is_read()
    {
        var result = await ApiEndpoints.SetKey(
            SignedInWithAnOversizedBody(), "employee", Resolver, new FakeAntiforgery(valid: false), Service(), Clock, default);

        Assert.Equal(StatusCodes.Status403Forbidden, Status(result));
    }

    [Fact]
    public async Task With_an_accepted_token_an_oversized_body_is_refused_with_413()
    {
        var refusal = await Assert.ThrowsAsync<BadHttpRequestException>(() => ApiEndpoints.SetKey(
            SignedInWithAnOversizedBody(), "employee", Resolver, ValidToken(), Service(), Clock, default));

        Assert.Equal(StatusCodes.Status413PayloadTooLarge, refusal.StatusCode);
    }

    /// <summary>
    /// A POST carrying the cookie token and, in the named header, the request token, both issued by the
    /// framework's own anti-forgery service configured as the application configures it. Keys are kept in
    /// memory, so nothing is written to the machine.
    /// </summary>
    private static async Task<bool> TokenInHeaderIsAccepted(string headerName)
    {
        var services = new ServiceCollection().AddLogging();
        services.AddDataProtection().UseEphemeralDataProtectionProvider();
        services.AddAntiforgery(ApiEndpoints.ConfigureAntiforgery);
        var provider = services.BuildServiceProvider();
        var antiforgery = provider.GetRequiredService<IAntiforgery>();
        var cookieName = provider.GetRequiredService<IOptions<AntiforgeryOptions>>().Value.Cookie.Name;

        var tokens = antiforgery.GetAndStoreTokens(new DefaultHttpContext { RequestServices = provider });

        var request = new DefaultHttpContext { RequestServices = provider };
        request.Request.Method = HttpMethods.Post;
        request.Request.Headers.Cookie = $"{cookieName}={tokens.CookieToken}";
        request.Request.Headers[headerName] = tokens.RequestToken;

        return await antiforgery.IsRequestValidAsync(request);
    }

    [Fact]
    public async Task The_anti_forgery_token_is_read_from_the_x_csrf_token_header_the_page_sends()
    {
        // One header name in all three implementations, so the page's script and any other caller send the
        // same request whichever implementation answers it.
        Assert.True(await TokenInHeaderIsAccepted("X-CSRF-TOKEN"));
        Assert.False(await TokenInHeaderIsAccepted("RequestVerificationToken"));
    }

    // -- Attest --------------------------------------------------------------------------------------

    [Fact]
    public async Task Attest_is_anonymous_and_answers_200_even_for_an_unparseable_key()
    {
        // /attest draws one distinction only -- valid or not -- and a malformed key is simply not valid.
        // A caller fishing at random must not be able to tell a bad key from an unknown one, so both are
        // exactly { "valid": false }.
        var result = await ApiEndpoints.Attest(new AttestRequest { PublicKey = "not-a-key" }, Service(), Clock, default);

        Assert.Equal(200, Status(result));
        var body = Body<AttestResponse>(result);
        Assert.False(body.Valid);
        Assert.Null(body.RelationshipType);
        Assert.Null(body.Status);
    }

    [Fact]
    public async Task Attest_with_a_well_formed_key_on_record_for_nobody_is_valid_false()
    {
        _store.ByPublicKey = null;

        var result = await ApiEndpoints.Attest(new AttestRequest { PublicKey = KeyBase64 }, Service(), Clock, default);

        Assert.Equal(200, Status(result));
        Assert.False(Body<AttestResponse>(result).Valid);
    }

    [Fact]
    public async Task Attest_with_a_key_that_is_on_record_returns_its_relationship_and_standing()
    {
        _store.ByPublicKey = Granted("employee", KeyBytes);

        var body = Body<AttestResponse>(
            await ApiEndpoints.Attest(new AttestRequest { PublicKey = KeyBase64 }, Service(), Clock, default));

        Assert.True(body.Valid);
        Assert.Equal("employee", body.RelationshipType);
        Assert.Equal("active", body.Status);
    }

    [Fact]
    public async Task Attest_reports_a_revoked_key_as_on_record_but_carrying_a_revoked_standing()
    {
        // "valid" here means the key is on record, not that the relationship is currently good: the caller
        // can only hold this key because the member handed it to them, so they are told the standing they
        // came to find out. A revoked key is still on record (valid:true) with status "revoked", and it is
        // the peer that reads any status other than active as not currently attested. Collapsing this to
        // valid:false would hide the distinction between a revoked key and one that was never registered.
        _store.ByPublicKey = new MemberRelationship
        {
            Id = 1,
            IamSubjectId = "alice",
            RelationshipType = "employee",
            PublicKey = KeyBytes,
            RevokedAt = new DateTime(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc),
        };

        var body = Body<AttestResponse>(
            await ApiEndpoints.Attest(new AttestRequest { PublicKey = KeyBase64 }, Service(), Clock, default));

        Assert.True(body.Valid);
        Assert.Equal("revoked", body.Status);
    }
}
