"""Synthetic lossless PNG matrix; no consumer media."""
from pathlib import Path
import struct
import zlib
from PIL import Image, ImageCms

root = Path(__file__).resolve().parents[1] / '.runtime' / 'fixtures'
root.mkdir(parents=True, exist_ok=True)
for mode in ['RGB', 'RGBA', 'L', 'LA', 'P']:
    image = Image.new(mode, (512, 512))
    if mode == 'P':
        image.putpalette([c for i in range(256) for c in (i, 255-i, i//2)])
    for y in range(512):
        for x in range(512):
            a = (x//8 + y//16) % 256
            value = {'RGB': (a, y//4, x//4), 'RGBA': (a, y//4, x//4, (x+y) % 256), 'L': a, 'LA': (a, (x+y) % 256), 'P': a}[mode]
            image.putpixel((x, y), value)
    options = {'compress_level': 0}
    if mode == 'P': options['transparency'] = bytes(range(256))
    image.save(root / ('m6-' + mode + '.png'), **options)
    if mode == 'RGB':
        image.save(root / 'm6-profile.png', compress_level=0, icc_profile=ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes())
        image.save(root / 'm6-efficient.png', compress_level=9)
        image.save(root / 'm6-animation.png', save_all=True, append_images=[image.copy()], duration=100, default_image=True)
Image.new('I;16', (512, 512), 12345).save(root / 'm6-depth16.png')
Image.new('L', (2000, 1800), 127).save(root / 'm6-large.png', compress_level=0)
Image.new('RGB', (2050, 2050), (40, 50, 60)).save(root / 'm6-oversized.png', compress_level=0)
data = (root / 'm6-RGB.png').read_bytes()
(root / 'm6-crc.png').write_bytes(data[:50] + bytes([data[50] ^ 1]) + data[51:])
(root / 'm6-trailing.png').write_bytes(data + b'foreign-trailer')
print('12 bounded synthetic PNG fixtures prepared.')

def chunk(kind, payload):
    return struct.pack('>I', len(payload)) + kind + payload + struct.pack('>I', zlib.crc32(kind + payload))

parts, offset = [], 8
while offset < len(data):
    length = struct.unpack('>I', data[offset:offset+4])[0]
    parts.append((data[offset+4:offset+8], data[offset+8:offset+8+length]))
    offset += length + 12
scanlines = zlib.decompress(b''.join(p for k, p in parts if k == b'IDAT'))
for name, replacement in [('bad-filter', bytes([5]) + scanlines[1:]), ('inflate-extra', scanlines + b'x')]:
    out, done = bytearray(data[:8]), False
    for kind, payload in parts:
        if kind != b'IDAT': out += chunk(kind, payload)
        elif not done:
            out += chunk(kind, zlib.compress(replacement)); done = True
    (root / ('m6-' + name + '.png')).write_bytes(out)
for name, kind, payload in [('unknown-critical', b'ABCD', b'x'), ('compressed-text', b'zTXt', b'n\0\0' + zlib.compress(b'text')), ('bad-profile', b'iCCP', b'profile\0\0' + zlib.compress(b'invalid'))]:
    out = bytearray(data[:8])
    for typ, value in parts:
        out += chunk(typ, value)
        if typ == b'IHDR': out += chunk(kind, payload)
    (root / ('m6-' + name + '.png')).write_bytes(out)
print('Five additional hostile PNG fixtures prepared.')
