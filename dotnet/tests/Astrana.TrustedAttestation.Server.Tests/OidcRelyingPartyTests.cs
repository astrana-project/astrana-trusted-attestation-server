using System.Security.Claims;
using System.Security.Cryptography;
using Astrana.TrustedAttestation.Server.Configuration;
using Astrana.TrustedAttestation.Server.Security;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.OpenIdConnect;
using Microsoft.AspNetCore.Http;
using Microsoft.IdentityModel.JsonWebTokens;
using Microsoft.IdentityModel.Protocols.OpenIdConnect;
using Microsoft.IdentityModel.Tokens;

namespace Astrana.TrustedAttestation.Server.Tests;

/// <summary>
/// What the OpenID Connect relying party accepts and refuses.
///
/// The conformance suite drives a real login against the dev Keycloak, so it only ever sees the valid
/// tokens that provider mints. The tokens that matter for security are the ones an attacker sends -- for
/// another audience, expired, signed with an untrusted key -- and a real IdP will not produce those.
/// This exercises them against a signing key the test owns.
///
/// The validation itself is Microsoft's. What belongs to Astrana Trusted Attestation is the configuration
/// of it, the flow, the claim mapping and the clock-skew tolerance, so these run the same
/// <see cref="OidcRelyingParty"/> startup uses, then check both the choices it makes and how tokens fare against the parameters it produces.
/// A test that built its own parameters instead would pass even if that configuration regressed.
/// </summary>
public class OidcRelyingPartyTests
{
    private const string Authority = "https://idp.example/realms/trusted-attestation";
    private const string ClientId = "trusted-attestation";

    private static readonly RSA SigningKey = RSA.Create(2048);

    private static OpenIdConnectOptions Configured()
    {
        var options = new OpenIdConnectOptions();
        OidcRelyingParty.Configure(options, new IamOptions
        {
            Authority = Authority,
            ClientId = ClientId,
            ClientSecret = "the-client-secret",
            RequireHttpsMetadata = false,
        });

        return options;
    }

    // ------------------------------------------------------------------------------------------------
    // The choices the configuration makes
    // ------------------------------------------------------------------------------------------------

    [Fact]
    public void It_uses_the_authorization_code_flow_with_pkce()
    {
        var options = Configured();

        Assert.Equal(OpenIdConnectResponseType.Code, options.ResponseType);
        Assert.True(options.UsePkce);
    }

    [Fact]
    public void Inbound_claim_names_are_kept_as_the_idp_sent_them()
    {
        Assert.False(Configured().MapInboundClaims);
    }

    [Fact]
    public void A_nonce_is_required_so_a_token_cannot_be_replayed_into_another_login()
    {
        // The nonce ties the ID token to the authorization request this browser started; the handler
        // enforces it only while RequireNonce holds, and nothing here turns it off. The other two
        // implementations check the nonce as well (PHP in OidcClient, Java in Spring's login flow).
        Assert.True(Configured().ProtocolValidator.RequireNonce);
    }

    [Fact]
    public void The_clock_skew_is_sixty_seconds_not_microsofts_five_minute_default()
    {
        // Guarded directly: a token near its expiry must be accepted or refused at the same boundary here
        // as on the other two implementations, which tolerate 60 seconds.
        var skew = Configured().TokenValidationParameters.ClockSkew;

        Assert.Equal(TimeSpan.FromSeconds(60), skew);
        Assert.NotEqual(TimeSpan.FromMinutes(5), skew);
    }

    // ------------------------------------------------------------------------------------------------
    // How tokens fare against the parameters that configuration produces
    // ------------------------------------------------------------------------------------------------

    [Fact]
    public async Task A_current_token_for_this_client_is_accepted()
    {
        Assert.True(await IsAccepted(Token()));
    }

    [Fact]
    public async Task A_token_from_another_issuer_is_refused()
    {
        Assert.False(await IsAccepted(Token(issuer: "https://evil.example/realms/x")));
    }

    [Fact]
    public async Task A_token_for_another_audience_is_refused()
    {
        Assert.False(await IsAccepted(Token(audience: "a-different-client")));
    }

    [Fact]
    public async Task A_token_expired_within_the_clock_skew_is_accepted()
    {
        // 30 seconds past expiry, inside the 60-second tolerance.
        Assert.True(await IsAccepted(Token(expires: DateTime.UtcNow.AddSeconds(-30))));
    }

