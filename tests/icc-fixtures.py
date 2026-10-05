"""Synthetic RGB ICC cases; no consuming-site image or profile is copied."""
from pathlib import Path
import struct
import io
import numpy as np
from PIL import Image, ImageCms

root = Path(__file__).resolve().parents[1] / '.runtime' / 'fixtures'
root.mkdir(parents=True, exist_ok=True)
srgb = ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes()
p3 = bytearray(srgb)
tags = {srgb[132+i*12:136+i*12]: struct.unpack('>II', srgb[136+i*12:144+i*12]) for i in range(struct.unpack('>I', srgb[128:132])[0])}

# Display P3 primaries, D65 white, Bradford adaptation to ICC D50 PCS.
xy = [(0.68, 0.32), (0.265, 0.69), (0.15, 0.06)]
primaries = np.array([[x/y, 1, (1-x-y)/y] for x, y in xy]).T
d65 = np.array([0.3127/0.329, 1, (1-0.3127-0.329)/0.329])
d50 = np.array([0.9642, 1, 0.8249])
bradford = np.array([[0.8951, 0.2664, -0.1614], [-0.7502, 1.7135, 0.0367], [0.0389, -0.0685, 1.0296]])
adapt = np.linalg.inv(bradford) @ np.diag((bradford @ d50)/(bradford @ d65)) @ bradford
matrix = adapt @ primaries @ np.diag(np.linalg.solve(primaries, d65))
for i, name in enumerate([b'rXYZ', b'gXYZ', b'bXYZ']):
    offset, _ = tags[name]
    p3[offset+8:offset+20] = struct.pack('>iii', *(round(float(value)*65536) for value in matrix[:, i]))
p3[84:100] = bytes(16)
ImageCms.ImageCmsProfile(io.BytesIO(bytes(p3)))

pixels = np.zeros((600, 900, 3), dtype=np.uint8)
pixels[:, :, 0] = np.linspace(0, 255, 900, dtype=np.uint8)[None, :]
pixels[:, :, 1] = np.linspace(0, 255, 600, dtype=np.uint8)[:, None]
pixels[:, :, 2] = 88
pixels[80:200, 80:220] = [190, 133, 108]
image = Image.fromarray(pixels)
for name, profile, size, orientation in [('srgb', srgb, (900,600), 1), ('p3', bytes(p3), (900,600), 1), ('p3-small', bytes(p3), (180,120), 1), ('p3-large', bytes(p3), (2400,1600), 1), ('p3-rotated', bytes(p3), (900,600), 6)]:
    exif = Image.Exif(); exif[274] = orientation; exif[40961] = 65535
    image.resize(size).save(root / f'{name}-icc.jpg', quality=96, icc_profile=profile, exif=exif)
image.save(root / 'bad-icc.jpg', icc_profile=b'invalid-profile')
data = (root / 'p3-icc.jpg').read_bytes()
start = data.index(b'ICC_PROFILE\0')
bad = bytearray(data); bad[start+12] = 2
(root / 'missing-icc-chunk.jpg').write_bytes(bad)
bad = bytearray(data); bad[start+14+16:start+14+20] = b'CMYK'
(root / 'non-rgb-icc.jpg').write_bytes(bad)
print('Synthetic sRGB / Display P3 / malformed ICC fixtures ready.')
