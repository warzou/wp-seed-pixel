<?php
defined('ABSPATH') || exit;

/** Graph effects use the existing M5.1 authority and Master_Storage journal. */
final class WP_Seed_Pixel_Metadata_Graph_Transaction {
    const VERSION = 'metadata-graph-transaction-1';
    const WRITE_CERTIFIED = true;

    public static function enabled() {
        return self::WRITE_CERTIFIED || (defined('WP_SEED_PIXEL_METADATA_GRAPH_TESTING') && WP_SEED_PIXEL_METADATA_GRAPH_TESTING === true
            && wp_get_environment_type() === 'local' && in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1', '::1'), true));
    }
    public static function is_item(array $item) { return isset(json_decode($item['data'], true)['metadata_graph']); }
    public static function capacity(array $plan) {
        return $plan['public_bytes'] * 4 + 16777216;
    }

    private static function allowed(array $item) {
        return self::enabled() && WP_Seed_Pixel_Master_Storage::enabled()
            && WP_Seed_Pixel_Authority::valid(0) && WP_Seed_Pixel_Authority::valid((int) $item['attachment_id']);
    }
    private static function boundary($name, array $item) { do_action('wp_seed_pixel_metadata_graph_boundary', $name, (int) $item['id']); }
    private static function path($relative) { return WP_Seed_Pixel_Files::path(wp_upload_dir(null, false)['basedir'] . '/' . $relative); }
    private static function identity($path, $hash, $bytes) {
        clearstatcache(true, $path); $s = @lstat($path);
        return $s && is_file($path) && !is_link($path) && $s['nlink'] === 1 && $s['size'] === $bytes && hash_file('sha256', $path) === $hash;
    }
    private static function copy($source, $target, $hash, $bytes) {
        if (!WP_Seed_Pixel_Authority::valid_all() || !self::identity($source, $hash, $bytes)) { return new WP_Error('EVIDENCE_INVALID'); }
        if (file_exists($target) || is_link($target)) { return self::identity($target, $hash, $bytes) ? true : new WP_Error('EVIDENCE_INVALID'); }
        $in = @fopen($source, 'rb'); $out = @fopen($target, 'xb');
        if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return new WP_Error('BACKUP_FAILED'); }
        $n = stream_copy_to_stream($in, $out); $ok = $n === $bytes && fflush($out) && (!function_exists('fsync') || fsync($out));
        fclose($in); fclose($out);
        return $ok && WP_Seed_Pixel_Authority::valid_all() && self::identity($target, $hash, $bytes) ? true : new WP_Error('BACKUP_FAILED');
    }
    private static function persist($dir, array $r, $phase) {
        $r['phase'] = $phase;
        return WP_Seed_Pixel_Master_Storage::save($dir, $r);
    }
    private static function frozen(array $item) {
        $data = json_decode($item['data'], true); $p = $data['metadata_graph'] ?? null;
        if (!is_array($p) || empty($p['admissible']) || empty($p['modified_files']) || !isset($p['manifest'], $p['signature'])
            || !is_string($p['signature']) || !is_array($p['manifest'])
            || !hash_equals($p['signature'], hash('sha256', wp_json_encode($p['manifest'])))
            || $p['manifest']['attachment_id'] !== (int) $item['attachment_id']) { return new WP_Error('EVIDENCE_INVALID'); }
        $before = $data['before'] ?? null; $modified = 0; $bytes = 0;
        if (!is_array($before) || !isset($before['files'], $before['relative'])
            || ($p['manifest']['version'] ?? '') !== WP_Seed_Pixel_Metadata_Public_Graph::VERSION
            || ($p['manifest']['master'] ?? '') !== $before['relative']
            || !is_array($p['manifest']['files'] ?? null)
            || count($p['manifest']['files']) !== count($before['files'])) { return new WP_Error('EVIDENCE_INVALID'); }
        foreach ($p['manifest']['files'] as $relative => $entry) {
            $source = $before['files'][$relative] ?? null;
            if (!is_array($entry) || !is_array($source) || ($entry['relative'] ?? null) !== $relative
                || ($entry['roles'] ?? null) !== $source['roles']
                || ($entry['before_sha256'] ?? null) !== $source['sha256']
                || ($entry['before_bytes'] ?? null) !== $source['bytes']
                || !in_array($entry['classification'] ?? '', array('A', 'B'), true)) { return new WP_Error('EVIDENCE_INVALID'); }
            $bytes += $source['bytes'];
            if ($entry['classification'] === 'B') { $modified++; }
        }
        if ($modified !== $p['modified_files'] || !$modified || $bytes !== $p['public_bytes']) { return new WP_Error('EVIDENCE_INVALID'); }
        return $p;
    }
    public static function journal(array $item, $dir) {
        $r = WP_Seed_Pixel_Master_Storage::load($dir, $item); if (is_wp_error($r) || !$r) { return $r; }
        $p = self::frozen($item); if (is_wp_error($p)) { return $p; }
        if (($r['graph_version'] ?? '') !== self::VERSION || ($r['graph_plan'] ?? null) !== $p['manifest']
            || ($r['graph_signature'] ?? '') !== $p['signature']) { return new WP_Error('EVIDENCE_INVALID'); }
        $index = 0;
        foreach ($p['manifest']['files'] as $relative => $entry) {
            if ($entry['classification'] !== 'B') { continue; }
            $file = $r['graph_files'][$relative] ?? null;
            if (!is_array($file) || ($file['entry'] ?? null) !== $entry
                || ($file['original'] ?? '') !== sprintf('graph-original-%04d.bin', $index)
                || ($file['candidate'] ?? '') !== sprintf('graph-candidate-%04d.bin', $index)
                || ($file['restore'] ?? '') !== sprintf('graph-restore-%04d.bin', $index)
                || !is_int($file['mode'] ?? null) || $file['mode'] < 0 || $file['mode'] > 0777
                || !is_int($file['uid'] ?? null) || !is_int($file['gid'] ?? null)) { return new WP_Error('EVIDENCE_INVALID'); }
            $index++;
        }
        if (count($r['graph_files'] ?? array()) !== $index) { return new WP_Error('EVIDENCE_INVALID'); }
        return $r;
    }
    public static function freshness(array $item) {
        $p = self::frozen($item); if (is_wp_error($p)) { return $p; }
        $data = json_decode($item['data'], true);
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot((int) $item['attachment_id'], false, true);
        if (is_wp_error($fresh) || $fresh !== $data['before']) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
        $again = WP_Seed_Pixel_Metadata_Public_Graph::plan($fresh);
        return !is_wp_error($again) && $again['admissible'] && hash_equals($p['signature'], $again['signature'])
            ? true : new WP_Error('METADATA_GRAPH_CHANGED');
    }
    public static function prepare(array $item, array $policy) {
        if (!self::allowed($item) || ($policy['intent']['metadata'] ?? '') !== 'anonymize') { return new WP_Error('METADATA_CERTIFICATION_REQUIRED'); }
        $p = self::frozen($item); if (is_wp_error($p)) { return $p; }
        $fresh = self::freshness($item); if (is_wp_error($fresh)) { return $fresh; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = self::journal($item, $dir); if (is_wp_error($r)) { return $r; }
        if ($r && $r['policy_hash'] !== WP_Seed_Pixel_Policy::hash($policy)) { return new WP_Error('EVIDENCE_INVALID'); }
        if (!$r) {
            $data = json_decode($item['data'], true);
            $r = array('job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'], 'before' => $data['before'],
                'policy_hash' => WP_Seed_Pixel_Policy::hash($policy), 'graph_version' => self::VERSION,
                'graph_plan' => $p['manifest'], 'graph_signature' => $p['signature'], 'candidate' => null, 'after' => null,
                'graph_files' => array(), 'graph_effects' => array(), 'recovery_bytes' => 0, 'candidate_bytes' => 0);
            $master = $p['manifest']['files'][$data['before']['relative']];
            $r['candidate'] = array('sha256' => $master['after_sha256'], 'bytes' => $master['after_bytes'], 'width' => $master['width'],
                'height' => $master['height'], 'encoded' => 0, 'processor' => WP_Seed_Pixel_Metadata::VERSION);
            if (isset($data['before']['rows']['_wp_attachment_metadata'])) {
                $r['after'] = maybe_unserialize($data['before']['rows']['_wp_attachment_metadata'][0]);
                $r['after']['filesize'] = $master['after_bytes'];
                foreach ($r['after']['sizes'] ?? array() as $name => $size) {
                    $relative = dirname($data['before']['relative']) . '/' . $size['file'];
                    if (isset($p['manifest']['files'][$relative])) { $r['after']['sizes'][$name]['filesize'] = $p['manifest']['files'][$relative]['after_bytes']; }
                }
                $categories = array(); foreach ($p['manifest']['files'] as $e) { $categories = array_merge($categories, $e['categories']); }
                $r['witness'] = array('job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'], 'engine' => self::VERSION,
                    'source_sha256' => $data['before']['sha256'], 'sha256' => $master['after_sha256'], 'policy_hash' => $r['policy_hash'],
                    'metadata' => 'anonymized', 'metadata_anonymized' => true, 'metadata_categories' => array_values(array_unique($categories)),
                    'metadata_removed_bytes' => $p['metadata_bytes_removed'], 'graph_signature' => $p['signature'],
                    'public_file_count' => count($p['manifest']['files']), 'recovery_file_count' => $p['modified_files']);
            }
            $index = 0;
            foreach ($p['manifest']['files'] as $relative => $entry) {
                if ($entry['classification'] !== 'B') { continue; }
                $path = self::path($relative); if (is_wp_error($path)) { return $path; }
                $s = lstat($path);
                if ($s['dev'] !== stat($dir)['dev']) { return new WP_Error('UNSUPPORTED_STORAGE'); }
                $r['graph_files'][$relative] = array('entry' => $entry, 'mode' => $s['mode'] & 0777, 'uid' => $s['uid'], 'gid' => $s['gid'],
                    'original' => sprintf('graph-original-%04d.bin', $index), 'candidate' => sprintf('graph-candidate-%04d.bin', $index),
                    'restore' => sprintf('graph-restore-%04d.bin', $index));
                $r['recovery_bytes'] += $entry['before_bytes']; $r['candidate_bytes'] += $entry['after_bytes']; $index++;
            }
            // Count the native graph, exact originals, candidates, largest restore and journal headroom.
            $r['peak_budget'] = $p['public_bytes'] + $r['recovery_bytes'] + $r['candidate_bytes']
                + max(array_column(array_column($r['graph_files'], 'entry'), 'before_bytes')) + 4 * strlen(wp_json_encode($r));
            $budget = WP_Seed_Pixel_Storage_Budget::admit($r['peak_budget'], $dir); if (is_wp_error($budget)) { return $budget; }
            if (($policy['capacity_bytes'] ?? 0) < $r['peak_budget']) { return new WP_Error('QUOTA_UNKNOWN'); }
            $r = self::persist($dir, $r, 'graph_preparing'); if (is_wp_error($r)) { return $r; }
            self::boundary('after_plan', $item);
        }
        if (!in_array($r['phase'], array('graph_preparing', 'graph_prepared'), true)) { return new WP_Error('RECOVERY_REQUIRED'); }
        foreach ($r['graph_files'] as $relative => $file) {
            $e = $file['entry']; $path = self::path($relative); if (is_wp_error($path)) { return $path; }
            $copy = self::copy($path, $dir . '/' . $file['original'], $e['before_sha256'], $e['before_bytes']); if (is_wp_error($copy)) { return $copy; }
            $candidate = $dir . '/' . $file['candidate'];
            if (!file_exists($candidate) && !is_link($candidate)) {
                $created = WP_Seed_Pixel_Metadata::create($dir . '/' . $file['original'], $candidate); if (is_wp_error($created)) { return $created; }
            }
            if (!self::identity($candidate, $e['after_sha256'], $e['after_bytes'])) { return new WP_Error('EVIDENCE_INVALID'); }
            $verified = WP_Seed_Pixel_Metadata::read($candidate);
            if (is_wp_error($verified) || $verified['categories'] || $verified['image_sha256'] !== $e['image_sha256']
                || $verified['color_sha256'] !== $e['color_sha256'] || $verified['width'] !== $e['width'] || $verified['height'] !== $e['height']) { return new WP_Error('VERIFY_FAILED'); }
        }
        self::boundary('after_candidates', $item);
        foreach ($r['graph_files'] as $file) {
            $e = $file['entry'];
            if (!self::identity($dir . '/' . $file['original'], $e['before_sha256'], $e['before_bytes'])) { return new WP_Error('BACKUP_FAILED'); }
        }
        self::boundary('after_recovery', $item);
        $fresh = self::freshness($item); if (is_wp_error($fresh)) { return $fresh; }
        $r = self::persist($dir, $r, 'graph_prepared'); if (!is_wp_error($r)) { self::boundary('after_prepared', $item); }
        return $r;
    }
    private static function replace($candidate, $path, $sha, $bytes, array $file) {
        if (!WP_Seed_Pixel_Authority::valid_all() || !self::identity($candidate, $sha, $bytes)
            || is_link($path) || !is_file($path) || stat($candidate)['dev'] !== stat($path)['dev']
            || fileowner($candidate) !== $file['uid'] || fileowner($path) !== $file['uid'] || filegroup($path) !== $file['gid']
            || !chmod($candidate, $file['mode']) || !chgrp($candidate, $file['gid'])) { return new WP_Error('SWAP_FAILED'); }
        $f = fopen($candidate, 'rb'); $ok = !function_exists('fsync') || fsync($f); fclose($f);
        if (!$ok || !WP_Seed_Pixel_Authority::valid_all() || !rename($candidate, $path)
            || !WP_Seed_Pixel_Master_Storage::sync_directory(dirname($path))) { return new WP_Error('SWAP_FAILED'); }
        return self::identity($path, $sha, $bytes) ? true : new WP_Error('VERIFY_FAILED');
    }
    public static function verify(array $item, array $r, $original = false) {
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot((int) $item['attachment_id'], true, true);
        if (is_wp_error($fresh) || array_keys($fresh['files']) !== array_keys($r['before']['files'])
            || $fresh['url'] !== $r['before']['url'] || $fresh['guid'] !== $r['before']['guid']) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
        if (isset($r['witness'])) {
            $observed = WP_Seed_Pixel_Master_Adapter::observe($r);
            if (is_wp_error($observed)) { return $observed; }
            if (($r['phase'] ?? '') === 'graph_committed' && (!$observed['meta_after'] || !$observed['witness_after'] || !$observed['references_after'])) { return new WP_Error('METADATA_CONFLICT'); }
            if (($r['phase'] ?? '') === 'graph_restored' && (!$observed['meta_before'] || !$observed['witness_before'] || !$observed['references_before'])) { return new WP_Error('METADATA_CONFLICT'); }
        } elseif ($fresh['rows'] !== $r['before']['rows']) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
        foreach ($r['graph_plan']['files'] as $relative => $e) {
            $path = self::path($relative); if (is_wp_error($path)) { return $path; }
            $before = $original || $e['classification'] === 'A';
            if (($fresh['files'][$relative]['roles'] ?? null) !== $e['roles']
                || !self::identity($path, $before ? $e['before_sha256'] : $e['after_sha256'], $before ? $e['before_bytes'] : $e['after_bytes'])) { return new WP_Error('VERIFY_FAILED'); }
            $v = WP_Seed_Pixel_Metadata::read($path);
            if (is_wp_error($v) || (!$original && $v['categories']) || $v['width'] !== $e['width'] || $v['height'] !== $e['height']
                || $v['image_sha256'] !== $e['image_sha256'] || $v['color_sha256'] !== $e['color_sha256']) { return new WP_Error('METADATA_PUBLIC_COPY'); }
        }
        return true;
    }
    public static function switch_graph(array $item, array $policy) {
        if (!self::allowed($item)) { return new WP_Error('METADATA_CERTIFICATION_REQUIRED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); if (is_wp_error($dir)) { return $dir; }
        $r = self::journal($item, $dir); if (is_wp_error($r) || !$r) { return new WP_Error('EVIDENCE_INVALID'); }
        if ($r['phase'] === 'graph_committed') { $v = self::verify($item, $r); return is_wp_error($v) ? $v : $r; }
        if ($r['phase'] !== 'graph_prepared') { return new WP_Error('RECOVERY_REQUIRED'); }
        if ($r['policy_hash'] !== WP_Seed_Pixel_Policy::hash($policy)) { return new WP_Error('EVIDENCE_INVALID'); }
        $fresh = self::freshness($item); if (is_wp_error($fresh)) { return $fresh; }
        foreach ($r['graph_files'] as $f) {
            $e = $f['entry'];
            if (!self::identity($dir . '/' . $f['original'], $e['before_sha256'], $e['before_bytes'])
                || !self::identity($dir . '/' . $f['candidate'], $e['after_sha256'], $e['after_bytes'])) { return new WP_Error('BACKUP_FAILED'); }
        }
        $r = self::persist($dir, $r, 'graph_switching'); if (is_wp_error($r)) { return $r; }
        foreach ($r['graph_files'] as $relative => $file) {
            $e = $file['entry']; $path = self::path($relative); if (is_wp_error($path)) { return self::rollback_error($item, $path); }
            if (!self::identity($path, $e['before_sha256'], $e['before_bytes'])) { return self::rollback_error($item, new WP_Error('METADATA_GRAPH_CHANGED')); }
            $r['graph_effects'][$relative] = 'intent'; $r = self::persist($dir, $r, 'graph_switching'); if (is_wp_error($r)) { return $r; }
            $move = self::replace($dir . '/' . $file['candidate'], $path, $e['after_sha256'], $e['after_bytes'], $file);
            if (is_wp_error($move)) { return self::rollback_error($item, $move); }
            self::boundary('after_rename_' . count($r['graph_effects']), $item);
            $r['graph_effects'][$relative] = 'switched'; $r = self::persist($dir, $r, 'graph_switching'); if (is_wp_error($r)) { return $r; }
            self::boundary('after_file_' . count($r['graph_effects']), $item);
        }
        $r = self::persist($dir, $r, 'graph_verifying'); if (is_wp_error($r)) { return $r; }
        self::boundary('before_verify', $item);
        $v = self::verify($item, $r); if (is_wp_error($v)) { return self::rollback_error($item, $v); }
        self::boundary('before_commit', $item);
        if (isset($r['witness'])) {
            $db = WP_Seed_Pixel_Master_Adapter::reconcile($r); if (is_wp_error($db)) { return self::rollback_error($item, $db); }
            if (!$db['meta_after'] || !$db['witness_after'] || !$db['references_after']) { return self::rollback_error($item, new WP_Error('METADATA_CONFLICT')); }
            $v = self::verify($item, $r); if (is_wp_error($v)) { return self::rollback_error($item, $v); }
            self::boundary('after_metadata', $item);
        }
        // This durable phase is the only graph-file commit point; no earlier UI success.
        $r = self::persist($dir, $r, 'graph_committed'); if (!is_wp_error($r)) { self::boundary('after_commit', $item); }
        return $r;
    }
    private static function rollback_error(array $item, $error) {
        $r = self::restore($item);
        return is_wp_error($r) ? $r : $error;
    }
    public static function restore(array $item) {
        if (!self::allowed($item)) { return new WP_Error('METADATA_CERTIFICATION_REQUIRED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); if (is_wp_error($dir)) { return $dir; }
        $r = self::journal($item, $dir); if (is_wp_error($r) || !$r) { return new WP_Error('EVIDENCE_INVALID'); }
        if ($r['phase'] === 'graph_restored') { $v = self::verify($item, $r, true); return is_wp_error($v) ? $v : $r; }
        $r = self::persist($dir, $r, 'graph_rolling_back'); if (is_wp_error($r)) { return $r; }
        foreach ($r['graph_files'] as $relative => $file) {
            $e = $file['entry']; $path = self::path($relative);
            if (is_wp_error($path)) { return self::review($dir, $r, $path); }
            if (self::identity($path, $e['before_sha256'], $e['before_bytes'])) { continue; }
            if (!self::identity($path, $e['after_sha256'], $e['after_bytes'])) { return self::review($dir, $r, new WP_Error('METADATA_GRAPH_CHANGED')); }
            $copy = self::copy($dir . '/' . $file['original'], $dir . '/' . $file['restore'], $e['before_sha256'], $e['before_bytes']);
            if (is_wp_error($copy)) { return self::review($dir, $r, $copy); }
            if (!self::identity($path, $e['after_sha256'], $e['after_bytes'])) { return self::review($dir, $r, new WP_Error('METADATA_GRAPH_CHANGED')); }
            $move = self::replace($dir . '/' . $file['restore'], $path, $e['before_sha256'], $e['before_bytes'], $file);
            if (is_wp_error($move)) { return self::review($dir, $r, $move); }
            $r['graph_effects'][$relative] = 'restored'; $r = self::persist($dir, $r, 'graph_rolling_back'); if (is_wp_error($r)) { return $r; }
            self::boundary('after_restore_' . count(array_filter($r['graph_effects'], static function($v) { return $v === 'restored'; })), $item);
        }
        $v = self::verify($item, $r, true); if (is_wp_error($v)) { return self::review($dir, $r, $v); }
        if (isset($r['witness'])) {
            $db = WP_Seed_Pixel_Master_Adapter::reconcile($r, true); if (is_wp_error($db)) { return self::review($dir, $r, $db); }
            if (!$db['meta_before'] || !$db['witness_before'] || !$db['references_before']) { return self::review($dir, $r, new WP_Error('METADATA_CONFLICT')); }
        }
        return self::persist($dir, $r, 'graph_restored');
    }
    private static function review($dir, array $r, $error) {
        $r['graph_error'] = $error->get_error_code(); $saved = self::persist($dir, $r, 'graph_needs_review');
        return is_wp_error($saved) ? $saved : new WP_Error('RECOVERY_REQUIRED');
    }
    public static function reconcile(array $item) {
        if (!self::allowed($item)) { return new WP_Error('METADATA_CERTIFICATION_REQUIRED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); if (is_wp_error($dir)) { return $dir; }
        $r = self::journal($item, $dir); if (is_wp_error($r) || !$r) { return new WP_Error('EVIDENCE_INVALID'); }
        if ($r['phase'] === 'graph_committed') { $v = self::verify($item, $r); return is_wp_error($v) ? $v : $r; }
        if ($r['phase'] === 'graph_restored') { $v = self::verify($item, $r, true); return is_wp_error($v) ? $v : $r; }
        if (in_array($r['phase'], array('graph_switching', 'graph_verifying', 'graph_rolling_back'), true)) { return self::restore($item); }
        if (in_array($r['phase'], array('graph_preparing', 'graph_prepared'), true)) { return self::restore($item); }
        return new WP_Error('RECOVERY_REQUIRED');
    }

    /** Existing Quarantine delegates bundle operations here, under its same lease. */
    public static function cleanup_unreplaced(array $item) {
        if (!self::allowed($item)) { return new WP_Error('LOCKED'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        if (is_wp_error($dir)) { return $dir; }
        $r = self::journal($item, $dir);
        if (is_wp_error($r) || !$r) { return new WP_Error('EVIDENCE_INVALID'); }
        if (!in_array($r['phase'], array('graph_preparing', 'graph_prepared', 'graph_restored'), true)
            || array_filter($r['graph_effects'], static function($effect) { return $effect !== 'restored'; })) { return new WP_Error('RECOVERY_REQUIRED'); }
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot((int) $item['attachment_id'], false, true);
        if (is_wp_error($fresh) || $fresh !== $r['before']) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
        $r = self::restore($item); if (is_wp_error($r)) { return $r; }
        $r = self::cleanup($item, $r, true); if (is_wp_error($r)) { return $r; }
        clearstatcache(true, $dir . '/journal.json');
        return array('cleanup_unreplaced' => true, 'active_delta' => 0, 'recovery_bytes' => 0,
            'audit_bytes' => filesize($dir . '/journal.json'), 'temporary_bytes' => 0);
    }

    public static function retain(array $item, array $r) {
        if (!self::allowed($item) || $r['phase'] !== 'graph_committed') { return new WP_Error('RECOVERY_REQUIRED'); }
        $v = self::verify($item, $r); if (is_wp_error($v)) { return $v; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        $files = array(); $allocated = 0;
        foreach ($r['graph_files'] as $relative => $file) {
            $e = $file['entry']; $path = $dir . '/' . $file['original'];
            if (!self::identity($path, $e['before_sha256'], $e['before_bytes'])) { return new WP_Error('BACKUP_FAILED'); }
            $s = lstat($path);
            $files[$relative] = array('dev' => $s['dev'], 'ino' => $s['ino'], 'sha256' => $e['before_sha256'], 'bytes' => $e['before_bytes']);
            if (isset($s['blocks']) && $allocated !== null) { $allocated += $s['blocks'] * 512; } else { $allocated = null; }
        }
        if (isset($r['quarantine']) && ($r['quarantine']['files'] ?? null) !== $files) { return new WP_Error('EVIDENCE_INVALID'); }
        $r['quarantine'] = array('version' => self::VERSION, 'kind' => 'metadata_graph', 'files' => $files,
            'bytes' => $r['recovery_bytes'], 'allocated_bytes' => $allocated, 'created' => $r['quarantine']['created'] ?? time());
        $r = self::cleanup($item, $r, false); if (is_wp_error($r)) { return $r; }
        return WP_Seed_Pixel_Master_Storage::save($dir, $r);
    }
    public static function cleanup(array $item, array $r, $restored) {
        if (!self::allowed($item)) { return new WP_Error('LOCKED'); }
        $v = self::verify($item, $r, $restored); if (is_wp_error($v)) { return $v; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        foreach ($r['graph_files'] as $f) {
            foreach (array('candidate', 'restore', 'original') as $kind) {
                if ($kind === 'original' && !$restored) { continue; }
                $path = $dir . '/' . $f[$kind];
                if (!file_exists($path) && !is_link($path)) { continue; }
                $e = $f['entry']; $before = $kind !== 'candidate';
                if (!self::identity($path, $before ? $e['before_sha256'] : $e['after_sha256'], $before ? $e['before_bytes'] : $e['after_bytes'])
                    || !WP_Seed_Pixel_Authority::valid_all() || !unlink($path)) { return new WP_Error('EVIDENCE_INVALID'); }
            }
        }
        if (!WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('BACKUP_FAILED'); }
        $r['graph_cleanup'] = $restored ? 'restored' : 'committed';
        return WP_Seed_Pixel_Master_Storage::save($dir, $r);
    }
    public static function inspect(array $item, array $r) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); if (is_wp_error($dir)) { return $dir; }
        $original = $r['phase'] === 'graph_restored';
        $v = self::verify($item, $r, $original); $healthy = !is_wp_error($v);
        $q = $r['quarantine'] ?? null; $owned = $q && ($q['version'] ?? '') === self::VERSION;
        $bytes = 0; $temporary = 0; $allocated = 0;
        foreach ($r['graph_files'] as $relative => $file) {
            $path = $dir . '/' . $file['original']; $e = $file['entry'];
            if (file_exists($path) || is_link($path)) {
                if (!self::identity($path, $e['before_sha256'], $e['before_bytes'])) { return new WP_Error('EVIDENCE_INVALID'); }
                $s = lstat($path); $anchor = $q['files'][$relative] ?? null;
                $owned = $owned && $anchor && $anchor['dev'] === $s['dev'] && $anchor['ino'] === $s['ino']
                    && $anchor['sha256'] === $e['before_sha256'] && $anchor['bytes'] === $s['size'];
                $bytes += $s['size'];
                if (isset($s['blocks']) && $allocated !== null) { $allocated += $s['blocks'] * 512; } else { $allocated = null; }
            } else { $owned = false; }
            foreach (array('candidate', 'restore') as $kind) {
                if (is_file($dir . '/' . $file[$kind])) { $temporary += filesize($dir . '/' . $file[$kind]); }
            }
        }
        $available = $healthy && $owned && $r['phase'] === 'graph_committed';
        $audit = filesize($dir . '/journal.json');
        $delta = $original ? 0 : array_sum(array_column($r['graph_plan']['files'], 'removed_bytes'));
        return array('kind' => 'metadata_graph', 'state' => $original && $healthy ? 'restored' : ($available ? 'retained' : 'needs_review'),
            'rollback_available' => (bool) $available, 'purge_available' => false, 'source_bytes' => $r['recovery_bytes'],
            'quarantine_bytes' => $bytes, 'potential_purge_bytes' => 0, 'removed_bytes' => 0, 'active_delta' => $healthy ? $delta : null,
            'audit_bytes' => $audit, 'temporary_bytes' => $temporary, 'net_reclaimed_bytes' => $healthy ? $delta - $bytes - $audit - $temporary : null,
            'allocated_bytes' => $allocated, 'quota_bytes' => null, 'generation' => hash('sha256', wp_json_encode($q) . $r['policy_hash']), 'created' => $q['created'] ?? null);
    }
}
