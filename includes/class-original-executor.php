<?php
defined('ABSPATH') || exit;

/** Retires the separately recorded uploaded JPEG without encoding or changing the master. */
final class WP_Seed_Pixel_Original_Executor implements WP_Seed_Pixel_Job_Executor {
    public function reconcile(array $item, array $policy) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item); if (is_wp_error($r)) { return $r; }
        if (!$r) { return $item['stage']; }
        if (($r['retired_original'] ?? null) !== (json_decode($item['data'], true)['original'] ?? null)) { return new WP_Error('EVIDENCE_INVALID'); }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
        return $item['stage'] === 'recovery_required' ? ($o['meta_after'] ? 'switched' : 'switch_intent') : $item['stage'];
    }

    public function execute($stage, array $item, array $policy) {
        if (!WP_Seed_Pixel_Quarantine::mutation_allowed($item) || $item['action'] !== 'retire') { return new WP_Error('PERMISSION_DENIED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item); if (is_wp_error($r)) { return $r; }
        $data = json_decode($item['data'], true); $original = $data['original'];
        if ($r && (($r['retired_original'] ?? null) !== $original || ($r['candidate']['sha256'] ?? '') !== $data['before']['sha256'])) { return new WP_Error('EVIDENCE_INVALID'); }
        $p = wp_upload_dir(null, false)['basedir'] . '/' . $original['relative']; $q = $dir . '/recovery.jpg';
        if (!$r) {
            $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($item['attachment_id']);
            $orig = is_wp_error($fresh) ? $fresh : WP_Seed_Pixel_Quarantine::original_snapshot($fresh);
            if (is_wp_error($orig) || $fresh !== $data['before'] || $orig !== $original) { return new WP_Error('SOURCE_CHANGED'); }
            $budget = $original['bytes'] * 2 + 16777216;
            $admission = WP_Seed_Pixel_Storage_Budget::admit($budget, $dir); if (is_wp_error($admission)) { return $admission; }
            if ($policy['capacity_bytes'] < $budget) { return new WP_Error('QUOTA_UNKNOWN'); }
            if (disk_free_space($dir) < $budget) { return new WP_Error('LOW_DISK'); }
            if (stat($dir)['dev'] !== stat($p)['dev']) { return new WP_Error('UNSUPPORTED_STORAGE'); }
            $after = maybe_unserialize($fresh['rows']['_wp_attachment_metadata'][0]); unset($after['original_image']);
            $r = array('job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'], 'policy_hash' => WP_Seed_Pixel_Policy::hash($policy),
                'before' => $fresh, 'retired_original' => $original, 'candidate' => array_intersect_key($fresh, array_flip(array('sha256', 'bytes', 'width', 'height'))),
                'after' => $after, 'phase' => 'preparing', 'peak_budget' => $budget);
            $r['witness'] = array('engine' => WP_Seed_Pixel_Quarantine::ENGINE, 'job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'],
                'source_sha256' => $fresh['sha256'], 'sha256' => $fresh['sha256'], 'policy_hash' => $r['policy_hash']);
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            WP_Seed_Pixel_Quarantine::boundary('original_intent', $item);
        }
        if ($stage === 'preparing') {
            $admission = WP_Seed_Pixel_Storage_Budget::admit($r['peak_budget'], $dir); if (is_wp_error($admission)) { return $admission; }
            if (is_link($p) || is_link($q) || hash_file('sha256', $p) !== $original['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
            if (!file_exists($q)) {
                $in = fopen($p, 'rb'); $out = fopen($q, 'xb');
                if (!$in || !$out) { return new WP_Error('BACKUP_FAILED'); }
                $ok = stream_copy_to_stream($in, $out) === $original['bytes'] && fflush($out) && fsync($out); fclose($in); fclose($out);
                if (!$ok) { return new WP_Error('BACKUP_FAILED'); }
            }
            if (hash_file('sha256', $q) !== $original['sha256']) { return new WP_Error('BACKUP_FAILED'); }
            $r['phase'] = 'ready'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r);
            WP_Seed_Pixel_Quarantine::boundary('original_escrow', $item);
        } elseif ($stage === 'switch_intent') {
            if (is_link($q) || hash_file('sha256', $q) !== $original['sha256']) { return new WP_Error('BACKUP_FAILED'); }
            $r['phase'] = 'switch_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            $meta = WP_Seed_Pixel_Master_Adapter::reconcile($r); if (is_wp_error($meta)) { return $meta; }
            WP_Seed_Pixel_Quarantine::boundary('original_metadata', $item);
        } elseif ($stage === 'switched') {
            $o = WP_Seed_Pixel_Master_Adapter::observe($r);
            if (is_wp_error($o) || !$o['meta_after'] || !$o['witness_after'] || is_link($q) || hash_file('sha256', $q) !== $original['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
            $r['phase'] = 'original_move_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            WP_Seed_Pixel_Quarantine::boundary('original_pre_move', $item);
            if (!WP_Seed_Pixel_Quarantine::mutation_allowed($item)) { return new WP_Error('LOCKED'); }
            $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
            if (!$o['meta_after'] || !$o['witness_after'] || is_link($q) || lstat($q)['nlink'] !== 1 || hash_file('sha256', $q) !== $original['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
            $refs = WP_Seed_Pixel_Quarantine::original_references(basename($original['relative']), $item['attachment_id']);
            if (is_wp_error($refs)) { return $refs; }
            if (file_exists($p) || is_link($p)) {
                $stat = lstat($p);
                if (is_link($p) || $stat['nlink'] !== 1 || hash_file('sha256', $p) !== $original['sha256'] || $stat['uid'] !== $original['uid'] || !rename($p, $q)) { return new WP_Error('SWAP_FAILED'); }
            }
            if (!WP_Seed_Pixel_Master_Storage::sync_directory($dir) || !WP_Seed_Pixel_Master_Storage::sync_directory(dirname($p))) { return new WP_Error('SWAP_FAILED'); }
            WP_Seed_Pixel_Quarantine::boundary('original_moved', $item);
            $r['phase'] = 'verified'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r);
        } elseif ($stage === 'verified') {
            $verify = WP_Seed_Pixel_Master_Storage::verify($item, $r); if (is_wp_error($verify)) { return $verify; }
            $r = WP_Seed_Pixel_Quarantine::retain($item, $r); if (is_wp_error($r)) { return $r; }
            WP_Seed_Pixel_Quarantine::boundary('original_retained', $item);
        }
        if (is_wp_error($r)) { return $r; }
        return array('simulation' => false, 'effect_key' => $item['id'] . ':' . $stage, 'encoded' => 0, 'deleted' => 0,
            'reclaimed_bytes' => 0, 'active_delta' => in_array($stage, array('switched', 'verified'), true) ? $original['bytes'] : 0, 'recovery_bytes' => is_file($q) ? filesize($q) : 0);
    }

    public static function restore(array $item, array $r) {
        if (!WP_Seed_Pixel_Quarantine::mutation_allowed($item)) { return new WP_Error('PERMISSION_DENIED'); }
        $record = WP_Seed_Pixel_Quarantine::record($item);
        if (is_wp_error($record) || $record !== $r || in_array($r['phase'], array('purge_intent', 'purged'), true)) { return new WP_Error('EVIDENCE_INVALID'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); $q = $dir . '/recovery.jpg'; $original = $r['retired_original'];
        $p = wp_upload_dir(null, false)['basedir'] . '/' . $original['relative'];
        $candidate = $dir . '/restore.jpg';
        if (isset($r['restore_candidate']) && is_file($candidate) && is_file($p) && !is_link($candidate) && !is_link($p)) {
            $s = lstat($candidate); $target = lstat($p);
            if ($s['dev'] !== $r['restore_candidate']['dev'] || $s['ino'] !== $r['restore_candidate']['ino'] || $s['dev'] !== $target['dev'] || $s['ino'] !== $target['ino']
                || $s['nlink'] !== 2 || hash_file('sha256', $candidate) !== $original['sha256']) { return new WP_Error('EVIDENCE_INVALID'); }
            if (!unlink($candidate) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('SWAP_FAILED'); }
        }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
        if (is_link($q) || hash_file('sha256', $q) !== $original['sha256'] || is_link($p) || (file_exists($p) && hash_file('sha256', $p) !== $original['sha256'])) { return new WP_Error('SOURCE_CHANGED'); }
        $r['phase'] = 'rollback_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        if (!file_exists($p)) {
            if (disk_free_space(dirname($p)) < $original['bytes'] + 16777216) { return new WP_Error('LOW_DISK'); }
            if (is_link($candidate)) { return new WP_Error('EVIDENCE_INVALID'); }
            if (!file_exists($candidate)) {
                $in = fopen($q, 'rb'); $out = fopen($candidate, 'xb');
                if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return new WP_Error('BACKUP_FAILED'); }
                $ok = stream_copy_to_stream($in, $out) === $original['bytes'] && fflush($out) && fsync($out); fclose($in); fclose($out);
                if (!$ok) { return new WP_Error('BACKUP_FAILED'); }
            }
            if (lstat($candidate)['nlink'] !== 1 || hash_file('sha256', $candidate) !== $original['sha256'] || !chmod($candidate, $original['mode']) || !chgrp($candidate, $original['gid'])) { return new WP_Error('BACKUP_FAILED'); }
            $s = lstat($candidate); $r['restore_candidate'] = array('dev' => $s['dev'], 'ino' => $s['ino']);
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            $fresh = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($fresh) || $fresh !== $o) { return new WP_Error('SOURCE_CHANGED'); }
            WP_Seed_Pixel_Quarantine::boundary('original_restore_ready', $item);
            $fresh = WP_Seed_Pixel_Master_Adapter::observe($r);
            if (is_wp_error($fresh) || $fresh !== $o || !WP_Seed_Pixel_Quarantine::mutation_allowed($item)) { return new WP_Error('SOURCE_CHANGED'); }
            // Exclusive publication refuses a competing newly created original, never overwrites it.
            if (!@link($candidate, $p)) { return new WP_Error('SWAP_FAILED'); }
            WP_Seed_Pixel_Quarantine::boundary('original_restore_linked', $item);
            if (!unlink($candidate) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir) || !WP_Seed_Pixel_Master_Storage::sync_directory(dirname($p))) { return new WP_Error('SWAP_FAILED'); }
        }
        WP_Seed_Pixel_Quarantine::boundary('original_restored_file', $item);
        $meta = WP_Seed_Pixel_Master_Adapter::reconcile($r, true); if (is_wp_error($meta)) { return $meta; }
        $verify = WP_Seed_Pixel_Master_Storage::verify($item, $r, true); if (is_wp_error($verify)) { return $verify; }
        WP_Seed_Pixel_Quarantine::boundary('original_restored_metadata', $item);
        $r['phase'] = 'rolled_back'; return WP_Seed_Pixel_Master_Storage::save($dir, $r);
    }
}
