# Pixel 0.4.0: architecture decision records

Initial selection: 2026-10-04. The records below retain their historical decision
context. M1-M5.1 have since been accepted; bounded M6 and the admin completion are
implemented in the private V1 candidate. The completion addendum at the end is
the current scope; exact certification is recorded in reports/storage-v1/.
There is no new public release. The
[architecture](../PIXEL-0.4-ARCHITECTURE.md) contains protocols and schemas;
[research](../PIXEL-0.4-WORDPRESS-IMAGE-LIFECYCLE.md) contains primary evidence;
[roadmap](../PIXEL-0.4-ROADMAP.md) contains proof requirements.

## ADR-01: evolve the local engine, separate policy from effects

Context: 0.3.x already has bounded encoding, RGB ICC safeguards, trusted file
handling, manifests, metadata CAS and a WordPress-native interface. Its immutable
derivative model does not itself reclaim master/original storage.

Decision: reuse those components. Add attachment analysis, an explicit plan and
one storage coordinator. Extract candidate generation only where necessary; the
processor returns verified candidates and never publishes or deletes masters.
Legacy APIs and derivative intents retain their non-destructive semantics.

Rejected: big-bang replacement engine; copying competitor code; turning a preset
or legacy `force` parameter into permission to replace/purge a source.

Consequences: meaningful storage reduction becomes possible without a second image
algorithm. New protocol complexity is localized. Existing 0.3.x tests remain
regression obligations; runtime changes start only after architecture approval.

## ADR-02: two durable job/item tables and a small private recovery journal

Context: a cursor option and bounded derivative history cannot safely describe
partially completed destructive operations, per-file identity and exact recovery.

Decision: per-site `seed_pixel_jobs` / `seed_pixel_items` tables with schema
versioning, bounded JSON in LONGTEXT, indexed state and CAS revisions. Keep options
small and not autoloaded; protect current master state and keep legacy manifests
readable. Persist checksummed private file evidence, not competing authority.
Never record private image payloads, credentials or public absolute-path exports.

Rejected: transient-only queues; giant all-library options; a database table for
every file role; mandatory third-party job framework; full database snapshots for
single-image recovery.

Consequences: resume/audit/idempotence survive browser closure. Active/recoverable
items cannot age out. Request-driven workers and optional Cron/CLI share state;
continuation depends on a functioning runner, not a promised background daemon.

## ADR-03: stable operational path, independent original retirement

Context: native operational full image and uploaded `original_image` can be two
different files. Content, REST, editing and later size generation depend on their
native meanings. Pixel source-reuse aliases may still point to the original.

Decision: MVP replacement keeps operational path, extension and MIME; no GUID or
global content rewrite. Build/verify candidate and recovery first, then certified
same-filesystem rename and exact affected metadata CAS. Retire a healthy separate
original only through its own verified plan: remove `original_image` while bytes
still exist, verify fallback/aliases, then quarantine or purge that old file.

Rejected: deleting first; changing extension while pretending URLs are stable;
moving a metadata source into private quarantine; guessing originals by `-scaled`;
automatically retiring an original referenced by content or custom fields.

Consequences: normal full URL stays stable; a separately retired original URL does
not. Externally unknown uses need explicit acknowledgement. Edited/missing/shared
or unknown source graphs remain REVIEW, not an automatic repair operation.

## ADR-04: rollback available until explicit verified purge, not forever

Context: retaining all originals forever defeats the storage objective, but
deleting the only source before verification makes failure unrecoverable.

Decision: every replacement temporarily escrows exact affected bytes and metadata.
Offer verified private local quarantine or an explicitly acknowledged irreversible
policy. Permanent purge is a separate authorized action after VERIFIED, with fresh
identity/reference/ownership checks. Prefer recovery outside the served root;
require demonstrated HTTP protection otherwise. Decline destructive mode if private
recovery and safe swap cannot be guaranteed.

Rejected: permanent duplicate storage as the only policy; public backup ZIPs;
claiming an external-backup checkbox proves restore; deleting another plugin's
backups; expiry-based automatic purge in MVP.

Consequences: storage can be physically reclaimed. Same-host quarantine still
counts against quota. Restore is displayed only while verified recovery exists;
successful purge irreversibly removes that Pixel rollback and retained resolution.

## ADR-05: conservative same-format scope and separate master metadata policy

Context: backend format support does not establish acceptable color, rights,
orientation, alpha, depth, animation or visual quality after recompression.

Decision: eligible RGB JPEG replacement, policy-bound no-upscale dimensions,
managed RGB ICC only on demonstrated backend capability. Preserve required master
metadata by default; existing derivative stripping remains independent. Bounded PNG
lossless same-dimension replacement requires exact decoded/color/alpha/depth/metadata
checks. Unsupported operations are explicit skips. No mandatory cloud/binary suite.

Rejected: silent CMYK conversion; GD recompression advertised as lossless JPEG;
PNG-to-JPEG; flattened animation; automatic WebP/AVIF; reusing a disposable derivative
as the only future master; assigning a profile in place of color conversion.

Consequences: broad inventory but narrow destructive support. Master thresholds
must be experimentally selected, not copied from THUMB. Quality outliers require
human review. Modern formats and specialist encoders are future opt-in work.

