# Compatibility and release status

0.3.0 is a Tech Preview, published as a GitHub pre-release.
The accepted adaptive engine is unchanged from 0.2.0. Product owner acceptance
is complete; it does not certify every hosting environment.

Tested locally: WordPress 7.1.2, PHP 8.4.23, GD, EXIF, Windows, SQLite Database
Integration 3.0.2, and Chrome. Generated synthetic fixtures are used for public
test instructions and screenshots. Private review evidence is not distributed.

WordPress 6.6 and PHP 8.1 are declared API floors, not fully tested matrices.
Imagick, Linux, MySQL/MariaDB and multisite remain uncertified. Network activation
is refused. Deep coexistence with commercial optimizers needs an independent pilot.
English translatable strings are supplied; no French translation catalogue yet.
Accessibility checks are partial, not a WCAG or screen-reader certification.

Only ordinary unprofiled RGB JPEGs are processed. ICC, CMYK, grayscale, non-sRGB
and unsupported formats are preserved without changes. Local uploads are required.
Master preservation means storage can grow; transfer reduction is not guaranteed.
Bulk advances while the authenticated admin page remains open; after closing it,
the user must explicitly resume. At most two retries are available per failed item.

No AI runtime, cloud optimization, service quota, telemetry or external image
processing. No third-party component is bundled; WordPress, GD, Python and browser
test dependencies are supplied separately. License: GPL-2.0-or-later.
