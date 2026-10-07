# Verified WordPress updates

From 0.5.1 the built-in stable source is:
https://raw.githubusercontent.com/warzou/wp-seed-pixel/main/updates/stable.json.
The stable manifest describes the exact official release ZIP and SHA-256.
Verify the immutable asset before promoting its manifest on main.

Optional `WP_SEED_PIXEL_UPDATE_MANIFEST` in the site's trusted configuration
replaces the built-in source completely. Empty/invalid overrides disable updates.
Do not put credentials in it. No request parameter can override this constant.
Private overrides require HTTPS manifest/ZIP/notes on the same host, without
credentials, query, fragment or redirects. Stable only accepts the exact
built-in endpoint and final X.Y.Z, with repository-bound URLs:
https://github.com/warzou/wp-seed-pixel/releases/download/vX.Y.Z/wp-seed-pixel-X.Y.Z.zip
https://github.com/warzou/wp-seed-pixel/releases/tag/vX.Y.Z
ZIP validation requires PHP ZipArchive.

Official requests permit only raw.githubusercontent.com, github.com and a
single explicit 302 to release-assets.githubusercontent.com, under
/github-production-release-asset/{numeric repository ID}/{asset identifier}.
The ephemeral signed query stays in memory, never in caches/reports. Both
requests disable automatic redirects; a second redirect is rejected.
HTTP, userinfo, fragments, non-443 ports, control characters, backslashes,
unexpected paths/hosts and oversized URLs fail closed. SHA binds the redirected
bytes to the exact manifest package before any unpack/replacement.
Manifest cap is 16384 bytes: read cap+1, reject oversize and mismatched declared
Content-Length before parsing. Malformed/truncated JSON is refused. Without
Content-Length, undetectable arbitrary transport truncation is not claimed;
cap+1 detects the known oversized valid-prefix truncation case.

Schema 1 has exactly these fields: schema (1), slug (wp-seed-pixel), channel
(stable or private), version, requires, tested, requires_php, package, sha256 (lowercase),
released (YYYY-MM-DD), notes_url. Version is the WordPress plugin version, not
the private build identifier; same-version private builds require an explicit
manual installation and do not pretend to be version upgrades.

PHP orders the nonstandard `private.N` suffix after final `0.5.0`. Moving from
0.5.0-private.3/private.5 to 0.5.0 therefore uses the verified native ZIP upload
replacement, not the update row. Do not uninstall or rewrite version constants.
The standard 0.4.0 to 0.5.0 path uses the native updater when its trusted endpoint
is configured. Neither path processes media or changes stored profile approvals.

Run `tools/private-release.py --output <private-output> --tested <tested-WP>`.
An explicitly approved `--endpoint https://<host>/<path>/manifest.json` also
generates the corresponding manifest. Upload immutable ZIP and notes first,
verify the remote ZIP SHA-256, then atomically publish the manifest. Preserve
the previous manifest and package for recovery. Nothing is published by this
tool. The allowlisted ZIP excludes tests, tools, reports, media and credentials.

WordPress's update transient supplies the normal Plugins update row; the
plugin_information hook supplies compatibility and a release-notes link.
Metadata is cached six hours; failure is cached fifteen minutes. Requests use
a fixed updater User-Agent and no site/user/media/body/cookie payload.

Once the approved endpoint is configured and offers a newer plugin version,
the normal administrator workflow is:
Extensions -> WP Seed Pixel -> Mettre a jour maintenant.
Do not enable an image operation to install an update.

Manual fallback: obtain the approved runtime ZIP and verify its published
SHA-256. Take a targeted plugin/settings backup, then use Extensions -> Ajouter
une extension -> Televerser une extension and confirm replacement of Pixel.
Verify the installed version and unchanged settings. Use only the previous
verified runtime for recovery; do not uninstall Pixel or delete its data.

At `upgrader_pre_download`, fresh trusted metadata, compatibility, exact package
URL, SHA-256 and archive identity are checked before WordPress unpacks/removes
the installed plugin. Unsafe paths, links, encryption, duplicates, excess size,
wrong identity/version and missing updater are refused. Core owns replacement,
temporary backups and normal reactivation. Never bypass integrity on failure.
Restore the previous verified runtime with the standard WordPress/plugin backup
procedure if core cannot complete; no media rollback is part of this updater.

The updater does not alter format settings, future cutoff, jobs, quarantine,
schema, storage limits or automation. Activation remains the existing plugin
activation mechanism, not an image-processing operation.

Disposable tests use the actual Plugin_Upgrader replacement and reactivation,
with controlled HTTP transport fixtures for failure injection. They are not a
certification of a real HTTPS hosting endpoint. Real endpoint/TLS/update-screen
download remains an owner gate after hosting has been approved.

Runtime 0.5.1 reports version/build 0.5.1; the legacy engine identity remains
0.3.1 for generation compatibility. Updates preserve settings and do not process
media. A local transport-fixture E2E
PASS does not certify an unpublished remote distribution endpoint. Endpoint
publication is a separate owner gate.

## Bootstrap and main promotion

Published 0.5.0 does not support stable channel or GitHub asset redirects.
Install verified 0.5.1 through native ZIP replacement, or use its existing
approved same-host private feed. Do not imply that publishing stable.json
alone upgrades an unmodified 0.5.0.

Base: released 22ab28b00eedb26af73d80be3fe61cb5ac036283, not stale main.
Observed main: 3da4435591488e6089b827c7b581c706e1e4a034, behind three
commits / ahead zero. Recheck remote ancestry and divergence before promotion.
After explicit publication authorization only, fetch and verify exact refs,
then use a fast-forward-only transition, never force:

```text
git fetch origin
git merge-base --is-ancestor origin/main 22ab28b00eedb26af73d80be3fe61cb5ac036283
git switch main
git merge --ff-only codex/github-stable-updater
git push origin main
```

This brings main to the entire release line. The approved
0.5.1 commit must descend from that exact base. Publish/verify its immutable
asset and checksum before publishing stable.json with the real 0.5.1 hash.
Never add a metadata-only commit to the stale main tree. No commands in this
plan are executed by local certification alone.

References:

Local reproducibility: tests/updater-lab.sh prepares extracted WSL dependencies
without installing host packages or exposing a database TCP port. Keep its db
mode running, run updater-cycle.sh init, then matrix/transport. Generate a test
ZIP only in a fresh ignored folder. For native UI, reset stable/private with
PIXEL_UPDATE_ZIP, serve WordPress on 127.0.0.1:8877, run updater-browser.cjs
headless and updater-cycle.sh verify. The stable bridge replaces only the
0.5.0 fixture updater; private starts from the exact released ZIP. Both seed a
synthetic conversion through the real APIs and verify exact state/restore.
Run each twice on the final candidate, inspect screenshots, stop owned servers
and use the guarded cleanup mode. Never use real site credentials or media.

- https://developer.wordpress.org/reference/hooks/upgrader_pre_download/
- https://developer.wordpress.org/reference/functions/wp_update_plugins/
- https://developer.wordpress.org/reference/classes/wp_upgrader/
