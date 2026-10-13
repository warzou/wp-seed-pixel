<?php
// CLI adapter unit tests only; binding's native SQL/filesystem tests run separately.
define('ABSPATH',__DIR__ . '/');
class WP_CLI { public static $lines=array(); public static function error($m) { throw new RuntimeException($m); } public static function line($s) { self::$lines[]=$s; } }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($v) { return false; }
class WP_Seed_Pixel_Migration_Binding {
    public static $calls=0;
    public static function bind($bundle,$sha,$roots) { self::$calls++; if (($_SERVER['DOCUMENT_ROOT'] ?? '') !== rtrim(ABSPATH,'/')) { throw new RuntimeException('Root not passed'); } return array('state'=>'BOUND_VERIFIED'); }
}
require dirname(__DIR__) . '/includes/class-migration-cli.php'; $n=0;
unset($_SERVER['DOCUMENT_ROOT']);
function check($v) { global $n; if (!$v) { throw new RuntimeException('CLI assertion failed'); } $n++; }
$args=array('bind'); $flags=array('bundle'=>'synthetic','sha256'=>str_repeat('a',64),'webroots'=>json_encode(array(rtrim(ABSPATH,'/'))));
try { WP_Seed_Pixel_Migration_CLI::run($args,$flags); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'--confirm')); }
check(WP_Seed_Pixel_Migration_Binding::$calls === 0);
$flags['confirm']=true;
try { WP_Seed_Pixel_Migration_CLI::run($args,$flags); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'document-root')); }
check(WP_Seed_Pixel_Migration_Binding::$calls === 0);
$flags['document-root']=rtrim(ABSPATH,'/');
WP_Seed_Pixel_Migration_CLI::run($args,$flags); check(WP_Seed_Pixel_Migration_Binding::$calls === 1); check(!isset($_SERVER['DOCUMENT_ROOT']));
$_SERVER['DOCUMENT_ROOT']='/different-root';
try { WP_Seed_Pixel_Migration_CLI::run($args,$flags); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'document-root')); }
check(WP_Seed_Pixel_Migration_Binding::$calls === 1 && $_SERVER['DOCUMENT_ROOT'] === '/different-root');
echo "$n CLI adapter assertions PASS (binding double only).\n";
