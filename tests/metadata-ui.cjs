/* Isolated headless UI test. Synthetic adapters, not WordPress integration. */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
(async () => {
  const root = process.argv[2];
  const browser = await chromium.launch({headless:true,channel:'chrome'});
  const checks=[]; const errors=[];
  try {
    const page=await browser.newPage();
    let cleanPanel='';
    page.on('pageerror',e=>errors.push(e.message));
    const assert=(value,name)=>{if(!value)throw Error(name);checks.push(name);};
    await page.route('https://fixture.invalid/ajax',async route=>{
      const params=new URLSearchParams(route.request().postData());
      assert(params.get('operation')==='analyze','UI never initiates a write');
      await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{panel:cleanPanel}})});
    });
    await page.goto(pathToFileURL(path.join(root,'outputs/ui.html')).href);
    cleanPanel=await page.locator('.pixel-metadata').evaluate(e=>e.outerHTML);
    assert(await page.locator('[value="keep"]').isChecked(),'Conserver default');
    assert(await page.locator('[data-operation="start"]').count()===0,'write control withheld before certification');
    for(const width of [1440,820,390,320]){
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} no overflow`);
      const rect=await page.locator('main').boundingBox();
      assert(rect.width<=width,`${width} bounded panel`);
      await page.locator('[value="keep"]').focus();
      await page.keyboard.press('ArrowRight');
      assert(await page.locator('[value="anonymize"]').isChecked(),`${width} keyboard radio`);
      await page.keyboard.press('Tab');
      assert(await page.locator('[data-operation="analyze"]').evaluate(e=>e===document.activeElement),`${width} keyboard reaches analysis`);
      assert(await page.locator('button').evaluate(e=>getComputedStyle(e).outlineStyle!=='none'),`${width} visible focus`);
      if(width===390)await page.screenshot({path:path.join(root,'outputs/ui-390.png'),fullPage:true});
    }
    await page.setViewportSize({width:720,height:1000});
    await page.evaluate(()=>document.documentElement.style.zoom='2');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'200% zoom no overflow');
    await page.evaluate(()=>document.documentElement.style.zoom='1');
    await page.locator('button').click();
    await page.waitForFunction(()=>!document.querySelector('[data-busy]'));
    assert(await page.locator('button').evaluate(e=>e===document.activeElement),'focus retained after analysis');
    assert(errors.length===0,'no JavaScript exception');
    fs.writeFileSync(path.join(root,'outputs/ui-checks.json'),JSON.stringify({checks,errors,environment:'synthetic read adapters; isolated Chromium headless'},null,2));
    console.log(`${checks.length} isolated UI assertions PASS`);
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
