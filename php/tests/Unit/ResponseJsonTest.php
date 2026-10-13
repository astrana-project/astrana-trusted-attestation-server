<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ResponseJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a JSON answer is written, byte for byte, for the characters the .NET and Java implementations write
 * the same way.
 *
 * PHP's own json_encode escapes a slash as \/, writes every character outside ASCII as a \u escape, and
 * writes the hex digits of an escape in lower case. The other two write a slash and those characters as
 * they are, and their hex digits in upper case, so a relationship's text or a manifest's address read the
 * same on all three only when PHP writes them the same way.
 */
final class ResponseJsonTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function strings(): iterable
    {
        yield 'a slash' => ['R&D / Lab', '"R&D / Lab"'];
        yield 'an address' => ['https://acmebank.example/support', '"https://acmebank.example/support"'];
        yield 'a letter with an accent' => ['Équipe de nuit', '"Équipe de nuit"'];
        yield 'a currency sign' => ['€', '"€"'];
        yield 'Japanese' => ['日本', '"日本"'];
        yield 'Arabic' => ['عميل', '"عميل"'];
        yield 'the characters HTML treats specially' => ["<>&'+`", "\"<>&'+`\""];
        yield 'a control character' => ["a\u{1F}b", '"a\u001Fb"'];
        yield 'NUL' => ["\0", '"\u0000"'];
        yield 'the control characters with a short escape' => ["\t\n\r\u{8}\f", '"\t\n\r\b\f"'];
        yield 'a quotation mark and a backslash' => ['"\\', '"\"\\\\"'];
        yield 'a backslash before text that looks like an escape' => ['\\u001f', '"\\\\u001f"'];
    }

    #[Test]
    #[DataProvider('strings')]
    public function a_string_is_written_as_the_other_implementations_write_it(string $value, string $expected): void
    {
        self::assertSame($expected, ResponseJson::encode($value));
    }

    #[Test]
    public function an_answer_is_written_compactly_with_its_fields_in_order(): void
    {
        self::assertSame(
            '{"valid":true,"relationship_type":"employee","relationship_subtype":"R&D / Lab","status":"active"}',
            ResponseJson::encode([
                'valid' => true,
                'relationship_type' => 'employee',
                'relationship_subtype' => 'R&D / Lab',
                'status' => 'active',
            ]),
        );
    }

    #[Test]
    public function a_response_carries_the_same_bytes_as_json(): void
    {
        $response = ResponseJson::response(['relationship_subtype' => 'R&D / Lab']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('{"relationship_subtype":"R&D / Lab"}', $response->getContent());
    }
}
