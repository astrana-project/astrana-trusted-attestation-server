using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.Extensions.Logging.Abstractions;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// The OpenID Connect relying-party configuration.
///
/// Extracted from startup so the security-relevant choices in it, the Authorization Code with PKCE flow,
/// keeping inbound claim names as the IdP sent them, and the clock-skew tolerance, can be asserted
/// directly by a test rather than only reached through a live sign-in. The token validation itself is
/// Microsoft's. What belongs to Astrana Trusted Attestation, and what a test here guards against
/// regressing, is this configuration of it.
/// </summary>
public static class OidcRelyingParty
{
    /// <summary>Clock-skew tolerance for an ID token's exp/nbf, matched across all three implementations.</summary>
    public static readonly TimeSpan ClockSkew = TimeSpan.FromSeconds(60);

    public static void Configure(OpenIdConnectOptions options, IamOptions iam)
    {
        options.Authority = iam.Authority;
        options.ClientId = iam.ClientId;
        options.ClientSecret = iam.ClientSecret;
        options.RequireHttpsMetadata = iam.RequireHttpsMetadata;

        // Authorization Code + PKCE, and nothing else: no implicit grant, no resource-owner password
        // credentials. OAuth 2.1 is not a separate protocol to choose, it is the secure way to do OAuth 2.0.
        options.ResponseType = OpenIdConnectResponseType.Code;
        options.UsePkce = true;
        options.ResponseMode = OpenIdConnectResponseMode.FormPost;

        // The ID token is kept, and only the ID token.
        //
        // RP-initiated logout sends it back as id_token_hint, which is how the IdP knows which session is
        // ending; Keycloak and others refuse the request without it, so "sign out" would end the local
        // session and silently leave the member signed in at the IdP. Nothing downstream is called on the
        // member's behalf, so the access and refresh tokens are dropped rather than parked in the cookie.
        // Dropped in OnTicketReceived, below, because the handler stores the tokens after OnTokenValidated
        // has run: a filter there sees an empty list, and the cookie ends up carrying every token.
        options.SaveTokens = true;
        options.Events.OnTokenValidated = context =>
        {
            // OpenID Connect Core 3.1.3.7: an ID token carrying more than one audience must also carry an
            // azp (authorized party) naming this client -- otherwise it was minted for a different client
            // that merely lists this one among its audiences, and accepting it is an audience-confusion
            // hole. Microsoft's protocol validator refuses an azp that names another client, but only logs a
            // warning when more than one audience arrives with no azp, so that case is refused here,
            // matching the Java (Spring) and PHP implementations.
            // Read from the validated principal rather than the token object, whose concrete type varies
            // with the handler; MapInboundClaims is off, so the claims carry their raw names.
            var audiences = context.Principal?.FindAll("aud").Select(claim => claim.Value).ToList() ?? [];
            var authorizedParty = context.Principal?.FindFirst("azp")?.Value;
            if (!HasValidAuthorizedParty(audiences, authorizedParty, iam.ClientId))
            {
                context.Fail(
                    "The ID token has multiple audiences but no authorized party naming this client.");
            }

            return Task.CompletedTask;
        };

        // The last look at a sign-in before the cookie is written, after the tokens are stored and the
        // UserInfo claims merged, so both of these see the whole sign-in.
        options.Events.OnTicketReceived = context =>
        {
            if (context.Properties is { } properties)
            {
                properties.StoreTokens(properties.GetTokens()
                    .Where(token => token.Name == OpenIdConnectParameterNames.IdToken)
                    .ToList());
            }

            // The configured subject claim may live in UserInfo rather than the ID token, so it can only be
            // judged here. Missing, empty, whitespace or multivalued, the sign-in is refused: a session
            // without a subject is one every page and every API call would turn away.
            if (context.Principal is null || MemberIdentityResolver.ResolveSubject(context.Principal, iam) is null)
            {
                Logger(context.HttpContext).LogWarning(
                    "Refused an OpenID Connect sign-in whose claims carry no usable '{SubjectClaim}'.", iam.SubjectClaim);
                context.Response.Redirect(SignInFailure.Path);
                context.HandleResponse();
            }

            return Task.CompletedTask;
        };

        // A provider error at the callback, a state or correlation that matches nothing this browser
        // started, a token that failed validation, or the azp refusal above. Each would otherwise surface
        // as an unhandled exception. The member lands where a failed sign-in lands, with no session.
        options.Events.OnRemoteFailure = context =>
        {
            Logger(context.HttpContext).LogWarning(context.Failure, "An OpenID Connect sign-in failed.");
            context.Response.Redirect(SignInFailure.Path);
            context.HandleResponse();
            return Task.CompletedTask;
        };

        options.GetClaimsFromUserInfoEndpoint = iam.GetClaimsFromUserInfoEndpoint;

        // Keep claim names as the IdP sent them, so configuration can name a claim ("sub", "name") and mean
        // it, rather than the legacy SOAP-era URIs .NET otherwise maps to.
        options.MapInboundClaims = false;
        options.TokenValidationParameters.NameClaimType = iam.NameClaim;

        // How far an ID token's exp/nbf may be off before it is refused. Set to 60 seconds rather than
        // left at Microsoft's 5-minute default so all three implementations accept the same tokens --
        // Java's and PHP's JWT validators both tolerate 60 seconds, and a 5-minute window here would let
        // this app accept a token the other two had already rejected as expired.
        options.TokenValidationParameters.ClockSkew = ClockSkew;

        options.Scope.Clear();
        options.Scope.Add("openid");
        options.Scope.Add("profile");
        options.Scope.Add("email");
        foreach (var scope in iam.AdditionalScopes)
        {
            options.Scope.Add(scope);
        }
    }

    /// <summary>
    /// Whether an ID token's authorized party is acceptable under OpenID Connect Core 3.1.3.7: a single
    /// audience needs no azp, but more than one requires an azp equal to this client's id. Pure and
    /// separated from the handler so the rule can be asserted directly.
    /// </summary>
    internal static bool HasValidAuthorizedParty(
        IReadOnlyCollection<string> audiences, string? authorizedParty, string clientId) =>
        audiences.Count <= 1 || authorizedParty == clientId;

    /// <summary>
    /// The events run inside the authentication handler, where the only way to a logger is the request's
    /// services. A bare context under test has none, and then nothing is logged.
    /// </summary>
    private static ILogger Logger(HttpContext http) =>
        (http.RequestServices?.GetService<ILoggerFactory>() ?? NullLoggerFactory.Instance)
            .CreateLogger(typeof(OidcRelyingParty).FullName!);
}
