<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Jobs {
    const FLOW = array('queued', 'preparing', 'ready', 'switch_intent', 'switched', 'verified', 'retained');

    private static function allowed() { return current_user_can('manage_options'); }
    private static function unstarted_review(array $item) {
        if ($item['stage'] !== 'needs_review' || !in_array($item['action'], array('replace', 'retire'), true)
            || (int) $item['revision'] !== 0 || (int) $item['attempts'] !== 0 || (int) $item['bytes'] !== 0
            || !empty($item['lease']) || (int) $item['lease_until'] !== 0 || !empty($item['error_code'])
            || !empty($item['journal']) || !empty($item['receipt'])
            || !hash_equals($item['snapshot_hash'], hash('sha256', $item['data']))) { return false; }
        $data = json_decode($item['data'], true);
        return is_array($data) && array_key_exists('before', $data) && $data['before'] === null
            && array_key_exists('original', $data) && $data['original'] === null
            && is_string($data['reason'] ?? null) && preg_match('/^[A-Z][A-Z0-9_]+$/D', $data['reason'])
            && ($data['planned_bytes'] ?? null) === 0 && ($data['peak_bytes'] ?? null) === 0;
    }

    /** Read-only reconciliation: a rejected, never-started plan is not an image owner. */
    public static function review_claims($id) {
        global $wpdb;
        $id = (int) $id;
        if (!self::allowed() || !current_user_can('edit_post', $id)
            || !WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid($id)) { return new WP_Error('LOCKED'); }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') .
            " WHERE attachment_id=%d AND kind='operation' AND action<>'convert' AND stage NOT IN ('retained','purged','rolled_back','skipped','failed','cancelled') ORDER BY id LIMIT 101", $id), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error || count($rows) > 100) { return new WP_Error('CLAIM_CONFLICT'); }
        $proofs = array();
        foreach ($rows as $item) {
            $job = WP_Seed_Pixel_Job_Store::job($item['job_id']);
            $root = defined('WP_SEED_PIXEL_RECOVERY_ROOT') ? realpath(WP_SEED_PIXEL_RECOVERY_ROOT) : false;
            $path = $root ? $root . '/m3-' . (int) $item['job_id'] . '-' . (int) $item['id'] : '';
            if (!self::unstarted_review($item) || (int) $item['owners'] !== 0 || !$job || !self::compatible($job)
                || !in_array($job['status'], array('completed_errors', 'cancelled'), true)
                || !empty($job['lease']) || (int) $job['lease_until'] !== 0 || !$root
                || is_link(WP_SEED_PIXEL_RECOVERY_ROOT) || file_exists($path) || is_link($path)) { return new WP_Error('CLAIM_CONFLICT'); }
            $proofs[] = array('item_id' => (int) $item['id'], 'job_id' => (int) $job['id'],
                'classification' => 'unstarted_terminal_review', 'state' => $item['stage'],
                'reason' => json_decode($item['data'], true)['reason'],
                'item_sha256' => hash('sha256', wp_json_encode($item)), 'job_sha256' => hash('sha256', wp_json_encode($job)));
        }
        return array('attachment_id' => $id, 'blocked' => false, 'reviews' => $proofs, 'mutations' => 0);
    }

    public static function reconcile_claims($id) {
        if (!self::allowed() || !current_user_can('edit_post', (int) $id) || !WP_Seed_Pixel_Master_Storage::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return $site; }
        $image = null;
        try {
            $image = WP_Seed_Pixel_Files::lock((int) $id); if (is_wp_error($image)) { return $image; }
            return self::review_claims($id);
        } finally {
            if ($image && !is_wp_error($image)) { WP_Seed_Pixel_Files::unlock($image); }
            WP_Seed_Pixel_Files::unlock($site);
        }
    }

    private static function other_claim(array $item, $ignore_queued) {
        global $wpdb;
        $table = WP_Seed_Pixel_Job_Store::table('items');
        $terminal = "'purged','rolled_back','cancelled','skipped','failed'";
        if ($ignore_queued) { $terminal .= ",'queued'"; }
        $exclude = $ignore_queued ? 'job_id' : 'id';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE attachment_id=%d AND kind='operation' AND $exclude<>%d AND stage NOT IN ($terminal) ORDER BY id LIMIT 101",
            $item['attachment_id'], $item[$exclude]), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '' || count($rows) > 100) { return true; }
        foreach ($rows as $other) {
            if ($other['stage'] === 'retained' && !WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($other)) { continue; }
            if ($ignore_queued && $other['stage'] === 'needs_review' && !in_array($other['action'], array('replace', 'retire'), true)) { continue; }
            // A rejected plan with no snapshot or execution evidence never reserved the image.
            if (!self::unstarted_review($other)) { return true; }
        }
        return false;
    }
    private static function compatible(array $job) {
        $p = json_decode($job['policy'], true);
        $real = in_array($job['kind'], array('replace', 'retire'), true);
        if (is_array($p) && isset($p['future_upload_generation']) && (!is_string($p['future_upload_generation'])
            || !preg_match('/^[a-f0-9]{48}$/D', $p['future_upload_generation']) || $job['kind'] !== 'replace'
            || !empty($p['effective']['purge']))) { return false; }
        if (is_array($p) && isset($p['bulk'])) {
            $b = $p['bulk'];
            if (!is_array($b) || ($b['version'] ?? 0) !== 1 || ($b['concurrency'] ?? 0) !== 1
                || !is_int($b['lot_size'] ?? null) || $b['lot_size'] < 1 || $b['lot_size'] > 5
                || !in_array($b['operation'] ?? '', array('replace','retire'), true)
                || ($real && $b['operation'] !== $job['kind']) || !is_int($p['capacity_bytes'] ?? null) || $p['capacity_bytes'] <= 0
                || !empty($p['effective']['purge']) || ($b['operation'] === 'retire' && ($p['original_url_acknowledged'] ?? false) !== true)) { return false; }
        }
        $bulk_plan = $job['kind'] === 'plan' && is_array($p) && isset($p['bulk']);
        if ($bulk_plan) {
            return (int) $job['schema_version'] === WP_Seed_Pixel_Job_Store::SCHEMA
                && $job['engine'] === WP_Seed_Pixel_Job_Store::ENGINE
                && ($p['version'] ?? 0) === WP_Seed_Pixel_Policy::VERSION
                && ($p['bulk']['version'] ?? 0) === 1
                && in_array($p['bulk']['operation'] ?? '', array('replace', 'retire'), true)
                && hash_equals($job['policy_hash'], WP_Seed_Pixel_Policy::hash($p));
        }
        $engine = $job['kind'] === 'retire' ? WP_Seed_Pixel_Quarantine::ENGINE : ($real ? WP_Seed_Pixel_Master_Storage::ENGINE : WP_Seed_Pixel_Job_Store::ENGINE);
        return (int) $job['schema_version'] === WP_Seed_Pixel_Job_Store::SCHEMA && $job['engine'] === $engine
            && is_array($p) && ($p['version'] ?? 0) === WP_Seed_Pixel_Policy::VERSION
            && (!$real || (($p['capability'] ?? '') === $engine && !empty($p['effective'][$job['kind']]) && empty($p['effective']['simulation_only'])))
            && ($real || !empty($p['effective']['simulation_only']))
            && hash_equals($job['policy_hash'], WP_Seed_Pixel_Policy::hash($p));
    }

    /** Explicit single-item local development path. Not an admin/cron/HTTP action. */
    public static function replace_one($attachment_id, array $input, $capacity_bytes, $future_generation = '', $metadata_approval = '') {
        global $wpdb;
        if (!self::allowed() || !WP_Seed_Pixel_Master_Storage::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $policy = WP_Seed_Pixel_Policy::normalize($input);
        if (!is_wp_error($policy) && $policy['intent']['metadata'] === 'anonymize' && !WP_Seed_Pixel_Metadata_Graph_Transaction::enabled()) { return new WP_Error('METADATA_CERTIFICATION_REQUIRED'); }
        if (!is_wp_error($policy) && $policy['intent']['metadata'] === 'anonymize' && $future_generation !== '') { return new WP_Error('POLICY_INVALID'); }
        if (is_wp_error($policy) || $policy['intent']['master'] !== 'replace_verified' || $policy['intent']['original'] !== 'keep'
            || $policy['intent']['purge'] || $policy['intent']['recovery'] !== 'local_quarantine' || !is_int($capacity_bytes) || $capacity_bytes <= 0) { return new WP_Error('POLICY_INVALID'); }
        $schema = WP_Seed_Pixel_Job_Store::install(); if (is_wp_error($schema)) { return $schema; }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        $image_lock = null;
        try {
            $image_lock = WP_Seed_Pixel_Files::lock((int) $attachment_id); if (is_wp_error($image_lock)) { $image_lock = null; return new WP_Error('LOCKED'); }
            if (self::other_claim(array('id' => 0, 'job_id' => 0, 'attachment_id' => $attachment_id), false)) { return new WP_Error('CLAIM_CONFLICT'); }
            $enrollment = null;
            if ($future_generation !== '') {
                $settings = WP_Seed_Pixel_Future_Uploads::settings();
                $enrollment = get_post_meta($attachment_id, WP_Seed_Pixel_Future_Uploads::META, true);
                if (($settings['mode'] ?? '') !== 'process' || ($settings['generation'] ?? '') !== $future_generation
                    || !is_array($enrollment) || ($enrollment['generation'] ?? '') !== $future_generation
                    || (int) $attachment_id <= ($settings['cutoff_id'] ?? PHP_INT_MAX)) { return new WP_Error('SOURCE_CHANGED'); }
                if (!empty($enrollment['job_id'])) { return self::status((int) $enrollment['job_id']); }
            }
            $before = WP_Seed_Pixel_Master_Adapter::snapshot((int) $attachment_id, false, $policy['intent']['metadata'] === 'anonymize'); if (is_wp_error($before)) { return $before; }
            $privacy = null;
            if ($policy['intent']['metadata'] === 'anonymize') {
                $conversion = WP_Seed_Pixel_Format_Conversion::record((int) $attachment_id);
                if (is_wp_error($conversion)) { return $conversion; }
                if ($conversion && !in_array($conversion['record']['phase'], array('restored', 'discarded'), true)) { return new WP_Error('METADATA_REVIEW'); }
                $retained = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND kind='operation' AND stage='retained' LIMIT 1", (int) $attachment_id));
                if ($wpdb->last_error) { return new WP_Error('STORE_FAILED'); }
                if ($retained) { return new WP_Error('CLAIM_CONFLICT'); }
                $privacy = WP_Seed_Pixel_Metadata_Public_Graph::analyze($before); if (is_wp_error($privacy)) { return $privacy; }
                if (!is_string($metadata_approval) || !hash_equals($privacy['signature'], $metadata_approval)) { return new WP_Error('SOURCE_CHANGED'); }
                if (!$privacy['master']['categories']) { return new WP_Error('METADATA_ALREADY_CLEAN'); }
            }
            $policy['capability'] = WP_Seed_Pixel_Master_Storage::ENGINE; $policy['capacity_bytes'] = $capacity_bytes;
            if ($enrollment !== null) { $policy['future_upload_generation'] = $future_generation; }
            $policy['effective'] = array('simulation_only' => false, 'replace' => true, 'retire' => false, 'purge' => false);
            $data = array('before' => $before, 'reason' => '', 'planned_bytes' => $before['bytes']);
            if ($privacy) { $data['metadata_graph'] = $privacy['plan']; $data['operation_type'] = 'metadata_anonymization'; }
            $json = wp_json_encode($data);
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
            $id = WP_Seed_Pixel_Job_Store::insert_job('replace', 0, $policy, 1);
            if (is_wp_error($id)) { $wpdb->query('ROLLBACK'); return $id; }
            $ok = $wpdb->insert(WP_Seed_Pixel_Job_Store::table('items'), array('job_id' => $id, 'kind' => 'operation', 'item_key' => $attachment_id . ':replace',
                'attachment_id' => $attachment_id, 'action' => 'replace', 'stage' => 'queued', 'data' => $json, 'snapshot_hash' => hash('sha256', $json), 'updated' => time()));
            if ($ok && $enrollment !== null) {
                $next = $enrollment; $next['job_id'] = $id; $next['state'] = 'queued';
                $ok = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE post_id=%d AND meta_key=%s AND meta_value=%s",
                    maybe_serialize($next), $attachment_id, WP_Seed_Pixel_Future_Uploads::META, maybe_serialize($enrollment))) === 1;
            }
            if (!$ok || !WP_Seed_Pixel_Authority::valid(0) || !$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('engine' => WP_Seed_Pixel_Master_Storage::ENGINE, 'status' => 'running'), array('id' => $id)) || $wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
            if ($enrollment !== null) { wp_cache_delete($attachment_id, 'post_meta'); }
            return self::status($id);
        } finally { if ($image_lock) { WP_Seed_Pixel_Files::unlock($image_lock); } WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function retire_original($attachment_id, $capacity_bytes, $acknowledge_original_url = false) {
        global $wpdb;
        if (!WP_Seed_Pixel_Quarantine::enabled() || !self::allowed() || $acknowledge_original_url !== true || !is_int($capacity_bytes) || $capacity_bytes <= 0) { return new WP_Error('PERMISSION_DENIED'); }
        $schema = WP_Seed_Pixel_Job_Store::install(); if (is_wp_error($schema)) { return $schema; }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        try {
            $before = WP_Seed_Pixel_Master_Adapter::snapshot($attachment_id); if (is_wp_error($before)) { return $before; }
            $original = WP_Seed_Pixel_Quarantine::original_snapshot($before); if (is_wp_error($original)) { return $original; }
            $policy = WP_Seed_Pixel_Policy::normalize(array('original' => 'retire_verified'));
            if (is_wp_error($policy)) { return $policy; }
            $policy['capability'] = WP_Seed_Pixel_Quarantine::ENGINE; $policy['capacity_bytes'] = $capacity_bytes;
            $policy['original_url_acknowledged'] = true;
            $policy['effective'] = array('simulation_only' => false, 'replace' => false, 'retire' => true, 'purge' => false);
            $data = wp_json_encode(array('before' => $before, 'original' => $original, 'reason' => '', 'planned_bytes' => $original['bytes']));
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
            $id = WP_Seed_Pixel_Job_Store::insert_job('retire', 0, $policy, 1);
            if (is_wp_error($id)) { $wpdb->query('ROLLBACK'); return $id; }
            $ok = $wpdb->insert(WP_Seed_Pixel_Job_Store::table('items'), array('job_id' => $id, 'kind' => 'operation', 'item_key' => $attachment_id . ':retire',
                'attachment_id' => $attachment_id, 'action' => 'retire', 'stage' => 'queued', 'data' => $data, 'snapshot_hash' => hash('sha256', $data), 'updated' => time()));
            if (!$ok || !$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('engine' => WP_Seed_Pixel_Quarantine::ENGINE, 'status' => 'running'), array('id' => $id)) || $wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
            return self::status($id);
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }

    /** Explicit operations on an existing M2 item, using its normal lock and CAS. */
    public static function quarantine_action($id, $action, array $approval = array(), $item_id = 0) {
        global $wpdb;
        if (!self::allowed() || !WP_Seed_Pixel_Quarantine::enabled() || !in_array($action, array('retain', 'restore', 'purge'), true)) { return new WP_Error('PERMISSION_DENIED'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return new WP_Error('LOCKED'); }
        $lock = null; $item = null; $claimed_id = 0; $token = bin2hex(random_bytes(24)); $table = WP_Seed_Pixel_Job_Store::table('items');
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id);
            if (!$job || !in_array($job['kind'], array('replace', 'retire'), true) || !self::compatible($job) || (int) $job['lease_until'] >= time()) { return new WP_Error('JOB_REQUIRED'); }
            $policy = json_decode($job['policy'], true);
            if (isset($policy['bulk']) && (!is_int($item_id) || $item_id <= 0)) { return new WP_Error('INVALID_STATE'); }
            $item = $item_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE job_id=%d AND kind='operation' AND id=%d", $id, $item_id), ARRAY_A)
                : $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE job_id=%d AND kind='operation' LIMIT 1", $id), ARRAY_A);
            if (!$item || !current_user_can('edit_post', $item['attachment_id']) || !hash_equals($item['snapshot_hash'], hash('sha256', $item['data'])) || !WP_Seed_Pixel_Job_Store::journal_valid($item)) { return new WP_Error('EVIDENCE_INVALID'); }
            if (self::other_claim($item, false)) { return new WP_Error('CLAIM_CONFLICT'); }
            $lock = WP_Seed_Pixel_Files::lock($item['attachment_id']); if (is_wp_error($lock)) { $lock = null; return new WP_Error('LOCKED'); }
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d AND lease_until<%d", $token, time() + 60, $item['id'], $item['revision'], time())) !== 1) { return new WP_Error('LOCKED'); }
            $claimed_id = (int) $item['id'];
            $item = WP_Seed_Pixel_Job_Store::item($item['id']);
            if ($action === 'retain') {
                if ($item['stage'] !== 'retained') { return new WP_Error('INVALID_STATE'); }
                $r = WP_Seed_Pixel_Quarantine::record($item, true); if (is_wp_error($r)) { return $r; }
                $journal = json_decode($item['journal'], true);
                if (!isset($r['quarantine']) || empty($journal['quarantine_hash'])) {
                    $r = WP_Seed_Pixel_Quarantine::retain($item, $r);
                    if (!is_wp_error($r)) {
                        $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'retained', '', array('quarantine_hash' => hash('sha256', wp_json_encode($r['quarantine']))));
                        if (is_wp_error($item)) { return $item; }
                    }
                }
            } elseif ($action === 'purge') {
                if (!in_array($item['stage'], array('retained', 'purge_intent', 'purged'), true)) { return new WP_Error('INVALID_STATE'); }
                if ($item['stage'] === 'retained') {
                    $view = WP_Seed_Pixel_Quarantine::inspect($item);
                    if (is_wp_error($view) || !$view['purge_available'] || ($approval['generation'] ?? '') !== $view['generation'] || ($approval['version'] ?? '') !== WP_Seed_Pixel_Quarantine::AUTHORIZATION || ($approval['irreversible'] ?? false) !== true) { return new WP_Error('PERMISSION_DENIED'); }
                    $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'purge_intent'); if (is_wp_error($item)) { return $item; }
                }
                $r = WP_Seed_Pixel_Quarantine::purge($item, $approval);
                if (!is_wp_error($r) && $item['stage'] !== 'purged') {
                    $receipt = array('authorization' => WP_Seed_Pixel_Quarantine::AUTHORIZATION, 'generation' => $r['purge']['generation'],
                        'removed_bytes' => $r['quarantine']['bytes'], 'physical_absence_verified' => true);
                    $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'purged', '', $receipt); if (is_wp_error($item)) { return $item; }
                    WP_Seed_Pixel_Quarantine::boundary('purge_accounted', $item);
                }
            } else {
                if (!in_array($item['stage'], array('retained', 'recovery_required', 'rolled_back'), true)) { return new WP_Error('RESTORE_UNAVAILABLE'); }
                if ($item['stage'] === 'retained') { $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'recovery_required'); if (is_wp_error($item)) { return $item; } }
                $r = WP_Seed_Pixel_Quarantine::restore($item);
                if (!is_wp_error($r) && $item['stage'] !== 'rolled_back') { $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'rolled_back'); if (is_wp_error($item)) { return $item; } }
            }
            return is_wp_error($r) ? $r : WP_Seed_Pixel_Quarantine::inspect($item);
        } finally {
            if ($claimed_id) { $wpdb->update($table, array('lease' => '', 'lease_until' => 0), array('id' => $claimed_id, 'lease' => $token)); }
            if ($lock) { WP_Seed_Pixel_Files::unlock($lock); }
            WP_Seed_Pixel_Files::unlock($site);
        }
    }

    public static function plan($scan_id, array $input = array()) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        $policy = WP_Seed_Pixel_Policy::normalize($input); if (is_wp_error($policy)) { return $policy; }
        $schema = WP_Seed_Pixel_Job_Store::install(); if (is_wp_error($schema)) { return $schema; }
        $source = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE id=%d AND kind='scan' AND status='complete'", $scan_id), ARRAY_A);
        if (!$source) { return new WP_Error('SCAN_REQUIRED'); }
        $observed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='attachment'", $scan_id));
        $id = WP_Seed_Pixel_Job_Store::insert_job('plan', $scan_id, $policy, $observed);
        return is_wp_error($id) ? $id : self::status($id);
    }

    /** Explicit disposable-only authorization; existing plans never gain this capability. */
    public static function bulk_plan($scan_id, array $input, $capacity_bytes, $operation = 'replace', $lot_size = 1, $original_url_acknowledged = false, $selected_ids = null) {
        if (($input['metadata'] ?? '') === 'anonymize') { return new WP_Error('POLICY_INVALID'); }
        global $wpdb;
        if (!self::allowed() || !WP_Seed_Pixel_Quarantine::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        if (!in_array($operation, array('replace', 'retire'), true) || !is_int($capacity_bytes) || $capacity_bytes <= 0
            || !is_int($lot_size) || $lot_size < 1 || $lot_size > 5) { return new WP_Error('POLICY_INVALID'); }
        $policy = WP_Seed_Pixel_Policy::normalize($input);
        if (is_wp_error($policy) || $policy['intent']['purge'] || $policy['intent']['recovery'] !== 'local_quarantine'
            || ($operation === 'replace' && ($policy['intent']['master'] !== 'replace_verified' || $policy['intent']['original'] !== 'keep'))
            || ($operation === 'retire' && ($policy['intent']['original'] !== 'retire_verified' || $policy['intent']['master'] !== 'keep' || $original_url_acknowledged !== true))) { return new WP_Error('POLICY_INVALID'); }
        $schema = WP_Seed_Pixel_Job_Store::install(); if (is_wp_error($schema)) { return $schema; }
        $source = $wpdb->get_row($wpdb->prepare('SELECT id FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE id=%d AND kind='scan' AND status='complete'", $scan_id), ARRAY_A);
        if (!$source) { return new WP_Error('SCAN_REQUIRED'); }
        if ($selected_ids !== null && (!is_array($selected_ids) || !$selected_ids || count($selected_ids) > 500
            || array_filter($selected_ids, static function ($id) { return !is_int($id) || $id < 1 || !current_user_can('edit_post', $id); })
            || count(array_unique($selected_ids)) !== count($selected_ids))) { return new WP_Error('POLICY_INVALID'); }
        $policy['capability'] = $operation === 'retire' ? WP_Seed_Pixel_Quarantine::ENGINE : WP_Seed_Pixel_Master_Storage::ENGINE;
        $policy['capacity_bytes'] = $capacity_bytes;
        $policy['original_url_acknowledged'] = $original_url_acknowledged === true;
        $policy['effective'] = array('simulation_only' => false, 'replace' => $operation === 'replace', 'retire' => $operation === 'retire', 'purge' => false);
        $policy['bulk'] = array('version' => 1, 'operation' => $operation, 'lot_size' => $lot_size, 'concurrency' => 1);
        if ($selected_ids !== null) { sort($selected_ids, SORT_NUMERIC); $policy['bulk']['selected_ids'] = array_values($selected_ids); }
        $scope = $selected_ids !== null ? ' AND attachment_id IN (' . implode(',', array_map('intval', $selected_ids)) . ')' : '';
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='attachment'" . $scope, $scan_id));
        if ($selected_ids !== null && $total !== count($selected_ids)) { return new WP_Error('SOURCE_CHANGED'); }
        $id = WP_Seed_Pixel_Job_Store::insert_job('plan', $scan_id, $policy, $total);
        return is_wp_error($id) ? $id : self::status($id);
    }

    private static function bulk_item(array $row, array $a, array $policy) {
        $id = (int) $row['attachment_id']; $reason = '';
        if (!current_user_can('edit_post', $id)) { $reason = 'PERMISSION_DENIED'; }
        elseif (isset($policy['bulk']['selected_ids']) && !in_array($id, $policy['bulk']['selected_ids'], true)) { $reason = 'EXCLUDED_BY_POLICY'; }
        elseif (($a['health'] ?? '') !== 'healthy') { $reason = ($a['health'] ?? '') === 'unsupported' ? 'UNSUPPORTED_FORMAT' : 'NEEDS_REVIEW'; }
        elseif (!in_array($a['image']['format'] ?? '', array('jpeg', 'png'), true)) { $reason = 'UNSUPPORTED_FORMAT'; }
        if (!$reason && ($a['image']['format'] ?? '') === 'png') {
            $png = WP_Seed_Pixel_PNG_Processor::inspect(get_attached_file($id));
            if (is_wp_error($png)) { $reason = $png->get_error_code(); }
            elseif ($policy['intent']['dimensions'] !== 'keep') { $reason = 'DIMENSION_CONFLICT'; }
        }
        if (!$reason) {
            $fresh = WP_Seed_Pixel_Analyzer::analyze($id);
            if (is_wp_error($fresh) || WP_Seed_Pixel_Policy::signature($a) !== WP_Seed_Pixel_Policy::signature($fresh)) { $reason = 'SOURCE_CHANGED'; }
        }
        $before = null; $original = null;
        if (!$reason) {
            $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
            if (is_wp_error($before)) { $reason = $before->get_error_code(); $before = null; }
            elseif ($policy['bulk']['operation'] === 'retire') {
                $original = WP_Seed_Pixel_Quarantine::original_snapshot($before);
                if (is_wp_error($original)) { $reason = $original->get_error_code(); $original = null; }
            } elseif (!empty($before['rows']['_seed_pixel_master_state'])) { $reason = 'NO_BENEFIT'; }
        }
        $skip = in_array($reason, array('UNSUPPORTED_FORMAT', 'NO_BENEFIT', 'SOURCE_MISSING', 'EXCLUDED_BY_POLICY'), true);
        return array('before' => $before, 'original' => $original, 'reason' => $reason,
            'peak_bytes' => $reason ? 0 : ($policy['bulk']['operation'] === 'retire'
                ? $original['bytes'] * 2 + 16777216 : $before['bytes'] * 4 + $before['width'] * $before['height'] * 8 + 16777216),
            'planned_bytes' => $reason ? 0 : ($original['bytes'] ?? $before['bytes']),
            'stage' => $reason ? ($skip ? 'skipped' : 'needs_review') : 'queued');
    }

    /** Bounded lot; every item still goes through the existing fenced step. */
    public static function bulk_step($id) {
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        $job = WP_Seed_Pixel_Job_Store::job($id); $p = $job ? json_decode($job['policy'], true) : null;
        if (!$job || empty($p['bulk']) || !self::compatible($job)) { return new WP_Error('JOB_REQUIRED'); }
        $limit = $job['kind'] === 'plan' ? 1 : (int) $p['bulk']['lot_size'];
        for ($n = 0; $n < $limit; $n++) {
            $result = self::step($id);
            if (!is_wp_error($result) && $job['kind'] !== 'plan') { do_action('wp_seed_pixel_m5_boundary', 'item_completed', (int) $id); }
            if (is_wp_error($result) || !in_array($result['status'], array('running', 'queued'), true)) { return $result; }
        }
        return $result;
    }

    private static function plan_step(array $job) {
        global $wpdb;
        $items = WP_Seed_Pixel_Job_Store::table('items'); $jobs = WP_Seed_Pixel_Job_Store::table('jobs');
        $policy = json_decode($job['policy'], true);
        $scope = isset($policy['bulk']['selected_ids']) ? ' AND attachment_id IN (' . implode(',', array_map('intval', $policy['bulk']['selected_ids'])) . ')' : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='attachment' AND id>%d" . $scope . ' ORDER BY id LIMIT 20', $job['source_id'], $job['scan_cursor']), ARRAY_A);
        _prime_post_caches(array_map('intval', array_column($rows, 'attachment_id')), false, false);
        $policy = json_decode($job['policy'], true); $hash = $job['plan_hash']; $cursor = $job['scan_cursor'];
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
        try {
            foreach ($rows as $row) {
                $a = json_decode($row['data'], true); if (!is_array($a)) { throw new RuntimeException('STORE_FAILED'); }
                $a['health'] = $row['stage']; $reason = WP_Seed_Pixel_Policy::eligibility($a, $policy);
                if (isset($policy['bulk'])) {
                    $bulk = self::bulk_item($row, $a, $policy); $stage = $bulk['stage']; unset($bulk['stage']);
                    $json = wp_json_encode($bulk); $hash = hash('sha256', $hash . $json);
                    if (!$wpdb->insert($items, array('job_id' => $job['id'], 'kind' => 'plan', 'item_key' => $row['attachment_id'] . ':' . $policy['bulk']['operation'],
                        'attachment_id' => $row['attachment_id'], 'stage' => $stage, 'action' => $policy['bulk']['operation'], 'bytes' => $bulk['planned_bytes'],
                        'data' => $json, 'snapshot_hash' => hash('sha256', $json), 'journal' => '', 'receipt' => '', 'updated' => time()))) { throw new RuntimeException('STORE_FAILED'); }
                    $cursor = $row['id']; continue;
                }
                if (!current_user_can('edit_post', (int) $row['attachment_id'])) { $reason = 'PERMISSION_DENIED'; }
                $sha = '';
                if (!$reason) {
                    $fresh = WP_Seed_Pixel_Analyzer::analyze((int) $row['attachment_id']);
                    if (is_wp_error($fresh) || WP_Seed_Pixel_Policy::signature($a) !== WP_Seed_Pixel_Policy::signature($fresh)) { $reason = 'SOURCE_CHANGED'; }
                    else { $sha = self::source_hash((int) $row['attachment_id']); if (!$sha) { $reason = 'SOURCE_MISSING'; } }
                }
                $data = array('attachment_id' => (int) $row['attachment_id'], 'analysis_signature' => WP_Seed_Pixel_Policy::signature($a),
                    'source_sha256' => $sha, 'policy_hash' => $job['policy_hash'], 'eligible' => !$reason, 'reason' => $reason,
                    'capacity' => array('quota' => null, 'peak_bytes' => null, 'destructive_ready' => false),
                    'planned_bytes' => (int) ($a['storage']['potential_original_bytes'] ?? 0), 'reclaimed_bytes' => 0);
                $json = wp_json_encode($data); $hash = hash('sha256', $hash . $json);
                if (!$wpdb->insert($items, array('job_id' => $job['id'], 'kind' => 'plan', 'item_key' => $row['attachment_id'] . ':simulate',
                    'attachment_id' => $row['attachment_id'], 'stage' => $reason ? 'skipped' : 'queued', 'action' => 'simulate',
                    'data' => $json, 'snapshot_hash' => hash('sha256', $json), 'journal' => '', 'receipt' => '', 'updated' => time()))) { throw new RuntimeException('STORE_FAILED'); }
                $cursor = $row['id'];
            }
            $status = count($rows) < 20 ? 'completed' : 'queued';
            if ($wpdb->query($wpdb->prepare("UPDATE $jobs SET scan_cursor=%d,plan_hash=%s,status=%s,revision=revision+1,updated=%d WHERE id=%d AND revision=%d", $cursor, $hash, $status, time(), $job['id'], $job['revision'])) !== 1) { throw new RuntimeException('STORE_FAILED'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('STORE_FAILED'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
        return self::status($job['id']);
    }

    private static function source_hash($id) {
        $path = get_attached_file($id, true);
        if (!$path || is_wp_error(WP_Seed_Pixel_Files::path($path)) || !is_readable($path) || filesize($path) > 64000000) { return ''; }
        return @hash_file('sha256', $path) ?: '';
    }

    public static function start($plan_id) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        try {
            $plan = WP_Seed_Pixel_Job_Store::job($plan_id);
            if (!$plan || $plan['kind'] !== 'plan' || $plan['status'] !== 'completed' || !self::compatible($plan)) { return new WP_Error('PLAN_REQUIRED'); }
            $items = WP_Seed_Pixel_Job_Store::table('items'); $jobs = WP_Seed_Pixel_Job_Store::table('jobs');
            $policy = json_decode($plan['policy'], true);
            $kind = isset($policy['bulk']) ? $policy['bulk']['operation'] : 'simulation';
            if ($kind !== 'simulation' && !WP_Seed_Pixel_Quarantine::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
            // A plan may have only one execution. Repeated Start returns that durable job.
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $jobs WHERE kind=%s AND source_id=%d ORDER BY id LIMIT 1", $kind, $plan_id));
            if ($existing) { return self::status($existing); }
            $hash = hash('sha256', 'pixel-plan-v1:' . $plan['policy_hash']); $cursor = 0; $count = 0;
            do {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id,data,snapshot_hash FROM $items WHERE job_id=%d AND kind='plan' AND id>%d ORDER BY id LIMIT 100", $plan_id, $cursor), ARRAY_A);
                foreach ($rows as $row) {
                    if (!hash_equals($row['snapshot_hash'], hash('sha256', $row['data']))) { return new WP_Error('PLAN_CHANGED'); }
                    $hash = hash('sha256', $hash . $row['data']); $cursor = $row['id']; $count++;
                }
            } while (count($rows) === 100);
            if (!hash_equals($plan['plan_hash'], $hash) || $count !== (int) $plan['total']) { return new WP_Error('PLAN_CHANGED'); }
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
            $id = WP_Seed_Pixel_Job_Store::insert_job($kind, $plan_id, $policy, $count);
            if (is_wp_error($id)) { $wpdb->query('ROLLBACK'); return $id; }
            // Smaller peak budgets first; source bytes break ties, never promise encoding savings.
            $peak_order = isset($policy['bulk']) ? "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(data,'$.peak_bytes')) AS UNSIGNED),0)," : '';
            $ok = $wpdb->query($wpdb->prepare("INSERT INTO $items (job_id,kind,item_key,attachment_id,stage,action,bytes,data,snapshot_hash,journal,receipt,updated) SELECT %d,'operation',item_key,attachment_id,stage,action,bytes,data,snapshot_hash,'','',%d FROM $items WHERE job_id=%d AND kind='plan' ORDER BY CASE WHEN stage='queued' THEN 0 ELSE 1 END,$peak_order bytes DESC,id", $id, time(), $plan_id));
            $values = array('status' => 'running', 'plan_hash' => $plan['plan_hash']);
            if ($kind !== 'simulation') { $values['engine'] = $policy['capability']; }
            if ($ok === false || !$wpdb->update($jobs, $values, array('id' => $id)) || $wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
            return self::status($id);
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function step($id, ?WP_Seed_Pixel_Job_Executor $executor = null) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        if ((int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return new WP_Error('ENGINE_INCOMPATIBLE'); }
        $site_lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site_lock)) { return new WP_Error('LOCKED'); }
        $handle = null; $token = ''; $item = null;
        $jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id); if (!$job) { return new WP_Error('JOB_REQUIRED'); }
            if (!self::compatible($job)) { $wpdb->update($jobs, array('status' => 'paused', 'error_code' => 'ENGINE_INCOMPATIBLE'), array('id' => $id)); return new WP_Error('ENGINE_INCOMPATIBLE'); }
            if ($job['kind'] === 'plan') { return $job['status'] === 'queued' ? self::plan_step($job) : self::status($id); }
            $real = in_array($job['kind'], array('replace', 'retire'), true);
            if ($real && (!WP_Seed_Pixel_Master_Storage::enabled() || $executor !== null)) { return new WP_Error('PERMISSION_DENIED'); }
            if ($job['kind'] === 'replace') {
                $cleanup_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $items WHERE job_id=%d AND kind='operation'
                    AND stage='skipped' AND error_code='NO_BENEFIT' AND JSON_VALID(receipt)
                    AND JSON_UNQUOTE(JSON_EXTRACT(receipt,'$.resume_stage')) IN ('queued','preparing','ready')
                    AND JSON_EXTRACT(receipt,'$.cleanup_unreplaced') IS NULL ORDER BY id LIMIT 1", $id));
                if ($cleanup_id) { return self::reconcile_unreplaced_locked($job, (int) $cleanup_id); }
            }
            if ($job['status'] !== 'running') { return self::status($id); }
            if ((int) $job['lease_until'] >= time()) { return new WP_Error('LOCKED'); }
            $token = bin2hex(random_bytes(24));
            if ($wpdb->query($wpdb->prepare("UPDATE $jobs SET lease=%s,lease_until=%d WHERE id=%d AND status='running' AND lease_until<%d", $token, time() + 60, $id, time())) !== 1) { return new WP_Error('CLAIM_CONFLICT'); }
            $terminal = "'retained','purged','rolled_back','skipped','cancelled','failed','needs_review'";
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='operation' AND stage NOT IN ($terminal) ORDER BY CASE WHEN stage='queued' THEN 1 ELSE 0 END,id LIMIT 1", $id), ARRAY_A);
            if (!$item) { return self::finish($id); }
            $handle = WP_Seed_Pixel_Files::lock((int) $item['attachment_id']); if (is_wp_error($handle)) { $handle = null; return new WP_Error('LOCKED'); }
            if ((int) $item['lease_until'] >= time()) { return new WP_Error('LOCKED'); }
            // An incomplete claimed item reserves its attachment even when its job is paused.
            if (self::other_claim($item, true)) { return new WP_Error('CLAIM_CONFLICT'); }
            if ($wpdb->query($wpdb->prepare("UPDATE $items SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d AND lease_until<%d", $token, time() + 60, $item['id'], $item['revision'], time())) !== 1) { return new WP_Error('CLAIM_CONFLICT'); }
            $item = WP_Seed_Pixel_Job_Store::item($item['id']);
            if (!hash_equals($item['snapshot_hash'], hash('sha256', $item['data'])) || !WP_Seed_Pixel_Job_Store::journal_valid($item)) { return self::fail($job, $item, $token, 'EVIDENCE_INVALID'); }
            if ($item['stage'] === 'recovery_required' && in_array($item['error_class'], array('review', 'unsupported'), true)) {
                $blocked = WP_Seed_Pixel_Job_Store::transition($item, $token, 'needs_review', $item['error_code']);
                return is_wp_error($blocked) ? self::systemic($id, 'STORE_FAILED') : self::finish($id);
            }
            if (!current_user_can('edit_post', (int) $item['attachment_id'])) { return self::fail($job, $item, $token, 'PERMISSION_DENIED'); }
            $data = json_decode($item['data'], true); $policy = json_decode($job['policy'], true);
            if (!$real) {
            $fresh = WP_Seed_Pixel_Analyzer::analyze((int) $item['attachment_id']);
            if (is_wp_error($fresh)) { return self::fail($job, $item, $token, 'SOURCE_MISSING'); }
            $reason = WP_Seed_Pixel_Policy::eligibility($fresh, $policy);
            if ($reason) { return self::fail($job, $item, $token, $reason); }
            if ($data['analysis_signature'] !== WP_Seed_Pixel_Policy::signature($fresh) || $data['source_sha256'] !== self::source_hash((int) $item['attachment_id'])) { return self::fail($job, $item, $token, 'SOURCE_CHANGED'); }
            }
            $executor = $job['kind'] === 'retire' ? new WP_Seed_Pixel_Original_Executor() : ($real ? new WP_Seed_Pixel_Master_Executor() : ($executor ?: new WP_Seed_Pixel_Simulated_Executor()));
            $graph_started = WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item) && defined('WP_SEED_PIXEL_RECOVERY_ROOT')
                && file_exists(WP_SEED_PIXEL_RECOVERY_ROOT . '/m3-' . (int) $item['job_id'] . '-' . (int) $item['id'] . '/journal.json');
            if ($item['stage'] !== 'queued' || $graph_started) {
                if ($item['stage'] === 'recovery_required') {
                    if ((int) $item['attempts'] >= 3) {
                        $blocked = WP_Seed_Pixel_Job_Store::transition($item, $token, 'needs_review', 'RETRY_EXHAUSTED');
                        return is_wp_error($blocked) ? self::systemic($id, 'STORE_FAILED') : self::finish($id);
                    }
                    if ($wpdb->query($wpdb->prepare("UPDATE $items SET attempts=attempts+1 WHERE id=%d AND lease=%s AND revision=%d", $item['id'], $token, $item['revision'])) !== 1) { return self::systemic($id, 'STORE_FAILED'); }
                    $item = WP_Seed_Pixel_Job_Store::item($item['id']);
                }
                $reconciled = $executor->reconcile($item, $policy);
                if (is_wp_error($reconciled)) { return self::fail($job, $item, $token, $reconciled->get_error_code()); }
                if ($reconciled === 'rolled_back' && WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item)) {
                    if ($item['stage'] !== 'recovery_required') { $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'recovery_required'); }
                    if (is_wp_error($item)) { return self::systemic($id, 'STORE_FAILED'); }
                    $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'rolled_back');
                    return is_wp_error($item) ? self::systemic($id, 'STORE_FAILED') : self::finish($id);
                }
                if (!in_array($reconciled, self::FLOW, true)) { return self::fail($job, $item, $token, 'EVIDENCE_INVALID'); }
                if ($reconciled !== $item['stage']) {
                    $item = WP_Seed_Pixel_Job_Store::transition($item, $token, $reconciled);
                    if (is_wp_error($item)) { return self::systemic($id, 'STORE_FAILED'); }
                }
            }
            while (!in_array($item['stage'], WP_Seed_Pixel_Job_Store::TERMINAL, true)) {
                $item = WP_Seed_Pixel_Job_Store::renew($item, $token);
                if (is_wp_error($item)) { return self::systemic($id, 'LOCKED'); }
                $position = array_search($item['stage'], self::FLOW, true);
                if ($position === false) { return self::fail($job, $item, $token, 'EVIDENCE_INVALID'); }
                $next = self::FLOW[$position + 1];
                // The intent is durable before invoking an adapter; retry uses the same effect key.
                if ($item['stage'] === 'ready') {
                    $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'switch_intent');
                    if (is_wp_error($item)) { return self::systemic($id, 'STORE_FAILED'); }
                    continue;
                }
                $receipt = $executor->execute($item['stage'], $item, $policy);
                $item = WP_Seed_Pixel_Job_Store::renew($item, $token);
                if (is_wp_error($item)) { return self::systemic($id, 'LOCKED'); }
                if (is_wp_error($receipt)) { return self::fail($job, $item, $token, $receipt->get_error_code()); }
                if (!is_array($receipt) || ($receipt['simulation'] ?? null) !== !$real || !isset($receipt['effect_key']) || ($receipt['reclaimed_bytes'] ?? -1) !== 0 || (!$real && ($receipt['encoded'] ?? -1) !== 0) || ($receipt['deleted'] ?? -1) !== 0) { return self::fail($job, $item, $token, 'EVIDENCE_INVALID'); }
                // Persist only our small whitelist, never arbitrary executor diagnostics or paths.
                $receipt = array('simulation' => !$real, 'stage' => $item['stage'], 'effect_key' => hash('sha256', (string) $receipt['effect_key']), 'encoded' => $real ? (int) $receipt['encoded'] : 0, 'deleted' => 0, 'reclaimed_bytes' => 0,
                    'active_delta' => $real ? (int) ($receipt['active_delta'] ?? 0) : 0, 'recovery_bytes' => $real ? (int) ($receipt['recovery_bytes'] ?? 0) : 0);
                if ($real && $next === 'retained' && WP_Seed_Pixel_Quarantine::enabled()) {
                    $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
                    $record = is_wp_error($dir) ? null : WP_Seed_Pixel_Master_Storage::load($dir, $item);
                    if (isset($policy['bulk']) || isset($policy['future_upload_generation']) || ($policy['intent']['metadata'] ?? '') === 'anonymize') {
                        if (!is_array($record)) { return self::fail($job, $item, $token, 'EVIDENCE_INVALID'); }
                        $record = WP_Seed_Pixel_Quarantine::retain($item, $record);
                        if (is_wp_error($record)) { return self::fail($job, $item, $token, $record->get_error_code()); }
                    }
                    if (is_array($record) && isset($record['quarantine'])) { $receipt['quarantine_hash'] = hash('sha256', wp_json_encode($record['quarantine'])); }
                }
                $item = WP_Seed_Pixel_Job_Store::transition($item, $token, $next, '', $receipt);
                if (is_wp_error($item)) { return self::systemic($id, 'STORE_FAILED'); }
            }
            $wpdb->update($jobs, array('failures' => 0, 'error_code' => '', 'updated' => time()), array('id' => $id, 'lease' => $token));
            return self::finish($id);
        } catch (Throwable $error) { return self::systemic($id, 'STORE_FAILED'); }
        finally {
            if ($token) {
                $wpdb->update($items, array('lease' => '', 'lease_until' => 0), array('job_id' => $id, 'lease' => $token));
                $wpdb->update($jobs, array('lease' => '', 'lease_until' => 0), array('id' => $id, 'lease' => $token));
            }
            if ($handle) { WP_Seed_Pixel_Files::unlock($handle); }
            WP_Seed_Pixel_Files::unlock($site_lock);
        }
    }

    public static function restore_master($id) {
        global $wpdb;
        if (!self::allowed() || !WP_Seed_Pixel_Master_Storage::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return new WP_Error('LOCKED'); }
        $lock = null; $token = bin2hex(random_bytes(24)); $item = null;
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id);
            if (!$job || $job['kind'] !== 'replace' || !self::compatible($job)) { return new WP_Error('JOB_REQUIRED'); }
            $policy = json_decode($job['policy'], true);
            if (isset($policy['bulk'])) { return new WP_Error('INVALID_STATE'); }
            $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE job_id=%d AND kind=%s LIMIT 1', $id, 'operation'), ARRAY_A);
            if (!$item || !current_user_can('edit_post', $item['attachment_id'])) { return new WP_Error('PERMISSION_DENIED'); }
            if ($item['stage'] === 'rolled_back') { return self::status($id); }
            if (!in_array($item['stage'], array('retained', 'recovery_required', 'switch_intent', 'switched', 'verified'), true)) { return new WP_Error('RECOVERY_REQUIRED'); }
            $lock = WP_Seed_Pixel_Files::lock($item['attachment_id']); if (is_wp_error($lock)) { $lock = null; return new WP_Error('LOCKED'); }
            if ($wpdb->query($wpdb->prepare('UPDATE ' . WP_Seed_Pixel_Job_Store::table('items') . ' SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d AND lease_until<%d', $token, time() + 60, $item['id'], $item['revision'], time())) !== 1) { return new WP_Error('LOCKED'); }
            $item = WP_Seed_Pixel_Job_Store::item($item['id']);
            if (!WP_Seed_Pixel_Job_Store::journal_valid($item) || !hash_equals($item['snapshot_hash'], hash('sha256', $item['data']))) { return new WP_Error('EVIDENCE_INVALID'); }
            if ($item['stage'] !== 'recovery_required') {
                $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'recovery_required'); if (is_wp_error($item)) { return $item; }
            }
            $r = WP_Seed_Pixel_Master_Storage::restore($item);
            if (is_wp_error($r)) {
                $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('status' => 'paused', 'error_code' => $r->get_error_code()), array('id' => $id));
                return $r;
            }
            $item = WP_Seed_Pixel_Job_Store::transition($item, $token, 'rolled_back'); if (is_wp_error($item)) { return $item; }
            $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('status' => 'completed', 'error_code' => ''), array('id' => $id));
            return self::status($id);
        } finally {
            $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('lease' => '', 'lease_until' => 0), array('job_id' => $id, 'lease' => $token));
            if ($lock) { WP_Seed_Pixel_Files::unlock($lock); }
            WP_Seed_Pixel_Files::unlock($site);
        }
    }

    private static function systemic($id, $code) {
        global $wpdb;
        $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('status' => 'failed_systemic', 'error_code' => $code, 'updated' => time()), array('id' => $id));
        return new WP_Error($code);
    }
    private static function fail(array $job, array $item, $token, $code) {
        global $wpdb;
        $known = array('NO_BENEFIT', 'SOURCE_MISSING', 'SOURCE_CHANGED', 'SHARED_PATH', 'METADATA_CONFLICT', 'UNSUPPORTED_FORMAT', 'UNSUPPORTED_STORAGE', 'LOW_DISK', 'QUOTA_UNKNOWN', 'BACKEND_UNAVAILABLE', 'ICC_UNSAFE', 'CANDIDATE_INVALID', 'BACKUP_FAILED', 'SWAP_FAILED', 'VERIFY_FAILED', 'PURGE_FAILED', 'LOCKED', 'CONFLICTING_OPTIMIZER', 'REQUEST_INTERRUPTED', 'STORE_FAILED', 'EVIDENCE_INVALID', 'PERMISSION_DENIED', 'NEEDS_REVIEW', 'DIMENSION_CONFLICT');
        $known[] = 'CEILING_EXCEEDED';
        $known = array_merge($known, array('METADATA_GRAPH_CHANGED', 'METADATA_PUBLIC_COPY', 'METADATA_ORIENTATION', 'METADATA_PROVENANCE', 'METADATA_REVIEW', 'METADATA_INVALID', 'RECOVERY_REQUIRED'));
        if (!in_array($code, $known, true)) { $code = 'EVIDENCE_INVALID'; }
        $class = WP_Seed_Pixel_Job_Store::error_class($code);
        $stage = in_array($class, array('retryable', 'systemic', 'conflict'), true) ? 'failed' : 'needs_review';
        $recover = in_array($item['stage'], array('switch_intent', 'switched', 'verified'), true);
        $policy = json_decode($job['policy'], true);
        $stale_bulk = isset($policy['bulk']) && !$recover && in_array($code, array('SOURCE_CHANGED', 'SOURCE_MISSING', 'METADATA_CONFLICT'), true);
        if ($stale_bulk) { $stage = 'needs_review'; }
        if ($recover) { $stage = 'recovery_required'; }
        if ($code === 'NO_BENEFIT' && !$recover) { $stage = 'skipped'; }
        $result = WP_Seed_Pixel_Job_Store::transition($item, $token, $stage, $code, array('simulation' => !in_array($job['kind'], array('replace', 'retire'), true), 'resume_stage' => $item['stage']));
        if (is_wp_error($result)) { return self::systemic($job['id'], 'STORE_FAILED'); }
        if ($job['kind'] === 'replace' && !$recover) {
            do_action('wp_seed_pixel_m3_boundary', 'terminal_decision', (int) $result['id']);
            $cleanup = WP_Seed_Pixel_Master_Storage::cleanup_unreplaced($result, $token);
            if (!is_wp_error($cleanup)) {
                $result = WP_Seed_Pixel_Job_Store::record_cleanup($result, $token, $cleanup);
                if (is_wp_error($result)) { return self::systemic($job['id'], 'STORE_FAILED'); }
            }
        }
        $individual_review = $stale_bulk || (isset($policy['bulk']) && !$recover && in_array($class, array('review', 'unsupported'), true));
        $failures = (int) $job['failures'] + ($individual_review ? 0 : 1);
        $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('failures' => $failures, 'error_code' => $code,
            'status' => $individual_review ? 'running' : ($class === 'systemic' ? 'failed_systemic' : ($recover || $failures >= 2 ? 'paused' : 'running')), 'updated' => time()), array('id' => $job['id']));
        return !$individual_review && ($class === 'systemic' || $recover || $failures >= 2) ? self::status($job['id']) : self::finish($job['id']);
    }
    private static function finish($id) {
        global $wpdb;
        $items = WP_Seed_Pixel_Job_Store::table('items');
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE job_id=%d AND kind='operation' AND stage NOT IN ('retained','purged','rolled_back','skipped','cancelled','failed','needs_review')", $id));
        if (!$pending) {
            $errors = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE job_id=%d AND kind='operation' AND stage IN ('failed','needs_review')", $id));
            $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('status' => $errors ? 'completed_errors' : 'completed', 'updated' => time()), array('id' => $id, 'status' => 'running'));
        }
        return self::status($id);
    }

    /** Official no-encoding reconciliation for a terminal pre-swap item, including older builds. */
    public static function reconcile_unreplaced($id, $item_id) {
        if (!self::allowed() || !WP_Seed_Pixel_Master_Storage::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return new WP_Error('LOCKED'); }
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id);
            if (!$job || $job['kind'] !== 'replace' || !self::compatible($job)) { return new WP_Error('JOB_REQUIRED'); }
            return self::reconcile_unreplaced_locked($job, (int) $item_id);
        } finally { WP_Seed_Pixel_Files::unlock($site); }
    }

    private static function reconcile_unreplaced_locked(array $job, $item_id) {
        global $wpdb;
        $item = WP_Seed_Pixel_Job_Store::item($item_id); $lock = null; $token = bin2hex(random_bytes(24));
        $jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
        if (!$item || (int) $item['job_id'] !== (int) $job['id'] || !current_user_can('edit_post', (int) $item['attachment_id'])
            || (int) $job['lease_until'] >= time() || (int) $item['lease_until'] >= time()) { return new WP_Error('LOCKED'); }
        if (self::other_claim($item, false)) { return new WP_Error('CLAIM_CONFLICT'); }
        try {
            $lock = WP_Seed_Pixel_Files::lock((int) $item['attachment_id']); if (is_wp_error($lock)) { $lock = null; return new WP_Error('LOCKED'); }
            if ($wpdb->query($wpdb->prepare("UPDATE $jobs SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d AND lease_until<%d",
                $token, time() + 60, $job['id'], $job['revision'], time())) !== 1) { return new WP_Error('LOCKED'); }
            if ($wpdb->query($wpdb->prepare("UPDATE $items SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d AND lease_until<%d",
                $token, time() + 60, $item_id, $item['revision'], time())) !== 1) { return new WP_Error('LOCKED'); }
            $item = WP_Seed_Pixel_Job_Store::item($item_id);
            $cleanup = WP_Seed_Pixel_Master_Storage::cleanup_unreplaced($item, $token); if (is_wp_error($cleanup)) { return $cleanup; }
            $receipt = json_decode($item['receipt'], true);
            if (empty($receipt['cleanup_unreplaced'])) {
                $item = WP_Seed_Pixel_Job_Store::record_cleanup($item, $token, $cleanup); if (is_wp_error($item)) { return $item; }
            }
            return self::finish($job['id']);
        } finally {
            $wpdb->update($items, array('lease' => '', 'lease_until' => 0), array('id' => $item_id, 'lease' => $token));
            $wpdb->update($jobs, array('lease' => '', 'lease_until' => 0), array('id' => $job['id'], 'lease' => $token));
            if ($lock) { WP_Seed_Pixel_Files::unlock($lock); }
        }
    }

    public static function control($id, $operation) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        if ((int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return new WP_Error('ENGINE_INCOMPATIBLE'); }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id); if (!$job || !in_array($job['kind'], array('simulation', 'replace', 'retire'), true)) { return new WP_Error('JOB_REQUIRED'); }
            if (!self::compatible($job)) { return new WP_Error('ENGINE_INCOMPATIBLE'); }
            if ((int) $job['lease_until'] >= time()) { return new WP_Error('LOCKED'); }
            $items = WP_Seed_Pixel_Job_Store::table('items'); $jobs = WP_Seed_Pixel_Job_Store::table('jobs');
            if ($operation === 'pause' && $job['status'] === 'running') { $next = 'paused'; }
            elseif ($operation === 'resume' && in_array($job['status'], array('paused', 'running', 'failed_systemic'), true)) { $next = 'running'; }
            elseif ($operation === 'cancel' && in_array($job['status'], array('running', 'paused', 'failed_systemic'), true)) {
                if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE job_id=%d AND kind='operation' AND stage NOT IN ('queued','retained','purged','rolled_back','skipped','cancelled','failed','needs_review')", $id))) { return new WP_Error('RECOVERY_REQUIRED'); }
                $next = 'cancelled';
            } elseif ($operation === 'retry' && in_array($job['status'], array('paused', 'failed_systemic', 'completed_errors'), true)) { $next = 'paused'; }
            else { return new WP_Error('INVALID_STATE'); }
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
            if ($operation === 'cancel') {
                $ok = $wpdb->query($wpdb->prepare("UPDATE $items SET stage='cancelled',revision=revision+1,updated=%d WHERE job_id=%d AND kind='operation' AND stage='queued'", time(), $id));
            } elseif ($operation === 'retry') {
                $ok = $wpdb->query($wpdb->prepare("UPDATE $items SET stage='queued',attempts=attempts+1,revision=revision+1,lease='',lease_until=0,updated=%d WHERE job_id=%d AND kind='operation' AND stage='failed' AND error_class IN ('retryable','conflict','systemic') AND attempts<3", time(), $id));
                if (!$ok) { $wpdb->query('ROLLBACK'); return new WP_Error('RETRY_UNAVAILABLE'); }
            } else { $ok = true; }
            if ($ok === false || $wpdb->query($wpdb->prepare("UPDATE $jobs SET status=%s,revision=revision+1,failures=0,lease='',lease_until=0,updated=%d WHERE id=%d AND revision=%d", $next, time(), $id, $job['revision'])) !== 1 || $wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
            return self::status($id);
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function status($id = 0) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        if ((int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return array('id' => 0); }
        if (!$id) { $id = $wpdb->get_var('SELECT MAX(id) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE kind IN ('plan','simulation','replace','retire')"); }
        $job = WP_Seed_Pixel_Job_Store::job($id); if (!$job) { return array('id' => 0); }
        $counts = $wpdb->get_results($wpdb->prepare('SELECT stage,COUNT(*) AS count FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE job_id=%d AND kind=%s GROUP BY stage', $id, $job['kind'] === 'plan' ? 'plan' : 'operation'), ARRAY_A);
        $out = array_intersect_key($job, array_flip(array('id', 'kind', 'status', 'total', 'policy_hash', 'plan_hash', 'source_id', 'error_code')));
        $out['states'] = array(); $out['done'] = 0; $out['planned'] = 0; $out['simulation_only'] = !in_array($job['kind'], array('replace', 'retire'), true); $out['reclaimed_bytes'] = 0;
        foreach ($counts as $row) { $out['states'][$row['stage']] = (int) $row['count']; $out['planned'] += (int) $row['count']; if (in_array($row['stage'], WP_Seed_Pixel_Job_Store::TERMINAL, true)) { $out['done'] += (int) $row['count']; } }
        $policy = json_decode($job['policy'], true);
        if (isset($policy['bulk'])) {
            $out['bulk'] = $policy['bulk']; $out['policy_intent'] = $policy['intent'];
            $table = WP_Seed_Pixel_Job_Store::table('items');
            $journal = "CASE WHEN JSON_VALID(journal) THEN journal ELSE '{}' END";
            $sql = "SELECT COALESCE(SUM(bytes),0) AS before_bytes,
                COALESCE(SUM(CASE WHEN stage IN ('retained','purged') THEN CAST(JSON_UNQUOTE(JSON_EXTRACT($journal,'$.storage.active_delta')) AS SIGNED) ELSE 0 END),0) AS active_saved_bytes,
                COALESCE(SUM(CASE WHEN stage='retained' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT($journal,'$.storage.recovery_bytes')) AS SIGNED) ELSE 0 END),0) AS retained_recovery_bytes,
                COALESCE(SUM(CASE WHEN stage='purged' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT($journal,'$.storage.removed_bytes')) AS SIGNED) ELSE 0 END),0) AS removed_source_bytes
                FROM $table WHERE job_id=%d AND kind=%s";
            $metrics = $wpdb->get_row($wpdb->prepare($sql, $id, $job['kind'] === 'plan' ? 'plan' : 'operation'), ARRAY_A);
            if ($wpdb->last_error || !$metrics) { return new WP_Error('STORE_FAILED'); }
            $out['storage'] = array_map('intval', $metrics);
            $out['storage']['allocated_reclaimed_bytes'] = null;
            $out['storage']['net_reclaimed_bytes'] = null;
            $out['storage']['potential_purge_bytes'] = null;
            $out['storage']['measurements'] = 'durable_item_receipts_not_current_quota';
        }
        return $out;
    }
    public static function results($id, $page = 1) {
        global $wpdb;
        if (!self::allowed()) { return new WP_Error('PERMISSION_DENIED'); }
        $job = WP_Seed_Pixel_Job_Store::job($id); if (!$job) { return array('items' => array(), 'pages' => 1, 'page' => 1); }
        $bulk = isset(json_decode($job['policy'], true)['bulk']);
        $page = max(1, (int) $page); $table = WP_Seed_Pixel_Job_Store::table('items'); $kind = $job['kind'] === 'plan' ? 'plan' : 'operation';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE job_id=%d AND kind=%s ORDER BY id LIMIT 20 OFFSET %d", $id, $kind, ($page - 1) * 20), ARRAY_A);
        _prime_post_caches(array_map('intval', array_column($rows, 'attachment_id')), false, false);
        $real_ids = array();
        foreach ($rows as $item) { if (is_array(json_decode($item['data'], true)['before'] ?? null)) { $real_ids[] = (int) $item['attachment_id']; } }
        if (!$bulk && $real_ids) { update_meta_cache('post', $real_ids); }
        $out = array();
        foreach ($rows as $row) {
            if (!current_user_can('edit_post', (int) $row['attachment_id'])) { continue; }
            $item = $row; $data = json_decode($row['data'], true);
            $row = array_intersect_key($row, array_flip(array('id','attachment_id','stage','error_code','error_class','attempts','revision')));
            $row['reason'] = $data['reason']; $row['planned_bytes'] = $data['planned_bytes']; $row['reclaimed_bytes'] = 0;
            if ($bulk && WP_Seed_Pixel_Job_Store::journal_valid($item)) {
                $row['storage_receipt'] = json_decode($item['journal'], true)['storage'] ?? array();
            }
            if (!$bulk && $job['kind'] === 'replace' && is_array($data['before'] ?? null) && WP_Seed_Pixel_Master_Storage::enabled()) {
                $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
                $record = is_wp_error($dir) ? null : WP_Seed_Pixel_Master_Storage::load($dir, $item);
                $row['before_bytes'] = (int) $data['before']['bytes'];
                $row['recovery_bytes'] = !is_wp_error($dir) && is_file($dir . '/recovery.jpg') ? filesize($dir . '/recovery.jpg') : 0;
                if (is_array($record) && $record['candidate']) {
                    $row['candidate'] = array_intersect_key($record['candidate'], array_flip(array('sha256', 'bytes', 'width', 'height', 'backend', 'quality', 'metric', 'processor')));
                    $active = WP_Seed_Pixel_Master_Adapter::path($data['before']);
                    if (!is_wp_error($active)) {
                        $info = @getimagesize($active);
                        $row['current_master'] = array('sha256' => hash_file('sha256', $active), 'bytes' => filesize($active), 'width' => $info[0] ?? null, 'height' => $info[1] ?? null);
                        $row['active_delta'] = $row['before_bytes'] - $row['current_master']['bytes'];
                    }
                }
            }
            if (!$bulk && in_array($job['kind'], array('replace', 'retire'), true) && is_array($data['before'] ?? null) && WP_Seed_Pixel_Quarantine::enabled()) {
                $view = WP_Seed_Pixel_Quarantine::inspect($item);
                if (!is_wp_error($view)) { $row['quarantine'] = $view; }
            }
            $out[] = $row;
        }
        return array('items' => $out, 'page' => $page, 'pages' => max(1, (int) ceil($job['total'] / 20)));
    }

    /** Explicit read-only audit. Bounded pages, no persisted aggregate can override item truth. */
    public static function storage_audit($id) {
        global $wpdb;
        if (!self::allowed() || !WP_Seed_Pixel_Quarantine::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        try {
            $job = WP_Seed_Pixel_Job_Store::job($id);
            if (!$job || !self::compatible($job) || !in_array($job['kind'], array('replace','retire'), true)) { return new WP_Error('JOB_REQUIRED'); }
            $out = array('current_active_bytes' => 0, 'active_saved_bytes' => 0, 'quarantine_bytes' => 0,
                'potential_purge_bytes' => 0, 'removed_source_bytes' => 0, 'allocated_reclaimed_bytes' => 0,
                'audit_bytes' => 0, 'temporary_bytes' => 0, 'net_reclaimed_bytes' => 0, 'unknown_items' => 0,
                'scope' => 'planned_operational_sources_only', 'quota_bytes' => null, 'measured_at' => time());
            $cursor = 0; $seen = array();
            do {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation' AND id>%d ORDER BY id LIMIT 20", $id, $cursor), ARRAY_A);
                if ($wpdb->last_error) { return new WP_Error('STORE_FAILED'); }
                _prime_post_caches(array_map('intval', array_column($rows, 'attachment_id')), false, true);
                foreach ($rows as $item) {
                    $cursor = (int) $item['id']; $data = json_decode($item['data'], true);
                    $before = $data['before'] ?? null;
                    if (!is_array($before)) { continue; }
                    if (!current_user_can('edit_post', $item['attachment_id']) || isset($seen[$item['attachment_id']])) { $out['unknown_items']++; continue; }
                    $seen[$item['attachment_id']] = true;
                    if (!WP_Seed_Pixel_Job_Store::journal_valid($item) || !hash_equals($item['snapshot_hash'], hash('sha256', $item['data']))) { $out['unknown_items']++; continue; }
                    $source = $data['original'] ?? $before;
                    if (in_array($item['stage'], array('retained','purged','rolled_back','purge_intent','recovery_required'), true)) {
                        $v = WP_Seed_Pixel_Quarantine::inspect($item);
                        if (is_wp_error($v) || $v['active_delta'] === null || $v['state'] === 'needs_review') { $out['unknown_items']++; continue; }
                        $out['current_active_bytes'] += $source['bytes'] - $v['active_delta'];
                        foreach (array('active_saved_bytes'=>'active_delta','quarantine_bytes'=>'quarantine_bytes','potential_purge_bytes'=>'potential_purge_bytes','removed_source_bytes'=>'removed_bytes','audit_bytes'=>'audit_bytes','temporary_bytes'=>'temporary_bytes','net_reclaimed_bytes'=>'net_reclaimed_bytes') as $k=>$vkey) { $out[$k] += $v[$vkey]; }
                        if ($v['removed_bytes'] && $v['allocated_bytes'] === null) { $out['allocated_reclaimed_bytes'] = null; }
                        elseif ($out['allocated_reclaimed_bytes'] !== null) { $out['allocated_reclaimed_bytes'] += (int) $v['allocated_bytes']; }
                    } else {
                        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($item['attachment_id'], false, WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item));
                        if (is_wp_error($fresh) || $fresh !== $before) { $out['unknown_items']++; continue; }
                        $pending = WP_Seed_Pixel_Quarantine::pending_overhead($item);
                        if (is_wp_error($pending)) { $out['unknown_items']++; continue; }
                        $out['current_active_bytes'] += $source['bytes'];
                        $out['audit_bytes'] += $pending['audit_bytes']; $out['temporary_bytes'] += $pending['temporary_bytes'];
                        $out['net_reclaimed_bytes'] -= $pending['audit_bytes'] + $pending['temporary_bytes'];
                    }
                }
            } while (count($rows) === 20);
            $out['complete'] = $out['unknown_items'] === 0;
            if (!$out['complete']) {
                $keys = array('current_active_bytes','active_saved_bytes','quarantine_bytes','potential_purge_bytes','audit_bytes','temporary_bytes','net_reclaimed_bytes');
                $out['known_subtotals'] = array_intersect_key($out, array_flip($keys));
                foreach ($keys as $key) { $out[$key] = null; }
            }
            return $out;
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }
}
