"""Unit checks for the dormant explicit calendar-mode value type.

This does not test authorization, configuration persistence, UI or switching.
"""
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def test_only_explicit_canonical_calendar_choices_are_accepted():
    php = r'''
    require $argv[1];
    use App\Services\SchoolCalendarMode;
    $values = ['ethiopian', 'gregorian', null, '', 'both', 'EC', 'GC',
               'ethiopian,gregorian', ' Ethiopian ', 0, 1, true, [], new stdClass()];
    $out = [];
    foreach ($values as $value) {
        try {
            $out[] = ['accepted' => SchoolCalendarMode::fromExplicitChoice($value)->value];
        } catch (InvalidArgumentException $e) {
            $out[] = ['rejected' => true];
        }
    }
    echo json_encode($out, JSON_THROW_ON_ERROR);
    '''
    result = subprocess.run(
        ['php', '-r', php, str(ROOT / 'admin/backend/services/SchoolCalendarMode.php')],
        check=True, capture_output=True, text=True, timeout=10,
    )
    parsed = json.loads(result.stdout)
    assert parsed[:2] == [{'accepted': 'ethiopian'}, {'accepted': 'gregorian'}]
    assert parsed[2:] == [{'rejected': True}] * 12
