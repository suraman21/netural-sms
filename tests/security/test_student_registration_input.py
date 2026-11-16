"""Pure validation foundation; no API, role, uploads, enrollment or calendar UI."""
import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
PHP = shutil.which('php')

@unittest.skipUnless(PHP, 'PHP CLI is required')
class StudentRegistrationInputTests(unittest.TestCase):
    def test_validation_boundaries(self):
        script = r'''<?php
require $argv[1];
use App\Services\StudentRegistrationInput as V;
$n=0;
function ok($x) { global $n; $n++; if (!$x) throw new Exception('failed check '.$n); }
function bad($f) { try { $f(); } catch (InvalidArgumentException $e) { ok(true); return; } throw new Exception('invalid accepted'); }
$base=['student_name'=>'  ማርያም  ', 'father_name'=>'ተስፋዬ', 'gender'=>'female'];
$v=V::validate($base, '2026-11-14');
ok($v['student_name']==='ማርያም' && $v['father_name']==='ተስፋዬ');
ok($v['gender']==='female' && $v['birth_date']===null && $v['guardian_phone']===null);
$v=V::validate($base+['grandfather_name'=>'ሀ', 'birth_date'=>'2000-02-29', 'address'=>'Addis Ababa', 'guardian_name'=>'ሄለን', 'guardian_phone'=>'+251 911 123 456'], '2026-11-14');
ok($v['birth_date']==='2000-02-29' && $v['guardian_name']==='ሄለን' && $v['guardian_phone']==='+251 911 123 456');
ok(V::validate(array_replace($base,['birth_date'=>'2026-11-14']), '2026-11-14')['birth_date']==='2026-11-14');
ok(V::validate(array_replace($base,['student_name'=>str_repeat('ሀ',150)]), '2026-11-14')['student_name']===str_repeat('ሀ',150));
ok(V::validate(array_replace($base,['guardian_name'=>'  ', 'address'=>null]), '2026-11-14')['guardian_name']===null);
foreach (['', ' ', "A\nB", "A\0B", "\xff", str_repeat('ሀ',151), [], null, 1, true] as $v) bad(fn()=>V::validate(array_replace($base,['student_name'=>$v]), '2026-11-14'));
foreach (['', [], null] as $v) bad(fn()=>V::validate(array_replace($base,['father_name'=>$v]), '2026-11-14'));
foreach (['', 'other', 'Male', [], null, 1] as $v) bad(fn()=>V::validate(array_replace($base,['gender'=>$v]), '2026-11-14'));
bad(fn()=>V::validate(['student_name'=>'A','father_name'=>'B'], '2026-11-14'));
foreach (['2026-11-15','1900-02-29','2024-02-30','2026-1-01','0000-01-01','2026-12-99',[],42] as $v) bad(fn()=>V::validate(array_replace($base,['birth_date'=>$v]), '2026-11-14'));
foreach (['','2026-02-30','2000-2-01','0000-01-01'] as $today) bad(fn()=>V::validate($base, $today));
foreach (['+', 'abc', "0911\n12", [], 45, str_repeat('1',31)] as $v) bad(fn()=>V::validate(array_replace($base,['guardian_phone'=>$v]), '2026-11-14'));
bad(fn()=>V::validate($base+['member_type'=>'regular'], '2026-11-14'));
bad(fn()=>V::validate($base+['doc_spiritual'=>'value'], '2026-11-14'));
bad(fn()=>V::validate($base+[0=>'unexpected'], '2026-11-14'));
ok(V::validate(array_replace($base,['gender'=>'male']), '2026-11-14')['gender']==='male');
echo "PASS $n\n";
'''
        result = subprocess.run([PHP, '-r', script.removeprefix('<?php\n'), str(ROOT/'admin/backend/services/StudentRegistrationInput.php')], text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, 'PASS 48\n')
