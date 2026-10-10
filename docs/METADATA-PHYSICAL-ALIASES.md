# Metadata physical aliases - local 0.6.1 candidate

Scope: metadata-only operations. JPEG master replacement retains its alias
refusal. No schema, policy, public API or recovery journal version is changed.

The adaptive engine legitimately returns a `kind=master` display resource when
a separate JPEG would not improve transfer/quality. Analyzer already deduplicates
the physical inventory and merges operational, WordPress-size, current-Pixel and
historical-Pixel roles. Public graph planning operates once on each physical
entry; resource names and paths remain in the frozen native/Pixel SQL rows.

Metadata admission now explicitly opts into this graph. It requires healthy
inventory, unique SQL rows, exclusive ownership, exact resource SHA/bytes and
matching dimensions for current master aliases. A stale or conflicting witness
still fails closed. Hardlinks, symlinks, unknown ownership and unsupported
metadata do not gain authority through this change.

After metadata removal, native file sizes and Pixel manifest/history integrity
witnesses change in the same existing InnoDB CAS transaction. Reference changes
are derived from the frozen pre-operation rows and graph plan, not trusted as an
independent mutable payload. Paths, generation identifiers and other fields stay
unchanged. Historical source hashes are updated only when they actually witness
the current master. Restore recovers the exact original serialized rows.

During a bounded transaction, observe accepts only the frozen before or derived
after witnesses. Durable committed/restored verification additionally requires
the corresponding exact SQL state. Authority, claims and the recovery lifecycle
are retained; NEEDS_REVIEW is never deleted or force-cleared.

## Targeted test gates

- `tests/metadata-physical-alias.php`: real Analyzer, file guard, parser, planner
  and adapter, with an explicitly labelled SQL CAS double. Tests same/distinct
  view, multiple roles and derivatives, no-op clean graph, exact restoration,
  conflicting witnesses, foreign SQL mutation, failed transaction, authority,
  duplicate rows and cross-attachment references.
- `tests/metadata-alias-wp.php`: disposable native WordPress/InnoDB gate, existing
  jobs/claims/whole-graph restore, synthetic attachments only. Must pass before
  recommending any DEV retry. Run with the existing `metadata-lab.sh php` harness.
- Existing parser, security, public graph, runtime admission, transaction and
  interruption tests remain required according to the affected boundary.

No site-specific IDs, media, credentials or bypass path belong to this plugin.
The candidate must not replace the published 0.6.0 ZIP or stable manifest.

## Local certification, 2026-10-10

Native WordPress 7.1.2, PHP 8.5.4 and MariaDB 11.8.6 laboratory: alias/claims/SQL
56, public graph 118, admission 502, pipeline 107, runtime states 25, generic
SIGKILL recovery 172, alias-specific SIGKILL recovery 73 assertions passed.
The five alias boundaries cover partial rename, pre-commit, post-SQL, durable
commit and interrupted restore, with natural lease expiry and exact/idempotent
restoration. Existing filesystem transaction suite: 198 assertions passed.
PHP 8.4.23 deterministic alias/CAS, parser, security and graph-plan tests also
passed. Evidence remains outside Git in reports/metadata-alias-0.6.1-20261010.
