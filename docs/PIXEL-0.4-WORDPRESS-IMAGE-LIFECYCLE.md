# Pixel 0.4.0: WordPress image lifecycle research

Research date: 2026-10-04. Architecture evidence, NOT runtime certification.
Decisions: [architecture](PIXEL-0.4-ARCHITECTURE.md) and
[ADRs](adr/PIXEL-0.4-DECISIONS.md). Tests still required:
[roadmap](PIXEL-0.4-ROADMAP.md).

## Evidence scope and version discipline

Read the official WordPress image implementation at tags
[6.6](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-admin/includes/image.php)
and [7.1.2](https://raw.githubusercontent.com/WordPress/WordPress/7.1.2/wp-admin/includes/image.php).
The latter version is independently identified by its
[version file](https://raw.githubusercontent.com/WordPress/WordPress/7.1.2/wp-includes/version.php).
The live master version file read during research identifies 7.2-alpha-64082;
trunk is not a release compatibility claim. Public Code Reference can move with
core development. Pin source versions again when implementing and testing.
[Live version source](https://raw.githubusercontent.com/WordPress/WordPress/master/wp-includes/version.php).

The declared Pixel floor remains WordPress 6.6 / PHP 8.1. Reading those sources
does not prove the full minimum-version/backend/OS/database matrix. No WordPress
site, private media or local experiment was needed for this research.

## Attachment file graph

One attachment post is not necessarily one physical file. Distinguish:

| Role | Native mapping | Pixel interpretation |
| --- | --- | --- |
| Operational full image | `_wp_attached_file`; metadata `file`, `width`, `height`, `filesize` | Current normal WordPress full URL; primary same-path replacement target. |
| Uploaded original | Metadata `original_image`, when present | Separate source, often retained after scaling; never infer ownership from a suffix alone. |
| Intermediate sizes | Metadata `sizes` entries | Existing published resources/crops; preserve healthy entries and files. |
| Image edit backups | `_wp_attachment_backup_sizes` | Editing/restoration state, not an ordinary purge opportunity. |
| Pixel resources | Protected manifest/history, including source reuse | Plugin-owned generation or alias; enumerate physical aliases exactly once. |
| Other companions | Version/plugin-specific metadata/files | Unknown ownership is REVIEW, not permission to delete. |

Do not infer all of these from filename patterns. The replacement helper updates
the attached file and dimensions and records the former basename in
`original_image`. Attachment URLs normally derive from the attached file and
uploads configuration; GUID is a fallback, not a field to rewrite routinely.
[Replacement helper](https://developer.wordpress.org/reference/functions/_wp_image_meta_replace_original/),
[attachment URL](https://developer.wordpress.org/reference/functions/wp_get_attachment_url/).

Resolve actual configured storage rather than assuming a fixed uploads path.
Filtered/custom paths and multisite require eligibility checks; discovery is not
automatic destructive support.
[Uploads API](https://developer.wordpress.org/reference/functions/wp_upload_dir/).

## Upload, scaling, conversion and orientation

Core image creation can choose a scaled/rotated operational image and generate
registered sizes. The default big-image threshold is 2560 and is filterable.
Metadata is saved incrementally, including during size generation. Consequently
this routine is not a side-effect-free candidate preparation API.
[Creation routine](https://developer.wordpress.org/reference/functions/wp_create_image_subsizes/).

Version-specific behavior matters: 6.6 excludes PNG from its large-image scaling
branch; the inspected 7.1.2 implementation has different conversion/scaling flow.
Do not assume every attachment with `original_image` is a JPEG `-scaled` pair.
HEIC-to-JPEG conversion was introduced with backend-dependent support in 6.7.
Pixel must report converted source companions separately and must not inherit
permission to convert formats merely because core can do so.
[6.6 implementation](https://raw.githubusercontent.com/WordPress/WordPress/6.6/wp-admin/includes/image.php),
[7.1.2 implementation](https://raw.githubusercontent.com/WordPress/WordPress/7.1.2/wp-admin/includes/image.php),
[HEIC announcement](https://make.wordpress.org/core/2024/08/15/automatic-conversion-of-heic-images-to-jpeg-in-wordpress-6-7/).

Design consequence: verify the current file graph, decoded dimensions, orientation,
MIME and metadata before selecting any source. Rotation, edited names and unknown
companions are not eligible for destructive MVP processing. No crop or upscale.

## Original-image lookup and regeneration

`wp_get_original_image_path()` uses the retained original when identified;
otherwise it falls back to the attached file. Its filter can redirect the result.
`wp_update_image_subsizes()` uses that original lookup for generation. Removing
`original_image` changes the source available to later regeneration, not just the
accounting displayed by Pixel.
[Original lookup](https://developer.wordpress.org/reference/functions/wp_get_original_image_path/),
[size update](https://developer.wordpress.org/reference/functions/wp_update_image_subsizes/).

Missing-size detection compares registered names with metadata; it is not proof
that every mapped file exists or has the expected dimensions. Sizes exceeding
source dimensions are excluded and existing keys need not be rebuilt when their
registered dimensions change.
[Missing-size logic](https://developer.wordpress.org/reference/functions/wp_get_missing_image_subsizes/).

Design consequence: separately verify mapped files. Do not blindly replace all
metadata or shrink below currently required retained WP/Pixel sizes. After original
retirement, future sizes can use only retained resolution; a larger new theme
requirement needs an external original or a lower-resolution result, never invented
pixels. Core-specific source lookup must be tested after disabling Pixel as well.

## Delivery contracts: full, sizes, srcset, REST and content

Responsive candidate selection uses attachment metadata, image URL matching,
aspect ratios and edit-version safeguards. The normal maximum srcset width is
filterable; it is not a universal required master dimension. Animated GIF handling
illustrates why a generic re-encode must not flatten animation.
[Srcset calculation](https://developer.wordpress.org/reference/functions/wp_calculate_image_srcset/),
[attachment srcset entry point](https://developer.wordpress.org/reference/functions/wp_get_attachment_image_srcset/).

The attachments REST response derives source URL and size details from native
attachment/image APIs. Pixel must preserve their ordinary meaning rather than add
a proprietary delivery dependency.
[REST preparation](https://developer.wordpress.org/reference/classes/wp_rest_attachments_controller/prepare_item_for_response/).

Design consequence: same-format replacement at the same operational path preserves
existing full-image URLs and embedded links without bulk content rewrites. It does
NOT preserve a distinct uploaded-original URL once that original is retired.
Scan known content, Divi payloads, custom fields, CSS and aliases for such references;
block known live use. Unknown external consumers require a specific owner warning.
Cached bytes, HTML dimensions and srcset still need a separate cache/delivery gate.

## Editing, restoration and attachment deletion

Image editing maintains backup-size metadata and can create timestamped edited
filenames. `IMAGE_EDIT_OVERWRITE` influences behavior. Core restoration consumes
those edit backups, not merely `original_image`.
[Image save](https://developer.wordpress.org/reference/functions/wp_save_image/),
[image restore](https://developer.wordpress.org/reference/functions/wp_restore_image/).

Attachment deletion knows native intermediates, original/edit backup files and the
main file. Current reference also mentions a source-image companion not found in
the inspected 7.1.2 image creation file; this is a version-drift warning, not a
license for Pixel to remove arbitrary companions.
[Native file deletion](https://developer.wordpress.org/reference/functions/wp_delete_attachment_files/).

Design consequence: edited backups are excluded from destructive MVP eligibility.
After a verified ordinary replacement, test crop, rotate, save and restore on
synthetic fixtures, including Pixel disabled. Restore cannot recover purged
high-resolution data. Normal attachment deletion cancels its pending Pixel work;
Pixel must not resurrect the attachment. Retained private recovery gets an explicit
detached-record lifecycle rather than an accidental public upload.

## Encoding, profiles and metadata are different contracts

Core GD and Imagick saving are distinct implementations. Imagick's stripping helper
protects selected profiles; that does not prove arbitrary metadata survives a GD
round-trip or that a backend preserves all bit depths and alpha values.
[GD save](https://developer.wordpress.org/reference/classes/wp_image_editor_gd/_save/),
[Imagick save](https://developer.wordpress.org/reference/classes/wp_image_editor_imagick/_save/),
[Imagick profile treatment](https://developer.wordpress.org/reference/classes/wp_image_editor_imagick/strip_meta/).

Assigning a colorspace/profile is not equivalent to correctly converting colors.
Profile operations and LittleCMS capability must be verified. GD PNG compression
levels control encoding, not JPEG-style perceptual quality.
[ImageMagick color management](https://imagemagick.org/color-management/),
[Imagick profiles](https://www.php.net/manual/en/imagick.profileimage.php),
[GD PNG encoding](https://www.php.net/manual/en/function.imagepng.php).

Design consequence: preserve required master rights/color/orientation metadata;
skip unsupported round-trips. Existing derivative privacy stripping is a separate
policy. PNG lossless acceptance requires decoded pixel, alpha, depth, color and
required metadata equivalence, not only an encoder flag. Lossy PNG conversion and
non-RGB JPEG expansion are deferred.

## Persistence and filesystem evidence

WordPress documents custom-table schema/version management and per-site prefixes.
This supports a bounded job/item ledger; it does not automatically provide a
transaction spanning filesystem and metadata operations.
[Plugin tables](https://developer.wordpress.org/plugins/creating-tables-with-plugins/).

PHP rename can replace a writable destination but wrapper/platform restrictions
apply. Linux rename provides same-filesystem replacement semantics; cross-filesystem
moves are not the same guarantee. File flush is not complete database/filesystem
power-loss certification. Free filesystem space is not an account-quota query.
[PHP rename](https://www.php.net/manual/en/function.rename.php),
[Linux rename](https://man7.org/linux/man-pages/man2/rename.2.html),
[PHP fsync](https://www.php.net/manual/en/function.fsync.php),
[PHP disk space](https://www.php.net/manual/en/function.disk-free-space.php).

Design consequence: persist intent before effects; validate same-filesystem swap,
file hashes, metadata CAS, private recovery and crash reconciliation. Unsupported
storage stays analyzer-only. Never implement replacement as delete-live then copy.

WP-Cron is request-triggered; queued work is not proof of a continuously running
daemon. Action Scheduler is a possible queue framework, not a substitute for
Pixel's replacement recovery protocol; it is not a new MVP dependency.
[WP-Cron](https://developer.wordpress.org/plugins/cron/),
[Action Scheduler](https://actionscheduler.org/).

## Lifecycle cases to certify, not already passed

| Fixture | Required observation before replacement/purge can be enabled |
| --- | --- |
| Ordinary JPEG | Same path/MIME, correct metadata, decoded quality, healthy native sizes and Pixel getters. |
| JPEG with separate original | Independent replacement and retirement plans; original lookup fallback works after exact metadata transition. |
| Live reference to original URL | Retirement blocked; no hidden global rewrite. |
| Missing original or operational image | Clear REVIEW result, zero repair/deletion by optimization. |
| Edited/rotated backups | REVIEW, no destructive processing; normal core behavior preserved. |
| New registered size | Source choice and retained-resolution limit explicit; no upscaling. |
| Shared path / filtered offload | Ownership uncertain: no destructive eligibility. |
| RGB ICC / metadata-heavy master | Managed color and required metadata round-trip, or safe skip. |
| PNG alpha/16-bit/APNG | Exact bounded support or reasoned skip, never silent flattening. |
| Pixel deactivated/uninstalled | Ordinary WP display, REST, srcset, editing and generation remain usable. |
| Crash/race/low disk | Recover or stop deterministically without overwriting later edits. |

No unresolved lifecycle design dependency blocks the architecture. Backend,
filesystem, crash and hosting behavior remain explicit implementation gates,
not claims of tests performed in this documentation task.
