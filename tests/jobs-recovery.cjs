const {execFileSync, spawn} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const {command:php,args:flags,file,wsl} = require('./local-php.cjs');
const tool = (op, id = 0) => JSON.parse(execFileSync(php, [...flags, file('jobs-tools.php'), op, String(id)], {encoding: 'utf8',windowsHide:true}));
const checks = [];
const check = (name, pass) => { checks.push({name, pass: Boolean(pass)}); if (!pass) throw Error(name); };
let child;
(async () => {
    for (const stage of ['queued', 'preparing', 'switch_intent', 'switched', 'verified']) {
        const job = tool('create');
        let code;
        try { execFileSync(php, [...flags, file('jobs-crash.php'), String(job.id), stage], {stdio: 'pipe',windowsHide:true}); }
        catch (error) { code = error.status; }
        check(stage + ' process interrupted at durable boundary', code === 73 && tool('row', job.id).stage === stage);
        check(stage + ' live durable lease prevents immediate takeover', tool('step', job.id).error === 'LOCKED');
        tool('expire', job.id);
        check(stage + ' bootstrap reload recovers abandoned item', tool('step', job.id).states.retained === 1);
        const row = tool('row', job.id);
        tool('step', job.id);
        check(stage + ' recovered success not rerun', JSON.stringify(tool('row', job.id)) === JSON.stringify(row));
    }
    const live = tool('create');
    child = spawn(php, [...flags, file('jobs-crash.php'), String(live.id), 'switch_intent', ...(wsl ? ['hold'] : [])], {env: {...process.env, PIXEL_QA_HOLD_LOCK: '1'}, stdio: ['ignore', 'pipe', 'pipe'],windowsHide:true});
    let heldPid = 0;
    await new Promise((resolve, reject) => { child.stdout.once('data', d => { heldPid=Number(d.toString().match(/PID=(\d+)/)?.[1] || 0); resolve(); }); child.once('error', reject); child.once('exit', () => reject(Error('Child exited before lock test'))); });
    tool('expire', live.id);
    check('expired lease never steals live filesystem lock', tool('step', live.id).error === 'LOCKED');
    const ended = new Promise(resolve => child.once('exit', resolve)); if(wsl) tool('kill-worker',heldPid); else child.kill(); await ended; child = undefined;
    check('killed process releases flock and recovery is deterministic', tool('step', live.id).states.retained === 1);
    fs.writeFileSync(path.join(root, 'reports/storage-m2/recovery.json'), JSON.stringify({checks, passed: checks.length, actualProcessDeath: true}, null, 2));
    process.stdout.write(JSON.stringify({passed: checks.length}));
})().catch(error => { fs.writeFileSync(path.join(root, 'reports/storage-m2/recovery.json'), JSON.stringify({checks, failure: error.message}, null, 2)); process.stderr.write(error.stack); process.exitCode = 1; }).finally(() => { if (child) child.kill(); });
