# Pixel 0.4.0: image optimization and storage management

2026-10-05 V1 candidate addendum: M1-M5.1 accepted; bounded M6 uses the same
master adapter, DB authority, budget, M4 recovery and M5 jobs. PNG changes only
IDAT compression, not filtered pixel bytes or any other chunk. JPEG behavior
and legacy engine identity are preserved. Future formats are explicit; older
settings default to JPEG without PNG enrollment. Selected plans freeze their
attachment IDs. Real operation admission stops at 10,000 records, retaining
all recovery evidence. No timed expiry or cloud service is introduced.
See PNG-LOSSLESS.md / V1-USER-GUIDE.md. Historical design text follows.

Date: 2026-10-04. Status: recommended architecture, ready for implementation
planning; NOT an implemented or certified 0.4.0 release.

This task changes documentation only. No site was contacted, no real image was
processed, no runtime file or version was changed, and no artifact was rebuilt.
The accepted PDE 0.3.2 baseline remains outside this work. Existing uncommitted
0.3.1/0.3.2 source changes are preserved, not absorbed into an architecture commit.

## Decision summary

Evolve the existing engine; do not rewrite it. Start with an attachment-driven,
read-only analyzer. Add an explicit storage operation coordinator around the
encoder, not deletion inside the encoder. For eligible local JPEGs, replace the
operational WordPress image at the same path and format through a journaled,
verified swap. Treat retirement of a WordPress-preserved original as a separate
operation. Keep recovery bytes until verification, then allow explicit permanent
purge that actually releases disk. Same-account quarantine is not disk savings.

The 0.4.0 MVP includes analysis, immutable policy/plan snapshots, durable jobs,
JPEG encoding/downsize replacement, original/scaled reconciliation, quarantine,
restore, explicit purge, resumable bulk processing, bounded lossless PNG
optimization, simple EN/FR admin UX and generic certification. Analyzer alone is
M1, not the entire storage-saving release. First real storage pilot: a separately
authorized TherapsyCorporel sample, after generic QA and visual acceptance.

Deferred: PNG-to-JPEG conversion, WebP/AVIF delivery or replacement, animated
optimization, remote/offload adapters, external archive automation, automatic
time-based purge, arbitrary WordPress-size pruning, unused-media deletion,
attachment deduplication, full multisite/network support and a CDN.

Research and decisions: [WordPress lifecycle](PIXEL-0.4-WORDPRESS-IMAGE-LIFECYCLE.md),
[competitors](PIXEL-0.4-COMPETITOR-RESEARCH.md),
[ADRs](adr/PIXEL-0.4-DECISIONS.md), [roadmap and M1 prompt](PIXEL-0.4-ROADMAP.md).

## Product boundaries and terminology

WordPress owns attachment posts, Media Library, upload paths, registered sizes,
metadata, editing and normal media URLs. Pixel owns analysis, image candidates,
its resources, storage operations and attributable recovery records. Albums owns
membership, order, selection, gallery and access-control workflows. iFolders
owns organization. None becomes dependent on destructive Pixel operations.
Whole-site backups, private-media access control, SEO, mail, media trash, DB
cleaning and hosting quota administration are not Pixel responsibilities.

An **operational master** is the current local file resolved by
`get_attached_file()`, ordinarily represented by metadata `file`. A **preserved
uploaded original** is the separate file named by metadata `original_image`,
when present. They can be identical only when no separate original exists.
The 0.3.2 engine's **source/master** is instead `wp_get_original_image_path()`.
Do not silently equate these concepts during upgrade.

French normal UI: image principale, original haute resolution conserve par
WordPress, versions WordPress, versions Pixel, anciennes versions conservees,
espace potentiellement recuperable, espace reellement libere. English-source
gettext remains the translation model; French catalogs and plural handling stay.

## Audit of the actual 0.3.2 checkout

Observed local branch `main`, HEAD `3da4435591488e6089b827c7b581c706e1e4a034`.
Runtime version is 0.3.2; processing signature constant is 0.3.1. This checkout
contains preexisting unstaged work. Historical docs describe earlier candidate
gates; they do not establish new runtime test results for this architecture.

