# Pixel 0.4.0: implementation roadmap and first-milestone proposal

2026-10-05 current gate: M1-M5 are accepted. M5.1 adds DB ownership, operational
ceiling and controlled future uploads; see [host hardening](STORAGE-HOST-HARDENING.md).
The initial milestone notes below are historical. Owner-authorized V1 completion
implements bounded M6 PNG and M7 UX on the accepted coordinator. Final V1 checks
and private packaging are recorded separately in reports/storage-v1. No public
release or additional real-site deployment follows from this roadmap.

V1 completion: M6/M7 and ten bounded review passes are complete. Two independent
clean local cycles pass 1,000 checks each with unchanged runtime fingerprints.
The private 0.4.0 runtime-only package is frozen; minimum-version/full hosting
matrix and public release are not newly certified. Next owner gate is the deferred
PDE DEV attachment 6354 eligibility/status-label test, not an automatic deployment.

Date: 2026-10-04. Architecture, M1 and M2 accepted by Guillaume + ChatGPT.
M2's accepted simulation infrastructure is documented in
[storage jobs](STORAGE-JOBS.md); M1 remains in [storage analyzer](STORAGE-ANALYZER.md).
M3 is a local single-JPEG replacement review candidate; see
[master replacement](MASTER-REPLACEMENT.md) and ignored reports/storage-m3.
M4 is a local quarantine/restore/purge review candidate, documented in
[quarantine lifecycle](QUARANTINE-LIFECYCLE.md). M5-M8 remain gated and have not
started. Source of truth: [architecture](PIXEL-0.4-ARCHITECTURE.md),
[lifecycle research](PIXEL-0.4-WORDPRESS-IMAGE-LIFECYCLE.md),
[competitor research](PIXEL-0.4-COMPETITOR-RESEARCH.md),
[decisions](adr/PIXEL-0.4-DECISIONS.md).

## Scope, defaults and gates

M2 implementation gate: normalized/frozen policy and plans, schema-2 shared tables,
one-item simulated runner, fenced shared locks, pause/resume/cancel/retry, bounded
error journal and deterministic request/process recovery pass local synthetic QA.
M1/0.3.2 regression and EN/FR headless coverage pass. No destructive executor,
canonical media write, version bump, package or consumer deployment is enabled.
M2 was subsequently human accepted and M3 explicitly authorized. Later M5 will
extend this coordinator with real storage operations/adapters rather than invent
a separate queue. See STORAGE-JOBS.md and ignored reports/storage-m2 for limits.

0.4 MVP: storage analysis; independent policy; verified local same-path JPEG
master replacement; safe separate original retirement; private recovery and
explicit purge; resumable jobs; bounded lossless PNG; native admin accounting.
Automatic replacement and purge stay OFF. Ordinary healthy WP sizes stay intact.

Deferred: format conversion/delivery, mandatory cloud, specialist encoder bundles,
automatic old-size/unused-attachment cleanup, deduplication of independent media,
offload, network-wide destructive multisite, archive/CDN/security suites and timed
automatic purge. GIF/WebP/AVIF/SVG/other unsupported operations are reported, not
silently converted. PNG exact preservation that cannot be proved is a safe skip.

Every milestone must preserve current derivative APIs, plugin settings, engine
signature and upgrade intents unless it explicitly needs a reviewed schema change.
Do not bump versions or package candidate ZIPs until a later release gate authorizes
that work. All initial development/fixtures are isolated local and synthetic.
Consumer sites and accepted 0.3.2 artifacts remain separate.

## Milestones

