"""Generate factual delivery reports from completed tests and measurements."""
from pathlib import Path
import collections, hashlib, json, statistics, subprocess

root=Path(__file__).resolve().parents[1]
report=root/'reports/adaptive'
def read(name):return json.loads((report/'data'/name).read_text())
def write(name,text):(report/name).write_text(text,encoding='utf-8')
allrows=read('runtime-benchmark.json')['rows']
artifact=read('zip-runtime-benchmark.json')
private=artifact['rows']
value=read('value.json');package=read('package-manifest.json')
if artifact['engine_sha256']!=hashlib.sha256((root/'includes/class-engine.php').read_bytes()).hexdigest() or artifact['adaptive_sha256']!=hashlib.sha256((root/'includes/class-adaptive.php').read_bytes()).hexdigest():raise ValueError('ZIP benchmark is not the final engine')
if not value['adaptive_justified']:raise ValueError('Simplify strategy before declaring adaptive ready')
counts=collections.Counter()
for f in (report/'cycle-5').glob('*.json'):
    d=json.loads(f.read_text());ts=d['tests'] if isinstance(d,dict) else d
    counts.update(t['status'] for t in ts)
for name in ['fallback-unit-tests.json','upgrade-tests.json','board-tests.json']:
    counts.update(t['status'] for t in read(name))
