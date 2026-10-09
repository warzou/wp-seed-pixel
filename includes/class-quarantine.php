<?php
defined('ABSPATH') || exit;

/** Single-entry private lifecycle; M2 owns leases, fencing and operation rows. */
final class WP_Seed_Pixel_Quarantine {
    const ENGINE = 'm4-local-quarantine-1';
    const AUTHORIZATION = 'm4-permanent-purge-1';

    public static function enabled() {
        return WP_Seed_Pixel_Master_Storage::enabled() && ((defined('WP_SEED_PIXEL_M4_TESTING') && WP_SEED_PIXEL_M4_TESTING === true)
            || (defined('WP_SEED_PIXEL_STORAGE_ENABLED') && WP_SEED_PIXEL_STORAGE_ENABLED === true));
    }

    public static function original_snapshot(array $before) {
        global $wpdb;
        $meta = maybe_unserialize($before['rows']['_wp_attachment_metadata'][0]);
        $name = $meta['original_image'] ?? '';
        if (!$name || basename($name) !== $name || strpos($name, '..') !== false) { return new WP_Error('NEEDS_REVIEW'); }
        $relative = dirname($before['relative']) . '/' . $name;
        $file = $before['files'][$relative] ?? null;
        $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null, false)['basedir'] . '/' . $relative);
        if (!$file || is_wp_error($path) || $relative === $before['relative'] || wp_get_original_image_path($before['attachment_id']) !== $path) { return new WP_Error('NEEDS_REVIEW'); }
        $info = @getimagesize($path); $stat = lstat($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $stat['nlink'] !== 1 || $info[0] < $before['width'] || $info[1] < $before['height']) { return new WP_Error('SHARED_PATH'); }
        if (!function_exists('imagecreatefromjpeg')) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        if ($info[0] * $info[1] > 16000000) { return new WP_Error('NEEDS_REVIEW'); }
        $decoded = @imagecreatefromjpeg($path);
        if (!$decoded || imagesx($decoded) !== $info[0] || imagesy($decoded) !== $info[1]) { return new WP_Error('VERIFY_FAILED'); }
        unset($decoded);
        $references = self::original_references($name, $before['attachment_id']);
        if (is_wp_error($references)) { return $references; }
        return array('relative' => $relative, 'sha256' => $file['sha256'], 'bytes' => $file['bytes'], 'width' => $info[0], 'height' => $info[1],
            'mode' => $stat['mode'] & 0777, 'uid' => $stat['uid'], 'gid' => $stat['gid']);
    }

    public static function original_references($name, $id) {
        global $wpdb;
        $needle = '%' . $wpdb->esc_like($name) . '%';
        // A failed search is not proof of no reference. Unknown external URLs require consent.
        $queries = array(
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s LIMIT 1", $needle, $needle),
            $wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND NOT (post_id=%d AND meta_key='_wp_attachment_metadata') LIMIT 1", $needle, $id),
            $wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $needle),
        );
        foreach ($queries as $sql) {
            $value = $wpdb->get_var($sql);
            if ($wpdb->last_error) { return new WP_Error('STORE_FAILED'); }
            if ($value) { return new WP_Error('NEEDS_REVIEW'); }
        }
        return true;
    }

    private static function identity($path, array $source, $inode = null) {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) { return false; }
        $s = lstat($path);
        return $s['nlink'] === 1 && $s['size'] === $source['bytes'] && $s['uid'] === $source['uid']
            && ($inode === null || ($s['dev'] === $inode['dev'] && $s['ino'] === $inode['ino']))
            && hash_file('sha256', $path) === $source['sha256'];
    }

    public static function record(array $item, $enrollment = false) {
        if (!self::enabled()) { return new WP_Error('PERMISSION_DENIED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item);
        if (is_array($r) && isset($r['graph_version'])) {
            $r = WP_Seed_Pixel_Metadata_Graph_Transaction::journal($item, $dir); if (is_wp_error($r)) { return $r; }
            $anchor = json_decode($item['journal'], true)['quarantine_hash'] ?? '';
            if (isset($r['quarantine']) && $anchor !== hash('sha256', wp_json_encode($r['quarantine']))
                && !($enrollment && $anchor === '' && $item['stage'] === 'retained')) { return new WP_Error('EVIDENCE_INVALID'); }
            return $r;
        }
        if (is_wp_error($r) || !$r || !$r['candidate']) { return new WP_Error('EVIDENCE_INVALID'); }
        $data = json_decode($item['data'], true);
        if ($item['action'] === 'retire' && ($r['retired_original'] ?? null) !== ($data['original'] ?? null)) { return new WP_Error('EVIDENCE_INVALID'); }
        if ($item['action'] === 'replace' && isset($r['retired_original'])) { return new WP_Error('EVIDENCE_INVALID'); }
        if (isset($r['quarantine'])) {
            $journal = json_decode($item['journal'], true);
            $anchor = $journal['quarantine_hash'] ?? '';
            if ($anchor !== hash('sha256', wp_json_encode($r['quarantine'])) && !($enrollment && $anchor === '' && $item['stage'] === 'retained')) { return new WP_Error('EVIDENCE_INVALID'); }
        }
        $w = $r['witness'] ?? array();
        if (($w['job_id'] ?? null) !== (int) $item['job_id'] || ($w['item_id'] ?? null) !== (int) $item['id']
            || ($w['source_sha256'] ?? '') !== $r['before']['sha256'] || ($w['sha256'] ?? '') !== $r['candidate']['sha256']) { return new WP_Error('EVIDENCE_INVALID'); }
        return $r;
    }

    private static function source(array $r) { return $r['retired_original'] ?? $r['before']; }

    public static function summary() {
        global $wpdb;
        $out = array('entries' => 0, 'quarantine_bytes' => 0, 'potential_purge_bytes' => 0, 'removed_bytes' => 0, 'needs_review' => 0);
        if (!self::enabled() || !current_user_can('manage_options') || (int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return $out; }
        $rows = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' AND action IN ('replace','retire') ORDER BY id DESC LIMIT 20", ARRAY_A);
        foreach ($rows as $item) {
            if (!current_user_can('edit_post', $item['attachment_id'])) { continue; }
            $receipt = json_decode($item['receipt'], true);
            if (!empty($receipt['cleanup_unreplaced']) && WP_Seed_Pixel_Job_Store::journal_valid($item)) {
                $overhead = self::pending_overhead($item);
                if (!is_wp_error($overhead) && $overhead['temporary_bytes'] === 0) { continue; }
            }
            $v = self::inspect($item); $out['entries']++;
            if (is_wp_error($v)) { $out['needs_review']++; continue; }
            foreach (array('quarantine_bytes', 'potential_purge_bytes', 'removed_bytes') as $key) { $out[$key] += $v[$key]; }
            if ($v['state'] === 'needs_review') { $out['needs_review']++; }
        }
        return $out;
    }

    public static function mutation_allowed(array $item) {
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $item['attachment_id'])) { return false; }
        $live = WP_Seed_Pixel_Job_Store::item($item['id']);
        return self::enabled() && current_user_can('manage_options') && current_user_can('edit_post', $item['attachment_id'])
            && $live && $live === $item && !empty($live['lease']) && (int) $live['lease_until'] >= time();
    }

    public static function retain(array $item, array $r) {
        if (!self::mutation_allowed($item)) { return new WP_Error('PERMISSION_DENIED'); }
        if (isset($r['graph_version'])) { return WP_Seed_Pixel_Metadata_Graph_Transaction::retain($item, $r); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
        $source = self::source($r); $path = $dir . '/recovery.jpg';
        if (!self::identity($path, $source, $r['quarantine'] ?? null)) { return new WP_Error('BACKUP_FAILED'); }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r);
        if (is_wp_error($o) || !$o['meta_after'] || !$o['witness_after'] || $o['hash'] !== $r['candidate']['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
        $s = lstat($path);
        $r['quarantine'] = array('version' => self::ENGINE, 'dev' => $s['dev'], 'ino' => $s['ino'], 'sha256' => $source['sha256'],
            'bytes' => $source['bytes'],
            'kind' => isset($r['retired_original']) ? 'preserved_original' : 'operational_master', 'created' => $r['quarantine']['created'] ?? time());
        $r['phase'] = 'retained';
        $r = WP_Seed_Pixel_Master_Storage::save($dir, $r);
        if (!is_wp_error($r)) { self::boundary('quarantine_recorded', $item); }
        return $r;
    }

    /** Verified pre-switch escrow is overhead, not quarantine or reclaimed space. */
    public static function pending_overhead(array $item) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        if (is_wp_error($dir)) {
            $expected = WP_SEED_PIXEL_RECOVERY_ROOT . '/m3-' . (int) $item['job_id'] . '-' . (int) $item['id'];
            return $dir->get_error_code() === 'BACKUP_FAILED' && !file_exists($expected) && !is_link($expected)
                ? array('audit_bytes' => 0, 'temporary_bytes' => 0) : $dir;
        }
        $listing = @scandir($dir);
        if ($listing === false) { return new WP_Error('EVIDENCE_INVALID'); }
        $names = array_diff($listing, array('.', '..'));
        if (!$names) { return array('audit_bytes' => 0, 'temporary_bytes' => 0); }
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item);
        if (is_wp_error($r) || !$r || isset($r['quarantine']) || array_diff($names, array('journal.json','recovery.jpg','candidate.jpg'))) { return new WP_Error('EVIDENCE_INVALID'); }
        $data = json_decode($item['data'], true);
        if (($r['retired_original'] ?? null) !== ($data['original'] ?? null)) { return new WP_Error('EVIDENCE_INVALID'); }
        $source = self::source($r); $out = array('audit_bytes' => 0, 'temporary_bytes' => 0);
        foreach ($names as $name) {
            $path = $dir . '/' . $name; $s = @lstat($path);
            if (!$s || !is_file($path) || is_link($path) || $s['nlink'] !== 1 || $s['uid'] !== fileowner($dir)) { return new WP_Error('EVIDENCE_INVALID'); }
            if ($name === 'journal.json') { $out['audit_bytes'] += $s['size']; continue; }
            $identity = $name === 'recovery.jpg' ? $source : ($r['candidate'] ?? null);
            if (!$identity || $s['size'] !== $identity['bytes'] || hash_file('sha256', $path) !== $identity['sha256']) { return new WP_Error('EVIDENCE_INVALID'); }
            $out['temporary_bytes'] += $s['size'];
        }
        return $out;
    }

    /** Read-only, live availability/accounting. Never trusts historical PURGED alone. */
    public static function inspect(array $item) {
        $r = self::record($item); if (is_wp_error($r)) { return $r; }
        if (isset($r['graph_version'])) { return WP_Seed_Pixel_Metadata_Graph_Transaction::inspect($item, $r); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); $path = $dir . '/recovery.jpg';
        $source = self::source($r); $q = $r['quarantine'] ?? null;
        $owned = $q && $q['version'] === self::ENGINE && $q['sha256'] === $source['sha256'] && $q['bytes'] === $source['bytes'] && self::identity($path, $source, $q);
        $o = WP_Seed_Pixel_Master_Adapter::observe($r);
        $healthy = !is_wp_error($o) && $o['meta_after'] && $o['witness_after'] && $o['hash'] === $r['candidate']['sha256'];
        clearstatcache(); $exists = file_exists($path) || is_link($path);
        $purged = $item['stage'] === 'purged' && $r['phase'] === 'purged' && !$exists && ($r['purge']['authorization'] ?? '') === self::AUTHORIZATION;
        $restored = $item['stage'] === 'rolled_back' && $r['phase'] === 'restored' && !$exists && !is_wp_error($o)
            && $o['meta_before'] && $o['witness_before'] && $o['hash'] === $r['before']['sha256'];
        $active = WP_Seed_Pixel_Master_Adapter::path($r['before']);
        $active_bytes = is_wp_error($active) ? null : filesize($active);
        $overhead = filesize($dir . '/journal.json');
        $extra = 0;
        foreach (array('journal.next', 'candidate.jpg', 'restore.jpg') as $name) {
            if (is_file($dir . '/' . $name)) { $extra += filesize($dir . '/' . $name); }
        }
        $original_active = 0;
        if (isset($r['retired_original'])) {
            $original_path = wp_upload_dir(null, false)['basedir'] . '/' . $source['relative'];
            if (is_file($original_path)) { $original_active = filesize($original_path); }
            $delta = $source['bytes'] - $original_active;
        } else { $delta = $active_bytes === null ? null : $source['bytes'] - $active_bytes; }
        $retained_bytes = $exists && is_file($path) && !is_link($path) ? filesize($path) : 0;
        $available = $owned && $healthy && $r['phase'] === 'retained';
        return array('kind' => $q['kind'] ?? (isset($r['retired_original']) ? 'preserved_original' : 'operational_master'),
            'state' => $purged ? 'purged' : ($restored ? 'restored' : ($available ? 'retained' : 'needs_review')),
            'rollback_available' => (bool) $available, 'purge_available' => (bool) $available,
            'source_bytes' => $source['bytes'], 'quarantine_bytes' => $retained_bytes,
            'potential_purge_bytes' => $available ? $source['bytes'] : 0, 'removed_bytes' => $purged ? $source['bytes'] : 0,
            'active_delta' => $healthy || $restored ? $delta : null, 'audit_bytes' => $overhead, 'temporary_bytes' => $extra,
            'net_reclaimed_bytes' => (($purged && $healthy) || $available || $restored) && $delta !== null ? $delta - $retained_bytes - $overhead - $extra : null,
            'allocated_bytes' => $purged ? ($r['purge']['allocated_bytes'] ?? null) : null, 'quota_bytes' => null, 'generation' => hash('sha256', wp_json_encode($q) . $r['policy_hash']),
            'created' => $q['created'] ?? null);
    }

    public static function purge(array $item, array $approval) {
        if (!self::mutation_allowed($item)) { return new WP_Error('PERMISSION_DENIED'); }
        $r = self::record($item); if (is_wp_error($r)) { return $r; }
        if (isset($r['graph_version'])) { return new WP_Error('PERMISSION_DENIED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); $p = $dir . '/recovery.jpg';
        $view = self::inspect($item);
        if (($approval['version'] ?? '') !== self::AUTHORIZATION || ($approval['generation'] ?? '') !== ($view['generation'] ?? '') || ($approval['irreversible'] ?? false) !== true) { return new WP_Error('PERMISSION_DENIED'); }
        if ($r['phase'] === 'purged') {
            return !file_exists($p) && !is_link($p) && ($r['purge']['authorization'] ?? '') === self::AUTHORIZATION ? $r : new WP_Error('EVIDENCE_INVALID');
        }
        if ($r['phase'] !== 'purge_intent') {
            if (!$view['purge_available']) { return new WP_Error('EVIDENCE_INVALID'); }
            $stat = lstat($p);
            $r['phase'] = 'purge_intent'; $r['purge'] = array('authorization' => self::AUTHORIZATION, 'generation' => $view['generation'], 'actor' => get_current_user_id(), 'at' => time(),
                'allocated_bytes' => isset($stat['blocks']) ? $stat['blocks'] * 512 : null);
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        }
        self::boundary('purge_intent', $item);
        $o = WP_Seed_Pixel_Master_Adapter::observe($r);
        if (is_wp_error($o) || !$o['meta_after'] || !$o['witness_after'] || $o['hash'] !== $r['candidate']['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
        if (!self::mutation_allowed($item)) { return new WP_Error('LOCKED'); }
        if (isset($r['retired_original'])) {
            $refs = self::original_references(basename($r['retired_original']['relative']), $item['attachment_id']);
            if (is_wp_error($refs)) { return $refs; }
        }
        clearstatcache(true, $p);
        if (file_exists($p) || is_link($p)) {
            if (!self::identity($p, self::source($r), $r['quarantine']) || !is_writable($dir)) { return new WP_Error('PURGE_FAILED'); }
            $stat = lstat($p);
            if (isset($r['purge']['allocated_bytes']) && (!isset($stat['blocks']) || $stat['blocks'] * 512 !== $r['purge']['allocated_bytes'])) { return new WP_Error('PURGE_FAILED'); }
            // Sole permanent quarantine deletion. Path is fixed, inode/hash anchored, intent durable.
            if (!self::mutation_allowed($item) || !unlink($p)) { return new WP_Error('PURGE_FAILED'); }
        }
        if (!WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('PURGE_FAILED'); }
        self::boundary('purge_deleted', $item);
        clearstatcache(true, $p);
        if (file_exists($p) || is_link($p)) { return new WP_Error('PURGE_FAILED'); }
        $r['phase'] = 'purged'; $r['purge']['completed'] = time();
        $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        self::boundary('purge_recorded', $item);
        return $r;
    }

    public static function restore(array $item) {
        if (!self::mutation_allowed($item)) { return new WP_Error('PERMISSION_DENIED'); }
        $r = self::record($item); if (is_wp_error($r)) { return $r; }
        if (isset($r['graph_version'])) {
            $r = WP_Seed_Pixel_Metadata_Graph_Transaction::restore($item); if (is_wp_error($r)) { return $r; }
            return WP_Seed_Pixel_Metadata_Graph_Transaction::cleanup($item, $r, true);
        }
        if (in_array($r['phase'], array('purged', 'purge_intent'), true)) { return new WP_Error('RESTORE_UNAVAILABLE'); }
        if ($r['phase'] === 'restored') { return $r; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        if (empty($r['quarantine']) || !self::identity($dir . '/recovery.jpg', self::source($r), $r['quarantine'])) {
            if (!in_array($r['phase'], array('rolled_back', 'restore_cleanup_intent'), true) || file_exists($dir . '/recovery.jpg') || is_link($dir . '/recovery.jpg')) { return new WP_Error('EVIDENCE_INVALID'); }
        }
        if (!in_array($r['phase'], array('rolled_back', 'restore_cleanup_intent'), true)) {
            if (isset($r['retired_original'])) { $r = WP_Seed_Pixel_Original_Executor::restore($item, $r); }
            else { $r = WP_Seed_Pixel_Master_Storage::restore($item); }
            if (is_wp_error($r)) { return $r; }
        }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r);
        if (is_wp_error($o) || !$o['meta_before'] || !$o['witness_before'] || $o['hash'] !== $r['before']['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
        $r['phase'] = 'restore_cleanup_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        $p = $dir . '/recovery.jpg';
        if (!self::mutation_allowed($item)) { return new WP_Error('LOCKED'); }
        if (file_exists($p) || is_link($p)) {
            if (!self::identity($p, self::source($r), $r['quarantine'])) { return new WP_Error('EVIDENCE_INVALID'); }
            // Restore has already verified the exact source at its canonical native path.
            if (!self::mutation_allowed($item) || !unlink($p)) { return new WP_Error('PURGE_FAILED'); }
        }
        if (!WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('PURGE_FAILED'); }
        self::boundary('restore_cleanup', $item);
        $r['phase'] = 'restored'; return WP_Seed_Pixel_Master_Storage::save($dir, $r);
    }

    public static function boundary($name, array $item) { do_action('wp_seed_pixel_m4_boundary', $name, (int) $item['id']); }
}
