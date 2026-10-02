=== WP Seed Pixel ===
Contributors: wpseed
Tags: images, jpeg, media, optimization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local JPEG derivatives, immutable masters and resumable administrator batches.

== Description ==

WP Seed Pixel adds owned JPEG derivatives to the existing WordPress media
library. It uses WP_Image_Editor, with no cloud or telemetry. Automatic processing
defaults to OFF. Manual and batch actions use the same pipeline.

Generic web preset: maximum 1600 pixels, quality 82. Optional participant_album
example: maximum 640 / Q80 and 2048 / Q90. No upscaling or cropping.

Masters and native sizes remain. Disk use increases. Re-encoding at higher
quality can increase transferred bytes; gains are never guaranteed.

Unprofiled RGB JPEGs only. ICC, CMYK, grayscale and other formats are skipped.
Local uploads only. No offloaded storage or network activation support.
This is a human-review candidate, not a production certification.

== Installation ==

1. Back up uploads and the database.
2. Upload the ZIP through Plugins > Add New and activate on one site.
3. Open Media > WP Seed Pixel, choose a preset and test a single attachment.
4. Confirm explicitly before starting the current-library batch.

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

= 0.1.0 =
Initial local-only JPEG engine, presets, manual actions, automation, resumable
batches, ownership-safe cleanup, integrity validation and scoped administration.
