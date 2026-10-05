using System.Globalization;
using Astrana.TrustedAttestation.Server.Endpoints;
using Microsoft.AspNetCore.Localization;
using StringWithQualityHeaderValue = Microsoft.Net.Http.Headers.StringWithQualityHeaderValue;

namespace Astrana.TrustedAttestation.Server.Services;

/// <summary>
/// How a request's language is resolved. Rendering in the member's language is a requirement, not optional
/// polish, and there is little to localise. Three pages, a handful of controls, the member's own details and
/// their relationship labels.
/// </summary>
public static class RequestLocalization
{
    public static void Configure(RequestLocalizationOptions options, LocalizationSettings localization)
    {
        var supported = localization.Locales.Select(c => new CultureInfo(c)).ToList();
        options.DefaultRequestCulture = new RequestCulture(localization.DefaultLocale);
        options.SupportedCultures = supported;
        options.SupportedUICultures = supported;

        // ASP.NET Core ships three culture providers by default, query string, then cookie, then
        // Accept-Language, and all three are replaced. The query-string provider stays out because a
        // ?ui-culture=de link would let a crafted URL silently set the language, which the other two
        // implementations do not allow either. The stock cookie provider parses its own "c=..|uic=.." format,
        // so a plain, validated cookie set only by the language switcher replaces it, kept identical across the
        // three stacks. The stock Accept-Language provider matches by the .NET culture parent chain, which has
        // no rule for a language shipped in two scripts, so a bare "zh" would match nothing here where the
        // other two serve zh-Hans.
        var stock = options.RequestCultureProviders
            .Where(p => p is QueryStringRequestCultureProvider
                or CookieRequestCultureProvider
                or AcceptLanguageHeaderRequestCultureProvider)
            .ToList();
        foreach (var provider in stock)
        {
            options.RequestCultureProviders.Remove(provider);
        }

        // Accept-Language is the fallback behind the other two.
        options.RequestCultureProviders.Add(new CustomRequestCultureProvider(
            context => Task.FromResult(FromAcceptLanguage(context, localization))));

        // An IAM-provided locale claim wins over the browser's Accept-Language, because the organisation knows
        // which language it holds this member's record in.
        options.RequestCultureProviders.Insert(0, new ClaimsRequestCultureProvider(localization));

        // The language switcher's explicit choice wins over everything else, because a member who picked a
        // language has said what they want.
        options.RequestCultureProviders.Insert(0, new CustomRequestCultureProvider(
            context => Task.FromResult(FromLocaleCookie(context, localization))));
    }

    /// <summary>
    /// The language switcher's cookie, which holds just the locale code. It is validated on write to the
    /// offered set (see <see cref="LanguageSwitchEndpoint"/>) and matched again here, so an unknown or
    /// no-longer-offered value is ignored and resolution falls through to the claim, then Accept-Language.
    /// </summary>
    internal static ProviderCultureResult? FromLocaleCookie(HttpContext context, LocalizationSettings localization)
    {
        var cookie = localization.Match(context.Request.Cookies[LanguageSwitchEndpoint.CookieName]);
        return cookie is null ? null : new ProviderCultureResult(cookie);
    }

    /// <summary>
    /// The whole Accept-Language header in quality order, every entry it carries rather than the default cap
    /// of three, each matched with the one rule all three implementations share
    /// (<see cref="LocalizationSettings.Match"/>).
    /// </summary>
    internal static ProviderCultureResult? FromAcceptLanguage(HttpContext context, LocalizationSettings localization)
    {
        if (!StringWithQualityHeaderValue.TryParseList(context.Request.Headers.AcceptLanguage, out var entries))
        {
            return null;
        }

        var matched = entries
            .Where(entry => entry.Quality is null or > 0)
            .OrderByDescending(entry => entry.Quality ?? 1.0)
            .Select(entry => localization.Match(entry.Value.Value))
            .FirstOrDefault(match => match is not null);

        return matched is null ? null : new ProviderCultureResult(matched);
    }
}
