# M4 private quarantine, restore and explicit purge

M4 is a local review candidate, not a 0.4 release or real-site authorization.
M1/M2 and the human-reviewed M3 foundation remain separate historical milestones.
Plugin 0.3.2, processing signature 0.3.1 and accepted distribution artifacts are
unchanged. See ignored `reports/storage-m4/FINAL-REPORT.md` for actual evidence.

## One coordinator

The existing M2 job/item tables, immutable policy, attachment/site flock, leases,
revision CAS and bounded checksummed journal remain authoritative. No quarantine
queue, cron, automatic retirement, time-based purge, bulk or M5 adapter is added.
M3 encoding is unchanged; quarantine lifecycle operations never encode images.

All execution remains deliberately gated to localhost WordPress on Linux,
`WP_SEED_PIXEL_M3_TESTING=true`, `WP_SEED_PIXEL_M4_TESTING=true`, InnoDB and a
private same-device recovery root outside webroot/uploads. This is a disposable
development capability, not a flag to enable on a real site. Unsupported Windows,
offload, multisite, shared/edited/incomplete graphs or storage uncertainty stop.

Trusted PHP entry points:

- `Jobs::quarantine_action(job_id, 'retain')` enrolls a verified M3 recovery copy.
- `Jobs::retire_original(attachment_id, capacity_bytes, true)` plans retirement
  of a separately recorded WordPress uploaded original, not the operational JPEG.
- `Jobs::step(job_id)` runs that one item using M2 and `Original_Executor`.
- `Jobs::quarantine_action(job_id, 'restore')` restores the exact known source.
- `Jobs::quarantine_action(job_id, 'purge', approval)` is a distinct irreversible
  action. Approval has the server-defined authorization version, current generation
  fingerprint and strict boolean `irreversible=true`.

Opening admin, analysis, a plan, status/results, reload, activation or a cron does
not approve or execute purge. Existing job HTTP actions cannot execute real
replacement/retirement; the narrow quarantine POST handler only operates on an
already existing verified item. Clients supply IDs/enumerated operations, never
paths, byte counts, a policy or an executor.

## Frozen ownership and evidence

The checksummed private journal is bound to the M2 item snapshot and job policy.
The source is an immutable exact SHA-256/byte-count/native-row snapshot. After
verification, quarantine records device/inode, source hash/size, kind and creation
time. Its descriptor hash is anchored independently in M2's checksummed journal
through a fenced transition. A private-manifest rewrite cannot silently replace
that identity. An interrupted enrollment is unavailable until explicitly resumed.

The sole recovery pathname is fixed inside its owned 0700 operation directory.
Source/quarantine must be regular, unshared and non-symlinked. Hash, size, owner,
inode and device are rechecked before destructive removal. Journal temporary
creation is exclusive: preexisting `journal.next` or a hardlinked journal is not
overwritten. Unknown or reappeared bytes remain untouched and require review.

Capability and the live M2 fence are checked on mutating helpers and again before
critical removal/publication. Wrong nonce, generation, policy/schema, attachment,
stale native rows, another claimed job or expired/stolen lease refuse safely.
There is no public REST route or anonymous destructive action.

## Separate WordPress original

For a healthy big-image upload, `_wp_attached_file` and metadata `file` identify
the operational scaled image; metadata `original_image` identifies a different
uploaded file. M4 never infers ownership from `-scaled` or another filename suffix.

Retirement sequence:

1. Freeze the native graph, exact original identity and explicit acknowledgment
   that arbitrary external uses of the old original URL cannot be discovered.
   The bounded JPEG original must decode correctly and stay within 16 megapixels;
   larger/unsupported originals require review, not an unbounded allocation.
2. Search known content/excerpt, metadata and option references. A failed query
   is not absence of references. Known live references block retirement.
3. Reserve operator-declared capacity plus actual filesystem free space, and
   copy/verify a private escrow before any native metadata change.
4. Reconcile only `original_image` and the owned witness while the original still
   exists. The operational image/getters already provide a valid native fallback.
5. Recheck native state, reference searches, source/escrow identity and fence;
   move the exact original into the private quarantine and sync both directories.
