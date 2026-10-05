'use strict';
const {spawnSync,spawn}=require('node:child_process'),fs=require('node:fs'),path=require('node:path');
const args=['-d','Ubuntu','--','bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh','tests/m5-tools.php'];const checks={};
function check(v,n){checks[n]=Boolean(v);if(!v)throw Error(n);}
function call(a){const p=spawnSync('wsl.exe',[...args,...a.map(String)],{encoding:'utf8',windowsHide:true,timeout:90000});if(p.status!==0)throw Error(p.stderr||p.stdout);return JSON.parse(p.stdout.trim());}
async function hold(j){const p=spawn('wsl.exe',[...args,'run',String(j.id),'0','step','hold_intent'],{windowsHide:true,stdio:['ignore','pipe','pipe']});let output='';const done=new Promise((resolve,reject)=>{p.once('exit',c=>c===0?resolve(output):reject(Error('worker '+output)));p.once('error',reject);});await new Promise((resolve,reject)=>{p.stdout.on('data',d=>{output+=d;if(output.includes('OWNED'))resolve();});p.once('error',reject);});return {done};}
(async()=>{
const j=call(['create']);const duplicate=call(['duplicate',j.id]);
const pending=await hold(j);
check(call(['run',duplicate.id,0,'step']).error==='LOCKED','same attachment competing job respects owner');
await pending.done; call(['finish',j.id]);
check(call(['finish',duplicate.id]).states.needs_review===2,'same attachment two frozen jobs cannot replay changed generation');
const one=call(['create']);const two=call(['create']);call(['untrash',one.id]);
const p=spawn('wsl.exe',[...args,'run',String(one.id),'0','step','hold_intent'],{windowsHide:true,stdio:['ignore','pipe','pipe']});let output='';
const done=new Promise((resolve,reject)=>p.once('exit',c=>c===0?resolve():reject(Error('worker exit'))));
await new Promise((resolve,reject)=>{p.stdout.on('data',d=>{output+=d;if(output.includes('OWNED'))resolve();});p.once('error',reject);});
check(call(['run',two.id,0,'step']).error==='LOCKED','different attachments serialize under existing site flock');
check(call(['run',one.id,0,'step']).error==='LOCKED','same job worker cannot steal live lock');
call(['expire',one.id]);check(call(['run',one.id,0,'step']).error==='LOCKED','SQL lease expiry cannot steal active filesystem owner');
call(['external',one.id,one.items[1]]);await done;call(['resume',one.id]);
const state=call(['finish',one.id]);check(state.states.retained===1&&state.states.needs_review===1,'queued external writer wins while healthy peer completes');
check(call(['finish',two.id]).states.retained===2,'other job proceeds when site owner releases');
const out=path.resolve(__dirname,'../reports/storage-m5');fs.mkdirSync(out,{recursive:true});fs.writeFileSync(path.join(out,'concurrency.json'),JSON.stringify({checks},null,2)+'\n');console.log(`M5 concurrency: ${Object.keys(checks).length} PASS`);
})().catch(e=>{console.error(e);process.exitCode=1;});
