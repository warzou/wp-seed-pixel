# WP Seed Pixel 0.6.1

Version 0.6.1 fixes verified master/display aliases during metadata-only graph
analysis, anonymization and exact restoration. One physical file retains every
logical role; integrity witnesses reconcile through the existing SQL authority.
Conflicting ownership and ordinary JPEG replacement still fail closed. Clean
graphs create no needless job or encoding. Upgrading from 0.6.1-private.1 does
not change image generations, settings or existing recovery records.
See [physical aliases](docs/METADATA-PHYSICAL-ALIASES.md).

Version 0.6.0 adds current-file metadata states and automatic privacy filtering for
new native uploads. The setting defaults to ON on first administrator activation
or upgrade initialization; an explicit OFF is preserved. Existing media are not
automatically enrolled. Unsupported uploads remain valid and require review.
Exact originals are retained privately; no automatic purge is introduced.
See `docs/METADATA-UPLOADS.md`.

Metadata anonymization removes recognized GPS, device, author and date/privacy
metadata from the complete public graph without re-encoding JPEG scans or PNG
IDAT. Pixels, dimensions, transparency, ICC and required color declarations are
preserved. Unsupported orientation, provenance and unknown metadata fail closed.
Attachment identity and WordPress editorial fields remain unchanged. Exact
private originals may still contain their original metadata; restoration returns
the complete pre-anonymization graph. Permanent purge is never automatic.
See [metadata scope and certification gates](docs/METADATA-PRIVACY.md).

Local WordPress image optimization and storage management. Images never leave
the host: no external image service, telemetry, account or AI runtime.
Version 0.5.1 adds the verified GitHub stable update channel without changing
image optimization, stored policies or recovery behavior.

Version 0.5.0 adds optional, single-image PNG to JPEG
conversion in the existing attachment panel. Analyze prepares a private JPEG
comparison; only explicit approval changes the attachment. Provenance loss
requires a separate acknowledgement. Original PNG URLs and private recovery
remain until a separately confirmed purge. No automatic upload or bulk format
conversion is added. The frozen 0.4.0 artifact is not replaced.
See [conversion scope and safety](docs/PNG-JPEG-CONVERSION.md).

Choose Web Q90, Good quality Q94 or Best quality Q98 after measured comparison.
Pixel recommends the lightest available profile passing its quality guards;
if none passes, it selects none automatically. A technically safe, beneficial
profile failing quality guards needs a clear warning, comparison and an extra
explicit acknowledgement enforced by the server. Metrics are not human approval.

## Install and Update

Back up the database, uploads and installed plugin before deployment. Install
the approved runtime ZIP through Plugins > Add New > Upload Plugin. Confirm
replacement when updating; do not uninstall to update. Activation and upgrade
do not process existing media or enable a new format.

Version 0.5.1 defaults to the repository-bound GitHub stable manifest at
https://raw.githubusercontent.com/warzou/wp-seed-pixel/main/updates/stable.json.
An explicit WP_SEED_PIXEL_UPDATE_MANIFEST constant overrides this source;
an empty or invalid override disables updates without falling back.
The updater validates version,
compatibility, archive identity and SHA-256 before WordPress replaces runtime
files, including one explicitly validated GitHub release-asset redirect.
Network or integrity failure refuses the update. The published 0.5.0 requires
verified manual ZIP replacement or its existing private endpoint to bootstrap
0.5.1; it cannot consume the new stable feed itself.
See [updates and release gates](docs/PRIVATE-UPDATES.md).

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

Ordinary optimization keeps JPEG as JPEG and lossless PNG as PNG. Optional
explicit PNG to JPEG conversion is a separate action. No WebP/AVIF conversion.
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
No endpoint publication or consumer deployment is implied by a local build.
M1-M6 describe developer certification only, not normal admin
steps. PHP >= 8.1 and WordPress >= 6.6 are API floors, not universal host claims.
