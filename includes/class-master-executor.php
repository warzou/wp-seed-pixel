<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Master_Executor implements WP_Seed_Pixel_Job_Executor {
    public function reconcile(array $item, array $policy) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item); if (is_wp_error($r)) { return $r; }
        if (!$r) { return $item['stage'] === 'preparing' ? 'preparing' : new WP_Error('EVIDENCE_INVALID'); }
        if ($r['policy_hash'] !== WP_Seed_Pixel_Policy::hash($policy)) { return new WP_Error('EVIDENCE_INVALID'); }
        if (in_array($r['phase'], array('rollback_intent', 'rolled_back'), true)) { return 'recovery_required'; }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
        if ($o['hash'] === $r['before']['sha256']) {
            return $item['stage'] === 'recovery_required' ? 'switch_intent' : $item['stage'];
        }
        return $item['stage'] === 'recovery_required' ? 'switched' : $item['stage'];
    }
    public function execute($stage, array $item, array $policy) {
        if (!WP_Seed_Pixel_Master_Storage::enabled() || $item['action'] !== 'replace') { return new WP_Error('PERMISSION_DENIED'); }
        if ($stage === 'queued') { $r = WP_Seed_Pixel_Master_Storage::prepare($item, $policy, false); }
        elseif ($stage === 'preparing') { $r = WP_Seed_Pixel_Master_Storage::prepare($item, $policy); }
        elseif ($stage === 'switch_intent') { $r = WP_Seed_Pixel_Master_Storage::switch_master($item, $policy); }
        else {
            $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
            $r = WP_Seed_Pixel_Master_Storage::load($dir, $item);
            if (!is_wp_error($r) && $r) {
                if ($stage === 'switched') {
                    $meta = WP_Seed_Pixel_Master_Adapter::reconcile($r); if (is_wp_error($meta)) { return $meta; }
                    do_action('wp_seed_pixel_m3_boundary', 'post_metadata', (int) $item['id']);
                }
                $verify = WP_Seed_Pixel_Master_Storage::verify($item, $r); if (is_wp_error($verify)) { return $verify; }
                $r['phase'] = $stage === 'verified' ? 'retained' : 'verified';
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r);
                do_action('wp_seed_pixel_m3_boundary', $stage === 'verified' ? 'retained' : 'verified', (int) $item['id']);
            }
        }
        if (is_wp_error($r)) { return $r; }
        if (!$r) { return new WP_Error('EVIDENCE_INVALID'); }
        return array('simulation' => false, 'effect_key' => $item['id'] . ':' . $stage . ':' . $r['policy_hash'], 'encoded' => $r['candidate'] ? 1 : 0, 'deleted' => 0,
            'reclaimed_bytes' => 0, 'active_delta' => $r['candidate'] ? $r['before']['bytes'] - $r['candidate']['bytes'] : 0, 'recovery_bytes' => $r['candidate'] ? $r['before']['bytes'] : 0);
    }
}
