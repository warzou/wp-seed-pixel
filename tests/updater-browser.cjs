const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const out = process.env.PIXEL_UPDATER_OUT;
if (!out || !path.isAbsolute(out)) throw new Error('Explicit local report folder required');
fs.mkdirSync(out, {recursive:true});
const checks = {};
function ok(value, label) { if (!value) throw new Error(label); checks[label]=true; }
(async()=>{
  const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  await context.route('**/*',route=>new URL(route.request().url()).hostname==='127.0.0.1'?route.continue():route.abort());
  const page=await context.newPage();
  const errors=[]; page.on('pageerror',e=>{errors.push(e.message);fs.writeFileSync(path.join(out,'browser-errors.json'),JSON.stringify(errors,null,2));});
  try {
    await page.goto('http://127.0.0.1:8877/wp-login.php?redirect_to=http%3A%2F%2F127.0.0.1%3A8877%2Fwp-admin%2Fplugins.php');
    await page.locator('#user_login').fill('pixel_qa');
    await page.locator('#user_pass').fill('local-updater-fixture');
    await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
    await page.goto('http://127.0.0.1:8877/wp-admin/plugins.php');
    const row=page.locator('tr[data-plugin="wp-seed-pixel/wp-seed-pixel.php"]').first();
    ok((await row.innerText()).includes('0.5.0'),'installed old version shown');
    const notice=page.locator('#wp-seed-pixel-update');
    await notice.waitFor();
    ok((await notice.innerText()).includes('0.5.1'),'native Plugins update notice');
    await page.screenshot({path:path.join(out,'plugins-update-1440.png'),fullPage:true});
    await notice.locator('.thickbox').click();
    const details=page.frameLocator('#TB_iframeContent');
    await details.locator('body').waitFor();
    ok((await details.locator('body').innerText()).includes('0.5.1'),'native plugin-information details');
    await page.screenshot({path:path.join(out,'plugin-information.png'),fullPage:true});
    await page.locator('#TB_closeWindowButton').click();
    await notice.locator('.update-link').click();
    await page.locator('#wp-seed-pixel-update .updated-message').waitFor({timeout:60000});
    ok(true,'native update action successful');
    await page.reload();
    const updated=page.locator('tr[data-plugin="wp-seed-pixel/wp-seed-pixel.php"]').first();
    ok((await updated.innerText()).includes('0.5.1'),'new installed version shown after reload');
    const activate=updated.locator('a').filter({hasText:/^Activate$/});
    if(await activate.count()){await Promise.all([page.waitForURL('**/plugins.php**'),activate.click()]);}
    for(const width of [1440,820,390,320]){
      await page.setViewportSize({width,height:1000});
      await page.screenshot({path:path.join(out,'plugins-installed-'+width+'.png'),fullPage:true});
      ok(await updated.isVisible(),'native update UI visible '+width);
    }
    if(errors.length){fs.writeFileSync(path.join(out,'browser-errors.json'),JSON.stringify(errors,null,2));}
    ok(errors.length===0,'console page errors zero');
    fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({status:'PASS',checks,count:Object.keys(checks).length,page_errors:errors},null,2));
    process.stdout.write(Object.keys(checks).length+' native browser checks PASS\n');
  } finally {await context.close();await browser.close();}
})().catch(e=>{process.stderr.write(e.stack+'\n');process.exitCode=1;});
