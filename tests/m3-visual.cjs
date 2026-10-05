'use strict';
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
    const root = path.resolve(__dirname, '../reports/storage-m3');
    const cases = ['same','downsize','small','rights','scaled','icc','p3'];
    const uri = file => 'data:image/jpeg;base64,'+fs.readFileSync(path.join(root,'visual',file)).toString('base64');
    const browser = await chromium.launch({headless:true,channel:'chrome'});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}, deviceScaleFactor:1});
        await page.setContent('<!doctype html><html><head><meta charset="utf-8"><style>body{margin:24px;font:16px Arial;color:#161616;background:#fff}h1{font-size:24px}section{margin-bottom:24px}h2{font-size:18px}div{display:grid;grid-template-columns:1fr 1fr;gap:20px}figure{margin:0}img{width:100%;height:auto;display:block}figcaption{padding:8px 0}</style></head><body><h1>Pixel M3 - synthetic before / verified candidate</h1>'+cases.map(c => `<section><h2>${c}</h2><div><figure><img src="${uri(c+'-before.jpg')}"><figcaption>Source / recovery bytes</figcaption></figure><figure><img src="${uri(c+'-after.jpg')}"><figcaption>Verified candidate</figcaption></figure></div></section>`).join('')+'</body></html>');
        await page.locator('img').evaluateAll(imgs => Promise.all(imgs.map(i => i.decode())));
        await page.screenshot({path:path.join(root,'quality-comparison.png'),fullPage:true});
        // 1:1 center crop reveals ringing/text/edges hidden in a whole-image thumbnail.
        await page.setContent('<style>body{margin:16px;background:white;font:16px Arial}div{display:flex;gap:16px}canvas{width:640px;height:420px}</style><h1>Same-dimension source / candidate - 1:1 pixels</h1><div><canvas id="a" width="640" height="420"></canvas><canvas id="b" width="640" height="420"></canvas></div>');
        await page.evaluate(async images => { for (let n=0;n<2;n++) { const im=new Image(); im.src=images[n]; await im.decode(); document.getElementById(n?'b':'a').getContext('2d').drawImage(im,400,450,640,420,0,0,640,420); } }, [uri('same-before.jpg'),uri('same-after.jpg')]);
        await page.screenshot({path:path.join(root,'quality-native-crop.png')});
    } finally { await browser.close(); }
    console.log('Synthetic visual comparison and native crop captured');
})().catch(e => {console.error(e.message);process.exitCode=1;});
