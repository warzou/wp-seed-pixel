"""Reproducible LOCAL candidate only; never updates release assets or manifests."""
import hashlib
import io
import json
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION = "0.6.1-private.1"
entry = (ROOT / "wp-seed-pixel.php").read_text(encoding="utf-8")
if f"Version: {VERSION}" not in entry or f"'WP_SEED_PIXEL_BUILD', '{VERSION}'" not in entry:
    raise RuntimeError("Candidate identity mismatch")
paths = [ROOT / name for name in ("LICENSE", "README.md", "readme.txt", "uninstall.php", "wp-seed-pixel.php")]
for directory in ("assets", "includes", "languages"):
    paths.extend(p for p in (ROOT / directory).rglob("*") if p.is_file())
paths.sort(key=lambda p: p.relative_to(ROOT).as_posix())
if any(p.is_symlink() or p.suffix.lower() in (".zip", ".patch", ".exe", ".pdb", ".sql") for p in paths):
    raise RuntimeError("Unexpected runtime artifact")
evidence = ROOT / "reports/metadata-alias-0.6.1-20261010/evidence"
for name in ("native-alias", "native-graph", "admission", "pipeline", "runtime-review", "graph-crashes", "alias-crashes"):
    gate = json.loads((evidence / (name + ".json")).read_text(encoding="utf-8"))
    if gate.get("passed", 0) <= 0 or len(gate.get("checks", [])) != gate["passed"]:
        raise RuntimeError("Native certification gate missing: " + name)

def build():
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in paths:
            relative = path.relative_to(ROOT).as_posix()
            item = zipfile.ZipInfo("wp-seed-pixel/" + relative, (2026, 10, 10, 12, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(item, path.read_bytes(), compresslevel=9)
    return stream.getvalue()

data = build()
if data != build():
    raise RuntimeError("Non-reproducible candidate")
out = ROOT / "reports/metadata-alias-0.6.1-20261010/candidate"
out.mkdir(parents=True, exist_ok=True)
target = out / f"wp-seed-pixel-{VERSION}.zip"
target.write_bytes(data)
digest = hashlib.sha256(data).hexdigest()
(out / (target.name + ".sha256")).write_text(f"{digest}  {target.name}\n", encoding="ascii")
with zipfile.ZipFile(io.BytesIO(data)) as archive:
    if archive.testzip() is not None:
        raise RuntimeError("ZIP integrity failed")
    for path in paths:
        if archive.read("wp-seed-pixel/" + path.relative_to(ROOT).as_posix()) != path.read_bytes():
            raise RuntimeError("Runtime/ZIP mismatch")
manifest = {"version": VERSION, "status": "LOCAL_ONLY_CERTIFIED_FOR_CONTROLLED_DEV_RETRY", "bytes": len(data),
            "sha256": digest, "runtime_files": len(paths), "reproducible": True,
            "files": {p.relative_to(ROOT).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}}
(out.parent / "CANDIDATE.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="ascii")
print(json.dumps({k: v for k, v in manifest.items() if k != "files"}))
