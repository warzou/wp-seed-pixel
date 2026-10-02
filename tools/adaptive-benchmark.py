"""Run the final PHP plugin, build a local-only human comparison and summarize."""
from pathlib import Path
import argparse, hashlib, html, json, subprocess, statistics

root = Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser(); p.add_argument('--private-source',type=Path,required=True);p.add_argument('--output',type=Path,required=True);p.add_argument('--php',type=Path,required=True)
p.add_argument('--artifact-verify',action='store_true')
a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True)
files=sorted(a.private_source.glob('*.jpg'))
index=[dict(id=f'B{i+1:02d}',group='private',source=str(files[round(i*(len(files)-1)/11)])) for i in range(12)]
if not a.artifact_verify:
    index += [dict(id=f'S{i+1:02d}',group='research' if i<12 else 'holdout',source=str(root/f'.runtime/adaptive-research/S{i+1:02d}.jpg')) for i in range(24)]
inp=root/'.runtime/adaptive-input.json';inp.write_text(json.dumps(index),encoding='utf-8')
cmd=[str(a.php),'-d',f'extension_dir={a.php.parent / "ext"}']
for ext in ['gd','exif','mbstring','pdo_sqlite','mysqli']:cmd+=['-d',f'extension={ext}']
cmd+=['-d','memory_limit=512M','-d','disable_functions=fsockopen,pfsockopen,stream_socket_client,curl_exec',str(root/'tests/adaptive-benchmark.php'),str(inp),str(a.output)]
filename='zip-runtime-benchmark.json' if a.artifact_verify else 'runtime-benchmark.json'
cmd.append(filename)
try:subprocess.run(cmd,check=True)
finally:inp.unlink(missing_ok=True)
d=json.loads((root/'reports/adaptive/data'/filename).read_text());rows=d['rows']
if a.artifact_verify:
    if d['version']!='0.2.0' or d['network_attempts'] or not all(r['master_preserved'] and r['unchanged_fast_path'] for r in rows):raise ValueError('Installed ZIP verification failed')
    print(json.dumps(dict(installed_zip=d['version'],algorithm=d['algorithm_version'],images=len(rows),masters_preserved=sum(r['master_preserved'] for r in rows),network=d['network_attempts']),indent=2))
    raise SystemExit(0)
sections=[]
for source,r in zip(index[:12],rows[:12]):
    media=[('MASTER',Path(source['source'])),('V0.1 THUMB',a.output.parent/'wp-seed-pixel-local-benchmark'/f'{r["id"]}-thumb.jpg'),('V0.1 VIEW',a.output.parent/'wp-seed-pixel-local-benchmark'/f'{r["id"]}-view.jpg')]
    for name in ['thumb','view']:
        f=r['files'][name]
        media.append((f'RECOMMENDED {name.upper()} / {f["bytes"]} bytes / {f["kind"]}',Path(source['source']) if f['kind']=='master' else a.output/f'{r["id"]}-{name}.jpg'))
    figures=''.join(f'<figure><a href="{html.escape(f.as_uri())}"><img loading="lazy" src="{html.escape(f.as_uri())}" alt="{r["id"]} {html.escape(label)}"></a><figcaption>{html.escape(label)}</figcaption></figure>' for label,f in media)
    sections.append(f'<section><h2>{r["id"]}</h2><div class="media">{figures}</div><p>{html.escape(r["files"]["view"]["reason"])}</p></section>')
page='''<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pixel adaptive - human pass local</title><style>body{margin:0;font:16px system-ui;color:#23282d;background:#fafafa}header,main{max-width:1300px;margin:auto;padding:24px}h1{font-size:28px}h2{font-size:21px}.media{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}figure{margin:0}img{width:100%;height:300px;object-fit:contain;background:#e4e9e8}figcaption{padding:8px 0;overflow-wrap:anywhere}section{padding:24px 0;border-top:1px solid #929f9b}a:focus-visible{outline:3px solid #b62649}@media(max-width:700px){.media{grid-template-columns:1fr}header,main{padding:16px}}</style><header><h1>WP Seed Pixel 0.2.0</h1><p>HUMAN PASS local uniquement. Comparer les details, visages, textures et degrades. Le score mesure n'est pas une certification visuelle.</p></header><main>'''+''.join(sections)+'</main></html>'
(root/'reports/adaptive/WP-SEED-PIXEL-ADAPTIVE-VISUAL-BENCHMARK.html').write_text(page,encoding='utf-8')
summary={}
for group in ['research','holdout','private']:
    subset=[r for r in rows if r['group']==group]
    n=len(subset);masters=sum(r['master_bytes'] for r in subset)
    summary[group]=dict(images=n,master_bytes=masters,masters_preserved=sum(r['master_preserved'] for r in subset),thumb_bytes=sum(r['files']['thumb']['bytes'] for r in subset),view_bytes=sum(r['files']['view']['bytes'] for r in subset),view_reused=sum(r['files']['view']['kind']=='master' for r in subset),view_larger=sum(r['files']['view']['bytes']>r['master_bytes'] for r in subset),view_over_500KB=sum(r['files']['view']['bytes']>500000 for r in subset),view_over_1MB=sum(r['files']['view']['bytes']>1000000 for r in subset),candidates_mean=statistics.mean(r['candidates'] for r in subset),candidates_max=max(r['candidates'] for r in subset),seconds_mean=statistics.mean(r['seconds'] for r in subset),seconds_max=max(r['seconds'] for r in subset),added_disk_bytes=sum(r['added_disk_bytes'] for r in subset))
(root/'reports/adaptive/data/summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps(summary,indent=2))
