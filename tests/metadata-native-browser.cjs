const {chromium}=require('playwright');
const fs=require('fs');const path=require('path');
(async()=>{
  const state=JSON.parse(fs.readFileSync(process.argv[2],'utf8'));
  const out=process.argv[3];fs.mkdirSync(out,{recursive:true});
  const browser=await chromium.launch({headless:true,channel:'chrome'});
  const checks=[];const errors=[];
  const ok=(v,n)=>{if(!v)throw Error(n);checks.push(n);};
  try {
    const context=await browser.newContext();await context.addCookies(state.cookies);
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://127.0.0.1:8877/wp-admin/media-new.php',{waitUntil:'networkidle'});
    const upload=page.waitForResponse(r=>r.url().includes('/async-upload.php')&&r.request().method()==='POST');
    await page.locator('input[type=file]').first().setInputFiles({name:`synthetic-native-${Date.now()}.jpg`,mimeType:'image/jpeg',buffer:fs.readFileSync('C:/Users/WaRZy/AppData/Local/Temp/codex-pixel-060-metadata-audit-20261007/fixtures/jpeg-exif.jpg')});
    const response=await upload;const body=await response.json();
    const uploadId=Number(typeof body==='number'?body:body.success&&body.data.id);
    ok(response.ok()&&Number.isSafeInteger(uploadId)&&uploadId>0,'actual native multipart upload succeeds');
    await page.goto(`http://127.0.0.1:8877/wp-admin/post.php?post=${uploadId}&action=edit`,{waitUntil:'networkidle'});
    ok(await page.locator('.pixel-metadata [data-state=anonymized]').count()===1,'multipart upload automatically anonymized');
    ok(await page.locator('.pixel-metadata [data-operation=start]').count()===0,'multipart upload no redundant action');
    await page.screenshot({path:path.join(out,'automatic-upload.png'),fullPage:true});
    await page.goto(`http://127.0.0.1:8877/wp-admin/post.php?post=${state.ids.success}&action=edit`,{waitUntil:'networkidle'});
    const panel=page.locator('.pixel-metadata');
    ok(await panel.count()===1,'actual WordPress attachment editor');
    ok(await panel.locator('[value=keep]').isChecked(),'keep default');
    await panel.locator('[data-operation=analyze]').click();
    try {await page.waitForFunction(()=>!!document.querySelector('.pixel-metadata-approval'));}
    catch(e){throw Error('Analysis: '+await panel.innerText());}
    ok(await panel.locator('[data-operation=start]').isDisabled(),'explicit double consent required');
    await page.screenshot({path:path.join(out,'manual-before.png'),fullPage:true});
    await panel.locator('[value=anonymize]').press('Space');
    ok(await panel.locator('[value=anonymize]').isChecked(),'anonymize selected with keyboard');
    await panel.locator('.pixel-metadata-approval').press('Space');
    ok(await panel.locator('.pixel-metadata-approval').isChecked(),'explicit consent checked with keyboard');
    ok(await panel.locator('[data-operation=start]').isEnabled(),'explicit consent enables action');
    await panel.locator('[data-operation=start]').press('Enter');
    try {await page.waitForFunction(()=>document.querySelector('.pixel-metadata-result')?.dataset.state==='anonymized');}
    catch(e){throw Error('Transaction: '+await panel.innerText());}
    ok(await page.locator('[data-operation=image_restore]').count()===1,'existing restore control immediately visible');
    ok(await panel.locator('[data-operation=start]').count()===0,'anonymized has no redundant action');
    ok(await panel.locator('[value=anonymize]').count()===0,'anonymized has no redundant radio');
    ok(/L['’]original conservé/.test(await panel.innerText()),'private-original disclosure French');
    ok(!(await panel.innerText()).includes('Synthetic author'),'no raw privacy values displayed');
    ok(await panel.locator('[data-operation=analyze]').evaluate(e=>e===document.activeElement),'focus retained after successful replacement');
    for(const width of [1440,820,390,320]){
      await page.setViewportSize({width,height:1000});
      ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} editor overflow`);
      const box=await panel.boundingBox();ok(box.width<=width,`${width} metadata panel bounded`);
      await panel.locator('[data-operation=analyze]').focus();
      ok(await panel.locator('[data-operation=analyze]').evaluate(e=>e===document.activeElement),`${width} keyboard focus`);
      ok(await panel.locator('[data-operation=analyze]').evaluate(e=>{const s=getComputedStyle(e);return(s.outlineStyle!=='none'&&parseFloat(s.outlineWidth)>0)||s.boxShadow!=='none';}),`${width} focus indicator`);
      if(width===390)await page.screenshot({path:path.join(out,'success-390.png'),fullPage:true});
      await page.evaluate(()=>window.scrollTo(0,0));
      await page.screenshot({path:path.join(out,`attachment-${width}.png`),fullPage:true});
    }
    await page.setViewportSize({width:720,height:1000});await page.evaluate(()=>document.documentElement.style.zoom='2');
    ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'200 percent zoom without overflow');
    await page.evaluate(()=>window.scrollTo(0,0));
    await page.screenshot({path:path.join(out,'attachment-zoom200.png'),fullPage:true});
    await page.evaluate(()=>document.documentElement.style.zoom='1');
    await page.setViewportSize({width:390,height:1000});
    await page.reload({waitUntil:'networkidle'});ok(await panel.locator('[data-state=anonymized]').count()===1,'success survives reload');
    await page.locator('[data-operation=image_restore]').press('Enter');
    try {await page.waitForFunction(()=>document.querySelector('.pixel-metadata-result')?.dataset.state==='restored');}
    catch(e){throw Error('Restore: '+await panel.innerText());}
    ok(await panel.locator('[data-state=restored]').count()===1,'existing restore action returns original');
    ok(await panel.locator('[data-operation=start]').count()===1,'restored metadata offers manual action');
    await page.screenshot({path:path.join(out,'manual-restored.png'),fullPage:true});
    for(const name of ['orientation','provenance']){
      await page.goto(`http://127.0.0.1:8877/wp-admin/post.php?post=${state.ids[name]}&action=edit`,{waitUntil:'networkidle'});
      await page.locator('.pixel-metadata [data-operation=analyze]').click();
      await page.waitForFunction(n=>document.querySelector('.pixel-metadata-result')?.dataset.state===`blocked_${n}`,name);
      ok(await page.locator('.pixel-metadata [data-operation=start]').count()===0,`${name} cannot write`);
      await page.screenshot({path:path.join(out,`${name}-390.png`),fullPage:true});
    }
    await page.goto('http://127.0.0.1:8877/wp-admin/upload.php?page=wp-seed-pixel',{waitUntil:'networkidle'});
    const automatic=page.locator('input[name=metadata_automatic]');
    ok(await automatic.isChecked(),'automatic privacy setting displayed ON');
    for(const width of [1440,820,390,320]){
      await page.setViewportSize({width,height:1000});
      await automatic.focus();
      ok(await automatic.evaluate(e=>e===document.activeElement),`${width} setting keyboard reachable`);
      ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} settings no overflow`);
      await page.screenshot({path:path.join(out,`settings-${width}.png`),fullPage:true});
    }
    ok(errors.length===0,'no JS errors');
    fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({count:checks.length,checks,errors,isolated:true,realWordPress:true},null,2));
    console.log(`${checks.length} authenticated isolated WordPress UI checks PASS`);
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
