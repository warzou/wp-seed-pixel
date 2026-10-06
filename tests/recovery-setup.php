<?php
require __DIR__ . '/runtime.php';
if (defined('WP_SEED_PIXEL_RECOVERY_ROOT')) { throw new RuntimeException('Fresh self-setup lab required'); }
$_SERVER['DOCUMENT_ROOT'] = rtrim(realpath(ABSPATH), '/');
$checks = array();
function setup_check($v, $name) { global $checks; $checks[$name] = (bool) $v; if (!$v) { throw new RuntimeException('FAIL: ' . $name); } }
function setup_code($r, $code) { return is_wp_error($r) && $r->get_error_code() === $code; }
$old_future = WP_Seed_Pixel_Future_Uploads::settings();
$candidate = WP_Seed_Pixel_Recovery_Setup::candidate();
setup_check(!is_wp_error($candidate), 'Writable outside-webroot candidate');
$parent = dirname($candidate['path']); $mode = fileperms($parent) & 0777;
$higher = dirname($parent); $higher_mode = fileperms($higher) & 0777;
try {
    chmod($parent, 0775);
    $shared = WP_Seed_Pixel_Recovery_Setup::candidate();
    setup_check(!is_wp_error($shared) && dirname($shared['path']) === $higher, 'Shared hosting group-writable parent skipped safely');
    chmod($parent, 0500);
    setup_check(!is_wp_error(WP_Seed_Pixel_Recovery_Setup::candidate()), 'Unwritable immediate parent permits safe bounded ancestor');
    chmod($higher, 0500);
    setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::candidate(), 'PRIVATE_PARENT_UNAVAILABLE'), 'No writable safe bounded parent fails closed');
} finally { chmod($higher, $higher_mode); chmod($parent, $mode); }
try {
    chmod($parent, 0775);
    $shared_ready = WP_Seed_Pixel_Recovery_Setup::prepare();
    setup_check(!is_wp_error($shared_ready) && dirname($shared_ready['path']) === $higher, 'Real setup on safe shared-host ancestor');
    setup_check(!is_wp_error(WP_Seed_Pixel_Recovery_Setup::verify()), 'Shared-host ancestor revalidated');
    unlink($shared_ready['path'] . '/.pixel-owner.json'); rmdir($shared_ready['path']); delete_option(WP_Seed_Pixel_Recovery_Setup::OPTION);
} finally { chmod($parent, $mode); }
mkdir($candidate['path'], 0700); file_put_contents($candidate['path'] . '/foreign', 'untouched');
setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::prepare(), 'FOREIGN_DIRECTORY'), 'Foreign collision refused');
setup_check(file_get_contents($candidate['path'] . '/foreign') === 'untouched', 'Foreign directory unchanged');
unlink($candidate['path'] . '/foreign'); rmdir($candidate['path']);
$alias = $parent . '/setup-symlink'; symlink(rtrim(ABSPATH, '/'), $alias);
$_SERVER['DOCUMENT_ROOT'] = $alias;
setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::candidate(), 'WEBROOT_UNKNOWN'), 'Symlink root refused');
unlink($alias); $_SERVER['DOCUMENT_ROOT'] = rtrim(realpath(ABSPATH), '/');
$ram = '/dev/shm/pixel-setup-' . bin2hex(random_bytes(8));
mkdir($ram, 0700);
$filter = static function ($u) use ($ram) { $u['basedir'] = $ram; return $u; };
add_filter('upload_dir', $filter);
setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::candidate(), 'FILESYSTEM_MISMATCH'), 'Real different filesystem refused');
remove_filter('upload_dir', $filter); rmdir($ram);
$r = WP_Seed_Pixel_Recovery_Setup::prepare();
setup_check(!is_wp_error($r), 'Bounded first setup');
setup_check(($r['state'] ?? '') === 'ready', 'Persisted ready state');
setup_check(!str_starts_with($r['path'], rtrim(ABSPATH, '/') . '/'), 'Recovery outside public WordPress');
setup_check((fileperms($r['path']) & 0777) === 0700 && (fileperms($r['path'] . '/.pixel-owner.json') & 0777) === 0600, 'Private directory and marker permissions');
setup_check(!glob($r['path'] . '/.probe-*'), 'Write probe removed');
setup_check(WP_Seed_Pixel_Future_Uploads::settings() === $old_future, 'Setup does not enable PNG or change future JPEG');
$again = WP_Seed_Pixel_Recovery_Setup::prepare();
setup_check($again === $r, 'Repeated setup idempotent');
$interrupted = $r; $interrupted['state'] = 'preparing'; update_option(WP_Seed_Pixel_Recovery_Setup::OPTION, $interrupted, false);
setup_check(WP_Seed_Pixel_Recovery_Setup::prepare() === $r, 'Interruption after marker resumes same identity');
$bad = $r; unset($bad['ino']); update_option(WP_Seed_Pixel_Recovery_Setup::OPTION, $bad, false);
setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::prepare(), 'SETUP_INTERRUPTED'), 'Unproven interruption cannot adopt directory');
update_option(WP_Seed_Pixel_Recovery_Setup::OPTION, $r, false);
$bad = $r; $bad['dev']++; setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::verify($bad), 'IDENTITY_CHANGED'), 'Mount identity change refused');
$bad = $r; $bad['path'] .= '/../'; setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::verify($bad), 'UNSAFE_LOCATION'), 'Traversal refused');
chmod($r['path'], 0755); setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::verify(), 'IDENTITY_CHANGED'), 'Permission drift blocks processing'); chmod($r['path'], 0700);
$moved = $r['path'] . '-moved'; rename($r['path'], $moved);
setup_check(is_wp_error(WP_Seed_Pixel_Recovery_Setup::verify()), 'Disappeared storage fails closed');
symlink($moved, $r['path']); setup_check(setup_code(WP_Seed_Pixel_Recovery_Setup::verify(), 'UNSAFE_LOCATION'), 'Replaced symlink fails closed'); unlink($r['path']); rename($moved, $r['path']);
setup_check(!is_wp_error(WP_Seed_Pixel_Recovery_Setup::verify()), 'Original identity restored');
WP_Seed_Pixel_Recovery_Setup::apply();
setup_check(WP_Seed_Pixel_Quarantine::enabled(), 'Managed preparation reaches accepted engine gate');
setup_check(isset(WP_Seed_Pixel_Recovery_Setup::png_requirements()['capacity']), 'Missing operation capacity explained');
setup_check(isset(WP_Seed_Pixel_Recovery_Setup::png_requirements()['policy']), 'Unchosen ceiling policy explained');
setup_check(is_wp_error(WP_Seed_Pixel_Storage_Admin::save_policy(array('capacity' => '256'))), 'No silent ceiling choice');
setup_check(!is_wp_error(WP_Seed_Pixel_Storage_Admin::save_policy(array('ceiling_policy' => 'limit', 'ceiling' => '600', 'capacity' => '256'))), 'Explicit site ceiling saved');
setup_check(isset(WP_Seed_Pixel_Recovery_Setup::png_requirements()['ceiling']), 'Missing complete hosting measurement exact reason');
setup_check(!is_wp_error(WP_Seed_Pixel_Storage_Admin::save_policy(array('ceiling_policy' => 'none', 'capacity' => '256'))), 'Explicit no-additional-ceiling choice saved');
setup_check(!WP_Seed_Pixel_Recovery_Setup::png_requirements(), 'Ready configuration makes PNG enableable');
setup_check(WP_Seed_Pixel_Future_Uploads::settings()['mode'] === 'off', 'Policy save does not enable PNG');
// Remove only the bounded owned setup fixtures, leaving browser QA a genuine first-run UI.
unlink($r['path'] . '/.pixel-owner.json'); rmdir($r['path']);
delete_option(WP_Seed_Pixel_Recovery_Setup::OPTION); delete_option('wp_seed_pixel_storage_policy_confirmed');
update_option(WP_Seed_Pixel_Future_Uploads::OPTION, $old_future, false); delete_option(WP_Seed_Pixel_Storage_Budget::OPTION);
echo wp_json_encode(array('checks' => $checks, 'count' => count($checks), 'result' => 'PASS'), JSON_PRETTY_PRINT);
