using Astrana.TrustedAttestation.Server.Security;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// The IdP is read at startup, not at the first member's login.
///
/// Decision record 31 in docs/adr requires detectable IAM misconfiguration to stop the application starting rather
/// than surfacing confusingly at first login. The OpenID Connect handler fetches its discovery document
/// lazily, on the first challenge, so without the check an unreachable or wrong authority would leave the
/// app starting cleanly and serving every anonymous path (the manifest, /attest, the stylesheet) while
/// being incapable of signing anybody in.
///
/// That failure mode is worse than an outage. Every signal an operator has says the instance is
/// healthy, and the failure arrives one member at a time, usually long after whoever deployed it has
/// moved on.
///
/// The fetch itself is passed in, so these tests need no IdP and no network: what is being checked is
/// what happens to a failure, and what the operator is told, which is the entire diagnosis when the app
/// is not running to be inspected.
/// </summary>
public class IamRequirementTests
{
    private const string Authority = "https://idp.example/realms/test";

    private static Task Reachable(CancellationToken _) => Task.CompletedTask;

    private static Task Unreachable(CancellationToken _) =>
        throw new HttpRequestException("No such host is known.");

    // -------------------------------------------------------------------------------------------
    // What it refuses
    // -------------------------------------------------------------------------------------------

    [Fact]
    public async Task An_unreachable_authority_stops_startup()
    {
        await Assert.ThrowsAsync<InvalidOperationException>(
            () => IamRequirement.AssertReachableAsync(Unreachable, Authority, CancellationToken.None));
    }

    [Fact]
    public async Task The_refusal_names_the_authority_it_could_not_read()
    {
        // The message is the whole diagnosis: the app is not running and there is nothing to inspect.
        // An operator needs to know which authority was tried, because the one in configuration and the
        // one they think is configured are not always the same thing.
        var refusal = await Assert.ThrowsAsync<InvalidOperationException>(
            () => IamRequirement.AssertReachableAsync(Unreachable, Authority, CancellationToken.None));

        Assert.Contains(Authority, refusal.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task The_refusal_keeps_the_underlying_failure()
    {
        // "Could not read the IdP configuration" is not enough on its own: DNS, TLS, a proxy and a
        // typo all produce it. The inner exception is what tells an operator which.
        var refusal = await Assert.ThrowsAsync<InvalidOperationException>(
            () => IamRequirement.AssertReachableAsync(Unreachable, Authority, CancellationToken.None));

        Assert.IsType<HttpRequestException>(refusal.InnerException);
    }

    // -------------------------------------------------------------------------------------------
    // What it accepts
    // -------------------------------------------------------------------------------------------

    [Fact]
    public async Task A_reachable_authority_starts_normally()
    {
        var exception = await Record.ExceptionAsync(
            () => IamRequirement.AssertReachableAsync(Reachable, Authority, CancellationToken.None));

        Assert.Null(exception);
    }

    // -------------------------------------------------------------------------------------------
    // What it does not claim
    // -------------------------------------------------------------------------------------------

    [Fact]
    public async Task It_does_not_claim_to_have_checked_the_client_secret()
    {
        // The honest limit, asserted so nobody later "improves" the message into something that claims
        // more than the check can deliver. A syntactically valid but wrong secret only fails at a real
        // token exchange, which needs a member to have logged in. An instance that started must not be
        // believed to have working credentials.
        var refusal = await Assert.ThrowsAsync<InvalidOperationException>(
            () => IamRequirement.AssertReachableAsync(Unreachable, Authority, CancellationToken.None));

        Assert.DoesNotContain("secret", refusal.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task A_cancelled_startup_is_not_reported_as_a_broken_idp()
    {
        // Shutting down while the discovery fetch is in flight is not a misconfiguration, and reporting
        // it as one would send an operator looking at an IdP that was never the problem.
        using var cancelled = new CancellationTokenSource();
        await cancelled.CancelAsync();

        // ThrowsAnyAsync, not ThrowsAsync: a cancelled Task surfaces as TaskCanceledException, which
        // derives from OperationCanceledException, and an exact-type assertion here would be asserting
        // which subclass the runtime happened to pick rather than that cancellation passed through.
        await Assert.ThrowsAnyAsync<OperationCanceledException>(
            () => IamRequirement.AssertReachableAsync(
                token => Task.FromCanceled(token), Authority, cancelled.Token));
    }
}
