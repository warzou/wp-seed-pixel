<?php
$root = getenv('PIXEL_METADATA_LAB');
if ($root !== '/home/warzy/.cache/wp-seed-pixel-060-metadata-lab') { throw new RuntimeException('Owned local lab required'); }
$wp_path = $root . '/project/wp-seed-pixel-m3-environment/.runtime/wordpress';
$mode = $argv[1] ?? '';
if ($mode === 'install') {
    $config = "<?php\ndefine('DB_NAME','pixel_metadata');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:$root/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='wp_';\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_ACCESSIBLE_HOSTS','127.0.0.1');\ndefine('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','$root/private');\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire ABSPATH.'wp-settings.php';\n";
    $config = str_replace("<?php\n", "<?php\ndefine('WP_ENVIRONMENT_TYPE','local');\ndefine('WP_SEED_PIXEL_METADATA_GRAPH_TESTING',true);\n", $config);
    file_put_contents($wp_path . '/wp-config.php', $config);
    define('WP_INSTALLING', true);
    require $wp_path . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    add_filter('pre_wp_mail', '__return_false');
    wp_install('Synthetic metadata certification', 'pixel_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)), 'fr_FR');
    echo "Disposable WordPress installed; mail disabled.\n";
    exit;
}
require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
add_filter('pre_wp_mail', '__return_false');
wp_set_current_user(1);
if ($mode === 'activate') {
    $r = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
    if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); }
    echo "Candidate activated locally.\n";
    exit;
}
if (!class_exists('WP_Seed_Pixel_Metadata')) { throw new RuntimeException('Candidate required'); }
$checks = array();
function meta_check($v, $name) {
    global $checks;
    if (is_wp_error($v)) { throw new RuntimeException($name . ': ' . $v->get_error_code()); }
    if (!$v) { throw new RuntimeException('FAIL: ' . $name); }
    $checks[] = $name;
}
function meta_fixture($name, $derivatives = false) {
    global $root;
    $bytes = file_get_contents($root . '/fixtures/' . $name);
    $u = wp_upload_bits('synthetic-' . bin2hex(random_bytes(5)) . '-' . $name, null, $bytes);
    meta_check(!$u['error'], 'fixture upload');
    $mime = wp_check_filetype($name)['type'];
    $id = wp_insert_attachment(array('post_title'=>'Titre synthetique', 'post_excerpt'=>'Legende synthetique', 'post_content'=>'Description synthetique', 'post_mime_type'=>$mime), $u['file']);
    update_post_meta($id, '_wp_attachment_image_alt', 'Alternative synthetique');
    $i = @getimagesize($u['file']);
    $m = $derivatives ? wp_generate_attachment_metadata($id, $u['file']) : array('file'=>_wp_relative_upload_path($u['file']), 'width'=>$i[0]??640, 'height'=>$i[1]??480, 'filesize'=>strlen($bytes), 'sizes'=>array(), 'foreign'=>array('keep'=>'yes'));
    wp_update_attachment_metadata($id, $m);
    return (int)$id;
}
function meta_editorial($id) {
    $p = get_post($id);
    return array($p->ID, $p->post_title, $p->post_excerpt, $p->post_content, get_post_meta($id, '_wp_attachment_image_alt', true),$p->post_author,$p->post_status,$p->post_name,$p->guid,wp_get_object_terms($id,get_object_taxonomies('attachment'),array('fields'=>'ids')));
}
function meta_job($id) {
    $a = WP_Seed_Pixel_Metadata_Admin::analyze($id);
    meta_check($a, 'native analysis');
    $r = WP_Seed_Pixel_Jobs::replace_one($id, array('master'=>'replace_verified','metadata'=>'anonymize'), 1073741824, '', $a['signature']);
    meta_check($r, 'explicit SQL job');
    return (int)$r['id'];
}
function meta_item($job) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE job_id=%d AND kind='operation'",$job), ARRAY_A);
}
if ($mode === 'library') { return; }
if ($mode !== 'transaction') { throw new RuntimeException('Explicit mode required'); }
meta_check(WP_Seed_Pixel_Job_Store::install(), 'SQL schema');
$results = array();
foreach (array('jpeg-exif.jpg', 'png-text.png') as $name) {
    $id = meta_fixture($name);
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    meta_check($before, 'native snapshot');
    $editorial = meta_editorial($id);
    $source = WP_Seed_Pixel_Metadata::read(get_attached_file($id));
    meta_check($source, 'source structure');
    $job = meta_job($id);
    $duplicate = meta_job_duplicate($id);
    meta_check(is_wp_error($duplicate), 'duplicate admission refused');
    $r = WP_Seed_Pixel_Jobs::step($job);
    meta_check($r, 'job step');
    $item = meta_item($job);
    meta_check($item['stage'] === 'retained', 'retained stage: '.($item['error_code']??''));
    $dir = WP_Seed_Pixel_Master_Storage::directory($item);
    $record = WP_Seed_Pixel_Master_Storage::load($dir, $item);
    meta_check($record, 'journal');
    $recovery = $dir . '/' . $record['graph_files'][$before['relative']]['original'];
    meta_check(hash_file('sha256',$recovery) === $before['sha256'], 'exact original SHA');
    meta_check(filesize($recovery) === $before['bytes'], 'exact original bytes');
    meta_check(WP_Seed_Pixel_Metadata::read($recovery) === $source, 'original metadata and container exact');
    $after = WP_Seed_Pixel_Metadata::read(get_attached_file($id));
    meta_check($after, 'public candidate');
    meta_check(!$after['categories'] && !$after['removed_bytes'], 'public metadata clean');
    meta_check($after['image_sha256'] === $source['image_sha256'] && $after['color_sha256'] === $source['color_sha256'], 'compressed pixels and color exact');
    meta_check($record['candidate']['encoded'] === 0, 'zero encoding');
    meta_check(meta_editorial($id) === $editorial, 'all editorial fields exact');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($job), 'restore');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'exact native restore');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($job), 'restore idempotent');
    $again = meta_job($id);
    meta_check(WP_Seed_Pixel_Jobs::step($again), 'second anonymization');
    meta_check(meta_item($again)['stage'] === 'retained', 're-anonymization without stale claim');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($again), 'second exact restore');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'second exact snapshot');
    $results[$name] = array('id'=>$id, 'job'=>$job, 'repeat_job'=>$again, 'before_sha'=>$before['sha256'], 'removed_bytes'=>$source['removed_bytes']);
}
file_put_contents($root.'/evidence/transaction.json', wp_json_encode(array('passed'=>count($checks),'checks'=>$checks,'results'=>$results),JSON_PRETTY_PRINT));
echo count($checks)." real WordPress transaction checks passed.\n";
function meta_job_duplicate($id) {
    $a = WP_Seed_Pixel_Metadata_Admin::analyze($id);
    if (is_wp_error($a)) { return $a; }
    return WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',$a['signature']);
}
