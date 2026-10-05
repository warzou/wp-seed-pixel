# Pixel V1 - private candidate administration

## Setup and ordinary behavior

Activation/update does not change images or enable automation. The legacy web
version API and its engine generation stay compatible. Images remain native
WordPress attachments when Pixel is inactive/removed. Do not uninstall if you
still need Pixel recovery actions; owned recovery resources are not silently
erased. Use a tested host backup for full disaster recovery.

Storage processing requires an explicitly configured private recovery directory
outside all public roots, same-filesystem certified rename/fsync, InnoDB tables
and a DB session with advisory-lock authority. A file lock is not authority.
Define WP_SEED_PIXEL_STORAGE_ENABLED=true only after host prerequisites pass,
and WP_SEED_PIXEL_RECOVERY_ROOT to that private directory. Unknown ownership,
unsupported storage/multisite, incomplete capacity or third-party changes stop
destructive work. Never place recovery under uploads or claim quota from df.

## New uploads

Media > Pixel - New uploads: OFF, Analyze only, or Process eligible new images.
Choose JPEG and optionally bounded lossless PNG. Save establishes a fresh ID/UTC
baseline; only subsequent ordinary authenticated uploads with completed native
metadata can enroll. Imports/restores and preexisting IDs are protected. Existing
JPEG-only settings never become PNG-enabled on upgrade. Turning OFF does not
delete images, jobs or quarantine. Retry/resume existing jobs is explicit.

Storage fields use decimal MB; MiB is explicitly marked where used. A provider
quota is information, not measured usage. An operational ceiling needs a live,
complete trusted usage adapter including private recovery/DB/all account storage.
Unknown usage is UNKNOWN and blocks admission when the operational ceiling is
enabled. Disabling that optional ceiling does not certify provider quota/usage;
the explicit operation budget and physical free-space gates still apply.
Upload bytes already exist before Pixel starts; estimated peak includes staging,
escrow and reserves. A tiny quota
cannot be financed by deleting the source first.

## Existing media

Run read-only image storage analysis, then Storage saver. Choose existing media
in the native WordPress selector (up to 500), budget and operation. Build the
plan; inspect proposed eligible/excluded/review states before starting. No work
starts automatically from analysis. Requests process one image by default;
pause/resume/cancel retain completed results. Browser closure requires explicit
resume. Never regenerate unchanged work to chase savings.

## Recovery and real savings

Retained versions: original bytes still occupy hosting space. Restore verifies
the live original and current attachment before exact rollback. Delete permanently
requires a separate acknowledgment bound to one verified generation; thereafter
Restore is unavailable. The Media editor reflects live recovery availability,
not a historical promise. Active savings, quarantine, pending temporary state,
journal overhead, net file reclaim and physical deletion are distinct. Provider
quota/allocation remains unknown unless independently measured.

## State size and retention

Two lifecycle tables, bounded 32-event item journals and temporary files owned
by one operation. UI results are paged (20) and recent uploads limited to 20.
No unbounded debug log or automatic purge. New real storage jobs stop at 10,000
records; existing restore/purge still work. Historical scan/plan rows grow only
through explicit requests. Old terminal simulation pruning is separately explicit
and does not delete real recovery receipts. No supported automatic real-history
archiving in V1: retain evidence and obtain an explicit maintenance plan.
Shared-host processing stays one item/request; conservative memory/peak gates can
refuse large inputs. PNG limits and skips are described in PNG-LOSSLESS.md.

Conflict warnings do not disable ShortPixel or another optimizer. Resolve ownership
before co-processing. No external image transmission, telemetry or cloud account.
English and fr_FR are supported; keyboard/native focus and responsive UI are
checked, not a blanket WCAG or screen-reader certification.
