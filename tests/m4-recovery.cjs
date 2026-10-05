'use strict';
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const checks = {};
const script = '/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh';
function check(v, name) { checks[name] = Boolean(v); if (!v) throw Error(name); }
function call(args, killed = false) {
    const r = spawnSync('wsl.exe', ['-d','Ubuntu','--','bash',script,'tests/m4-tools.php', ...args.map(String)], {encoding:'utf8',windowsHide:true,timeout:60000});
    if (killed) return r;
    if (r.status !== 0) throw Error(r.stderr || r.stdout);
    return JSON.parse(r.stdout.trim());
}
for (const boundary of ['original_intent','original_escrow','original_metadata','original_pre_move','original_moved','original_retained']) {
    const j = call(['create','original']).id;
    const r = call(['run',j,'step',boundary], true);
    check(r.status !== 0, `${boundary} real SIGKILL`);
    const s = call(['state',j]);
    check(s.journal_valid && s.lease_until > 0 && s.master_sha === s.before_sha, `${boundary} durable state and unchanged operational master`);
    call(['expire',j]); call(['run',j,'step']);
    const final = call(['state',j]);
    check(final.stage === 'retained' && final.view.rollback_available, `${boundary} resumed verified quarantine`);
    call(['run',j,'restore']);
    check(call(['state',j]).view.state === 'restored', `${boundary} exact restore after recovery`);
}
for (const boundary of ['purge_intent','purge_deleted','purge_recorded','purge_accounted']) {
    const j = call(['create','master']).id;
    const r = call(['run',j,'purge',boundary], true);
    check(r.status !== 0, `${boundary} real SIGKILL`);
    const s = call(['state',j]);
    check(s.journal_valid && s.master_sha === s.candidate_sha, `${boundary} active remains expected`);
    call(['expire',j]); const result = call(['run',j,'purge']);
    check(!result.error && result.state === 'purged' && !result.rollback_available, `${boundary} resumed irreversible result`);
    check(call(['run',j,'purge']).removed_bytes === result.removed_bytes, `${boundary} no double saving on replay`);
    check(call(['run',j,'restore']).error, `${boundary} no restore after purge`);
}
{
    const j=call(['create','unbound']).id;
    check(call(['run',j,'retain','quarantine_recorded'],true).status!==0,'enrollment real SIGKILL between file record and M2 identity');
    check(call(['state',j]).view===null,'unbound copy never advertised as restorable or purgeable');
    call(['expire',j]); const r=call(['run',j,'retain']);
    check(!r.error && r.rollback_available,'explicit enrollment recovery binds same owned copy');
    check(call(['run',j,'restore']).state==='restored','enrollment recovered exact restore');
}
for (const boundary of ['rollback_file','restore_cleanup','original_restore_linked','original_restored_file','original_restored_metadata']) {
    const original = boundary.startsWith('original');
    const j = call(['create',original ? 'original' : 'master']).id;
    if (original) call(['run',j,'step']);
    const r = call(['run',j,'restore',boundary], true);
    check(r.status !== 0, `${boundary} real SIGKILL`);
    call(['expire',j]); const result = call(['run',j,'restore']);
    check(!result.error && result.state === 'restored', `${boundary} restored and redundant backup consumed`);
    check(call(['state',j]).master_sha === call(['state',j]).before_sha, `${boundary} original master exact`);
}
const out = path.join(__dirname,'../reports/storage-m4'); fs.mkdirSync(out,{recursive:true});
fs.writeFileSync(path.join(out,'recovery.json'),JSON.stringify({checks},null,2)+'\n');
console.log(`M4 recovery: ${Object.keys(checks).length} PASS`);
