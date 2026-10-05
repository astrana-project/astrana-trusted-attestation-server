<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TextDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The writing direction for a resolved locale.
 *
 * Keyed on the same explicit right-to-left set the .NET and Java implementations use (ar, he, fa, ur, ps)
 * rather than on any runtime culture data, so all three emit the same dir for the same language. A consumer
 * must not be able to tell the stacks apart.
 */
final class TextDirectionTest extends TestCase
{
    public static function rightToLeft(): iterable
    {
        yield 'Arabic' => ['ar'];
        yield 'Hebrew' => ['he'];
        yield 'Persian' => ['fa'];
        yield 'Urdu' => ['ur'];
        yield 'Pashto' => ['ps'];
    }

    #[Test]
    #[DataProvider('rightToLeft')]
    public function right_to_left_languages_are_rtl(string $tag): void
    {
        self::assertSame('rtl', TextDirection::of($tag));
    }

    public static function leftToRight(): iterable
    {
        yield 'English' => ['en'];
        yield 'French' => ['fr'];
        yield 'German' => ['de'];
        yield 'Japanese' => ['ja'];
        yield 'empty' => [''];
        yield 'none' => [null];
    }

    #[Test]
    #[DataProvider('leftToRight')]
    public function everything_else_is_ltr(?string $tag): void
    {
        self::assertSame('ltr', TextDirection::of($tag));
    }

    #[Test]
    public function only_the_primary_language_subtag_decides(): void
    {
        // A region or script suffix, and either separator, resolve on the language alone.
        self::assertSame('rtl', TextDirection::of('ar-EG'));
        self::assertSame('rtl', TextDirection::of('fa_IR'));
        self::assertSame('ltr', TextDirection::of('en-GB'));
    }

    #[Test]
    public function the_language_match_is_case_insensitive(): void
    {
        self::assertSame('rtl', TextDirection::of('AR'));
        self::assertSame('rtl', TextDirection::of('He'));
    }
}
