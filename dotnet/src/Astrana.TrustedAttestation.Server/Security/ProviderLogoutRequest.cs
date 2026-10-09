using System.Security.Claims;
using System.Xml;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Http.Extensions;
using Sustainsys.Saml2;
using Sustainsys.Saml2.AspNetCore2;
using Sustainsys.Saml2.Saml2P;
using Sustainsys.Saml2.WebSso;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// A logout request the identity provider signs ends the session only when it is addressed to this server's
/// single logout address, its NotOnOrAfter time, if it has one, has not passed, and its NameID names the member
/// whose session the browser holds. The NameID rule is the one Spring Security applies in the Java
/// implementation. Any other request is answered with a signed logout response whose status is Requester, and
/// the session stays, so a genuine logout request for one member replayed from another member's browser, taken
/// from another server or sent late signs nobody out. With no session there is nobody to compare the NameID
/// against, and a request that passes the other checks is answered with Success. The session index is not
/// compared, as Java does not compare it. The SAML handler itself refuses a request that is not signed with the
/// identity provider's key. Nothing else that reaches the single logout address ends the session, so a link to
/// it from another site cannot sign a member out.
///
/// <para>The SAML handler runs before the session cookie is read, so it never sees the member, and the logout
/// response it makes cannot be changed afterwards. So the session is read here before the handler runs, and a
/// refused request's response is made again with the Requester status and put in place of the handler's.</para>
/// </summary>
public static class ProviderLogoutRequest
{
    private const string NotAddressedHere = "it was not addressed to this server's single logout address";

    private const string Expired = "its NotOnOrAfter time had passed";

    private const string NamesAnotherMember = "it named a different member from the one signed in";

    /// <summary>
    /// What one request to the single logout address carries from the session to the handler and back.
    /// </summary>
    private sealed class Exchange(ClaimsPrincipal? session, Uri logoutAddress)
    {
        public ClaimsPrincipal? Session { get; } = session;

        /// <summary>This server's single logout address, as the SAML handler advertises it in its metadata.</summary>
        public Uri LogoutAddress { get; } = logoutAddress;

        /// <summary>The logout request as the identity provider sent it, before the handler reads it.</summary>
        public XmlElement? Message { get; set; }

        public bool EndsSession { get; set; }

        public string? RefusalReason { get; set; }

        public CommandResult? Refusal { get; set; }
    }

    private static readonly AsyncLocal<Exchange?> Current = new();

    /// <summary>
    /// Reads the session of a request to the single logout address before the SAML handler runs, and logs a
    /// refused logout request. Placed ahead of UseAuthentication, which is where the handler runs.
    /// </summary>
    public static void UseSessionForProviderLogout(this IApplicationBuilder app, string modulePath)
    {
        app.Use(async (context, next) =>
        {
            if (!SamlLogoutPath.Matches(context.Request.Path, modulePath))
            {
                await next();
                return;
            }

            var session = await context.AuthenticateAsync(CookieAuthenticationDefaults.AuthenticationScheme);
            var exchange = new Exchange(session.Principal, LogoutAddress(context.Request, modulePath));
            Current.Value = exchange;
            await next();

            if (exchange.Refusal is not null)
            {
                context.RequestServices.GetRequiredService<ILoggerFactory>()
                    .CreateLogger(typeof(ProviderLogoutRequest).FullName!)
                    .LogWarning(
                        "A SAML logout request was refused because {Reason}, so the session was left as it was.",
                        exchange.RefusalReason);
            }
        });
    }

    /// <summary>
    /// The single logout address the SAML handler advertises, made from the request's scheme, host and path base
    /// the same way the handler makes it.
    /// </summary>
    private static Uri LogoutAddress(HttpRequest request, string modulePath) =>
        new Saml2Urls(new Uri(UriHelper.BuildAbsolute(request.Scheme, request.Host, request.PathBase)), modulePath)
            .LogoutUrl;

    /// <summary>
    /// Sets the SAML handler's hooks that keep the request as sent, compare it with the session and act on the
    /// outcome.
    /// </summary>
    public static void Configure(Saml2Options options)
    {
        options.Notifications.MessageUnbound = Keep;
        options.Notifications.LogoutResponseCreated =
            (response, request, _, identityProvider) => Compare(response, request, identityProvider, options);
        options.Notifications.LogoutCommandResultCreated = Apply;
    }

