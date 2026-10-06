"""Produce a runtime-only private candidate and optional HTTPS update manifest."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import urlsplit
import zipfile

p = argparse.ArgumentParser()
p.add_argument('--output', required=True)
p.add_argument('--endpoint')
p.add_argument('--tested', required=True)
p.add_argument('--name', choices=['wp-seed-pixel-0.4.0.zip'])
a = p.parse_args()
root = Path(__file__).resolve().parents[1]
header = (root / 'wp-seed-pixel.php').read_text(encoding='utf-8')
version = re.search(r"define\('WP_SEED_PIXEL_VERSION', '([^']+)'\)", header)[1]
build = re.search(r"define\('WP_SEED_PIXEL_BUILD', '([^']+)'\)", header)[1]
out = Path(a.output).resolve()
out.mkdir(parents=True, exist_ok=True)
name = a.name or 'wp-seed-pixel-' + build + '.zip'
files = [root / n for n in ['wp-seed-pixel.php', 'uninstall.php', 'LICENSE', 'README.md', 'readme.txt']]
for directory, suffixes in [('includes', {'.php'}), ('assets', {'.css', '.js'}), ('languages', {'.mo', '.po', '.pot'})]:
    files += sorted(f for f in (root / directory).iterdir() if f.is_file() and f.suffix in suffixes)
records = []
archive = out / name
if archive.exists():
    raise ValueError('Never overwrite a candidate; use a fresh output directory')
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for f in sorted(files):
        if f.is_symlink() or f.name.startswith('.'):
            raise ValueError('Unsafe runtime input')
        rel = f.relative_to(root).as_posix()
        data = f.read_bytes()
        entry = zipfile.ZipInfo('wp-seed-pixel/' + rel, (2026, 10, 6, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(entry, data)
        records.append({'file': rel, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
sha = hashlib.sha256(archive.read_bytes()).hexdigest()
result = {'build': build, 'version': version, 'zip': name, 'bytes': archive.stat().st_size, 'files': records,
          'runtime_bytes': sum(r['bytes'] for r in records), 'sha256': sha, 'development_files': 0}
(out / 'runtime-manifest.json').write_text(json.dumps(result, indent=2), encoding='utf-8', newline='\n')
(out / (name + '.sha256')).write_text(sha + '  ' + name + '\n', encoding='ascii')
if a.endpoint:
    u = urlsplit(a.endpoint)
    if u.scheme != 'https' or not u.hostname or u.username or u.password or u.query or u.fragment or u.port not in (None, 443):
        raise ValueError('Explicit credential-free HTTPS endpoint required')
    base = a.endpoint.rsplit('/', 1)[0]
    m = {'schema': 1, 'slug': 'wp-seed-pixel', 'channel': 'private', 'version': version, 'requires': '6.6', 'tested': a.tested,
         'requires_php': '8.1', 'package': base + '/' + name, 'sha256': sha, 'released': '2026-10-06', 'notes_url': base + '/release-notes.html'}
    (out / 'update-manifest.json').write_text(json.dumps(m, indent=2), encoding='utf-8', newline='\n')
print(json.dumps({k: v for k, v in result.items() if k != 'files'}))
