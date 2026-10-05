<?php

use App\Http\Middleware\OpportunisticAuditPrune;
use App\Http\Middleware\PreventRequestForgeryUnlessSignedOut;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StripErrorContentType;
use App\Http\Middleware\StripSessionCookies;
use App\Support\EnvironmentSettings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
 * A request is handled as the method it arrived with. Laravel would otherwise route a POST as whatever
 * method a _method field, in the query string or a form body, or an X-HTTP-Method-Override header names.
 * A plain form on another site can send a POST without the preflight a real PUT or DELETE needs, so it
 * could set or remove a signed-in member's key. The other two implementations have no method override,
 * and nothing here uses it: the self-service page sends real PUT and DELETE requests.
 */
Request::setAllowedHttpMethodOverride([]);

return Application::configure(basePath: dirname(__DIR__))
    // No framework health route. The manifest at /.well-known/ata-manifest.json is the liveness and readiness
    // probe on every implementation, so Laravel's own /up stays unregistered and answers 404 like any other
    // unknown path rather than being a fourth probe only this stack has.
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * When a reverse proxy terminates TLS, the app has to be told which hops to believe before it can
         * trust X-Forwarded-Proto -- otherwise it generates http:// URLs behind an https:// front door.
         *
         * Read from the environment rather than config because middleware is configured before the
         * config cache is available, through the same parser config/trusted_attestation.php uses, so the
         * two never read one value two ways. A value that does not parse reads as the default here, and
         * the service provider then refuses every request naming the setting. Narrow
         * TRUSTED_ATTESTATION_TRUSTED_PROXIES to your actual proxy in production: trusting every hop is
         * how forwarded headers become spoofable.
         */
        if ((new EnvironmentSettings)->bool('TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY', false)) {
            $middleware->trustProxies(at: env('TRUSTED_ATTESTATION_TRUSTED_PROXIES', '*'));
        }

        /*
         * CSRF is excluded for the API, except the self-revoke.
         *
         * The session cookie is SameSite=Lax, which stops a cross-site page from making the browser
         * attach it to a PUT or DELETE, and these are application/json requests, which are preflighted
         * and fail CORS without a matching policy. The self-revoke is a plain POST with no body, which a
         * form on a sibling subdomain could submit with the cookie attached, so a signed-in member's
         * revoke carries the token in the X-CSRF-TOKEN header (PreventRequestForgeryUnlessSignedOut).
         * The self-service page calls the same operations any other caller would, with no privileged
         * back door of its own, which is also what lets the shared conformance suite drive all three
         * implementations identically.
         */
        /*
         * On the API an empty string is data, not an omission: a present but empty public_key is the member
         * clearing their key (ApiController::setKey). Laravel's default of turning "" into null before the
         * controller runs would make that indistinguishable from a JSON null, which is a 400.
         */
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->is('api/*'),
        ]);

        /*
         * Nor does the API trim its input. Laravel's trim strips Unicode whitespace, so a key of one
         * non-breaking space would arrive as "" and clear the key, where the contract refuses any
         * whitespace other than spaces, tabs, carriage returns and line feeds with HTTP 400. The
         * controller applies that rule itself.
         *
         * Nor does the language switcher. Its locale is matched as posted, so a padded " fr" sets no
         * cookie, as it sets none on the other two implementations. Trimmed first, it would be accepted
         * here and refused there.
         */
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/*') || $request->is('set-language'),
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',

            // The IdP posts the SAML assertion here from its own origin, so there is no session cookie
            // and no CSRF token to carry. The assertion's signature is what authenticates it, and it is
            // tied to a request this browser made by the in-response-to check in SamlController.
            'auth/saml/acs',

            // The IdP posts its single logout messages here from its own origin too, when it uses the
            // HTTP-POST binding. Safe to exempt only because nothing happens without a signature that
            // verifies against the IdP's certificate (SamlController::singleLogout): an unsigned or
            // wrongly signed POST changes nothing, whoever sent it. The member's own sign-out stays the
            // CSRF-protected POST /signout.
            'auth/saml/logout',

            // The language switcher posts here from the public landing page too, which carries no session
            // and so no token to send. Changing one's own display language is harmless, and the value is
            // validated against the offered set. A cross-site form can still submit the switcher, and the
            // most it changes is the display language. Kept identical across the three stacks, none of
            // which demands a token on this route.
            'set-language',
        ]);

        // The same check, answered 403 rather than 419, and asked of the sign-out and the self-revoke
        // only when a member is signed in. See the middleware.
        $middleware->web(replace: [
            PreventRequestForgery::class => PreventRequestForgeryUnlessSignedOut::class,
        ]);

        // The locale cookie is a plain value, not an encrypted one: the shared suite and the other two
        // implementations read and write it as the bare locale tag, and a member's browser carries the same
        // cookie whichever stack set it. The session cookie stays encrypted.
        $middleware->encryptCookies(except: [
            'ata_locale',
        ]);

        // Ahead of everything, so it wraps the session and cookie middleware from the outside: the
        // session and CSRF cookies are attached as the response passes back out through those, and only
        // an outer wrapper sees them in place to strip on the stateless, anonymous surfaces. See the
        // middleware for why /attest, the manifest and the landing page send no cookie.
        $middleware->prepend(StripSessionCookies::class);

        // Also outermost: Symfony stamps Content-Type: text/html on a body-less error deep inside the
        // pipeline (in prepare()), after the inner SecurityHeaders middleware has run, so the removal has
        // to happen from outside prepare() -- here. The other two implementations send no Content-Type on
        // an empty-bodied error; this makes a 401/404/405 look the same whichever stack served it.
        $middleware->prepend(StripErrorContentType::class);

        // Applied to every response, including the manifest and the API.
        $middleware->append(SecurityHeaders::class);

        // Audit pruning, checked on a small random fraction of requests. See the middleware for why this
        // stack schedules it this way rather than with a background process or a database scheduler.
        $middleware->append(OpportunisticAuditPrune::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Framework-raised errors answer with a status code and nothing else -- the contract reserves
         * response bodies for data. Left to Laravel, a 404 on an unknown path or a 405 on a wrong method
         * renders a page (a JSON object on the API, HTML in the browser), and with APP_DEBUG on a full
         * stack trace with it -- a body on an error and leaked internals from the anonymous endpoints.
         *
         * Every error, on the machine surfaces (api/*, .well-known/*) and on a browser path alike, returns
         * the bare, empty-bodied status. The .NET and Java implementations ship no error pages, so this is
         * what makes a 404 look identical whichever stack served it. An unexpected failure on a browser
         * path is answered the same way, a bare 500, because the rule holds in every environment and the
         * fault goes to the log, never to the page.
         *
         * The security headers are set and the Content-Type removed here as well as in their middleware.
         * A refusal raised while the application boots, the HTTP 421 for a request a proxy forwarded over
         * plain HTTP or a configuration error, is rendered by this handler before any middleware runs, and
         * it has to look like every other error. Inside the pipeline the middleware sets the same values
         * again, which changes nothing.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return StripErrorContentType::strip(SecurityHeaders::apply(response('', $status)));
        });
    })->create();