| Component | Disposition | Reuse and required evolution |
| --- | --- | --- |
| `wp-seed-pixel.php` | EXTEND | Minimal bootstrap, independent UI/engine/schema versions; no processing on activation/update. |
| `class-plugin.php` | EXTEND | Preserve automatic derivative intent; replacement and purge remain separately OFF on upgrade. |
| `api.php` | KEEP + EXTEND | Existing optimize/register/get/prune semantics remain non-destructive. New explicit storage API, never reinterpret `force`. |
| `class-presets.php` | KEEP + EXTEND | Existing/custom preset signatures unchanged; separate master dimension/retention/metadata policy. |
| `class-adaptive.php` | KEEP | Independent bounded candidates, reuse and measured gates; no new arbitrary master quality numbers. |
| `class-color.php` | EXTEND | Keep bounded RGB ICC/LittleCMS checks and fail-closed GD handling; add a separately verified master-output metadata contract. |
| `class-engine.php` | REFACTOR narrowly | Extract candidate creation/verification for reuse; legacy resource entry point keeps its contract. Encoder never purges. |
| `class-files.php` | EXTEND | Trusted paths, symlink refusal, ownership checks and flock; distinguish uploads, private recovery root and swap capabilities. |
| `class-store.php` | KEEP + EXTEND | Protected manifests/history, exact metadata CAS, journals; new storage repository/coordinator rather than overloading derivative history. |
| `class-batch.php` | KEEP legacy / DEPRECATE for new storage jobs | One serialized cursor option is adequate for existing workflow, not durable per-item destructive history. Use job/item tables for new operations. |
| `class-media.php` | EXTEND | Native list/grid integration and editable attachment checks; analyzer status, no casual purge row action. |
| `class-admin.php`, admin/media assets | EXTEND | Existing nonce/capability adapters and native UI; paginated analysis/plan/results, no new frontend framework. |
| `class-i18n.php`, messages, languages/tools | KEEP + EXTEND | Translate at presentation boundary; machine codes/manifests remain stable. |
| `uninstall.php` | EXTEND before storage release | Preserve masters, active referenced resources and all outstanding recovery information; explicit separate cleanup only. |
| Tests/package tooling | KEEP + EXTEND | Preserve allowlisted ZIP and synthetic suites; add lifecycle, crash, DB, backend and accounting matrices. |
| Existing architecture/API/color/i18n docs | KEEP | Historical behavior stays readable; this package is the future design, not a rewritten certification record. |

Current Adaptive: Q78/Q86/Q94 candidates, VIEW SSIM >=0.985 / PSNR >=35;
THUMB >=0.960 / >=30; sampled 64 luma blocks plus RGB PSNR, not a universal
perceptual guarantee. Built-in balanced bounds: THUMB 640 and VIEW 1920.
Fixed `web`: 1600/Q82; legacy participant preset: 640/Q80 and 2048/Q90.
These are derivative parameters, NOT certified replacement-master defaults.
The present engine rejects above 40 MP/64 MB and applies decode/disk budgets.
Keep these safeguards until separately measured alternatives are certified.

Limitations to address: source vs operational-master ambiguity; derivative
metadata stripping cannot safely become master metadata preservation; single
option batch state; no original retirement or whole-operation recovery; current
disk-growth metrics not net hosting savings; workspace permissions alone are not
proof of HTTP confidentiality. Keep existing conservative history pruning.

## Services and API contracts

`AttachmentAdapter -> Analyzer -> Policy/Plan -> OperationCoordinator`

Coordinator calls existing/extracted Processor, Files and Store services plus
a small RecoveryStore. Add boundaries only where these responsibilities differ:

- Adapter: resolve a complete attachment/file graph, relevant exact DB snapshot,
  guarded reconciliation and WordPress integration verification.
- Analyzer: inspect canonical media without modifying it; no calls to the current
  optimization engine (it writes job/meta/workspace state).
- Processor: inspect capability, encode candidates, verify output, describe it;
  NEVER delete or publish masters.
