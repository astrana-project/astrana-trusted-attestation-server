using Microsoft.AspNetCore.Antiforgery;
using Microsoft.AspNetCore.Http;

namespace Astrana.TrustedAttestation.Server.Tests.Support;

/// <summary>
/// An anti-forgery service whose verdict is fixed, and which records whether it was asked, so a test can
/// show both what a handler does with each verdict and that a request with nothing to protect is never checked.
/// </summary>
internal sealed class FakeAntiforgery(bool valid) : IAntiforgery
{
    public bool Checked { get; private set; }

    public Task ValidateRequestAsync(HttpContext httpContext)
    {
        Checked = true;
        return valid ? Task.CompletedTask : throw new AntiforgeryValidationException("The token is not valid.");
    }

    public Task<bool> IsRequestValidAsync(HttpContext httpContext)
    {
        Checked = true;
        return Task.FromResult(valid);
    }

    public AntiforgeryTokenSet GetAndStoreTokens(HttpContext httpContext) => throw new NotSupportedException();

    public AntiforgeryTokenSet GetTokens(HttpContext httpContext) => throw new NotSupportedException();

    public void SetCookieTokenAndHeader(HttpContext httpContext) => throw new NotSupportedException();
}
