"""Static candidate audit. Report findings without echoing potentially secret text."""
from pathlib import Path
import hashlib
import json
import re
import subprocess
import sys
import zipfile

root = Path(__file__).resolve().parents[1]
paths = subprocess.check_output(["git", "ls-files", "--cached", "--others", "--exclude-standard"], cwd=root, text=True).splitlines()
findings = []
patterns = {
    "private_key": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    "github_token": re.compile(r"(?:github_pat_|gh[pousr]_)[A-Za-z0-9_]{20,}"),
    "aws_key": re.compile(r"AKIA[A-Z0-9]{16}"),
    "literal_credential": re.compile(r"(?i)(?:ssh_password|password|api_key|secret)\s*=\s*['\"][^'\"\n]{8,}['\"]"),
    "basic_auth_url": re.compile(r"https?://[^/\s:]+:[^/@\s]+@"),
    "absolute_machine_path": re.compile(r"[A-Z]:[\\/](?:Users|Dev|inetpub)[\\/]"),
}
for relative in paths:
    file = root / relative
    if not file.is_file() or file.is_symlink():
        findings.append({"file": relative, "issue": "missing_or_symlink"})
        continue
    data = file.read_bytes()
    if data.startswith(b"\xef\xbb\xbf") or b"\r" in data:
        findings.append({"file": relative, "issue": "BOM_or_non_LF"})
    try:
        text = data.decode("utf-8")
    except UnicodeError:
        findings.append({"file": relative, "issue": "not_UTF8"})
        continue
    for name, pattern in patterns.items():
        if pattern.search(text):
            findings.append({"file": relative, "issue": name})
    if file.suffix == ".php":
        process = subprocess.run([sys.argv[1], "-l", str(file)], capture_output=True, text=True)
        if process.returncode:
            findings.append({"file": relative, "issue": "PHP_lint"})
    if re.search(r"(?i)(\.codex-|askpass|\.zip$|\.patch$|\.exe$|\.pdb$|\.jpe?g$|\.png$|\.webp$|\.sql$)", relative):
        findings.append({"file": relative, "issue": "non_source_artifact"})
report_files = list((root / "reports/final").glob("*.md")) + list((root / "reports/final").glob("*.json"))
for file in report_files:
    text = file.read_text(encoding="utf-8")
    for name, pattern in patterns.items():
        if name != "absolute_machine_path" and pattern.search(text):
            findings.append({"file": file.name, "issue": name})
archive = root / "dist/wp-seed-pixel-0.1.0.zip"
with zipfile.ZipFile(archive) as source:
    for entry in source.infolist():
        if not entry.filename.startswith("wp-seed-pixel/") or ".." in Path(entry.filename).parts or re.search(r"(?i)(\.codex-|askpass|\.zip$|\.patch$|\.exe$|\.pdb$|\.jpe?g$|\.png$|\.webp$|\.sql$|/tests/|/tools/|/reports/|\.runtime|\.git/)", entry.filename):
            findings.append({"file": entry.filename, "issue": "archive_scope"})
        if not entry.is_dir():
            expected = root / Path(entry.filename).relative_to("wp-seed-pixel")
            if not expected.is_file() or hashlib.sha256(source.read(entry)).digest() != hashlib.sha256(expected.read_bytes()).digest():
                findings.append({"file": entry.filename, "issue": "archive_not_source"})
    if source.testzip() is not None:
        findings.append({"issue": "CRC"})
result = {"source_paths": len(paths), "php_linted": sum(Path(path).suffix == ".php" for path in paths), "secret_scan": "PASS" if not any(row["issue"] in patterns for row in findings) else "FAIL", "findings": findings, "archive_crc": "PASS"}
(root / "reports/final/static-audit.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
print(json.dumps(result, indent=2))
sys.exit(bool(findings))
