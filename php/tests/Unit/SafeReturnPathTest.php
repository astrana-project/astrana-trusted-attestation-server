<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\SafeReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The open-redirect guard on the post-login ?next target.
 *
 * This is the security-critical half of the login redirect: a crafted next value must never send a member
 * off-site after they authenticate. The rule is easy to get subtly wrong -- "//evil" and "/\evil" both look
 * local but a browser follows them away -- and a regression here is invisible to a black-box test that only
 * checks the happy path, so every refusal is pinned by value.
 */
final class SafeReturnPathTest extends TestCase
{
    #[Test]
    public function a_local_path_is_kept(): void
    {
        self::assertSame('/me', SafeReturnPath::sanitize('/me'));
    }

    #[Test]
    public function a_local_path_keeps_its_query_string(): void
    {
        self::assertSame('/me?error=state', SafeReturnPath::sanitize('/me?error=state'));
    }

    /**
     * @return list<array{mixed}>
     */
    public static function offSiteOrMalformed(): array
    {
        return [
            'protocol-relative //' => ['//evil.example/path'],
            'protocol-relative /\\' => ['/\\evil.example'],
            'absolute https' => ['https://evil.example'],
            'absolute http' => ['http://evil.example'],
            'scheme-only' => ['javascript:alert(1)'],
            'no leading slash' => ['me'],
            'empty string' => [''],
            'not a string (null)' => [null],
            'not a string (array)' => [['/me']],
            'not a string (int)' => [123],
            'a tab' => ["/me\t"],
            'a line feed, which would end the Location header early' => ["/me\nSet-Cookie: x=y"],
            'a carriage return' => ["/me\r"],
            'a NUL' => ["/me\0"],
            'DEL (0x7F)' => ["/me\x7F"],
        ];
    }

    #[Test]
    public function a_character_outside_ascii_is_not_a_control_character(): void
    {
        // Only the characters below 0x20 and 0x7F are refused. A path with a non-ASCII letter is unusual
        // but local, and the other two implementations keep it.
        self::assertSame('/café', SafeReturnPath::sanitize('/café'));
    }

    #[Test]
    #[DataProvider('offSiteOrMalformed')]
    public function anything_not_a_local_path_falls_back(mixed $candidate): void
    {
        self::assertSame('/me', SafeReturnPath::sanitize($candidate));
    }

    #[Test]
    public function the_fallback_is_configurable(): void
    {
        // Used by the SAML entry point, which sends an unusable next to its own login rather than /me.
        self::assertSame('/auth/saml/login', SafeReturnPath::sanitize('//evil', '/auth/saml/login'));
    }

    #[Test]
    public function a_backslash_anywhere_in_the_path_falls_back(): void
    {
        // A backslash deeper in the path is refused too, as the other two implementations refuse it, so the
        // three answer the same return path the same way.
        self::assertSame('/me', SafeReturnPath::sanitize('/a/b\\c'));
        self::assertSame('/', SafeReturnPath::sanitize('/me\\', '/'));
    }
}
