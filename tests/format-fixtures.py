"""Synthetic fixtures only. Private regression images are referenced externally."""
from pathlib import Path
import struct
import zlib
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageCms

root = Path(__file__).resolve().parents[1] / '.runtime' / 'format-fixtures'
root.mkdir(parents=True, exist_ok=True)
rng = np.random.default_rng(7361)

def photo(w, h):
    raw = rng.integers(0, 256, (h, w, 3), dtype=np.uint8)
    return Image.fromarray(raw).filter(ImageFilter.GaussianBlur(1.5))

def chunk(kind, data):
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

im = photo(1200, 1000)
im.save(root / 'photo.png')
im.save(root / 'provenance.png')
p = (root / 'provenance.png').read_bytes()
(root / 'provenance.png').write_bytes(p[:33] + chunk(b'caBX', b'SYNTHETIC TEST ONLY - NOT SIGNED C2PA') + p[33:])
photo(2000, 1800).save(root / 'large-photo.png')
smooth = photo(1200, 1000).filter(ImageFilter.GaussianBlur(3))
smooth.save(root / 'smooth.png')
smooth.save(root / 'smooth-provenance.png')
p = (root / 'smooth-provenance.png').read_bytes()
(root / 'smooth-provenance.png').write_bytes(p[:33] + chunk(b'caBX', b'SYNTHETIC TEST ONLY - NOT SIGNED C2PA') + p[33:])
Image.fromarray(rng.integers(0, 256, (1000, 1200, 3), dtype=np.uint8)).save(root / 'noisy.png')
portrait = photo(1200, 1000)
d = ImageDraw.Draw(portrait)
d.ellipse((260, 130, 940, 890), fill='#ccaa88')
for x in range(280, 920, 7): d.line((x, 80, x-30, 300), fill='#302824', width=2)
portrait.save(root / 'portrait.png')
im.convert('L').save(root / 'gray.png')
im.save(root / 'icc.png', icc_profile=ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes())
rgba = im.convert('RGBA'); rgba.putalpha(160); rgba.save(root / 'transparent.png')
im.quantize(128).save(root / 'palette.png')
Image.new('RGB', (64, 64), '#335577').save(root / 'small.png')
flat = Image.new('RGB', (1200, 1000), 'white'); d = ImageDraw.Draw(flat)
d.rectangle((100, 100, 900, 800), fill='#00583b'); d.ellipse((200, 200, 800, 700), fill='#ffcc44')
flat.save(root / 'logo.png')
flat.save(root / 'lossless.png', compress_level=0)
text = Image.new('RGB', (1200, 1000), 'white'); d = ImageDraw.Draw(text)
for y in range(10, 990, 18): d.text((10, y), 'SYNTHETIC SCREENSHOT 0123456789 ABCDEFGHIJKLMNOPQRSTUVWXYZ ' * 3, fill='black')
text.save(root / 'text.png')
line = Image.new('RGB', (1200, 1000), 'white'); d = ImageDraw.Draw(line)
for x in range(0, 1200, 12): d.line((x, 0, 1200-x, 999), fill='black', width=1)
line.save(root / 'line.png')
print('11 synthetic PNG fixtures generated outside Git')