    [Fact]
    public async Task A_token_expired_beyond_the_clock_skew_is_refused()
    {
        // Two minutes past expiry, well beyond the tolerance.
        Assert.False(await IsAccepted(Token(expires: DateTime.UtcNow.AddSeconds(-120))));
    }

    [Fact]
    public async Task A_token_signed_with_an_untrusted_key_is_refused()
    {
        using var forged = RSA.Create(2048);
        Assert.False(await IsAccepted(Token(signingKey: forged)));
    }

    // ------------------------------------------------------------------------------------------------
    // The authorized-party (azp) rule -- OpenID Connect Core 3.1.3.7
    // ------------------------------------------------------------------------------------------------

    [Fact]
    public void A_single_audience_needs_no_authorized_party()
    {
        Assert.True(OidcRelyingParty.HasValidAuthorizedParty([ClientId], authorizedParty: null, ClientId));
    }

    [Fact]
    public void Multiple_audiences_with_an_authorized_party_naming_this_client_are_accepted()
    {
        // A genuine multi-audience token minted for this client carries azp == this client's id.
        Assert.True(OidcRelyingParty.HasValidAuthorizedParty(
            ["another-client", ClientId], authorizedParty: ClientId, ClientId));
    }

    [Fact]
    public void Multiple_audiences_without_an_authorized_party_are_refused()
    {
        // Several audiences and no azp is not a valid ID token: it was minted for some other client that
        // merely lists this one among its audiences. Java and PHP refuse it; so must this.
        Assert.False(OidcRelyingParty.HasValidAuthorizedParty(
            ["another-client", ClientId], authorizedParty: null, ClientId));
    }

    [Fact]
    public void Multiple_audiences_whose_authorized_party_names_a_different_client_are_refused()
    {
        Assert.False(OidcRelyingParty.HasValidAuthorizedParty(
            ["some-other-client", ClientId], authorizedParty: "some-other-client", ClientId));
    }

    // ------------------------------------------------------------------------------------------------
    // What a sign-in keeps, and when it is refused
    // ------------------------------------------------------------------------------------------------

    private static readonly AuthenticationScheme Scheme =
        new(OpenIdConnectDefaults.AuthenticationScheme, null, typeof(OpenIdConnectHandler));

    private static AuthenticationProperties EveryToken()
    {
        var properties = new AuthenticationProperties();
        properties.StoreTokens(
        [
            new AuthenticationToken { Name = "access_token", Value = "the-access-token" },
            new AuthenticationToken { Name = "id_token", Value = "the-id-token" },
            new AuthenticationToken { Name = "refresh_token", Value = "the-refresh-token" },
            new AuthenticationToken { Name = "token_type", Value = "Bearer" },
            new AuthenticationToken { Name = "expires_at", Value = "2026-10-05T12:00:00Z" },
        ]);
        return properties;
    }

    private static async Task<TicketReceivedContext> TicketReceived(params Claim[] claims)
    {
        var options = Configured();
        var principal = new ClaimsPrincipal(new ClaimsIdentity(claims, "oidc"));
        var context = new TicketReceivedContext(
            new DefaultHttpContext(), Scheme, options, new AuthenticationTicket(principal, EveryToken(), Scheme.Name));

        await options.Events.TicketReceived(context);
        return context;
    }

    [Fact]
    public async Task Only_the_id_token_survives_into_the_session()
    {
        // The handler stores the tokens after OnTokenValidated has run, so the filter has to sit in
        // OnTicketReceived to see them. Sign-out needs the ID token as id_token_hint; nothing needs the rest,
        // and a cookie is no place to park an access or refresh token nobody will use.
        var context = await TicketReceived(new Claim("sub", "alice"));

        var properties = context.Properties!;
        var kept = properties.GetTokens().Select(token => token.Name).ToList();
        Assert.Equal(["id_token"], kept);
        Assert.Equal("the-id-token", properties.GetTokenValue("id_token"));
        Assert.Null(properties.GetTokenValue("access_token"));
        Assert.Null(properties.GetTokenValue("refresh_token"));
        Assert.Null(properties.GetTokenValue("token_type"));
        Assert.Null(properties.GetTokenValue("expires_at"));
        Assert.Null(context.Result);
    }