6. Verify operational bytes, native full-image URL/dimensions and HTTP, then bind
   retained identity in M2. No same-disk source saving is claimed.

Restore copies privately, verifies bytes and metadata ownership, then publishes
the original exclusively: a competing file is never overwritten. A journaled
temporary hardlink is reconciled after an actual process death. Exact original
bytes precede restoration of `original_image`; the redundant private copy is
consumed only after native before-state verification.

The old original direct URL intentionally stops resolving after retirement.
It is not redirected or replaced by a delivery proxy. Public operational URLs,
healthy native sizes/srcset and the attachment remain valid without Pixel. Later
native sizes/editing use retained resolution; purging a larger original permanently
removes its future resolution. Native WordPress edit backups are not Pixel rollback
and are neither purged nor falsely advertised as restoring a purged upload.

## Permanent purge

Purge is not retirement, restoration, attachment deletion or encoding cleanup.
The current native after-state/witness and recovery identity must still be known.
Known newly introduced original references block its purge too.

Persist M2/private purge intent and the explicit generation/actor authorization
before unlink. Recheck source/quarantine/fence. Unlink only `recovery.jpg`, sync
its directory, prove absence, persist the private completed audit, then CAS M2 to
`purged` with one physical-removal receipt. Both physical absence and persistent
completed state are necessary before displaying completed removal bytes.

If death/SQL/journal failure occurs after deletion but before completion, the
durable intent reconciles absence without deleting twice or inventing a restore.
Unknown reappearance refuses. Replay requires the same explicit approval and
does not add bytes again. No restore is available after purge or purge intent.
Audit/job records are retained; simulation pruning/uninstall cannot erase them.

## Truthful accounting

Per-item live views distinguish operational reduction, quarantine bytes,
conditional purge bytes, physically removed source bytes, audit/temporary file
bytes, file-only net delta, and actual rollback/purge availability. Missing,
corrupt or externally changed evidence is review, not successful savings.

Same-disk conservation remains occupied storage. Replacing a master while keeping
its old bytes usually increases total files; moving an original saves zero source
bytes. Purge deducts remaining audit/temporary files. A newer unknown active
generation invalidates the attributable net calculation, not the historical fact
of a known source removal. Values are not sums over overlapping media roles.

The read-only storage dashboard summarizes the latest 20 operation records only;
this is explicitly not a complete library/quota total. Per-item measured logical
file bytes exclude database overhead. `allocated_bytes` and `quota_bytes` remain
unknown in the API: local ext4 block measurements are test evidence, not portable
hosting billing claims. Capacity budgets are conservative reservations, not a
continuous process-RSS or provider-quota measurement.

## Minimal administration

Media > Pixel - Retained versions uses native WordPress forms, EN/FR labels,
capability/nonce guards and an explicit irreversible acknowledgment. Restore and
Delete permanently are distinct commands and work without JavaScript. Purged,
missing, stale or corrupt copies do not expose a misleading Restore command.
Opening this screen performs bounded read-only inspection, never processing.
No M7-wide Media Library redesign is included.

## Durability and certification limits

Files and journal temporaries use flush/fsync; M4 syncs affected directories after
rename/unlink. [PHP fsync](https://www.php.net/manual/en/function.fsync.php) and
[Linux fsync](https://man7.org/linux/man-pages/man2/fsync.2.html) distinguish file
and directory durability. Actual SIGKILL and injected SQL/journal failures are
tested. They do not certify physical power-loss behavior, controller caches,
another filesystem, hosting ACL/SAPI, cache/CDN invalidation or an uncooperative
writer changing a pathname between the final check and the kernel operation.

Further WP/PHP/database/backend matrices and real hosting readiness remain explicit
pre-pilot gates. Minimum-version lint is not runtime certification. Real sites,
real images, production enabling and bulk require separate human authorization.

M5 may reuse the operation identities, states, receipts and lifecycle entry points;
it must not invent another queue or silently enable a destructive job adapter.
M5 is not started. STOP for Guillaume + ChatGPT review of M4 and remaining gates.
