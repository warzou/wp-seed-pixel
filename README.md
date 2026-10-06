# WP Seed Pixel 0.4.0

Local WordPress image optimization and storage management. Images never leave
the host: no external image service, telemetry, account or AI runtime.
The release candidate remains private until the owner publishes it.

## Install and Update

Back up the database, uploads and installed plugin before deployment. Install
the approved runtime ZIP through Plugins > Add New > Upload Plugin. Confirm
replacement when updating; do not uninstall to update. Activation and upgrade
do not process existing media or enable a new format.

An owner-approved HTTPS distribution endpoint enables the native Plugins
update row. There is no default endpoint. The updater validates version,
compatibility, archive identity and SHA-256 before WordPress replaces runtime
files. Network or integrity failure refuses the update. Manual approved ZIP
installation is the fallback. See [private updates](docs/PRIVATE-UPDATES.md).

## Ordinary Administration

Open Media > WP Seed Pixel. One page contains:
- Automatic optimization: independent JPEG and lossless PNG choices.
- Existing images: the native media selector and explicit selected-count action.
- Recent activity: durable status and progress, with pause/resume where relevant.

Saving automatic settings does not process old attachments. Future uploads are
protected by a fresh enrollment baseline. Existing settings and JPEG-only
choices remain unchanged on upgrade. The selected workflow is limited to the
chosen attachments (maximum 500), not the whole library. Whole-library
processing and storage diagnostics are advanced, explicit actions.

In the Media Library, choose Optimize this image. An unprocessed image shows
format, dimensions and bytes. Successful native replacement shows Original,
Optimized version and active-image saving. An original retained for restoration
still occupies space: active savings are not permanent disk reclaim.

Restore original verifies the current generation and restores its exact bytes.
Permanently delete original requires separate, irreversible, per-generation
confirmation. Recovery remains until that explicit action; no automatic expiry.
For a verified pre-swap No useful saving outcome, there is nothing to restore.
Pixel automatically removes unnecessary private escrow/candidate resources.
The active image and native derivatives remain unchanged. Small bounded
checksummed audit metadata remains and is accounted for.

Technical details are collapsed. English and French are supported. Native
buttons, focus and reflow are tested; this is not a blanket accessibility
certification. Frontend media do not require Pixel JavaScript.

## Image and Host Safety

JPEG remains JPEG; PNG remains PNG. No ordinary WebP/AVIF conversion.
- JPEG candidates use bounded local quality and byte gates, no enlargement,
  arbitrary crop or aesthetic editing. The metric is not human quality approval.
- Lossless PNG supports bounded 8-bit non-interlaced inputs, up to 4 megapixels
  and 16 MiB. Decoded pixels, depth and color semantics are verified. Animation,
  unsupported chunks/profiles/depth and unsafe inputs fail closed.
- Legacy derivative APIs, Balanced/fixed profiles and their generation identity
  remain compatible. See [adaptive strategy](docs/ADAPTIVE-ALGORITHM.md).
- Native storage replacement needs supported single-site Linux storage,
  InnoDB and database-session advisory-lock authority, verified rename/fsync,
  ownership, generation and physical-space guards. File flock is not authority.
- Pixel prepares an owned private recovery directory outside verified public
  roots where safe. No mandatory FTP path or manual MiB budget is needed.
  Explicit host configuration wins; uncertain roots, links, shared paths,
  identity changes, third-party changes or incomplete inventories refuse work.
- The optional advanced operational ceiling requires complete trusted live
  usage. An unset ceiling does not disable otherwise safe operation. Unknown
  provider quota remains unknown; physical/per-operation peak gates still apply.
- Required recovery is never cleaned as pre-swap escrow. Ambiguous crash states
  retain bytes for review. Terminal pre-swap reconciliation is resumable under
  the same DB authority and lease/CAS fences, without encoding.
- Offloaded storage and multisite/network activation are not certified.
  Coexistence with other optimizers requires a site-specific audit.

## Deactivation and Uninstall

Deactivation stops automation; optimized files remain native WordPress media.
Recovery and job state remain. Uninstall never silently deletes recovery
originals. Optional legacy derivative cleanup is restricted to proven-owned
outputs and does not override recovery safety. Reinstall the approved runtime
to use recovery actions. A host backup remains necessary for disaster recovery.

## Documentation and Development

[Administrator guide](docs/V1-USER-GUIDE.md),
[PNG limits](docs/PNG-LOSSLESS.md),
[host hardening](docs/STORAGE-HOST-HARDENING.md),
[compatibility](docs/COMPATIBILITY.md).

Runtime packaging uses tools/private-release.py into a fresh ignored directory.
It excludes tests, tools, labs, screenshots, reports, credentials and media.
No distribution endpoint, public publication or consumer deployment is implied
by a local build. M1-M6 describe developer certification only, not normal admin
steps. PHP >= 8.1 and WordPress >= 6.6 are API floors, not universal host claims.
