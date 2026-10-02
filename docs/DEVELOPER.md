# Development

Runtime: WordPress >=6.6, PHP >=8.1, JPEG-capable GD or Imagick. EXIF is required
for sources carrying EXIF. No Composer or Node dependency in the installed plugin.
The development suite additionally uses Python/Pillow and Playwright/Chrome.

Adaptive R&D also uses NumPy in development only. `tools/adaptive-research.py`
generates redistributable synthetic research and independent holdout fixtures.
`tools/adaptive-benchmark.py` executes the actual PHP plugin and compares guarded
fixed baselines. It requires explicit private-source and output paths; private
photos/results never enter source Git or the ZIP. Run `tests/adaptive.php` with
outbound socket functions disabled as well as WordPress HTTP blocked.

## Isolated tests

Never run the suite against a live site. Download official WordPress and the
official SQLite Database Integration test dependency into `.runtime/wordpress.zip`
and `.runtime/sqlite.zip`. Run `tools/prepare-runtime.py`, `tests/install.php`,
`tests/make-fixtures.py`, `tests/integration.php`, `tests/adversarial.php` and
`tests/visual-assertions.py <portable-php>`. Enable GD, EXIF, mbstring, mysqli and
pdo_sqlite only for the test PHP process. Do not change host PHP configuration.

The tests verify their disposable localhost root before any mutation. Outbound
WordPress HTTP and mail are blocked. SQLite validates real WordPress integration,
not MySQL engine behavior. No Docker daemon was available in the initial run.

Serve the disposable root on 127.0.0.1:8877 for `tests/browser.cjs`.
Set `PIXEL_QA_PHP` and an external `NODE_PATH` to existing tooling. Auth cookies
are created and consumed only in memory. Do not publish screenshots containing
credentials. Close test processes and delete the disposable runtime afterward.

`tools/package.py` creates a deterministic allowlisted archive. Tests, tools,
reports, fixtures, local databases and dependencies never enter the ZIP.
`tests/lifecycle.php` exercises activation/deactivation/removal on the extracted
final ZIP. Reports record exact tested versions and skipped environments.

## Maintainer rules

Keep one image pipeline. Never overwrite `original_image`, attached file or
foreign native sizes. Do not add a second media library or global output filters.
When expanding supported colors, demonstrate visual/ICC correctness on each
backend first. Do not assume that EXIF metadata rotation implies pixel rotation.
Test crashes before and after publication, locks, shared file references and
metadata races for any storage change.
