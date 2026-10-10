<?php
// Required release/retry gate: real disposable WordPress + InnoDB, synthetic media only.
$argv[1] = 'library'; require __DIR__ . '/metadata-wp.php';
meta_check(WP_Seed_Pixel_Job_Store::install(), 'alias SQL schema');
global $wpdb;
foreach (array(array(true, false), array(false, false), array(true, true)) as $case) {
    list($alias, $clean) = $case;
    $id = meta_fixture($clean ? 'jpeg-clean.jpg' : 'jpeg-gps.jpg', true); $master = get_attached_file($id);
    $meta = wp_get_attachment_metadata($id); $info = getimagesize($master);
    $thumb = $meta['sizes']['thumbnail']; $view = $alias ? array('file' => basename($master), 'width' => $info[0], 'height' => $info[1], 'filesize' => filesize($master)) : $thumb;
    $meta['sizes']['seed-pixel-view'] = $view; wp_update_attachment_metadata($id, $meta);
    $resources = array();
    foreach (array('view' => $view, 'thumb' => $thumb) as $role => $size) {
        $path = dirname($master) . '/' . $size['file'];
        $resources[$role] = array('kind' => $path === $master ? 'master' : 'derived', 'path' => $path, 'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path), 'width' => $size['width'], 'height' => $size['height']);
    }
    $manifest = array('attachment_id' => $id, 'generation' => 'synthetic-alias', 'master_sha256' => hash_file('sha256', $master), 'master_bytes' => filesize($master), 'files' => $resources);
    update_post_meta($id, '_seed_pixel_manifest', $manifest); update_post_meta($id, '_seed_pixel_history', array($manifest));
    $legacy = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    meta_check($alias ? is_wp_error($legacy) && $legacy->get_error_code() === 'NEEDS_REVIEW' : !is_wp_error($legacy), 'JPEG replacement guard');
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true); meta_check($before, 'metadata alias snapshot');
    $editorial = meta_editorial($id); $plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before); meta_check($plan['admissible'], 'alias public graph');
    if ($clean) {
        $analysis = WP_Seed_Pixel_Metadata_Admin::analyze($id); meta_check($analysis, 'clean alias native analysis');
        meta_check($plan['no_op'] && !$analysis['master']['categories'], 'entire clean alias graph is a no-op');
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs'));
        $job = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified', 'metadata' => 'anonymize'), 1073741824, '', $analysis['signature']);
        meta_check(is_wp_error($job) && $job->get_error_code() === 'METADATA_ALREADY_CLEAN', 'official lifecycle refuses needless clean job');
        meta_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs')) === $count, 'clean alias creates no claim/job');
        meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $before, 'clean alias files and SQL unchanged');
        continue;
    }
    $job = meta_job($id); $duplicate = meta_job_duplicate($id);
    meta_check(is_wp_error($duplicate) && $duplicate->get_error_code() === 'CLAIM_CONFLICT', 'existing claim refuses second job');
    meta_check(WP_Seed_Pixel_Jobs::step($job), 'alias transaction');
    $item = meta_item($job); meta_check($item['stage'] === 'retained', 'alias retained');
    $journal = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item, false), $item);
    meta_check($journal, 'alias checksummed journal');
    meta_check($journal['phase'] === 'graph_committed', 'alias durable commit');
    meta_check(count($journal['graph_files']) === $plan['modified_files'], 'exactly one recovery file per dirty physical resource');
    $after = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true); meta_check($after, 'all public witnesses certified after commit');
    $expected = WP_Seed_Pixel_Master_Adapter::graph_reference_rows($before, $plan['manifest']);
    foreach ($expected as $key => $rows) { meta_check($after['rows'][$key] === $rows, 'manifest/history CAS read-back ' . $key); }
    foreach ($after['files'] as $relative => $file) { meta_check(!WP_Seed_Pixel_Metadata::read(wp_upload_dir(null, false)['basedir'] . '/' . $relative)['categories'], 'every public physical member clean'); }
    meta_check(meta_editorial($id) === $editorial, 'editorial and attachment identity exact');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($job), 'official restore lifecycle');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $before, 'exact files, native SQL, manifest/history restored');
    $corrupt = $manifest; $corrupt['files']['view']['sha256'] = str_repeat('0', 64); update_post_meta($id, '_seed_pixel_manifest', $corrupt);
    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs'));
    $review = WP_Seed_Pixel_Metadata_Admin::analyze($id);
    meta_check(is_wp_error($review) && $review->get_error_code() === 'METADATA_CONFLICT', 'real conflicting resource remains blocked');
    meta_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs')) === $count, 'no job from conflicting alias');
}
file_put_contents($root . '/evidence/native-alias.json', wp_json_encode(array('passed' => count($checks), 'checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " native alias/claims/SQL/restore assertions PASS.\n";