- Policy/Plan: identical eligibility logic for preview and execution; freeze
  selected opportunities and policy, not stale source assumptions.
- Files/RecoveryStore: validated storage, private escrow, swap, restore and purge.
- Coordinator/repository: durable transitions, owned locks, recovery and metrics.

Keep `wp_seed_pixel_optimize`, `wp_seed_pixel_register_preset`,
`wp_seed_pixel_get_derivative`, `wp_seed_pixel_prune_history` backward compatible.
Proposed new trusted PHP contracts: analyze attachment, build plan, start/status/
resume job, restore item, purge verified item. Storage execution requires an
explicit plan/policy/context, even for PHP callers. HTTP adapters enforce
`manage_options` AND `edit_post` for each attachment plus nonce; no public route.
CLI registers only when real WP-CLI classes/runtime exist, not just `WP_CLI=true`.
UI, Cron and CLI delegate to the same coordinator.

Keep existing hooks. Add only analysis completion, before/after master change,
after restore, and before/after purge/failure events. Observers cannot waive
identity, path, backup or verification gates. Cache adapters consume resource
change events; no Divi or hosting-provider hardcoding.

## Normalized policy and analysis

Separate quality intent (`quality`, `balanced`, `storage_saver`), dimensions
(`keep` or explicitly chosen max long edge), format (`keep`), master action
(`keep` or `replace_verified`), preserved-original retirement, recovery mode
(`local_quarantine` or explicit external/irreversible mode), purge authorization,
metadata and derivative policy. Install default: KEEP originals, KEEP format;
new destructive automation and purge OFF. Existing derivative automation intent
is preserved. A quality/profile choice is NEVER destructive permission.

Reject contradictory plans: require local restore but immediate removal of the
only recovery copy; unsupported format/backend; dimension target below required
current resource bounds; unknown quota with unsafe peak. Material consent-policy
changes require renewed approval. Every job stores normalized effective values,
policy hash, plan revision and schema/encoder signatures, independent of UI version.

Analyzer schema, proposed (not a runtime API yet):

```text
analysis_version, attachment_id, scanned_at, policy_sha256
identity: attached_relative_path, metadata_revision, stat_signature
health: healthy | inconsistent | missing | unsupported
image: actual_format, reported_mime, width, height, orientation, ICC_class,
       alpha_state, animation_state (unknown is not false)
files[]: identity, relative_path, roles[], exists, logical_bytes,
         allocated_bytes_or_unknown, owner/confidence
storage: operational, preserved_original, wp_sizes, pixel_current,
         pixel_history, edit_backups, recovery, unattributed
capabilities: local_storage, decoders, encoders, color, metadata, swap
opportunities[]: action, risk, eligibility, reason_code,
                measured_bytes_or_null, estimate_confidence, conflicts[]
plan_projection: net_active_delta, recovery_delta, reclaim_after_purge,
                 peak_extra_bytes, exclusions
stale: true | false | unknown
```

Attachment-driven inventory first; no recursive uploads scan by default.
Resolve WP APIs/custom upload roots, deduplicate physical paths/inodes where
available, report shared paths without double counting. `kind=master` aliases
do not add Pixel bytes. Missing files count zero, with an anomaly. Unknown adjacent
files, backups belonging to competitors and obsolete core-size names are NOT
deletion candidates. A metadata size key does not prove its file exists.

Quick scan: file graph/stat/header evidence and exact recorded-original bytes;
no invented compression percentage. Unmeasured encode savings remain unknown.
Optional later deep analysis uses the execution encoder on private temporary
candidates, cleans them and labels samples/extrapolation separately. M1 excludes
deep encoding to make its no-image-write guarantee unambiguous.

Cache scan rows with timestamp/stat/metadata/policy signatures. Hook invalidation
is an optimization, not proof; fresh identity/hash revalidation is mandatory at
every destructive gate. No full-corpus hashing on every admin/frontend request.

## Minimal persistent model and execution

Options (autoload false): schema version, policy/settings and active job pointer.
Keep existing protected manifest/history schemas readable; new protected
`_seed_pixel_master_state` summarizes current operation/signature/recovery status,
never a public REST field. Do not duplicate WordPress attachment authority.

