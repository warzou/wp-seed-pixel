# M1 read-only storage analyzer

Review candidate for the accepted Pixel 0.4 architecture, implemented on
2026-10-04. This is not a 0.4 release or a destructive storage manager.
Pixel/plugin version 0.3.2 and processing signature 0.3.1 remain unchanged.

## Boundary and entry point

Media > Pixel - Image storage opens a separate, administrator-only inventory.
Opening the page does not start or resume a scan. Analyze library starts an
attachment-driven quick scan; Pause, Resume and Cancel affect only scan state.
Browser closure stops requests. Resume is explicit, including after reload.
Transport errors remain visible; failed steps are not automatically retried.

`WP_Seed_Pixel_Analyzer::analyze($attachment_id)` is the read-only inspection API.
It requires `manage_options` and `edit_post` for the attachment. It uses existing
side-effect-free path/header/capability helpers, not the optimization pipeline.
It does not encode, resize, generate, replace, quarantine, purge or update any
canonical post, postmeta, media file, manifest or processing setting.

`WP_Seed_Pixel_Scan` persists only owned job/item data. The admin endpoint is
authenticated `wp_ajax_wp_seed_pixel_scan`, POST-only, capability- and
nonce-guarded. There is no anonymous action or public REST analysis report.
Reported paths are upload-relative, not absolute server paths. Thumbnail previews
use existing native images on the same home hostname, without URL credentials.

## Physical accounting

The graph distinguishes the operational attached file from the preserved
`original_image`, native intermediate sizes, WordPress edit backups and current
or previous Pixel manifest resources. A suffix such as `-scaled` is not enough
to establish a relationship. Native metadata is authoritative for that mapping.

A physical identity uses filesystem device/inode where exposed, otherwise the
resolved local path (case-normalized on Windows). Hardlinks and multiple roles
do not add the same file twice. Identical bytes in different independent files
are not deduplicated: both files occupy storage. Shared attachment relationships
are retained and marked for review; they are not deletion ownership evidence.

The scan's file ledger counts unique logical file lengths. Its disjoint role
priority is operational, preserved original, current Pixel, previous Pixel,
native WordPress sizes, edit backups, then unattributed related files. Priority
only selects an accounting bucket; all relationships remain in item evidence.
Per-attachment related bytes are explicitly non-additive across attachments.

Missing paths have zero measured bytes and a missing health state. Symlinks,
traversal and outside-upload paths are excluded rather than followed. Filtered
storage is flagged; remote objects are not downloaded or assigned guessed bytes.
Filesystem allocated blocks and hosting quota are unknown. Logical lengths are
not a promise of quota reduction, especially on compressed/sparse storage.
Database scan-state overhead, caches and unrelated files are not part of the
image-file subtotal; M1 does not label that subtotal as total hosting usage.

## Opportunities and uncertainty

Preserved-original bytes are measured conditional opportunities, not removable
bytes. Shared/hardlinked or unreadable ledger entries are excluded from that
aggregate. Oversized JPEG dimensions, possible JPEG recompression, PNG lossless
work and previous Pixel versions have review categories; compression savings
remain unmeasured. No estimated percentage or safe-to-delete authorization is
returned. Space reclaimed is always zero. Same-disk quarantine would still
occupy storage and is not implemented in M1.

JPEG/PNG dimensions and file headers are inspected without image encoding.
Other recognizable raster formats are inventoried but not eligible for processing.
ICC presence is unclassified, not a color-transform certification. PNG alpha and
animation inspection is bounded; unknown evidence remains unknown. GD, Imagick,
managed-RGB and EXIF capability facts do not authorize an operation.

Quick analysis only is available. Deep encoder experiments and immutable action
plans belong to later milestones and have no active or disguised M1 controls.

## Bounded scans and persistence

Two lazy-created site-prefix tables are owned by analysis:

- `seed_pixel_jobs`: scan status, cursor, starting attachment-ID ceiling, count,
  actor, creation time and lease token/expiry.
- `seed_pixel_items`: indexed attachment results and deduplicated file ledger,
  unique by job/kind/item identity. No whole-library JSON option is used.

The non-autoloaded `wp_seed_pixel_scan_schema` option records schema version 1.
Tables are prepared only on explicit Start, not on frontend requests or plugin
activation. Scan transactions and lease/CAS checkpoints protect each job's own
rows; they are not a filesystem transaction or an image-processing lock.
Concurrent starts in separate tabs are not a destructive coordinator contract.

Each step inspects one attachment. Results paginate at 20 items, with per-item
permission checks. Each graph is bounded to 256 unique files, 128 native sizes,
128 edit backups, 20 old Pixel generations and 32 resources per state. A sibling
sample examines at most 128 entries and never recursively crawls uploads. This
can find related-looking extras, not all unrelated/orphaned disk content.
Partial graph/sample evidence is disclosed, not presented as a full disk audit.

Results carry scan time, stat evidence and metadata revision. The page rechecks
visible results and marks changed graphs stale. It is a dated scan, not an atomic
whole-library snapshot. Equal-length byte changes with unchanged stat/header
evidence are not cryptographically detected by quick analysis. Hashing and stronger
reference/ownership fences are mandatory before any future destructive milestone.

Owned scan history is retained; it is not automatically purged. M1 adds no
uninstall-time deletion of images or analysis evidence.

## Tests and environment

Run only in a disposable local WordPress created with `tools/prepare-runtime.py`:

1. `tests/storage-install.php` initializes the empty guarded fixture database.
2. `tests/storage-analyzer.php` creates the synthetic graph, verifies exact
   accounting/invariance, then scans 2,008 relationships with bounded steps.
3. `tests/storage-edge.php` probes bounded metadata, hardlinks and stale results.
   `tests/storage-matrix.php` adds EXIF/ICC/GIF/malformed-header, filtered-storage,
   simulated subsite-root and Windows reparse-boundary probes. Its junction fixture
   is supplied and removed by the local test runner; PHP `is_link()` does not
   recognize that Windows junction, so resolved-path exclusion is verified instead.
4. `tests/storage-rescan.php` checks the latest candidate against full canonical
   rows, postmeta, file SHA-256, settings and existing processing state.
5. `tests/storage-browser.cjs` checks the real EN/FR admin headlessly, responsive
   states, zoom/keyboard, nonce denial and pause/resume/cancel/error behavior.
6. `tests/storage-regression.ps1` runs existing i18n, Adaptive and GD-only ICC
   regressions while restoring preexisting ignored report bytes.

Fixtures include ordinary JPEG, native original/scaled, PNG alpha, missing file,
unknown extra, actual Pixel 0.3 resources, shared path, hardlink and unsupported
MIME. Fixture setup and existing optimizer regression tests can create images;
the analyzer itself must not. Full before/after hashes establish that distinction.

Current evidence uses WordPress 7.1.2, PHP 8.4.23, Windows, SQLite integration 3.0.2,
GD and EXIF, plus isolated headless Chrome. See the ignored `reports/storage-m1`
evidence pack for exact counts and observed limitations. PHP 8.1, WordPress 6.6,
Linux, MySQL/MariaDB, a real multisite network, real offload and Imagick analysis
are not certified by this run. Existing derivative APIs, Albums integration code
and accepted artifacts are preserved; no consuming-site certification is implied.

STOP for Guillaume + ChatGPT review of M1 before M2.
