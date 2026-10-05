"""Build a private frozen M5.1 pilot ZIP without replacing the stable artifact."""
import hashlib
import json
from pathlib import Path
import re
import zipfile


def main():
    root = Path(__file__).resolve().parents[1]
    if root != Path(r"C:\Dev\git\wp-seed-pixel"):
        raise SystemExit("OWNED_CHECKOUT_REQUIRED")
    frozen = root / "reports/storage-m5.1/authority-gate/mariadb/runtime.sha256"
    if hashlib.sha256(frozen.read_bytes()).hexdigest() != "145247be8c6fa0fcb9460ce947cade49ab43c270d3b3d8db223d715b1f210eba":
        raise SystemExit("FROZEN_MANIFEST_CHANGED")
    for row in frozen.read_text(encoding="ascii").splitlines():
        digest, relative = row.split("  ", 1)
        path = root / relative
        if not path.is_relative_to(root) or path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise SystemExit("FROZEN_RUNTIME_CHANGED")
    files = [root / name for name in ("wp-seed-pixel.php", "uninstall.php", "readme.txt", "LICENSE")]
    for folder, suffixes in (("includes", {".php"}), ("assets", {".css", ".js"}),
                             ("languages", {".pot", ".po", ".mo"})):
        for path in sorted((root / folder).rglob("*")):
            if path.is_file():
                if path.is_symlink() or path.suffix not in suffixes or path.name.startswith("."):
                    raise SystemExit("NON_CANDIDATE_INPUT")
                files.append(path)
    if any(not path.is_file() or path.is_symlink() for path in files):
        raise SystemExit("CANDIDATE_INPUT_MISSING")
    output = root / "reports/storage-m5.1/first-real-pilot"
    output.mkdir(parents=True, exist_ok=True)
    archive = output / "wp-seed-pixel-m51-runtime-pilot.zip"
    version = re.search(r"define\('WP_SEED_PIXEL_VERSION', '([0-9.]+)'\)",
                        (root / "wp-seed-pixel.php").read_text(encoding="utf-8")).group(1)
    manifest = []
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as target:
        for folder in ("", "includes/", "assets/", "languages/"):
            entry = zipfile.ZipInfo("wp-seed-pixel/" + folder, (2026, 10, 5, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (0o40755 << 16) | 0x10
            target.writestr(entry, b"")
        for path in sorted(files):
            payload = path.read_bytes()
            relative = path.relative_to(root).as_posix()
            entry = zipfile.ZipInfo("wp-seed-pixel/" + relative, (2026, 10, 5, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            target.writestr(entry, payload)
            manifest.append({"file": relative, "bytes": len(payload), "sha256": hashlib.sha256(payload).hexdigest()})
    with zipfile.ZipFile(archive) as check:
        if check.testzip() is not None or len([entry for entry in check.infolist() if not entry.is_dir()]) != len(files):
            raise SystemExit("ZIP_VERIFICATION_FAILED")
        for entry in manifest:
            if hashlib.sha256(check.read("wp-seed-pixel/" + entry["file"])).hexdigest() != entry["sha256"]:
                raise SystemExit("ZIP_READBACK_FAILED")
    result = {"version": version, "scope": "accepted M1-M5.1 private candidate; not a public 0.4 release",
              "runtime_frozen": True, "file_count": len(files), "installed_bytes": sum(entry["bytes"] for entry in manifest),
              "largest_files": sorted(manifest, key=lambda entry: entry["bytes"], reverse=True)[:8],
              "categories": {folder: sum(entry["bytes"] for entry in manifest if entry["file"].startswith(folder + "/"))
                             for folder in ("includes", "assets", "languages")}, "zip_bytes": archive.stat().st_size,
              "zip_sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "files": manifest}
    (output / "runtime-package-manifest.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({key: value for key, value in result.items() if key != "files"}, indent=2))


if __name__ == "__main__":
    main()