| Milestone | Deliverable | Acceptance / stop gate |
| --- | --- | --- |
| M1 - Read-only storage analyzer | Trusted attachment graph, storage roles, evidence/confidence, quick opportunities, native scan UI. | Zero canonical media/content mutation; exact unique byte accounting; bounded resume; stale/unsupported cases; STOP for review. |
| M2 - Policy and immutable plan | Normalize quality/dimensions/format/recovery/retirement/purge independently; job/item schema; capacity and conflict plan. | Same preview/execution eligibility, upgrades non-destructive, deny unsupported sources/capabilities; no live replacement yet. |
| M3 - Single JPEG replacement | Candidate verification, exact escrow, certified rename/CAS, verification and recovery coordinator. | Crash/race/low-disk matrix passes with native display/REST/srcset/editing/getters, permissions and metadata/color gates; safe disable/uninstall behavior in place before enabling replacement; no original purge yet. |
| M4 - Original retirement and purge | Healthy original/scaled case, original lookup/alias reconciliation, explicit purge and scoped restore. | No dangling original references; recovery real until purge; exact after-purge net bytes; no resurrection/foreign deletion. |
| M5 - Resumable storage jobs | One-item runner, pause/cancel/status, bounded retry, Cron/CLI adapters if appropriate. | Browser closure/restart and fencing verified; completed work not re-encoded or double-counted; no concurrent destructive workers. |
| M6 - Bounded PNG | Same-dimension lossless candidate and pixel/alpha/depth/color/metadata verification. | No silent format conversion, palette loss or animation flattening; unsupported backend/metadata skips honestly. |
| M7 - Product UX and integrations | Understandable analysis/plan/result/restore/purge flow, EN/FR, Media Library, existing API/consumer regression. | Keyboard/responsive/zoom, honest recovery/total/quota states, same data across API/UI, Pixel disabled/uninstalled usable. |
| M8 - Release and pilot readiness | Minimum/current WP/PHP and OS/DB/backend matrix, evidence pack, visual outliers, package scope review. | No false certification; no implementation gaps hidden as PASS; separate permission needed for tag/release/deployment. |

M1 can be reviewed independently. M2-M7 may use shared tests but cannot enable a
destructive control before its prerequisite gate passes. A fallback analyzer-only
mode is a supported result on unsupported hosting, not a failed installation.

## M1 detailed acceptance

1. Attachment-driven inventory: resolve operational file, `original_image`, native
   sizes, edit backups, Pixel current/history aliases and known recovery roles.
   No recursive uploads discovery/deletion and no assumptions from suffixes.
2. Count a shared physical file once, retain its roles/ownership uncertainty, and
   flag missing/inconsistent metadata. Missing bytes are zero, not silent health.
3. Report actual dimensions/MIME, ICC/alpha/animation capabilities or unknown,
   without expensive whole-corpus decode/hash on frontend/admin entry.
4. Quick analysis reports measured original bytes and conditional opportunities.
   Recompression saving is unknown until measured in a later encoder experiment;
   no fabricated percentage, safe-to-delete claim or deep encoding in M1.
5. Existing derivatives/masters/meta/settings/API behavior stay unchanged. Scan
   persistence is limited to new owned analysis state. WordPress object-cache
   incidental activity is not a hidden change to canonical attachment data.
6. Scan authorization is capability/nonce guarded, per-item permission checked;
   no public REST report, absolute path exposure, credentials or private payloads.
7. Bounded cursor/ceiling, one item/time slice, resume/pause, EN/FR friendly reasons
   and status distinguish healthy/unsupported/missing/needs-review/stale.
8. Unit tests plus real isolated WordPress integration compare attachment rows,
   full postmeta, files, existing Pixel manifests and native getters before/after.
   Hash synthetic fixture files for this test only. Verify no candidate/recovery
   directories were created and optimization was never called.
9. Include ordinary/scaled/edited/missing/shared/offloaded cases; multiple-role
   alias and competitor-backup ownership uncertainty; malformed headers, symlinks,
   traversal, multisite-root discovery and cancellation/restart.
10. Run scoped existing regression tests. Inspect isolated headless admin screenshots
    at desktop/tablet/mobile and zoom/keyboard; no visible user Chrome/desktop takeover.

M1 ready means these properties are demonstrated, not merely mocked. If a matrix
environment is unavailable, report it explicitly; no fabricated minimum-version
or hosting support. There is no need to connect a consumer to finish M1.

## Cross-milestone safety matrix

