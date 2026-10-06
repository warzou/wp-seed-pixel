"""Read-only identity gate for the frozen runtime and final ZIP."""
import hashlib
import json
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
directory = root / 'reports/final-closure-20261006/candidate'
manifest = json.loads((directory / 'runtime-manifest.json').read_text(encoding='utf-8'))
archive = directory / manifest['zip']
assert archive.stat().st_size == manifest['bytes']
assert hashlib.sha256(archive.read_bytes()).hexdigest() == manifest['sha256']
with zipfile.ZipFile(archive) as package:
    expected = {'wp-seed-pixel/' + item['file'] for item in manifest['files']}
    assert set(package.namelist()) == expected and len(package.namelist()) == len(expected)
    for item in manifest['files']:
        source = (root / item['file']).read_bytes()
        assert len(source) == item['bytes']
        assert hashlib.sha256(source).hexdigest() == item['sha256']
        assert package.read('wp-seed-pixel/' + item['file']) == source
        assert not any(part in item['file'].split('/') for part in ('tests', 'reports', '.runtime', '.git'))
print(json.dumps({'identity': 'PASS', 'files': len(expected), 'sha256': manifest['sha256'], 'development_files': 0}))
