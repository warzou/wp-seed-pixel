'use strict';
const {spawnSync}=require('node:child_process'),{chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path');
const checks={};const out=path.resolve(__dirname,'../reports/storage-m5');fs.mkdirSync(out,{recursive:true});
process.env.TEMP=process.env.TMP=path.resolve(__dirname,'../.runtime/m5-browser-temp');fs.mkdirSync(process.env.TEMP,{recursive:true});
function check(v,n){checks[n]=Boolean(v);if(!v)throw Error(n);}
function php(file,args=[]){const p=spawnSync('wsl.exe',['-d','Ubuntu','--','bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh',file,...args.map(String)],{encoding:'utf8',windowsHide:true,timeout:90000});if(p.status!==0)throw Error(p.stderr||p.stdout);return JSON.parse(p.stdout.trim());}
(async()=>{
const browser=await chromium.launch({headless:true,channel:'chrome'});
try{
for(const locale of ['en_US','fr_FR']){
    const fixture=php('tests/m5-tools.php',['create']);const auth=php('tests/storage-browser-auth.php',[locale]);
    const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    await context.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877'})));
    const page=await context.newPage(),errors=[],external=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(new URL(r.url()).origin!=='http://127.0.0.1:8877')external.push(r.url());});
    await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-bulk');
    const fr=locale==='fr_FR';check(await page.getByRole('heading',{level:1,name:fr?'Réduction du stockage par lots':'Bulk storage saver'}).count()===1,`${locale} full native UI localized`);
    check(await page.evaluate(()=>{const s=[...document.styleSheets].find(x=>x.href?.includes('/assets/bulk.css'));return s?.cssRules.length===13&&s.cssRules[12].cssRules.length===2;}),`${locale} all bulk CSS rules parsed`);
    check((await page.locator('#pixel-bulk-status').innerText()).includes(fr?'Aucun':'No bulk'),`${locale} no automatic processing`);
    await page.locator('#pixel-bulk-job').selectOption(String(fixture.id));await page.locator('#pixel-bulk-results tbody tr').first().waitFor();
    const resume=page.locator('[data-command=resume]');await resume.focus();check(await resume.evaluate(b=>document.activeElement===b&&getComputedStyle(b).boxShadow!=='none'),`${locale} keyboard focus visible`);
    await page.keyboard.press('Enter');await page.waitForFunction(()=>document.querySelector('#pixel-bulk-status').textContent.includes('1/2'),null,{timeout:60000});
    await page.locator('[data-command=pause]').click();await page.waitForFunction(()=>/pause/i.test(document.querySelector('#pixel-bulk-status').textContent));
    check(php('tests/m5-tools.php',['state',fixture.id]).status.status==='paused',`${locale} pause waits for safe item boundary`);
    await page.reload();await page.locator('#pixel-bulk-job').selectOption(String(fixture.id));await page.locator('#pixel-bulk-results tbody tr').first().waitFor();
    check(php('tests/m5-tools.php',['state',fixture.id]).status.status==='paused',`${locale} persisted pause after reload`);
    await page.locator('[data-command=resume]').click();await page.waitForFunction(()=>document.querySelector('#pixel-bulk-status').textContent.includes('2/2'),null,{timeout:60000});
    check(php('tests/m5-tools.php',['state',fixture.id]).items.every(i=>i.stage==='retained'),`${locale} browser start/resume uses real M4 items`);
    await page.locator('[data-command=audit]').click();await page.locator('#pixel-bulk-metrics dd').first().waitFor();
    check(await page.locator('#pixel-bulk-metrics dt').count()===8,`${locale} distinct active quarantine potential actual accounting`);
    const state=php('tests/m5-tools.php',['state',fixture.id]);
    await page.reload();check(await page.locator('#pixel-bulk-progress').getAttribute('value')==='0',`${locale} reload never auto-resumes`);
    await page.locator('#pixel-bulk-job').selectOption(String(fixture.id));await page.locator('#pixel-bulk-results tbody tr').first().waitFor();
    check(JSON.stringify(php('tests/m5-tools.php',['state',fixture.id]).items)===JSON.stringify(state.items),`${locale} reload completed no replay`);
    await page.locator('[data-command=audit]').click();await page.locator('#pixel-bulk-metrics dd').first().waitFor();
    for(const width of [1440,820,390,320]){await page.setViewportSize({width,height:1000});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${locale} ${width} no overflow`);await page.screenshot({path:path.join(out,`bulk-${locale}-${width}.png`),fullPage:true});}
    await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.documentElement.style.zoom='2');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${locale} zoom200 no overflow`);
    const nonce=await page.evaluate(()=>wpSeedPixelBulk.nonce);
    for(const form of [{nonce:'invalid',operation:'step',job_id:String(fixture.id)},{nonce,operation:'purge',job_id:String(fixture.id)},{nonce,operation:'plan',capacity_mb:'-1',lot:'1'}]){const r=await context.request.post('http://127.0.0.1:8877/wp-admin/admin-ajax.php',{form:{action:'wp_seed_pixel_bulk',...form}});check(r.status()>=400,`${locale} denied ${form.operation} ${form.nonce==='invalid'?'nonce':'intent'}`);}
    check(errors.length===0&&external.length===0,`${locale} no JavaScript errors or external network`);
    await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-quarantine');
    const purge=page.locator('form').filter({has:page.locator(`input[name=item_id][value="${fixture.items[0]}"]`)}).filter({has:page.locator('input[name=operation][value=purge]')});
    check(await purge.count()===1,`${locale} explicit bulk item target in native quarantine form`);
    check(await purge.evaluate(f=>!f.checkValidity()),`${locale} irreversible consent required separately`);
    check((await purge.locator('button,input[type=submit]').first().getAttribute('value')||await purge.innerText()).includes(fr?'Supprimer':'Delete'),`${locale} permanent action clearly named`);
    await context.close();
}
const anonymous=await browser.newContext();const denied=await anonymous.request.post('http://127.0.0.1:8877/wp-admin/admin-ajax.php',{form:{action:'wp_seed_pixel_bulk',operation:'step',job_id:'1',nonce:'x'}});check(denied.status()>=400,'anonymous bulk endpoint unavailable');await anonymous.close();
}finally{await browser.close();}
fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks},null,2)+'\n');console.log(`M5 browser: ${Object.keys(checks).length} PASS`);
})().catch(e=>{console.error(e);process.exitCode=1;});