d=json.loads((root/'reports/final/lifecycle-results.json').read_text());counts.update(t['status'] for t in d['tests'])
if counts['FAIL']:raise ValueError('Blocking final test failure')
master=sum(r['master_bytes'] for r in private);thumb=sum(r['files']['thumb']['bytes'] for r in private);view=sum(r['files']['view']['bytes'] for r in private)
gain=lambda n:round(100*(1-n/master),2)
weighted=dict(master_bytes=master,thumb_bytes=thumb,thumb_gain_percent=gain(thumb),view_bytes=view,view_gain_percent=gain(view),view_reused=sum(r['files']['view']['kind']=='master' for r in private),view_larger=sum(r['files']['view']['bytes']>r['master_bytes'] for r in private),view_over_500KB=sum(r['files']['view']['bytes']>500000 for r in private),view_over_1MB=sum(r['files']['view']['bytes']>1000000 for r in private),candidates_mean=statistics.mean(r['candidates'] for r in private),seconds_mean=statistics.mean(r['seconds'] for r in private),seconds_max=max(r['seconds'] for r in private))
(report/'data/final-summary.json').write_text(json.dumps(dict(weighted=weighted,tests=dict(counts),package={k:package[k] for k in ('archive','bytes','sha256')}),indent=2),encoding='utf-8')
write('ADAPTIVE-BENCHMARK.md',f'''# Final benchmark

Actual installed ZIP 0.2.0, algorithm {artifact['algorithm_version']}, WordPress
7.1.2 / PHP 8.4.23 / GD / SQLite Integration 3.0.2 on Windows. The engine and
adaptive-file hashes match final source. Optimization socket functions disabled;
WordPress outbound attempts: {artifact['network_attempts']}.

| Twelve private photographs | V0.1 fixed | Final recommended |
| --- | ---: | ---: |
| MASTER bytes | {master} | {master} |
| THUMB bytes | 710547 | {thumb} |
| THUMB saving vs MASTER | 75.49% | {gain(thumb)}% |
| VIEW bytes | 4298601 | {view} |
| VIEW saving vs MASTER | -48.26% | {gain(view)}% |
| VIEW larger than MASTER | 12/12 | {weighted['view_larger']}/12 |
| MASTER reused for VIEW | 0/12 | {weighted['view_reused']}/12 |

THUMB is {round(100*(thumb/710547-1),2)}% heavier than V0.1, an explicit small
quality-gate tradeoff rather than hidden regression. Q78 or Q86 selected locally;
no assumption that Q80/Q90 is universally correct. All twelve sources retain
their SHA-256. Final added disk for this corpus: {sum(r['added_disk_bytes'] for r in private)} bytes,
not the served-resource sum: MASTER reuse adds zero bytes.

Decimal KB=1000 bytes; MiB=1048576 bytes. Prior approximate "Mio" descriptions
must not be confused with decimal MB. The VIEW soft target is 500000 bytes.
Private VIEW >500KB: {weighted['view_over_500KB']}; >1MB: {weighted['view_over_1MB']}.

Research: 12 generated fixtures; holdout: 12 independently seeded fixtures,
including six fine-texture challenge cases. All 24 fixture MASTER hashes unchanged
in the actual PHP benchmark. R&D grid: dimensions 640/1600/1800/1920/2048,
qualities 72/78/82/86/90/94. Development grid is not executed in production.
GD actual-candidate comparisons in runtime-benchmark.json are authoritative for
selection; Pillow R&D is exploratory and uses a different resampler.

## Exceptions / outliers

Random-color noise and strong fine texture can fail JPEG fidelity gates. The
safe MASTER is then retained, potentially above dimension/weight targets even
for THUMB. This trades bandwidth for fidelity and is explicitly visible in the
decision. Do not claim every thumbnail is 640 or every image <500KB. Unsafe
metadata cannot take this fallback. Wider real-photo/human calibration remains
required; this candidate is not a certification for a 10000-photo library.

Local visual review: WP-SEED-PIXEL-ADAPTIVE-VISUAL-BENCHMARK.html. It references
authorized existing private MASTER paths and final local outputs, never Git/ZIP.
''')
write('PERFORMANCE-ADAPTIVE.md',f'''# Performance

Actual final ZIP, twelve private photographs:
- Candidate encodes/image: mean {weighted['candidates_mean']:.2f}, maximum {max(r['candidates'] for r in private)}.
- Seconds/image: mean {weighted['seconds_mean']:.4f}, maximum {weighted['seconds_max']:.4f}.
- Production hard search bound: three encodes/resource, six/Balanced attachment.
- Worst measured research/holdout image: {max(r['seconds'] for r in allrows):.4f}s.
- PHP reported peak allocator: {max(r['peak_php_memory_bytes'] for r in allrows)} bytes.

Allocator measurements do not include every native GD allocation; this is not an
OS RSS certification. Time includes lock, hashes, local quality assessment and
metadata commits, not only JPEG encoding. The final ZIP measurement reruns the
actual plugin after WordPress installation, not a Python substitute.

Independent decodes preserve MASTER fidelity. Only one stage candidate per output
exists at a time; rejected bytes are overwritten/removed. Preflight requires at
least max(16MiB, 6*source bytes), existing 40MP/64MB and conservative memory checks.
Staging peak is bounded structurally, not a continuously sampled disk peak.
No physical ENOSPC/OOM experiment was performed: injected failure and preflight
refusal tests do not certify every host resource-exhaustion scenario.

Automation OFF by default; batch handles one attachment/request, pausable and
resumable, not fifty encodes/upload. Do not extrapolate wall time as a guarantee
for a 10000-photo library. Host/Imagick/Linux/MySQL validation remains separate.
''')
write('ALGORITHM-EVOLUTION.md','''# Algorithm evolution

1. V0.1 fixed 640/Q80 + 2048/Q90: 12/12 VIEWs grew, weighted +48.26%.
2. Prototype bounded-rgb-1: source-safe reuse and Q78/Q86/Q94 search. One strict
   VIEW gate used for both intents; private THUMB total inflated to 1234454 bytes.
3. Intent-aware bounded-rgb-2: THUMB floors .960/30dB, VIEW .985/35dB. Thumbnail
   inflation removed without a blanket fixed Q override. Best fixed research
   baseline evaluated with identical quality and source-reuse guards.
4. Final bounded-rgb-3: strict canonical JFIF source classification, frontend
   source-header/privacy revalidation, cache-policy version bump and independent
   source-ownership refusal. Selection weights are unchanged from prototype 2.

Spec challenged: Q90 does not restore lost detail; a higher encoder quality can
increase bytes. 500KB is soft, not a destructive hard cap. No fixed Q fits every
input. A reduced-resolution score overestimated fidelity, so real-resolution
stratified blocks replaced it. No entropy classifier/ML/cloud/scientific runtime
was added. The small deterministic search passed the guarded fixed-baseline
Occam comparison; evidence and limitations are in ADAPTIVE-VALUE.md.
''')
write('SELF-REVIEW-ADAPTIVE.md','''# Self-review log

Five bounded cycles, below the authorized maximum of six. Findings are observable
defects/risks, not a reasoning transcript. No phase-1 work was recreated.

| Cycle | Findings | Correction and evidence |
| --- | --- | --- |
| 1 | Major: shared strict quality gate inflated THUMB; minor: backend/cache fingerprint and resource ownership semantics incomplete | Separate usage floors; version/backend hash; kind=master; explicit byte accounting; prototype benchmark retained |
| 2 | Major: extended JFIF APP0 could enter source reuse; Major: admin select overflow at 320px | Canonical 14-byte JFIF guard + private APP0 fixture; bounded select CSS; failing browser evidence retained in cycle-2 |
| 3 | Major: reused-source getter needed current header/privacy guard; policy changes needed cache invalidation | Header/dimension validation without whole-MASTER frontend hashing; bounded-rgb-3; synthetic late-header mutation and recovery tests |
| 4 | Critical 0, Major 0 | Full 69 regression, 44 adverse + 1 SKIP, 112 adaptive, 28 adaptive adverse, 10 pixel and 29 browser PASS |
| 5 | Critical 0, Major 0; minor test-only fallback harness redeclaration | Same full passing suite; conditional stub declarations fixed; four unavailable-metric branch tests pass; actual Imagick still not certified |

Convergence: YES, cycles 4 and 5 consecutive with no new Critical/Major.
Final unique results include ZIP lifecycle and upgrade, not fivefold duplicated
counts. Initial 320px failure and prototype inflation are not erased from reports.

## Final contradiction check

- Anchored on Q80/Q90/500KB? No: independent grid, local gates, Q78/Q86/Q94 and
  soft target; original retention rather than forced compression.
- Better than a simpler guarded fixed preset? Measured benefit on holdout and
  private corpus; zero benefit on initial research set is reported, not hidden.
- Trust on 10000 personal photos? Not yet certified. Preserve backups, complete
  HUMAN PASS and broader host/corpus checks; do not automatically deploy to PDE.

Residual limits: sampled metric can miss localized artifacts; extreme noise may
reuse a large source even for THUMB; private dataset small; Imagick/Linux/MySQL/
multisite/minimum-version combinations not certified. These are explicit scope
limits, not silently passed tests or a production-readiness claim.
''')
write('SECURITY-REVIEW-ADAPTIVE.md',f'''# Security and isolation

MASTER SHA-256: private 12/12 and synthetic 24/24 unchanged. No in-place original
write. JPEG RGB/profile/EXIF limits retained; EXIF/GPS and extended APP0 fixtures
sanitize rather than source reuse. Current source header checked again by getter.

Ownership, source-kind refusal, original-path refusal, native mapping checks,
locks, source recheck, metadata CAS, crash before/after commit, journal recovery,
retained URLs, history pruning and cleanup pass actual local tests. Only selected
outputs published; temporary candidates deleted. Private manifests remain protected
and absent from public REST, including deactivated-plugin tests.

AI runtime: NONE. Cloud: NONE. Network required: NO. Actual ZIP optimization,
regeneration and unchanged processing: {artifact['network_attempts']} outbound attempts;
WordPress HTTP blocked and socket functions disabled. Static installed-source
scan checks for network/inference entry points. Trusted third-party callbacks
remain their author's responsibility; Pixel does not claim to control all WP code.

Capabilities/nonces/methods, anonymous/subscriber/author denials, escaping,
wrong native mapping, corrupt formats, fake MIME, path traversal, changed sources
and file integrity are exercised. Real symlink creation was denied on this Windows
host: 1 SKIP, not a simulated PASS. Physical OOM/ENOSPC not certified.

No PDE environment, SSH/AskPass, DEV or public site access. Private images and
temporary WP/database/dependencies are excluded from Git/ZIP; no commercial
plugin or licensed theme copy. Runtime is disposable localhost only.
''')
write('TEST-MATRIX.md',f'''# Final unique test matrix

PASS: {counts['PASS']}
FAIL: {counts['FAIL']}
SKIP: {counts['SKIP']}

Latest full cycle-5, plus four fallback branch tests, nine real ZIP upgrade tests,
twelve ZIP lifecycle tests and six local visual-board checks. Repeated cycles are
not counted multiple times.
Final ZIP benchmark additionally validates twelve actual private-source hashes
and zero network calls; 24 synthetic research/holdout hashes are preserved.

WordPress 7.1.2, PHP 8.4.23, GD, SQLite Integration 3.0.2, Chrome/Playwright,
Windows. Admin 1440/820/390/320, keyboard, reduced motion, 200%-equivalent reflow,
no-JS manual/settings and no plugin frontend presentation assets tested.
No claim of a full WCAG certification or actual browser-UI 200% setting.

Uncertified environments (not PASS): Imagick, Linux permissions, MySQL/MariaDB,
multisite, minimum PHP8.1/WordPress6.6 combinations. The no-GD fallback unit stub
checks only the branch contract, not a real Imagick editor. One actual symlink
test is SKIP because this Windows process cannot create it.
''')
git=subprocess.check_output(['git','-c',f'safe.directory={root.as_posix()}','rev-parse','HEAD'],cwd=root,text=True).strip()
write('FINAL-ADAPTIVE-REPORT.md',f'''# WP Seed Pixel adaptive final report

WP SEED PIXEL ADAPTIVE - READY FOR HUMAN PASS

VERSION: 0.2.0. STRATEGY: bounded local adaptive + safe source reuse.
ADAPTIVE JUSTIFIED: YES; guarded fixed Q{value['fixed_best_research_quality']} baseline,
holdout saving {value['groups']['holdout']['saving_percent']}%.
AI RUNTIME: NONE. CLOUD: NONE. NETWORK REQUIRED: NO.
MASTER INTEGRITY: 12/12 private + 24/24 generated fixtures unchanged.

THUMB old/new: 710547 / {thumb} bytes; new MASTER saving {gain(thumb)}%.
VIEW old/new: 4298601 / {view} bytes; inflation +48.26% becomes source reuse.
VIEW larger than MASTER: {weighted['view_larger']}/12; reused: {weighted['view_reused']}/12.
Private VIEW >500KB / >1MB: {weighted['view_over_500KB']} / {weighted['view_over_1MB']}.
Quality: local stratified block SSIM + RGB PSNR, intent-specific floors; human
review required. Candidate encodes average {weighted['candidates_mean']:.2f};
actual ZIP seconds/image mean {weighted['seconds_mean']:.4f}, max {weighted['seconds_max']:.4f}.

Tests: {counts['PASS']} PASS, {counts['FAIL']} FAIL, {counts['SKIP']} SKIP.
Five review cycles, consecutive clean cycles 4/5, convergence YES; final Critical
and Major: 0. See limitations and the corrected failures in SELF-REVIEW-ADAPTIVE.md.

ZIP: dist/{package['archive']}
Bytes: {package['bytes']}
SHA-256: {package['sha256']}
Official WordPress ZIP install/activation, upgrade0.1->0.2, legacy preservation,
explicit opt-in, lifecycle and actual final ZIP benchmark passed. No release tag.

Source base/HEAD when generated: {git}; branch codex/adaptive-v0.2.0.
Final local commit/hygiene proof is reported separately in HYGIENE.md.
No remote/push/tag/release/publication. PDE/DEV/531 photos/ShortPixel/iFolders/A003
unchanged. No contact mail or remote action. Next: Guillaume human visual review,
not PDE integration. Inspect ADMIN, visual comparison, value and self-review;
no intermediate quality parameters need a human choice.
''')
print(json.dumps(dict(tests=dict(counts),weighted=weighted,package={k:package[k] for k in ('archive','bytes','sha256')}),indent=2))
