"""Verify the private runtime ZIP against its manifest and current source."""
import hashlib
import json
from pathlib import Path
import re
import zipfile
import os

root = Path(__file__).resolve().parents[1]
out = Path(os.environ.get('PIXEL_FORMAT_REPORT_DIR', root / 'reports/png-jpeg'))
directory = out / 'candidate'
manifest = json.loads((directory / "runtime-manifest.json").read_text(encoding="utf-8"))
archive = directory / manifest["zip"]
assert archive.stat().st_size == manifest["bytes"]
assert hashlib.sha256(archive.read_bytes()).hexdigest() == manifest["sha256"]
assert manifest["version"] == manifest["build"] == "0.5.0"
allowed = {"wp-seed-pixel.php", "uninstall.php", "LICENSE", "README.md", "readme.txt"}
checks = []
with zipfile.ZipFile(archive) as zipped:
    assert zipped.testzip() is None
    assert len(zipped.namelist()) == len(set(zipped.namelist())) == len(manifest["files"])
    assert set(zipped.namelist()) == {"wp-seed-pixel/" + r["file"] for r in manifest["files"]}
    for record in manifest["files"]:
        relative = record["file"]
        assert relative in allowed or re.fullmatch(r"(?:includes/[\w-]+\.php|assets/[\w-]+\.(?:js|css)|languages/[\w.-]+\.(?:po|mo|pot))", relative)
        data = zipped.read("wp-seed-pixel/" + relative)
        assert data == (root / relative).read_bytes()
        assert len(data) == record["bytes"]
        assert hashlib.sha256(data).hexdigest() == record["sha256"]
        assert zipped.getinfo("wp-seed-pixel/" + relative).external_attr >> 16 == 0o100644
        assert b"psychotherapiedeletre.com" not in data
        assert b"Charlotte-Le-Colleter" not in data
        checks.append(relative)
result = {"status": "PASS", "files": len(checks), "bytes": manifest["bytes"],
          "runtime_bytes": manifest["runtime_bytes"], "sha256": manifest["sha256"],
          "private_media": 0, "development_files": 0, "source_zip_identity": True}
(out / 'package.json').write_text(json.dumps(result, indent=2), encoding="utf-8", newline="\n")
print(json.dumps(result))
