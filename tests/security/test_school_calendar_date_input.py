"""Date-only adapter tests: no runtime calendar switching or user-facing UI."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which('php')

@unittest.skipUnless(PHP, 'PHP CLI required')
class SchoolCalendarDateInputTests(unittest.TestCase):
    def test_known_dates_leaps_invalid_and_exhaustive_roundtrip(self):
        script = r'''<?php
require $argv[1]; require $argv[2];
use App\Services\SchoolCalendarDateInput as D;
use App\Services\SchoolCalendarMode as M;
$n=0;
function ok($c){global $n;$n++;if(!$c) throw new Exception('check '.$n);}
function bad($f){try{$f();}catch(InvalidArgumentException|TypeError $e){ok(true);return;}throw new Exception('invalid accepted');}
ok(D::toCivil(M::Ethiopian,2015,13,6)==='2023-09-11');
ok(D::toCivil(M::Ethiopian,2016,1,1)==='2023-09-12');
ok(D::toCivil(M::Ethiopian,2017,1,1)==='2024-09-11');
ok(D::toCivil(M::Gregorian,2024,2,29)==='2024-02-29');
ok(D::toCivil(M::Gregorian,2016,1,1)!==D::toCivil(M::Ethiopian,2016,1,1));
foreach ([[2016,13,6],[2015,14,1],[2015,0,1],[2015,1,31],[0,1,1],[2015,1,0],['2015',1,1],[2015,1.0,1]] as $v) bad(fn()=>D::toCivil(M::Ethiopian,...$v));
foreach ([[1900,2,29],[2023,2,29],[2024,13,1],[0,1,1],[10000,1,1]] as $v) bad(fn()=>D::toCivil(M::Gregorian,...$v));
foreach (['2024-2-01','2024-02-30','0000-01-01','2024-01-01 00:00:00',''] as $v) bad(fn()=>D::fromCivil(M::Ethiopian,$v));
bad(fn()=>M::fromExplicitChoice(null)); bad(fn()=>M::fromExplicitChoice(''));
$dates=0;$parity=0;$knownDivergence=0;
$start=new DateTimeImmutable('1900-01-01',new DateTimeZone('UTC'));
$end=new DateTimeImmutable('2101-01-01',new DateTimeZone('UTC'));
for($dt=$start;$dt<$end;$dt=$dt->modify('+1 day')){
 $civil=$dt->format('Y-m-d');
 foreach([M::Ethiopian,M::Gregorian] as $mode){$c=D::fromCivil($mode,$civil);if(D::toCivil($mode,$c['year'],$c['month'],$c['day'])!==$civil)throw new Exception('round trip '.$civil);}
 if($civil>='2000-01-01' && $civil<'2100-01-01'){
  if ($civil < '2099-09-11') {
   if(D::fromCivil(M::Ethiopian,$civil)!==gregorian_to_ethiopian($civil))throw new Exception('legacy parity '.$civil);
   $parity++;
  } else {
   if(D::fromCivil(M::Ethiopian,$civil)===gregorian_to_ethiopian($civil))throw new Exception('unexpected legacy agreement '.$civil);
   $knownDivergence++;
  }
 }
 $dates++;
}
echo json_encode(['checks'=>$n,'dates'=>$dates,'parity'=>$parity,'known_divergence'=>$knownDivergence],JSON_THROW_ON_ERROR);
'''
        result = subprocess.run([PHP, '-r', script.removeprefix('<?php\n'), str(ROOT/'admin/backend/services/SchoolCalendarDateInput.php'), str(ROOT/'admin/backend/ethiopian_date.php')], text=True, capture_output=True, timeout=45)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'checks':25,'dates':73414,'parity':36413,'known_divergence':112})

    @unittest.skipUnless(shutil.which('node'), 'Node.js ICU required')
    def test_independent_icu_reference(self):
        php = r'''<?php
require $argv[1]; use App\Services\SchoolCalendarDateInput as D; use App\Services\SchoolCalendarMode as M;
$end = new DateTimeImmutable('2101-01-01',new DateTimeZone('UTC'));
for($d=new DateTimeImmutable('1900-01-01',new DateTimeZone('UTC'));$d<$end;$d=$d->modify('+1 day')) {
 $date=$d->format('Y-m-d'); $v=D::fromCivil(M::Ethiopian,$date);
 echo $date,',',$v['year'],',',$v['month'],',',$v['day'],"\n";
}
'''
        actual = subprocess.run([PHP,'-r',php.removeprefix('<?php\n'),str(ROOT/'admin/backend/services/SchoolCalendarDateInput.php')],capture_output=True,text=True,timeout=30,check=True)
        node = r'''const fs=require('fs');
const f=new Intl.DateTimeFormat('en-u-ca-ethiopic',{timeZone:'UTC',year:'numeric',month:'numeric',day:'numeric'});
let n=0;
for(const line of fs.readFileSync(0,'utf8').trim().split('\n')) {
 const [date,y,m,d]=line.split(',');
 let out=Object.fromEntries(f.formatToParts(new Date(date+'T00:00:00Z')).filter(x=>['year','month','day'].includes(x.type)).map(x=>[x.type,Number(x.value)]));
 if(out.year!==Number(y)||out.month!==Number(m)||out.day!==Number(d)) throw Error('ICU mismatch '+line+' '+JSON.stringify(out));
 n++;
}
console.log(n);'''
        result = subprocess.run([shutil.which('node'),'-e',node],input=actual.stdout,capture_output=True,text=True,timeout=30)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stdout.strip(),'73414')
