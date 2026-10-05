using Astrana.TrustedAttestation.Server.Security;
using Astrana.TrustedAttestation.Server.Services;

namespace Astrana.TrustedAttestation.Server.Endpoints;

/// <summary>
/// The language switcher posts here. It stores the chosen locale in a plain cookie, validated against the
/// offered set so a forged value cannot render an unsupported language, and returns to the page the member
/// was on. There is no anti-forgery token, because the switcher is on the public landing page too, which
/// carries no session to hold a token. A cross-site form can still submit the switcher, and the most it
/// changes is the display language. Kept identical across the three stacks.
/// </summary>
public static class LanguageSwitchEndpoint
{
    public const string Path = "/set-language";

    /// <summary>The cookie holding the chosen locale code, read back by <see cref="RequestLocalization"/>.</summary>
    public const string CookieName = "ata_locale";

    // The explicit return type keeps the handler off the plain request delegate overload, which would accept
    // the lambda as well and then drop the redirect it returns.
    public static void MapLanguageSwitch(this IEndpointRouteBuilder app, LocalizationSettings localization) =>
        app.MapPost(Path, Task<IResult> (HttpContext http) => SetLanguageAsync(http, localization));

    internal static async Task<IResult> SetLanguageAsync(HttpContext http, LocalizationSettings localization)
    {
        // The form body only, and only when there is one. A request with no form (no body, or JSON) carries no
        // locale and no return path, so it sets nothing and lands on the landing page rather than failing.
        var form = await ReadFormAsync(http.Request);
        var posted = form?["locale"].ToString();

        // Stored in the shipped spelling, whatever case was posted, so the three implementations agree.
        var offered = localization.Locales.FirstOrDefault(l => string.Equals(l, posted, StringComparison.OrdinalIgnoreCase));
        if (offered is not null)
        {
            http.Response.Cookies.Append(CookieName, offered, new CookieOptions
            {
                HttpOnly = true,
                Secure = true,
                SameSite = SameSiteMode.Lax,
                MaxAge = TimeSpan.FromDays(365),
                Path = "/",
                IsEssential = true,
            });
        }

        // Only a path on this server, by the rule all three implementations apply (see ReturnPath).
        return Results.LocalRedirect(ReturnPath.Sanitize(form?["next"].ToString()));
    }

    private static async Task<IFormCollection?> ReadFormAsync(HttpRequest request)
    {
        if (!request.HasFormContentType)
        {
            return null;
        }

        try
        {
            return await request.ReadFormAsync(request.HttpContext.RequestAborted);
        }
        catch (InvalidDataException)
        {
            // A form Content-Type on a body that is not a form, or one past the form limits. No form, then.
            return null;
        }
    }
}
