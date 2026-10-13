# Migration evidence API - WP Seed Pixel 0.7.0

Migration remains an explicitly authorized operator procedure, not permission to
clone a live site automatically. Independent-target binding and exact restoration
cover metadata JPEG/PNG graphs, ordinary JPEG/PNG replacements, preserved-original
retirement, explicit PNG-to-JPEG conversion, M5 items, and terminal histories.
Adaptive manifests/history without recovery jobs are projected without encoding.
Existing engines retain their generation identity. Immutable published 0.6.1
artifacts are not replaced. Upgrade rc.2 to 0.7.0 without uninstalling or resetting
storage options; an already verified binding remains in its existing epoch.
Do not downgrade a bound installation to a runtime lacking migration verification.

## Implemented

`WP_Seed_Pixel_Migration_Evidence` inspects a quiescent source under the existing
SQL authority and exports an immutable transport package outside all supplied
web roots. It copies bytes, never encodes images or modifies source SQL/journals.
The caller must provide the complete list of web roots from trusted hosting
inventory; the API cannot discover every virtual host on a shared account.

The package contains exact source storage evidence, Pixel job/item rows, current
public snapshots, journals and original files. It is confidential: keep it in
0700 directories with 0600 files and never put its contents in logs or a public
download. Its SHA must be supplied separately from a trusted source. SHA proves
integrity relative to that trusted value, not the identity of an untrusted sender.

Transport states: SOURCE_VERIFIED, EXPORTED_UNBOUND, VERIFIED_UNBOUND,
AWAITING_DESTINATION_BINDING. None alone means a target recovery may be restored.
Binding v2 additionally verifies upload-directory identity, creates a new storage
epoch with exact option CAS, stages immutable source evidence and separate target
journals, then commits native references and operation snapshots transactionally.
Only BOUND_VERIFIED authorizes the existing restore lifecycle. Interrupted binding
remains migration_pending and blocks normal processing until exact resume/read-back.
Unknown engines, active jobs/leases, operational review, duplicate retained owners,
SQL evidence conflicts, pending legacy workspaces, symlinks/hardlinks, missing
or altered originals are refused. Expired terminal tokens are preserved, never
cleared. An unstarted rejected plan with a null snapshot and no recovery directory
can be transported unchanged; it remains rejected, not a newly valid recovery.
Completed simulations are transported only with zero-effect receipts, never as
physical recoveries. Source journals, candidate hashes, profiles and witnesses
remain authenticated against the immutable source archive during target loading.

Binding resumes at its certified staged/SQL-committed checkpoints. An interrupted export leaves private evidence
without a valid manifest; retain it for investigation and start a new export only
after reviewing the cause. No automatic deletion or retry.

## Reusable commands

Run through WP-CLI with an explicitly authorized administrator on an isolated
installation. WordPress bootstrap can itself run hooks: freeze cron, outbound
mail/HTTP and processing BEFORE bootstrapping a clone. These commands do not
replace the infrastructure preflight or copying of WordPress/uploads.

```sh
wp --path=/srv/source/web --user=1 seed-pixel migration-evidence inspect
wp --path=/srv/source/web --user=1 seed-pixel migration-evidence export \
  --parent=/srv/private-transfer \
  --webroots='["/srv/source/web","/srv/target/web"]'
wp --path=/srv/target/web --user=1 seed-pixel migration-evidence verify \
  --bundle=/srv/target-transfer/transfer-EXPORTED_ID \
  --sha256=TRUSTED_SOURCE_MANIFEST_SHA256 \
  --webroots='["/srv/source/web","/srv/target/web"]'
wp --path=/srv/target/web --user=1 seed-pixel migration-evidence assess-target \
  --bundle=/srv/target-transfer/transfer-EXPORTED_ID \
  --sha256=TRUSTED_SOURCE_MANIFEST_SHA256 \
  --webroots='["/srv/source/web","/srv/target/web"]'
```

`assess-target` verifies independent WordPress path/database authority, unchanged
transported Pixel rows and public file bytes. It does not certify target native
references, URL projections, or an operational public graph. Preserve the copied
source evidence unchanged: copied recovery options should continue to fail the
normal IDENTITY_CHANGED guard rather than point at the source store.

## Binding protocol

1. Freeze both installations; export a consistent source and authenticate its
   digest. Hold source authority while exporting; keep a complete site backup too.
2. Create a target SQL authority and fresh private storage through a migration
   lifecycle, not by deleting copied options or bypassing Recovery_Setup.
3. Preserve the original package/journals verbatim. A separate destination binding
   certificate references the package SHA, original journal SHA, mapping version,
   target context, and observed identities of each physical file.
4. Use engine-specific adapters for JPEG master replacement, preserved original,
   PNG-to-JPEG conversion, and metadata public graphs. Map structured paths/URLs,
   native serialized rows, aliases and graph signatures explicitly. Do not use
   recursive global string replacement, or rewrite GUIDs blindly.
5. The existing Job Store owns the migration operation, its frozen policy,
   revisions, leases, journal/receipt and authoritative SQL CAS. Never reinterpret
   a source lease as a target lease or forge old quarantine identity evidence.
6. Runtime loaders must distinguish immutable source provenance from certified
   destination operational projections. After exact CAS/read-back, certify all
   native references and public resources, then test official restoration on the
   target. Unknown mapping or interrupted binding remains MIGRATION_NEEDS_REVIEW.
7. Only after all engine/restore/crash tests pass may the target binding be
   declared ready. Keep source/target isolated and archives until acceptance.

Conversion binds both compatibility PNGs and the current JPEG graph, with new
target backup/public identities and unchanged source originals and hashes.
Purged histories remain non-restorable. Their expected absences are verified;
old source allocation measurements are archived, not claimed as target measurements.
Restored history never becomes retained. A fresh scan/plan is required before new
processing; transported historical analyses are not proof of current eligibility.

The explicit CLI mutation is `seed-pixel migration-evidence bind --bundle=...`
with `--sha256=... --webroots='["/srv/target/web"]' --confirm`. The clone must
also supply `--document-root=/srv/target/web` from verified host configuration when
CLI does not receive DOCUMENT_ROOT. The command refuses guessing this value.
The clone must
retain the exact source recovery option until enrollment. Never bootstrap an
unprotected clone or clear the source identity to force prepare().

The v2 source package captures the upload-directory dev/ino/uid. Target binding
rejects shared/nested upload roots, overlapping WordPress roots and recovery
directories occupied by another owner. Same database-name/prefix authority is
conservatively refused even across hosts when independence cannot be demonstrated.

## Compatibility

Reads schema 2 jobs and M1 scan history, preserving source rows and journal bytes
in the archive. Target operation revisions advance by exact CAS. Active leases,
incomplete operations and post-mutation review must be reconciled on the source
by its official lifecycle before export, never by rewriting SQL to pass a guard.
Multiple retained recoveries on one attachment are explicitly outside coverage;
they are refused, not automatically declared corrupt. Independent Linux single-site
targets are certified; in-place domain changes and real UID/filesystem changes
remain separate gates. Do not infer live PDE readiness from synthetic tests.
Multisite/Windows recovery preparation remains outside the accepted host contract.
Legacy host-managed roots without the verified Recovery_Setup option are not
admitted by this migration protocol. They are not reset or silently enrolled.

The CLI callback is tested with native WordPress plus a CLI facade; a full WP-CLI
binary end-to-end invocation is still a separate integration gate.
