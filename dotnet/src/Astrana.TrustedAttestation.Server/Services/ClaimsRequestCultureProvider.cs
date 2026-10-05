using Microsoft.AspNetCore.Localization;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// Reads the member's preferred locale from an IAM-provided claim, if the IdP sends one.
///
/// <para>Registered ahead of the built-in providers, so an org that holds a member's language preference
/// in its own directory wins over whatever the browser happens to advertise. Accept-Language remains the
/// fallback behind it. The claim is matched to the offered locales with the same rule as every other
/// source (see <see cref="LocalizationSettings.Match"/>), so a claim this instance cannot serve falls
/// through rather than selecting a language by accident.</para>
/// </summary>
internal sealed class ClaimsRequestCultureProvider(LocalizationSettings localization) : RequestCultureProvider
{
    /// <summary>The claim read. When a SAML attribute carries several values, the first is used.</summary>
    public const string ClaimType = "locale";

    public override Task<ProviderCultureResult?> DetermineProviderCultureResult(HttpContext httpContext)
    {
        var matched = localization.Match(httpContext.User.FindFirst(ClaimType)?.Value);

        return Task.FromResult(matched is null ? null : new ProviderCultureResult(matched));
    }
}