    public static TheoryData<Claim[]> NoUsableSubject => new()
    {
        Array.Empty<Claim>(),                                           // missing
        new[] { new Claim("sub", "") },                                 // empty
        new[] { new Claim("sub", "   ") },                              // whitespace
        new[] { new Claim("sub", "a"), new Claim("sub", "b") },         // an array, which arrives multivalued
        new[] { new Claim("subject", "alice") },                        // the wrong claim
    };

    [Theory]
    [MemberData(nameof(NoUsableSubject))]
    public async Task A_token_with_no_usable_subject_is_refused_and_lands_where_a_failed_sign_in_lands(Claim[] claims)
    {
        // No session: the response is handled here, so the handler never signs the principal in, and the
        // member goes to the page carrying the error marker, the same place PHP sends a failed sign-in.
        var context = await TicketReceived(claims);

        Assert.True(context.Result?.Handled);
        Assert.Equal(StatusCodes.Status302Found, context.Response.StatusCode);
        Assert.Equal(SignInFailure.Path, context.Response.Headers.Location);
    }

    [Fact]
    public async Task The_configured_subject_claim_is_the_one_that_has_to_be_usable()
    {
        var options = new OpenIdConnectOptions();
        OidcRelyingParty.Configure(options, new IamOptions
        {
            Authority = Authority,
            ClientId = ClientId,
            ClientSecret = "the-client-secret",
            SubjectClaim = "employee_number",
        });

        var principal = new ClaimsPrincipal(new ClaimsIdentity([new Claim("sub", "opaque")], "oidc"));
        var context = new TicketReceivedContext(
            new DefaultHttpContext(), Scheme, options, new AuthenticationTicket(principal, EveryToken(), Scheme.Name));

        await options.Events.TicketReceived(context);

        Assert.True(context.Result?.Handled);
        Assert.Equal(SignInFailure.Path, context.Response.Headers.Location);
    }

    [Fact]
    public async Task A_failure_at_the_callback_lands_where_a_failed_sign_in_lands_with_no_session()
    {
        // A provider error, a state that matches nothing this browser started, or a token that failed
        // validation: the handler reports each as a remote failure, which would otherwise escape as an
        // exception. The member is redirected instead, and nothing is signed in.
        var options = Configured();
        var context = new RemoteFailureContext(
            new DefaultHttpContext(), Scheme, options, new Exception("Correlation failed."));

        await options.Events.RemoteFailure(context);

        Assert.True(context.Result?.Handled);
        Assert.Equal(StatusCodes.Status302Found, context.Response.StatusCode);
        Assert.Equal(SignInFailure.Path, context.Response.Headers.Location);
        Assert.DoesNotContain("Correlation", context.Response.Headers.Location.ToString(), StringComparison.Ordinal);
    }

    // ------------------------------------------------------------------------------------------------
    // Helpers -- the configured parameters, and forged tokens
    // ------------------------------------------------------------------------------------------------

    private static TokenValidationParameters ValidationParameters()
    {
        // The parameters as configured, with the three things the handler otherwise fills in from the
        // provider's metadata at runtime supplied here instead: the issuer, the audience, and the key.
        var parameters = Configured().TokenValidationParameters.Clone();
        parameters.ValidIssuer = Authority;
        parameters.ValidAudience = ClientId;
        parameters.IssuerSigningKey = new RsaSecurityKey(SigningKey);
        parameters.ValidateIssuerSigningKey = true;

        return parameters;
    }

    private static async Task<bool> IsAccepted(string token)
    {
        var result = await new JsonWebTokenHandler().ValidateTokenAsync(token, ValidationParameters());

        return result.IsValid;
    }

    private static string Token(
        string issuer = Authority, string audience = ClientId, DateTime? expires = null, RSA? signingKey = null)
    {
        // A token is issued before it expires, so its iat/nbf are anchored ahead of exp -- otherwise an
        // "expired" token would be one issued after it expired, rejected on that ground rather than on
        // the clock-skew boundary these cases are about.
        var exp = expires ?? DateTime.UtcNow.AddMinutes(5);

        return new JsonWebTokenHandler().CreateToken(new SecurityTokenDescriptor
        {
            Issuer = issuer,
            Audience = audience,
            Subject = new ClaimsIdentity([new Claim("sub", "member-1")]),
            NotBefore = exp.AddMinutes(-5),
            IssuedAt = exp.AddMinutes(-5),
            Expires = exp,
            SigningCredentials = new SigningCredentials(
                new RsaSecurityKey(signingKey ?? SigningKey), SecurityAlgorithms.RsaSha256),
        });
    }
}
