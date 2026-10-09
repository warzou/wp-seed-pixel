# Metadata Public Graph Transaction - private.3 Work In Progress

## Current Gate

The read-only planner is implemented. An unregistered, disabled graph transaction
prototype now implements per-file recovery, replacement and reconciliation.
It is tested with real synthetic files but WordPress/authority/storage doubles;
the integrated SQL lifecycle and final certification are NOT implemented.
No private.3 ZIP exists. No DEV retry is safe yet. The private.2
METADATA_PUBLIC_COPY guard remains unchanged and effective.

The new planner is not registered in wp-seed-pixel.php. WRITE_CERTIFIED=false is
not a deployment switch: certification and integration of the lifecycle are
still required. Never route an admissible plan into the existing master-only
writer or treat planning assertions as transaction proof.

## Real Finding

Dedicated synthetic DEV attachment 6594 has a 640x480 JPEG master and native
300x225 and 150x150 derivatives. All three retain controlled EXIF privacy data.
Private.2 refuses before creating an anonymization job. Pixel 0.5.1 was restored
exactly. This is a generic graph-lifecycle gap, not a special attachment rule.

## Inventory And Planning

The input must be a fresh ownership-checked Master_Adapter snapshot. Reuse the
native Analyzer inventory, not a recursive uploads crawler. Before writer
integration audit native size/srcset mappings, original_image/scaled relations,
Pixel manifest/history, and format-conversion compatibility mappings. Unknown
ownership and filtered/unresolved external paths block, not silently disappear.

Metadata_Public_Graph::plan inspects every mapped file independently and returns
an approval signature binding attachment, relative paths, roles, source hashes,
classification and expected filtered hashes. Paths/roles cannot be added to an
old approved plan. No parser-output bytes, absolute paths or writes are returned.

- A: clean; keep exact bytes, do not rewrite or create unnecessary recovery.
- B: supported sensitive metadata; verified deterministic filtering required.
- C: unsafe orientation; block.
- D: provenance; block.
- E: unknown/ambiguous/unsupported; block.
- F: malformed container; block.
- G: missing/unreadable public file; block.

Mixed A/B is admissible for planning only. Any C-G blocks the operation. Byte,
file-count, path containment, source identity, shared inode and ownership guards
are checked. A graph containing only A is a no-op, not an empty recovery job.

## Required Integration - Not Yet Implemented

1. Jobs::replace_one must validate the fresh full plan under existing M5.1 SQL
   claims/CAS. No second job model, manual SQL bypass or flock authority.
2. Master_Storage must prepare an anchored per-file graph record before any
   promotion. Persist exact recovery for every B file and verified candidates
   for every B file. A files keep their existing bytes and identities.
3. Account peak storage through existing Storage_Budget, covering the entire
   public graph, originals, candidates, durable journal and restore staging.
   The current master-only peak() is insufficient for this operation.
4. Bind every graph entry to the existing item's snapshot/policy hash and its
   authoritative journal. Validate private path allowlists, hashes, permissions,
   owner, link count and storage device before each effect.
5. Journal pre/post replacement for each file. A dead process can leave a mixed
   physical graph temporarily; it must never publish an ANONYMIZED witness.
   Resume must finish the verified forward plan or restore all exact originals
   coherently under the same SQL authority. Unexpected third-party bytes stop
   reconciliation without overwriting them.
6. Observe must accept only planned before/after identities for each entry,
   reject missing/added files and preserve WordPress path/editorial state.
   Current observe() rejects every changed derivative and must be extended.
7. Verify every public file and payload/color invariant after all replacements,
   before committing the successful witness. Report metadata bytes per master,
   derivative and total, never as lossy image-compression gain.
8. Extend Quarantine retain/inspect/restore/accounting to the anchored recovery
   bundle. Current recovery.jpg-only accounting cannot certify this graph.
   A clean master with dirty derivatives requires no master recovery copy.
9. Disable graph permanent purge until a complete bundle-aware destructive
   lifecycle is designed and certified. No existing master-only purge may run
   against a graph recovery entry. This lot must execute no permanent purge.
10. Whole-graph restore verifies every original SHA, preserves filenames/ID and
    fields, and states that original private metadata returns. Re-anonymization
    must work after verified restoration.
11. UI success is conditional on the whole public graph. Disclose that private
    recovery originals retain metadata. Keep normal UI free of private paths.
12. Preserve deterministic parser, JPEG scans, PNG IDAT/color/alpha, format
    conversion policy/consent, updater and JPEG quality profiles unchanged.

## Required Certification

Synthetic native WordPress graph: dirty master, two independently dirty
derivatives, one clean derivative, supported legacy copy and unsafe legacy copy.
Admission must block C-G with zero promoted files. Clean A must remain exact.
Include a clean-master/dirty-derivative graph and an entirely clean no-op graph.

Crash injection: before first swap, after master, between derivatives, before
final verification, after files before witness, after witness before cleanup,
and during multi-file restore. Resume/reconciliation must be repeat-safe.

Exact hashes: every recovered original; every restored public file; all JPEG
compressed scans and PNG IDAT/color/alpha. No image encoder in anonymization.
Native srcset URLs and fields unchanged. Cache validation uses targeted no-cache
body SHA checks for master/derivatives, not a global cache purge.

Run all native storage/claims/quarantine/recovery, JPEG, PNG, PNG-to-JPEG,
profile, parser, metadata graph and real admin UI suites in TWO complete final
cycles on the frozen exact candidate. Current planning/parser-only cycles do
not meet that gate.

Only then build private.3 and report its bytes, SHA and runtime-file count. A
fresh AskPass authorization is mandatory before the one DEV retry on 6594.
Attachment 6354, production, public release and permanent purge remain excluded.

## 2026-10-08 Transaction Prototype Evidence

Metadata_Graph_Transaction consumes the frozen plan and rejects inconsistent
counters or a recovery manifest not matching the plan. It prepares exact copies
only for B files, deterministic candidates, a storage admission and a journal.
Before switch it checks the fresh snapshot, plan signature and all recovery and
candidate hashes. Public replacements use rename, not in-place editing.

The intended conservative policy is exact-original rollback for any interrupted
switch before durable graph_committed. After commit, reconciliation verifies the
filtered graph. Changed third-party bytes or corrupt recovery enter
graph_needs_review without automatic overwrite. User restore in this prototype
retains the private recovery evidence; integrated bundle lifecycle remains open.

Two targeted filesystem runs pass 198 assertions each, including 11 forward
interruption boundaries and 2 restore interruption boundaries. Interruptions are
exceptions, NOT process kills. SQL claims, durable journal checksum/CAS, native
WordPress fields/srcset, quarantine accounting and integrated UI are NOT certified
by these doubles. Planner123, parser336 and independent payload/color26 remain
PASS; runtime PHP lint47/47. These are NOT two complete final cycles.

At that prototype milestone the writer constant was false and the entry point
did not load this class. The subsequent runtime-integration milestone loads the
class through the official entry point and delegates Jobs/Executor/Adapter/
Quarantine/UI to the same transaction. The local source write gate is enabled
for exact-candidate certification, not an authorization to deploy it. Two
complete regressions and clean install/upgrade gates remain required. See
reports/metadata-public-graph-private3-20261008/TRANSACTION-PROGRESS.md.