Two site-prefixed tables, versioned through idempotent `dbDelta` migration:

| Table | Proposed fields / indexes |
| --- | --- |
| `seed_pixel_jobs` | id, kind (scan/plan/replace/purge), status, policy+plan snapshot/hash, schema/engine version, scan ceiling/cursor, counters, byte summaries, actor, timestamps; indexes status/updated, kind/status. |
| `seed_pixel_items` | id, job_id, attachment_id, action, stage, revision, fencing/lease token+expiry, source/candidate/recovery identities, owned file ledger, exact affected metadata before/after, byte deltas, error code/retry count, timestamps; unique job/attachment/action, indexes job/status and attachment/status. |

JSON payloads stored in portable LONGTEXT, no image pixels, secret, GPS dumps or
global DB snapshot. Row updates CAS on stage/revision/token. Durable intent is
recorded BEFORE each filesystem/DB side effect. Add a checksummed private per-item
recovery manifest as filesystem evidence, not a competing source of authority.
Active/recoverable items never pruned. Completed verbose scan rows can expire;
small replacement/purge identities and audit summaries stay until explicit cleanup.
Options/transients alone are rejected for durable destructive history. See ADR-02.

One destructive worker per site initially. Shared per-attachment flock with legacy
engine plus durable lease/fencing token. Lease expiry alone does not steal a lock:
prove old process has released flock, reconcile state, then claim. WordPress/third
party editors do not automatically honor this lock; guard fresh file and DB state
again and pause on races. Do not claim universal concurrency exclusion.

Request-driven one-item/bounded time steps, explicit pause/resume, optional bounded
WP-Cron wakeups and CLI. Closing the browser never loses progress; continuation
depends on working Cron or another runner, not a promised daemon. No Action
Scheduler dependency in MVP: its queue/log capabilities do not remove the need
for item recovery. Pause after repeated systemic backend/storage failures; never
retry a VERIFIED/PURGED item as a new encoding. Cancel between safe boundaries,
not during swap. Old derivative batch remains readable until explicitly completed.

## Replacement protocol: concrete and recoverable

Supported MVP: local regular files, known ownership, no offload/filter redirection,
no shared master or edited-backup ambiguity. Same MIME/extension/path, no crop or
upscale. PHP filesystem APIs behind a narrow validated local adapter, not FTP
`WP_Filesystem` copy/delete pretending to be atomic. Destructive eligibility
requires a certified same-filesystem rename-over-existing capability. No fallback
to `unlink(live)` then `copy(candidate)`.

```text
QUEUED -> PREPARING -> READY -> SWITCH_INTENT -> SWITCHED
       -> VERIFIED -> RETAINED -> PURGE_INTENT -> PURGED
Any pre-switch failure: FAILED/SKIPPED, original stays.
Post-switch error: RECOVERY_REQUIRED -> ROLLED_BACK or verified continuation.
Ambiguous external changes: NEEDS_REVIEW, no automatic overwrite/purge.
```

1. Claim lock/lease; resolve current attached path, original, sizes, Pixel aliases,
   edit backups, ownership and references. Capture exact affected metadata rows;
   duplicate rows, unknown source companions or shared paths block execution.
2. Revalidate/hash source(s), selected policy, rights/color/orientation and budgets.
   Build explicit before/after graph. Record PREPARING and durable identities.
3. Encode private candidate independently from chosen source/reference. Decode,
   verify MIME/extension/dimensions/positive meaningful gain, orientation, ICC,
   metadata and quality gates. Never reuse a disposable derivative as master.
4. Prepare complete private escrow of every byte this operation will overwrite or
   retire; verify hashes. External backup acknowledgement is NOT proof of restore.
   Even immediate-purge mode holds short-lived recovery bytes until verification.
5. Prepare any necessary new Pixel generation BEFORE retiring its old one.
   Unchanged valid WordPress sizes remain at current URLs; no blind whole-meta
   generation routine that progressively writes DB state during preparation.