| Area | Required fixtures / evidence |
| --- | --- |
| Source and ownership | Shared/inode/hard-link alias, absent file, symlink, filtered/offload path, changed hash with equal byte length or mtime, unknown native/competitor companion, mode/group/ACL preservation or safe refusal. |
| Native graph | Ordinary JPEG, healthy original/scaled pair, edited backups, rotated original, current Pixel source alias, healthy old crops, missing-size key/file mismatch. |
| Color / quality | Plain RGB and RGB ICC, malformed/oversized/non-RGB profiles, EXIF orientation, rights metadata, GPS policy, small/already optimized masters, difficult color/texture/text faces. |
| PNG | RGB/RGBA, palette and partial alpha, color chunks, supported 16-bit evidence, metadata, APNG and malformed/unknown animation as safe skips. |
| Resources | Peak disk/quota limit, reference workspace, recovery copy, candidate output inflation, RAM/time/pixel ceilings, missing backend, permission changes, fsync/rename failures. |
| Crash boundaries | Preparation, escrow, intent write, swap before metadata, metadata before Pixel manifest, verification, original pointer removal before retirement, restore stages, partial purge. |
| Concurrency | Two workers, stale lease with live flock, external core edit, size regeneration, attachment deletion, metadata filter mutation, restore after later edit. |
| Delivery | Full/native/Pixel getters, frontend/image markup, srcset/REST, browser cache and bounded targeted invalidation, cache adapter unavailable, private-media gateway. |
| Lifecycle | Edit/crop/rotate/save/restore, new registered size within/beyond retained resolution, deactivation, uninstall, downgrade, upgrade preserving policies/signatures. |
| Accounting | Aliases once, active vs quarantine, retained generation, failed/partial/repeated purge, audit overhead, logical vs allocated/quota unknown. |
| Admin security | Capabilities and per-attachment permission, nonce, unauthorized API, path traversal, sanitized diagnostics, no public recovery or manifest. |

Test real MySQL/MariaDB and Linux filesystem behavior before claiming those targets;
SQLite/Windows success is not equivalent. Verify minimum PHP 8.1 / WP 6.6 separately
from currently tested versions. Pin exact backend/library versions in evidence.
No competitor, CDN or hosting-wide compatibility claim from presence detection alone.

## Quality experiment gate before master defaults

Reuse independent candidate search and bounded color references, not legacy THUMB
thresholds as master approval. On synthetic/publicly licensed local test images,
compare same-oriented, same-color and same-dimension reference pixels. Record source
and output dimensions, encoder/library/subsampling/profile, bytes, decision reason,
perceptual metrics and visual outliers. Choose conservative actual master floors
from evidence; an 8 MB -> 1 MB example is an intent, not a fixed expected result.

Require positive meaningful saving after all final files, or skip. Compare future
derivatives generated from the proposed retained master as well as its direct
appearance. Human visual review catches artifacts that sampled metrics miss.
No repeated recompression from a previous lossy candidate to chase smaller bytes.
PNG is pixel-equivalence work, not a photographic lossy benchmark.

## First consumer pilot proposal: TherapsyCorporel

NOT EXECUTED. No connection, credential access or site mutation in this task.

After generic release readiness and explicit owner authorization, start with
read-only attachment-driven storage analysis. Select a tiny reviewed set of local
RGB JPEGs with healthy metadata: one ordinary large photograph and one supported
original/scaled pair, if the real inventory contains them. No assumed site counts,
dimensions, quota, savings or backend capabilities.

Before replacement: validate private recovery, known capacity/peak reserve, scoped
backup/rollback and live references/cache delivery. Process one attachment at a
time with quarantine, verify frontend/admin/native sizes/REST/srcset/editing and
owner visual approval. No library-wide execution or early original deletion.

Permanent purge is a separate explicitly approved gate after verified recovery
state and owner acknowledgement of irreversible original/resolution loss. If
capacity cannot fit even one safe escrow/candidate, stop with a bounded external
backup/off-host preparation recommendation; never unlink the source to make room.

PDE remains on its accepted 0.3.2 behavior while 0.4 matures. No reoptimization of
accepted Albums media, no Albums/iFolders changes, no modern-format enablement and
no ShortPixel action follows from this proposal.

## Prepared first implementation prompt

The following prompt was not executed by the architecture run. It was subsequently
approved and is the execution specification for the separate M1 implementation.

### WP Seed Pixel M1: read-only storage analyzer

Work exclusively in the WP Seed Pixel repository. Read local instructions and the
four Pixel 0.4 architecture/research documents plus ADRs. Their decisions are the
source of truth; do not reopen the entire master brief. Audit the current worktree,
preserve all preexisting 0.3.x changes and do not touch consumer repositories.

Implement ONLY M1: an attachment-driven quick storage analyzer with a small native
WordPress admin entry and a bounded resumable scan. No optimization, candidate
encoding, source replacement, original retirement, recovery-file creation, purge,
repair, format conversion, new automatic processing or image-size regeneration.

