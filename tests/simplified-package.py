"""Freeze a runtime-only private UX candidate; never overwrite a build artifact."""
from pathlib import Path
import hashlib
import json
import zipfile
import sys

root = Path(__file__).resolve().parents[1]
number = int(sys.argv[1]) if len(sys.argv) > 1 else 6
build = f'0.4.0-private.{number}'
out = root / f'reports/simplified-ux/certification-candidate-{number}'
out.mkdir(parents=True, exist_ok=True)
archive = out / f'wp-seed-pixel-{build}.zip'
if archive.exists():
    raise SystemExit('IMMUTABLE_CANDIDATE_EXISTS')
if f"define('WP_SEED_PIXEL_BUILD', '{build}')" not in (root / 'wp-seed-pixel.php').read_text(encoding='utf-8'):
    raise SystemExit('BUILD_MISMATCH')
files = [root / p for p in ('wp-seed-pixel.php', 'uninstall.php', 'readme.txt', 'LICENSE')]
for folder, suffixes in [('includes', {'.php'}), ('assets', {'.css', '.js'}), ('languages', {'.pot', '.po', '.mo'})]:
    for p in sorted((root / folder).rglob('*')):
        if p.is_file():
            if p.is_symlink() or p.name.startswith('.') or p.suffix not in suffixes:
                raise SystemExit('NON_RUNTIME_INPUT')
            files.append(p)
manifest = []
with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for p in sorted(files):
        data = p.read_bytes()
        name = p.relative_to(root).as_posix()
        info = zipfile.ZipInfo('wp-seed-pixel/' + name, (2026, 10, 6, 0, 0, 0))
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, data)
        manifest.append({'file': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    for row in manifest:
        assert hashlib.sha256(z.read('wp-seed-pixel/' + row['file'])).hexdigest() == row['sha256']
result = {'version': '0.4.0', 'build': build, 'private': True, 'development_files': 0,
          'file_count': len(manifest), 'installed_bytes': sum(r['bytes'] for r in manifest),
          'zip_bytes': archive.stat().st_size, 'zip_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'files': manifest}
(out / 'package-manifest.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
(out / 'runtime.sha256').write_text(''.join(f"{r['sha256']}  {r['file']}\n" for r in manifest), encoding='ascii')
print(json.dumps({k: v for k, v in result.items() if k != 'files'}))