6. Persist READY and SWITCH_INTENT with exact candidate/recovery/DB after-state.
   Flush files/journal and check storage guarantees. Stage final candidate on the
   live filesystem, protected from HTTP; rename over live without a missing-path
   interval on certified filesystems. Verify resulting SHA immediately.
7. CAS exact `_wp_attachment_metadata`: `width`, `height`, `filesize`, `file`
   unchanged path, adjusted orientation/dimension metadata and affected owned
   `sizes` entries only. `_wp_attached_file`, MIME and GUID stay unchanged for
   same-format/same-path operations. Foreign keys are preserved. A filter altering
   proposed metadata causes refusal/recovery, not a blind commit. Invalidate
   WordPress object metadata caches and exact read-back. Save protected current
   Pixel generation/master state via journaled reconciliation.
8. Verify all required files and WP URL/image/srcset/REST/admin contracts, plus
   applicable cache/delivery acknowledgement. Mark VERIFIED only if exact file
   graph, metadata and runtime invariants hold. HTTP verification of private media
   uses the authorized delivery adapter, not an unauthenticated request bypass.
9. Move retained recovery into confirmed private quarantine, or execute separately
   authorized purge after the verified gate. Original/scaled special case follows
   its independent algorithm below. Count actual removed unique files, report
   failed removals, clean only recorded matching temps, checkpoint then unlock.

This is a recoverable protocol, NOT an atomic file+SQL transaction. A renderer
can briefly see new bytes with old dimensions before CAS; encoding-only avoids
that difference. Aspect-ratio-preserving downsizes still require QA/cache gates.
Multi-file Pixel publication likewise relies on old metadata remaining valid
until complete new resources exist. Power-loss durability is not promised merely
because PHP `rename` succeeds: fsync/storage/DB behavior needs M3/M8 testing.

### Derivatives and original/scaled cases

Existing healthy WordPress size files/entries stay unchanged in MVP; do not churn
URLs or discard crops. Require proposed master sufficient for all currently
required retained WP/Pixel roles, else REVIEW instead of silently shrinking below
them. A retained size larger than the proposed master is REVIEW initially.
Do not promise future reconstruction of discarded resolution. Normal regeneration
after retirement must work from the retained operational file.

Pixel `kind=master` aliases require special handling: if bytes/dimensions change,
prepare replacement THUMB/VIEW or safe reuse mappings and a new manifest before
commit. An unchanged source hash means zero new processing. Old immutable Pixel
URLs may remain in explicitly counted history until reference/cache review permits
owned pruning; they are not current resources. Do not report them as reclaimed.
For non-destructive API calls keep legacy source behavior; an explicit storage
plan may select operational source and retire the uploaded original. That change
must record the new source signature and republish affected getters correctly.

Case A, no `original_image`: ordinary operational replacement.
Case B, healthy separate original: keep `photo-scaled.jpg` path; optimizing it and
retiring `photo.jpg` are separate opportunities, not mandatory double processing.
For retirement, first verify escrow and all original references (including Pixel
reuse). CAS metadata to remove **only** `original_image`; while the old original
still exists, APIs now resolve retained master. Verify WP/Pixel behavior, then
retire old file into quarantine/purge. No metadata may point into private recovery.
Restoration restores original bytes before reinstating exact `original_image`.

Cases C/D, missing original/current file: report inconsistency; no repair/deletion.
Case E, `_wp_attachment_backup_sizes`, edited `-e...`, rotation history or unknown
source companion: REVIEW, destructive operation excluded in MVP. Do not assume
all `original_image` entries are simply unused scaled JPEGs.

Known content/meta/CSS/Divi/custom-field references to retired original URLs block
automatic retirement. External consumers cannot all be discovered: require a
specific acknowledgement of the original-URL retirement risk. Same-path stability
applies to operational URL, NOT a guarantee that retiring the separate original
URL is invisible. No global URL rewrite or attachment GUID rewrite in MVP.

### Crash, restore and purge examples

