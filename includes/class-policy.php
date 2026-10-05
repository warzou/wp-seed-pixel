<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Policy {
    const VERSION = 1;

    public static function normalize(array $input = array()) {
        $defaults = array('quality' => 'balanced', 'dimensions' => 'keep', 'max_edge' => 0,
            'format' => 'keep', 'master' => 'keep', 'original' => 'keep',
            'recovery' => 'local_quarantine', 'purge' => false,
            'metadata' => 'preserve_required', 'derivatives' => 'strip_sensitive');
        if (array_diff_key($input, $defaults)) { return new WP_Error('POLICY_INVALID'); }
        $p = array_merge($defaults, $input);
        $choices = array('quality' => array('quality', 'balanced', 'storage_saver'),
            'dimensions' => array('keep', 'max_edge'), 'format' => array('keep'),
            'master' => array('keep', 'replace_verified'), 'original' => array('keep', 'retire_verified'),
            'recovery' => array('local_quarantine', 'external_verified', 'irreversible'),
            'metadata' => array('preserve_required'), 'derivatives' => array('strip_sensitive', 'preserve'));
        foreach ($choices as $key => $values) { if (!in_array($p[$key], $values, true)) { return new WP_Error('POLICY_INVALID'); } }
        if (!is_int($p['max_edge']) || !is_bool($p['purge']) || ($p['dimensions'] === 'keep' && $p['max_edge'] !== 0)
            || ($p['dimensions'] === 'max_edge' && ($p['max_edge'] < 256 || $p['max_edge'] > 16000))
            || ($p['purge'] && $p['recovery'] === 'local_quarantine')) { return new WP_Error('POLICY_INVALID'); }
        return array('version' => self::VERSION, 'intent' => $p,
            'effective' => array('simulation_only' => true, 'replace' => false, 'retire' => false, 'purge' => false));
    }

    public static function hash(array $policy) { return hash('sha256', wp_json_encode($policy)); }

    public static function signature(array $analysis) {
        return hash('sha256', wp_json_encode(array($analysis['metadata_revision'] ?? '', $analysis['files'] ?? array(), $analysis['image'] ?? array())));
    }

    /** One eligibility contract for planning and execution; no destructive permission. */
    public static function eligibility(array $a, array $policy) {
        if (get_post_status((int) ($a['attachment_id'] ?? 0)) === 'trash') { return 'SOURCE_MISSING'; }
        if (($a['health'] ?? '') !== 'healthy') { return ($a['health'] ?? '') === 'unsupported' ? 'UNSUPPORTED_FORMAT' : 'NEEDS_REVIEW'; }
        if (!empty($a['stale'])) { return 'SOURCE_CHANGED'; }
        if (!in_array($a['image']['format'] ?? '', array('jpeg', 'png'), true)) { return 'UNSUPPORTED_FORMAT'; }
        if ($a['image']['format'] === 'png') {
            if ($policy['intent']['dimensions'] !== 'keep') { return 'DIMENSION_CONFLICT'; }
            $png = WP_Seed_Pixel_PNG_Processor::inspect(get_attached_file($a['attachment_id']));
            if (is_wp_error($png)) { return $png->get_error_code(); }
        } elseif (($a['image']['icc'] ?? '') !== 'none' || ($a['image']['orientation'] ?? 0) !== 1) { return 'ICC_UNSAFE'; }
        if (empty($a['extra_inventory_complete'])) { return 'NEEDS_REVIEW'; }
        if ($policy['intent']['dimensions'] === 'max_edge') {
            $meta = wp_get_attachment_metadata($a['attachment_id']);
            foreach ((array) ($meta['sizes'] ?? array()) as $size) {
                if (max((int) ($size['width'] ?? 0), (int) ($size['height'] ?? 0)) > $policy['intent']['max_edge']) { return 'DIMENSION_CONFLICT'; }
            }
        }
        return '';
    }
}
