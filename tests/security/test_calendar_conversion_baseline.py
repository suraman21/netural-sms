"""Regression characterization of PHP Ethiopian date conversion boundaries.

These tests do not establish selectable calendar mode or UI consistency.
They use synthetic dates and do not connect to any application database.
"""
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "admin/backend/ethiopian_date.php"


def run_php(script: str) -> dict:
    result = subprocess.run(
        ["php", "-r", script, str(HELPER)],
        check=True,
        capture_output=True,
        text=True,
        timeout=30,
    )
    return json.loads(result.stdout)


def test_known_ethiopian_new_year_and_pagume_boundaries():
    result = run_php(
        r'''require $argv[1];
        $dates = ['2023-09-10', '2023-09-11', '2023-09-12',
                  '2024-09-10', '2024-09-11'];
        $out = [];
        foreach ($dates as $date) $out[$date] = gregorian_to_ethiopian($date);
        echo json_encode($out, JSON_THROW_ON_ERROR);'''
    )
    assert result == {
        "2023-09-10": {"year": 2015, "month": 13, "day": 5},
        "2023-09-11": {"year": 2015, "month": 13, "day": 6},
        "2023-09-12": {"year": 2016, "month": 1, "day": 1},
        "2024-09-10": {"year": 2016, "month": 13, "day": 5},
        "2024-09-11": {"year": 2017, "month": 1, "day": 1},
    }


def test_round_trip_each_date_in_documented_21st_century_range():
    result = run_php(
        r'''require $argv[1];
        $date = new DateTimeImmutable('2000-01-01', new DateTimeZone('Africa/Addis_Ababa'));
        $end = new DateTimeImmutable('2100-01-01', new DateTimeZone('Africa/Addis_Ababa'));
        $count = 0;
        $firstMismatch = null;
        for (; $date < $end; $date = $date->modify('+1 day')) {
            $ec = gregorian_to_ethiopian($date->format('Y-m-d'));
            $actual = ethiopian_to_gregorian($ec['year'], $ec['month'], $ec['day'])->format('Y-m-d');
            if ($actual !== $date->format('Y-m-d')) {
                $firstMismatch = [$date->format('Y-m-d'), $ec, $actual];
                break;
            }
            $count++;
        }
        echo json_encode(['count' => $count, 'mismatch' => $firstMismatch], JSON_THROW_ON_ERROR);'''
    )
    assert result == {"count": 36525, "mismatch": None}
