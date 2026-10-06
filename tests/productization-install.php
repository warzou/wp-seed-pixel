<?php
$root = dirname(__DIR__) . '/.runtime/wordpress';
if (!str_contains(realpath($root), 'wp-seed-pixel-m3-environment')) { throw new RuntimeException('Disposable tree required'); }
$config = "<?php\ndefine('DB_NAME','pixel_m3');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:/home/warzy/.cache/wp-seed-pixel-m3-environment/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='wp_';\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_ACCESSIBLE_HOSTS','127.0.0.1');\ndefine('WP_SEED_PIXEL_UPDATE_MANIFEST','https://127.0.0.1/manifest.json');\ndefine('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','/home/warzy/.cache/wp-seed-pixel-m3-environment/recovery');\ndefine('ABSPATH',__DIR__.'/');\nrequire ABSPATH.'wp-settings.php';\n";
if (getenv('PIXEL_SELF_SETUP')) {
    $config = str_replace("define('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','/home/warzy/.cache/wp-seed-pixel-m3-environment/recovery');\n", '', $config);
}
$config = str_replace("define('ABSPATH',__DIR__.'/');", "if (!defined('ABSPATH')) { define('ABSPATH',__DIR__.'/'); }", $config);
file_put_contents($root . '/wp-config.php', $config);
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', '__return_false');
if (is_blog_installed()) { throw new RuntimeException('Fresh DB required'); }
wp_install('Pixel productization lab', 'pixel_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)), 'en_US');
activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
wp_set_current_user(1);
$schema = WP_Seed_Pixel_Job_Store::install();
if (is_wp_error($schema)) { throw new RuntimeException($schema->get_error_code()); }
echo json_encode(array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'schema' => true, 'home' => get_option('home')));
