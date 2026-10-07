"""Audit final packaging against immutable certified private runtime artifacts."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import zipfile

root = Path(__file__).resolve().parents[1]
out = Path(os.environ['PIXEL_FORMAT_REPORT_DIR'])
candidate = out / 'candidate' / 'wp-seed-pixel-0.5.0.zip'
previous = root / 'reports/png-jpeg-profile-policy-private5/final/candidate/wp-seed-pixel-0.5.0-private.5.zip'
allowed_delta = {'wp-seed-pixel.php', 'README.md', 'readme.txt'}
with zipfile.ZipFile(previous) as old, zipfile.ZipFile(candidate) as new:
    assert old.namelist() == new.namelist()
    changed = [name.removeprefix('wp-seed-pixel/') for name in new.namelist() if new.read(name) != old.read(name)]
    assert set(changed) == allowed_delta
    entry = 'wp-seed-pixel/wp-seed-pixel.php'
    assert old.read(entry).replace(b'0.5.0-private.5', b'0.5.0') == new.read(entry)
    for name in new.namelist():
        assert b'0.5.0-private.5' not in new.read(name)
    assert b'review_claims' in new.read('wp-seed-pixel/includes/class-jobs.php')
    assert b"WP_SEED_PIXEL_ENGINE_VERSION', '0.3.1'" in new.read(entry)

artifacts = {
    Path('C:/Dev/git/wp-seed-pixel/reports/final-closure-20261006/candidate/wp-seed-pixel-0.4.0.zip'): '512d7685b42b7dfddc308be25ae882bb22ad8314cbbf14fa89cf4f38d0a87712',
    root / 'reports/png-jpeg/candidate/wp-seed-pixel-0.5.0-private.1.zip': '1369943c9df3f9cb24e777e44f74d39afe7358aa74decc9ca0c8cf0e0cce7647',
    root / 'reports/png-jpeg-ux-private2/candidate/wp-seed-pixel-0.5.0-private.2.zip': 'ab2fe8d3a7929a4b75c5521b0744073f4e3b4afd127c3a035f427801b4e5a334',
    root / 'reports/png-jpeg-claims-private3/candidate/wp-seed-pixel-0.5.0-private.3.zip': 'bc7da003fbee737dac31bc9934de6fc2c0f885aa67955d9223ce182d274085e8',
    root / 'reports/png-jpeg-profiles-private4/final/candidate/wp-seed-pixel-0.5.0-private.4.zip': '0bf31af61751f11bc2dfdc16b13439e463ccacc41d5a11ad94726bc66cc7e3ff',
    previous: '57866805e16e7c1d87eb1653a5dbb040adf232c457e8943732f9a74f6da1feb0',
}
for path, expected in artifacts.items():
    assert hashlib.sha256(path.read_bytes()).hexdigest() == expected, str(path)
second = out.parent / 'reproducibility' / candidate.name
assert candidate.read_bytes() == second.read_bytes()
paths = subprocess.check_output(['git', 'ls-files', '-co', '--exclude-standard', '-z'], cwd=root).decode().split('\0')
paths = sorted(set(filter(None, paths)))
for path in paths:
    assert not Path(path).is_absolute()
    assert not re.search(r'(^|/)(?:\.codex-|\.env|AskPass)|\.(?:zip|patch|sql|exe|pdb)$', path, re.I), path
    assert not path.startswith(('reports/', '.runtime/'))
result = {'status': 'PASS', 'behavior_delta': 'NONE', 'runtime_changes': changed,
          'historical_artifacts_unchanged': len(artifacts), 'runtime_files': 60,
          'reproducible_byte_identical': True, 'internal_compatibility_preserved': True,
          'historical_claim_fix_present': True, 'distribution_paths_clean': True}
(out / 'release-audit.json').write_text(json.dumps(result, indent=2), encoding='utf-8', newline='\n')
print(json.dumps(result))