## ADR-06: analyzer-first delivery and explicit consumer gates

Context: a storage-constrained consumer needs actionable byte opportunities, not
an immediately executable whole-library operation.

Decision: M1 is read-only media analysis, then policy/planning, single JPEG protocol,
scaled-original retirement/purge, resumable bulk, bounded PNG, UI and release QA.
Keep 0.3.2 accepted consumer behavior unchanged throughout architecture and initial
generic development. First consumer pilot is a separately authorized tiny JPEG
sample; generic QA precedes it and permanent purge gets its own gate.

Rejected: whole-library first pilot; remote mutation during research; version bump
or ZIP packaging from documentation alone; implying architecture approval certifies
backend quality or hosting compatibility.

Consequences: useful results arrive before destructive functionality. Scope can
be reviewed at each milestone without repeating unrelated consumer certification.
PDE no-WebP/no-AVIF policy remains valid; TherapsyCorporel has not been contacted.

## ADR-07: count unique files and net storage, including recovery

Context: transfer reduction and smaller active files can coexist with greater
hosting usage when backups, old generations and workspace copies remain.

Decision: a unique physical-file ledger with overlapping roles, exact logical
bytes and measured-or-unknown allocated/quota bytes. Separate active, recovery,
history, temporary peak and final total. Net reclaimed equals total before minus
total after, including operation-created audit overhead. Aliases are not extra
files; absence during interrupted purge is not a second saving.

Rejected: sum every metadata role; projected quality percentages as measurements;
equating `disk_free_space()` with hosting quota; claiming quarantine releases quota;
discarding backup cost from the final number.

Consequences: truthful UI may show negative net savings before purge. Use bounded
worst-case capacity checks before each allocation, one item at a time and a known
quota/operator limit. Lack of sufficient safe workspace means skip, never unsafe
early deletion to make room.

## ADR-08: recoverable file/database protocol with bounded concurrency

Context: file publication and SQL metadata cannot be one portable atomic
transaction. Editors/other optimizers do not necessarily respect Pixel's lock.

Historical M2 decision (superseded for locking by M5.1): shared flock, durable lease/fencing token, stage/revision CAS, intent
before effects, fresh hashes/metadata at each boundary. Reconcile before taking
over an expired item; expiry alone never authorizes concurrent workers. Resume
completes or restores an unambiguous before/after graph, otherwise NEEDS_REVIEW.

Rejected: unlink/copy replacement, lock timeout alone, blind rollback over a later
edit, infinite retry, universal atomicity or power-loss claims based on rename.

Consequences: crash injection and real filesystem/database matrices are mandatory.
Restore is scoped to affected image state, not album membership or unrelated
postmeta. A failed purge leaves a valid master and accurately reports retained bytes.

M5.1 correction: the real-host synthetic overlap invalidated filesystem flock
as universal authority. See [M5.1-HOST-AUTHORITY.md](M5.1-HOST-AUTHORITY.md).
MySQL/MariaDB connection ownership now supplements persistent M2 leases/CAS;
flock is no longer consulted. Existing intent/reconciliation rules remain.

## ADR-09: normal WordPress remains the delivery authority

Context: Albums, themes, REST, Media Library, iFolders and other optimizers have
separate responsibilities; users must not lose ordinary images when Pixel stops.

Decision: preserve native healthy sizes, URLs and ordinary attachment behavior.
Pixel manages its own resources/lifecycle only. No upload reorganization, album
ordering, private-media authorization, CDN proxy or unused-attachment deletion.
Inactive competitors are informative; actual active overlap warns/blocks without
automatic deactivation. Updates never enable replacement/purge or format conversion.

Rejected: replacing the Media Library/size system, theme/plugin-specific hardcoding,
whole-site cache purge, offload support by assumption, uninstall deleting masters
or outstanding recovery.

Consequences: compatibility gates include Pixel disabled/uninstalled and downgrade
with valid ordinary WP files. Old runtimes cannot manage new recovery state; preserve
it rather than silently delete it. Multisite destructive mode and offload are deferred.
## V1 completion addendum (2026-10-05)

The accepted JPEG authority and native-master lifecycle are preserved.
PNG uses a bounded IDAT-only lossless candidate encoder, with exact filtered
pixel and non-IDAT chunk identity, then the same jobs, authority, budget,
quarantine, restore and explicit-purge coordinator. Unsafe or unprofitable PNG
is preserved without conversion. Future PNG requires separate explicit opt-in;
an old JPEG policy never enables it during upgrade.

The storage-saver UI requires a native Media Library selection, capped at 500
IDs per plan. Selection is frozen into the existing plan policy. Trusted existing
API calls retain their explicit full-plan semantics. There is no automatic
whole-library run on activation or upgrade.

New native operation admission stops at 10,000 retained jobs. Recovery and purge
remain usable at that ceiling; history is never silently pruned. Quarantine has
no automatic destructive expiry. Explicit scans and plans remain administrator
actions, not unattended growth. Private journal filenames retain their historical
suffix for recovery compatibility; PNG content is never exposed under that suffix.

The final artifact is a private 0.4.0 candidate. Public 0.3.2 is not replaced.
Next owner validation is PDE DEV attachment 6354 eligibility/status UX; this
development lot does not access PDE or enable unattended real-site processing.
