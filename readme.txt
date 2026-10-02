=== WP Seed Pixel ===
Tags: images, jpeg, media, optimization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local JPEG derivatives, immutable masters and resumable administrator batches.

== Description ==

WP Seed Pixel adds owned JPEG derivatives to the existing WordPress media
library. It uses WP_Image_Editor, with no cloud or telemetry. Automatic processing
defaults to OFF. Manual and batch actions use the same pipeline.

Balanced intent is the new-install default: thumbnail 640, view target 1920,
bounded local quality/byte selection and metadata-safe source reuse. No AI,
cloud, external scoring or network is required. Legacy fixed/custom profiles
remain compatible. Upgrades do not regenerate the library or change settings.

Masters and native sizes remain. Disk use can increase. Re-encoding at higher
quality can increase transferred bytes; gains are never guaranteed.

Unprofiled RGB JPEGs only. ICC, CMYK, grayscale and other formats are skipped.
Local uploads only. No offloaded storage or network activation support.
This release is a Tech Preview with owner acceptance completed, not a universal
production certification. PHP 8.4.23 / GD / Windows / SQLite / WordPress 7.1.2
were tested. Other backends and platforms remain uncertified.

== Installation ==

1. Back up uploads and the database.
2. Upload the ZIP through Plugins > Add New and activate on one site.
3. Open Media > WP Seed Pixel; Balanced is recommended, automation is off.
4. Choose an image in the Media Library and use Optimize with WP Seed Pixel.
5. For several images, use the list view bulk action, then Resume the selection.

== Frequently Asked Questions ==

= Does it delete my master? =
No. WordPress may delete originals when the user deletes an attachment.

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

= 0.3.0 =
Human-readable media status, native attachment details in list and grid, selected
bulk processing, simplified settings and progress. Image engine unchanged.

= 0.2.0 =
Adaptive Balanced intent, independent bounded candidates, sampled quality gates,
safe master reuse, explicit decision statistics and legacy upgrade compatibility.

= 0.1.0 =
Initial local-only JPEG engine, presets, manual actions, automation, resumable
batches, ownership-safe cleanup, integrity validation and scoped administration.