    private static void Keep(UnbindResult unbound)
    {
        if (Current.Value is { } exchange)
        {
            exchange.Message = unbound.Data;
        }
    }

    private static void Compare(
        Saml2LogoutResponse response,
        Saml2LogoutRequest request,
        IdentityProvider identityProvider,
        Saml2Options options)
    {
        var exchange = Current.Value;
        if (exchange is null)
        {
            return;
        }

        exchange.RefusalReason = ReasonToRefuse(request, exchange);
        if (exchange.RefusalReason is null)
        {
            exchange.EndsSession = true;
            return;
        }

        var refusal = new Saml2LogoutResponse(Saml2StatusCode.Requester)
        {
            DestinationUrl = response.DestinationUrl,
            SigningCertificate = response.SigningCertificate,
            SigningAlgorithm = response.SigningAlgorithm,
            InResponseTo = response.InResponseTo,
            Issuer = response.Issuer,
            RelayState = response.RelayState,
        };
        exchange.Refusal = Saml2Binding.Get(identityProvider.SingleLogoutServiceBinding)
            .Bind(refusal, options.SPOptions.Logger, options.Notifications.LogoutResponseXmlCreated);
    }

    /// <summary>Why the logout request is refused, or null when it ends the session.</summary>
    private static string? ReasonToRefuse(Saml2LogoutRequest request, Exchange exchange)
    {
        if (!IsAddressedHere(exchange))
        {
            return NotAddressedHere;
        }

        if (HasExpired(exchange.Message))
        {
            return Expired;
        }

        return NamesTheSignedInMember(request, exchange.Session) ? null : NamesAnotherMember;
    }

    /// <summary>
    /// Whether the request's Destination is this server's single logout address. One with no Destination is not,
    /// since a signed request has to name where it is going.
    /// </summary>
    private static bool IsAddressedHere(Exchange exchange)
    {
        var destination = exchange.Message?.GetAttribute("Destination");
        return Uri.TryCreate(destination, UriKind.Absolute, out var address) && address == exchange.LogoutAddress;
    }

    /// <summary>
    /// Whether the request's NotOnOrAfter time has passed, with no allowance for clock difference, as the PHP
    /// implementation checks it. One with no NotOnOrAfter has not expired, and one whose time cannot be read has.
    /// </summary>
    private static bool HasExpired(XmlElement? message)
    {
        var notOnOrAfter = message?.GetAttribute("NotOnOrAfter");
        if (string.IsNullOrEmpty(notOnOrAfter))
        {
            return false;
        }

        try
        {
            return DateTime.UtcNow >= XmlConvert.ToDateTime(notOnOrAfter, XmlDateTimeSerializationMode.Utc);
        }
        catch (FormatException)
        {
            return true;
        }
    }

    /// <summary>
    /// Whether the request's NameID is the one the member signed in with, compared exactly, as Java compares it.
    /// With no session it is taken as named, because there is no member it could name wrongly.
    /// </summary>
    private static bool NamesTheSignedInMember(Saml2LogoutRequest request, ClaimsPrincipal? session)
    {
        if (session?.Identity?.IsAuthenticated != true)
        {
            return true;
        }

        var signedIn = session.FindFirst(Saml2ClaimTypes.LogoutNameIdentifier)?.ToSaml2NameIdentifier().Value;
        return signedIn is not null && string.Equals(signedIn, request.NameId?.Value, StringComparison.Ordinal);
    }

    /// <summary>
    /// Ends the session only for a logout request that passed every check, and sends the Requester response in
    /// place of the handler's for one that did not.
    /// </summary>
    private static void Apply(CommandResult result)
    {
        var exchange = Current.Value;
        result.TerminateLocalSession = exchange?.EndsSession == true;

        if (exchange?.Refusal is { } refusal)
        {
            result.HttpStatusCode = refusal.HttpStatusCode;
            result.Location = refusal.Location;
            result.Content = refusal.Content;
            result.ContentType = refusal.ContentType;
        }
    }
}
