<?php
require __DIR__ . '/runtime.php';
$before = json_decode(file_get_contents(dirname(__DIR__) . '/.runtime/storage-ui-before.json'), true);
$id = $before['png'];
if (hash_file('sha256', get_attached_file($id)) !== $before['png_sha'] || get_post_meta($id) !== $before['meta']
    || get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true)) { throw new RuntimeException('Existing PNG changed or enrolled'); }
if (WP_Seed_Pixel_Future_Uploads::settings()['formats'] !== array('png') || WP_Seed_Pixel_Future_Uploads::settings()['mode'] !== 'process') { throw new RuntimeException('UI PNG policy did not persist'); }
if (is_wp_error(WP_Seed_Pixel_Recovery_Setup::verify()) || !WP_Seed_Pixel_Quarantine::enabled()) { throw new RuntimeException('Managed setup did not survive reload'); }
echo 'Old PNG bytes/meta unchanged, no automatic enrollment, PNG persisted, managed storage revalidated: PASS';
