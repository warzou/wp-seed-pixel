'use strict';
const {spawnSync,spawn}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path');
const checks={};
const args=['-d','Ubuntu','--','bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh','tests/m5-tools.php'];
function check(v,n){checks[n]=Boolean(v);if(!v)throw Error(n);}
function call(a,killed=false){const p=spawnSync('wsl.exe',[...args,...a.map(String)],{encoding:'utf8',windowsHide:true,timeout:90000});if(killed)return p;if(p.status!==0)throw Error(p.stderr||p.stdout);return JSON.parse(p.stdout.trim());}
(async()=>{
for(const boundary of ['intent','candidate','escrow','post_swap','post_metadata','retained','quarantine_recorded']){
    const j=call(['create']); const p=call(['run',j.id,0,'step',boundary],true);
    check(p.status!==0,`${boundary} bulk real SIGKILL`);
    const before=call(['state',j.id]);check(before.items.every(i=>i.journal_valid),`${boundary} durable journals`);
    check(call(['run',j.id,0,'step']).error==='LOCKED',`${boundary} lease not stolen`);
    call(['expire',j.id]); const done=call(['finish',j.id]);
    check(done.status==='completed',`${boundary} peers and interrupted item complete`);
    const s=call(['state',j.id]);check(s.items.every(i=>i.stage==='retained'&&i.view.rollback_available),`${boundary} verified M4 retention`);
    call(['finish',j.id]); const again=call(['state',j.id]);
    check(JSON.stringify(s.items)===JSON.stringify(again.items),`${boundary} no re-encode or side-effect replay`);
    check(JSON.stringify({...s.audit,measured_at:0})===JSON.stringify({...again.audit,measured_at:0}),`${boundary} no accounting replay`);
}
for(const boundary of ['purge_intent','purge_deleted','purge_recorded','purge_accounted']){
    const j=call(['create']);call(['finish',j.id]);const i=j.items[0];
    check(call(['run',j.id,i,'purge',boundary],true).status!==0,`${boundary} bulk purge SIGKILL`);
    call(['expire',j.id]);const result=call(['run',j.id,i,'purge']);check(result.state==='purged',`${boundary} purge resumed`);
    const a=call(['state',j.id]);call(['run',j.id,i,'purge']);const b=call(['state',j.id]);
    check(a.audit.removed_source_bytes===result.removed_bytes && b.audit.removed_source_bytes===result.removed_bytes,`${boundary} actual bytes counted once`);
    check(JSON.stringify(a.items)===JSON.stringify(b.items),`${boundary} purge never replayed`);
    check(b.audit.quarantine_bytes>0,`${boundary} healthy peer recovery preserved`);
}
{
    const j=call(['create']);check(call(['run',j.id,0,'step','item_completed'],true).status!==0,'between-items actual SIGKILL');const s=call(['state',j.id]);
    check(s.items.filter(i=>i.stage==='retained').length===1,'between-items durable checkpoint');
    call(['finish',j.id]); const f=call(['state',j.id]);
    check(f.items[0].revision===s.items[0].revision&&f.items[0].sha===s.items[0].sha,'between-items first result not replayed');
    const holder=spawn('wsl.exe',[...args,'hold'],{windowsHide:true,stdio:['ignore','pipe','pipe']});
    const exited=new Promise((resolve,reject)=>{holder.once('exit',c=>c===0?resolve():reject(Error('hold exit')));});
    await new Promise((resolve,reject)=>{holder.stdout.once('data',d=>d.toString().includes('OWNED')?resolve():reject(Error('hold sync')));holder.once('error',reject);});
    check(call(['run',j.id,j.items[1],'restore']).error==='LOCKED','competing restore respects live site flock');
    check(call(['run',j.id,j.items[1],'purge']).error==='LOCKED','competing purge respects live site flock');
    await exited;
}
const out=path.resolve(__dirname,'../reports/storage-m5');fs.mkdirSync(out,{recursive:true});fs.writeFileSync(path.join(out,'recovery.json'),JSON.stringify({checks},null,2)+'\n');console.log(`M5 recovery: ${Object.keys(checks).length} PASS`);
})().catch(e=>{console.error(e);process.exitCode=1;});
