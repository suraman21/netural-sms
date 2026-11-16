<?php
/**
 * Basic school-student input validation only. Not an authorization, storage,
 * upload, enrollment or HTML-escaping boundary. Not connected to a route.
 * Birth dates must already be converted by the caller from the school's chosen
 * calendar into canonical civil YYYY-MM-DD; this class does not choose a mode.
 */
namespace App\Services;

final class StudentRegistrationInput
{
    private const FIELDS = [
        'student_name', 'father_name', 'grandfather_name', 'gender',
        'birth_date', 'address', 'guardian_name', 'guardian_phone',
    ];

    /**
     * @param array<string,mixed> $input Basic student and guardian input.
     * @param string $today Trusted canonical civil date in the school's timezone.
     * @return array<string,?string> Normalized values; optional blanks are null.
     */
    public static function validate(array $input, string $today): array
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, self::FIELDS, true)) {
                throw new \InvalidArgumentException('Unsupported registration field.');
            }
        }
        if (!self::civilDate($today)) {
            throw new \InvalidArgumentException('A valid current date is required.');
        }
        $student = self::text($input['student_name'] ?? null, 150, true, 'Student name');
        $father = self::text($input['father_name'] ?? null, 150, true, 'Father name');
        $grandfather = self::text($input['grandfather_name'] ?? null, 150, false, 'Grandfather name');
        $gender = $input['gender'] ?? null;
        if (!is_string($gender) || !in_array($gender, ['male', 'female'], true)) {
            throw new \InvalidArgumentException('Select a valid gender.');
        }
        $birth = self::text($input['birth_date'] ?? null, 10, false, 'Birth date');
        if ($birth !== null && (!self::civilDate($birth) || strcmp($birth, $today) > 0)) {
            throw new \InvalidArgumentException('Birth date must be a valid date not in the future.');
        }
        $address = self::text($input['address'] ?? null, 250, false, 'Address');
        $guardian = self::text($input['guardian_name'] ?? null, 150, false, 'Guardian name');
        $phone = self::text($input['guardian_phone'] ?? null, 30, false, 'Guardian phone');
        if ($phone !== null && (!preg_match('/^[0-9+() .-]+$/D', $phone) || !preg_match('/[0-9]/', $phone))) {
            throw new \InvalidArgumentException('Guardian phone contains unsupported characters.');
        }
        return [
            'student_name' => $student,
            'father_name' => $father,
            'grandfather_name' => $grandfather,
            'gender' => $gender,
            'birth_date' => $birth,
            'address' => $address,
            'guardian_name' => $guardian,
            'guardian_phone' => $phone,
        ];
    }

    private static function text(mixed $value, int $max, bool $required, string $label): ?string
    {
        if ($value === null && !$required) return null;
        if (!is_string($value) || strlen($value) > 4096 || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be valid text.');
        }
        if (preg_match('/[\p{Cc}\p{Cs}]/u', $value) === 1) {
            throw new \InvalidArgumentException($label . ' contains control characters.');
        }
        $clean = preg_replace('/^\s+|\s+$/u', '', $value);
        if (!is_string($clean)) throw new \InvalidArgumentException($label . ' must be valid text.');
        if ($clean === '') {
            if ($required) throw new \InvalidArgumentException($label . ' is required.');
            return null;
        }
        $length = preg_match_all('/./us', $clean);
        if ($length === false || $length > $max) {
            throw new \InvalidArgumentException($label . ' is too long.');
        }
        return $clean;
    }

    private static function civilDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
