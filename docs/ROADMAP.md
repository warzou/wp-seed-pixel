# Roadmap

## Before deployment

Human benchmark review, GD/Imagick comparison, real MySQL/MariaDB and Linux
filesystem/permission tests, host memory limits and selected optimizer coexistence.
No release publication is authorized by this candidate delivery.

## Next releases

1. Profile-aware JPEG/ICC and CMYK handling with reviewed reference profiles and
   verified backend behavior; preserve correct color before broadening support.
2. Optional rule to skip re-encodes that increase transfer bytes, with explicit
   orientation/metadata semantics rather than copying sensitive originals.
3. WP-CLI commands and server-driven queue with cancellation, stale job recovery
   and retention independent from an open browser.
4. Offloaded/private storage adapter with a contract owned by the consuming site.
5. Per-site multisite certification and bounded network orchestration.
6. PNG lossless, optional WebP/AVIF only after animation/transparency/color tests.
7. More accessible summarized progress/error controls, translations and optimizer
   compatibility matrix. Never embed PDE-specific paths or album auth in core.

Deferred intentionally: cloud services, automatic artistic corrections, a media
catalogue, mandatory external binaries and automatic replacement of other plugins.
