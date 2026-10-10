=== WP Seed Pixel ===
Tags: images, jpeg, png, media, optimization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local image optimization, storage analysis, verified recovery and resumable jobs.

== Description ==

Version 0.6.1 fixes verified physical master/display aliases. Metadata-only
operations admit verified master/display aliases and reconcile their integrity
witnesses using the existing transaction and restore lifecycle. Existing
private.1 jobs, policies, image generations and recovery remain compatible.

Version 0.6.0 removes recognized privacy metadata (GPS, device, author and date)
from the complete public JPEG/PNG graph without re-encoding image data. Pixels,
dimensions, transparency, ICC and required color declarations are preserved.
Safe new native uploads are anonymized automatically by default. Explicit OFF
is preserved on upgrade; existing images are not automatically processed.
Unsupported orientation, provenance, unknown metadata and insufficient recovery
storage require review without unsafe modification. WordPress editorial fields
stay unchanged. Exact originals remain privately recoverable, including their
original metadata; whole-graph restore is available. No automatic permanent purge.

0.5.0 adds optional PNG to JPEG
conversion requires local comparison and explicit administrator approval for
one image. It never converts uploads or bulk selections automatically. Exact
PNG recovery and old addresses remain until a separate confirmed deletion.

Web Q90, Good quality Q94 and Best quality Q98 are measured per image. Pixel
recommends the lightest available profile passing its quality guards, or none
when none passes. A safe, beneficial profile failing these guards requires
comparison, a clear warning and extra explicit server-enforced acknowledgement.

Safe private recovery is automatically prepared on
supported hosts; activation itself changes no image.
Future image optimization defaults OFF (separate from metadata privacy ON).
JPEG and bounded lossless PNG optimization are opt-in;
existing media require an explicit selected plan. Recovery occupies hosting space.
Permanent deletion requires separate per-version approval and removes rollback.
Provider quota remains unknown unless a complete live accounting adapter proves it.
Ordinary lossless PNG: 8-bit non-interlaced, <=4 megapixels / 16 MiB; stays PNG.
No automatic quarantine expiry. New storage operations stop at 10,000 records.
See repository docs/V1-USER-GUIDE.md and docs/PNG-LOSSLESS.md for setup and limits.

WP Seed Pixel adds owned JPEG derivatives to the existing WordPress media
library. It uses WP_Image_Editor, with no cloud or telemetry. Automatic processing
defaults to OFF. Manual and batch actions use the same pipeline.

Balanced intent is the new-install default: thumbnail 640, view target 1920,
bounded local quality/byte selection and metadata-safe source reuse. No AI,
cloud, external scoring or network is required. Legacy fixed/custom profiles
remain compatible. Upgrades do not regenerate the library or change settings.

Masters and native sizes remain. Disk use can increase. Re-encoding at higher
quality can increase transferred bytes; gains are never guaranteed.

Unprofiled RGB JPEGs and validated RGB ICC JPEGs with Imagick/LittleCMS.
ICC conversion uses a lossless sRGB working reference; MASTER stays byte-exact.
GD-only hosts skip ICC safely. CMYK, grayscale and other formats are skipped.
Local uploads only. No offloaded storage or network activation support.
This release is a Tech Preview with owner acceptance completed, not a universal
production certification. PHP 8.4.23 / GD / Windows / SQLite / WordPress 7.1.2
were tested. RGB ICC conversion was also tested with Imagick 3.8.1 / LittleCMS
on Windows. Other platforms and production hosting remain uncertified.

== Installation ==

1. Back up uploads and the database.
2. Upload the ZIP through Plugins > Add New and activate on one site.
3. Open Media > WP Seed Pixel; Balanced is recommended, automation is off.
4. Choose an image in the Media Library and use Optimize with WP Seed Pixel.
5. For several images, use the list view bulk action, then Resume the selection.

== Frequently Asked Questions ==

= Does it delete my master? =
Native replacement is explicit and verified. The recovery original remains
until separate permanent-deletion approval. No useful saving leaves the active
file unchanged and cleans only proven unnecessary pre-swap resources.

= Can a batch resume? =
Yes. Its cursor, active attachment and counters persist. Resume from the admin.
Each request handles one image. Failed items have at most two explicit retries.

= Does uninstall remove images? =
Not by default. Optional cleanup removes only proven-owned derivatives, never
originals. Shared or changed files are retained. Multisite uninstall is retained.

= Can it replace a private albums gateway? =
No. Authorization and private delivery remain the consuming site's responsibility.

= Does regeneration break previous URLs? =
Previously published generations are retained by default, up to twenty historical
generations. Further regeneration stops until a trusted operator confirms history
pruning through the PHP API. Review external/cached uses and back up first.

= Are internal manifests public? =
No. They use protected postmeta, separate from WordPress public media details.
WordPress still provides normal public derivative URLs for public attachments.

== Changelog ==

= 0.5.1 =
Repository-bound GitHub stable updates by default, explicit private override,
one validated release-assets redirect, bounded metadata and pre-install SHA/ZIP
verification. No image engine, profile, recovery or stored policy changes.
Existing 0.5.0 installations need verified ZIP replacement or their existing
private feed to bootstrap 0.5.1; subsequent stable updates use GitHub by default.

= 0.5.0 =
Explicit single-image PNG to JPEG comparison and conversion, with retained
originals and exact restore. Measured Q90/Q94/Q98 choices, lightest quality-passing
recommendation and separate acknowledgement for non-validated manual choices.
Provenance loss requires independent approval. No automatic format conversion.
Safely reconcile proven unstarted historical review claims without encoding.

= 0.4.0 =
Verified native JPEG and lossless PNG storage replacement, private recovery,
exact restore and explicitly confirmed permanent deletion. Persistent selected
jobs share database authority, lease/CAS fencing, crash reconciliation and
truthful active/recovery/metadata accounting. Protected old media and opt-in
future uploads. One normal admin page and one attachment panel, French UI,
safe recovery self-configuration and native private updater with manual ZIP
fallback. No useful saving now reclaims unnecessary pre-swap escrow without
encoding again; successful recovery remains intact. No media processing on
activation/update and no external image service. Multisite remains unsupported.

= 0.3.0 =
Human-readable media status, native attachment details in list and grid, selected
bulk processing, simplified settings and progress. Image engine unchanged.

= 0.2.0 =
Adaptive Balanced intent, independent bounded candidates, sampled quality gates,
safe master reuse, explicit decision statistics and legacy upgrade compatibility.

= 0.1.0 =
Initial local-only JPEG engine, presets, manual actions, automation, resumable
batches, ownership-safe cleanup, integrity validation and scoped administration.
