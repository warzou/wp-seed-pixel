# Adaptive algorithm 0.2.0

Strategy: bounded local search, algorithm `bounded-rgb-3`. Settings schema remains
the three existing fields (schema 1); automatic processing stays OFF by default.
0.2.0 is a minor feature release, not a 0.1.1 bugfix: it adds resource reuse and
a new optimization intent. Existing explicit fixed/custom profiles stay stable.

## Decision

Balanced targets THUMB 640 and VIEW 1920, preserving aspect ratio and never
enlarging. The 1920 cap reduces pixel work versus 2048 without downscaling the
twelve already smaller private masters. It is a usage choice, not a quality score.
Source-safe means no private JPEG APP/COM markers and no ICC profile. EXIF-bearing
sources cannot take the source-reuse branch, including orientation-only EXIF.
Only a canonical 14-byte JFIF APP0 without embedded thumbnail is considered safe;
extended/noncanonical APP0 requires sanitization rather than source reuse.

1. A safe bounded source under 50,000 bytes (THUMB) or 500,000 bytes (VIEW)
   is reused with zero encodes.
2. Otherwise try Q78, Q86, Q94, independently from MASTER via WP_Image_Editor.
3. Correct EXIF pixels, resize, set quality AFTER resize, save privately.
4. Compare with an oriented MASTER resampled to exactly the candidate dimensions.
5. Stop at the first candidate passing both quality gates. Require at least 5%
   byte saving for clean sources. Otherwise retain the safe MASTER, not an inflated JPEG.
6. If all candidates fail, retain the safe MASTER with an explicit reason. Its
   dimensions/bytes may exceed targets. Unsafe sources fail closed, preserving
   old mappings. Required metadata/orientation sanitization may justify a larger
   derived resource; the manifest discloses that functional exception.

Only the winner is published. Rejected encodings overwrite a single private
stage file, never a MASTER or a previous encoding input. Maximum six encodes per
Balanced attachment, no unbounded quality/dimension grid in production.

## Quality heuristic

64 stratified 8-pixel windows at actual output resolution, population luma block
SSIM (K1=.01, K2=.03, L=255), plus RGB PSNR over the same pixels. Tiny images use
smaller windows. This is not full-image Gaussian-window SSIM, a calibrated
perceptual model, face recognition or a universal visual guarantee.
See the [original SSIM research](https://www.cns.nyu.edu/~lcv/ssim/).

VIEW gates: block SSIM >=0.985 and RGB PSNR >=35 dB.
THUMB gates: >=0.960 and >=30 dB. The first prototype wrongly applied the VIEW
gate to thumbnails and inflated them. Intent-specific floors correct this without
changing source pixels. The thresholds are engineering policies subject to HUMAN
PASS, not objective claims that all viewers will perceive identical quality.
The reports compare nearby thresholds and a best fixed-quality guarded baseline.

Without GD measurement, the existing WordPress editor can use conservative Q94;
the decision says metric unavailable. This path needs Imagick-host validation;
GD is the actually certified backend. No scientific library ships at runtime.

## Storage and recovery

`files` contains served resources. `kind=derived` owns a new generation file;
`kind=master` references the existing source (quality null). Native image-size
metadata maps both kinds; no zero-byte file, symlink or source copy is created.
Added disk counts only derived files. MASTER ownership is never transferred.
History pruning skips master references. File-deletion checks independently
refuse the original path and non-generation filenames.

The manifest records only the selected decision, bounded candidate count,
dimensions, quality, bytes/gain, metric, backend and reason. No attempt archive.
Algorithm/floors/preset/backend/WordPress version enter the configuration hash.
Identical inputs reuse the verified result. MASTER hashing happens while processing,
not in frontend source getters; a source getter verifies canonical path, native
mapping and the current bounded JPEG header for dimensions/privacy. Generated
output getters retain hash verification. Changing source-reuse security policy
also changes the algorithm version to invalidate processing caches.

Legacy manifests remain readable. There is no activation migration or automatic
bulk regeneration. Explicit Balanced processing retires old output URLs safely.
Safety still depends on operators retaining backups and reviewing history before
pruning, especially external/static caches.

## Limits

JPEG RGB scope and memory/disk/ownership checks remain unchanged. Random RGB
noise can make JPEG quality gates impossible; safe source reuse is intentional
and can exceed 1 MB. Targets are soft, not destructive hard caps. Sampling can
miss localized artifacts: inspect full-resolution human examples before use.
0.3.1 adds bounded RGB ICC conversion through an Imagick/LittleCMS lossless
sRGB reference, with reuse disabled and metrics in that managed space. See
[color management](COLOR-MANAGEMENT.md); GD-only ICC inputs still fail closed.
No claim of broad production or 10,000-photo certification,
multisite, Linux permissions, MySQL or commercial-optimizer coexistence.
