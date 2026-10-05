<?php
defined('ABSPATH') || exit;

/** The only canonical-file mutation point: verified, same-device rename. No purge. */
final class WP_Seed_Pixel_Master_Storage {
    const ENGINE = 'm3-jpeg-master-1';

    public static function enabled() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $local = defined('WP_SEED_PIXEL_M3_TESTING') && WP_SEED_PIXEL_M3_TESTING === true
            && wp_get_environment_type() === 'local' && in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        $explicit = defined('WP_SEED_PIXEL_STORAGE_ENABLED') && WP_SEED_PIXEL_STORAGE_ENABLED === true;
        return ($local || $explicit) && defined('WP_SEED_PIXEL_RECOVERY_ROOT') && PHP_OS_FAMILY === 'Linux';
    }

    public static function directory(array $item, $create = true) {
        if (!self::enabled()) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        $root = realpath(WP_SEED_PIXEL_RECOVERY_ROOT); $web = realpath(ABSPATH); $uploads = realpath(wp_upload_dir(null, false)['basedir']);
        if (!$root || !$web || !$uploads || is_link(WP_SEED_PIXEL_RECOVERY_ROOT) || $root === $web || strpos($root, $web . '/') === 0
            || $root === $uploads || strpos($root, $uploads . '/') === 0 || (fileperms($root) & 0077)) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        $cursor = '/';
        foreach (explode('/', trim($root, '/')) as $part) { $cursor = rtrim($cursor, '/') . '/' . $part; if (is_link($cursor)) { return new WP_Error('UNSUPPORTED_STORAGE'); } }
        $dir = $root . '/m3-' . (int) $item['job_id'] . '-' . (int) $item['id'];
        if (!is_dir($dir) && (!$create || !mkdir($dir, 0700))) { return new WP_Error('BACKUP_FAILED'); }
        if (is_link($dir) || realpath($dir) !== $dir || (fileperms($dir) & 0077) || fileowner($dir) !== fileowner($root)) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        return $dir;
    }

    public static function save($dir, array $record) {
        if (!WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        unset($record['checksum']); $record['checksum'] = hash('sha256', wp_json_encode($record));
        $p = $dir . '/journal.next';
        if (file_exists($p) || is_link($p) || is_link($dir . '/journal.json')
            || (is_file($dir . '/journal.json') && lstat($dir . '/journal.json')['nlink'] !== 1)) { return new WP_Error('EVIDENCE_INVALID'); }
        $json = wp_json_encode($record);
        $admission = WP_Seed_Pixel_Storage_Budget::admit(strlen($json) * 2, $dir); if (is_wp_error($admission)) { return $admission; }
        $f = @fopen($p, 'xb');
        if (!$f) { return new WP_Error('BACKUP_FAILED'); }
        $ok = fwrite($f, $json) === strlen($json) && fflush($f) && (!function_exists('fsync') || fsync($f)); fclose($f);
        if (!$ok || !WP_Seed_Pixel_Authority::valid_all() || !rename($p, $dir . '/journal.json')) { return new WP_Error('BACKUP_FAILED'); }
        if (class_exists('WP_Seed_Pixel_Quarantine') && WP_Seed_Pixel_Quarantine::enabled() && !self::sync_directory($dir)) { return new WP_Error('BACKUP_FAILED'); }
        return $record;
    }

    public static function sync_directory($dir) {
        $f = @fopen($dir, 'r');
        if (!$f) { return false; }
        try { return @fsync($f); } finally { fclose($f); }
    }

    public static function load($dir, array $item) {
        $p = $dir . '/journal.json';
        if (!file_exists($p)) { return null; }
        if (is_link($p) || filesize($p) > 1048576) { return new WP_Error('EVIDENCE_INVALID'); }
        $r = json_decode(file_get_contents($p), true);
        if (!is_array($r) || !isset($r['checksum'], $r['item_id'], $r['job_id'], $r['policy_hash'])) { return new WP_Error('EVIDENCE_INVALID'); }
        $hash = $r['checksum']; unset($r['checksum']);
        $data = json_decode($item['data'], true);
        $job = WP_Seed_Pixel_Job_Store::job($item['job_id']);
        if (!hash_equals($hash, hash('sha256', wp_json_encode($r))) || $r['item_id'] !== (int) $item['id'] || $r['job_id'] !== (int) $item['job_id']
            || !hash_equals($item['snapshot_hash'], hash('sha256', $item['data'])) || !$job || $r['policy_hash'] !== $job['policy_hash']
            || ($r['before'] ?? null) !== ($data['before'] ?? null)) { return new WP_Error('EVIDENCE_INVALID'); }
        return $r;
    }

    private static function copy_exact($source, $destination, $sha) {
        if (!WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        if (is_link($destination) || is_link($source)) { return new WP_Error('BACKUP_FAILED'); }
        if (file_exists($destination)) { return hash_file('sha256', $destination) === $sha ? true : new WP_Error('EVIDENCE_INVALID'); }
        $in = @fopen($source, 'rb'); $out = @fopen($destination, 'xb');
        if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return new WP_Error('BACKUP_FAILED'); }
        $n = stream_copy_to_stream($in, $out); $ok = $n !== false && fflush($out) && (!function_exists('fsync') || fsync($out));
        fclose($in); fclose($out);
        return $ok && hash_file('sha256', $destination) === $sha ? true : new WP_Error('BACKUP_FAILED');
    }

    public static function peak(array $before) {
        foreach (array('bytes', 'width', 'height') as $key) { if (!is_int($before[$key] ?? null) || $before[$key] < 1) { return new WP_Error('QUOTA_UNKNOWN'); } }
        if ($before['bytes'] > intdiv(PHP_INT_MAX, 4) || $before['width'] > intdiv(PHP_INT_MAX, $before['height'])) { return new WP_Error('QUOTA_UNKNOWN'); }
        $pixels = $before['width'] * $before['height'];
        if ($pixels > intdiv(PHP_INT_MAX, 8)) { return new WP_Error('QUOTA_UNKNOWN'); }
        $memory = $pixels * 8; $files = $before['bytes'] * 4;
        if ($files > PHP_INT_MAX - 16777216 || $memory > PHP_INT_MAX - $files - 16777216) { return new WP_Error('QUOTA_UNKNOWN'); }
        return $files + $memory + 16777216;
    }

    private static function boundary($name, array $item) { do_action('wp_seed_pixel_m3_boundary', $name, (int) $item['id']); }

    public static function prepare(array $item, array $policy, $encode = true) {
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $item['attachment_id'])) { return new WP_Error('LOCKED'); }
        $dir = self::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = self::load($dir, $item); if (is_wp_error($r)) { return $r; }
        $data = json_decode($item['data'], true); $before = $data['before'];
        if (!$r) {
            $path = WP_Seed_Pixel_Master_Adapter::path($before); if (is_wp_error($path)) { return $path; }
            $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($before['attachment_id']);
            if (is_wp_error($fresh) || $fresh !== $before) { return new WP_Error('SOURCE_CHANGED'); }
            $peak = self::peak($before); if (is_wp_error($peak)) { return $peak; }
            $admission = WP_Seed_Pixel_Storage_Budget::admit($peak, $dir); if (is_wp_error($admission)) { return $admission; }
            if ($policy['capacity_bytes'] < $peak) { return new WP_Error('QUOTA_UNKNOWN'); }
            if (disk_free_space($dir) < $peak) { return new WP_Error('LOW_DISK'); }
            if (stat($dir)['dev'] !== stat($path)['dev']) { return new WP_Error('UNSUPPORTED_STORAGE'); }
            $r = array('job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'], 'policy_hash' => WP_Seed_Pixel_Policy::hash($policy),
                'before' => $before, 'phase' => 'preparing', 'candidate' => null, 'after' => null, 'peak_budget' => $peak);
            $r = self::save($dir, $r); if (is_wp_error($r)) { return $r; }
            self::boundary('intent', $item);
        }
        if ($r['policy_hash'] !== WP_Seed_Pixel_Policy::hash($policy) || $r['before'] !== $before) { return new WP_Error('EVIDENCE_INVALID'); }
        if (!$encode || $r['candidate']) { return $r; }
        $path = WP_Seed_Pixel_Master_Adapter::path($before); if (is_wp_error($path)) { return $path; }
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($before['attachment_id']);
        if (is_wp_error($fresh) || $fresh !== $before) { return new WP_Error('SOURCE_CHANGED'); }
        if (file_exists($dir . '/candidate.jpg')) {
            // A crash before candidate identity publication is ambiguous. Keep its bytes.
            return new WP_Error('EVIDENCE_INVALID');
        }
        // Encode the exact private input, never a path another optimizer can replace.
        $admission = WP_Seed_Pixel_Storage_Budget::admit($r['peak_budget'], $dir); if (is_wp_error($admission)) { return $admission; }
        $copy = self::copy_exact($path, $dir . '/recovery.jpg', $before['sha256']); if (is_wp_error($copy)) { return $copy; }
        self::boundary('escrow', $item);
        $candidate = WP_Seed_Pixel_Master_Processor::create($dir . '/recovery.jpg', $dir . '/candidate.jpg', $policy);
        if (is_wp_error($candidate)) {
            // Only this operation's pre-swap candidate; never canonical/original bytes.
            if (WP_Seed_Pixel_Authority::valid_all() && is_file($dir . '/candidate.jpg') && !is_link($dir . '/candidate.jpg')) { unlink($dir . '/candidate.jpg'); }
            return $candidate;
        }
        $after = WP_Seed_Pixel_Master_Adapter::metadata($before, $candidate);
        if (!is_wp_error($after) && ($candidate['processor'] ?? '') === WP_Seed_Pixel_PNG_Processor::VERSION
            && $before['bytes'] - $candidate['bytes'] < 65536 + 4 * strlen(wp_json_encode(array($before, $after)))) {
            // PNG must also cover the actual native graph's durable evidence, not just encoder savings.
            $after = new WP_Error('NO_BENEFIT');
        }
        if (is_wp_error($after)) {
            if (WP_Seed_Pixel_Authority::valid_all() && !is_link($dir . '/candidate.jpg') && hash_file('sha256', $dir . '/candidate.jpg') === $candidate['sha256']) { unlink($dir . '/candidate.jpg'); }
            return $after;
        }
        $r['candidate'] = $candidate; $r['after'] = $after;
        $r['witness'] = array('engine' => self::ENGINE, 'item_id' => (int) $item['id'], 'job_id' => (int) $item['job_id'],
            'source_sha256' => $before['sha256'], 'sha256' => $candidate['sha256'], 'policy_hash' => $r['policy_hash']);
        $r = self::save($dir, $r); if (is_wp_error($r)) { return $r; }
        self::boundary('candidate', $item);
        $r['phase'] = 'ready'; $r = self::save($dir, $r);
        return $r;
    }

    public static function switch_master(array $item, array $policy) {
        $r = self::prepare($item, $policy); if (is_wp_error($r)) { return $r; }
        $dir = self::directory($item); $before = $r['before'];
        // Ready evidence after a crash at escrow creation must be finished idempotently.
        $copy = self::copy_exact(WP_Seed_Pixel_Master_Adapter::path($before), $dir . '/recovery.jpg', $before['sha256']);
        if (is_wp_error($copy)) { return $copy; }
        $observed = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($observed)) { return $observed; }
        if ($observed['hash'] === $r['candidate']['sha256']) { return $r; }
        if ($observed['meta_after'] || $observed['witness_after']) { return new WP_Error('METADATA_CONFLICT'); }
        $path = WP_Seed_Pixel_Master_Adapter::path($before); $candidate = $dir . '/candidate.jpg';
        $r['phase'] = 'switch_intent'; $r = self::save($dir, $r); if (is_wp_error($r)) { return $r; }
        self::boundary('pre_swap', $item);
        $observed = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($observed) || $observed['hash'] !== $before['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
        if (is_link($dir . '/recovery.jpg') || hash_file('sha256', $dir . '/recovery.jpg') !== $before['sha256']) { return new WP_Error('BACKUP_FAILED'); }
        $swap = self::replace($candidate, $path, $r['candidate']['sha256'], $before['mode']); if (is_wp_error($swap)) { return $swap; }
        self::boundary('post_swap', $item);
        $r['phase'] = 'switched'; return self::save($dir, $r);
    }

    private static function replace($candidate, $canonical, $sha, $mode) {
        if (!WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        if (is_link($candidate) || is_link($canonical) || !is_file($candidate) || hash_file('sha256', $candidate) !== $sha
            || stat($candidate)['dev'] !== stat($canonical)['dev'] || !is_writable(dirname($canonical))
            || fileowner($candidate) !== fileowner($canonical) || !chgrp($candidate, filegroup($canonical))
            || !chmod($candidate, $mode)) { return new WP_Error('SWAP_FAILED'); }
        $f = fopen($candidate, 'rb'); $ok = !function_exists('fsync') || fsync($f); fclose($f);
        if (!$ok || !WP_Seed_Pixel_Authority::valid_all() || !rename($candidate, $canonical)) { return new WP_Error('SWAP_FAILED'); }
        if (WP_Seed_Pixel_Quarantine::enabled() && !self::sync_directory(dirname($canonical))) { return new WP_Error('SWAP_FAILED'); }
        clearstatcache(true, $canonical);
        return hash_file('sha256', $canonical) === $sha ? true : new WP_Error('VERIFY_FAILED');
    }

    public static function verify(array $item, array $r, $restore = false, $http = true) {
        $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
        $expected = $restore ? $r['before'] : $r['candidate'];
        if ($o['hash'] !== $expected['sha256'] || (!$restore && (!$o['meta_after'] || !$o['witness_after']))) { return new WP_Error('VERIFY_FAILED'); }
        if ($restore && (!$o['meta_before'] || !$o['witness_before'])) { return new WP_Error('VERIFY_FAILED'); }
        $id = $r['before']['attachment_id']; $src = wp_get_attachment_image_src($id, 'full');
        $path = WP_Seed_Pixel_Master_Adapter::path($r['before']);
        $info = @getimagesize($path);
        $decode = $info && $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$decode || !$src || $src[0] !== $r['before']['url'] || $src[1] !== $expected['width'] || $src[2] !== $expected['height']) { return new WP_Error('VERIFY_FAILED'); }
        unset($decode);
        if ($http) {
            $response = wp_remote_get($src[0], array('timeout' => 10, 'limit_response_size' => 64000001, 'headers' => array('Cache-Control' => 'no-cache')));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || hash('sha256', wp_remote_retrieve_body($response)) !== $expected['sha256']) { return new WP_Error('VERIFY_FAILED'); }
        }
        return true;
    }

    public static function restore(array $item) {
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $item['attachment_id'])) { return new WP_Error('LOCKED'); }
        $dir = self::directory($item); if (is_wp_error($dir)) { return $dir; }
        $r = self::load($dir, $item); if (is_wp_error($r) || !$r || !$r['candidate']) { return new WP_Error('EVIDENCE_INVALID'); }
        if (in_array($r['phase'], array('purge_intent', 'purged'), true)) { return new WP_Error('RESTORE_UNAVAILABLE'); }
        $o = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($o)) { return $o; }
        if (!is_file($dir . '/recovery.jpg') || hash_file('sha256', $dir . '/recovery.jpg') !== $r['before']['sha256']) { return new WP_Error('BACKUP_FAILED'); }
        $r['phase'] = 'rollback_intent'; $r = self::save($dir, $r); if (is_wp_error($r)) { return $r; }
        if ($o['hash'] !== $r['before']['sha256']) {
            $admission = WP_Seed_Pixel_Storage_Budget::admit($r['before']['bytes'] + 16777216, $dir); if (is_wp_error($admission)) { return $admission; }
            $copy = self::copy_exact($dir . '/recovery.jpg', $dir . '/restore.jpg', $r['before']['sha256']); if (is_wp_error($copy)) { return $copy; }
            // Copying can take time: a writer outside the Pixel lock may have won.
            $fresh = WP_Seed_Pixel_Master_Adapter::observe($r); if (is_wp_error($fresh)) { return $fresh; }
            if ($fresh !== $o) { return new WP_Error('SOURCE_CHANGED'); }
            $swap = self::replace($dir . '/restore.jpg', WP_Seed_Pixel_Master_Adapter::path($r['before']), $r['before']['sha256'], $r['before']['mode']); if (is_wp_error($swap)) { return $swap; }
        }
        self::boundary('rollback_file', $item);
        $meta = WP_Seed_Pixel_Master_Adapter::reconcile($r, true); if (is_wp_error($meta)) { return $meta; }
        $verify = self::verify($item, $r, true); if (is_wp_error($verify)) { return $verify; }
        $r['phase'] = 'rolled_back'; return self::save($dir, $r);
    }
}
