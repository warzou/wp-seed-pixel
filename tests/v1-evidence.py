"""Validate primary final-cycle results, excluding duplicated evidence copies."""
from pathlib import Path
import hashlib
import json

root = Path(__file__).resolve().parents[1]
out = root / 'reports/storage-v1'
expected = {
    'storage-m5.1/authority-gate/schema-cas.json': 29,
    'storage-m5.1/locks.json': 28,
    'storage-m5.1/uploads-budget.json': 63,
    'storage-m5.1/quarantine.json': 8,
    'storage-m5.1/crash.json': 6,
    'storage-m1/integration.json': 29,
    'storage-m1/edge.json': 11,
    'storage-m1/rescan.json': 5,
    'storage-m2/integration.json': 53,
    'storage-m2/faults.json': 24,
    'storage-m3/integration.json': 71,
    'storage-m3/faults.json': 21,
    'storage-m3/native.json': 28,
    'storage-m4/integration.json': 28,
    'storage-m4/faults.json': 38,
    'storage-m4/adversarial.json': 22,
    'storage-m4/accounting.json': 12,
    'storage-m4/native.json': 8,
    'storage-m5/mixed.json': 18,
    'storage-m5/order-security.json': 10,
    'storage-m5/systemic.json': 8,
    'storage-m5/accounting-unknown.json': 9,
    'm6.json': 61,
    'resources.json': 3,
    'stable-regression/upgrade.json': 12,
    'stable-regression/stable/integration-results.json': 69,
    'stable-regression/stable/adversarial-results.json': 45,
    'stable-regression/stable/lifecycle-results.json': 12,
    'i18n/unit-qa.json': 22,
    'adaptive/data/adaptive-tests.json': 112,
    'adaptive/data/adaptive-adversarial-tests.json': 28,
    'color/gd-RESULT.json': 30,
    'color/imagick-RESULT.json': 77,
}


def count_passed(data):
    if isinstance(data, dict):
        if data.get('status', 'PASS') != 'PASS':
            raise SystemExit('FINAL_STATUS_NOT_PASS')
        if data.get('passed', True) is False:
            raise SystemExit('FINAL_RESULT_NOT_PASS')
        if 'checks' in data:
            checks = data['checks']
            if isinstance(checks, dict):
                if not all(value is True for value in checks.values()):
                    raise SystemExit('FINAL_BOOLEAN_NOT_PASS')
                return len(checks)
            if isinstance(checks, list) and all(isinstance(c, str) for c in checks):
                if data.get('status') != 'PASS':
                    raise SystemExit('FINAL_NAMED_CHECKS_WITHOUT_PASS')
                return len(checks)
        data = data.get('tests')
    if not isinstance(data, list) or not all(row.get('status') == 'PASS' for row in data):
        raise SystemExit('FINAL_TEST_NOT_PASS')
    return len(data)


cycles = []
manifest = None
for number in (1, 2):
    folder = out / f'cycle-{number}'
    start = (folder / 'runtime-start.sha256').read_bytes()
    end = (folder / 'runtime.sha256').read_bytes()
    if start != end or (manifest is not None and manifest != end):
        raise SystemExit('FINAL_RUNTIME_CHANGED')
    manifest = end
    rows = []
    for name, count in expected.items():
        actual = count_passed(json.loads((folder / name).read_text(encoding='utf-8-sig')))
        if actual != count:
            raise SystemExit(f'FINAL_COUNT_MISMATCH: {name}')
        rows.append({'evidence': name, 'passed': actual})
    cycles.append({'cycle': number, 'status': 'PASS', 'checks': sum(r['passed'] for r in rows), 'results': rows})
for line in manifest.decode('ascii').splitlines():
    digest, name = line.split('  ', 1)
    path = root / name
    if path.is_symlink() or not path.resolve().is_relative_to(root) or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
        raise SystemExit('FINAL_RUNTIME_NO_LONGER_FROZEN')
result = {'status': 'PASS', 'duplicate_evidence_counted': False, 'runtime_unchanged': True, 'cycles': cycles}
(out / 'final-cycles.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
print(json.dumps({**result, 'cycles': [{k: v for k, v in c.items() if k != 'results'} for c in cycles]}))