Crash after master rename, before metadata CAS: journal says SWITCH_INTENT, live
hash matches candidate, escrow matches old source, DB matches before-state. Resume
can finish exact CAS/verification or restore old bytes then confirm before-state.
If either hash/DB state differs unexpectedly, stop NEEDS_REVIEW. Never overwrite a
concurrent edit just to force rollback. Lease reconciliation precedes either action.

Restore: lock, verify current expected after-state and escrow, stage original on
same filesystem, record RESTORE_INTENT, switch bytes then CAS exact affected
before-metadata, restore Pixel mappings/manifest, verify, finish. Example without
later edits restores original hashes and serialized affected rows. If user/theme
editing altered current state, refuse exact restore pending a new plan; do not
restore every unrelated postmeta or roll back album order. New sizes since the job
may need explicit regeneration, not a claim of whole-site rollback.

Purge: separate authorized job, revalidate current VERIFIED graph and old file
ownership/hash plus references; record PURGE_INTENT before unlink. Missing file
after an interrupted purge is already removed, not a new saving to count again.
Persist measured identity/bytes audit. Failed deletion: VERIFIED_NOT_PURGED,
rollback status reflects actual escrow and physical saving stays unchanged.
After successful purge no Pixel Restore button; higher resolution cannot be
recovered without an independent backup. Never delete competitor/hosting backups.

### File identity, permissions and timestamps

Capture relative path/root identity, SHA-256, decoded type/dimensions, stat size,
mtime, mode and available owner/group/device/inode/link-count evidence. A timestamp
or identical byte length alone never proves unchanged contents. Same physical file
with multiple roles is counted once; multiple hard-link names or uncertain shared
ownership are REVIEW for destructive work, not an invitation to remove an alias.
Recheck source, candidate and escrow hashes immediately before their side effects.

Rename publishes the candidate's attributes, not automatically those of the old
file. Prepare and verify the intended existing delivery permissions/ownership;
never blindly publish a private 0600 candidate or widen it to 0666. If the required
mode/group/ACL or private gateway cannot be preserved with available privileges,
refuse replacement. Escrow remains private with recorded original attributes for
restore. An immutable public-resource generation follows its existing delivery
contract, independently of the private recovery contract.

Preserve image dates/rights according to metadata policy, not by hiding new file
bytes behind the former filesystem mtime. Replacement records a changed-file
timestamp and before/after hashes; mtime still is not a universal cache flush.
Restore records restored bytes/attributes and invalidates applicable caches rather
than promising to recreate inode/ctime or every historical timestamp exactly.
The rollback guarantee is affected file contents and meaningful metadata/delivery
attributes, not filesystem history or a whole-host transaction.

## Recovery location, capacity and accounting

Prefer a configured writable private root outside the served tree. It can still
consume the same hosting quota. Where unavailable, an uploads recovery/staging
root is eligible only with server-level denial verified for GET/HEAD/listing and
the real private-media delivery environment; `.htaccess` or 0700 alone is not proof.
Otherwise analyzer/non-destructive capabilities remain, destructive mode refused.
No arbitrary retrieval endpoint or world-readable archive. Same-filesystem swap
candidate may require a protected sibling staging directory separate from escrow.

Peak additional bytes = candidate + lossless color reference + newly required
derivatives + recovery copies + any extra publish copy + manifests/margin. Current
source already exists and is not added a second time. Plan worst-case bounds, not
estimated savings, before allocation; resample free space at every stage. Use a
configured minimum reserve plus measured working overhead, not universal 10%.
`disk_free_space` is filesystem capacity, not proof of account quota; unknown quota
headroom requires a bounded operator-supplied limit for destructive runs.
One item at a time on constrained hosts; skip if even the smallest safe peak fails.

File roles can overlap, physical bytes count once. Keep logical bytes internally;
allocated/quota bytes are separate measured-or-unknown values, particularly for
hard links/compressed filesystems. Net reclaimed logical bytes = attributed total
before minus attributed total after, INCLUDING Pixel history/quarantine/new sizes.
Physical/quota claims require corresponding measured allocation/provider evidence.
Independently observed free-space changes are corroboration, not attribution on a
busy shared disk. Deduct operation-created audit/storage overhead; show it separately.

