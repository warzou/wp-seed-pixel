from pathlib import Path
from PIL import Image, ImageDraw

root = Path(__file__).resolve().parents[1] / ".runtime" / "fixtures"
root.mkdir(parents=True, exist_ok=True)
image = Image.new("RGB", (2400, 1600), "white")
draw = ImageDraw.Draw(image)
for x, color in [(0, "red"), (1200, "green")]:
    draw.rectangle((x, 0, x + 1199, 799), fill=color)
for x, color in [(0, "blue"), (1200, "yellow")]:
    draw.rectangle((x, 800, x + 1199, 1599), fill=color)
draw.text((100, 100), "WP SEED PIXEL - SYNTHETIC QA", fill="black")
image.save(root / "rgb.jpg", quality=96)
for orientation in range(1, 9):
    exif = Image.Exif()
    exif[274] = orientation
    image.save(root / f"orientation-{orientation}.jpg", quality=96, exif=exif)
image.resize((200, 120)).save(root / "small.jpg", quality=96)
image.resize((3600, 2400)).save(root / "big.jpg", quality=96)
image.convert("CMYK").save(root / "cmyk.jpg", quality=90)
image.save(root / "icc.jpg", quality=90, icc_profile=b"synthetic-untrusted-ICC-profile")
image.save(root / "png.png")
frames = [image.resize((80, 50)), image.resize((80, 50)).transpose(Image.Transpose.FLIP_LEFT_RIGHT)]
frames[0].save(root / "animated.gif", save_all=True, append_images=frames[1:], duration=200, loop=0)
(root / "corrupt.jpg").write_bytes(b"not a JPEG\x00")
(root / "spoof.jpg").write_text("<svg><script>alert(1)</script></svg>", encoding="utf-8")
original = (root / "rgb.jpg").read_bytes()
padding = (b"\xff\xfe" + (60002).to_bytes(2, "big") + b"x" * 60000) * 36
icc = b"ICC_PROFILE\0\x01\x01synthetic-profile"
(root / "late-icc.jpg").write_bytes(original[:2] + padding + b"\xff\xe2" + (len(icc) + 2).to_bytes(2, "big") + icc + original[2:])
exif = Image.Exif()
exif[274] = 6
exif[34853] = {1: "N", 2: (48, 30, 0), 3: "E", 4: (2, 20, 0)}
image.save(root / "gps.jpg", quality=96, exif=exif)
exif = Image.Exif()
exif[274] = 9
image.save(root / "invalid-orientation.jpg", quality=96, exif=exif)
exif = Image.Exif()
exif[40961] = 65535
image.save(root / "uncalibrated.jpg", quality=96, exif=exif)
print("Synthetic fixtures created; no private photographs used.")
