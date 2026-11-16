"""Synthetic JS behavior checks for registration response-loss messaging."""
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NODE = shutil.which('node')

@unittest.skipUnless(NODE, 'Node.js required')
class RegistrationResponseLossUI(unittest.TestCase):
    def test_hr_and_school_registration_handlers(self):
        hr = (ROOT / 'admin/dashboards/hr-dept.php').read_text()
        school = (ROOT / 'admin/dashboards/school_admin.php').read_text()
        legacy = hr.split('    function handleMemberFormSubmit(event) {', 1)[1].split('    // Advanced search', 1)[0]
        legacy = 'function handleMemberFormSubmit(event) {' + legacy
        legacy = legacy.replace("<?php echo $ajaxPrefix; ?>", '/admin/')
        main = 'function submitRegistrationForm(formData) {' + hr.split('function submitRegistrationForm(formData) {', 1)[1].split('// Initialize duplicate check on page load', 1)[0]
        quick = 'let quickAddInFlight=false;' + school.split('let quickAddInFlight=false;', 1)[1].split('// ATTENDANCE', 1)[0]
        script = r'''
const vm=require('vm'), assert=require('assert');
const sources=JSON.parse(process.argv[1]);
const tick=()=>new Promise(resolve=>setTimeout(resolve,10));
class FD { constructor(){this.values={};} append(k,v){this.values[k]=v;} has(k){return k in this.values;} }
function makeContext(kind, behavior) {
 let alerts=[], reloads=0, resets=0, calls=[], toasts=[];
 const els={memberRegistrationForm:{},formLoadingOverlay:{classList:{add(){},remove(){}}},
   memberRegistrationWrapper:{scrollIntoView(){}},quickAddButton:{disabled:false},
   quickAddModal:{classList:{remove(){}}}};
 for(const [id,value] of Object.entries({qmName:'Student',qmFather:'Father',qmGender:'male',qmPhone:'',qmSection:'',qmRegType:'waiting'})) els[id]={value};
 let ctx={console:{error(){}}, FormData:FD, CSRF_TOKEN:'csrf', CSRF:'csrf',
   document:{getElementById:id=>els[id]||null},navigator:{onLine:false},
   window:{location:{reload(){reloads++;}},SsmsRequestId:{create:()=> 'same-key'}},
   alert:m=>alerts.push(m),toast:m=>toasts.push(m),resetMemberForm(){resets++},
   showMemberSuccessToast(){},ensureRegistrationRequestId(){return 'same-key'},
   loadMembers(){},allMembers:[],
   fetch:(url,opts)=>{calls.push(opts.body.values);return behavior();}};
 vm.createContext(ctx);vm.runInContext(sources[kind],ctx);
 return {ctx,alerts,toasts,calls,els,counts:()=>({reloads,resets})};
}
const response=(status,message,ok=true)=>Promise.resolve({ok,headers:{get:()=> 'application/json'},json:()=>Promise.resolve({status,message})});
(async()=>{
 for(const kind of ['legacy','main','quick']) {
   for(const scenario of ['network','nonjson','invalid','success','explicit']) {
     const behavior=()=>scenario==='network'?Promise.reject(new Error('network')):
       scenario==='nonjson'?Promise.resolve({headers:{get:()=> 'text/html'},json:()=>Promise.reject(new SyntaxError('bad'))}):
       scenario==='invalid'?Promise.resolve({ok:true,headers:{get:()=> 'application/json'},json:()=>Promise.resolve({})}):
       scenario==='success'?response('success','saved'):response('error','Specific validation error',false);
     let t=makeContext(kind,behavior);
     const invoke=()=>kind==='legacy'?t.ctx.handleMemberFormSubmit({preventDefault(){}}):kind==='main'?t.ctx.submitRegistrationForm(new FD()):t.ctx.quickAddMember();
     await invoke(); await tick();
     const notices=kind==='quick'?t.toasts:t.alerts;
     if(['network','nonjson','invalid'].includes(scenario)) {
       assert.match(notices[0],/outcome is unknown/i,kind+' '+scenario);
       assert.match(notices[0],/check the member record/i);
       assert.equal(t.counts().reloads,0);assert.equal(t.counts().resets,0);
       if(kind==='quick'){await invoke();await tick();assert.equal(t.calls[0].registration_request_id,t.calls[1].registration_request_id);assert.equal(t.els.qmName.value,'Student');}
     } else if(scenario==='success') {
       if(kind==='quick') assert.equal(t.els.qmName.value,''); else assert.equal(t.counts().resets,1);
     } else {assert.equal(notices[0],'Specific validation error');assert.equal(t.counts().resets,0);}
   }
 }
 console.log('15 response scenarios passed');
})().catch(e=>{console.error(e);process.exit(1)});
'''
        import json
        result = subprocess.run([NODE, '-e', script, json.dumps({'legacy':legacy,'main':main,'quick':quick})], capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('15 response scenarios passed', result.stdout)
