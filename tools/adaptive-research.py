"""Offline R&D only: generated fixtures and local read-only private masters."""
from pathlib import Path
import argparse, hashlib, json, math, random, csv
import numpy as np
from PIL import Image, ImageDraw

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--private-source', type=Path)
args = parser.parse_args()
work = root / '.runtime/adaptive-research'
work.mkdir(parents=True, exist_ok=True)
data = root / 'reports/adaptive/data'
data.mkdir(parents=True, exist_ok=True)
rows, sources = [], []
for i in range(24):
    # Six holdouts use different seeds, dimensions and compression; not tuning input.
    rng = np.random.default_rng(7100 + i)
    w, h = ((2400, 1600) if i < 12 else (2100, 2800))
    y, x = np.mgrid[:h, :w]
    pattern = i % 6
    if pattern == 0:
        a = np.stack([x * 255 / w, y * 255 / h, (x+y)*127/(w+h)], -1)
    elif pattern == 1:
        a = rng.integers(0, 256, (h, w, 3))
    elif pattern == 2:
        a = np.stack([40+30*np.sin(x/5), 110+80*np.sin((x+y)/13), 60+40*np.cos(y/7)], -1)
    elif pattern == 3:
        a = np.stack([10+x*25/w, 8+y*20/h, 10+20*np.sin(x/40)**2], -1)
    else:
        a = np.full((h,w,3), 225)
    im = Image.fromarray(np.clip(a,0,255).astype('uint8'), 'RGB')
    draw = ImageDraw.Draw(im)
    if pattern == 4:
        for k in range(0,h,37):
            draw.text((21,k), 'IMAGE TEST / architecture 12345 / sharp edges', fill=(10,20,30), stroke_width=1)
            draw.line((w//2,k,w-10,k+24), fill=(30,45,100), width=3)
    if pattern == 5:
        draw.ellipse((w*.2,h*.15,w*.8,h*.85), fill=(190,139,105))
        for px in (.38,.62):
            draw.ellipse((w*px-25,h*.38,w*px+25,h*.38+35), fill=(45,30,20))
        draw.arc((w*.3,h*.4,w*.7,h*.7),0,180,fill=(75,35,25),width=8)
    if i >= 18:
        # Independent challenge holdout: fine achromatic texture, not private images.
        base = np.asarray(im,dtype=np.float64)
        grain = rng.normal(0, 5 + (i-18)*5, (h,w,1))
        im = Image.fromarray(np.clip(base*.65 + 50 + grain,0,255).astype('uint8'),'RGB')
    source = work / f'S{i+1:02d}.jpg'
    im.save(source, quality=96 if i < 12 else 89)
    sources.append((source.stem, 'research' if i < 12 else 'holdout', source))
if args.private_source:
    files = sorted(args.private_source.glob('*.jpg'))
    if len(files) < 12:
        raise ValueError('Missing authorized private corpus')
    sources += [(f'B{i+1:02d}','private',files[round(i*(len(files)-1)/11)]) for i in range(12)]

def metric(reference, candidate):
    # Same-size reference, then bounded analysis. Blocks protect local structure.
    positions = [(round(i*(reference.width-8)/7),round(j*(reference.height-8)/7)) for j in range(8) for i in range(8)]
    ref = np.array([np.asarray(reference.crop((x,y,x+8,y+8)),dtype=np.float64) for x,y in positions])
    out = np.array([np.asarray(candidate.crop((x,y,x+8,y+8)),dtype=np.float64) for x,y in positions])
    mse = np.mean((ref-out)**2)
    psnr = 99 if mse == 0 else 10*math.log10(255**2/mse)
    weights = np.array([.299,.587,.114])
    a, b = ref@weights, out@weights
    a = a.reshape(-1,64)
    b = b.reshape(-1,64)
    ma, mb = a.mean(1), b.mean(1)
    va, vb = a.var(1), b.var(1)
    cov = ((a-ma[:,None])*(b-mb[:,None])).mean(1)
    s = ((2*ma*mb+6.5025)*(2*cov+58.5225))/((ma**2+mb**2+6.5025)*(va+vb+58.5225))
    return float(s.mean()), float(psnr)

masters = []
for ident, group, source in sources:
    before = hashlib.sha256(source.read_bytes()).hexdigest()
    im = Image.open(source).convert('RGB')
    masters.append(dict(id=ident,group=group,bytes=source.stat().st_size,width=im.width,height=im.height,sha256=before))
    for limit in (640,1600,1800,1920,2048):
        reference = im.copy()
        reference.thumbnail((limit,limit),Image.Resampling.LANCZOS)
        for q in (72,78,82,86,90,94):
            dest = work / 'candidate.jpg'
            reference.save(dest,quality=q)
            candidate = Image.open(dest).convert('RGB')
            s,p = metric(reference,candidate)
            rows.append(dict(id=ident,group=group,limit=limit,quality=q,bytes=dest.stat().st_size,ssim=round(s,6),psnr=round(p,3)))
    if hashlib.sha256(source.read_bytes()).hexdigest() != before:
        raise ValueError('MASTER modified')
    print(ident, group, flush=True)
(data/'research-grid.json').write_text(json.dumps(dict(masters=masters,candidates=rows),indent=2),encoding='utf-8')
with (data/'research-grid.csv').open('w',newline='',encoding='utf-8') as f:
    writer=csv.DictWriter(f,fieldnames=list(rows[0]));writer.writeheader();writer.writerows(rows)
(work/'candidate.jpg').unlink(missing_ok=True)
print('Research grid complete; no private source copied or modified.')
