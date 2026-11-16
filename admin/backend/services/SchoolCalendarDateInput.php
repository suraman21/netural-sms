<?php
/**
 * Date-only conversion for a future school-mode-aware input boundary.
 * Does not read configuration, authorize, render, or handle stale revisions.
 * The caller MUST revalidate the trusted mode and revision before writing.
 * Dormant: legacy calendar helpers disagree starting 2099-09-11. Do not wire
 * this into live dates until every consumer uses an agreed conversion.
 */
namespace App\Services;

require_once __DIR__ . '/SchoolCalendarMode.php';

final class SchoolCalendarDateInput
{
    private const ETHIOPIAN_JDN_BASE = 1723856;
    private const UNIX_EPOCH_JDN = 2440588;

    /** Convert integer components in exactly the provided mode to civil ISO. */
    public static function toCivil(SchoolCalendarMode $mode, mixed $year, mixed $month, mixed $day): string
    {
        if (!is_int($year) || !is_int($month) || !is_int($day)) {
            throw new \InvalidArgumentException('Integer date components are required.');
        }
        if ($mode === SchoolCalendarMode::Gregorian) {
            if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
                throw new \InvalidArgumentException('Invalid date.');
            }
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 13) {
            throw new \InvalidArgumentException('Invalid date.');
        }
        $days = $month === 13 ? ($year % 4 === 3 ? 6 : 5) : 30;
        if ($day < 1 || $day > $days) throw new \InvalidArgumentException('Invalid date.');
        $jdn = self::ETHIOPIAN_JDN_BASE + 365 * $year + intdiv($year, 4) + 30 * ($month - 1) + $day - 1;
        $date = self::epoch()->modify(($jdn - self::UNIX_EPOCH_JDN) . ' days');
        if (!$date instanceof \DateTimeImmutable) throw new \InvalidArgumentException('Date out of range.');
        $civil = $date->format('Y-m-d');
        if (!self::validCivil($civil)) throw new \InvalidArgumentException('Date out of range.');
        return $civil;
    }

    /** @return array{year:int,month:int,day:int} Components only in the specified mode. */
    public static function fromCivil(SchoolCalendarMode $mode, string $civil): array
    {
        if (!self::validCivil($civil)) throw new \InvalidArgumentException('Invalid civil date.');
        [$year, $month, $day] = array_map('intval', explode('-', $civil));
        if ($mode === SchoolCalendarMode::Gregorian) return compact('year', 'month', 'day');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $civil, new \DateTimeZone('UTC'));
        if (!$date) throw new \InvalidArgumentException('Invalid civil date.');
        $offset = (int)self::epoch()->diff($date)->format('%r%a');
        $n = self::UNIX_EPOCH_JDN + $offset - self::ETHIOPIAN_JDN_BASE;
        if ($n < 365) throw new \InvalidArgumentException('Date out of range.');
        $year = intdiv(4 * $n + 3, 1461);
        if ($year < 1 || $year > 9999) throw new \InvalidArgumentException('Date out of range.');
        $dayOfYear = $n - (365 * $year + intdiv($year, 4));
        $month = intdiv($dayOfYear, 30) + 1;
        $day = $dayOfYear % 30 + 1;
        return compact('year', 'month', 'day');
    }

    private static function validCivil(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)) return false;
        return (int)$m[1] >= 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    private static function epoch(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('1970-01-01', new \DateTimeZone('UTC'));
    }
}
