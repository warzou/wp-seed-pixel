# M2 persistent storage simulation

M1 and M2 are accepted, not a 0.4 release. Plugin 0.3.2, processing signature 0.3.1
and existing artifacts are unchanged. The M2 simulation contract below remains
unchanged; M3's separately authorized local real executor reuses its coordinator.
See MASTER-REPLACEMENT.md for that narrow development gate and actual evidence.
M4's separately authorized single-item lifecycle reuses the same coordinator;
see QUARANTINE-LIFECYCLE.md. The M2 simulation UI remains non-destructive.

## Entry points and frozen identity

Media > Pixel - Simulation uses the completed M1 analysis. Opening/reloading never
starts work. Build plan prepares 20 recorded analysis rows per request. Run simulation
freezes that completed plan into its own job. One item is processed per step, with
every intermediate transition persisted. Resume is explicit after browser closure,
pause or repaired systemic failure; transport failures never automatically retry.

Trusted PHP: `WP_Seed_Pixel_Policy::normalize`, `WP_Seed_Pixel_Jobs::plan`, `start`,
`step`, `status`, `results`, `control`. Admin POST actions require manage_options,
nonce and per-attachment edit permission. Clients provide enumerated commands and
IDs only, not filesystem paths, policy JSON, executor types or private diagnostics.
There is no anonymous action/public REST endpoint.

The normalized policy separates quality, dimensions, format, master replacement,
preserved-original retirement, recovery, purge, required metadata and derivative
privacy. Keep dimensions/format/master/original and no purge are defaults. Future
intent may be described, but effective replacement/retirement/purge are always OFF.
Contradictory or unsupported intents are rejected. No settings migration changes
the existing optimizer. Policy version/hash, plan hash, schema and engine signature
are frozen independently of later settings or UI/plugin version.

## Persistence and compatibility

Schema 2 lazily extends M1's two per-site tables with nullable evidence columns,
fenced revisions/leases, errors/attempts and policy/plan identities. M1's schema-1
marker and scan rows remain compatible. dbDelta migration and repeated install
are tested. The additional schema option is small and non-autoloaded; no library
JSON option. Existing `(job_id,kind,item_key)` uniqueness is retained to avoid
colliding with M1 physical-file rows. Operation keys are attachment-ID plus action,
providing per-job/attachment/action uniqueness. Indexed status, attachment/stage
and job/cursor queries serve coordination and 20-row result pages. Post capability
lookups are bulk-primed rather than one query per displayed row.

Plan identity chains immutable item payloads in cursor order. Start verifies the
chain/count using bounded PHP pages, then copies items in one database transaction;
it does not load the library into an option or HTTP response. Repeated Start of
the same plan returns the same execution. Plans count observed analysis rows,
not a scan's potentially outdated starting attachment forecast.

Job states: queued (plan preparation), running, paused, completed,
completed_errors, cancelled, failed_systemic. Item simulation path:

`queued -> preparing -> ready -> switch_intent -> switched -> verified -> retained`

Failures are failed/needs_review/skipped; post-intent interruptions can require
recovery_required. All stage names describe simulation, never a replaced master.
Purged/rolled_back are reserved terminal vocabulary, not available operations.
Completed items cannot transition or re-enter the runner.

## Locks, failures and deterministic recovery

A site worker database-session lock serializes steps/controls (M5.1 supersedes
the historical flock authority after real-host synthetic overlap). Each attachment
uses the same DB authority, plus a durable random fencing token, expiry and revision CAS.
Expired leases alone cannot steal a live DB session lock. An incomplete item
continues reserving its attachment across jobs, including while paused. External
WordPress editors/optimizers need not honor these locks: no universal concurrency
guarantee is claimed. A crashed process releases the DB session, not its durable evidence;
after lease expiry, explicit Resume validates/reconciles that evidence.
See [host hardening](STORAGE-HOST-HARDENING.md) for supported topology, stage-boundary
renewal, operational ceilings and controlled future-upload enrollment.

Intent is persisted before invoking the executor. The default simulated executor
has deterministic effect keys and `execute`/`reconcile` boundaries. It only returns
small simulated receipts; the coordinator persists a whitelist, not arbitrary
adapter strings/paths. There are no hidden encode/replace/delete methods. M3 must
add a separately authorized executor, real effect reconciliation, private filesystem
manifests/escrow and source/metadata/file-graph gates before any destructive kind.
Simulation success does not prove real rename, escrow or power-loss safety.

Machine errors distinguish retryable, conflict, review, unsupported and systemic.
Systemic errors stop; two consecutive item failures pause future work. Retry
requeues only eligible failures, at most three retries, preserving successes.
Post-intent reconciliation is bounded; inconsistent evidence requires review.
Cancellation preserves completed items, cancels queued ones and refuses to hide
an incomplete intent. Controls serialize at safe item boundaries.

Per-item checksummed journals retain at most 32 transitions plus dropped count,
from/to stages, revision, attempt, machine code and time. Job revision/status/time
record current controls; this is a bounded operation journal, not a full admin
click log. Evidence contains no pixels, private metadata, credentials or absolute
paths. Recovery here means persistent logical simulation, not image rollback.

## Analysis, eligibility and accounting

One eligibility function is used by plan and execution. M1 review/unsupported,
missing/offloaded/ambiguous cases are excluded. M2 simulates ordinary local JPEGs;
ICC/rotation and PNG processing remain gated. Source metadata/stat identity and
a bounded operational SHA-256 are frozen/rechecked, including equal-size/equal-mtime
changes. This is not yet M3's complete ownership/reference/hash protocol for every
derivative and companion. Capacity/peak/quota are unknown; destructive_ready=false.
Planned original bytes are conditional/non-additive, never reclaimed bytes.
Actual reclaimed, encoded and deleted by the M2 executor are always zero.

Explicit trusted-PHP retention removes only old terminal simulation records with
no incomplete, failed, review or leased items. It never removes scan/plan/active
or recoverable records, media or recovery files. No automatic schedule/UI purge.
Old plugin versions cannot manage M2 jobs; normal WordPress media remains native
and untouched. Existing uninstall behavior retains these tables.

## Certification and repeatability

Disposable WordPress 7.1.2 / PHP 8.4.23 / Windows / SQLite 3.0.2, GD/EXIF.
`tests/jobs-run.ps1` initializes the disposable runtime and runs M1 and M2 core;
`jobs-faults.php` injects SQL/source/permission failures; `jobs-recovery.cjs` kills
real PHP processes at five stages and tests expired-lease/live-flock exclusion.
`jobs-browser.cjs` runs isolated EN/FR headless QA. Test bootstrap refuses a real
site. Fixtures/legacy regressions create images before separate M2 invariance
proofs; the M2 executor never creates or changes canonical image bytes/metadata.

Local evidence: reports/storage-m2. Large case: 2,008 relations (mostly shared,
correctly excluded), 101 pages; not 2,008 eligible independent photographs.
PHP 8.1 / WordPress 6.6 / Linux / MySQL / real multisite/offload / Imagick and
physical power-loss behavior remain untested. M1 and legacy regressions are
separate evidence, not consumer-site or destructive-operation certification.

STOP for Guillaume + ChatGPT M2 review before M3.
