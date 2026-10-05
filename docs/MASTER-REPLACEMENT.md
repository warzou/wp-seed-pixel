# M3 single-JPEG master replacement

M1 and M2 are human accepted. M3 is a separate local review candidate, not a
0.4 release or a real-site authorization. Plugin 0.3.2 and processing signature
0.3.1 are unchanged. M4 is a separately authorized local lifecycle candidate in
[quarantine lifecycle](QUARANTINE-LIFECYCLE.md). See ignored reports/storage-m3 for actual
certification and limits; historical M1/M2 reports retain their original status.

## Explicit entry and supported storage

`WP_Seed_Pixel_Jobs::replace_one(attachment_id, intent, capacity_bytes)` freezes
one validated attachment snapshot into M2's existing job/item tables. `step`
executes its durable stages; `restore_master(job_id)` is the scoped recovery
entry. This is trusted PHP development code, not a public/admin/cron destructive
action. HTTP actions cannot execute a real replacement job. No automatic start,
new queue, bulk, original retirement, purge, PNG mutation or format conversion.

Execution requires Linux, local WordPress, a localhost URL, an explicit
`WP_SEED_PIXEL_M3_TESTING=true`, and `WP_SEED_PIXEL_RECOVERY_ROOT` outside both
webroot and uploads. That private root and operation directories require 0700,
no symlink, the same device as the operational master, and a known operator
capacity limit. Core metadata and M2 tables must use InnoDB. Non-local storage,
multisite, edited backups, hard/shared paths, incomplete inventories, stale or
ambiguous native graphs, and a Pixel master alias require review/refusal.

The capacity limit is supplied by the authorized local caller, not inferred
from hosting free space. Free space is a second independent gate. Peak reserve
is `4 * source_bytes + 8 * pixels + 16 MiB`; it is a conservative allocation
budget, not measured quota or advertised saving.

## Responsibilities and mutation points

`Master_Processor` only creates/verifies a private candidate. It uses native WP
image editors, JPEG Q98 then Q100 if needed, original-source-independent attempts,
no upscaling, SSIM >= 0.995, RGB PSNR >= 40, and gain >= max(4096 bytes, 5%). The
metric is sampled and is not a perceptual guarantee. Required APP1-APP15/COM
metadata, including rights/ICC, is preserved byte-for-byte. RGB ICC requires a
demonstrated Imagick/LittleCMS backend; GD-only RGB is supported without ICC.
CMYK/grayscale and non-1 EXIF orientations refuse safely. Downsizing EXIF/XMP with
dimension-bearing semantics is gated rather than rewriting metadata heuristically.

`Master_Storage` owns a checksum journal, verified immutable recovery input,
candidate, same-device atomic rename, and verification. `Master_Adapter` resolves
native attachment identity/relationships and narrowly reconciles native metadata
plus `_seed_pixel_master_state`. `Master_Executor` plugs those operations into
M2's locks, leases, CAS and journals. A verified recovery input precedes encoding;
the encoder never reads a mutable canonical path. Source and protected native
rows are rechecked before publication and inside metadata reconciliation.

Only `Master_Storage::replace` renames candidate/restore bytes over the existing
canonical pathname. It never unlinks the canonical image, preserves mode/owner/
group, and verifies bytes afterwards. Metadata transactions preserve raw protected
rows and all unrelated native keys; only width/height/filesize and the owned
master witness change. No attachment post, content, membership or ordering write.
Generic low-level arbitrary-path publication is private, not an admin API.

Candidate/header writes and owned invalid-candidate cleanup are the other file
mutations. Journal publication is rename of `journal.next` to `journal.json`.
Escrow copying uses exclusive creation, flush/fsync and exact SHA-256. Copies
are never deleted as an original-retirement shortcut. Failed/no-benefit work may
retain an escrow: its actual bytes remain reported, not silently reclaimed.

## Native WordPress contract

Operational master URL/path stays identical. Direct stored URLs continue to
resolve, although old explicit width/height in arbitrary content are not rewritten.
All healthy native sizes and `original_image` references are retained. A required
size larger than the planned master blocks downsizing. In the big-image case,
only the operational `-scaled` file is replaced; the separate uploaded original
is never changed/deleted. Native image getters, REST, srcset, editing and later
size generation continue from ordinary files. New sizes cannot exceed retained
resolution; this limitation is deliberate and must be explained before a pilot.

Pixel deactivation/removal needs no delivery proxy. Uninstall keeps recovery
evidence if any master witness or M3 replacement job exists, including before the
first witness. This deliberately conservative stop is not a permanent quarantine
product. New core edits/metadata changes block an old operation's rollback rather
than overwriting newer work.

## Recovery decision table

| Observed state | Next action |
| --- | --- |
| Original bytes, no journal/candidate yet | Validate snapshot and prepare. |
| Original bytes, checksum intent/escrow known | Continue candidate preparation. |
| Original bytes, candidate identity and escrow exact | Revalidate all guards, then swap. |
| Candidate bytes, old native row/witness | Reconcile narrowly, then verify. |
| Candidate bytes and exact new native row/witness | Verify without re-encoding; retain. |
| Rollback intent, known original/candidate bytes | Explicit restore; complete old metadata and verify. |
| Already rolled back | Idempotent no-op; never overwrite a newer generation. |
| Expired lease but live OS flock | Refuse takeover. |
| Unknown bytes, foreign native edit, bad journal/escrow or unidentified candidate | Preserve evidence and REVIEW/BLOCK; no guessed rollback. |

Filesystem publication and SQL cannot be one atomic transaction. Durable intent
precedes effects and recovery understands their narrow divergence window. These
tests certify process interruption, not sudden power loss, directory durability,
all filesystems/DBs, or hostile writers ignoring locks in every timing window.
File fsync is used where available; directory-fsync/power-loss guarantees remain
uncertified and cannot be advertised.

## Accounting, QA and reproduction

Results distinguish measured current master from a historical verified candidate.
After rollback active delta is zero, not the candidate's former gain. Recovery
bytes are measured even for unsuccessful work. Reclaimed bytes remain zero:
retained escrow and journal consume space; M3 cannot claim net quota release.
M1 remains read-only and observes native graphs without M3 fixture hardcoding.

Owned synthetic fixtures are generated with make-fixtures.py, icc-fixtures.py and
m3-fixtures.py. The Linux scripts build a disposable environment under
`~/.cache/wp-seed-pixel-m3-environment`, extract dependencies without global
installation, and start socket-only MariaDB plus localhost PHP. The current WSL
helpers document the Ubuntu PHP 8.5 ABI and Windows checkout path; adapt those
test-only paths for a different developer. No runtime plugin dependency is added.

Run m3.php, m3-faults.php, m3-native.php, m3-recovery.cjs and m3-browser.cjs only
there. Crash hooks are deterministic SIGKILL boundaries, actual OOM, and rollback
interruption; a synchronized second process holds the OS lock past lease expiry.
The low-disk test mounts/removes a private 96 MiB loopback volume, never a real
disk. Headless Chrome uses its own context/profile, never the owner's browser.

Useful results/captures are kept under ignored reports/storage-m3. Stop owned
servers, unmount owned test volumes and remove the disposable runtime afterwards.
Minimum versions, Windows destructive rename, other DB engines/filesystems,
multisite/offload, hosting caches and human visual outliers need separate gates.
M4 may rely on verified retained state and exact scoped recovery; it must still
design the user-facing quarantine/original-retirement/purge authorization.
