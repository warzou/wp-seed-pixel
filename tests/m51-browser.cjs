'use strict';
const {spawnSync}=require('node:child_process'),{chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..'),out=path.join(root,'reports/storage-m5.1'),checks={};
fs.mkdirSync(out,{recursive:true});
process.env.TEMP=process.env.TMP=path.join(root,'.runtime/m51-browser-temp');fs.mkdirSync(process.env.TEMP,{recursive:true});
function check(v,n){checks[n]=Boolean(v);if(!v)throw Error(n);}
function php(file,args=[]){const p=spawnSync('wsl.exe',['--','bash','/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh',file,...args.map(String)],{encoding:'utf8',windowsHide:true,timeout:60000});if(p.status!==0)throw Error('Local PHP fixture failed: '+p.stderr);return JSON.parse(p.stdout.trim());}
(async()=>{
php('tests/m51-browser-state.php',['prepare']);
const browser=await chromium.launch({headless:true,channel:'chrome'});
try{
    const auth=php('tests/storage-browser-auth.php',['fr_FR']);
    const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    await context.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877'})));
    const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
    await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-host');
    check(await page.getByRole('heading',{level:1,name:'Pixel : stockage et nouveaux téléversements'}).count()===1,'Natural French host settings heading');
    check(await page.locator('label[for]').count()===6,'All six fields labeled');
    await page.locator('#pixel-mode').focus();
    check(await page.locator('#pixel-mode').evaluate(e=>document.activeElement===e&&getComputedStyle(e).boxShadow!=='none'),'Keyboard focus visible');
    check((await page.locator('.wrap').innerText()).includes('jamais traités automatiquement'),'Old-media safeguard clear');
    for(const width of [1440,820,390,320]){
        await page.setViewportSize({width,height:1000});
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} no horizontal overflow`);
        await page.screenshot({path:path.join(out,`host-fr-${width}.png`),fullPage:true});
    }
    await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.documentElement.style.zoom='2');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Zoom 200 percent no overflow');
    await page.screenshot({path:path.join(out,'host-fr-zoom200.png'),fullPage:true});
    await page.goto('http://127.0.0.1:8877/wp-admin/media-new.php');
    const nonce=await page.evaluate(()=>window.wpUploaderInit?.multipart_params?._wpnonce ?? window._wpPluploadSettings?.defaults?.multipart_params?._wpnonce);
    check(typeof nonce==='string','Native WordPress upload nonce available');
    const source=fs.readFileSync(path.join(root,'.runtime/fixtures/m3-small.jpeg'));
    const r=await context.request.post('http://127.0.0.1:8877/wp-admin/async-upload.php',{multipart:{action:'upload-attachment',_wpnonce:nonce,'async-upload':{name:'m51-native-http.jpeg',mimeType:'image/jpeg',buffer:source}}});
    const payload=await r.json();check(r.ok()&&payload.success,'Native HTTP upload succeeds without waiting for processing');
    const id=Number(payload.data.id);const state=php('tests/m51-browser-state.php',['state',id]);
    check(state.exists&&state.marker?.state==='analyzed'&&state.marker.job_id===0,'Real HTTP provenance enters analysis only');
    await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-host');
    check((await page.locator('.widefat').innerText()).includes('Analysé'),'Persisted result visible after reload');
    for(const width of [1440,820,390,320]){
        await page.setViewportSize({width,height:1000});
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} populated rows no overflow`);
        if(width<=390){check(await page.locator('.widefat tbody tr').first().locator('td[data-colname="État"]').isVisible(),`${width} mobile details directly visible`);check(await page.locator('.widefat tbody tr td.column-primary').first().evaluate(e=>!e.hasAttribute('data-colname')),`${width} media title has no duplicate mobile label`);}
        await page.screenshot({path:path.join(out,`host-fr-${width}.png`),fullPage:true});
    }
    await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.documentElement.style.zoom='2');
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Populated zoom 200 percent no overflow');
    await page.screenshot({path:path.join(out,'host-fr-zoom200.png'),fullPage:true});
    check(errors.length===0,'No browser JavaScript error');
    const denied=await context.request.post('http://127.0.0.1:8877/wp-admin/admin-post.php',{form:{action:'wp_seed_pixel_host',_wpnonce:'invalid',mode:'process'}});
    check(denied.status()===403,'Settings invalid nonce refused');
    await context.close();
}finally{await browser.close();php('tests/m51-browser-state.php',['cleanup']);}
fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks},null,2)+'\n');
console.log(`${Object.keys(checks).length} isolated M5.1 browser checks passed.`);
})().catch(e=>{console.error(e.message);process.exitCode=1;});
