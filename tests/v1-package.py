"""Private runtime-only V1 package; requires matching final frozen cycles."""
from pathlib import Path
import hashlib
import json
import zipfile

root = Path(__file__).resolve().parents[1]
out = root / 'reports/storage-v1'
cycles = [out / f'cycle-{i}/runtime.sha256' for i in (1, 2)]
if cycles[0].read_bytes() != cycles[1].read_bytes():
    raise SystemExit('FINAL_CYCLES_NOT_IDENTICAL')
for line in cycles[0].read_text(encoding='ascii').splitlines():
    digest, name = line.split('  ', 1)
    if name.startswith('/') or '..' in Path(name).parts or hashlib.sha256((root / name).read_bytes()).hexdigest() != digest:
        raise SystemExit('FROZEN_RUNTIME_DIFFERS')
if "define('WP_SEED_PIXEL_VERSION', '0.4.0')" not in (root / 'wp-seed-pixel.php').read_text(encoding='utf-8'):
    raise SystemExit('VERSION_MISMATCH')
files = [root / p for p in ('wp-seed-pixel.php', 'uninstall.php', 'readme.txt', 'LICENSE')]
for folder, suffixes in [('includes', {'.php'}), ('assets', {'.css', '.js'}), ('languages', {'.pot', '.po', '.mo'})]:
    for p in sorted((root / folder).rglob('*')):
        if p.is_file():
            if p.is_symlink() or p.name.startswith('.') or p.suffix not in suffixes:
                raise SystemExit('NON_RUNTIME_INPUT')
            files.append(p)
manifest = []
archive = out / 'wp-seed-pixel-0.4.0-private-candidate.zip'
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for p in sorted(files):
        data = p.read_bytes()
        name = p.relative_to(root).as_posix()
        info = zipfile.ZipInfo('wp-seed-pixel/' + name, (2026, 10, 5, 0, 0, 0))
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, data)
        manifest.append({'file': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    for row in manifest:
        assert hashlib.sha256(z.read('wp-seed-pixel/' + row['file'])).hexdigest() == row['sha256']
result = {'version': '0.4.0', 'private': True, 'development_files': 0, 'file_count': len(files), 'installed_bytes': sum(r['bytes'] for r in manifest),
          'zip_bytes': archive.stat().st_size, 'zip_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
          'largest_files': sorted(manifest, key=lambda r: r['bytes'], reverse=True)[:8], 'files': manifest}
(out / 'package-manifest.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
(out / (archive.name + '.sha256')).write_text(result['zip_sha256'] + '  ' + archive.name + '\n', encoding='ascii')
print(json.dumps({k:v for k,v in result.items() if k != 'files'}, indent=2))
