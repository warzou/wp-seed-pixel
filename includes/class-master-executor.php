<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Master_Executor implements WP_Seed_Pixel_Job_Executor {
    public function reconcile(array $item, array $policy) {
        if (WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item)) {
            $r = WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item); if (is_wp_error($r)) { return $r; }
            if ($r['phase'] === 'graph_restored') {
                $r = WP_Seed_Pixel_Metadata_Graph_Transaction::cleanup($item, $r, true);
                return is_wp_error($r) ? $r : 'rolled_back';
            }
            return $item['stage'] === 'recovery_required' ? 'switch_intent' : $item['stage'];
        }
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
        if (WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item)) {
            if (in_array($stage, array('queued', 'preparing'), true)) { $r = WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy); }
            elseif ($stage === 'switch_intent') { $r = WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy); }
            else {
                $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
                $r = is_wp_error($dir) ? $dir : WP_Seed_Pixel_Metadata_Graph_Transaction::journal($item, $dir);
                if (!is_wp_error($r) && $r && $r['phase'] === 'graph_committed') {
                    $v = WP_Seed_Pixel_Metadata_Graph_Transaction::verify($item, $r); if (is_wp_error($v)) { return $v; }
                } else { return new WP_Error('RECOVERY_REQUIRED'); }
            }
            if (is_wp_error($r)) { return $r; }
            return array('simulation' => false, 'effect_key' => $item['id'] . ':' . $stage . ':' . $r['policy_hash'], 'encoded' => 0,
                'deleted' => 0, 'reclaimed_bytes' => 0, 'active_delta' => array_sum(array_column($r['graph_plan']['files'], 'removed_bytes')), 'recovery_bytes' => $r['recovery_bytes']);
        }
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
        return array('simulation' => false, 'effect_key' => $item['id'] . ':' . $stage . ':' . $r['policy_hash'], 'encoded' => $r['candidate'] && ($r['candidate']['processor'] ?? '') !== WP_Seed_Pixel_Metadata::VERSION ? 1 : 0, 'deleted' => 0,
            'reclaimed_bytes' => 0, 'active_delta' => $r['candidate'] ? $r['before']['bytes'] - $r['candidate']['bytes'] : 0, 'recovery_bytes' => $r['candidate'] ? $r['before']['bytes'] : 0);
    }
}
