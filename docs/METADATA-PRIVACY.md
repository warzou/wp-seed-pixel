# Metadata Privacy: 0.6.0

The human-passed private.4 implementation is the release baseline. Deterministic
public-graph anonymization and exact recovery use the existing SQL authority and
Jobs lifecycle. New native upload privacy defaults ON, preserving explicit OFF;
existing media are not automatically processed. See METADATA-UPLOADS.md for the
exact initialization and upload boundaries. Prior private archives and reports
remain immutable historical evidence. No public bypass or automatic purge.

## Supported Filter

The deterministic filter constructs a separate candidate without changing the
source, invoking an encoder or changing WordPress editorial fields. A bounded
GD decode validates the candidate; GD is not used to rewrite it.

- JPEG: 8-bit grayscale/RGB baseline, extended sequential and progressive
  containers; marker lengths, bounded TIFF IFDs, recognized IPTC/XMP and comments.
- PNG: valid IHDR/PLTE/tRNS/contiguous IDAT/IEND, CRC, recognized text/eXIf,
  valid color declarations, bounded compressed metadata.
- Orientation absent/1 only. Orientations 2-8 refuse without normalization.
- ICC bytes, PNG color chunks, alpha, compressed JPEG scan data and PNG IDAT
  remain unchanged. Known EXIF resolution/ColorSpace declarations are rebuilt
  deterministically in a minimal TIFF rather than preserving private EXIF.
- Limits: 32 MiB/file, 16 megapixels, 4,096 container blocks, 2 MiB metadata,
  256 KiB TIFF/XML/profile, 1,024 TIFF entries/XML elements. Public inventory is
  capped at 256 files / 128 MiB with explicit memory checks.

Unknown APP/chunk/text keys, opaque EXIF technical dependencies, EXIF thumbnails,
unknown XMP namespaces and provenance (including APP11/caBX) require review.
Malformed lengths, CRC, duplicate structures, cycles and compressed metadata
bombs refuse. This is not universal metadata support or absolute anonymity:
unchanged ICC profiles may themselves contain descriptive data.

## Public Graph and Recovery

The graph comes from the existing ownership/inventory adapter, not guessed URLs.
Source reads compare fresh identity, permissions, size, mtime and ctime. They
ignore atime because reading a file can advance it on Windows without changing
its bytes. Snapshot SHA-256 and transaction authority checks remain mandatory.
Every mapped WordPress/Pixel public copy is checked against the snapshot hash.
Private.3 plans the entire mapped public graph. Already-clean files remain
byte-identical; supported dirty copies receive deterministic candidates and exact
private originals in one recovery bundle. Unsupported or ambiguous derivative,
original-size or compatibility files block the entire admission before promotion.
Offloaded, unknown, shared or incomplete ownership inventories are not certified.
WordPress GD derivative ICC loss is unchanged and out of scope.

Integration hooks reuse the existing single-image immutable job, authority,
lease, escrow, atomic replacement, witness and recovery adapter. They are
tested in a disposable real WordPress/MariaDB lab. There is no second recovery system.
Private recovery may retain metadata; public success must not imply it is clean.
Restore must reproduce the exact original hash; no destructive purge is run.
The existing witness persists public anonymization, categories, removed byte count
and graph signature/file counts. The anchored Quarantine bundle describes the
unchanged private recovery originals. The existing restore action represents
the operation. Bytes removed are not presented as compression. Restored/review
states use existing journals and live checks; blocked admission states derive
from analysis, without creating jobs or recovery copies to record a refusal.

## Operation Ordering

Anonymization is separate from optimization. Conserver preserves the existing
image-processing algorithms. Single-image admission now rejects duplicate active
jobs; previously persisted competing jobs still fail closed before execution.
Filtering encodes zero times. Same-format optimization remains
an explicit independent action. The actual existing PNG lossless processor is
tested in both orders and has identical scanlines and commuting filtered output.
JPEG optimization may encode once; metadata filtering must not add a second
lossy pass. Both orders run through actual WordPress jobs; the synthetic JPEG
fixture counts one actual editor save, not merely one committed receipt. Existing
JPEG quality probes are otherwise unchanged. Independent recovery domains are
serialized: restore the active operation exactly before starting another one.
No nested rollback support is claimed. Tests execute both orders with that
restoration between operations and verify one actual JPEG save/zero metadata saves.

Private.3 does not retain the private.2 nested PNG-to-JPEG exception. A retained
metadata bundle blocks conversion; an active conversion blocks anonymization.
Restoring an original private PNG does not make it conversion-admissible: the
existing unsupported-metadata guard still applies. An independently clean PNG
can use the unchanged explicit conversion workflow. Q90/Q94/Q98 and conversion
provenance disclosure/consent remain separate and unchanged.

## Final Certification Gate

1. Real disposable WordPress/MariaDB: snapshot, public graph, job creation,
   atomic switch, exact private recovery, failed-switch/crash resumes and restore.
2. Verify attachment title/alt/caption/description/author/ID/taxonomy invariance
   on that real transaction path and fresh shared/compatibility ownership cases.
3. JPEG optimization in both orders, PNG-to-JPEG interaction, all quality
   profiles, storage/claims/quarantine/recovery and native updater regressions.
4. Actual WordPress media modal/editor focus and final recovery controls; then
   two complete clean final cycles on the exact write-enabled candidate.

Final release results and exact complete-cycle counts belong in ignored
reports/release-0.6.0-20261009/REPORT.md. Parser tests alone are not sufficient.
All inputs are synthetic; permanent original deletion is excluded even from old
purge test branches. No PDE media, attachment 6354 or credentials are inputs.
No AskPass, site pilot or stable publication is authorized by this document.
