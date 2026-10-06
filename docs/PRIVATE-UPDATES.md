# Private WordPress updates

No endpoint is shipped or silently enabled. The owner must first approve an
HTTPS host and release workflow. Local commits do not publish this private candidate.

Configure `WP_SEED_PIXEL_UPDATE_MANIFEST` in the site's trusted configuration.
Do not put credentials in it. No request parameter can override this constant.
The manifest, ZIP and release notes must use HTTPS, the same host, no embedded
credentials, query string or redirect. ZIP validation requires PHP ZipArchive.

Schema 1 has exactly these fields: schema (1), slug (wp-seed-pixel), channel
(private), version, requires, tested, requires_php, package, sha256 (lowercase),
released (YYYY-MM-DD), notes_url. Version is the WordPress plugin version, not
the private build identifier; same-version private builds require an explicit
manual installation and do not pretend to be version upgrades.

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

Runtime 0.4.0 has a separate private build identifier. PDE DEV deployment is
targeted and backed up; settings are preserved. A local transport-fixture E2E
PASS does not certify an unpublished remote distribution endpoint. Endpoint
publication/configuration is an owner gate, not a code-freeze blocker.

References:
- https://developer.wordpress.org/reference/hooks/upgrader_pre_download/
- https://developer.wordpress.org/reference/functions/wp_update_plugins/
- https://developer.wordpress.org/reference/classes/wp_upgrader/
