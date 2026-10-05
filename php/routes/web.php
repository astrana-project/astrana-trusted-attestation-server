<?php

declare(strict_types=1);

use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SamlController;
use App\Http\Middleware\LimitRequestBody;
use Illuminate\Support\Facades\Route;

/*
 * The member's four operations and the anonymous attestation lookup, the landing page, the self-service
 * page and the licence page, and no administrator interface.
 *
 * Everything runs in the web middleware group, including the API, because the contract's security scheme
 * is the session cookie the organisation's sign-in established, not a bearer token this server issued, so
 * the API needs the same session handling the pages do. CSRF is excluded for /api/* in bootstrap/app.php,
 * and the note there says why that is safe.
 */

// The public landing page: unauthenticated, indexable, and what the manifest's enrollment_url points
// at. It is the first thing a prospective member sees, before they have an account to sign in with, so
// it does not redirect into /me.
Route::get('/', [PageController::class, 'landing']);

// Where the IdP sends the member after logout: the same page, with the confirmation shown. A dedicated
// path rather than a query marker, because the IdP matches its registered post-logout URIs exactly.
Route::get('/signed-out', fn () => app(PageController::class)->landing(request(), true));

// The software's own licence: anonymous, like the landing page, at the fixed path the footer links to
// (contract/attribution.json). Not member-specific and not org-configurable.
Route::get('/license', [PageController::class, 'license']);

Route::get('/me', [PageController::class, 'me']);

// The language switcher posts here from any page, signed in or not. It stores the chosen locale in a plain
// cookie, validated against the offered set, and returns to the page the member was on. Exempt from CSRF in
// bootstrap/app.php: the public landing page has no session to hold a token. A cross-site form can still
// submit the switcher, and the most it changes is the display language.
Route::post('/set-language', [PageController::class, 'setLanguage']);

// One entry point regardless of protocol; AuthController sends SAML deployments on to the routes below,
// and signs a SAML member out on the POST itself.
Route::get('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/callback', [AuthController::class, 'callback']);
Route::post('/signout', [AuthController::class, 'signout']);

/*
 * SAML 2.0, for orgs whose IAM speaks it rather than OIDC, and registered only when that is the
 * configured protocol.
 *
 * Registered under OIDC, they would not sit harmlessly unused. With no SAML configuration there is no
 * client to build, so each one would fail during construction, before the controller's own try/catch
 * could turn it into a redirect, and an unauthenticated caller would get a 500 from an endpoint this
 * deployment does not implement. The service provider metadata would not be readable either, because a
 * service provider with no entity id and no keypair has nothing to publish. Not routing them says the
 * true thing, and matches what the other two implementations do.
 *
 * The assertion consumer service is exempt from CSRF in bootstrap/app.php: the IdP posts the assertion
 * cross-site by nature, and the assertion's own signature is what authenticates it.
 *
 * The single logout service answers a GET and a POST, because an IdP delivers its LogoutResponse and
 * LogoutRequest over the HTTP-Redirect binding or the HTTP-POST binding, and Keycloak posts by default. The
 * POST is exempt from CSRF in bootstrap/app.php for the same reason the assertion consumer is. On either
 * binding it acts only on a logout message whose signature verifies, never on the request alone
 * (SamlController::singleLogout). The member's own sign-out is the CSRF-protected POST /signout above.
 *
 * One operational consequence of deciding this here: `php artisan route:cache` freezes whichever
 * protocol was configured when the cache was written. Changing protocol therefore needs
 * `route:clear` (or another `route:cache`) alongside it, the same as any other config-dependent
 * route -- otherwise the login endpoints silently do not exist.
 */
if (config('trusted_attestation.iam.protocol') === 'saml') {
    Route::get('/auth/saml/login', [SamlController::class, 'login']);
    Route::post('/auth/saml/acs', [SamlController::class, 'acs']);
    Route::get('/auth/saml/metadata', [SamlController::class, 'metadata']);
    Route::match(['get', 'post'], '/auth/saml/logout', [SamlController::class, 'singleLogout']);
}

Route::get('/.well-known/ata-manifest.json', [PageController::class, 'manifest']);

/*
 * Versioned in the route so a breaking change can introduce /api/v2 alongside this without forcing every
 * deployment to upgrade in lockstep. /api with no version segment resolves to the latest, so a caller
 * that does not care about pinning never has to track version numbers.
 */
foreach (['/api/v1', '/api'] as $prefix) {
    // The body is capped at 64 kilobytes on the two operations that read one, setting a key and the
    // attestation lookup (LimitRequestBody), with HTTP 413 and no body beyond it, as on the other two
    // implementations. A key request with no member signed in is answered 401 before the cap. The body
    // is then read as JSON whatever its Content-Type says, by the controller.
    Route::prefix($prefix)->group(function (): void {
        Route::get('/me', [ApiController::class, 'me']);

        // The relationship type says which of the caller's own relationships to act on, never whose --
        // the member is still derived entirely from the session.
        //
        // Not constrained to the governed vocabulary here. A type outside it is one nobody has been
        // granted, so RelationshipService answers it with the same 404 as a governed type the caller does
        // not hold, checking the vocabulary through the catalogue, and a route constraint would put a
        // second copy of the vocabulary somewhere it could drift from relationship-types.json.
        Route::put('/me/relationships/{relationshipType}/key', [ApiController::class, 'setKey'])
            ->middleware(LimitRequestBody::class.':member');
        Route::delete('/me/relationships/{relationshipType}/key', [ApiController::class, 'delete']);
        Route::post('/me/relationships/{relationshipType}/revoke', [ApiController::class, 'selfRevoke']);

        // Anonymous by design. A 32-byte random key cannot be guessed, so holding one is itself the
        // access control; requiring callers to identify themselves would only let the org learn who is
        // asking.
        Route::post('/attest', [ApiController::class, 'attest'])->middleware(LimitRequestBody::class);
    });
}
