<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Reads the typed settings from the environment, the way the other two implementations' configuration
 * binders do: a value that does not parse is a configuration error naming the setting, never a silent
 * default.
 *
 * Laravel's env() converts only true, false, (true), (false), empty and null. Anything else arrives as the
 * string it was typed as, so (bool) "no" is true and (int) "abc" is 0, and a retention of 0 days would
 * prune every audit row. Each reader here returns the setting's default when the value does not parse and
 * records a complaint, which the service provider turns into a refusal of every request. The default is
 * returned rather than null so the configuration keeps its declared types for the code that reads it
 * before the refusal lands.
 *
 * Framework-free: the reader is injected, so the parsing is pinned without touching the real environment.
 */
final class EnvironmentSettings
{
    /** @var list<string> */
    private array $problems = [];

    private readonly Closure $read;

    /** @param  (callable(string): mixed)|null  $read  the environment reader, env() by default */
    public function __construct(?callable $read = null)
    {
        $this->read = $read === null ? static fn (string $name): mixed => env($name) : Closure::fromCallable($read);
    }

    /** A boolean setting: 1, true, on and yes, or 0, false, off, no and the empty string, in any case. */
    public function bool(string $name, bool $default): bool
    {
        $raw = ($this->read)($name);
        if ($raw === null) {
            return $default;
        }

        $parsed = self::parseBool($raw);
        if ($parsed === null) {
            $this->problems[] = "{$name} must be true or false, was ".self::describe($raw).'.';

            return $default;
        }

        return $parsed;
    }

    /** A whole number between $min and $max inclusive. */
    public function int(string $name, int $default, int $min, int $max): int
    {
        $raw = ($this->read)($name);
        if ($raw === null) {
            return $default;
        }

        $parsed = self::parseInt($raw, $min, $max);
        if ($parsed === null) {
            $range = $max === PHP_INT_MAX ? "{$min} or more" : "{$min} to {$max}";
            $this->problems[] = "{$name} must be a whole number from {$range}, was ".self::describe($raw).'.';

            return $default;
        }

        return $parsed;
    }

    /**
     * A comma-separated list, each item trimmed and empty items dropped, so "employee, client" and
     * "employee,client" configure the same thing.
     *
     * @return list<string>
     */
    public function list(string $name): array
    {
        return self::parseList(($this->read)($name));
    }

    /**
     * A locale-keyed JSON object such as {"en":"Acme Bank","fr":"Banque Acme"}. Unset or empty means no
     * entries. Anything that is not a JSON object is a configuration error, not an empty object, so a
     * misquoted name is reported rather than served as a manifest with no name.
     *
     * @return array<string, mixed>
     */
    public function localeMap(string $name): array
    {
        $raw = ($this->read)($name);
        if ($raw === null || $raw === '') {
            return [];
        }

        $parsed = self::parseLocaleMap($raw);
        if ($parsed === null) {
            $this->problems[] = "{$name} must be a JSON object keyed by locale, for example {\"en\":\"Acme Bank\"}, was "
                .self::describe($raw).'.';

            return [];
        }

        return $parsed;
    }

    /**
     * Every value that did not parse, each naming its setting, in the order they were read.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public static function parseBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_scalar($raw)) {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    public static function parseInt(mixed $raw, int $min, int $max): ?int
    {
        if (! is_scalar($raw) || is_bool($raw)) {
            return null;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $parsed === false ? null : $parsed;
    }

    /** @return list<string> */
    public static function parseList(mixed $raw): array
    {
        if (! is_scalar($raw)) {
            return [];
        }

        $items = array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /** @return array<string, mixed>|null */
    public static function parseLocaleMap(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        // A JSON array ([...]) decodes to a list, which has no locale keys to look up.
        return array_is_list($decoded) && $decoded !== [] ? null : $decoded;
    }

    /** The value as the operator typed it, quoted, or its type when it is not text. */
    private static function describe(mixed $raw): string
    {
        return is_scalar($raw) ? '"'.(string) $raw.'"' : gettype($raw);
    }
}
