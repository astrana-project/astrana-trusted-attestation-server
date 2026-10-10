<?php

declare(strict_types=1);

use App\Support\StaticFileRouter;

/*
 * Router for PHP's built-in web server, which the container image serves with, in the demonstration and
 * in the integration matrix alike.
 *
 *   php -S 0.0.0.0:8000 -t public server.php
 *
 * Emulates the rewrite rule a real web server applies: serve the file if it exists under public/,
 * otherwise hand the request to Laravel's front controller.
 *
 * This exists rather than `artisan serve` because that command starts the server as a child process and
 * forwards only a fixed whitelist of environment variables to it, so a container's own configuration is
 * silently dropped. It resolves paths from __DIR__ rather than the working directory, so it does not
 * care where it is invoked from.
 *
 * The built-in server suits the container, which sits behind a proxy that holds the certificate. It runs
 * one worker, and PHP's manual does not intend it for production use, so beyond a small organisation's
 * traffic the other deployment shape fits better: a web server, Apache or Nginx with PHP-FPM or a hosting
 * account's own, pointed at public/, which applies the same rewrite itself and does not use this file.
 */

// The Composer autoloader only, not the framework: index.php requires the same file, so nothing is loaded
// twice. It brings in StaticFileRouter, which decides what may be served and is pinned by its own tests.
require_once __DIR__.'/vendor/autoload.php';

$publicPath = __DIR__.'/public';

// The built-in server sends PHP's version when expose_php is on. The image's php.ini turns it off, and this
// covers the router run without that file. The other two implementations name no runtime, the application
// removes the header from its own responses too, and .htaccess and web.config remove it on a hosting account.
header_remove('X-Powered-By');

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '');

// A path that climbs with "..", in whatever encoding it arrived, and the two Laravel skeleton names the
// other implementations do not serve, name no file here. They go to the application with every other path
// that is not a file, which answers HTTP 404 - Not Found with the security headers and no Content-Type, as
// it does under .htaccess and web.config.
$file = StaticFileRouter::file($publicPath, $uri);

if ($file !== null) {
    // Static assets (the stylesheet, the favicon) bypass Laravel, so App\Http\Middleware\SecurityHeaders
    // never sets its headers on them. The built-in server discards any header a router sets when it goes on
    // to serve the file itself (return false), so to apply them the router serves the file. An unrecognised
    // extension falls back to the built-in server. This gives the container's static responses the same
    // headers Kestrel and Tomcat send on theirs, after a dynamic application security test (ZAP) found this
    // stack omitting nosniff on static files while Java sent it. Behind Apache, public/.htaccess applies the
    // same set. An Nginx or panel-managed host sets it at that layer, as it already must for HSTS, and the
    // PHP installation page shows how. Kept in step with the middleware by hand, because a router this thin
    // cannot reach a shared source without booting the framework it exists to skip.
    $contentType = StaticFileRouter::contentType($file);

    if ($contentType === null) {
        return false;
    }

    header('Content-Type: '.$contentType);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-XSS-Protection: 0');
    header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
    readfile($file);

    return true;
}

require_once $publicPath.'/index.php';
