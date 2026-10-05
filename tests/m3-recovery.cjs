'use strict';
const {spawnSync, spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
const script = '/mnt/c/Dev/git/wp-seed-pixel/tests/m3-linux-php.sh';
const checks = {};
function check(value, name) { checks[name] = Boolean(value); if (!value) throw Error(name); }
function run(file, args = []) {
    const p = spawnSync('wsl.exe', ['-d', 'Ubuntu', '--', '/bin/bash', script, file, ...args.map(String)], {encoding: 'utf8', windowsHide: true, timeout: 90000});
    if (p.status !== 0) throw Error(p.stderr || p.stdout || `exit ${p.status}`);
    return JSON.parse(p.stdout.trim());
}
const tool = (op, id) => run('tests/m3-tools.php', [op, id || 0]);
for (const boundary of ['intent', 'candidate', 'escrow', 'pre_swap', 'post_swap', 'post_metadata', 'verified', 'retained', 'fatal_oom']) {
    const job = tool('create').id;
    const p = spawnSync('wsl.exe', ['-d', 'Ubuntu', '--', '/bin/bash', script, 'tests/m3-crash.php', String(job), boundary], {encoding:'utf8', windowsHide:true, timeout:90000});
    check(p.status !== 0, `${boundary} genuine process death`);
    let s = tool('state', job);
    const handled = boundary === 'fatal_oom' && s.status.status === 'failed_systemic';
    check(s.journal_valid && (s.item.lease_until > 0 || handled), `${boundary} durable intent and fenced or safely released lease`);
    const changed = ['post_swap','post_metadata','verified','retained'].includes(boundary);
    check(s.canonical === (changed ? s.candidate : s.before), `${boundary} canonical known identity`);
    check(s.meta_before || s.meta_after, `${boundary} native state known`);
    check(handled || tool('step', job).error === 'LOCKED', `${boundary} stale lease not stolen`);
    tool('expire', job); if (handled) tool('resume', job); tool('step', job);
    s = tool('state', job);
    check(s.item.stage === 'retained' && s.meta_after && s.recovery_exists && !s.candidate_exists, `${boundary} resumable verified completion`);
    const rev = s.item.revision; tool('step', job);
    check(tool('state', job).item.revision === rev, `${boundary} completion idempotent`);
    check(!tool('restore', job).error, `${boundary} byte exact rollback accepted`);
    s = tool('state', job);
    check(s.item.stage === 'rolled_back' && s.canonical === s.before && s.meta_before, `${boundary} rollback final invariants`);
}
const job = tool('create').id;
spawnSync('wsl.exe', ['-d','Ubuntu','--','/bin/bash',script,'tests/m3-crash.php',String(job),'post_swap'], {windowsHide:true, timeout:90000});
tool('expire', job);
const unknown = tool('unknown', job);
tool('step', job);
const state = tool('state', job);
check(state.canonical === unknown && ['recovery_required','needs_review'].includes(state.item.stage), 'unknown canonical bytes never overwritten');
check(tool('restore', job).error, 'rollback refuses foreign canonical bytes');
// A real competing process must retain its OS lock even after SQL lease expiry.
const concurrent = tool('create').id;
const duplicate = tool('duplicate', concurrent).id;
const holder = spawn('wsl.exe', ['-d','Ubuntu','--','/bin/bash',script,'tests/m3-tools.php','hold',String(concurrent)], {windowsHide:true, stdio:['ignore','pipe','pipe']});
const exited = new Promise((resolve,reject) => { holder.once('exit', code => code === 0 ? resolve() : reject(Error('holder failed'))); });
await new Promise((resolve,reject) => { holder.stdout.once('data', data => data.toString().includes('OWNED') ? resolve() : reject(Error('holder not ready'))); holder.once('error',reject); });
tool('expire', concurrent);
check(tool('step', concurrent).error === 'LOCKED', 'expired lease cannot steal live filesystem lock');
await exited;
spawnSync('wsl.exe', ['-d','Ubuntu','--','/bin/bash',script,'tests/m3-crash.php',String(concurrent),'post_swap'], {windowsHide:true, timeout:90000});
tool('expire', concurrent);
check(tool('step', duplicate).error === 'CLAIM_CONFLICT', 'second real job cannot take incomplete owner');
tool('step', concurrent);
const killed = spawnSync('wsl.exe', ['-d','Ubuntu','--','/bin/bash',script,'tests/m3-crash.php',String(concurrent),'rollback_file','restore'], {windowsHide:true, timeout:90000});
check(killed.status !== 0, 'rollback genuine process death');
let rollback = tool('state', concurrent);
check(rollback.phase === 'rollback_intent' && rollback.canonical === rollback.before && rollback.meta_after, 'rollback process death has exact bytes and recoverable metadata intent');
check(tool('restore', concurrent).error === 'LOCKED', 'rollback lease fenced after process death');
tool('expire', concurrent);
check(!tool('restore', concurrent).error, 'rollback resumes idempotently after process death');
rollback = tool('state', concurrent);
check(rollback.item.stage === 'rolled_back' && rollback.meta_before, 'rollback process death native reconciliation complete');
const out = path.join(__dirname, '../reports/storage-m3'); fs.mkdirSync(out, {recursive:true});
fs.writeFileSync(path.join(out, 'recovery.json'), JSON.stringify({passed:Object.keys(checks).length,checks}, null, 2)+'\n');
console.log(`${Object.keys(checks).length} crash recovery checks passed`);
})().catch(error => { console.error(error); process.exitCode = 1; });
