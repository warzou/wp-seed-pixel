"""Materialize the one generated non-private photographic JPEG pilot source."""
import argparse
import hashlib
import json
from pathlib import Path
from PIL import Image, ImageCms

parser = argparse.ArgumentParser()
parser.add_argument("source", type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
output = root / "reports/storage-m5.1/first-real-pilot"
output.mkdir(parents=True, exist_ok=True)
target = output / "IMAGE-A-ORIGINAL.jpg"
if target.exists():
    raise SystemExit("ORIGINAL_ALREADY_PRESERVED")
with Image.open(args.source) as image:
    if image.size != (1536, 1024) or image.format != "PNG":
        raise SystemExit("GENERATED_FIXTURE_SHAPE_REQUIRED")
    profile = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
    exif = Image.Exif()
    exif[274] = 1
    image.convert("RGB").save(target, quality=100, subsampling=0, icc_profile=profile, exif=exif)
with Image.open(target) as image:
    image.load()
    result = {"origin": "single built-in imagegen generic still life; no persons, logos or site media",
              "jpeg_source_created_locally": True, "dimensions": list(image.size), "bytes": target.stat().st_size,
              "sha256": hashlib.sha256(target.read_bytes()).hexdigest(), "icc": "sRGB assigned to generated RGB fixture",
              "icc_bytes": len(image.info.get("icc_profile", b"")), "orientation": image.getexif().get(274),
              "decode": "PASS", "pixel_peak_formula_bytes": target.stat().st_size * 4 + image.width * image.height * 8 + 16777216}
(output / "image-a.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
print(json.dumps(result, indent=2))
