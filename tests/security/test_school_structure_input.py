"""CLI-only tests for the dormant academic hierarchy input validator."""
import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
PHP = shutil.which('php')


@unittest.skipUnless(PHP, 'PHP CLI is required')
class SchoolStructureInputTests(unittest.TestCase):
    def test_validator_boundaries(self):
        script = r'''<?php
require $argv[1];
use App\Services\SchoolStructureInput as V;
$n = 0;
function ok($condition) { global $n; $n++; if (!$condition) throw new Exception('check '.$n); }
function bad($fn) { try { $fn(); } catch (InvalidArgumentException $e) { ok(true); return; } throw new Exception('invalid input accepted'); }
ok(V::name('  አንደኛ  ') === 'አንደኛ');
ok(V::name('Primary') === 'Primary');
ok(V::name(str_repeat('ሀ',150)) === str_repeat('ሀ',150));
bad(fn()=>V::name(str_repeat('ሀ',151)));
foreach (['', '   ', "A\nB", "A\0B", "\xff", [], null, 12] as $v) bad(fn()=>V::name($v));
foreach ([-2147483648, 0, 2147483647] as $v) ok(V::sortOrder($v) === $v);
foreach ([-2147483649, 2147483648, '1', 1.0, true, [], null] as $v) bad(fn()=>V::sortOrder($v));
foreach ([1, PHP_INT_MAX] as $v) ok(V::parentId($v) === $v);
foreach ([0, -1, '1', 1.0, true, [], null] as $v) bad(fn()=>V::parentId($v));
echo "PASS $n\n";
'''
        result = subprocess.run([PHP, '-r', script.removeprefix('<?php\n'), str(ROOT / 'admin/backend/services/SchoolStructureInput.php')],
                                text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, 'PASS 31\n')