Reuse trusted path/capability/presentation helpers where side-effect-free. Never
call the existing optimize pipeline just to inspect an image. Canonical attachment
posts/postmeta, image bytes, manifests and settings must not be written. A new
owned analysis repository/schema may persist scan results; keep activation/schema
changes scoped and preserve current processing/version constants and API semantics.

Return the proposed analysis schema: identity, health, dimensions/format, explicit
unknown color/alpha/animation, physical files with roles and ownership, measured
logical bytes plus unknown-or-measured allocated bytes, backend eligibility and
conditional opportunities/reason codes. Do not claim quota from disk free space.
No guessed compression ratio or claim an original is safe to delete from filename.
No public route, raw private payload or absolute server-path report.

Support bounded cursor/ceiling, timestamps/stat/metadata signatures, pause/resume,
stale marking, graceful unsupported cases and per-item permission. Deduplicate
physical aliases; report missing files, edit backups, shared ownership and external
storage honestly. No broad directory crawler and no frontend whole-library scan.

Add focused synthetic unit and isolated real WordPress tests for the M1 acceptance
list above. Compare file hashes and canonical DB state before/after scans, test
authorization/malformed paths/resume and preserve existing derivative regressions.
Inspect headless isolated screenshots and keyboard/zoom/responsive states yourself.
Do not use the owner's visible Chrome or desktop. Report actual environment and
untested matrix honestly; do not contact PDE, TherapsyCorporel or production.

Deliver scoped code/tests/docs, a factual result and self-review including byte
accounting and zero-media-write evidence. No commit, push, tag, release, ZIP rebuild,
new runtime dependency or consumer action. Clean only your temporary fixtures.
STOP at M1 review; do not begin M2 or destructive functionality automatically.

## Architecture self-review and current result

| Question | Architecture answer | Proof still required |
| --- | --- | --- |
| Can several GB actually be reclaimed? | Yes through verified original/master retirement and explicit physical purge, not permanent mandatory copies. | Real selected-byte ledger and safe pilot, not promised universal savings. |
| Can core still display/edit/regenerate? | Native path/metadata/sizes remain; future resolution limit explicit. | M3/M4/M8 integration including Pixel disabled. |
| Can every destructive boundary be diagnosed? | Durable intent, exact graph, scoped recovery and ambiguity stop. | Crash/power-loss/OS/DB matrix, no atomicity overclaim. |
| Is deletion ownership provable? | Recorded relative identity/hash/reference/current state required. | Race/shared-path/foreign-file tests. |
| Is rollback really present when shown? | Live escrow verification, not a historical boolean. | Corrupt/missing escrow and later-edit cases. |
| Is quota accounting honest? | Unique logical bytes, recovery/overhead included; allocated/quota uncertainty explicit. | Measurement and partial purge tests. |
| Could saving hide quality loss? | Master-specific experiment/metadata/color/visual gates. | Local outlier human review before defaults/pilot. |
| What if a larger theme size is required? | Retained resolution limits generation; external original needed for more. | Larger new-size core test and UI warning. |
| Disable/downgrade/uninstall safe? | Ordinary attachments usable; recovery/resources not erased. | Real lifecycle matrix. |
| Is the product still bounded? | Analyzer, local encoder/coordinator, two tables; modern delivery and media manager excluded. | Scope gate on each implementation milestone. |
| Can PDE remain unchanged? | Yes; no automatic processing/settings migration or current connection. | Future explicit upgrade regression, not performed here. |

Design issues caught and resolved: original URL retirement is not same-path URL
stability; native missing-size detection is not a file existence check; current
Pixel master reuse can alias the original; arbitrary master metadata stripping is
not derivative privacy policy; quarantine and old generations do not release
quota; rename is not a file+SQL transaction; external workers ignore Pixel locks.

Architecture-run verdict: ARCHITECTURE READY FOR IMPLEMENTATION. M1 has since
been implemented as a review candidate; this does not certify 0.4.0 quality defaults,
a package, hosting support or destructive processing. See STORAGE-ANALYZER.md
and ignored reports/storage-m1 for the separate M1 evidence and limits.

STOP for Guillaume + ChatGPT review of M1. No automatic M2 execution.
