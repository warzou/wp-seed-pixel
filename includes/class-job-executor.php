<?php
defined('ABSPATH') || exit;

interface WP_Seed_Pixel_Job_Executor {
    public function execute($stage, array $item, array $policy);
    public function reconcile(array $item, array $policy);
}

/** No image operations exist here, including behind flags. Receipts describe simulation. */
final class WP_Seed_Pixel_Simulated_Executor implements WP_Seed_Pixel_Job_Executor {
    public function reconcile(array $item, array $policy) {
        if ($item['stage'] === 'recovery_required') {
            $receipt = json_decode((string) $item['receipt'], true);
            return $receipt['resume_stage'] ?? new WP_Error('EVIDENCE_INVALID');
        }
        return $item['stage'];
    }
    public function execute($stage, array $item, array $policy) {
        return array('simulation' => true, 'stage' => $stage, 'effect_key' =>
            hash('sha256', $item['job_id'] . ':' . $item['item_key'] . ':' . $stage . ':' . WP_Seed_Pixel_Policy::hash($policy)),
            'encoded' => 0, 'deleted' => 0, 'reclaimed_bytes' => 0);
    }
}
