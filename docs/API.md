# PHP API

## Admin adapters (0.3.0)

The trusted optimization contracts are unchanged. Selected jobs use
`WP_Seed_Pixel_Batch::start($preset, true, $ids)` with 1..1000 editable attachment
IDs. HTTP routes still require capabilities and nonces. An authorized admin
response may include escaped presentation HTML, never filesystem paths.

Call after `plugins_loaded`. All entry points are trusted PHP, not public HTTP.
Return values are a structured array or `WP_Error`.

```php
$result = wp_seed_pixel_optimize(123, 'balanced', false);
if (is_wp_error($result)) {
    // Record the non-sensitive error code; do not silently retry forever.
} elseif ($result['status'] === 'success') {
    $thumb = wp_seed_pixel_get_derivative(123, 'thumb');
}
```

`force=true` regenerates from the original, not from an existing derivative.
`false` returns the verified current generation unchanged for matching master and
configuration. `skipped` reports unsupported MIME/color/profile or exclusion.
Errors include unsafe/missing path, absent metadata, budget limits, busy lock,
editor failure, modified source, metadata filter mutation and concurrent updates.

The success result includes `attachment_id`, `preset`, `generation`,
`master_sha256`, `master_bytes`, `config_sha256`, `files`, `added_disk_bytes`,
`seconds` and `created`. Each file includes local path (trusted PHP only), SHA,
width, height, bytes, quality, backend and transfer savings relative to master.
Negative savings mean a larger file, not a bug hidden by clamping.

0.2.0 also reports `strategy`, `algorithm_version`, `candidates`; resource entries
include `kind`, `reason`, `metric`, `soft_target_exceeded` for Balanced. A resource
can be an existing safe MASTER (`kind=master`, quality null), not a generated JPEG.
`files` therefore means served resources; added disk bytes exclude master reuse.
Native image sizes/srcset and the existing getter work for both kinds. The getter
does not hash a MASTER on every frontend request. Processing still hashes before
and after. Legacy manifests without `kind` remain readable. No automatic upgrade
regeneration. API default `web` stays fixed for signature/behavior compatibility;
select `balanced` explicitly. Registering a custom `balanced` remains fixed.

```php
wp_seed_pixel_register_preset('catalogue', array(
    'sizes' => array('preview' => array('width' => 960, 'height' => 960, 'quality' => 84)),
    'metadata' => 'strip_sensitive',
    'color' => 'preserve',
    'upscale' => false,
    'format' => 'image/jpeg',
));
```

Names are restricted; 1..4 sizes, integer dimensions 1..8192, quality 1..100.
Unsupported policies are rejected, not silently approximated. Register custom
presets on every request/cron context where they may be consumed.

## Hooks

- Filter `wp_seed_pixel_exclude(bool, attachment_id, preset_name)` before decode.
- Action `wp_seed_pixel_before(attachment_id, preset_name)` under the lock.
- Action `wp_seed_pixel_after(attachment_id, result)` after verified commit.
- Action `wp_seed_pixel_error(attachment_id, WP_Error)` for a failed job.
- Action `wp_seed_pixel_checkpoint(phase, attachment_id)` for diagnostic/test
  observers; phases `before_publish`, `before_commit` and `after_native_commit`. Never alter image data
  or metadata from an observer in production.

Do not recursively call the same attachment from a hook. The lock refuses it.
Observers must not throw or perform expensive/network work. A completion observer
exception after commit returns success with a warning; it does not remove files.

Native rendering works with `wp_get_attachment_image($id, 'seed-pixel-thumb')`
and WordPress responsive image APIs. Only helpers explicitly selecting these
sizes guarantee their use; the plugin does not rewrite themes or gallery markup.

## Explicit history pruning

`wp_seed_pixel_prune_history($id, true)` is a trusted operator action AFTER a
backup and review of old externally stored/cached URLs. Confirmation defaults to
false. It removes only hash-matching owned files with no WordPress metadata,
content or option reference and returns the number of retained generations.
Changed/shared files remain. Twenty retained generations block further forced
regeneration instead of growing forever. No global cache is purged by Pixel.
