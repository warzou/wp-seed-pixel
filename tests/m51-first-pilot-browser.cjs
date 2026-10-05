const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

(async () => {
  const root = path.resolve(__dirname, '..');
  if (root.toLowerCase() !== 'c:\\dev\\git\\wp-seed-pixel') throw new Error('OWNED_SCOPE');
  const output = path.join(root, 'reports', 'storage-m5.1', 'first-real-pilot');
  const pilot = JSON.parse(fs.readFileSync(path.join(output, 'pixel-only-pilot.json'), 'utf8'));
  if (pilot.verdict !== 'READY FOR HUMAN PASS') throw new Error('PILOT_NOT_READY');
  const url = pilot.qa.media_url;
  if (new URL(url).hostname !== 'therapsycorporel.fr') throw new Error('SITE_SCOPE');
  const installed = chromium.executablePath();
  const executable = fs.existsSync(installed) ? installed : 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ headless: true, executablePath: executable });
  const checks = {};
  const errors = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, userAgent: 'WP-Seed-Pixel-M5.1-One-JPEG/1.0' });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.name));
    const response = await page.goto(url, { waitUntil: 'load', timeout: 30000 });
    checks['Public retained master HTTP 200'] = response.status() === 200;
    for (const [width, height] of [[1440, 1000], [820, 1180], [390, 844]]) {
      await page.setViewportSize({ width, height });
      await page.locator('img').evaluate(image => image.decode());
      const state = await page.locator('img').evaluate(image => ({ naturalWidth: image.naturalWidth, naturalHeight: image.naturalHeight, width: image.getBoundingClientRect().width, complete: image.complete }));
      checks[`Image decoded at ${width}`] = state.complete && state.naturalWidth === 1536 && state.naturalHeight === 1024 && state.width > 0;
      await page.screenshot({ path: path.join(output, `public-master-${width}.png`), fullPage: true });
    }
    checks['No browser page error'] = errors.length === 0;
    if (!Object.values(checks).every(Boolean)) throw new Error('BROWSER_CHECK_FAILED');
    await context.close();
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(output, 'browser-qa.json'), JSON.stringify({ checks, errors, headless: true, privilegedMediaLibraryUI: 'NOT TESTED', browserClosed: true }, null, 2) + '\n');
  console.log(`${Object.keys(checks).length} isolated public-image browser checks PASS; browser closed.`);
})().catch(error => { console.error(error.name + ': ' + error.message); process.exitCode = 1; });
