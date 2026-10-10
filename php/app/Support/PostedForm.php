<?php

declare(strict_types=1);

namespace App\Support;

use RequestParseBodyException;

/**
 * The fields of a form a page posts, parsed by PHP when the request is captured.
 *
 * The server runs with enable_post_data_reading off (public/.user.ini), so that PHP leaves every request
 * body for the server to read and the API can refuse one over 64 kilobytes whatever its Content-Type. PHP
 * then fills no $_POST either, so RequestCapture fills it from here, for the language switcher, the sign-out
 * and the SAML messages an identity provider posts. The SAML library reads $_POST itself, which is why the
 * fields go there and not only into Laravel's request.
 *
 * PHP's own parser reads the form, request_parse_body(), with PHP's own limits, so a posted form is read
 * whole as it always was, however much larger than the API's cap it is. Only a form sent with POST is
 * parsed here. Symfony parses one sent with PUT, PATCH or DELETE as it builds the request, and a multipart
 * body stays unread: no page posts one, and the API reads its own body as JSON, whatever its Content-Type.
 */
final class PostedForm
{
    private const FORM = 'application/x-www-form-urlencoded';

    /**
     * @param  array<string, mixed>  $server  the request's server variables, as in $_SERVER
     * @param  callable(): array{0: array<array-key, mixed>, 1: array<array-key, mixed>}  $parse  request_parse_body
     * @return array<array-key, mixed>
     */
    public static function fields(array $server, callable $parse): array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? ''));
        $mediaType = strtolower(trim(explode(';', (string) ($server['CONTENT_TYPE'] ?? ''), 2)[0]));

        if ($method !== 'POST' || $mediaType !== self::FORM) {
            return [];
        }

        try {
            return $parse()[0];
        } catch (RequestParseBodyException) {
            return [];
        }
    }
}
