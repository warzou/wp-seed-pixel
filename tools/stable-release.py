"""Build a reproducible stable runtime from an authenticated release candidate."""
import argparse
from datetime import date
import hashlib
import io
import json
from pathlib import Path
import re
import sys
import zipfile
import zlib

p = argparse.ArgumentParser()
p.add_argument("--output", required=True)
p.add_argument("--reference", required=True)
p.add_argument("--reference-sha256", required=True)
p.add_argument("--released", required=True)
a = p.parse_args()
released = date.fromisoformat(a.released)
root = Path(__file__).resolve().parents[1]
out = Path(a.output).resolve()
entry = (root / "wp-seed-pixel.php").read_bytes()
version = re.search(rb"define\('WP_SEED_PIXEL_VERSION', '([^']+)'\)", entry)[1].decode()
build = re.search(rb"define\('WP_SEED_PIXEL_BUILD', '([^']+)'\)", entry)[1].decode()
if not re.fullmatch(r"\d+\.\d+\.\d+", version) or build != version:
    raise ValueError("Stable version/build required")
reference = Path(a.reference).read_bytes()
if hashlib.sha256(reference).hexdigest() != a.reference_sha256:
    raise ValueError("Candidate digest mismatch")
with zipfile.ZipFile(io.BytesIO(reference)) as z:
    if z.testzip() is not None or len(z.namelist()) != len(set(z.namelist())):
        raise ValueError("Invalid candidate archive")
    prior = {}
    for name in z.namelist():
        if not name.startswith("wp-seed-pixel/") or ".." in name or name.endswith("/"):
            raise ValueError("Unexpected candidate path")
        prior[name.removeprefix("wp-seed-pixel/")] = z.read(name)
prior_version = re.search(rb"define\('WP_SEED_PIXEL_VERSION', '([^']+)'\)", prior["wp-seed-pixel.php"])[1].decode()
if not prior_version.startswith(version + "-rc."):
    raise ValueError("Candidate is not for this stable version")
if prior["wp-seed-pixel.php"].count(prior_version.encode()) != 4:
    raise ValueError("Candidate version surface changed")
if prior["wp-seed-pixel.php"].replace(prior_version.encode(), version.encode()) != entry:
    raise ValueError("Functional entry-point change during promotion")
paths = [root / n for n in ("LICENSE", "README.md", "readme.txt", "uninstall.php", "wp-seed-pixel.php", "docs/MIGRATION.md")]
for name in ("assets", "includes", "languages"):
    paths += [x for x in (root / name).rglob("*") if x.is_file()]
paths.sort(key=lambda x: x.relative_to(root).as_posix())
current = {}
for path in paths:
    if path.is_symlink() or path.suffix in (".sql", ".zip", ".exe", ".log"):
        raise ValueError("Unexpected runtime file")
    current[path.relative_to(root).as_posix()] = path.read_bytes()
if current.keys() != prior.keys():
    raise ValueError("Runtime file set changed during promotion")
allowed = {"wp-seed-pixel.php", "README.md", "readme.txt", "docs/MIGRATION.md"}
changed = [name for name in current if current[name] != prior[name]]
if set(changed) - allowed:
    raise ValueError("Functional runtime change: " + str(set(changed) - allowed))
if f"Stable tag: {version}".encode() not in current["readme.txt"]:
    raise ValueError("Readme stable tag mismatch")

def archive():
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, data in current.items():
            item = zipfile.ZipInfo("wp-seed-pixel/" + name, (released.year, released.month, released.day, 12, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(item, data, compresslevel=9)
    return stream.getvalue()

data = archive()
if data != archive():
    raise ValueError("Package not reproducible")
out.mkdir(parents=True, exist_ok=True)
name = f"wp-seed-pixel-{version}.zip"
target = out / name
if target.exists() and target.read_bytes() != data:
    raise ValueError("Never overwrite a different release artifact")
target.write_bytes(data)
sha = hashlib.sha256(data).hexdigest()
manifest = {"schema": 1, "slug": "wp-seed-pixel", "channel": "stable", "version": version,
            "requires": "6.6", "tested": "7.1", "requires_php": "8.1",
            "package": f"https://github.com/warzou/wp-seed-pixel/releases/download/v{version}/{name}",
            "sha256": sha, "released": a.released,
            "notes_url": f"https://github.com/warzou/wp-seed-pixel/releases/tag/v{version}"}
evidence = {"version": version, "bytes": len(data), "sha256": sha, "files": {n: hashlib.sha256(b).hexdigest() for n, b in current.items()},
            "reference_sha256": a.reference_sha256, "changed": changed, "functional_changes": 0,
            "reproducible": True, "python": sys.version.split()[0], "zlib": zlib.ZLIB_RUNTIME_VERSION}
(out / (name + ".sha256")).write_text(sha + "  " + name + "\n", encoding="ascii")
(out / "stable.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="ascii")
(out / "PACKAGE.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="ascii")
print(json.dumps({k: v for k, v in evidence.items() if k != "files"}))
