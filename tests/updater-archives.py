"""Generate synthetic adversarial ZIPs only in an explicit local output folder."""
import sys
from pathlib import Path
import zipfile
root = Path(sys.argv[1]).resolve()
root.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(root / 'duplicate.zip', 'w') as z:
    z.writestr('wp-seed-pixel/wp-seed-pixel.php', '<?php\n/*\nPlugin Name: WP Seed Pixel\nVersion: 0.5.1\n*/')
    z.writestr('wp-seed-pixel/includes/class-updater.php', '<?php')
    z.writestr('wp-seed-pixel/includes/class-updater.php', '<?php')
