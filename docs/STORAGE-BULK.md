# M5 bulk storage orchestration

Status: READY FOR REVIEW in disposable environments only. Not a release or
real-site authorization. M6 is not started; version 0.3.2/signature 0.3.1 stay.

Bulk extends the existing M2 jobs/items repository and runs the accepted M3/M4
executor one item at a time. It does not create another queue or image engine.

The plan freezes policy, operation version, inventory ceiling, eligible
attachment identities and per-item snapshots. New attachments are excluded.
Each destructive item revalidates its generation and current capacity.
Stale individual items require review without invalidating healthy peers or
increasing retryable-failure pressure. Systemic backend/disk failures pause.

Progress derives from durable item state. Active byte reduction, recovery
occupation, potential purge and verified physical removal remain separate.
Retries and resume must not encode or count completed items again.

Implemented entry points in the existing Jobs class: bulk_plan, bulk_step and
item-targeted quarantine_action. Policy bulk version 1 freezes operation, a
1-5 item lot limit, concurrency 1 and operator capacity. Existing schema 2 is
unchanged. Plans are built in 20-row steps; Start checks the existing hash chain
and clones the frozen items into the same operation table. Each step runs the
accepted executor and binds M4 quarantine before completion. No purge is implicit.

The legacy simulation AJAX adapter refuses mutation of a bulk plan/job. Only
the existing explicit localhost disposable M3/M4 opt-in authorizes execution.
There is no public REST route or spontaneous activation/upgrade/cron processing.

Ordering is eligible first, estimated peak bytes ascending, source bytes
descending, stable ID. Replacement estimate: source bytes * 4 + width * height
* 8 + 16 MiB; original retirement: original bytes * 2 + 16 MiB. These admission
estimates are frozen, not hosting quota measurements. Executor rechecks live
capacity before copying/encoding. No delete-first behavior.

## Administration

Media > Pixel - Storage saver covers analysis links, operation/policy/budget,
plan, Start, progress, Pause, Resume, Cancel, Retry failed items, audit and
review/failure reasons. Results have 20 rows; latest-job picker is bounded to 50.
Default one item/request, maximum five, concurrency one, 500 ms client delay.
POST guards enforce manage_options, nonce, item capability and generation.
Requests are serialized; pause/cancel waits for the owned request, stale UI
responses cannot update another selected job. Reload does not start work.
First-start UI does not query missing schema-2 tables. English/fr_FR labels,
status, visible focus, responsive controls and errors are tested headlessly.

Retained versions administration reuses M4 evidence and separate explicit
irreversible purge consent. Bulk restore/purge requires the exact item ID in
its owning job. Legacy job-only restore cannot pick an arbitrary first image.
Restore never encodes. Cancel preserves completed work and stops pending items
at a safe boundary, not a global rollback. Retry never repeats completed items.

## Storage truth

Explicit storage_audit uses the existing site lock, streams 20-row pages and
primes native caches. It does not rewrite journals, media or database state.
Durable per-item truth overrides stale aggregate receipts. Measurements cover
planned operational sources, not a live whole-site census.

Separate current active bytes, active reduction, quarantine, potential explicit
purge, verified removed source bytes, allocated removal blocks, audit files,
temporary escrow/candidates and net file reduction. Blocks are measured at
actual purge intent and rechecked before unlink, not inferred from logical size
or old allocation. Failed purge advances neither removal counter. Replay counts
once. Same-disk moves and retained uploaded originals are not reclaimed storage.

Pre-switch recovery/candidate copies are temporary overhead, not quarantine or
savings. Net file reduction subtracts recovery and audit costs and may be
negative after successful replacement. Unknown/foreign/corrupt/hardlinked
evidence suppresses incomplete totals: complete=false and null, shown as Unknown.
Known subtotals are separately named; verified removed-byte receipts are the
verified subset, not an implied full census. Audit does not delete unknown files.

Scope: planned_operational_sources_only. Unchanged native sizes, excluded files,
database overhead and whole-host allocation are not silently counted. Hosting
quota always remains unknown. File removal is never presented as quota recovery.

## Evidence and limits

Results use three SQL queries with WordPress options loaded; four after a full
cold WordPress cache (one bounded alloptions query). Real operation rows also
use three without per-row quarantine inspection. Whole-job physical audit is
a separate streamed operation, not a constant-three-query whole-job scan.

Final cycles: 154 integrity/crash/accounting/low-disk checks then 90 concurrency,
writer/security/UI/scale checks, same 42-file frozen runtime. Fresh 2,005-relation
case: 2,001 shared aliases excluded, two actual JPEG replacements, PNG and an
unsupported-orientation JPEG. One replacement restored, one explicitly purged.
Exact hashes, bounded memory and 101 pages verified; not 2,005 encodes.

Historical 38-check partial evidence and fresh relevant M1/M2/M3/M4/stable
regressions remain in reports/storage-m5/FINAL-REPORT.md. Local ext4/WordPress
7.1.2/PHP 8.5.4/MariaDB 11.8.6 and isolated Chromium tested. Power loss, wider/
minimum matrix and actual host SAPI/quota/caches/writers/quality remain separate.

No real site or private media is authorized by M5 local readiness.
