const {chromium}=require('playwright');
const fs=require('fs');
const path=require('path');
const assert=require('assert/strict');
(async()=>{
 const login=JSON.parse(fs.readFileSync(process.env.PIXEL_LAB_LOGIN,'utf8'));
 const out=process.env.PIXEL_QA_OUT;fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 const checks={},errors=[];
 try {
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:8877/wp-login.php');
  await page.locator('#user_login').fill(login.user);await page.locator('#user_pass').fill(login.password);
  await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
  await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel');
  await page.waitForFunction(()=>document.getElementById('pixel-result').textContent.length>10);
  assert.equal(await page.locator('#adminmenu a[href^="upload.php?page=wp-seed-pixel"]').count(),2);checks.menu=true;
  assert.equal(await page.locator('input[name="png"]').count(),1);checks.png=true;
  assert.equal(await page.locator('#pixel-pause').isVisible(),false);checks.noIdlePause=true;
  assert.equal(await page.locator('#pixel-resume').isVisible(),false);checks.noIdleResume=true;
  await page.locator('#pixel-select').click();
  await page.screenshot({path:path.join(out,'local-media-picker.png'),fullPage:true});
  await page.locator('.media-modal').getByRole('tab',{name:'Media Library',exact:true}).click();
  await page.screenshot({path:path.join(out,'local-media-library.png'),fullPage:true});
  for(const id of [login.jpg,login.png]) {await page.locator(`.media-modal .attachment[data-id="${id}"]`).click({modifiers:['Control']});}
  await page.locator('.media-modal .media-button-select').click();
  assert.match(await page.locator('#pixel-selection-count').innerText(),/2/);
  assert.equal(await page.locator('#pixel-selection-review li').count(),2);checks.nativeMediaMultiselection=true;
  assert.equal(await page.locator('#pixel-selected-start').isEnabled(),true);checks.explicitStart=true;
  for(const width of [1440,820,390]) {
   await page.setViewportSize({width,height:1000});
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2);
   assert.equal(overflow,false);checks['main'+width]=true;
   await page.screenshot({path:path.join(out,`local-main-${width}.png`),fullPage:true});
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(`http://127.0.0.1:8877/wp-admin/upload.php?mode=grid&item=${login.png}`);
  await page.locator(`.attachment[data-id="${login.png}"]`).first().click();
  await page.screenshot({path:path.join(out,'local-detail-loaded.png'),fullPage:true});
  const panel=page.locator('.pixel-media-panel');await panel.waitFor();
  const summary=panel.locator('summary');await summary.focus();await page.keyboard.press('Enter');
  assert.equal(await panel.locator('details').getAttribute('open'),'');
  assert.match(await panel.innerText(),/Dimensions:/);checks.technicalDetailsKeyboard=true;
  for(const width of [1440,820,390]) {
   await page.setViewportSize({width,height:1000});
   await panel.scrollIntoViewIfNeeded();
   await panel.screenshot({path:path.join(out,`local-panel-${width}.png`)});
   await page.screenshot({path:path.join(out,`local-detail-${width}.png`),fullPage:true});checks['details'+width]=true;
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.evaluate(()=>document.body.style.zoom='2');
  assert.equal(await summary.isVisible(),true);checks.zoom200=true;
  await page.screenshot({path:path.join(out,'local-detail-zoom200.png'),fullPage:true});
  assert.deepEqual(errors,[]);checks.noPageErrors=true;
  fs.writeFileSync(path.join(out,'local-browser.json'),JSON.stringify({checks,errors,mediaProcessingClicks:0},null,2));
  console.log(JSON.stringify({checks:Object.keys(checks).length,errors:errors.length,result:'PASS'}));
  await context.close();
 } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
