<?php
/** Input validation only. Not an authorization, persistence or HTML-escaping boundary. */
namespace App\Services;

final class SchoolStructureInput
{
    /** Validate an untrusted hierarchy name without restricting language. */
    public static function name(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('A valid UTF-8 name is required.');
        }
        // Reject control and surrogate characters, including embedded line breaks.
        if (preg_match('/[\p{Cc}\p{Cs}]/u', $value) === 1) {
            throw new \InvalidArgumentException('Name contains control characters.');
        }
        $name = preg_replace('/^\s+|\s+$/u', '', $value);
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('A name is required.');
        }
        $length = preg_match_all('/./us', $name);
        if ($length === false || $length > 150) {
            throw new \InvalidArgumentException('Name must not exceed 150 characters.');
        }
        return $name;
    }

    /** Accept JSON integer values only; no implicit numeric conversion. */
    public static function sortOrder(mixed $value): int
    {
        if (!is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw new \InvalidArgumentException('Sort order must be a 32-bit integer.');
        }
        return $value;
    }

    /** Positive integer IDs representable by this PHP runtime. */
    public static function parentId(mixed $value): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException('A positive integer parent ID is required.');
        }
        return $value;
    }
}
