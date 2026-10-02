# Contributing

This is a local JPEG optimizer, not a cloud service or a private-media gateway.
Discuss storage, image support and compatibility changes before implementation.
Keep one pipeline and never overwrite the master or foreign WordPress sizes.

## Local validation

Use a disposable localhost WordPress installation only. Read
[development instructions](docs/DEVELOPER.md) for prerequisites and the ordered
test setup. PHP requires GD, EXIF, mbstring, mysqli and pdo_sqlite for that setup;
Python uses Pillow, with NumPy for adaptive research. Browser tests use Playwright
and installed Chrome. No dependency is installed into the plugin runtime.

Run PHP syntax checks, integration and adversarial tests, then relevant browser
scenarios. Inspect actual screenshots; DOM checks alone are not visual QA.
Never commit credentials, databases, licensed dependencies, private media,
generated reports or browser profiles. Fixtures must be generated and redistributable.

## Distribution

Run `python tools/package.py` from the repository root. The build uses an explicit
allowlist, sorted entries, fixed ZIP timestamps, 0755 directories and 0644 files.
It produces the installable ZIP and its SHA-256 in ignored `dist/`. Python/zlib
versions can affect compressed bytes; verify hashes rather than assuming a
cross-toolchain byte-identical build. Tests and internal reports are excluded.

Install the built ZIP through WordPress and test activation, media actions,
regeneration, selected bulk and deactivation/reactivation before a release.
Do not publish a source archive as the installable plugin.
