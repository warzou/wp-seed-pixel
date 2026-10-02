<?php
// Disposable local test setup. Do not use this script on an existing site.
$root = dirname(__DIR__) . '/.runtime/wordpress';
if (!is_dir($root) || is_file($root . '/wp-config.php')) {
    throw new RuntimeException('Expected a fresh disposable runtime.');
}
$config = "<?php\ndefine('DB_NAME','local');\ndefine('DB_USER','');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost');\ndefine('DB_CHARSET','utf8');\ndefine('DB_COLLATE','');\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL','http://127.0.0.1:8877');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('DISABLE_WP_CRON',true);\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\ndefine('WP_DEBUG_LOG',true);\n";
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT') as $key) {
    $config .= "define('" . $key . "','" . bin2hex(random_bytes(32)) . "');\n";
}
$config .= "\$table_prefix='wppixel_';\nif (!defined('ABSPATH')) { define('ABSPATH',__DIR__.'/'); }\nrequire_once ABSPATH.'wp-settings.php';\n";
file_put_contents($root . '/wp-config.php', $config);
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_false');
$password = getenv('PIXEL_QA_PASSWORD') ?: bin2hex(random_bytes(24));
wp_install('Pixel disposable QA', 'pixel_qa', 'qa@example.invalid', false, '', $password, 'en_US');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$activated = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if (is_wp_error($activated)) {
    throw new RuntimeException($activated->get_error_message());
}
echo json_encode(array('wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'installed' => is_blog_installed(), 'plugin_active' => is_plugin_active('wp-seed-pixel/wp-seed-pixel.php')), JSON_PRETTY_PRINT);