Illustrative unique-image bytes, NOT site measurements:
8.0 MB uploaded original + 1.8 MB operational + 1.2 MB WP sizes + 0.3 MB Pixel
= 11.3 MB. New operational 1.0 MB, old 1.8 + uploaded 8.0 retained privately:
active 2.5 MB, recovery 9.8 MB, total 12.3 MB, net reclaimed -1.0 MB.
After explicit purge of both recovery files: total 2.5 MB, net reclaimed 8.8 MB
(before small journal overhead). Report active reduction 8.8 MB separately;
quarantine had NOT released that space. Encoding/original-retirement projections
are mutually combined by a file-set plan, never summed twice for the same bytes.

## Format and backend decisions

| Format | Analyze | Optimize / master resize / replace in 0.4 MVP | Conversion | Animation / alpha |
| --- | --- | --- | --- | --- |
| JPEG/JPG RGB | Yes | Yes / policy-bound / verified same path | None | Orientation + RGB ICC gates; CMYK/grayscale remain excluded. |
| PNG | Yes | Bounded lossless / no master resize / same-dimension verified replacement | No PNG-to-JPEG | Preserve complete alpha, depth and color; APNG/unknown animation skipped. |
| GIF | Yes | Report/skip | None | Detect animation structurally, unknown means skip; never flatten. |
| WebP / AVIF | Header/metadata inventory where supported | Pass through, no optimization/replacement | Deferred opt-in delivery | Detect or report unknown animation/alpha; capability != permission. |
| SVG | Report bytes only | No rasterization/sanitization | None | Do not parse/execute untrusted SVG for optimization. |
| HEIC/HEIF, TIFF, BMP | Report/review | Unsupported replacement | None | Account for WP-converted companions separately. |
| PDF/audio/video | Non-target report | No | None | PDF preview attribution is not PDF optimization. |

| Backend | Safe scope and limits |
| --- | --- |
| GD | Plain RGB JPEG; PNG only when complete pixel/alpha/metadata preservation can be proven. No ICC transform or arbitrary metadata round-trip. |
| Imagick + LittleCMS | Validated RGB ICC to sRGB; tested profile-preserving master output; PNG supported capabilities still need pixel/depth/alpha checks. |
| Optional optipng/pngquant/jpegtran/etc. | Extension point only, not bundled/mandatory; licensing, argument safety and platform QA required separately. Lossy PNG palette conversion deferred. |

Master-output metadata policy is PRESERVE_REQUIRED by default: retain rights/IPTC/
XMP, GPS/EXIF unless explicitly changed, reconcile physical orientation and
dimension tags, preserve correct color appearance. Unsupported round-trip => skip,
not silent stripping. Normalize validated RGB ICC via managed sRGB conversion and
retain correct target color declaration; non-RGB/out-of-gamut risk gets review.
Derivative `strip_sensitive` remains its existing separate contract. The current
private lossless ICC reference is reused, not installed as a PNG master.

JPEG quality floor must be more conservative than disposable THUMB, independent
candidate selection, meaningful gain threshold and perceptual sample gates.
Existing 5% reuse rule informs experiments, not untested new master defaults.
Certification selects actual master thresholds/Q/subsampling/progressive policy;
metrics compare oriented, color-managed, same-dimension references and do not
replace human review of outliers. Never claim GD recompression is lossless JPEG.
PNG re-encoding is accepted only when decoded RGBA samples/depth/color/required
metadata match; no palette reduction or silent alpha loss. Savings may be modest.

## Cache, compatibility and lifecycle

Stable operational URL minimizes embedded-content changes. Mtime/ETag are not
universal invalidation: browser/CDN may retain previous bytes. Same-dimension
re-encoding can tolerate visually valid old cached bytes; resize needs targeted
HTML/srcset/resource cache acknowledgement. Unknown external delivery/CDN behavior
blocks resize/purge eligibility until supported or explicitly bounded. No global
cache purge, URL query rewriting or CDN proxy dependency. Never delete old
intermediate URLs just because current registered sizes changed.

