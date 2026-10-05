<?php
// Disposable local settings only; no real credentials or external requests.
$root = getenv('PIXEL_M3_ROOT');
if (!$root || !is_dir($root . '/project/.runtime/wordpress')) { throw new RuntimeException('Owned M3 runtime missing'); }
$db = getenv('PIXEL_M3_DB') ?: 'pixel_m3';
if (!in_array($db, array('pixel_m3', 'pixel_m3_regression', 'pixel_m3_final_regression'), true)) { throw new RuntimeException('Only owned disposable databases are allowed'); }
define('DB_NAME', $db);
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost:' . $root . '/mysql.sock');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
if ($db === 'pixel_m3_final_regression') { define('UPLOADS', 'wp-content/regression-uploads'); }
define('WP_HOME', 'http://127.0.0.1:8877');
define('WP_SITEURL', 'http://127.0.0.1:8877');
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_SEED_PIXEL_M3_TESTING', true);
define('WP_SEED_PIXEL_M4_TESTING', getenv('PIXEL_M4_TESTING') === '1');
$recovery = $root . (getenv('PIXEL_M3_LOW_DISK') === '1' ? '/volume/private' : '/private');
$authority_scope = getenv('PIXEL_M51_RECOVERY');
if ($authority_scope) {
    if (dirname($authority_scope) !== $root || !preg_match('/^private-m51-(mariadb|mysql)-[A-Za-z0-9]+$/', basename($authority_scope))
        || is_link($authority_scope) || realpath($authority_scope) !== $authority_scope || (fileperms($authority_scope) & 0077)) {
        throw new RuntimeException('Owned authority recovery scope required');
    }
    $recovery = $authority_scope;
}
define('WP_SEED_PIXEL_RECOVERY_ROOT', $recovery);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', PHP_SAPI === 'cli');
define('WP_MEMORY_LIMIT', '512M');
require __DIR__ . '/wp-settings.php';
if (getenv('PIXEL_M3_FORCE_GD') === '1') { add_filter('wp_image_editors', static function () { return array('WP_Image_Editor_GD'); }); }
