const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '..');
const temp = path.join(root, '.runtime', 'board-browser-temp');
fs.mkdirSync(temp, { recursive: true });
process.env.TEMP = temp;
process.env.TMP = temp;
(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const tests = [];
    try {
        const context = await browser.newContext();
        const external = [];
        await context.route('**/*', route => {
            if (new URL(route.request().url()).protocol !== 'file:') {
                external.push(route.request().url());
                return route.abort();
            }
            return route.continue();
        });
        const page = await context.newPage();
        await page.goto(pathToFileURL(path.join(root, 'reports/adaptive/WP-SEED-PIXEL-ADAPTIVE-VISUAL-BENCHMARK.html')).href);
        await page.locator('img').evaluateAll(images => images.forEach(img => { img.loading = 'eager'; }));
        await page.waitForFunction(() => [...document.images].every(img => img.complete && img.naturalWidth > 0));
        tests.push({ test: 'All 60 local comparison images loaded', status: await page.locator('img').count() === 60 ? 'PASS' : 'FAIL' });
        for (const width of [1440, 820, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            tests.push({ test: `Local comparison ${width} no horizontal overflow`, status: overflow ? 'FAIL' : 'PASS' });
        }
        tests.push({ test: 'Private comparison requires no external requests', status: external.length === 0 ? 'PASS' : 'FAIL' });
        fs.writeFileSync(path.join(root, 'reports/adaptive/data/board-tests.json'), JSON.stringify(tests, null, 2));
        console.log(JSON.stringify(tests, null, 2));
        if (tests.some(t => t.status === 'FAIL')) process.exitCode = 1;
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
