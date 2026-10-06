const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
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
  assert.equal(await page.locator('#adminmenu a[href^="upload.php?page=wp-seed-pixel"]').count(),1);checks.oneMenu=true;
  assert.equal(await page.locator('input[name="png"]').isEnabled(),true);checks.pngNoBudget=true;
  assert.equal(await page.locator('.wp-seed-pixel > h2').count(),3);checks.threeSections=true;
  assert.equal(await page.getByText('Configure storage safety',{exact:true}).count(),0);checks.noStorageJourney=true;
  assert.equal(await page.locator('#pixel-pause').isVisible(),false);checks.noIdleControls=true;
  await page.locator('#pixel-select').click();
  await page.locator('.media-modal').getByRole('tab',{name:'Media Library',exact:true}).click();
  for(const id of [login.jpg,login.png]) await page.locator(`.media-modal .attachment[data-id="${id}"]`).click({modifiers:['Control']});
  await page.locator('.media-modal .media-button-select').click();
  assert.match(await page.locator('#pixel-selection-count').innerText(),/2/);checks.nativeSelection=true;
  assert.equal(await page.locator('#pixel-selected-start').isEnabled(),true);checks.explicitStart=true;
  for(const width of [1440,820,390,320]) {
   await page.setViewportSize({width,height:1000});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);checks['main'+width]=true;
   await page.screenshot({path:path.join(out,`main-${width}.png`),fullPage:true});
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.goto(`http://127.0.0.1:8877/wp-admin/upload.php?mode=grid&item=${login.unprocessed}`);
  if(!await page.locator('.media-modal').isVisible()) await page.locator(`.attachment[data-id="${login.unprocessed}"]`).first().click();
  const panel=page.locator('.media-modal .pixel-media-panel').first();await panel.waitFor();
  assert.equal(await panel.locator('.pixel-media-panel').count(),0);checks.noNestedLegacyPanel=true;
  assert.equal(await panel.locator(':scope > details').count(),1);checks.singleTechnicalDetails=true;
  assert.equal(await panel.locator('a[href*="wp-seed-pixel-bulk"]').count(),0);checks.noLegacyJourney=true;
  assert.match(await panel.innerText(),/Not yet optimized|Pas encore optimisée/);checks.unprocessedClear=true;
  assert.equal(await panel.getByRole('button',{name:/^(Optimize this image|Optimiser cette image)$/}).count(),1);checks.singleAction=true;
  const summary=panel.locator(':scope > details > summary');await summary.focus();await page.keyboard.press('Enter');
  assert.equal(await panel.locator(':scope > details').getAttribute('open'),'');checks.detailsKeyboard=true;
  await page.keyboard.press('Enter');
  for(const width of [1440,820,390,320]) {
   await page.setViewportSize({width,height:1000});await panel.scrollIntoViewIfNeeded();
   if(await panel.evaluate(el=>el.scrollWidth>el.clientWidth+2)) {
    await panel.screenshot({path:path.join(out,`overflow-${width}.png`)});
    console.log(JSON.stringify(await panel.evaluate(el=>({width:el.clientWidth,scroll:el.scrollWidth,children:[...el.querySelectorAll('*')].filter(c=>c.getBoundingClientRect().width>el.clientWidth).map(c=>({tag:c.tagName,cls:c.className,width:c.getBoundingClientRect().width}))}))));
   }
   assert.equal(await panel.evaluate(el=>el.scrollWidth>el.clientWidth+2),false);checks['panel'+width]=true;
   await panel.screenshot({path:path.join(out,`panel-${width}.png`)});
  }
  await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.body.style.zoom='2');
  await panel.screenshot({path:path.join(out,'panel-zoom200.png')});checks.zoom200=true;
  await page.evaluate(()=>document.body.style.zoom='');await page.locator('.media-modal .media-modal-close').click();
  await page.goto(`http://127.0.0.1:8877/wp-admin/upload.php?mode=grid&item=${login.retained}`);
  if(!await page.locator('.media-modal').isVisible()) await page.locator(`.attachment[data-id="${login.retained}"]`).first().click();
  const retained=page.locator('.media-modal .pixel-media-panel').first();await retained.waitFor();
  const confirmation=retained.locator('.pixel-delete-confirmation');await confirmation.locator('summary').focus();await page.keyboard.press('Enter');
  const purge=confirmation.locator('button');assert.equal(await purge.isEnabled(),false);checks.purgeInitiallyDisabled=true;
  const checkbox=confirmation.locator('input');await checkbox.focus();await page.keyboard.press('Space');
  assert.equal(await purge.isEnabled(),true);checks.purgeExplicitKeyboardConfirmation=true;
  await checkbox.uncheck();assert.equal(await purge.isEnabled(),false);
  await retained.screenshot({path:path.join(out,'retained-confirmation.png')});
  await page.locator('.media-modal .media-modal-close').click();
  await page.goto(`http://127.0.0.1:8877/wp-admin/upload.php?mode=grid&item=${login.unprocessed}`);
  if(!await page.locator('.media-modal').isVisible()) await page.locator(`.attachment[data-id="${login.unprocessed}"]`).first().click();
  const action=page.locator('.media-modal [data-operation="image_start"]').first();
  await action.waitFor();await action.focus();await page.keyboard.press('Enter');
  const restored=page.locator('.media-modal [data-operation="image_restore"]').first();
  await restored.waitFor({timeout:120000});checks.optimizeViaKeyboardAjax=true;
  await restored.focus();await page.keyboard.press('Enter');
  await page.locator('.media-modal [data-operation="image_start"]').first().waitFor({timeout:120000});checks.restoreViaKeyboardAjax=true;
  await page.locator('.media-modal .pixel-media-panel').first().screenshot({path:path.join(out,'after-restore.png')});
  assert.deepEqual(errors,[]);checks.consoleClean=true;
  fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks,errors,realSiteMutations:0},null,2));
  console.log(JSON.stringify({checks:Object.keys(checks).length,errors:0,result:'PASS'}));await context.close();
 } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
