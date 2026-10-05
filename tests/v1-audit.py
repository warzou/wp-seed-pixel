"""Bounded isolation/secret/package audit, with all credentials suppressed."""
from pathlib import Path
import hashlib
import json
import re
import subprocess

root = Path(__file__).resolve().parents[1]
paths = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=root).decode().split('\0')
paths = sorted({p for p in paths if p})
for name in paths:
    p = root / name
    if not p.resolve().is_relative_to(root) or p.is_symlink(): raise SystemExit('ISOLATION_FAILED')
    if re.search(r'(^|/)(reports|dist|node_modules|\.runtime|\.codex[^/]*|AskPass)(/|$)|\.(zip|patch|sql|exe|pdb|tmp)$', name, re.I):
        raise SystemExit('NON_SOURCE_GIT_ARTIFACT')
secret_values = []
for line in Path(r'C:\Dev\git\therapsycorporel-site\.env').read_text(encoding='utf-8-sig').splitlines():
    match = re.match(r'^\s*([A-Z_]*(?:PASSWORD|TOKEN|SECRET))\s*=(.*)$', line)
    if match:
        value = match[2].strip().strip('"').strip("'")
        if len(value) >= 8: secret_values.extend([value, value.replace(' ', '')])
files = [root / p for p in paths]
for folder in ['reports/storage-v1', 'reports/storage-m5.1/first-real-pilot']:
    files.extend(p for p in (root / folder).rglob('*') if p.is_file())
scanned = 0
for p in files:
    if p.suffix.lower() in {'.png', '.jpg', '.jpeg', '.gif', '.zip', '.mo'}: continue
    try: text = p.read_text(encoding='utf-8-sig')
    except UnicodeDecodeError: raise SystemExit('UNEXPECTED_NON_UTF8_TEXT')
    if any(value in text for value in secret_values) or re.search(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', text):
        raise SystemExit('SECRET_HIT_VALUE_SUPPRESSED')
    if re.search(r'\b(?:gh[opusr]_[A-Za-z0-9]{36,255}|github_pat_[A-Za-z0-9_]{50,255}|AKIA[0-9A-Z]{16}|sk-(?:proj-)?[A-Za-z0-9_-]{30,})\b', text):
        raise SystemExit('CREDENTIAL_PATTERN_HIT_VALUE_SUPPRESSED')
    scanned += 1
secret_values.clear()
subprocess.run(['git', 'diff', '--check'], cwd=root, check=True)
if subprocess.check_output(['git', 'diff', '--cached', '--name-only'], cwd=root): raise SystemExit('INDEX_NOT_EMPTY')
changed = subprocess.check_output(['git', 'diff', '--name-only', '-z', 'HEAD'], cwd=root).decode().split('\0')
untracked = subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard', '-z'], cwd=root).decode().split('\0')
candidate = sorted({p for p in changed + untracked if p})
if not set(candidate).issubset(paths): raise SystemExit('UNAUDITED_COMMIT_PATH')
if candidate:
    baseline = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode().strip()
    if baseline != '3da4435591488e6089b827c7b581c706e1e4a034':
        raise SystemExit('COMMIT_BASE_CHANGED')
    (root / 'reports/storage-v1/commit-candidate-paths.json').write_text(json.dumps({'base_sha': baseline, 'paths': candidate}, indent=2) + '\n', encoding='utf-8')
stable = root / 'dist/wp-seed-pixel-0.3.2.zip'
if stable.stat().st_size != 455161 or hashlib.sha256(stable.read_bytes()).hexdigest() != 'b685dd3a2f340862c08626d872710410a934327fd2e069b9bbe115c9ba2f337b':
    raise SystemExit('STABLE_ARTIFACT_CHANGED')
checks = 0
for i in (1,2):
    for p in (root / f'reports/storage-v1/cycle-{i}').rglob('*.json'):
        data = json.loads(p.read_text(encoding='utf-8-sig'))
        evidence = data.get('checks', {}) if isinstance(data, dict) else {}
        if isinstance(evidence, dict):
            if any(v is False for v in evidence.values()): raise SystemExit('FAILED_FINAL_CHECK')
            checks += len(evidence)
result = {'source_paths': len(paths), 'text_files_scanned': scanned, 'known_secret_hits': 0, 'private_key_hits': 0, 'credential_pattern_hits': 0, 'git_diff_check': 'PASS', 'index_empty': True,
          'commit_candidate_paths': len(candidate), 'stable_zip_unchanged': True, 'boolean_checks_in_final_evidence': checks, 'pde_access': 0, 'push': 0}
(root / 'reports/storage-v1/isolation-audit.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
print(json.dumps(result))
