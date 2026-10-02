const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const temp = path.join(root, '.runtime', 'admin-preview-browser-temp');
fs.mkdirSync(temp, { recursive: true });
process.env.TEMP = temp; process.env.TMP = temp;
const php = process.env.PIXEL_QA_PHP;
const args = ['-d',`extension_dir=${path.dirname(php)}/ext`,'-d','extension=gd','-d','extension=exif','-d','extension=mbstring','-d','extension=pdo_sqlite','-d','extension=mysqli'];
const auth = JSON.parse(execFileSync(php,[...args,path.join(__dirname,'auth.php')],{encoding:'utf8'}));
(async () => {
    const browser = await chromium.launch({channel:'chrome',headless:true});
    try {
        const context = await browser.newContext();
        await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
        await context.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877',httpOnly:true,sameSite:'Lax'})));
        const page = await context.newPage();
        await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel');
        await page.locator('#pixel-preset').selectOption('balanced');
        const details=page.locator('details').filter({hasText:'Balanced JPEG example'});
        await details.evaluate(el=>{el.open=true;});
        if(!(await details.textContent()).includes('bounded-rgb-3'))throw new Error('Final adaptive stats not displayed');
        for(const width of [1440,820,390,320]){
            await page.setViewportSize({width,height:1000});
            if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw new Error(`Overflow ${width}`);
            await page.screenshot({path:path.join(root,`reports/adaptive/admin-${width}.png`),fullPage:true});
        }
        console.log('Final adaptive administration captures: 1440/820/390/320 PASS.');
    }finally{await browser.close();auth.cookies.forEach(c=>{c.value='';});auth.nonce='';}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
