# Compatibility and release status

## Private 0.4.0 V1 candidate

The public 0.3.2 artifact is unchanged. The private candidate keeps the 0.3.1
JPEG derivative engine and adds the accepted native-master job lifecycle.
Local V1 certification uses WordPress 7.1.2, PHP 8.5.4, GD/Imagick and MariaDB
11.8.6 in an owned disposable Linux environment. English and complete fr_FR
catalogues are included. Isolated headless Chrome covers ordinary admin UI,
keyboard focus, responsive layouts and invalid-nonce rejection. These checks
are not a full WCAG or assistive-technology certification.

The unchanged M5.1 DB authority protocol has separate accepted MariaDB 11.8.6
and MySQL 8.4.11 certification, with real NFS authority tests. V1 is not a new
full hosting/PHP/database matrix. PHP 8.1 and WordPress 6.6 remain declared API
floors. PHP 8.5 emits non-fatal deprecation notices from inherited GD test and
legacy code; the bounded PNG encoder does not depend on `imagedestroy()`.

The single real future-upload JPEG lifecycle on TherapsyCorporel (4395) was
accepted by Guillaume and completed through guarded permanent purge. No other
real image or PDE media was processed for V1. Unattended real-site processing
remains disabled. Native/API Media Library checks passed on that host; a
privileged browser visit to its admin editor was not independently certified.

PNG support is bounded to non-interlaced 8-bit RGB, RGBA, gray, gray-alpha and
indexed files, at most 4,194,304 pixels and 16 MiB. Validated profiles and all
non-IDAT chunks are preserved byte-for-byte. Animation, 16-bit/interlaced PNG,
ambiguous profiles, compressed text and oversized files are refused for review.
No PNG-to-JPEG, WebP or AVIF conversion is implemented. See PNG-LOSSLESS.md.

Future-upload processing is explicit opt-in, JPEG-only by default. Selected
existing-media processing requires explicit selection in the native Media
Library picker. Third-party optimizer warnings remain; Pixel never silently
disables another optimizer. Multisite network activation is still refused.

## Historical certification notes

0.3.0 is a Tech Preview, published as a GitHub pre-release.
The accepted adaptive engine is unchanged from 0.2.0. Product owner acceptance
is complete; it does not certify every hosting environment.

Tested locally: WordPress 7.1.2, PHP 8.4.23, GD, EXIF, Windows, SQLite Database
Integration 3.0.2, and Chrome. Generated synthetic fixtures are used for public
test instructions and screenshots. Private review evidence is not distributed.

WordPress 6.6 and PHP 8.1 are declared API floors, not fully tested matrices.
RGB ICC conversion is additionally tested locally with Imagick 3.8.1,
ImageMagick 7.1.1-46 and LittleCMS on Windows. Linux, MySQL/MariaDB and multisite
remain uncertified. Network activation
is refused. Deep coexistence with commercial optimizers needs an independent pilot.
English translatable strings are supplied; no French translation catalogue yet.
Accessibility checks are partial, not a WCAG or screen-reader certification.

0.3.1 is an unpublished patch candidate. Ordinary unprofiled RGB JPEGs still
use the existing pipeline. Validated RGB ICC JPEGs require Imagick/LittleCMS;
GD-only ICC, malformed profiles, CMYK, grayscale, unprofiled non-sRGB declarations
and unsupported formats are preserved without changes. Local uploads are required.
Master preservation means storage can grow; transfer reduction is not guaranteed.
Bulk advances while the authenticated admin page remains open; after closing it,
the user must explicitly resume. At most two retries are available per failed item.

No AI runtime, cloud optimization, service quota, telemetry or external image
processing. No third-party component is bundled; WordPress, GD, Python and browser
test dependencies are supplied separately. License: GPL-2.0-or-later.
