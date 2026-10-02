# WP Seed Pixel 0.3.0

Local JPEG derivatives for the existing WordPress media library. No cloud,
telemetry, frontend assets, additional media library or required runtime package.
The original master is never an output destination.

## Install

Upload `wp-seed-pixel-0.3.0.zip` through Plugins > Add New > Upload Plugin.
Activate on one site. Open Media > WP Seed Pixel. Automatic processing defaults
to OFF. Back up the database and uploads before a library-wide batch.

Choose an image in Media > Library and use **Optimize with WP Seed Pixel**.
Native attachment details in list and grid show status, benefit and optional
technical details. **Regenerate web versions** uses the preserved original.
For several images, use the list view bulk action, then **Resume** on the Pixel
page. A selection is capped at 1000 items; larger libraries can use several
selections or the separate explicitly confirmed whole-library action.
A batch processes one attachment per authenticated POST and can be paused,
resumed after reload, and retried twice per failed attachment. Keep the admin
page open to advance it; this is not a background worker daemon.
Image buttons and batch progress require JavaScript. Settings and the diagnostic
single-image fallback work without it. Numeric IDs are confined to developer
tools, not normal use. Frontend rendering does not require JavaScript.

## Presets

| Preset | Derivatives | JPEG quality |
| --- | --- | --- |
| balanced (new installation default) | THUMB 640; VIEW target 1920 | local quality/byte selection, source reuse |
| web (legacy/API default) | maximum 1600 x 1600, proportional | 82 |
| participant_album (example) | THUMB maximum 640; VIEW maximum 2048 | 80; 90 |

No cropping, enlargement, sharpening or aesthetic alteration. Preset dimensions
are upper bounds, not promised square outputs. Both derivatives come from the
master independently, never from a previous compressed derivative.

**Smaller transfer is not guaranteed.** A Q90 re-encode can be larger than an
already-compressed source. The result reports negative transfer savings honestly.
Disk use can increase because the master and native WordPress sizes remain.

The recommended `balanced` intent tries at most three independent qualities
per output, checks sampled local structure and RGB error, and retains a safe
MASTER when encoding has no material gain. It never exposes an original carrying
private metadata. A reused resource has `kind=master`, `quality=null` and adds
zero disk bytes; it is not a duplicate file. A source retained after quality
rejection can exceed the dimension/soft weight target. See [algorithm](docs/ADAPTIVE-ALGORITHM.md).
Existing settings and fixed/custom profiles stay unchanged on upgrade. Select
Balanced explicitly to regenerate existing images; there is no upgrade-wide batch.
The quality metric is a bounded heuristic, not a human visual certification.

## Safety and compatibility

- WordPress `WP_Image_Editor` selects its existing GD or Imagick backend.
- Ordinary, unprofiled three-channel RGB JPEGs are supported. EXIF rotation and
  mirrored orientations require PHP EXIF. Sensitive metadata is removed from
  outputs; the fixed technical GD/JPEG encoder comment can remain.
- ICC-profiled, declared non-sRGB/uncalibrated, CMYK and grayscale JPEGs are deliberately skipped. This release
  does not promise profile conversion or wide-gamut color fidelity.
- PNG, GIF (including animation), WebP, AVIF and SVG are skipped, not overwritten.
- Source files must be regular local files inside this site's uploads directory,
  without symlink components. Remote/offloaded/private external storage is not
  supported by this release; this plugin is not a private-album access-control system.
- Preflight limits: 40 megapixels, 64 MB source, bounded JPEG header, estimated
  decode memory and staging disk budget. Host resource failures remain possible.
- File locks and metadata compare-and-swap prevent two Pixel jobs and concurrent
  metadata overwrites. Foreign metadata filters cause a safe refusal.
- Other optimizers are neither disabled nor rewritten. Coexistence with each
  commercial optimizer requires a separate real-site validation.
- PHP minimum 8.1, WordPress minimum 6.6 are declared API floors, not an assertion
  that every combination was tested. See the delivered compatibility report.
- Network activation is refused. Per-site multisite behavior is not certified.

## Deactivation and removal

Deactivation stops automation and pauses a running batch; masters and outputs
remain. Default uninstall retains generated files and settings. Optional cleanup
removes only proven-owned, hash-matching, unreferenced derivatives and plugin
metadata. Shared or changed files are retained conservatively. WordPress itself
deletes its original when the user explicitly deletes an attachment.

Interrupted output publication leaves a journal. The next job for that attachment
recovers it under the lock. A crash does not replace the previous valid generation.
Previously published URLs remain stored, including after regeneration, to avoid
breaking static/cached HTML. A maximum of twenty previous generations is kept;
further regeneration stops until explicitly reviewed/pruned with the PHP API.
Disk growth includes these retained generations. Never prune before reviewing
external and cached uses; database reference checks cannot see a CDN cache.
Locks are persistent empty synchronization files, not active processes. Do not
manually remove locks while workers may be running.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [PHP API and hooks](docs/API.md)
- [Development and tests](docs/DEVELOPER.md)
- [Roadmap](docs/ROADMAP.md)
- [External album integration example](docs/INTEGRATION-EXAMPLE-PDE-ALBUMS.md)
- [Security](SECURITY.md)

Image-engine visual review passed for 0.2.0; its engine is unchanged. The 0.3.0
admin UX is subject to owner acceptance, not a production certification. Linux,
Imagick, MySQL/MariaDB and multisite remain uncertified. English source strings
are translatable; a French catalogue is not shipped yet.
