"""Compare guarded fixed baselines against PHP results; no image input."""
from pathlib import Path
import json

root=Path(__file__).resolve().parents[1]
report=root/'reports/adaptive'
rows=json.loads((report/'data/runtime-benchmark.json').read_text())['rows']
qualities=(78,82,86,90,94)
floors={'thumb':(.960,30.0),'view':(.985,35.0)}
def fixed_bytes(r,name,q,shift=0.0):
    f=r['files'][name]
    if f['kind']=='master' and f['candidates']==0:
        return r['master_bytes']
    c=r['fixed'][name][str(q)];s,p=floors[name]
    if c['metric']['ssim'] < s+shift or c['metric']['psnr'] < p or c['bytes'] > .95*r['master_bytes']:
        return r['master_bytes']
    return c['bytes']
research=[r for r in rows if r['group']=='research']
totals={q:sum(fixed_bytes(r,n,q) for r in research for n in ('thumb','view')) for q in qualities}
best=min(totals,key=totals.get)
groups={}
for group in ('research','holdout','private'):
    subset=[r for r in rows if r['group']==group]
    baseline=sum(fixed_bytes(r,n,best) for r in subset for n in ('thumb','view'))
    adaptive=sum(r['files'][n]['bytes'] for r in subset for n in ('thumb','view'))
    groups[group]={'fixed_bytes':baseline,'adaptive_bytes':adaptive,'saving_percent':round(100*(1-adaptive/baseline),3)}
justified=groups['holdout']['saving_percent'] > 1
sensitivity={}
for shift in (-.005,0,.005):
    total=0;count=0
    for r in rows:
        for n in ('thumb','view'):
            f=r['files'][n]
            if f['kind']=='master' and f['candidates']==0:
                total+=r['master_bytes'];continue
            s,p=floors[n]
            chosen=r['master_bytes']
            for q in (78,86,94):
                count+=1;c=r['fixed'][n][str(q)]
                if c['metric']['ssim']>=s+shift and c['metric']['psnr']>=p:
                    if c['bytes']<=.95*r['master_bytes']:chosen=c['bytes']
                    break
            total+=chosen
    sensitivity[str(shift)]={'served_bytes':total,'encodes':count}
d={'fixed_best_research_quality':best,'research_quality_totals':totals,'groups':groups,'ssim_sensitivity':sensitivity,'adaptive_justified':justified}
(report/'data/value.json').write_text(json.dumps(d,indent=2),encoding='utf-8')
text='# Adaptive value / Occam check\n\n'
text+=f'FIXED BEST BASELINE: Q{best}, selected on 12 research fixtures only, with identical quality/byte gates and safe source reuse.\n\n'
text+='| Corpus | Guarded fixed bytes | Adaptive bytes | Adaptive saving |\n| --- | ---: | ---: | ---: |\n'
for g,v in groups.items():text+=f'| {g} | {v["fixed_bytes"]} | {v["adaptive_bytes"]} | {v["saving_percent"]}% |\n'
text+=f'\nADAPTIVE JUSTIFIED: {"YES" if justified else "NO"}.\n\n'
text+='The fixed baseline is not an unconditional bad encoder: rejected/larger files fall back to MASTER exactly as the adaptive strategy does. Holdout includes six untouched initial fixtures and six independent fine-texture challenges. This tests generalization but is not a representative commercial photo corpus. Private examples do not select the fixed baseline.\n\n'
text+='SSIM floor sensitivity (+/-0.005), holding RGB PSNR floors fixed:\n\n'
for shift,v in sensitivity.items():text+=f'- {shift}: {v["served_bytes"]} bytes, {v["encodes"]} encodes.\n'
text+='\nThe chosen metric is sampled and may miss localized defects. CPU cost and wider human validation remain tradeoffs; no claim that adaptive is universally superior. No threshold parameter is delegated to Guillaume.\n'
(report/'ADAPTIVE-VALUE.md').write_text(text,encoding='utf-8')
print(json.dumps(d,indent=2))
