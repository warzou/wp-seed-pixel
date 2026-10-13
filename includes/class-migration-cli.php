<?php
defined('ABSPATH') || exit;

/** Explicit CLI only. Binding is restricted to the certified metadata-graph envelope. */
final class WP_Seed_Pixel_Migration_CLI {
    public static function run($args, $flags) {
        $mode = $args[0] ?? '';
        $roots = isset($flags['webroots']) ? json_decode($flags['webroots'], true) : null;
        if ($mode !== 'inspect' && (!is_array($roots) || !$roots || array_values($roots) !== $roots)) {
            WP_CLI::error('Supply --webroots as a JSON array of all verified public web roots.'); return;
        }
        if ($mode === 'inspect') {
            $r = WP_Seed_Pixel_Migration_Evidence::inspect();
            if (!is_wp_error($r)) { $r = array('state'=>$r['state'], 'jobs'=>count($r['sql']['jobs']),
                'items'=>count($r['sql']['items']), 'files'=>count($r['files']), 'restoration_authorized'=>false); }
        } elseif ($mode === 'export' && isset($flags['parent'])) {
            $r = WP_Seed_Pixel_Migration_Evidence::export($flags['parent'], $roots);
        } elseif ($mode === 'bind' && isset($flags['bundle'], $flags['sha256'])) {
            if (($flags['confirm'] ?? false) !== true) { WP_CLI::error('Explicit --confirm required for destination binding.'); return; }
            $root = $flags['document-root'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? '');
            if (!is_string($root) || realpath($root) !== $root || !in_array($root,$roots,true)
                || (rtrim(ABSPATH,'/') !== $root && !str_starts_with(ABSPATH,$root . '/'))
                || (!empty($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== $root)) {
                WP_CLI::error('Supply the verified target --document-root, included in --webroots.'); return;
            }
            $previous = $_SERVER['DOCUMENT_ROOT'] ?? null; $_SERVER['DOCUMENT_ROOT'] = $root;
            try { $r = WP_Seed_Pixel_Migration_Binding::bind($flags['bundle'],$flags['sha256'],$roots); }
            finally { if ($previous === null) { unset($_SERVER['DOCUMENT_ROOT']); } else { $_SERVER['DOCUMENT_ROOT'] = $previous; } }
        } elseif (in_array($mode, array('verify', 'assess-target'), true) && isset($flags['bundle'], $flags['sha256'])) {
            $method = $mode === 'verify' ? 'verify_bundle' : 'assess_target';
            $r = WP_Seed_Pixel_Migration_Evidence::$method($flags['bundle'], $flags['sha256'], $roots);
        } else { WP_CLI::error('Explicit inspect/export/verify/assess-target/bind mode and required arguments expected.'); return; }
        if (is_wp_error($r)) { WP_CLI::error($r->get_error_code()); return; }
        WP_CLI::line(wp_json_encode($r));
    }
}
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('seed-pixel migration-evidence', array('WP_Seed_Pixel_Migration_CLI', 'run'));
}
