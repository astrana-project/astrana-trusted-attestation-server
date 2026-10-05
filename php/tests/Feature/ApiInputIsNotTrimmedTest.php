<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The framework's input trimming leaves the API alone, so the key endpoint sees the value as sent.
 *
 * Laravel trims every input with a Unicode-aware trim, which turns a key of one non-breaking space into ""
 * before the controller runs, and "" clears the key. The contract refuses any whitespace other than
 * spaces, tabs, carriage returns and line feeds with HTTP 400, and ApiController applies that rule itself,
 * so the API must reach it untrimmed. ApiControllerTest covers the rule, and this covers the middleware
 * that sits in front of it, which a test calling the controller directly never passes through.
 */
final class ApiInputIsNotTrimmedTest extends TestCase
{
    #[Test]
    public function an_api_request_keeps_a_non_breaking_space_in_its_input(): void
    {
        $request = $this->jsonRequest('/api/v1/me/relationships/employee/key', "\u{00A0}");

        $seen = $this->passThroughTrimStrings($request);

        self::assertSame("\u{00A0}", $seen);
    }

    #[Test]
    public function a_page_request_is_still_trimmed(): void
    {
        $request = $this->jsonRequest('/me', "\u{00A0}fr ");

        $seen = $this->passThroughTrimStrings($request);

        self::assertSame('fr', $seen);
    }

    #[Test]
    public function the_language_switcher_sees_its_locale_as_posted(): void
    {
        // Matched untrimmed, so a padded " fr" sets no cookie here, as it sets none on the other two
        // implementations. SetLanguageTest covers the refusal; this covers the middleware in front of it.
        $request = $this->jsonRequest('/set-language', ' fr');

        $seen = $this->passThroughTrimStrings($request);

        self::assertSame(' fr', $seen);
    }

    private function jsonRequest(string $path, string $value): Request
    {
        return Request::create(
            $path,
            'PUT',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            content: json_encode(['public_key' => $value], JSON_THROW_ON_ERROR),
        );
    }

    private function passThroughTrimStrings(Request $request): mixed
    {
        $seen = null;

        $this->app->make(TrimStrings::class)->handle($request, function (Request $handled) use (&$seen) {
            $seen = $handled->input('public_key');

            return response('');
        });

        return $seen;
    }
}
