"""Browser calendar date-only regression; Node VM does not replace browser E2E."""
import json
import os
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = r'''
const fs = require('fs');
const vm = require('vm');
const mode = process.argv[2];
const context = {window: {}, WBWS_CALENDAR_MODE: mode,
  document: {readyState: 'loading', addEventListener: () => {}}};
vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), context);
const c = context.window.WBWSCalendar;
console.log(JSON.stringify({
  ec: c.toEthiopian('2023-09-12'),
  display: c.formatDate('2023-09-12', 'short'),
  datetime: c.formatDateTime('2023-09-12'),
  instant: c.toEthiopian('2023-09-12T00:00:00Z'),
  invalid: c.formatDate('not-a-date', 'short')
}));
'''


@pytest.mark.parametrize("timezone", ["UTC", "Africa/Addis_Ababa", "America/Los_Angeles"])
@pytest.mark.parametrize("mode", ["ethiopian", "gregorian"])
def test_date_only_is_a_civil_day_in_each_browser_timezone(timezone, mode):
    env = dict(os.environ, TZ=timezone)
    run = subprocess.run(
        ["node", "-e", SCRIPT, str(ROOT / "admin/js/wbws-calendar.js"), mode],
        check=True, capture_output=True, text=True, env=env, timeout=10,
    )
    value = json.loads(run.stdout)
    assert value["ec"] == {"year": 2016, "month": 1, "day": 1}
    assert value["display"] == ("01/01/2016" if mode == "ethiopian" else "12/09/2023")
    assert value["datetime"] == ("Mes 1, 2016 00:00" if mode == "ethiopian" else "12 Sept 2023 00:00")
    # Explicit instants continue to use the browser-local date.
    expected_instant = ({"year": 2015, "month": 13, "day": 6}
                        if timezone == "America/Los_Angeles"
                        else {"year": 2016, "month": 1, "day": 1})
    assert value["instant"] == expected_instant
    assert value["invalid"] == "not-a-date"


@pytest.mark.parametrize("timezone", ["UTC", "Africa/Addis_Ababa", "America/Los_Angeles"])
def test_browser_conversion_matches_php_for_each_day_in_tested_range(timezone):
    php_script = r'''require $argv[1];
    $date = new DateTimeImmutable('2000-01-01', new DateTimeZone('Africa/Addis_Ababa'));
    $end = new DateTimeImmutable('2100-01-01', new DateTimeZone('Africa/Addis_Ababa'));
    $out = [];
    for (; $date < $end; $date = $date->modify('+1 day')) {
        $out[] = gregorian_to_ethiopian($date->format('Y-m-d'));
    }
    echo json_encode($out, JSON_THROW_ON_ERROR);'''
    node_script = r'''
    const fs = require('fs'), vm = require('vm');
    const context = {window: {}, WBWS_CALENDAR_MODE: 'ethiopian',
      document: {readyState: 'loading', addEventListener: () => {}}};
    vm.runInNewContext(fs.readFileSync(process.argv[1], 'utf8'), context);
    const out = [], c = context.window.WBWSCalendar;
    for (let t = Date.UTC(2000, 0, 1); t < Date.UTC(2100, 0, 1); t += 86400000) {
      out.push(c.toEthiopian(new Date(t).toISOString().slice(0, 10)));
    }
    console.log(JSON.stringify(out));'''
    php = subprocess.run(
        ['php', '-r', php_script, str(ROOT / 'admin/backend/ethiopian_date.php')],
        check=True, capture_output=True, text=True, timeout=30,
    )
    node = subprocess.run(
        ['node', '-e', node_script, str(ROOT / 'admin/js/wbws-calendar.js')],
        check=True, capture_output=True, text=True, timeout=30,
        env=dict(os.environ, TZ=timezone),
    )
    expected, actual = json.loads(php.stdout), json.loads(node.stdout)
    assert len(expected) == len(actual) == 36525
    assert actual == expected
