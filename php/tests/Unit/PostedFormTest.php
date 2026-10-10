<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PostedForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RequestParseBodyException;

/**
 * The fields of a form a page posts, parsed by PHP when the request is captured.
 *
 * PHP leaves every request body unread (enable_post_data_reading is off in public/.user.ini), so it fills
 * no $_POST either. RequestCapture fills it from here, for the language switcher, the sign-out and the SAML
 * messages an identity provider posts, which the SAML library reads from $_POST itself. PHP's own parser
 * does the work (request_parse_body), so a posted form is read as it always was. RequestCaptureTest shows
 * it through a real server.
 */
final class PostedFormTest extends TestCase
{
    private const FORM = 'application/x-www-form-urlencoded';

    private const FIELDS = ['locale' => 'fr', 'next' => '/me'];

    /** A stand-in for request_parse_body(), which answers the fields and the files of the body. */
    private static function parser(): callable
    {
        return static fn (): array => [self::FIELDS, []];
    }

    #[Test]
    public function a_posted_form_is_parsed_by_php(): void
    {
        self::assertSame(self::FIELDS, PostedForm::fields(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => self::FORM], self::parser()));
    }

    #[Test]
    public function a_form_with_a_character_set_is_parsed_too(): void
    {
        self::assertSame(
            self::FIELDS,
            PostedForm::fields(['REQUEST_METHOD' => 'post', 'CONTENT_TYPE' => 'Application/X-WWW-Form-Urlencoded; charset=UTF-8'], self::parser()),
        );
    }

    #[Test]
    public function a_form_php_refuses_to_parse_has_no_fields(): void
    {
        $refusing = static fn (): never => throw new RequestParseBodyException('Request does not provide a content type');

        self::assertSame([], PostedForm::fields(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => self::FORM], $refusing));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function requestsThatAreNotPostedForms(): iterable
    {
        yield 'a form sent with PUT' => [['REQUEST_METHOD' => 'PUT', 'CONTENT_TYPE' => self::FORM]];
        yield 'a JSON body' => [['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json']];
        yield 'a multipart body' => [['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']];
        yield 'no Content-Type' => [['REQUEST_METHOD' => 'POST']];
        yield 'no method' => [['CONTENT_TYPE' => self::FORM]];
    }

    /** @param array<string, string> $server */
    #[Test]
    #[DataProvider('requestsThatAreNotPostedForms')]
    public function anything_but_a_posted_form_is_left_unparsed(array $server): void
    {
        // Symfony parses a form sent with PUT as it builds the request. A multipart body stays unread, so
        // that the API reads it as JSON and refuses it when it is over 64 kilobytes. No page posts one.
        $parsed = false;
        $parser = static function () use (&$parsed): array {
            $parsed = true;

            return [self::FIELDS, []];
        };

        self::assertSame([], PostedForm::fields($server, $parser));
        self::assertFalse($parsed);
    }
}
