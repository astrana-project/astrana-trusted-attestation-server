using System.Security.Cryptography.X509Certificates;
using Astrana.TrustedAttestation.Server.Configuration;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Sustainsys.Saml2;
using Sustainsys.Saml2.AspNetCore2;
using Sustainsys.Saml2.Metadata;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Astrana Trusted Attestation keeps no user list of its own. It speaks the protocol, not the vendor, so
/// any standards-compliant provider works without custom integration. Which protocol the organisation's
/// identity system speaks is a deployment setting, not a different build. Everything downstream reads
/// claims by configured name and cannot tell which of the two produced them.
/// </summary>
public static class IamAuthentication
{
    /// <summary>
    /// Where the SAML handler listens. Set explicitly rather than left to the library default, because the
    /// failure handler (see SamlFailureHandling) is scoped to this same path and the two must not be able
    /// to drift apart.
    /// </summary>
    public const string SamlModulePath = "/Saml2";

    /// <summary>The scheme that signs a member in and out under the configured protocol.</summary>
    public static string ChallengeScheme(IamProtocol protocol) => protocol == IamProtocol.Saml
        ? Saml2Defaults.Scheme
        : OpenIdConnectDefaults.AuthenticationScheme;

    /// <summary>
    /// The session cookie and the sign-in handler for the configured protocol, and only that one. The
    /// handler of the other protocol is not registered at all, because the authentication middleware builds
    /// every registered handler on every request, and one built from settings its protocol needs and this
    /// deployment leaves empty fails its own validation and turns every page into an error.
    ///
    /// The SAML signing certificate is read here, while the application starts, rather than when the handler
    /// is first built, so a missing or unreadable one stops start-up. A relative path is resolved against
    /// <paramref name="contentRootPath"/>.
    /// </summary>
    public static void AddIamAuthentication(this IServiceCollection services, IamOptions iam, string contentRootPath)
    {
        var challengeScheme = ChallengeScheme(iam.Protocol);

        var authentication = services
            .AddAuthentication(options =>
            {
                options.DefaultScheme = CookieAuthenticationDefaults.AuthenticationScheme;
                options.DefaultChallengeScheme = challengeScheme;
                options.DefaultSignOutScheme = challengeScheme;
            })
            .AddCookie(options => SessionCookie.Configure(options, iam));

        if (iam.Protocol == IamProtocol.Saml)
        {
            var signingCertificate = SamlSigningCertificate.Load(iam.Saml, contentRootPath);
            authentication.AddSaml2(Saml2Defaults.Scheme, options => ConfigureSaml(options, iam, signingCertificate));
        }
        else
        {
            authentication.AddOpenIdConnect(options => OidcRelyingParty.Configure(options, iam));
        }
    }

    private static void ConfigureSaml(Saml2Options options, IamOptions iam, X509Certificate2? signingCertificate)
    {
        var saml = iam.Saml;

        options.SPOptions.ModulePath = SamlModulePath;

        options.SPOptions.EntityId = new EntityId(saml.EntityId);
        options.SPOptions.ReturnUrl = new Uri("/me", UriKind.Relative);
        options.SignInScheme = CookieAuthenticationDefaults.AuthenticationScheme;

        // The session a logout request from the identity provider ends. Left unset, the handler would sign
        // out of the default sign-out scheme, which is this SAML scheme itself, so the session cookie would
        // stay in place and the provider would get no answer.
        options.SignOutScheme = CookieAuthenticationDefaults.AuthenticationScheme;

        // A logout request ends the session only when it is addressed here, has not expired and names the
        // signed-in member. See ProviderLogoutRequest, whose middleware Program.cs places ahead of
        // UseAuthentication.
        ProviderLogoutRequest.Configure(options);

        // How far a SAML assertion's timestamps may be out before it is refused: 180 seconds, the same
        // value the Java (OpenSAML) and PHP (OneLogin) implementations use, so the same assertion is
        // accepted or refused whichever stack receives it. It is the low end of the three-to-five-minute
        // window the SAML ecosystem settled on (OneLogin's default): wide enough to absorb the clock drift
        // that builds up between NTP resyncs, narrow enough to keep an intercepted bearer assertion's
        // replay window tight. Deliberately looser than the 60s used for OIDC ID tokens (OidcRelyingParty),
        // which are validated the instant a fast redirect returns. Sustainsys exposes no clock-skew option;
        // this reaches the TokenValidationParameters it builds through its one hook for the purpose. The
        // hook is on the library's Unsafe surface because it can also loosen validation -- this only
        // tightens it, from the Microsoft.IdentityModel default of five minutes down to three.
        options.Notifications.Unsafe.TokenValidationParametersCreated =
            (parameters, _, _) => parameters.ClockSkew = TimeSpan.FromSeconds(180);

        // A validated assertion that names no usable subject is refused before any session exists, and
        // the member lands where a failed sign-in lands. See SignInFailure.
        options.Notifications.AcsCommandResultCreated =
            (commandResult, _) => SignInFailure.RefuseAssertionWithoutSubject(commandResult, iam);

        // Signs AuthnRequests, and lets the IdP verify them. Most IdPs advertise
        // WantAuthnRequestsSigned, and single logout needs a signature regardless.
        if (signingCertificate is not null)
        {
            options.SPOptions.ServiceCertificates.Add(signingCertificate);
        }

        // The metadata is still fetched here, when the handler is first built, as it always was.
        options.IdentityProviders.Add(
            new IdentityProvider(new EntityId(saml.IdentityProviderEntityId), options.SPOptions)
            {
                // Endpoints, bindings and the certificate assertions are verified against all come from
                // the IdP's own metadata. Nothing about the IdP is configured by hand.
                MetadataLocation = saml.IdentityProviderMetadataUrl,
                LoadMetadata = true,

                // An assertion nobody asked for is not a login. Accepting one would let anyone who can
                // reach the ACS endpoint start a session.
                AllowUnsolicitedAuthnResponse = false,
            });
    }
}
