'use strict';
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '..'), out = path.join(root, 'reports/storage-v1/browser');
const tmp = path.join(root, '.runtime/v1-browser-temp');
fs.mkdirSync(out, {recursive:true}); fs.mkdirSync(tmp, {recursive:true});
process.env.TEMP = process.env.TMP = tmp;
const checks = {}, errors = [];
function check(v, name) { checks[name] = Boolean(v); if (!v) throw Error(name); }
function php(file, args=[]) {
    const r = spawnSync('wsl.exe', ['--', 'bash', '/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh', file, ...args.map(String)], {encoding:'utf8', windowsHide:true, timeout:60000});
    if (r.status !== 0) throw Error('Local fixture failed: ' + r.stderr);
    return JSON.parse(r.stdout.trim());
}
(async () => {
    const state = php('tests/v1-browser-state.php');
    const browser = await chromium.launch({headless:true, executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
    let lastPage;
    try {
        for (const locale of ['en_US','fr_FR']) {
            const auth = php('tests/storage-browser-auth.php', [locale]);
            const context = await browser.newContext({viewport:{width:1440,height:1000}, reducedMotion:'reduce'});
            await context.addCookies(auth.cookies.map(c=>({...c,url:'http://127.0.0.1:8877'})));
            const page = await context.newPage(); lastPage = page; page.on('pageerror', e=>errors.push(e.message));
            await context.route('**/*', r=>new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort());
            await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-host');
            check(await page.getByRole('heading', {level:1, name:locale === 'fr_FR' ? 'Pixel : stockage et nouveaux téléversements' : 'Pixel storage and new uploads'}).count() === 1, locale+' translated host page');
            check(await page.locator('input[name="formats[]"]').count() === 2, locale+' explicit format choices');
            await page.locator('#pixel-mode').focus();
            check(await page.locator('#pixel-mode').evaluate(e=>document.activeElement === e && getComputedStyle(e).boxShadow !== 'none'), locale+' visible keyboard focus');
            for (const width of [1440,820,390,320]) {
                await page.setViewportSize({width,height:1000});
                check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), locale+width+' host no overflow');
                await page.screenshot({path:path.join(out,locale+'-host-'+width+'.png'),fullPage:true});
            }
            await page.setViewportSize({width:1440,height:1000});
            await page.evaluate(()=>document.documentElement.style.zoom='2');
            check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), locale+' zoom200 host');
            await page.goto('http://127.0.0.1:8877/wp-admin/post.php?post='+state.purged_id+'&action=edit');
            const panel = page.locator('.pixel-media-panel');
            check(await panel.count() === 1, locale+' native Media editor Pixel panel');
            check((await panel.innerText()).includes(locale==='fr_FR' ? 'Restauration indisponible' : 'Restoration unavailable'), locale+' purged status truthful');
            check(!(await panel.innerText()).includes(locale==='fr_FR' ? 'Votre original est conservé.' : 'Your original is kept.'), locale+' no false original claim');
            await page.screenshot({path:path.join(out,locale+'-purged-media.png'),fullPage:true});
            await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel-bulk');
            await page.locator('#pixel-bulk-select').click();
            check(await page.locator('.media-modal').isVisible(), locale+' native Media selector opens');
            await page.locator('.media-modal').getByRole('tab', {name:/^(Médiathèque|Media Library)$/}).click();
            await page.locator('.media-modal .attachment').first().click();
            await page.locator('.media-modal .media-button-select').click();
            check(/^[1-9][0-9]*$/.test(await page.locator('#pixel-bulk-selection').inputValue()), locale+' explicit selection populated');
            await page.locator('#pixel-bulk-select').focus(); await page.keyboard.press('Tab');
            check(await page.evaluate(()=>document.activeElement.tagName !== 'BODY'), locale+' keyboard sequence');
            for (const width of [1440,820,390,320]) {
                await page.setViewportSize({width,height:1000});
                check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), locale+width+' bulk no overflow');
                await page.screenshot({path:path.join(out,locale+'-bulk-'+width+'.png'),fullPage:true});
            }
            const denied = await context.request.post('http://127.0.0.1:8877/wp-admin/admin-post.php', {form:{action:'wp_seed_pixel_host',_wpnonce:'invalid',mode:'process'}});
            check(denied.status()===403,locale+' mutation denied invalid nonce');
            await context.close();
        }
        check(errors.length===0,'No browser JavaScript errors');
    } catch (e) {
        if (lastPage && !lastPage.isClosed()) { await lastPage.screenshot({path:path.join(out,'failure.png'),fullPage:true}); fs.writeFileSync(path.join(out,'failure.txt'),(await lastPage.locator('body').innerText())); }
        throw e;
    } finally { await browser.close(); php('tests/m51-browser-state.php',['cleanup']); }
    fs.writeFileSync(path.join(out,'qa.json'),JSON.stringify({checks,errors},null,2));
    console.log(Object.keys(checks).length+' V1 isolated browser checks PASS');
})().catch(e=>{console.error(e.message);process.exitCode=1;});
