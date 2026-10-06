<?php
// Presentation unit contracts only, not a real WordPress or database test.
define('ABSPATH', __DIR__ . '/');
define('WP_SEED_PIXEL_FILE', dirname(__DIR__) . '/wp-seed-pixel.php');
define('WP_SEED_PIXEL_VERSION', '0.4.0');
define('WP_SEED_PIXEL_BUILD', '0.4.0-private.3');
$translations = json_decode(file_get_contents(dirname(__DIR__) . '/tools/i18n-fr.json'), true, 512, JSON_THROW_ON_ERROR);
$locale = 'en_US'; $localized = array(); $options = array('automatic' => false, 'preset' => 'balanced', 'cleanup_on_uninstall' => false);
function __($s, $domain = null) { global $translations, $locale; if ($domain !== 'wp-seed-pixel') { throw new RuntimeException('Wrong domain'); } return $locale === 'fr_FR' && isset($translations[$s]) && is_string($translations[$s]) ? $translations[$s] : $s; }
function _n($one, $many, $n, $domain) { global $translations, $locale; return $locale === 'fr_FR' ? $translations[$one][$n > 1 ? 1 : 0] : ($n === 1 ? $one : $many); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s, $d) { return esc_html(__($s, $d)); }
function esc_html_e($s, $d) { echo esc_html__($s, $d); }
function add_action(...$args) {}
function add_filter(...$args) {}
function load_plugin_textdomain(...$args) {}
function plugin_basename($f) { return basename($f); }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_enqueue_media() {}
function wp_localize_script($name, $object, $value) { global $localized; $localized[$object] = $value; }
function current_user_can(...$args) { return true; }
function admin_url($path) { return 'https://example.invalid/' . $path; }
function plugins_url($path, $file) { return '../' . $path; }
function wp_create_nonce($s) { return 'synthetic-unit-nonce'; }
function wp_nonce_field($s) {}
function checked($a) { if ($a) { echo 'checked'; } }
function disabled($a) { if ($a) { echo 'disabled'; } }
function selected($a, $b) { if ($a === $b) { echo 'selected'; } }
function submit_button() { global $locale; echo '<button type="submit">' . ($locale === 'fr_FR' ? 'Enregistrer les modifications' : 'Save Changes') . '</button>'; }
function get_posts($args) { return array(); }
function get_option($name, $default = null) { global $options; return $name === 'wp_seed_pixel_settings' ? $options : $default; }
function get_site_option($name, $default = null) { return $default; }
function get_bloginfo($key) { return 'unit-preview'; }
function get_post_meta(...$args) { return array(); }
function get_post_mime_type($id) { return 'image/jpeg'; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_]/', '', strtolower($s)); }
function wp_unslash($s) { return $s; }
function check_admin_referer(...$args) {}
function is_wp_error($s) { return false; }
function update_option($key, $value, $autoload) { global $options; $options = $value; }
function wp_safe_redirect($url) { throw new RuntimeException('unit-redirect'); }
class WP_Seed_Pixel_Store { const KEY = '_seed_pixel_manifest'; static function manifest($id) { return false; } }
class WP_Seed_Pixel_Future_Uploads { const META = '_seed_pixel_future_upload'; static function settings() { return array('mode' => 'off', 'cutoff_id' => 0, 'capacity_bytes' => 0, 'formats' => array('jpeg')); } }
class WP_Seed_Pixel_Quarantine { static function enabled() { return false; } }
class WP_Seed_Pixel_Selected_Admin { static function available() { return false; } }
class WP_Seed_Pixel_Host_Admin { static function recent() {} }
class WP_Seed_Pixel_Updater { static function endpoint() { return ''; } }
class WP_Seed_Pixel_Job_Store { const SCHEMA = 2; }
class WP_Seed_Pixel_Presets { static function all() { return array('balanced'=>array(), 'web'=>array(), 'participant_album'=>array()); } static function get($key) { return array(); } }
require dirname(__DIR__) . '/includes/class-i18n.php';
require dirname(__DIR__) . '/includes/class-plugin.php';
require dirname(__DIR__) . '/includes/class-media.php';
require dirname(__DIR__) . '/includes/class-admin.php';
$checks = array();
function check($name, $pass) { global $checks; if (!$pass) { throw new RuntimeException($name); } $checks[] = $name; }
$out = dirname(__DIR__) . '/reports/i18n';
if (!is_dir($out)) { mkdir($out, 0755, true); }
foreach (array('en_US', 'fr_FR') as $locale) {
    WP_Seed_Pixel_Admin::assets('media_page_wp-seed-pixel');
    $before = $options;
    ob_start(); WP_Seed_Pixel_Admin::page(); $html = ob_get_clean();
    check($locale . ' heading', str_contains($html, $locale === 'fr_FR' ? 'Réglages avancés' : 'Advanced settings'));
    check($locale . ' profile', str_contains($html, $locale === 'fr_FR' ? 'Équilibré (recommandé)' : 'Balanced (recommended)'));
    check($locale . ' render no mutation', $before === $options && !$options['automatic']);
    check($locale . ' exact internal profile keys', str_contains($html, 'value="balanced"') && str_contains($html, 'value="web"') && str_contains($html, 'value="participant_album"'));
    check($locale . ' localized JS', $localized['wpSeedPixel']['labels']['running'] === ($locale === 'fr_FR' ? 'Traitement en cours' : 'Processing'));
    check($locale . ' media label', WP_Seed_Pixel_Media::label('new') === ($locale === 'fr_FR' ? 'Non optimisée' : 'Not optimized'));
    check($locale . ' engine message at boundary', WP_Seed_Pixel_I18n::message('Attachment not found.') === ($locale === 'fr_FR' ? 'Le média est introuvable.' : 'Attachment not found.'));
    check($locale . ' raw diagnostics preserved', WP_Seed_Pixel_I18n::message('Imagick raw error 42') === 'Imagick raw error 42');
    $counters = WP_Seed_Pixel_I18n::counters();
    check($locale . ' singular', str_contains($counters[1]['optimized'], $locale === 'fr_FR' ? 'image optimisée' : 'image optimized'));
    check($locale . ' plural', str_contains($counters[2]['optimized'], $locale === 'fr_FR' ? 'images optimisées' : 'images optimized'));
    if ($locale === 'fr_FR') { check('French zero singular', $counters[0]['optimized'] === $counters[1]['optimized']); }
    file_put_contents($out . '/' . $locale . '.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="../../assets/admin.css"><style>body{font:14px Arial;margin:24px;max-width:1000px}button{padding:8px}input,select{max-width:100%}.pixel-actions{flex-wrap:wrap}p{line-height:1.6}</style>' . $html);
    file_put_contents($out . '/' . $locale . '-js.json', json_encode($localized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('preset'=>'balanced');
try { WP_Seed_Pixel_Admin::save(); } catch (RuntimeException $e) { if ($e->getMessage() !== 'unit-redirect') { throw $e; } }
check('Settings save contract keeps automatic OFF', $options === array('automatic'=>false,'preset'=>'balanced','cleanup_on_uninstall'=>false));
file_put_contents($out . '/unit-qa.json', json_encode(array('status'=>'PASS','type'=>'MO compiler plus stubbed presentation unit contracts; not real WordPress integration','checks'=>$checks), JSON_PRETTY_PRINT));
echo count($checks) . "/" . count($checks) . " presentation unit contracts PASS\n";
