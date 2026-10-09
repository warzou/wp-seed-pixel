<?php
$root = getenv('PIXEL_METADATA_LAB');
$n = (int) ($argv[1] ?? 0);
if ($root !== '/home/warzy/.cache/wp-seed-pixel-060-metadata-lab' || !in_array($n, array(1, 2), true)) {
    throw new RuntimeException('Owned local legacy lab required');
}
$wp_path = "$root/legacy-$n/wp-seed-pixel-m3-environment/.runtime/wordpress";
$config = "<?php\ndefine('DB_NAME','pixel_metadata_legacy_$n');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:$root/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='wp_';\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','$root/legacy-$n/private');\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire ABSPATH.'wp-settings.php';\n";
file_put_contents($wp_path . '/wp-config.php', $config);
define('WP_INSTALLING', true);
require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_false');
wp_install('Synthetic legacy regressions', 'pixel_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)), 'fr_FR');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$r = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); }
echo "Independent legacy database installed.\n";
