"""Audit source history and the public ZIP without printing credential contents."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'reports/release-0.3.0'
OUT.mkdir(parents=True, exist_ok=True)

def git(*args):
    return subprocess.check_output(['git', '-c', 'safe.directory=' + ROOT.as_posix(), '-C', str(ROOT), *args])

patterns = {
    'private_key': rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    'access_token': rb'gh[pousr]_[A-Za-z0-9]{30,}|AKIA[0-9A-Z]{16}',
    'credential_url': rb'https?://[^\s/:@]+:[^\s/@]+@',
    'local_private_path': rb'\b[A-Z]:[\\/](?:Dev|Users|Downloads|tmp)[\\/]',
    'private_site': rb'dev\.psychotherapiedeletre\.com|marieodilehouverformation\.com',
}
findings = []
objects = git('rev-list', '--objects', '--all').decode().splitlines()
blobs = 0
for entry in objects:
    oid, _, name = entry.partition(' ')
    if git('cat-file', '-t', oid).strip() != b'blob':
        continue
    blobs += 1
    data = git('cat-file', 'blob', oid)
    for category, pattern in patterns.items():
        if re.search(pattern, data, re.I):
            findings.append({'path': name, 'object': oid, 'category': category})
    if re.search(r'(^reports/|\.codex-|\.(?:zip|sql|exe|pdb|log|jpe?g|webp)$)', name, re.I):
        findings.append({'path': name, 'object': oid, 'category': 'unexpected_artifact'})
    if name.endswith('.png'):
        current = ROOT / name
        if not name.startswith('screenshots/') or not current.is_file() or current.read_bytes() != data:
            findings.append({'path': name, 'object': oid, 'category': 'unapproved_image'})

paths = git('ls-files', '--cached', '--others', '--exclude-standard').decode().splitlines()
for name in paths:
    file = ROOT / name
    if not file.is_file():
        continue
    for category, pattern in patterns.items():
        if re.search(pattern, file.read_bytes(), re.I):
            findings.append({'path': name, 'category': category})

for file in [ROOT / 'wp-seed-pixel.php', ROOT / 'uninstall.php', *ROOT.glob('includes/*.php'), *ROOT.glob('assets/*')]:
    relative = file.relative_to(ROOT).as_posix()
    if file.read_bytes() != git('show', '07b6878:' + relative):
        findings.append({'path': relative, 'category': 'accepted_product_changed'})

package = json.loads((ROOT / 'reports/product-ux/package-manifest.json').read_text())
archive = ROOT / 'dist' / package['archive']
assert hashlib.sha256(archive.read_bytes()).hexdigest() == package['sha256']
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert all(n.startswith('wp-seed-pixel/') for n in z.namelist())
    assert not any(re.search(r'/(?:\.git|reports|tests|tools|node_modules|\.runtime)/|\.(?:sql|zip|log|exe|pdb)$', n) for n in z.namelist())
    for item in package['files']:
        data = z.read('wp-seed-pixel/' + item['file'])
        assert hashlib.sha256(data).hexdigest() == item['sha256']
        assert data == (ROOT / item['file']).read_bytes()

readme = (ROOT / 'README.md').read_text()
for target in re.findall(r'\]\(([^)]+)\)', readme):
    if '://' not in target:
        assert (ROOT / target.split('#')[0]).is_file(), 'Broken relative link: ' + target
git('diff', '--check')
git('diff', '--cached', '--check')
result = {'history_blobs': blobs, 'findings': findings, 'accepted_product_unchanged': True,
          'zip': package['archive'], 'bytes': package['bytes'], 'sha256': package['sha256'],
          'relative_links': 'PASS', 'zip_integrity': 'PASS'}
(OUT / 'gate.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
print(json.dumps(result))
if findings:
    raise SystemExit(1)
