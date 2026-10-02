"""Deterministic, allowlisted release ZIP; no runtime or test artifacts."""
from pathlib import Path
import hashlib
import json
import zipfile
import re

root = Path(__file__).resolve().parents[1]
files = [root / name for name in ["wp-seed-pixel.php", "uninstall.php", "README.md", "readme.txt", "CHANGELOG.md", "LICENSE", "SECURITY.md"]]
for directory, suffixes in [("includes", {".php"}), ("assets", {".css", ".js"}), ("docs", {".md"})]:
    for file in sorted((root / directory).rglob("*")):
        if file.is_file():
            if file.is_symlink() or file.suffix not in suffixes or file.name.startswith("."):
                raise ValueError(f"Non-release file in {directory}")
            files.append(file)
dist = root / "dist"
dist.mkdir(exist_ok=True)
version = re.search(r"define\('WP_SEED_PIXEL_VERSION', '([0-9.]+)'\)", (root / 'wp-seed-pixel.php').read_text(encoding='utf-8')).group(1)
archive = dist / ("wp-seed-pixel-" + version + ".zip")
temporary = dist / (archive.name + ".tmp")
if any(not file.is_file() or file.is_symlink() for file in files):
    raise ValueError("Missing or symlinked release input")
manifest = []
with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as target:
    for directory in ["wp-seed-pixel/", "wp-seed-pixel/includes/", "wp-seed-pixel/assets/", "wp-seed-pixel/docs/"]:
        entry = zipfile.ZipInfo(directory, (2026, 10, 2, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = (0o40755 << 16) | 0x10
        target.writestr(entry, b"")
    for file in sorted(files):
        data = file.read_bytes()
        relative = file.relative_to(root).as_posix()
        entry = zipfile.ZipInfo("wp-seed-pixel/" + relative, (2026, 10, 2, 0, 0, 0))
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        target.writestr(entry, data)
        manifest.append({"file": relative, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()})
with zipfile.ZipFile(temporary) as check:
    if check.testzip() is not None:
        raise ValueError("Archive CRC validation failed")
temporary.replace(archive)
result = {"archive": archive.name, "bytes": archive.stat().st_size, "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "files": manifest}
(root / "reports/product-ux").mkdir(parents=True, exist_ok=True)
(root / "reports/product-ux/package-manifest.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
(dist / (archive.name + ".sha256")).write_text(result["sha256"] + "  " + archive.name + "\n", encoding="ascii")
print(json.dumps({key: value for key, value in result.items() if key != "files"}, indent=2))
