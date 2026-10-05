namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Reads the IdP's configuration once at startup, so a misconfigured one stops the application rather
/// than surfacing at the first member's login.
///
/// The OpenID Connect handler fetches its discovery document lazily, on the first challenge. Left to
/// that, an unreachable or wrong authority lets the application start cleanly and serve every anonymous
/// path -- the manifest, <c>/attest</c>, the stylesheet -- while being incapable of signing anybody in.
///
/// That is worse than an outage. Every signal an operator has says the instance is healthy: it is
/// serving, the manifest is right, verification works. The failure arrives one member at a time, at the
/// moment they try to sign in, usually long after whoever deployed it has moved on.
///
/// Scoped to what is actually checkable without a live login, which is the same scope
/// <see cref="TlsRequirement"/> takes: a syntactically valid but wrong client secret cannot be caught
/// this way by definition -- it only fails at a real token exchange -- and a check implying otherwise
/// would be worse than none, because an instance that started would be believed to have working
/// credentials.
/// </summary>
internal static class IamRequirement
{
    /// <param name="fetchConfiguration">
    /// Reads the provider's configuration. Passed in rather than reached for, so this stays testable
    /// without an IdP and without a network, and so the caller decides what "the configuration" means --
    /// a discovery document for OIDC, metadata for SAML.
    /// </param>
    public static async Task AssertReachableAsync(
        Func<CancellationToken, Task> fetchConfiguration,
        string authority,
        CancellationToken cancellationToken)
    {
        try
        {
            await fetchConfiguration(cancellationToken);
        }
        catch (OperationCanceledException)
        {
            // Shutting down while the fetch is in flight is not a misconfiguration. Reporting it as one
            // would send an operator looking at an IdP that was never the problem.
            throw;
        }
        catch (Exception exception)
        {
            // The inner exception is kept deliberately. "Could not read the IdP configuration" is not
            // enough on its own -- DNS, TLS, a proxy and a typo all produce it, and which one it was is
            // the only thing that tells an operator where to look.
            throw new InvalidOperationException(
                $"Could not read the IdP configuration at {authority}. The application will not start "
                + "against an identity provider it cannot reach, because the alternative is starting "
                + "successfully and failing at the first member's login. Check the authority is correct "
                + "and reachable from this host.",
                exception);
        }
    }
}
