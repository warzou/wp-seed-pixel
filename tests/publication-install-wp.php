<?php
declare(strict_types=1);
$root = getenv('SEED_PORTABILITY_LAB');
if ($root !== '/home/warzy/.cache/wp-seed-pixel-publication-20261013' || realpath($root) !== $root
    || trim(file_get_contents($root . '/publication.marker')) !== 'pixel-publication-synthetic-only-v1') { throw new RuntimeException('OWNED_INSTALL_LAB'); }
$web = $root . '/h/web';
if (file_exists($web . '/wp-config.php') || file_exists($web . '/wp-content/plugins/wp-seed-pixel')) { throw new RuntimeException('INSTALL_NOT_FRESH'); }
$config = "<?php\ndefine('DB_NAME','seed_portability_h');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:$root/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='lab_';\ndefine('WP_HOME','http://127.0.0.1/h');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_ENVIRONMENT_TYPE','local');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\nif(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');\nrequire ABSPATH.'wp-settings.php';\n";
file_put_contents($web . '/wp-config.php', $config); chmod($web . '/wp-config.php', 0600);
$_SERVER['DOCUMENT_ROOT'] = $web; define('WP_INSTALLING', true);
require $web . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install('Synthetic fresh ZIP', 'local_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)));
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);
$u = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$zip = getenv('PIXEL_PUBLICATION_REPORT') . '/candidate/wp-seed-pixel-0.7.0-rc.2.zip';
$r = $u->install($zip);
if (is_wp_error($r) || $r !== true) { throw new RuntimeException('NATIVE_FRESH_ZIP_INSTALL'); }
$r = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if (is_wp_error($r) || !is_plugin_active('wp-seed-pixel/wp-seed-pixel.php') || WP_SEED_PIXEL_VERSION !== '0.7.0-rc.2') { throw new RuntimeException('NATIVE_FRESH_ACTIVATION'); }
$result = ['version' => WP_SEED_PIXEL_VERSION, 'passed' => 3, 'checks' => ['native ZIP installation', 'native activation', 'candidate identity']];
file_put_contents($root . '/evidence/fresh-install.json', wp_json_encode($result, JSON_PRETTY_PRINT));
echo wp_json_encode($result) . "\n";
