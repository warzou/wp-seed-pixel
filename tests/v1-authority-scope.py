"""Check that V1 preserves the previously certified DB authority primitives."""
from pathlib import Path
import hashlib
import json

root = Path(__file__).resolve().parents[1]
base = root / 'reports/storage-m5.1/authority-gate'
files = ['includes/class-authority.php', 'includes/class-files.php', 'includes/class-storage-budget.php']
checks = {}
for engine in ('mariadb', 'mysql'):
    manifest = dict((name, digest) for digest, name in (line.split('  ', 1) for line in (base / engine / 'runtime.sha256').read_text().splitlines()))
    for name in files:
        checks[engine + ':' + name] = hashlib.sha256((root / name).read_bytes()).hexdigest() == manifest[name]
if not all(checks.values()): raise SystemExit('AUTHORITY_PRIMITIVE_CHANGED')
(root / 'reports/storage-v1/authority-scope.json').write_text(json.dumps({'checks': checks, 'scope': 'unchanged primitives; this is not a new full MySQL/NFS V1 matrix'}, indent=2) + '\n')
print('Six accepted MariaDB/MySQL authority primitive fingerprints preserved.')
