"""Package the human-passed runtime without publishing or touching a site."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'reports/release-0.6.0-20261009'
BASE = '80dbca30dea54e857270f767ebd2c2d30765c222'

def sha(data):
    return hashlib.sha256(data).hexdigest()

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

assert git('rev-parse', 'HEAD').decode().strip() == BASE
assert not git('diff', '--cached', '--name-only').strip()
git('diff', '--check')
runtime = sorted([p.relative_to(ROOT).as_posix() for d in ('assets', 'includes', 'languages')
                  for p in (ROOT / d).iterdir() if p.is_file()] +
                 ['LICENSE', 'README.md', 'readme.txt', 'uninstall.php', 'wp-seed-pixel.php'])
assert len(runtime) == 67
old = ROOT / 'reports/metadata-private4-20261008/candidate/wp-seed-pixel-0.6.0-private.4.zip'
assert old.stat().st_size == 250829
assert sha(old.read_bytes()) == '28e2c206fb440a6e4bb12d550eb691beb83e3db9dcc70824b47b4152e9e10212'
with zipfile.ZipFile(old) as z:
    assert sorted(z.namelist()) == ['wp-seed-pixel/' + n for n in runtime]
    original_entry = z.read('wp-seed-pixel/wp-seed-pixel.php').replace(b'\r\n', b'\n')
    final_entry = (ROOT / 'wp-seed-pixel.php').read_bytes().replace(b'\r\n', b'\n')
    assert original_entry.replace(b'0.6.0-private.4', b'0.6.0') == final_entry
    for name in runtime:
        if name not in ('README.md', 'readme.txt', 'wp-seed-pixel.php'):
            assert z.read('wp-seed-pixel/' + name) == (ROOT / name).read_bytes(), name
assert (ROOT / 'updates/stable.json').read_bytes() == git('show', BASE + ':updates/stable.json')
for cycle in (1, 2):
    c = json.loads((OUT / f'cycle-{cycle}/complete.json').read_text(encoding='utf-8-sig'))
    assert c['complete'] and c['version'] == '0.6.0' and not c['remote'] and not c['permanentPurge']
    records = c['runtime']
    if isinstance(records, dict):
        records = records['value']
    assert {f['file']: f['sha256'] for f in records} == {
        n: sha((ROOT / n).read_bytes()) for n in runtime}
paths = sorted(set(git('diff', '--name-only').decode().splitlines() +
                   git('ls-files', '--others', '--exclude-standard').decode().splitlines()))
for name in paths:
    assert not Path(name).is_absolute() and '..' not in Path(name).parts
    assert not any(p in name.split('/') for p in ('reports', '.runtime', '.git', 'node_modules'))
    assert not name.lower().endswith(('.zip', '.patch', '.sql', '.exe', '.pdb', '.jpg', '.jpeg', '.png'))
    assert '.codex-' not in name and 'askpass' not in name.lower()
    data = (ROOT / name).read_bytes()
    for pattern in (rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
                    rb'gh[pousr]_[A-Za-z0-9]{30,}', rb'github_pat_[A-Za-z0-9_]{30,}',
                    rb'AKIA[A-Z0-9]{16}', rb'https?://[^\s/]+:[^\s@]+@'):
        assert not re.search(pattern, data), 'Credential signature: ' + name
    if not name.endswith('.mo'):
        assert '\ufffd' not in data.decode('utf-8'), name
assert b'Version: 0.6.0\n' in (ROOT / 'wp-seed-pixel.php').read_bytes().replace(b'\r\n', b'\n')
assert b'Stable tag: 0.6.0\n' in (ROOT / 'readme.txt').read_bytes().replace(b'\r\n', b'\n')
directory = OUT / 'candidate'
directory.mkdir(parents=True, exist_ok=True)
archives = []
for suffix in ('', '-rebuild'):
    archive = directory / ('wp-seed-pixel-0.6.0' + suffix + '.zip')
    assert not archive.exists(), 'Do not overwrite frozen release evidence'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name in runtime:
            info = zipfile.ZipInfo('wp-seed-pixel/' + name, (2026, 10, 9, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            z.writestr(info, (ROOT / name).read_bytes())
    archives.append(archive)
assert archives[0].read_bytes() == archives[1].read_bytes()
with zipfile.ZipFile(archives[0]) as z:
    assert z.testzip() is None
    assert z.namelist() == ['wp-seed-pixel/' + n for n in runtime]
    for name in runtime:
        assert z.read('wp-seed-pixel/' + name) == (ROOT / name).read_bytes()
record = {'version': '0.6.0', 'bytes': archives[0].stat().st_size,
          'sha256': sha(archives[0].read_bytes()), 'runtime_files': len(runtime),
          'byte_identical_builds': True, 'functional_runtime_private4_identical': True,
          'stable_manifest_unchanged': True, 'secret_signature_findings': 0,
          'paths': paths, 'files': [{'file': n, 'sha256': sha((ROOT / n).read_bytes())} for n in runtime]}
(directory / 'runtime-manifest.json').write_text(json.dumps(record, indent=2), encoding='utf-8')
(directory / 'wp-seed-pixel-0.6.0.zip.sha256').write_text(
    record['sha256'] + '  wp-seed-pixel-0.6.0.zip\n', encoding='ascii')
print(json.dumps({k: v for k, v in record.items() if k not in ('paths', 'files')}, indent=2))
