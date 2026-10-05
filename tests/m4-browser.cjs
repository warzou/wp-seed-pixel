'use strict';
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
const out = path.resolve(__dirname,'../reports/storage-m4'); fs.mkdirSync(out,{recursive:true});
const temp = path.resolve(__dirname,'../.runtime/m4-browser-temp'); fs.mkdirSync(temp,{recursive:true});
const checks = {};
function check(v,n) { checks[n]=Boolean(v); if(!v) throw Error(n); }
function php(file,args=[]) {
    const r=spawnSync('wsl.exe',['-d','Ubuntu','--','bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh',file,...args],{encoding:'utf8',windowsHide:true,timeout:90000});
    if(r.status!==0) throw Error(r.stderr || r.stdout); return JSON.parse(r.stdout.trim());
}
const fixture=php('tests/m4-browser-state.php');
const browser=await chromium.launch({channel:'chrome',headless:true,env:{...process.env,TEMP:temp,TMP:temp}});
try {
    for(const locale of ['en_US','fr_FR']) {
        const auth=php('tests/storage-browser-auth.php',[locale]);
        const context=await browser.newContext({viewport:{width:1440,height:1000}});
        await context.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877'})));
        const page=await context.newPage(); const errors=[]; const external=[];
        page.on('pageerror',e=>errors.push(e.message)); page.on('request',r=>{if(new URL(r.url()).origin!=='http://127.0.0.1:8877')external.push(r.url());});
        await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-quarantine');
        check(await page.locator('form input[name="irreversible"]').count()>0,`${locale} irreversible acknowledgment present`);
        const name=locale==='fr_FR'?'Supprimer définitivement':'Delete permanently';
        check(await page.getByRole('button',{name,exact:true}).count()>0,`${locale} explicit delete label`);
        const form=page.locator('form').filter({has:page.locator('input[name="irreversible"]')}).first();
        check(await form.evaluate(f=>!f.checkValidity()),`${locale} no implicit purge`);
        const checkbox=form.locator('input[name="irreversible"]'); await checkbox.focus(); await page.keyboard.press('Space');
        check(await checkbox.isChecked() && await form.evaluate(f=>f.checkValidity()),`${locale} keyboard consent`);
        const button=form.getByRole('button',{name,exact:true}); await button.focus();
        check(await button.evaluate(b=>document.activeElement===b && (getComputedStyle(b).boxShadow!=='none' || (getComputedStyle(b).outlineStyle!=='none' && parseFloat(getComputedStyle(b).outlineWidth)>0))),`${locale} native keyboard focus visible`);
        for(const width of [1440,820,390,320]) {
            await page.setViewportSize({width,height:1000});
            check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${locale} ${width} no overflow`);
            await page.screenshot({path:path.join(out,`admin-${locale}-${width}.png`),fullPage:true});
            if(width===1440 || width===390) await page.screenshot({path:path.join(out,`admin-${locale}-${width}-viewport.png`)});
        }
        await page.setViewportSize({width:1440,height:1000}); await page.evaluate(()=>document.documentElement.style.zoom='2');
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${locale} zoom200 no overflow`);
        if(errors.length || external.length) console.log(JSON.stringify({locale,errors,external}));
        check(errors.length===0 && external.length===0,`${locale} no JS errors external requests`);
        // Invalid nonce and missing permission cannot mutate anything.
        const denied=await context.request.post('http://127.0.0.1:8877/wp-admin/admin-post.php',{form:{action:'wp_seed_pixel_quarantine',operation:'purge',job_id:fixture.job,_wpnonce:'invalid'}});
        check(denied.status()===403,`${locale} nonce refused`);
        await context.close();
    }
    const auth=php('tests/storage-browser-auth.php',['en_US']);
    const admin=await browser.newContext({javaScriptEnabled:false});
    await admin.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877'})));
    const ui=await admin.newPage();
    await ui.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-quarantine');
    const selected=ui.locator('form').filter({has:ui.locator(`input[name="job_id"][value="${fixture.job}"]`)}).filter({has:ui.locator('input[name="operation"][value="purge"]')});
    check(await selected.count()===1,'noJS exact fixture form');
    const values=await selected.evaluate(f=>Object.fromEntries(new FormData(f)));
    await admin.request.post('http://127.0.0.1:8877/wp-admin/admin-post.php',{form:values});
    check(php('tests/m4-browser-state.php',['state',String(fixture.job)]).rollback_available,'server missing consent no purge');
    await selected.locator('input[name="irreversible"]').check();
    await selected.getByRole('button',{name:'Delete permanently',exact:true}).click();
    await ui.waitForURL(/result=SUCCESS/);
    check(php('tests/m4-browser-state.php',['state',String(fixture.job)]).state==='purged','noJS explicit UI purge');
    check(await ui.locator('form').filter({has:ui.locator(`input[name="job_id"][value="${fixture.job}"]`)}).count()===0,'purged fixture no false restore control');
    const restore=ui.locator('form').filter({has:ui.locator(`input[name="job_id"][value="${fixture.original_job}"]`)}).filter({has:ui.locator('input[name="operation"][value="restore"]')});
    await restore.getByRole('button',{name:'Restore this version',exact:true}).click();
    if(!ui.url().includes('result=SUCCESS')) throw Error('native restore stopped: '+ui.url());
    await ui.waitForURL(/result=SUCCESS/);
    check(php('tests/m4-browser-state.php',['state',String(fixture.original_job)]).state==='restored','noJS original restore native form');
    await admin.close();
    php('tests/m4-browser-state.php',['remove']);
    const context=await browser.newContext({javaScriptEnabled:false}); const page=await context.newPage();
    await page.goto(fixture.url);
    check(await page.locator('img').first().evaluate(i=>i.complete && i.naturalWidth>0),'Pixel removed noJS native image');
    const r=await context.request.get(fixture.image_url); check(r.status()===200,'Pixel disabled native URL200');
    for(const width of [1440,820,390,320]) {
        await page.setViewportSize({width,height:1000}); check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`disabled ${width} native overflow absent`);
    }
    await page.screenshot({path:path.join(out,'native-disabled-320.png'),fullPage:true}); await context.close();
} finally { await browser.close(); php('tests/m4-browser-state.php',['enable']); }
fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks},null,2)+'\n');
console.log(`M4 browser: ${Object.keys(checks).length} PASS`);
})().catch(e=>{console.error(e);process.exitCode=1;});
