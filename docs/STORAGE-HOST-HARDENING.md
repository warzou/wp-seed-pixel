# M5.1: host ownership, capacity and future uploads

Candidate architecture, not a public 0.4 release. M6 is not implemented.

## Authoritative ownership

`Files::lock()` delegates to a MySQL/MariaDB session advisory lock. Attachment
ID scopes effects; ID 0 is the existing site coordinator. Locks do not expire
with the durable M2 lease. Old session handles and tokens cannot release or
renew a new owner. Expired rows may be reconciled only after authoritative
ownership and native/file-generation checks. SQL loss fails closed at effect
boundaries; an interrupted intent remains recoverable, not blindly rolled back.

`Job_Store::renew()` renews only the same item token and revision under the
same live session. No polling heartbeat or new lock/queue table is added.
Named locks survive COMMIT and are released in finally or by session death.
SQL and remote filesystem effects are not a portable atomic transaction.
Single-primary nonpersistent connections are the certified topology.

## Settings and accounting contract

Media > Pixel - Nouveaux fichiers exposes decimal MB (1 MB = 1,000,000 bytes).
Internal values are integer bytes. Defaults do not impose a provider quota,
ceiling, uncertainty reserve or destructive upload processing.

`wp_seed_pixel_storage_limits` stores provider quota (informational), operational
ceiling, uncertainty reserve, safety reserve and `max_usage_age` (default 60 s).
The ceiling may not exceed a configured informational provider quota. It does
not replace actual provider or physical-filesystem limits.

The trusted `wp_seed_pixel_storage_usage` filter returns:

```php
array(
    'bytes' => $complete_current_upper_bound,
    'measured_at' => time(),
    'uncertainty_bytes' => $bounded_external_growth,
    'complete' => true,
    'includes_recovery' => true,
    'live' => true,
);
```

The adapter must freshly observe its declared complete scope on each call and
include active files, native sizes, retained originals, Pixel candidates,
quarantine and other account storage relevant to the chosen ceiling (database,
logs, backups, hidden files where applicable). Provider totals are not inferred
from FTP or `disk_free_space()`. Missing/incomplete/cached/stale data means
UNKNOWN and no allocation when a ceiling is configured. No default adapter
fabricates a complete hosting-account total from the M1 media inventory.

Peak admission includes current usage + max(measured uncertainty, configured
uncertainty reserve) + safety reserve + conservative operation extra bytes.
Equality to the ceiling is blocked. Byte sums and multiplications fail closed
on overflow. Independently, physical free space must cover extra + reserve.
The existing per-operation capacity also remains mandatory. Each journal-next
allocation is admitted, including explicit restore/purge lifecycle changes.

The coordinator serializes Pixel allocations. Persistent recovery/candidates
must appear in the next live reading; cached measurements cannot act as a
reservation. Unbounded growth by unrelated writers is not covered by a finite
reserve. Host/provider-specific scope verification remains a separate gate.

Same-disk quarantine is not reclaimed space. Only a verified deletion reduces
the live account total. Purge remains explicitly approved per item; there is
no new automatic timeout or delete-first admission. If even the purge journal
cannot fit, the operation blocks until capacity is safely made available.

The historical 0.3 derivative API has no accepted M3 peak plan and therefore
refuses processing when an operational ceiling is enabled. Use the M3/M4/M5
coordinator rather than silently bypassing the ceiling.

## New uploads

Explicit modes: OFF, analyze only, process new JPEG. `configure()` stores mode,
random generation, maximum existing post ID, activation UTC, per-operation
capacity and opting-in administrator. Storage limits and mode are saved together
under site ownership and an InnoDB options transaction. Every explicit save
creates a new cutoff; historical jobs remain available for manual review.

Provenance requires the normal `wp_handle_upload` path in the same request,
an attachment above the ID cutoff and uploader/edit capabilities. Each path
is consumed once. Imports/CLI and restored records without upload provenance
are excluded. Old metadata regeneration does not enqueue old attachments.

Core creates the native image graph first. The create-metadata filter schedules
a later cron callback without changing the metadata. The worker revalidates
generation, capabilities, source bytes and the full native graph. Enrollment
and its existing M2 job ID commit together: duplicate hooks, reloads and crash
resumption cannot create a second job for that generation.

Processing uses the accepted M3 executor and M4 retained quarantine, including
immutable policy/generation, candidate verification, native CAS, rollback and
honest storage evidence. Unsupported PNG is skipped; already appropriate JPEG
is not replaced. No alternate fast destructive upload engine is introduced.

The original upload may already exceed a ceiling before Pixel runs. Pixel
cannot retroactively prevent that upload and does not delete it to finance a
candidate. It blocks the additional peak and records review separately.
Failed/skipped processing leaves ordinary native WordPress media usable.
WP-Cron must run for automatic progress; reviews/blocked operations require
the existing explicit Jobs controls, not infinite retries.
Temporary coordinator contention keeps one deferred callback, without creating
a second job or queue. Permanent evidence/database errors still fail closed;
OFF or a changed settings generation prevents deferred progress.
The accepted M1 bounded sibling inventory remains a precondition: directories
with more than its 128-entry scan limit are incomplete and processing is refused
for manual review. M5.1 does not weaken this guard or claim every hosting media
directory can be automatically processed. This is a host-pilot eligibility gate.

Legacy automatic callbacks do not execute while future mode is active. OFF or
plugin deactivation stops future callbacks without deleting attachments or
recovery; optimized media remain normal WordPress files with Pixel absent.

## Certification and limits

See `tests/m51-*.php` and `tests/m51-browser.cjs` for synthetic MariaDB contention,
lying-flock, actual SIGKILL, connection loss, uploads, ceiling and private purge.
Headless browser tests use only the disposable local WordPress. No consumer
images, credentials, hosting configuration or real-site data enter the fixtures.
The separately authorized final authority gate passes 194 checks on each real
MariaDB/MySQL lab engine and a bounded synthetic NFS retest: 2/10 requests,
all flock claims successful, exactly one DB owner per group. Twelve real-host
token/CAS/release checks pass. Dedicated test state and endpoint are removed
and verified absent. No consumer media, plugin or WordPress state is mutated.
This certifies ownership only, not image IO, quota usage, power-loss or quality.
The one-new-JPEG pilot remains a separate human decision. Evidence is in the
ignored reports/storage-m5.1/authority-gate/FINAL-REPORT.md and the authority ADR.
