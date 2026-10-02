"""Create a disposable local WordPress test tree from official downloads."""
from pathlib import Path
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / ".runtime"

def extract(archive, destination):
    destination.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive) as source:
        for entry in source.infolist():
            target = (destination / entry.filename).resolve()
            if not target.is_relative_to(destination.resolve()):
                raise ValueError("Unsafe ZIP path")
            if (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError("ZIP symbolic link rejected")
        source.extractall(destination)

site = RUNTIME / "wordpress"
if not (site / "wp-load.php").exists():
    extract(RUNTIME / "wordpress.zip", RUNTIME)
    extract(RUNTIME / "sqlite.zip", site / "wp-content" / "plugins")
    shutil.copyfile(site / "wp-content/plugins/sqlite-database-integration/db.copy", site / "wp-content/db.php")
plugin = site / "wp-content/plugins/wp-seed-pixel"
plugin.mkdir(parents=True, exist_ok=True)
for relative in ["wp-seed-pixel.php", "uninstall.php", "includes", "assets"]:
    source, destination = ROOT / relative, plugin / relative
    if source.is_dir():
        shutil.copytree(source, destination, dirs_exist_ok=True)
    else:
        shutil.copyfile(source, destination)
print("Disposable WordPress runtime prepared; no existing site was used.")
