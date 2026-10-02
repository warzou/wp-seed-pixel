from pathlib import Path
from PIL import Image, ImageOps
import io
import json
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
php = Path(sys.argv[1])
command = [str(php), "-d", f"extension_dir={php.parent / 'ext'}", "-d", "extension=gd", "-d", "extension=exif", "-d", "extension=mbstring", "-d", "extension=pdo_sqlite", "-d", "extension=mysqli", str(root / "tests/visual-input.php")]
rows = json.loads(subprocess.check_output(command, text=True))
results = []
for orientation in range(1, 9):
    row = rows[str(orientation)]
    expected = ImageOps.exif_transpose(Image.open(row["source"]))
    actual = Image.open(row["output"])
    expected.thumbnail(actual.size)
    deltas = []
    for x, y in [(0.25, 0.25), (0.75, 0.25), (0.25, 0.75), (0.75, 0.75)]:
        a = actual.getpixel((int(actual.width * x), int(actual.height * y)))
        b = expected.getpixel((int(expected.width * x), int(expected.height * y)))
        deltas.append(max(abs(c - d) for c, d in zip(a, b)))
    results.append({"test": f"Orientation {orientation} actual corner placement", "status": "PASS" if max(deltas) < 10 else "FAIL", "max_channel_delta": max(deltas)})
for name, quality in [("thumb", 80), ("view", 90)]:
    image = Image.open(rows["quality"][name]["path"])
    reference = io.BytesIO()
    Image.new("RGB", (8, 8)).save(reference, format="JPEG", quality=quality)
    expected_tables = Image.open(reference).quantization
    results.append({"test": f"Actual JPEG quantization Q{quality}", "status": "PASS" if image.quantization == expected_tables else "FAIL"})
(root / "reports/final/visual-assertions.json").write_text(json.dumps({"tests": results}, indent=2), encoding="utf-8")
print(json.dumps({"tests": len(results), "pass": sum(row["status"] == "PASS" for row in results), "failed": [row for row in results if row["status"] == "FAIL"]}, indent=2))
sys.exit(any(row["status"] == "FAIL" for row in results))
