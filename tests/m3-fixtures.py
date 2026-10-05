"""Generate owned synthetic master test images, never use consumer media."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageCms
import numpy as np

root = Path(__file__).resolve().parents[1] / '.runtime' / 'fixtures'
root.mkdir(parents=True, exist_ok=True)
for name, size in [('m3-detail.jpg', (2400, 1600)), ('m3-large.jpg', (4200, 2800)), ('m3-small.jpeg', (600, 400))]:
    w, h = size
    y, x = np.mgrid[:h, :w]
    rng = np.random.default_rng(90403)
    noise = np.zeros((h, w), dtype='uint8')
    rgb = np.stack([(x / w * 190 + 30 + noise) % 256, (y / h * 170 + 50 + noise) % 256, ((x + y) / (w + h) * 120 + 70 + noise) % 256], axis=-1).astype('uint8')
    im = Image.fromarray(rgb)
    draw = ImageDraw.Draw(im)
    for i in range(min(16, h//70)):
        draw.ellipse((w//8+i*19, h//8+i*19, w*3//4-i*19, h*3//4-i*19), outline=(220, 50+i*8, 30), width=3)
    draw.text((w//5, h//3), 'Synthetic retained master / Pixel M3', fill=(5, 5, 5), stroke_width=1)
    im.save(root/name, quality=100, subsampling=0)
    if name == 'm3-detail.jpg':
        textured = (np.asarray(im).astype('uint16') + rng.integers(0, 4, (h, w, 1))).clip(0, 255).astype('uint8')
        Image.fromarray(textured).save(root/'m3-texture.jpg', quality=100, subsampling=0)
        im.save(root/'m3-light.jpg', quality=85)
        exif = Image.Exif(); exif[274] = 6
        im.save(root/'m3-orientation.jpg', quality=100, subsampling=0, exif=exif)
        exif[274] = 1; exif[315] = 'Synthetic rights owner'
        im.save(root/'m3-rights.jpg', quality=100, subsampling=0, exif=exif)
        profile = ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes()
        im.save(root/'m3-color.jpg', quality=100, subsampling=0, icc_profile=profile)
        with Image.open(root/'p3-icc.jpg') as p3:
            im.save(root/'m3-p3.jpg', quality=100, subsampling=0, icc_profile=p3.info['icc_profile'])
        im.convert('CMYK').save(root/'m3-cmyk.jpg', quality=100)
        im.convert('L').save(root/'m3-gray.jpg', quality=100)
print('Synthetic M3 fixtures generated.')
