<?php
defined('ABSPATH') || exit;

/** Immutable transport evidence, not authorization to bind or restore a cloned recovery. */
final class WP_Seed_Pixel_Migration_Evidence {
    const VERSION = 2;
    const MAX_FILES = 20000;
    const MAX_ROWS = 10000;

    private static function error($code) { return new WP_Error('MIGRATION_' . $code); }
    private static function inside($path, $root) { return $path === $root || str_starts_with($path, $root . '/'); }

    private static function plain($path) {
        if (!is_string($path) || !str_starts_with($path, '/') || strpos($path, "\0") !== false
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return false; }
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') { return false; }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { return false; }
        }
        return realpath($path) === $path;
    }

    private static function private_directory($path, array $webroots) {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || !function_exists('fsync')
            || !self::plain($path) || !is_dir($path) || !is_writable($path)) { return false; }
        $s = lstat($path);
        if (($s['mode'] & 0777) !== 0700 || $s['uid'] !== posix_geteuid() || !$webroots) { return false; }
        $webroots[] = rtrim(ABSPATH, '/');
        $webroots[] = wp_upload_dir(null, false)['basedir'];
        foreach ($webroots as $root) {
            if (!self::plain($root) || self::inside($path, $root) || self::inside($root, $path)) { return false; }
        }
        return true;
    }

    private static function files($root) {
        if (!self::plain($root)) { return self::error('UNSAFE_PATH'); }
        $result = array(); $pending = array('');
        while ($pending) {
            $relative = array_pop($pending); $path = $root . ($relative === '' ? '' : '/' . $relative);
            $entries = scandir($path); if ($entries === false) { return self::error('READ_FAILED'); }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $key = $relative === '' ? $name : $relative . '/' . $name; $file = $root . '/' . $key;
                $s = lstat($file);
                if (!$s || is_link($file) || $s['uid'] !== posix_geteuid()) { return self::error('FOREIGN_FILE'); }
                if (is_dir($file)) {
                    if (($s['mode'] & 0777) !== 0700) { return self::error('PUBLIC_FILE'); }
                    $pending[] = $key;
                } elseif (is_file($file) && $s['nlink'] === 1 && ($s['mode'] & 0022) === 0) {
                    $sha = hash_file('sha256', $file); if (!$sha) { return self::error('READ_FAILED'); }
                    $result[$key] = array('sha256' => $sha, 'bytes' => $s['size'], 'dev' => $s['dev'],
                        'ino' => $s['ino'], 'uid' => $s['uid'], 'mode' => $s['mode'] & 0777);
                } else { return self::error('FOREIGN_FILE'); }
                if (count($result) + count($pending) > self::MAX_FILES) { return self::error('LIMIT'); }
            }
        }
        ksort($result); return $result;
    }

    private static function rows() {
        global $wpdb;
        $rows = array();
        foreach (array('jobs', 'items') as $kind) {
            $rows[$kind] = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table($kind)
                . ' ORDER BY id LIMIT ' . (self::MAX_ROWS + 1), ARRAY_A);
            if (!is_array($rows[$kind]) || $wpdb->last_error || count($rows[$kind]) > self::MAX_ROWS) { return self::error('SQL_READ_FAILED'); }
        }
        return $rows;
    }

    private static function validate_rows(array $rows) {
        if (!isset($rows['jobs'], $rows['items']) || !is_array($rows['jobs']) || !is_array($rows['items'])
            || count($rows['jobs']) > self::MAX_ROWS || count($rows['items']) > self::MAX_ROWS) { return self::error('SQL_CONFLICT'); }
        $jobs = array(); $claims = array(); $ids = array();
        foreach ($rows['jobs'] as $job) {
            if (!is_array($job) || array_diff(array('id','kind','engine','schema_version','policy','policy_hash','lease','lease_until','status'), array_keys($job))
                || !is_string($job['kind']) || !is_string($job['engine']) || (!is_string($job['policy']) && $job['kind'] !== 'scan') || !is_string($job['policy_hash'])
                || (int)$job['id'] < 1 || isset($jobs[(int)$job['id']])) { return self::error('SQL_CONFLICT'); }
            if ($job['kind'] === 'scan') {
                if ($job['engine'] !== '' || (int)$job['schema_version'] !== 0 || $job['policy_hash'] !== ''
                    || !in_array($job['status'], array('complete', 'cancelled'), true)
                    || !empty($job['lease']) || (int)$job['lease_until'] !== 0) { return self::error('ACTIVE_JOB'); }
                $jobs[(int)$job['id']] = $job; continue;
            }
            $engines = array('plan'=>WP_Seed_Pixel_Job_Store::ENGINE, 'simulation'=>WP_Seed_Pixel_Job_Store::ENGINE,
                'replace'=>WP_Seed_Pixel_Master_Storage::ENGINE, 'retire'=>WP_Seed_Pixel_Quarantine::ENGINE,
                'convert'=>WP_Seed_Pixel_Job_Store::ENGINE);
            if (!isset($engines[$job['kind']]) || $job['engine'] !== $engines[$job['kind']]) { return self::error('ENGINE_NOT_CERTIFIED'); }
            $policy = json_decode($job['policy'],true);
            if (!is_array($policy) || ($policy['version'] ?? null) !== WP_Seed_Pixel_Policy::VERSION) { return self::error('ENGINE_NOT_CERTIFIED'); }
            if (in_array($job['kind'],array('replace','retire'),true) && (($policy['capability'] ?? '') !== $engines[$job['kind']]
                || ($policy['effective'][$job['kind']] ?? false) !== true || ($policy['effective']['simulation_only'] ?? true) !== false)) { return self::error('ENGINE_NOT_CERTIFIED'); }
            if ($job['kind'] === 'convert' && (($policy['conversion']['engine'] ?? '') !== WP_Seed_Pixel_Format_Conversion::ENGINE
                || ($policy['effective']['convert'] ?? false) !== true)) { return self::error('ENGINE_NOT_CERTIFIED'); }
            if ((int)$job['schema_version'] !== WP_Seed_Pixel_Job_Store::SCHEMA
                || !hash_equals($job['policy_hash'], hash('sha256', $job['policy']))) { return self::error('SQL_CONFLICT'); }
            if (!empty($job['lease']) || (int)$job['lease_until'] !== 0
                || !in_array($job['status'], array('completed', 'completed_errors', 'cancelled'), true)) { return self::error('ACTIVE_JOB'); }
            $jobs[(int)$job['id']] = $job;
        }
        foreach ($rows['items'] as $item) {
            if (!is_array($item) || array_diff(array('id','job_id','attachment_id','kind','lease','lease_until','snapshot_hash','data','journal','stage'), array_keys($item))
                || !is_string($item['snapshot_hash']) || !is_string($item['data']) || (!is_string($item['journal']) && !in_array($item['kind'],array('file','attachment'),true))
                || (int)$item['id'] < 1 || isset($ids[(int)$item['id']])) { return self::error('SQL_CONFLICT'); }
            $ids[(int)$item['id']] = true;
            // Conversion keeps its terminal token. An expired lease is historical, not live authority.
            if (!isset($jobs[(int)$item['job_id']]) || (int)$item['lease_until'] >= time()) { return self::error('SQL_CONFLICT'); }
            if ($item['kind'] !== 'operation') {
                if ($jobs[(int)$item['job_id']]['kind'] !== 'scan' && (!hash_equals($item['snapshot_hash'],hash('sha256',$item['data']))
                    || !WP_Seed_Pixel_Job_Store::journal_valid($item))) { return self::error('SQL_CONFLICT'); }
                continue;
            }
            if (!hash_equals($item['snapshot_hash'], hash('sha256', $item['data'])) || !WP_Seed_Pixel_Job_Store::journal_valid($item)) { return self::error('SQL_CONFLICT'); }
            $kind = $jobs[(int)$item['job_id']]['kind'];
            if (in_array($kind,array('replace','retire','convert'),true) && $item['action'] !== $kind) { return self::error('SQL_CONFLICT'); }
            if ($kind === 'simulation') {
                if (!in_array($item['stage'],array('retained','skipped','cancelled','failed','needs_review'),true)) { return self::error('REVIEW_REQUIRED'); }
                $receipt = json_decode($item['receipt'],true);
                if ($item['stage'] === 'retained' && (($receipt['simulation'] ?? false) !== true
                    || ($receipt['encoded'] ?? -1) !== 0 || ($receipt['deleted'] ?? -1) !== 0 || ($receipt['reclaimed_bytes'] ?? -1) !== 0)) { return self::error('REVIEW_REQUIRED'); }
                continue;
            }
            $data = json_decode($item['data'],true);
            $inert_review = in_array($item['stage'],array('needs_review','failed'),true) && is_array($data)
                && array_key_exists('before',$data) && $data['before'] === null && !empty($data['reason'])
                && empty($item['journal']);
            if (!in_array($item['stage'], array('retained', 'purged', 'rolled_back', 'skipped', 'cancelled'), true) && !$inert_review) { return self::error('REVIEW_REQUIRED'); }
            if ($inert_review) {
                // Admission must prove absence of any operational recovery, including in a transported bundle.
                if (!in_array($item['action'],array('replace','retire'),true)) { return self::error('REVIEW_REQUIRED'); }
            }
            if ($item['stage'] === 'retained') {
                $id = (int)$item['attachment_id'];
                if (isset($claims[$id])) { return self::error('CLAIM_CONFLICT'); }
                $claims[$id] = true;
            }
        }
        return true;
    }

    /** Acquires the existing SQL authority. No SQL updates or lifecycle reconciliation. */
    private static function capture() {
        if (!current_user_can('manage_options') || is_multisite()) { return self::error('PERMISSION_DENIED'); }
        if (!WP_Seed_Pixel_Authority::valid(0)) { return self::error('LOCKED'); }
        $locks = array();
        try {
            $state = WP_Seed_Pixel_Recovery_Setup::verify(); if (is_wp_error($state)) { return $state; }
            if (($state['state'] ?? '') !== 'ready' || !WP_Seed_Pixel_Recovery_Setup::gate()) { return self::error('UNBOUND'); }
            $rows = self::rows(); if (is_wp_error($rows)) { return $rows; }
            $valid = self::validate_rows($rows); if (is_wp_error($valid)) { return $valid; }
            $public = array(); $job_kinds = array_column($rows['jobs'],'kind','id');
            foreach ($rows['items'] as $item) {
                if ($item['kind'] !== 'operation') { continue; }
                if ($job_kinds[$item['job_id']] === 'simulation') {
                    if (file_exists($state['path'] . '/m3-' . (int)$item['job_id'] . '-' . (int)$item['id'])) { return self::error('REVIEW_REQUIRED'); }
                    continue;
                }
                $data = json_decode($item['data'],true);
                if (empty($data['before']) && $item['stage'] !== 'retained') {
                    $dir = $state['path'] . '/m3-' . (int)$item['job_id'] . '-' . (int)$item['id'];
                    if (file_exists($dir) || is_link($dir)) { return self::error('REVIEW_REQUIRED'); }
                    continue;
                }
                $id = (int)$item['attachment_id'];
                if (!current_user_can('edit_post', $id)) { return self::error('PERMISSION_DENIED'); }
                if (!isset($public[$id])) {
                    $lock = WP_Seed_Pixel_Files::lock($id); if (is_wp_error($lock)) { return $lock; } $locks[] = $lock;
                }
                if ($item['stage'] === 'retained') {
                    $q = WP_Seed_Pixel_Migration_Binding::availability($item);
                    if (is_wp_error($q) || !$q) { return self::error('RECOVERY_INVALID'); }
                }
                if ($item['stage'] === 'purged' && WP_Seed_Pixel_Migration_Binding::terminal($item) !== true) { return self::error('RECOVERY_INVALID'); }
                $snapshot = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true);
                if (is_wp_error($snapshot)) { return $snapshot; }
                if ($item['action'] === 'convert' && $item['stage'] === 'retained') {
                    $dir = WP_Seed_Pixel_Master_Storage::directory($item,false);
                    $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); if (is_wp_error($r)) { return $r; }
                    foreach ($r['before']['files'] as $relative=>$file) {
                        if (isset($snapshot['files'][$relative]) && $snapshot['files'][$relative] !== $file) { return self::error('PUBLIC_GRAPH_CONFLICT'); }
                        $snapshot['files'][$relative] = $file;
                    }
                }
                $public[$id] = $snapshot;
            }
            global $wpdb;
            $legacy_jobs = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='_seed_pixel_job' ORDER BY post_id LIMIT 10001");
            if ($wpdb->last_error || count($legacy_jobs) > self::MAX_ROWS) { return self::error('SQL_READ_FAILED'); }
            foreach ($legacy_jobs as $id) {
                $job = get_post_meta((int)$id,'_seed_pixel_job',true);
                if (is_array($job) && ($job['status'] ?? '') === 'processing') { return self::error('LEGACY_RECOVERY_PENDING'); }
            }
            $legacy = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_seed_pixel_manifest','_seed_pixel_history') ORDER BY post_id LIMIT 10001");
            if ($wpdb->last_error || count($legacy) > self::MAX_ROWS) { return self::error('SQL_READ_FAILED'); }
            foreach ($legacy as $legacy_id) {
                $id = (int)$legacy_id; if (isset($public[$id])) { continue; }
                $lock = WP_Seed_Pixel_Files::lock($id); if (is_wp_error($lock)) { return $lock; } $locks[] = $lock;
                $snapshot = WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true); if (is_wp_error($snapshot)) { return $snapshot; }
                $public[$id] = $snapshot;
            }
            $legacy_root = wp_upload_dir(null,false)['basedir'] . '/wp-seed-pixel';
            if (is_link($legacy_root) || (is_dir($legacy_root) && glob($legacy_root . '/job-*'))) { return self::error('LEGACY_RECOVERY_PENDING'); }
            ksort($public);
            $files = self::files($state['path']); if (is_wp_error($files)) { return $files; }
            if (isset($state['migration'])) {
                $lineage = WP_Seed_Pixel_Migration_Binding::verify_store($state); if (is_wp_error($lineage)) { return $lineage; }
            }
            $directories = array();
            foreach ($rows['items'] as $item) { $directories['m3-' . (int)$item['job_id'] . '-' . (int)$item['id']] = $item; }
            foreach ($files as $name => $file) {
                if ($name === '.pixel-owner.json') { continue; }
                if (isset($state['migration']) && ($name === WP_Seed_Pixel_Migration_Binding::PLAN
                    || str_starts_with($name,WP_Seed_Pixel_Migration_Binding::SOURCE . '/'))) { continue; }
                $parts = explode('/', $name);
                if (count($parts) !== 2 || !isset($directories[$parts[0]])
                    || in_array($parts[1], array('journal.next', 'restore.jpg'), true)) { return self::error('UNCLASSIFIED_RECOVERY'); }
                $r = WP_Seed_Pixel_Master_Storage::load($state['path'] . '/' . $parts[0], $directories[$parts[0]]);
                if (is_wp_error($r) || !$r) { return self::error('RECOVERY_INVALID'); }
            }
            if (!WP_Seed_Pixel_Authority::valid_all() || self::rows() !== $rows || WP_Seed_Pixel_Recovery_Setup::verify() !== $state) { return self::error('SOURCE_CHANGED'); }
            return array('version' => self::VERSION, 'state' => 'SOURCE_VERIFIED', 'pixel' => WP_SEED_PIXEL_VERSION,
                'context' => array('wordpress' => realpath(ABSPATH), 'prefix' => $GLOBALS['wpdb']->prefix,
                    'authority_namespace' => hash('sha256', $GLOBALS['wpdb']->get_var('SELECT DATABASE()') . ':' . $GLOBALS['wpdb']->prefix),
                    'home' => home_url(), 'uploads' => wp_upload_dir(null, false)['basedir'],
                    'uploads_identity' => array_intersect_key(stat(wp_upload_dir(null,false)['basedir']),array_flip(array('dev','ino','uid')))),
                'storage' => $state, 'sql' => $rows, 'public' => $public, 'files' => $files);
        } finally {
            foreach (array_reverse($locks) as $lock) { WP_Seed_Pixel_Files::unlock($lock); }
        }
    }

    public static function inspect() {
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return $site; }
        try { return self::capture(); } finally { WP_Seed_Pixel_Files::unlock($site); }
    }

    private static function write($path, $bytes) {
        $old = umask(0077);
        try { $f = @fopen($path, 'xb'); } finally { umask($old); }
        if (!$f) { return false; }
        try { return fwrite($f, $bytes) === strlen($bytes) && fflush($f) && fsync($f); }
        finally { fclose($f); }
    }

    private static function copy($source, $target, array $expected) {
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) { return false; }
        $old = umask(0077);
        try { $in = @fopen($source, 'rb'); $out = @fopen($target, 'xb'); } finally { umask($old); }
        if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return false; }
        try {
            $s = fstat($in);
            if ($s['dev'] !== $expected['dev'] || $s['ino'] !== $expected['ino'] || $s['nlink'] !== 1 || $s['size'] !== $expected['bytes']) { return false; }
            $n = stream_copy_to_stream($in, $out);
            return $n === $expected['bytes'] && fflush($out) && fsync($out)
                && hash_file('sha256', $target) === $expected['sha256'];
        } finally { fclose($in); fclose($out); }
    }

    /** All web roots must be supplied by trusted orchestration, including other hosted sites. */
    public static function export($parent, array $webroots) {
        if (!self::private_directory($parent, $webroots)) { return self::error('UNSAFE_DESTINATION'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return $site; }
        try {
            $proof = self::capture(); if (is_wp_error($proof)) { return $proof; }
            if (self::inside($parent, $proof['storage']['path']) || self::inside($proof['storage']['path'], $parent)) { return self::error('SHARED_STORAGE'); }
            $dir = $parent . '/transfer-' . bin2hex(random_bytes(12));
            if (!mkdir($dir, 0700) || !mkdir($dir . '/files', 0700)) { return self::error('WRITE_FAILED'); }
            foreach ($proof['files'] as $name => $file) {
                if (!self::copy($proof['storage']['path'] . '/' . $name, $dir . '/files/' . $name, $file)) { return self::error('COPY_FAILED'); }
            }
            if (self::capture() !== $proof) { return self::error('SOURCE_CHANGED'); }
            $json = wp_json_encode($proof);
            if (!self::write($dir . '/manifest.json', $json) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return self::error('WRITE_FAILED'); }
            return array('state' => 'EXPORTED_UNBOUND', 'directory' => $dir, 'manifest_sha256' => hash('sha256', $json));
        } finally { WP_Seed_Pixel_Files::unlock($site); }
    }

    /** Expected SHA comes from the trusted source, not from the untrusted transported package. */
    public static function verify_bundle($dir, $expected_sha, array $webroots) {
        if (!current_user_can('manage_options')) { return self::error('PERMISSION_DENIED'); }
        if (!is_string($expected_sha) || !preg_match('/^[a-f0-9]{64}$/D', $expected_sha) || !self::private_directory($dir, $webroots)) { return self::error('UNSAFE_BUNDLE'); }
        if (scandir($dir) !== array('.', '..', 'files', 'manifest.json')) { return self::error('BUNDLE_INVALID'); }
        $manifest = $dir . '/manifest.json'; $s = @lstat($manifest);
        if (!$s || !is_file($manifest) || is_link($manifest) || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()
            || ($s['mode'] & 0077) || $s['size'] > 16777216 || hash_file('sha256', $manifest) !== $expected_sha) { return self::error('EVIDENCE_INVALID'); }
        $proof = json_decode(file_get_contents($manifest), true);
        if (!is_array($proof) || ($proof['version'] ?? null) !== self::VERSION || ($proof['state'] ?? '') !== 'SOURCE_VERIFIED'
            || !is_array($proof['files'] ?? null) || !is_array($proof['sql'] ?? null)) { return self::error('EVIDENCE_INVALID'); }
        $valid = self::validate_rows($proof['sql']); if (is_wp_error($valid)) { return $valid; }
        $files = self::files($dir . '/files'); if (is_wp_error($files)) { return $files; }
        if (array_keys($files) !== array_keys($proof['files'])) { return self::error('FILE_SET_CHANGED'); }
        $job_kinds = array_column($proof['sql']['jobs'],'kind','id');
        foreach ($proof['sql']['items'] as $item) {
            $data = json_decode($item['data'],true);
            if ($item['kind'] === 'operation' && (($job_kinds[$item['job_id']] ?? '') === 'simulation'
                || (empty($data['before']) && $item['stage'] !== 'retained'))) {
                $prefix = 'm3-' . (int)$item['job_id'] . '-' . (int)$item['id'] . '/';
                foreach (array_keys($files) as $name) { if (str_starts_with($name,$prefix)) { return self::error('REVIEW_REQUIRED'); } }
            }
        }
        foreach ($files as $name => $actual) {
            if ($actual['sha256'] !== ($proof['files'][$name]['sha256'] ?? '') || $actual['bytes'] !== ($proof['files'][$name]['bytes'] ?? null)) { return self::error('ORIGINAL_CHANGED'); }
        }
        return array('state' => 'VERIFIED_UNBOUND', 'manifest_sha256' => $expected_sha, 'files' => count($files),
            'restoration_authorized' => false, 'reason' => 'DESTINATION_BINDING_NOT_CERTIFIED');
    }

    /** Target inspection deliberately cannot mark an imported journal as live recovery evidence. */
    public static function assess_target($dir, $expected_sha, array $webroots) {
        if (!current_user_can('manage_options')) { return self::error('PERMISSION_DENIED'); }
        $bundle = self::verify_bundle($dir, $expected_sha, $webroots); if (is_wp_error($bundle)) { return $bundle; }
        $proof = json_decode(file_get_contents($dir . '/manifest.json'), true);
        $namespace = hash('sha256', $GLOBALS['wpdb']->get_var('SELECT DATABASE()') . ':' . $GLOBALS['wpdb']->prefix);
        if ($GLOBALS['wpdb']->last_error || $namespace === ($proof['context']['authority_namespace'] ?? null)
            || realpath(ABSPATH) === ($proof['context']['wordpress'] ?? null)) { return self::error('SHARED_AUTHORITY'); }
        $site = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($site)) { return $site; }
        try {
            $rows = self::rows(); if (is_wp_error($rows)) { return $rows; }
            if ($rows !== $proof['sql']) { return self::error('TARGET_SQL_CONFLICT'); }
            $source_root = $proof['storage']['path'] ?? '';
            $target_state = WP_Seed_Pixel_Recovery_Setup::verify();
            if (!is_wp_error($target_state) && ($target_state['path'] === $source_root
                || self::inside($target_state['path'], $source_root) || self::inside($source_root, $target_state['path']))) { return self::error('SHARED_STORAGE'); }
            $u = wp_upload_dir(null, false)['basedir']; $seen = array();
            foreach ($proof['public'] ?? array() as $snapshot) {
                foreach ($snapshot['files'] as $relative => $file) {
                    $path = WP_Seed_Pixel_Files::path($u . '/' . $relative);
                    if (is_wp_error($path) || filesize($path) !== $file['bytes'] || hash_file('sha256', $path) !== $file['sha256']) { return self::error('PUBLIC_GRAPH_CHANGED'); }
                    if (isset($seen[$relative]) && $seen[$relative] !== $file) { return self::error('PUBLIC_GRAPH_CONFLICT'); }
                    $seen[$relative] = $file;
                }
            }
            if (!WP_Seed_Pixel_Authority::valid_all() || self::rows() !== $rows) { return self::error('TARGET_SQL_CONFLICT'); }
            return array_merge($bundle, array('state' => 'AWAITING_DESTINATION_BINDING',
                'sql_transport_exact' => true, 'public_file_transport_exact' => true,
                'storage_gate' => is_wp_error($target_state) ? $target_state->get_error_code() : 'NEW_TARGET_STORAGE',
                'public_graph_certified' => false));
        } finally { WP_Seed_Pixel_Files::unlock($site); }
    }
}
