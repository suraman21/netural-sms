"""Synthetic browser smoke for standalone structure UI (no database/network)."""
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
CHROMIUM = shutil.which('chromium') or shutil.which('chromium-browser')


@unittest.skipUnless(CHROMIUM, 'Chromium required')
class StructureBrowser(unittest.TestCase):
    def test_safe_render_create_and_unknown_outcome(self):
        js = (ROOT / 'admin/js/school_structure.js').read_text(encoding='utf-8')
        html = '''<!doctype html><meta charset="utf-8"><title>WAIT</title><meta name="csrf-token" content="fixture-token">
<p id="notice"></p><div id="structure"></div><button id="refresh"></button>
<form id="create-form"><select id="kind"><option value="level">Level</option><option value="grade">Grade</option><option value="section">Section</option></select><div id="parent-wrap"><select id="parent"></select></div><input id="name" required><input id="sort-order" value="0" required><button id="submit" type="submit"></button></form>
<script>
let posts=[]; let fail=false;
window.fetch=async function(url, opts){
 if(url.includes('create')){posts.push(JSON.parse(opts.body)); if(fail) throw Error('network'); return {ok:true,status:201,json:async()=>({status:'success',id:8})};}
 return {ok:true,status:200,json:async()=>({status:'success',levels:[{id:1,name:'<img src=x onerror=alert(1)>',is_active:1}],grades:[{id:2,level_id:1,name:'Grade',is_active:1}],sections:[]})};
};
</script><script>__SCRIPT__</script><script>
const wait=()=>new Promise(r=>setTimeout(r,30));
(async()=>{await wait(); let e=document.getElementById.bind(document);
let safe=!e('structure').querySelector('img')&&e('structure').textContent.includes('<img src=x onerror=alert(1)>');
e('kind').value='grade';e('kind').dispatchEvent(new Event('change'));e('parent').value='1';e('name').value='New';
e('create-form').dispatchEvent(new Event('submit',{cancelable:true}));await wait();
let saved=posts.length===1&&posts[0].kind==='grade'&&posts[0].parent_id===1&&posts[0].name==='New';
fail=true;e('parent').value='1';e('name').value='Uncertain';e('create-form').dispatchEvent(new Event('submit',{cancelable:true}));await wait();
let blocked=e('submit').disabled&&e('notice').textContent.includes('could not be confirmed');
e('create-form').dispatchEvent(new Event('submit',{cancelable:true}));await wait();
let noRetry=posts.length===2;e('refresh').click();await wait();let reviewed=!e('submit').disabled;
document.title=safe&&saved&&blocked&&noRetry&&reviewed?'PASS':'FAIL';
})().catch(()=>document.title='FAIL');
</script>'''.replace('__SCRIPT__', js)
        with tempfile.TemporaryDirectory(prefix='structure-browser-') as tmp:
            path = Path(tmp) / 'fixture.html'
            path.write_text(html, encoding='utf-8')
            # 30s was not a margin, it was the observed runtime: CI measured
            # 30.05s on a loaded runner (the slowest test in the suite), so the
            # test failed on wall-clock, not on behaviour, and three separate CI
            # runs died here while proving nothing about the UI. --virtual-time-
            # budget already bounds the page's own execution; this bounds the
            # browser's startup, which is what actually varies on a shared runner.
            result = subprocess.run([CHROMIUM, '--headless', '--no-sandbox', '--disable-gpu',
                                     '--disable-dev-shm-usage', '--host-resolver-rules=MAP * ~NOTFOUND',
                                     '--user-data-dir='+tmp+'/profile', '--virtual-time-budget=2000',
                                     '--dump-dom', path.as_uri()], capture_output=True, text=True, timeout=120,
                                    env=dict(os.environ))
            self.assertEqual(result.returncode, 0, result.stderr[-1200:])
            self.assertIsNotNone(re.search(r'<title>PASS</title>', result.stdout), result.stdout[-2000:])
