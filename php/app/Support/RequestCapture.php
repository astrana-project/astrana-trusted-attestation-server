<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\LimitRequestBody;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * The request the front controller (public/index.php) hands to Laravel, with its body read once and never
 * more than one byte past the API's 64 kilobyte cap.
 *
 * Laravel reads the whole body while it builds the request, before any middleware runs, to fill the input
 * of a JSON request, and reads it whole again whenever the application asks for it. A body of tens of
 * megabytes used up PHP's memory there and the request failed. Read here instead, the request carries at
 * most one byte more than the cap, which is enough for LimitRequestBody to tell a body over the cap and
 * refuse it with HTTP 413 on the two operations that read one. Nothing else needs the raw body. The forms
 * the pages and the identity provider post are parsed by PHP, whole, into $_POST (PostedForm), or by
 * Symfony as it builds the request for a form sent with PUT, PATCH or DELETE.
 *
 * PHP leaves the body unread for the server (enable_post_data_reading is off in public/.user.ini), so a
 * multipart body sent in chunks, which PHP would otherwise read itself and keep from the server, is read
 * here like any other.
 */
final class RequestCapture
{
    /** Laravel's Request::capture(), reading the body from $body, which is the request body outside tests. */
    public static function capture(string $body = 'php://input'): Request
    {
        if (! ini_get('enable_post_data_reading')) {
            $_POST = PostedForm::fields($_SERVER, request_parse_body(...));
        }

        // Symfony builds the request through this factory once it has parsed any form sent with PUT, PATCH
        // or DELETE, which PHP reads from the body first, so the body is read here after it.
        SymfonyRequest::setFactory(static fn (
            array $query, array $request, array $attributes, array $cookies, array $files, array $server,
        ): SymfonyRequest => new SymfonyRequest($query, $request, $attributes, $cookies, $files, $server, self::read($body)));

        try {
            return Request::capture();
        } finally {
            SymfonyRequest::setFactory(null);
        }
    }

    private static function read(string $body): string
    {
        $stream = fopen($body, 'rb');

        if ($stream === false) {
            return '';
        }

        try {
            return (string) stream_get_contents($stream, LimitRequestBody::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
    }
}
