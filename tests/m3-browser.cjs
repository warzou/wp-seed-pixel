'use strict';
const {chromium} = require('playwright');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const script = '/mnt/c/Dev/git/wp-seed-pixel/tests/m3-linux-php.sh';
const out = path.resolve(__dirname, '../reports/storage-m3');
function command(op) {
    const p = spawnSync('wsl.exe', ['-d','Ubuntu','--','/bin/bash',script,'tests/m3-browser-state.php',op], {encoding:'utf8', windowsHide:true, timeout:90000});
    if (p.status !== 0) throw Error(p.stderr || `local command failed ${p.status}`);
    return JSON.parse(p.stdout);
}
(async () => {
    fs.mkdirSync(out, {recursive:true}); const checks = {}, errors = [], network = [];
    const check = (v,n) => { checks[n] = Boolean(v); if (!v) throw Error(n); };
    const state = command('prepare');
    const browser = await chromium.launch({headless:true, channel:'chrome'});
    let disabled = false;
    try {
        const context = await browser.newContext({reducedMotion:'reduce'});
        await context.route('**/*', route => {
            const u = route.request().url();
            if (!u.startsWith('http://127.0.0.1:8877/') && !u.startsWith('data:') && !u.startsWith('about:')) { network.push(u); return route.abort(); }
            return route.continue();
        });
        await context.addCookies(state.cookies.map(c => ({...c, url:'http://127.0.0.1:8877', httpOnly:true, sameSite:'Lax'})));
        const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
        for (const width of [1440,820,390,320]) {
            await page.setViewportSize({width, height:900});
            const response = await page.goto(state.url, {waitUntil:'networkidle'});
            check(response.status() === 200, `frontend ${width} HTTP 200`);
            const image = page.locator(`img[src="${state.image}"]`).first();
            check(await image.evaluate(i => i.complete && i.naturalWidth === 1920 && i.naturalHeight === 1280), `frontend ${width} native retained image decoded`);
            await page.screenshot({path:path.join(out, `native-frontend-${width}.png`), fullPage:true});
            const overflow = await page.evaluate(() => ({width:innerWidth, scroll:document.documentElement.scrollWidth, nodes:Array.from(document.querySelectorAll('body *')).filter(e => e.getBoundingClientRect().right > innerWidth+1).slice(0,8).map(e => ({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))}));
            if (overflow.scroll > width+1) { fs.writeFileSync(path.join(out,'browser-overflow.json'), JSON.stringify(overflow,null,2)); }
            check(overflow.scroll <= width+1, `frontend ${width} no overflow`);
        }
        command('deactivate'); disabled = true;
        const cold = command('cold');
        check(!cold.pixel_loaded && cold.sha256_current === state.sha256, 'fresh process Pixel disabled native master unchanged');
        await page.reload({waitUntil:'networkidle'});
        check(await page.locator(`img[src="${state.image}"]`).first().evaluate(i => i.complete && i.naturalWidth === 1920), 'cold web Pixel disabled renders');
        const rest = await context.request.get(`http://127.0.0.1:8877/?rest_route=/wp/v2/media/${state.attachment_id}`, {headers:{'X-WP-Nonce':state.nonce}});
        const media = await rest.json();
        check(rest.status() === 200 && media.source_url === state.image && media.media_details.width === 1920, 'cold web Pixel disabled REST coherent');
        command('remove');
        const removed = command('cold');
        check(!removed.pixel_loaded && removed.sha256_current === state.sha256 && removed.src[1] === 1920, 'plugin uninstalled and files absent native attachment intact');
        await page.reload({waitUntil:'networkidle'});
        check(await page.locator(`img[src="${state.image}"]`).first().evaluate(i => i.complete && i.naturalWidth === 1920), 'cold web plugin absent renders natively');
        const noJS = await browser.newContext({javaScriptEnabled:false, viewport:{width:390,height:844}});
        const plain = await noJS.newPage(); await plain.goto(state.url, {waitUntil:'networkidle'});
        check(await plain.locator(`img[src="${state.image}"]`).first().evaluate(i => i.complete && i.naturalWidth === 1920), 'native frontend without JavaScript');
        await plain.screenshot({path:path.join(out,'native-disabled-no-js.png'),fullPage:true}); await noJS.close();
        await page.goto(`http://127.0.0.1:8877/wp-admin/post.php?post=${state.attachment_id}&action=edit`, {waitUntil:'networkidle'});
        check(await page.locator('#wpbody-content').count() === 1 && !page.url().includes('wp-login.php'), 'native Media Library editor authenticated Pixel disabled');
        await page.setViewportSize({width:1440,height:1000});
        await page.screenshot({path:path.join(out,'native-media-disabled.png'),fullPage:true});
        check(errors.length === 0, 'browser zero JavaScript errors');
        fs.writeFileSync(path.join(out,'browser-blocked-network.json'), JSON.stringify(network.map(u => new URL(u).host),null,2));
        check(network.length === 0, 'browser zero external requests');
        fs.writeFileSync(path.join(out,'browser.json'), JSON.stringify({passed:Object.keys(checks).length,checks,errors,external_requests:network.length},null,2)+'\n');
        console.log(`${Object.keys(checks).length} isolated browser checks passed`);
    } finally { if (disabled) command('activate'); await browser.close(); }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
