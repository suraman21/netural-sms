"""School calendar single-display regression; synthetic browser, no app DB."""
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
CHROME = shutil.which('chromium') or shutil.which('chromium-browser')
PAGE = ROOT / 'admin/school_calendar.php'
WBWS = ROOT / 'admin/js/wbws-calendar.js'
CALENDAR = ROOT / 'admin/js/school_calendar.js'


@unittest.skipUnless(os.environ.get('SSMS_STRUCTURE_FIXTURE') == '1' and os.environ.get('SSMS_AUDIT_TESTING') == '1', 'explicit isolated app fixture required')
class CalendarShellHttp(unittest.TestCase):
    def test_authenticated_education_shell(self):
        from test_attendance_phase1 import BASE, WebSession, _live_ready
        if not _live_ready(): self.fail('isolated fixture is unavailable')
        status, body = WebSession('edu_dept')._open(BASE + '/admin/school_calendar.php')
        self.assertEqual(status, 200)
        self.assertIn(b'From (Ethiopian calendar)', body)
        self.assertNotIn(b'From (Gregorian)', body)
        self.assertIn(b'id="sc-h-submit" type="submit" disabled', body)


class CalendarShellStatic(unittest.TestCase):
    def test_holiday_controls_fail_closed_in_uninitialized_shell(self):
        html = PAGE.read_text(encoding='utf-8')
        self.assertNotIn('From (Gregorian)', html)
        self.assertNotIn('To (Gregorian', html)
        self.assertIn('From (Ethiopian calendar)', html)
        self.assertIn('id="sc-h-from" type="date" hidden disabled', html)
        self.assertIn('id="sc-h-to" type="date" hidden disabled', html)
        self.assertIn('id="sc-h-submit" type="submit" disabled', html)


@unittest.skipUnless(CHROME, 'Chromium required')
class CalendarBrowserSafety(unittest.TestCase):
    def run_synthetic(self, mode):
        html = '''<!doctype html><html><head><meta charset="utf-8"><title>WAIT</title></head><body>
<div class="sc-wrap"><select id="sc-year"></select><select id="sc-month"></select><select id="sc-class"><option value="0">All</option></select>
<div id="sc-monthstrip"></div><div id="sc-gridtitle"></div><div id="sc-weekdays"></div><div id="sc-days"></div><div id="sc-legend"></div>
<ul id="sc-holidays"></ul><ul id="sc-dayoffs"></ul><div id="sc-unconfigured"></div>
<form id="sc-holiday-form"><input id="sc-h-title" value="Fixture"><input id="sc-h-title-am"><label>From (Ethiopian calendar)<input id="sc-h-from" type="date" hidden disabled></label><label>To (Ethiopian calendar)<input id="sc-h-to" type="date" hidden disabled></label><p id="sc-date-status"></p><select id="sc-h-type"><option value="holiday">holiday</option></select><button id="sc-h-submit" type="submit" disabled>Add</button></form></div>
<div id="sc-dialog-host"></div><div id="sc-toast-host"></div>
<script>
window.SCHOOL_CALENDAR_CFG={api:'/fixture',canWrite:true,csrfToken:'fixture',today:'2000-01-01'};
window.WBWS_CALENDAR_MODE='__MODE__'; window.saved=null;
window.fetch=async function(url,opts){let action=new URL(url,'http://example.invalid').searchParams.get('action');
if(opts.method==='POST'){action=opts.body.get('action'); window.saved={from:opts.body.get('date_from'),to:opts.body.get('date_to'),action};}
return {status:200,json:async()=>({status:'success',classes:[],holidays:[],day_offs:[],unconfigured_classes:[],school_days:{},weekday_class_counts:{},message:'ok'})};};
window.DangerConfirm={ask:async o=>({result:await o.onConfirm()})};
</script>__WBWS__<script>__CALENDAR__</script><script>
const wait=()=>new Promise(r=>setTimeout(r,50));
(async()=>{await wait();await wait(); const $=id=>document.getElementById(id);
if('__CASE__'!=='ethiopian'){
 document.title=$('sc-h-submit')===null && document.querySelector('.sc-wrap').textContent.includes('unavailable')?'PASS':'FAIL'; return;
}
let from=$('sc-h-from'),to=$('sc-h-to'),button=$('sc-h-submit');
let initialized=from.type==='hidden' && to.type==='hidden' && !from.disabled && !button.disabled;
from.value='2023-09-12';to.value='2023-09-12';
let display=from.parentNode.querySelector('input[data-for="sc-h-from"]');
let converted=display && display.value.includes('2016') && !display.value.includes('2023');
let day=document.querySelector('.sc-day.clickable');let iso=day.getAttribute('data-date');day.click();
let dialog=$('sc-dialog-host').textContent;let safe=dialog.includes('ዓ.ም.') && !dialog.includes(iso);
$('sc-holiday-form').dispatchEvent(new Event('submit',{cancelable:true}));await wait();await wait();
let payload=window.saved && saved.action==='save_holiday' && saved.from==='2023-09-12' && saved.to==='2023-09-12';
document.title=initialized&&converted&&safe&&payload?'PASS':'FAIL';
})().catch(e=>{document.title='FAIL';document.body.setAttribute('data-error',String(e));});
</script></body></html>'''
        html = html.replace('__MODE__', 'gregorian' if mode == 'gregorian' else 'ethiopian')
        html = html.replace('__CASE__', mode)
        html = html.replace('__WBWS__', '' if mode == 'missing' else '<script>'+WBWS.read_text(encoding='utf-8')+'</script>')
        html = html.replace('__CALENDAR__', CALENDAR.read_text(encoding='utf-8'))
        with tempfile.TemporaryDirectory(prefix='calendar-display-') as tmp:
            path = Path(tmp)/'test.html'; path.write_text(html, encoding='utf-8')
            p = subprocess.run([CHROME, '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                                '--host-resolver-rules=MAP * ~NOTFOUND','--user-data-dir='+tmp+'/profile',
                                '--virtual-time-budget=2500','--dump-dom',path.as_uri()],
                               capture_output=True,text=True,timeout=30,env=dict(os.environ,TZ='Africa/Addis_Ababa'))
            self.assertEqual(p.returncode, 0, p.stderr[-1200:])
            self.assertIsNotNone(re.search(r'<title>PASS</title>',p.stdout),p.stdout[-3000:])

    def test_ethiopian_picker_dialog_and_payload(self): self.run_synthetic('ethiopian')
    def test_wrong_calendar_mode_fails_closed(self): self.run_synthetic('gregorian')
    def test_missing_calendar_library_fails_closed(self): self.run_synthetic('missing')
