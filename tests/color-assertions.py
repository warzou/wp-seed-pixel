"""Compare the managed working reference with an independent Pillow CMS call."""
from pathlib import Path
import io
import json
import numpy as np
from PIL import Image, ImageCms, ImageOps

root = Path(__file__).resolve().parents[1]
cases = []
target = ImageCms.createProfile('sRGB')
for name in ['srgb', 'p3', 'p3-small', 'p3-large', 'p3-rotated']:
    source = Image.open(root / '.runtime' / 'fixtures' / f'{name}-icc.jpg')
    profile = ImageCms.ImageCmsProfile(io.BytesIO(source.info['icc_profile']))
    expected = ImageCms.profileToProfile(ImageOps.exif_transpose(source), profile, target, renderingIntent=ImageCms.Intent.RELATIVE_COLORIMETRIC, outputMode='RGB')
    actual = Image.open(root / 'reports' / 'color' / f'{name}-icc.jpg-reference.png')
    difference = np.abs(np.asarray(actual).astype(float)-np.asarray(expected).astype(float))
    case = {'fixture': name, 'max_rgb_delta': float(difference.max()), 'mean_rgb_delta': float(difference.mean()), 'status': 'PASS' if difference.max() <= 2 and difference.mean() <= 0.6 else 'FAIL'}
    cases.append(case)
    canvas = Image.new('RGB', (900, 330), 'white')
    for x, image in [(0, expected), (450, actual)]:
        image.thumbnail((450, 300)); canvas.paste(image, (x, 30))
    canvas.save(root / 'reports' / 'color' / f'{name}-comparison.png')
report = {'reference': 'Independent Pillow/LittleCMS relative-colorimetric transform; RGB rounding check, not a perceptual Delta E claim', 'cases': cases}
(root / 'reports' / 'color' / 'REFERENCE-COMPARISON.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
raise SystemExit(any(case['status'] == 'FAIL' for case in cases))
