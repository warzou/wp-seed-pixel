# Bounded RGB ICC handling (0.3.1 candidate)

JPEG APP2 chunks are bounded to 1 MiB total and must form one complete sequence.
The ICC header, RGB input/XYZ or Lab PCS and tag boundaries are validated before
decoding. CMYK/grayscale remain unsupported. Invalid profiles never fall back to
raw RGB or metadata stripping.

Imagick must expose LittleCMS and PNG support. Its decoded ICC bytes must match
the validated embedded profile. A relative-colorimetric transform to the bundled
LittleCMS built-in sRGB profile happens before orientation and metadata stripping.
The profile copyright permits free use. No external font/profile/service download
occurs at runtime.

The resulting 8-bit PNG is a lossless, private working reference, never a public
master or attachment. WordPress editors generate JPEG candidates from it. GD
quality sampling compares against this managed reference, not unconverted RGB.
PNG-reference reuse is disabled. Only owned JPEG derivatives are committed using
the existing CAS/journal path. Workspace cleanup includes the exact PNG filename.

Manifest `color` records conversion, source/target profile hashes, method and
algorithm version. No private profile bytes or working absolute path are saved.
Original master bytes/hashes, native sizes, public THUMB/VIEW API and old
generations remain unchanged. Updating the plugin never triggers reprocessing.

Tests: `tests/icc-fixtures.py`, `tests/color.php` with and without Imagick,
`tests/color-assertions.py`, and the existing adaptive/adversarial suites.
Fixtures are synthetic. Independent Pillow/CMS comparisons verify RGB rounding,
not perceptual Delta E or arbitrary monitor accuracy. Conversion to sRGB can
clip out-of-gamut colors. Real-host support requires a separate scoped pilot.

References: [Imagick profileImage](https://www.php.net/manual/en/imagick.profileimage.php)
and [ImageMagick color management](https://imagemagick.org/color-management/).
