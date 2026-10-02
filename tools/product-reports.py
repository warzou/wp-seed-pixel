"""Summarize local product QA without embedding private media or credentials."""
from pathlib import Path
import hashlib
import html
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'reports/product-ux'
OUT.mkdir(parents=True, exist_ok=True)

def read(path):
    return json.loads((ROOT / path).read_text(encoding='utf-8-sig'))

def git(*args):
    return subprocess.check_output(['git', '-c', 'safe.directory=' + ROOT.as_posix(), '-C', str(ROOT), *args], text=True).strip()

def write(name, text):
    (OUT / name).write_text(text.strip() + '\n', encoding='utf-8', newline='\n')

engine = {}
for name in ['engine', 'adaptive', 'presets', 'files', 'store']:
    path = 'includes/class-' + name + '.php'
    old = subprocess.check_output(['git', '-c', 'safe.directory=' + ROOT.as_posix(), '-C', str(ROOT), 'show', '7949399:' + path])
    current = (ROOT / path).read_bytes()
    assert old == current, 'Frozen image component changed: ' + name
    engine[path] = hashlib.sha256(current).hexdigest()
package = read('reports/product-ux/package-manifest.json')
archive = ROOT / 'dist' / package['archive']
assert hashlib.sha256(archive.read_bytes()).hexdigest() == package['sha256']
assert hashlib.sha256((ROOT / 'dist/wp-seed-pixel-0.2.0.zip').read_bytes()).hexdigest() == '7c6658c3039eabc69bed14d8abcfb757b36321d525e3d5d64294e28bbb3f3627'
paths = git('ls-files', '--cached', '--others', '--exclude-standard').splitlines()
paths += [p.relative_to(ROOT).as_posix() for p in OUT.iterdir() if p.suffix in ('.md', '.json', '.html')]
patterns = [r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', r'gh[pousr]_[A-Za-z0-9]{30,}', r'AKIA[0-9A-Z]{16}', r'https?://[^\s/:@]+:[^\s/@]+@']
findings = []
for relative in paths:
    path = ROOT / relative
    if not path.is_file():
        continue
    data = path.read_text(encoding='utf-8-sig')
    if any(re.search(pattern, data) for pattern in patterns):
        findings.append(relative)
assert not findings, 'Credential-like material found (paths only): ' + str(findings)
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert not any(n.endswith(('.jpg', '.webp', '.sql', '.zip', '.patch', '.log')) or (n.endswith('.png') and not n.startswith('wp-seed-pixel/screenshots/')) or '/reports/' in n or '/tests/' in n or '.runtime' in n for n in z.namelist())
    for entry in package['files']:
        assert hashlib.sha256(z.read('wp-seed-pixel/' + entry['file'])).hexdigest() == entry['sha256']
subprocess.check_call(['git', '-c', 'safe.directory=' + ROOT.as_posix(), '-C', str(ROOT), 'diff', '--check'])

suites = [
    'reports/final/integration-results.json', 'reports/final/adversarial-results.json',
    'reports/adaptive/data/adaptive-tests.json', 'reports/adaptive/data/adaptive-adversarial-tests.json',
    'reports/final/visual-assertions.json', 'reports/final/lifecycle-results.json',
    'reports/product-ux/product-tests.json', 'reports/product-ux/browser-tests.json',
    'reports/product-ux/scale-tests.json', 'reports/product-ux/upgrade-tests.json',
]
counts = {'PASS': 0, 'FAIL': 0, 'SKIP': 0}
for source in suites:
    obj = read(source)
    if isinstance(obj, dict) and 'tests' in obj:
        obj = obj['tests']
    if isinstance(obj, list):
        for row in obj:
            counts[row['status']] += 1
    else:
        for value in obj.values():
            counts['PASS' if value is True else 'FAIL'] += 1
assert counts['FAIL'] == 0
(OUT / 'final-qa.json').write_text(json.dumps({'tests': counts, 'engine': engine, 'secret_findings': findings, 'zip': package['archive'], 'sha256': package['sha256'], 'bytes': package['bytes']}, indent=2), encoding='utf-8')

write('UX-0.2-AUDIT.md', '''# UX 0.2 audit
Audit basis: existing admin source and retained baseline, before Phase 3 changes.

- Major: manual Attachment ID was a primary workflow; discovery required developer knowledge.
- Major: progress displayed raw JSON and internal status codes instead of a readable result.
- Major: no native selected-media bulk workflow or detail panel in the grid modal.
- Minor: row actions always forced regeneration even for a never-processed image.
- Minor: advanced engine information dominated recent results.
- Minor: optimizer warning was unconditional, even without an optimizer.

Native patterns researched: media rows, bulk screen filters, attachment fields,
WordPress notices, submit buttons and disclosure. Official sources:
https://developer.wordpress.org/reference/hooks/attachment_fields_to_edit/
https://developer.wordpress.org/reference/hooks/bulk_actions-this-screen-id/
https://developer.wordpress.org/reference/hooks/handle_bulk_actions-screen/

Decision: native list actions and attachment-field integration (including grid
details), no tile overlay or alternative media library. Reuse the existing queue.
''')
write('UX-BEFORE-AFTER.md', '''# UX before / after
| Concern | 0.2 | 0.3 |
| --- | --- | --- |
| Numeric ID in normal use | Primary form | Diagnostics only |
| Individual media action | Always regeneration | Optimize / regenerate according to manifest |
| Selected bulk | Absent | Native list bulk action, explicit resume |
| Progress | JSON | Text, counters, native progress and bounded error links |
| Detail in grid | Absent | Native attachment fields |
| Technical information | Prominent | Disclosure |
| Optimizer warning | Unconditional | Known active plugin detected |
| 320px commands | Narrow two-column button wraps | Full-width commands |
| Mobile detail label | Narrow left column | Stacked plugin field |

Benefits stay per resource, not estimated site traffic. Additional disk usage
is not presented as a saving. Fixed profiles and advanced APIs remain available.
''')
write('SELF-REVIEW.md', '''# Self review
Preparation findings are listed in UX-0.2-AUDIT and UX-BEFORE-AFTER.
No model reasoning transcript is included; these are observable product checks.

| Review role | Observation / verification | Action / retest |
| --- | --- | --- |
| Novice WordPress | Numeric IDs and JSON were primary | Media Library entry and human progress implemented; actual browser use |
| Power user | Diagnostics and failed-item identification needed to stay accessible | Disclosure and up to ten failed-media links; no filesystem paths |
| Visual responsive | 320px button wrapped into a tall command; detail label became three lines | Full-width controls below 360px, stacked scoped field; recaptured |
| Accessibility / interaction | Native field labels, status text, Enter, focus retention and grid dialog | Browser tests; no new Critical/Major remaining |
| Maintainer / hostile UX | Selected IDs, authority, nonce, scale, pause, partial result, retries, no-JS fallback | PHP and browser tests; no new Critical/Major remaining |

Five review roles used. Critical found: 0. Major preparation issues: 3, corrected.
Observable visual refinements: 3; corrected after inspection, despite DOM
overflow checks already passing. Last interaction and maintainer reviews found
no new Critical/Major after fixes. No claim of complete WCAG certification.

Harness issues: bulk top control is hidden by WordPress on mobile; test now uses
the visible bottom control. A misspelled PHP helper name was corrected. Lifecycle
first ran in a hostile-fixture-contaminated runtime; repeated in a fresh runtime
where all 12 checks passed. These are not presented as plugin success tests.
Final inspection also found an encoded entity in a failed-media title; decode
before textContent fixed it and a browser assertion prevents recurrence. Capture
scroll is reset before full-page screenshots; coexistence uses its stated width.
Upgrade preserves prior presets; browser first-use fixtures explicitly reset
their settings after the upgrade test rather than treating persistence as failure.
''')
write('UX-QA.md', '''# Codex UX QA
Final verdict: PASS for the implemented local product workflows.

| Goal | Executed path | Result |
| --- | --- | --- |
| First use | Install final ZIP, activate, open Pixel | Automation off, Balanced and preserved original explained |
| Settings | Native save POST | Clear saved notice, no existing-image reprocessing |
| Automatic upload | Synthetic JPEG, event, official callback | Engine success, original preserved |
| Existing media | List action, keyboard Enter | Optimize succeeds without entering an ID |
| Regeneration | Already processed media, keyboard Enter | Shared engine succeeds, focus retained |
| Multiple media | Native list selection, bulk action, paused queue, Resume | Current / skip / failure counters distinct |
| Error | Partial result, failed-media links | Error not presented as total success; diagnosis available |
| Technical details | Attachment disclosure and diagnostic fallback | Accessible without dominating normal use |
| Grid | Open native attachment modal | Same panel available, Escape handled by WordPress |
| No JS | Native diagnostic POST and settings | Functional fallback; normal buttons/bulk explicitly require JS |
| Coexistence | Synthetic active-plugin marker then restore | Conditional non-blocking warning; no optimizer disabled |
| Scale | 10, 100, 1000 synthetic lightweight attachments | 8/7/6 preparation queries, bounded option and per-item step |

Closing the browser stops advancing the queue. Reload does not silently process
anything; the user resumes explicitly. No background-worker promise is made.
Bulk is administrator-only; per-image actions require upload and edit authority.
French source catalogue is not yet shipped; English translatable strings were inspected.
''')
write('ACCESSIBILITY-QA.md', '''# Accessibility QA
Verdict: PARTIAL, no observed blocking issue in the central tested controls.

Automated: labels, live text status, progress label, no horizontal overflow,
authority/nonce checks and responsive controls at 1440/820/390/320.
Manual interaction via browser: Tab/Enter commands, visible focus, retained focus
after regeneration, WordPress media modal and Escape, disclosure, normal/native
settings submission. Status is not color-only. Mobile commands have 44px minimum
height. Zoom-equivalent 720px layout and reduced motion tested.
Visual: final captures inspected for hierarchy, spacing, button readability,
wrapping, detail labels and result text.
Not certified: full WCAG audit, assistive-technology user study, every admin color
scheme, browser-menu 200% zoom, French catalogue or long translated UI.
''')

screens = ['settings', 'media', 'detail', 'exception', 'bulk']
rows = []
for screen in screens:
    for width in [1440, 820, 390, 320]:
        filename = f'{screen}-{width}.png'
        assert (OUT / filename).is_file()
        rows.append(f'| {screen} | {width} | [{filename}]({filename}) | PASS |')
write('VISUAL-QA.md', '# Codex visual QA\n\nFinal verdict: PASS after actual image inspection.\n\n| Screen | Viewport | Capture inspected | Verdict |\n| --- | --- | --- | --- |\n' + '\n'.join(rows) + '''

Also inspected: grid-1440.png and warning-1440.png.
Found visually, not by DOM overflow assertions: tall wrapped primary bulk button
at 320px; narrow Pixel detail label at 320px. Corrected and recaptured. Row actions
use WordPress button-link styling; core owns collapsed mobile table rows. The
grid uses native details instead of an injected tile badge. Long filename wrapping
and partial/error text remain readable. No private photographs were used.
Final failed-item titles are decoded, not shown as literal HTML entities.
Full-page captures start at scroll zero; the grid dialog uses a viewport capture.
Grid capture waits for actual image load. Offline owner HTML: nine embedded
captures loaded, zero remote requests, no overflow at all four tested widths.
''')
write('SECURITY-QA.md', '''# Security QA
PASS for targeted local surfaces; not an external penetration certification.

POST-only AJAX, nonce and upload/edit checks for individual actions; administrator
and bulk-media nonce for selected queues. Invalid/oversized selections refused.
Selected authority is rechecked before advancing; list cache primed once. Error
links limited to ten editable attachments. Titles and diagnostic reasons escaped;
JS uses textContent for returned titles/statuses. Internal filesystem paths removed
from AJAX resources. Details panel is admin-authorized, no new public REST surface.
Anonymous, invalid nonce, array ID, lower-privilege bulk and foreign-attachment
requests tested. No frontend assets; browser external requests: 0. Engine components
byte-identical to 0.2.0. Credential-pattern scan: 0 findings; final ZIP contains
only selected synthetic screenshots, no private images, runtime, test credentials,
private benchmark or report.
''')
write('FINAL-REPORT.md', f'''# WP Seed Pixel 0.3.0

WP SEED PIXEL 0.3 - READY FOR OWNER ACCEPTANCE

BASE: 0.2.0 Adaptive, human engine visual pass retained.
IMAGE ENGINE: UNCHANGED, five frozen components byte-identical to 7949399.
AUTOMATED QA: PASS ({counts['PASS']} PASS / {counts['FAIL']} FAIL / {counts['SKIP']} SKIP).
CODEX VISUAL QA: PASS; 22 final screenshots inspected.
CODEX UX QA: PASS; actual local WordPress workflows executed.
ACCESSIBILITY QA: PARTIAL, no central observed blocker; no WCAG certification.
SECURITY QA: PASS for targeted tests, limits documented.
OWNER ACCEPTANCE: PENDING.

## Product
Numeric Attachment ID in normal workflow: NO. Native list actions, native grid
attachment details, selected bulk queue and simplified settings implemented.
Status labels distinguish optimized, new, skipped, error and update available.
Regeneration uses original and retains previous versions. Technical details and
compatibility profiles remain available. Bulk uses the existing shared engine.

## Decisions Codex took autonomously
Use native attachment fields rather than a tile overlay. Administrator-only bulk,
bounded 1000-item selection, explicit pause/resume and two bounded retries. No
new dashboard scan, no whole-library scanning on page load, no new optimizer.
Human text rather than JSON; failed-item links rather than opaque failure counts.
English translatable labels; no fictitious quality profiles.

## Visual issues found by Codex
320px primary command too tall after wrapping: full-width command fixed it.
320px detail label fragmented across lines: scoped stacked field fixed it.
Both existed when automated overflow checks passed. All final screens recaptured.
Before/after source/workflow comparisons: UX-BEFORE-AFTER.md. No invented screenshots.

## Validation
WordPress 7.1.2 / PHP 8.4.23 / GD / SQLite / Chrome, disposable local runtime.
Final ZIP installed with native WordPress Upgrader. Clean lifecycle: 12/12.
Upgrade 0.2 -> 0.3: 8/8; settings, manifest, metadata, source reuse and master kept.
Selection tests 10/100/1000: preparation query counts 8/7/6; ten steps in large
queue; pause and persisted cursor tested. No 1000-large-JPEG throughput claim.
AI: NONE. Cloud: NONE. Telemetry: NONE. Optimization external requests: 0.

## Package
ZIP: ../../dist/{package['archive']}
Bytes: {package['bytes']}
SHA-256: {package['sha256']}
Reports and private media are excluded; previous 0.2 ZIP unchanged.

## Limits
Imagick, Linux, real MySQL/MariaDB, multisite and deep commercial optimizer
coexistence not certified. French translation catalogue not shipped. No full
screen-reader/WCAG audit or browser-menu zoom certification. Processing advances
only while the admin page is open; resume required after closing it.

## Isolation and publication
PDE, 531 photos, ShortPixel, iFolders and Albums: no action or mutation.
No remote connection or consuming-site deployment. GitHub: NOT PUBLISHED.
Push/tag/release: NO. Local branch: {git('branch', '--show-current')}.
HEAD at report generation: {git('rev-parse', 'HEAD')}.
Reports/captures are ignored. Owner acceptance before any publication or deployment.
''')

figures = []
for screen, caption in [('settings','Settings and first use'), ('media','Media Library'), ('detail','Media details'), ('bulk','Selected bulk result'), ('settings','Mobile settings'), ('bulk','Mobile partial result'), ('exception','Preserved unsupported image'), ('grid','Native grid details'), ('warning','Simulated optimizer coexistence')]:
    width = 390 if caption.startswith('Mobile') else 1440
    name = f'{screen}-{width}.png'
    figures.append(f'<section><h2>{html.escape(caption)}</h2><a href="{name}"><img src="{name}" alt="{html.escape(caption)}" loading="lazy"></a></section>')
document = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Seed Pixel 0.3 - Owner acceptance</title><style>body{font:16px/1.5 system-ui;margin:0;color:#222;background:#fff}main{max-width:1280px;margin:auto;padding:24px}h1{font-size:28px}h2{font-size:22px}section{border-top:1px solid #bbb;padding:24px 0}img{max-width:100%;height:auto;display:block}a{color:#185c82}a:focus-visible{outline:3px solid #185c82}@media(max-width:700px){main{padding:16px}}</style></head><body><main><h1>WP Seed Pixel 0.3.0</h1><p>One recommended product interface. Automated and Codex visual/UX QA completed; owner acceptance pending. Synthetic media only.</p><p>Image engine unchanged. Native media workflows, clear settings, separate transfer reduction and disk usage. No production certification.</p>' + ''.join(figures) + '<section><h2>Review</h2><p>Does the overall organization work for you? Do the labels feel natural? Any personal preference to adjust?</p><p><a href="FINAL-REPORT.md">Final report</a> | <a href="VISUAL-QA.md">Visual QA</a> | <a href="UX-QA.md">UX QA</a> | <a href="SELF-REVIEW.md">Self review</a></p></section></main></body></html>'
write('OWNER-ACCEPTANCE.html', document)
print(json.dumps({'tests': counts, 'zip_bytes': package['bytes'], 'zip_sha256': package['sha256'], 'secret_findings': findings, 'frozen_components': len(engine)}))
