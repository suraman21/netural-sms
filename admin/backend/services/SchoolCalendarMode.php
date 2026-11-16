<?php
/**
 * Explicit school-wide calendar choice. Validation only: callers must enforce
 * authorization and must not activate a mode until all consumers support it.
 */
namespace App\Services;

enum SchoolCalendarMode: string
{
    case Ethiopian = 'ethiopian';
    case Gregorian = 'gregorian';

    /** Accept exactly one canonical mode; missing or invalid input has no default. */
    public static function fromExplicitChoice(mixed $choice): self
    {
        if (!is_string($choice)) {
            throw new \InvalidArgumentException('An explicit calendar choice is required.');
        }
        return self::tryFrom($choice)
            ?? throw new \InvalidArgumentException('Choose either Ethiopian or Gregorian calendar.');
    }
}