Keep native master, metadata and valid size files usable with Pixel disabled or
removed; certify Media Library, full URL, REST, srcset, editing, regeneration and
new registered size. Removal cannot erase referenced resources/recovery records.
Normal attachment deletion cancels pending items, lets WordPress own its files,
removes only proven-owned Pixel extras; recovery remains as detached admin-only
records until explicit purge, never silently resurrects a deleted attachment.
Multisite: per-site prefix/root possible, network/destructive support deferred.
Unsupported offload/remote streams/symlink storage: inventory with uncertainty,
no destructive actions. Another active optimizer is a responsibility warning or
block for overlapping pipelines, never automatically disabled by Pixel.

Existing Albums consumers retain THUMB/VIEW and ordinary native size/getter usage,
`kind=master` awareness, presets and existing hooks. Membership, order, titles,
iFolders and private gateways unchanged. PDE JPEG-only/no WebP/no AVIF policy can
survive future upgrades; neither format capability nor plugin version enables it.
Migration never processes existing media or reinterprets automatic derivative ON
as automatic replacement/purge. Downgrade sees ordinary valid WP attachments;
0.3.x cannot manage 0.4 recovery history, which stays intact for an operator.

## Admin UX, errors and security

Single journey: analyze -> storage/opportunities -> profile + original policy ->
specific plan/rollback/saving/peak summary -> run -> result -> later explicit purge.
M1 exposes NO destructive button. Use Media Library previews, paginated filters,
low-risk/high-confidence items ordered by potential net saving (and peak budget),
review items excluded by default. Native WP admin, accessible labels, keyboard,
text progress/status announcements and EN/FR; no giant JS framework needed.
Show actual rollback status, kept originals, current vs recovery vs total bytes;
do not hide permanent deletion under the generic Optimize button.

Machine errors: SOURCE_MISSING, SOURCE_CHANGED, SHARED_PATH, METADATA_CONFLICT,
UNSUPPORTED_FORMAT/STORAGE, LOW_DISK/QUOTA_UNKNOWN, BACKEND_UNAVAILABLE, ICC_UNSAFE,
CANDIDATE_INVALID, BACKUP_FAILED, SWAP_FAILED, VERIFY_FAILED, PURGE_FAILED, LOCKED,
CONFLICTING_OPTIMIZER. Pre-switch stale/source errors skip/replan; low disk/backend
systemic errors pause; post-switch verify failures require recovery; altered
recovery identities require review; failed purge does not corrupt a valid master.
Translated friendly messages separated from sanitized technical details.

Never trust a client path, allow public job/diagnostic endpoints or export absolute
server paths. Revalidate capability per item, bound decode pixels/RAM/time, refuse
malformed images/profiles and traversal/symlinks/shared ownership. Authenticated
HTTP/nonces for scan-state writes too. No telemetry/cloud upload by default.
No new runtime dependency is introduced by this documentation. Optional encoders
need a later benefit/license/portability/fallback review, not copying competitor code.

## Skeptical self-review and implementation gates

Wrong-file deletion: relative ledger + ID/ownership + SHA immediately before purge;
not filenames/bytes alone. File/DB split: persisted intent and exact before/after
recovery, not a claim of atomic SQL+filesystem. False saving: unique file graph
includes retained copies. False restore: validate recovery now, not historical flag.
Hidden quality loss: master-specific floor/color/metadata plus outlier human sample.
Future resolution: explicit irreversible tradeoff, no fabricated reconstruction.
External mutation/cache: bounded eligibility and review, no universal compatibility.

Simplification: two tables, one item coordinator, existing encoder/metrics/APIs,
same-format/path MVP, ordinary WordPress files, no CDN/archive/format-rewrite suite.
Storage goal is met by explicit VERIFIED -> PURGE, including a short-lived escrow
mode for quota constraints; indefinite duplicate storage is not required.

Architecture tests performed in this task: source/document research and local
scope/integrity checks only. No new backend, crash, browser or site certification.
M1-M8 gates in the roadmap must prove this design before a destructive pilot.
Verdict: ARCHITECTURE READY FOR IMPLEMENTATION (M1 proposed, NOT executed).
