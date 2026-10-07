<?php
defined('ABSPATH') || exit;

/** Dedicated format lifecycle on the existing M2 store and M5.1 SQL authority. */
final class WP_Seed_Pixel_Format_Conversion {
    const ENGINE = 'png-jpeg-explicit-1';

    private static function owned($id, callable $operation) {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $id) || is_multisite()) { return new WP_Error('PERMISSION_DENIED'); }
        if (!WP_Seed_Pixel_Master_Storage::enabled()) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        $global = WP_Seed_Pixel_Authority::acquire(0); if (is_wp_error($global)) { return $global; }
        $image = null;
        try {
            $image = WP_Seed_Pixel_Authority::acquire($id); if (is_wp_error($image)) { return $image; }
            return $operation();
        } finally {
            if (is_object($image) && !is_wp_error($image)) { WP_Seed_Pixel_Authority::release($image); }
            WP_Seed_Pixel_Authority::release($global);
        }
    }

    public static function item($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') .
            " WHERE attachment_id=%d AND action='convert' AND kind='operation' ORDER BY id DESC LIMIT 1", $id), ARRAY_A);
    }

    private static function claim(array $item) {
        global $wpdb;
        if (!WP_Seed_Pixel_Authority::valid_all() || !WP_Seed_Pixel_Job_Store::journal_valid($item)) { return new WP_Error('LOCKED'); }
        $token = bin2hex(random_bytes(24));
        $ok = $wpdb->query($wpdb->prepare('UPDATE ' . WP_Seed_Pixel_Job_Store::table('items') .
            ' SET lease=%s,lease_until=%d WHERE id=%d AND revision=%d', $token, time() + 60, $item['id'], $item['revision']));
        if ($ok !== 1) { return new WP_Error('CLAIM_CONFLICT'); }
        $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease' => $token, 'lease_until' => time() + 60), array('id' => $item['job_id']));
        $fresh = WP_Seed_Pixel_Job_Store::item($item['id']);
        return $fresh && $fresh['lease'] === $token ? $fresh : new WP_Error('CLAIM_CONFLICT');
    }

    private static function advance(array $item, $stage) {
        if ($item['stage'] === $stage) { return $item; }
        return WP_Seed_Pixel_Job_Store::transition($item, $item['lease'], $stage);
    }

    private static function renew(array $item) { return WP_Seed_Pixel_Job_Store::renew($item, $item['lease']); }
    private static function boundary($name, array $item) { do_action('wp_seed_pixel_conversion_boundary', $name, (int) $item['id']); }
    private static function uploads() { return wp_upload_dir(null, false)['basedir']; }

    private static function absolute($relative, $exists = true) {
        if (!is_string($relative) || $relative === '' || str_contains($relative, '..') || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || strpos($relative, ':') !== false || preg_match('/[\x00-\x1f]/', $relative)) { return new WP_Error('UNSAFE_PATH'); }
        $path = self::uploads() . '/' . $relative;
        if ($exists) { return WP_Seed_Pixel_Files::path($path); }
        return WP_Seed_Pixel_Files::path($path, false);
    }

    private static function identity($path, array $expected, $links = 1) {
        clearstatcache(true, $path);
        $s = @lstat($path);
        return $s && is_file($path) && !is_link($path) && $s['nlink'] === $links
            && $s['uid'] === fileowner(dirname($path))
            && (!isset($expected['identity']) || ($s['dev'] === $expected['identity']['dev'] && $s['ino'] === $expected['identity']['ino']))
            && (!isset($expected['attributes']) || array('mode' => $s['mode'] & 0777, 'uid' => $s['uid'], 'gid' => $s['gid']) === $expected['attributes'])
            && $s['size'] === $expected['bytes'] && hash_file('sha256', $path) === $expected['sha256'];
    }

    private static function copy($source, $target, array $expected) {
        if (!WP_Seed_Pixel_Authority::valid_all() || is_link($source) || is_link($target)) { return new WP_Error('LOCKED'); }
        if (file_exists($target)) { return self::identity($target, $expected) ? true : new WP_Error('EVIDENCE_INVALID'); }
        $in = fopen($source, 'rb'); $out = fopen($target, 'xb');
        if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return new WP_Error('BACKUP_FAILED'); }
        $bytes = stream_copy_to_stream($in, $out);
        $ok = $bytes === $expected['bytes'] && fflush($out) && fsync($out); fclose($in); fclose($out);
        chmod($target, 0600);
        return $ok && self::identity($target, $expected) ? true : new WP_Error('BACKUP_FAILED');
    }

    private static function durable($path) {
        if (!WP_Seed_Pixel_Authority::valid_all() || is_link($path) || !is_file($path)) { return false; }
        $f = fopen($path, 'rb'); if (!$f) { return false; }
        try { return fsync($f) && WP_Seed_Pixel_Master_Storage::sync_directory(dirname($path)); }
        finally { fclose($f); }
    }

    private static function reserve($target, $known, $dir) {
        if (file_exists($target) || is_link($target)) {
            $stat = lstat($target);
            if (!$known || is_link($target) || $stat['dev'] !== $known['dev'] || $stat['ino'] !== $known['ino']
                || $stat['uid'] !== fileowner($dir) || $stat['nlink'] !== 1 || !WP_Seed_Pixel_Authority::valid_all() || !unlink($target)) { return new WP_Error('EVIDENCE_INVALID'); }
        }
        $f = fopen($target, 'xb'); if (!$f) { return new WP_Error('BACKUP_FAILED'); }
        $ok = fflush($f) && fsync($f); fclose($f); chmod($target, 0600);
        if (!$ok || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('BACKUP_FAILED'); }
        $s = lstat($target); return array('dev' => $s['dev'], 'ino' => $s['ino']);
    }

    public static function references(array $before) {
        global $wpdb;
        $names = array_map('basename', array_keys($before['files']));
        $variants = array();
        foreach ($names as $name) {
            $variants[] = $name; $variants[] = rawurlencode($name); $variants[] = substr(wp_json_encode($name), 1, -1);
        }
        $names = array_values(array_unique($variants));
        $found = array();
        foreach (array(array($wpdb->posts, 'ID', 'post_content'), array($wpdb->postmeta, 'meta_id', 'meta_value'), array($wpdb->options, 'option_id', 'option_value')) as $surface) {
            list($table, $key, $column) = $surface;
            $clauses = array_fill(0, count($names), "$column LIKE %s");
            $values = array_map(static function ($name) use ($wpdb) { return '%' . $wpdb->esc_like($name) . '%'; }, $names);
            $exclude = $table === $wpdb->postmeta ? $wpdb->prepare(' AND post_id<>%d', $before['attachment_id']) : '';
            $rows = $wpdb->get_col($wpdb->prepare("SELECT $key FROM $table WHERE (" . implode(' OR ', $clauses) . ")$exclude LIMIT 21", $values));
            if ($wpdb->last_error) { return new WP_Error('REFERENCE_SCAN_FAILED'); }
            $found[$surface[2]] = array_map('intval', $rows);
        }
        return $found;
    }

    public static function analyze($id) {
        return self::owned($id, static function () use ($id) {
            global $wpdb;
            $install = WP_Seed_Pixel_Job_Store::install(); if (is_wp_error($install)) { return $install; }
            $claims = WP_Seed_Pixel_Jobs::review_claims($id);
            if (is_wp_error($claims)) { return $claims; }
            $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); if (is_wp_error($before)) { return $before; }
            $meta = maybe_unserialize($before['rows']['_wp_attachment_metadata'][0]);
            if ($before['mime'] !== 'image/png' || !empty($meta['original_image']) || $before['rows']['_seed_pixel_master_state']
                || $before['rows']['_seed_pixel_manifest'] || $before['rows']['_seed_pixel_history']) { return new WP_Error('UNSUPPORTED_CONVERSION_STATE'); }
            $post_table = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $wpdb->posts), ARRAY_A);
            if (!$post_table || strtoupper($post_table['Engine']) !== 'INNODB') { return new WP_Error('UNSUPPORTED_STORAGE'); }
            $source = WP_Seed_Pixel_Master_Adapter::path($before);
            $info = WP_Seed_Pixel_Format_Processor::inspect($source); if (is_wp_error($info)) { return $info; }
            $references = self::references($before); if (is_wp_error($references)) { return $references; }
            $registered = wp_get_registered_image_subsizes();
            $allowed = array($before['relative']);
            foreach ($meta['sizes'] ?? array() as $name => $size) {
                if (!isset($registered[$name]) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name)) { return new WP_Error('DERIVATIVE_CONTRACT_UNKNOWN'); }
                $allowed[] = (dirname($before['relative']) === '.' ? '' : dirname($before['relative']) . '/') . $size['file'];
            }
            $actual = array_keys($before['files']); $allowed = array_values(array_unique($allowed)); sort($actual); sort($allowed);
            if ($actual !== $allowed) { return new WP_Error('INVENTORY_INCOMPLETE'); }
            $pending = self::item($id);
            if ($pending && !in_array($pending['stage'], array('rolled_back', 'skipped', 'failed', 'cancelled'), true)) {
                $old = json_decode($pending['data'], true);
                if (($old['before'] ?? null) !== $before) { return new WP_Error('SOURCE_CHANGED'); }
                $item = self::claim($pending); if (is_wp_error($item)) { return $item; }
            } else {
                $p = WP_Seed_Pixel_Policy::normalize();
                $p['effective'] = array('simulation_only' => false, 'replace' => false, 'retire' => false, 'purge' => false, 'convert' => true);
                $p['conversion'] = array('engine' => self::ENGINE, 'generation' => bin2hex(random_bytes(16)));
                $job = WP_Seed_Pixel_Job_Store::insert_job('convert', 0, $p, 1); if (is_wp_error($job)) { return $job; }
                $data = wp_json_encode(array('before' => $before, 'registered' => $registered, 'references' => $references));
                $inserted = $wpdb->insert(WP_Seed_Pixel_Job_Store::table('items'), array('job_id' => $job, 'kind' => 'operation',
                    'item_key' => $id . ':convert', 'attachment_id' => $id, 'action' => 'convert', 'stage' => 'queued',
                    'data' => $data, 'snapshot_hash' => hash('sha256', $data), 'updated' => time()));
                if ($inserted !== 1) { return new WP_Error('STORE_FAILED'); }
                $item = self::claim(WP_Seed_Pixel_Job_Store::item($wpdb->insert_id)); if (is_wp_error($item)) { return $item; }
            }
            $dir = WP_Seed_Pixel_Master_Storage::directory($item); if (is_wp_error($dir)) { return $dir; }
            $record = WP_Seed_Pixel_Master_Storage::load($dir, $item); if (is_wp_error($record)) { return $record; }
            if (!$record) {
                $job = WP_Seed_Pixel_Job_Store::job($item['job_id']); $policy = json_decode($job['policy'], true);
                $total = array_sum(array_column($before['files'], 'bytes'));
                $peak = 6 * $total + 3 * $before['width'] * $before['height'] * 8 + 16777216;
                $admission = WP_Seed_Pixel_Storage_Budget::admit($peak, $dir); if (is_wp_error($admission)) { return $admission; }
                if (stat($dir)['dev'] !== stat($source)['dev']) { return new WP_Error('UNSUPPORTED_STORAGE'); }
                $record = array('job_id' => (int) $item['job_id'], 'item_id' => (int) $item['id'], 'policy_hash' => $job['policy_hash'],
                    'before' => $before, 'phase' => 'preparing', 'generation' => $policy['conversion']['generation'], 'peak_bytes' => $peak,
                    'candidate' => null, 'after' => null, 'backup' => array(), 'new_files' => array(), 'references' => $references,
                    'profiles' => array(), 'profile_slots' => array(), 'selected_profile' => null);
                $record = WP_Seed_Pixel_Master_Storage::save($dir, $record); if (is_wp_error($record)) { return $record; }
                self::boundary('intent', $item);
            }
            if ($record['phase'] === 'ready') {
                if ($item['stage'] === 'preparing') { $item = self::advance($item, 'ready'); if (is_wp_error($item)) { return $item; } }
                return self::view($item, $record, $dir);
            }
            if ($record['phase'] !== 'preparing') { return new WP_Error('RECOVERY_REQUIRED'); }
            // A profile-only preparation resumes within the already-ready M2 item.
            if ($item['stage'] !== 'ready') { $item = self::advance($item, 'preparing'); if (is_wp_error($item)) { return $item; } }
            $prepared = self::prepare($item, $record, $dir);
            if (is_wp_error($prepared)) {
                // Only a pre-publication, fully witnessed analysis may be discarded automatically.
                $latest = WP_Seed_Pixel_Master_Storage::load($dir, $item);
                if (is_array($latest) && in_array($prepared->get_error_code(), array('NO_CONVERSION_BENEFIT', 'QUALITY_REJECTED', 'CANDIDATE_INVALID', 'BACKEND_UNAVAILABLE', 'TARGET_METADATA_UNSUPPORTED'), true)) {
                    self::discard_owned($item, $latest, $dir);
                }
                return $prepared;
            }
            $item = self::advance($item, 'ready'); if (is_wp_error($item)) { return $item; }
            return self::view($item, $prepared, $dir);
        });
    }

    private static function prepare(array $item, array $r, $dir) {
        $before = $r['before'];
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($before['attachment_id']);
        if (is_wp_error($fresh) || $fresh !== $before) { return new WP_Error('SOURCE_CHANGED'); }
        $admission = WP_Seed_Pixel_Storage_Budget::admit($r['peak_bytes'], $dir); if (is_wp_error($admission)) { return $admission; }
        foreach ($before['files'] as $relative => $file) {
            $item = self::renew($item); if (is_wp_error($item)) { return $item; }
            $name = 'old-' . substr(hash('sha256', $relative), 0, 32) . '.png';
            $path = self::absolute($relative); if (is_wp_error($path)) { return $path; }
            if (!self::identity($path, $file)) { return new WP_Error('SOURCE_CHANGED'); }
            $o = lstat($path);
            $identity = array('dev' => $o['dev'], 'ino' => $o['ino']);
            $attributes = array('mode' => $o['mode'] & 0777, 'uid' => $o['uid'], 'gid' => $o['gid']);
            if ((isset($r['original_identity'][$relative]) && $r['original_identity'][$relative] !== $identity)
                || (isset($r['original_attributes'][$relative]) && $r['original_attributes'][$relative] !== $attributes)
                || ($relative === $before['relative'] && $attributes !== array('mode' => $before['mode'], 'uid' => $before['uid'], 'gid' => $before['gid']))) { return new WP_Error('SOURCE_CHANGED'); }
            if (isset($r['backup_identity'][$relative]) && !self::identity($dir . '/' . $name, array_merge($file, array('identity' => $r['backup_identity'][$relative])))) { return new WP_Error('BACKUP_FAILED'); }
            $copy = self::copy($path, $dir . '/' . $name, $file); if (is_wp_error($copy)) { return $copy; }
            $r['backup'][$relative] = $name;
            $s = lstat($dir . '/' . $name);
            $r['backup_identity'][$relative] = array('dev' => $s['dev'], 'ino' => $s['ino']);
            $r['original_identity'][$relative] = $identity;
            $r['original_attributes'][$relative] = $attributes;
        }
        $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        self::boundary('escrow', $item);
        $source = $dir . '/' . $r['backup'][$before['relative']];
        if (isset($r['profiles'])) {
            foreach (WP_Seed_Pixel_Format_Processor::profiles() as $key => $definition) {
                $item = self::renew($item); if (is_wp_error($item)) { return $item; }
                $path = $dir . '/profile-' . $key . '.jpg';
                if (isset($r['profiles'][$key])) {
                    if (!self::identity($path, $r['profiles'][$key])) { return new WP_Error('EVIDENCE_INVALID'); }
                    continue;
                }
                $slot = self::reserve($path, $r['profile_slots'][$key] ?? null, $dir); if (is_wp_error($slot)) { return $slot; }
                $r['profile_slots'][$key] = $slot;
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
                self::boundary('profile_intent', $item);
                $profile = WP_Seed_Pixel_Format_Processor::create($source, $path, $slot, $key);
                if (is_wp_error($profile)) { return $profile; }
                if (!self::durable($path)) { return new WP_Error('BACKUP_FAILED'); }
                self::boundary('profile_encoded', $item);
                $profile['identity'] = $slot;
                if (!self::identity($path, $profile)) { return new WP_Error('EVIDENCE_INVALID'); }
                $r['profiles'][$key] = $profile;
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            }
            if ($r['selected_profile'] === null) { $r['selected_profile'] = WP_Seed_Pixel_Format_Processor::lightest($r['profiles']); }
            if ($r['selected_profile'] === null) {
                if (!array_filter($r['profiles'], static function ($p) use ($before) { return WP_Seed_Pixel_Format_Processor::selectable($p, $before['bytes']); })) { return new WP_Error('NO_CONVERSION_BENEFIT'); }
                // No quality-passing default: retain measured choices, without preparing or approving one silently.
                $r['phase'] = 'ready';
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
                self::boundary('ready', $item);
                return $r;
            }
        }
        if (!$r['candidate']) {
            $target = $dir . '/new-master.jpg';
            $r['encoding_slot'] = self::reserve($target, $r['encoding_slot'] ?? null, $dir);
            if (is_wp_error($r['encoding_slot'])) { return $r['encoding_slot']; }
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            self::boundary('encoding_intent', $item);
            if (isset($r['profiles'])) {
                $candidate = $r['profiles'][$r['selected_profile']];
                if (!WP_Seed_Pixel_Format_Processor::selectable($candidate, $before['bytes'])) { return new WP_Error('INVALID_PROFILE'); }
                $in = fopen($dir . '/profile-' . $r['selected_profile'] . '.jpg', 'rb'); $out = fopen($target, 'r+b');
                if (!$in || !$out) { if ($in) { fclose($in); } if ($out) { fclose($out); } return new WP_Error('BACKUP_FAILED'); }
                $ok = stream_copy_to_stream($in, $out) === $candidate['bytes'] && fflush($out) && fsync($out);
                fclose($in); fclose($out);
                unset($candidate['identity']);
                if (!$ok) { return new WP_Error('BACKUP_FAILED'); }
            } else { $candidate = WP_Seed_Pixel_Format_Processor::create($source, $target, $r['encoding_slot']); }
            if (is_wp_error($candidate)) { return $candidate; }
            if (!self::durable($dir . '/new-master.jpg')) { return new WP_Error('BACKUP_FAILED'); }
            self::boundary('candidate_encoded', $item);
            $r['candidate'] = $candidate;
            $stat = lstat($dir . '/new-master.jpg');
            if ($stat['dev'] !== $r['encoding_slot']['dev'] || $stat['ino'] !== $r['encoding_slot']['ino'] || $stat['nlink'] !== 1 || is_link($target)) { return new WP_Error('EVIDENCE_INVALID'); }
            $r['candidate']['identity'] = array('dev' => $stat['dev'], 'ino' => $stat['ino']);
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        }
        if (!self::identity($dir . '/new-master.jpg', $r['candidate'])) { return new WP_Error('EVIDENCE_INVALID'); }
        $prefix = (dirname($before['relative']) === '.' ? '' : dirname($before['relative']) . '/') .
            'seed-pixel-' . (int) $before['attachment_id'] . '-' . $r['generation'];
        $data = json_decode($item['data'], true);
        $meta = maybe_unserialize($before['rows']['_wp_attachment_metadata'][0]);
        $meta['file'] = $prefix . '.jpg'; $meta['filesize'] = $r['candidate']['bytes'];
        $r['new_files'][$meta['file']] = array_merge($r['candidate'], array('private' => 'new-master.jpg'));
        foreach ($meta['sizes'] ?? array() as $name => $old) {
            $item = self::renew($item); if (is_wp_error($item)) { return $item; }
            $private = 'new-' . $name . '.jpg';
            $relative = $prefix . '-' . $name . '.jpg';
            if (!isset($r['new_files'][$relative])) {
                $target = $dir . '/' . $private;
                $slot = self::reserve($target, $r['derivative_slots'][$private] ?? null, $dir); if (is_wp_error($slot)) { return $slot; }
                $r['derivative_slots'][$private] = $slot;
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
                self::boundary('derivative_intent', $item);
                // Re-encode the exact native crop, not an already-lossy JPEG master.
                $original_relative = (dirname($before['relative']) === '.' ? '' : dirname($before['relative']) . '/') . $old['file'];
                $original = $dir . '/' . $r['backup'][$original_relative];
                $native_info = WP_Seed_Pixel_Format_Processor::inspect($original); if (is_wp_error($native_info)) { return $native_info; }
                $r['candidate']['provenance_lost'] = $r['candidate']['provenance_lost'] || $native_info['provenance'];
                $passed = false;
                foreach (WP_Seed_Pixel_Master_Processor::quality_profile() as $quality) {
                    $editor = wp_get_image_editor($original); if (is_wp_error($editor)) { return $editor; }
                    $dims = $editor->get_size();
                    if ($dims['width'] !== $old['width'] || $dims['height'] !== $old['height']) { return new WP_Error('DERIVATIVE_CONTRACT_CHANGED'); }
                    $saved = WP_Seed_Pixel_Format_Processor::save_jpeg($editor, $target, $quality); if (is_wp_error($saved)) { return $saved; }
                    if (!self::durable($saved['path']) || getimagesize($saved['path'])[2] !== IMAGETYPE_JPEG) { return new WP_Error('CANDIDATE_INVALID'); }
                    $metric = WP_Seed_Pixel_Adaptive::metric($original, $saved['path']);
                    if (!is_wp_error($metric) && $metric['ssim'] >= 0.995 && $metric['psnr'] >= 40) { $passed = true; break; }
                }
                if (!$passed) { return new WP_Error('QUALITY_REJECTED'); }
                self::boundary('derivative_encoded', $item);
                $r['new_files'][$relative] = array('private' => $private, 'bytes' => filesize($saved['path']),
                    'sha256' => hash_file('sha256', $saved['path']), 'width' => $dims['width'], 'height' => $dims['height'], 'metric' => $metric, 'quality' => $quality);
                $stat = lstat($saved['path']);
                if ($stat['dev'] !== $slot['dev'] || $stat['ino'] !== $slot['ino'] || $stat['nlink'] !== 1 || is_link($target)) { return new WP_Error('EVIDENCE_INVALID'); }
                $r['new_files'][$relative]['identity'] = array('dev' => $stat['dev'], 'ino' => $stat['ino']);
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            }
            $meta['sizes'][$name] = array_merge($old, array('file' => basename($relative), 'mime-type' => 'image/jpeg', 'filesize' => $r['new_files'][$relative]['bytes']));
        }
        $old_bytes = array_sum(array_column($before['files'], 'bytes'));
        $new_bytes = array_sum(array_column($r['new_files'], 'bytes'));
        foreach ($r['profiles'] ?? array() as $key => $profile) {
            if (!WP_Seed_Pixel_Format_Processor::benefit($old_bytes, $new_bytes - $r['candidate']['bytes'] + $profile['bytes'])) {
                $r['profiles'][$key]['accepted'] = false;
                $r['profiles'][$key]['selectable'] = false;
                $r['profiles'][$key]['rejection'] = 'NO_CONVERSION_BENEFIT';
            }
        }
        if (!WP_Seed_Pixel_Format_Processor::benefit($old_bytes, $new_bytes)) { return new WP_Error('NO_CONVERSION_BENEFIT'); }
        $r['after'] = $meta;
        $r['witness'] = array('engine' => self::ENGINE, 'job_id' => $r['job_id'], 'item_id' => $r['item_id'], 'generation' => $r['generation']);
        $r['phase'] = 'ready';
        $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        self::boundary('ready', $item);
        return $r;
    }

    public static function record($id) {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $id)) { return new WP_Error('PERMISSION_DENIED'); }
        $item = self::item($id); if (!$item) { return null; }
        if (!WP_Seed_Pixel_Job_Store::journal_valid($item)) { return new WP_Error('EVIDENCE_INVALID'); }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false); if (is_wp_error($dir)) { return $dir; }
        $record = WP_Seed_Pixel_Master_Storage::load($dir, $item);
        return is_wp_error($record) || !$record ? $record : array('item' => $item, 'record' => $record, 'directory' => $dir);
    }

    private static function rows($id) {
        global $wpdb;
        $rows = array();
        foreach (WP_Seed_Pixel_Master_Adapter::KEYS as $key) {
            $rows[$key] = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key));
            if (count($rows[$key]) > 1 || $wpdb->last_error) { return new WP_Error('METADATA_CONFLICT'); }
        }
        return $rows;
    }

    private static function desired(array $r, $restore) {
        $rows = $r['before']['rows'];
        if (!$restore) {
            $rows['_wp_attached_file'] = array($r['after']['file']);
            $rows['_wp_attachment_metadata'] = array(maybe_serialize($r['after']));
            $rows['_seed_pixel_master_state'] = array(maybe_serialize($r['witness']));
        }
        return $rows;
    }

    private static function observe(array $r) {
        $id = $r['before']['attachment_id']; $post = get_post($id); $rows = self::rows($id);
        if (!$post || $post->post_type !== 'attachment' || $post->post_status === 'trash' || $post->guid !== $r['before']['guid'] || is_wp_error($rows)) { return new WP_Error('SOURCE_CHANGED'); }
        if ($post->post_mime_type === 'image/png' && $rows === self::desired($r, true)) { return 'before'; }
        if ($post->post_mime_type === 'image/jpeg' && $rows === self::desired($r, false)) { return 'after'; }
        return new WP_Error('METADATA_CONFLICT');
    }

    private static function database(array $r, $restore) {
        global $wpdb;
        $id = $r['before']['attachment_id'];
        if (!WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        $desired = self::desired($r, $restore);
        if (apply_filters('wp_update_attachment_metadata', maybe_unserialize($desired['_wp_attachment_metadata'][0]), $id) !== maybe_unserialize($desired['_wp_attachment_metadata'][0])) { return new WP_Error('METADATA_CONFLICT'); }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
        try {
            $post = $wpdb->get_row($wpdb->prepare("SELECT guid,post_mime_type,post_type,post_status FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $id), ARRAY_A);
            if (!$post || $post['post_type'] !== 'attachment' || $post['post_status'] === 'trash' || $post['guid'] !== $r['before']['guid']) { throw new RuntimeException('SOURCE_CHANGED'); }
            $current = array(); $raw = array();
            foreach (WP_Seed_Pixel_Master_Adapter::KEYS as $key) {
                $raw[$key] = $wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s FOR UPDATE", $id, $key), ARRAY_A);
                $current[$key] = array_column($raw[$key], 'meta_value');
            }
            $valid = ($post['post_mime_type'] === 'image/png' && $current === self::desired($r, true))
                || ($post['post_mime_type'] === 'image/jpeg' && $current === self::desired($r, false));
            if (!$valid || !WP_Seed_Pixel_Authority::valid_all()) { throw new RuntimeException('METADATA_CONFLICT'); }
            $old = self::old_files($r, WP_Seed_Pixel_Master_Storage::directory(WP_Seed_Pixel_Job_Store::item($r['item_id']), false));
            if (is_wp_error($old)) { throw new RuntimeException($old->get_error_code()); }
            if (!$restore) {
                $new = self::new_files($r, WP_Seed_Pixel_Master_Storage::directory(WP_Seed_Pixel_Job_Store::item($r['item_id']), false));
                if (is_wp_error($new)) { throw new RuntimeException($new->get_error_code()); }
            }
            foreach ($desired as $key => $values) {
                if ($current[$key] === $values) { continue; }
                if (!$values) { $ok = $wpdb->delete($wpdb->postmeta, array('meta_id' => $raw[$key][0]['meta_id'])); }
                elseif (!$raw[$key]) { $ok = $wpdb->insert($wpdb->postmeta, array('post_id' => $id, 'meta_key' => $key, 'meta_value' => $values[0])); }
                else { $ok = $wpdb->update($wpdb->postmeta, array('meta_value' => $values[0]), array('meta_id' => $raw[$key][0]['meta_id'])); }
                if ($ok !== 1) { throw new RuntimeException('STORE_FAILED'); }
            }
            $mime = $restore ? 'image/png' : 'image/jpeg';
            if ($post['post_mime_type'] !== $mime && $wpdb->update($wpdb->posts, array('post_mime_type' => $mime), array('ID' => $id, 'post_mime_type' => $post['post_mime_type'])) !== 1) { throw new RuntimeException('STORE_FAILED'); }
            if (!WP_Seed_Pixel_Authority::valid_all() || $wpdb->query('COMMIT') === false) { throw new RuntimeException('STORE_FAILED'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return new WP_Error($e->getMessage()); }
        wp_cache_delete($id, 'post_meta'); clean_post_cache($id);
        return self::observe($r);
    }

    private static function old_files(array $r, $dir, $backup = true, $allow_purged = false, $allow_backup_missing = false) {
        foreach ($r['before']['files'] as $relative => $expected) {
            $path = self::absolute($relative, false); if (is_wp_error($path)) { return $path; }
            if (!$allow_purged || file_exists($path)) { if (!self::identity($path, array_merge($expected, array('identity' => $r['original_identity'][$relative], 'attributes' => $r['original_attributes'][$relative])))) { return new WP_Error('SOURCE_CHANGED'); } }
            $private = $dir . '/' . $r['backup'][$relative];
            if ($backup && ((!$allow_purged && !$allow_backup_missing) || file_exists($private)) && !self::identity($private, array_merge($expected, array('identity' => $r['backup_identity'][$relative])))) { return new WP_Error('BACKUP_FAILED'); }
        }
        return true;
    }

    private static function new_files(array $r, $dir, $publish = false) {
        foreach ($r['new_files'] as $relative => $expected) {
            $private = $dir . '/' . $expected['private']; $public = self::absolute($relative, false);
            $public_expected = array_merge($expected, array('attributes' => array('mode' => $r['before']['mode'], 'uid' => $r['before']['uid'], 'gid' => $r['before']['gid'])));
            if (is_wp_error($public) || ($publish && !WP_Seed_Pixel_Authority::valid_all())) { return new WP_Error('LOCKED'); }
            if ($publish) {
                // link() is no-clobber and atomic on the certified same-device filesystem.
                if (!file_exists($public)) {
                    if (!self::identity($private, $expected) || !link($private, $public)) { return new WP_Error('SWAP_FAILED'); }
                    if (!chmod($public, $r['before']['mode']) || (filegroup($public) !== $r['before']['gid'] && !chgrp($public, $r['before']['gid']))) { return new WP_Error('SWAP_FAILED'); }
                    if (!WP_Seed_Pixel_Master_Storage::sync_directory(dirname($public))) { return new WP_Error('SWAP_FAILED'); }
                }
                $s = @lstat($public); $p = @lstat($private);
                if (!$s || !$p || $s['dev'] !== $p['dev'] || $s['ino'] !== $p['ino'] || !self::identity($public, $public_expected, 2)) { return new WP_Error('EVIDENCE_INVALID'); }
            } else {
                $links = file_exists($private) ? 2 : 1;
                if (!self::identity($public, $public_expected, $links)) { return new WP_Error('SOURCE_CHANGED'); }
                if ($links === 2 && (stat($private)['ino'] !== stat($public)['ino'] || is_link($private))) { return new WP_Error('EVIDENCE_INVALID'); }
            }
        }
        return true;
    }

    public static function select_profile($id, $generation, $profile) {
        return self::owned($id, static function () use ($id, $generation, $profile) {
            $b = self::record($id); if (!is_array($b)) { return new WP_Error('JOB_REQUIRED'); }
            $r = $b['record']; $dir = $b['directory'];
            if (!hash_equals($r['generation'], $generation) || $r['phase'] !== 'ready'
                || !isset($r['profiles'][$profile]) || !WP_Seed_Pixel_Format_Processor::selectable($r['profiles'][$profile], $r['before']['bytes'])) { return new WP_Error('INVALID_PROFILE'); }
            $item = self::claim($b['item']); if (is_wp_error($item)) { return $item; }
            if ($r['before'] !== WP_Seed_Pixel_Master_Adapter::snapshot($id)
                || ($r['candidate'] && !self::identity($dir . '/new-master.jpg', $r['candidate']))) { return new WP_Error('SOURCE_CHANGED'); }
            if ($r['selected_profile'] === $profile) { return self::view($item, $r, $dir); }
            $r['selected_profile'] = $profile;
            $r['candidate'] = null;
            $r['phase'] = 'preparing';
            unset($r['approved']);
            $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            self::boundary('profile_selection', $item);
            $prepared = self::prepare($item, $r, $dir); if (is_wp_error($prepared)) { return $prepared; }
            return self::view($item, $prepared, $dir);
        });
    }

    public static function convert($id, $generation, $confirmed = false, $provenance = false, $profile = null, $quality_override = false) {
        return self::owned($id, static function () use ($id, $generation, $confirmed, $provenance, $profile, $quality_override) {
            $bundle = self::record($id); if (is_wp_error($bundle) || !$bundle) { return new WP_Error('JOB_REQUIRED'); }
            $item = self::claim($bundle['item']); if (is_wp_error($item)) { return $item; }
            $r = $bundle['record']; $dir = $bundle['directory'];
            if ($profile !== null && $profile !== ($r['selected_profile'] ?? null)) { return new WP_Error('INVALID_PROFILE'); }
            if (!$r['candidate']) { return new WP_Error('INVALID_PROFILE'); }
            if (!hash_equals($r['generation'], $generation) || $confirmed !== true || ($r['candidate']['provenance_lost'] && $provenance !== true)) { return new WP_Error('CONFIRMATION_REQUIRED'); }
            $quality_passed = WP_Seed_Pixel_Format_Processor::quality_passed($r['candidate']);
            if (!$quality_passed && ($r['phase'] === 'ready' ? $quality_override !== true : empty($r['approved']['quality_override']))) { return new WP_Error('QUALITY_CONFIRMATION_REQUIRED'); }
            if ($r['phase'] === 'retained') {
                if (self::observe($r) !== 'after' || is_wp_error(self::old_files($r, $dir)) || is_wp_error(self::new_files($r, $dir))) { return new WP_Error('SOURCE_CHANGED'); }
                if ($item['stage'] === 'verified') { $item = self::advance($item, 'retained'); if (is_wp_error($item)) { return $item; } }
                self::complete($item);
                return self::view($item, $r, $dir);
            }
            if (!in_array($r['phase'], array('ready', 'publish_intent', 'switched'), true)) { return new WP_Error('INVALID_STATE'); }
            $old = self::old_files($r, $dir); if (is_wp_error($old)) { return $old; }
            $observed = self::observe($r); if (is_wp_error($observed)) { return $observed; }
            if ($r['phase'] === 'ready') {
                if ($observed !== 'before') { return new WP_Error('SOURCE_CHANGED'); }
                foreach ($r['profiles'] ?? array() as $key => $expected) {
                    if (!self::identity($dir . '/profile-' . $key . '.jpg', $expected)) { return new WP_Error('EVIDENCE_INVALID'); }
                }
                $r['approved'] = array('by' => get_current_user_id(), 'at' => time(), 'provenance' => $provenance,
                    'profile' => $r['selected_profile'] ?? null, 'quality' => $r['candidate']['quality'],
                    'quality_passed' => $quality_passed, 'quality_override' => !$quality_passed && $quality_override === true);
                $r['phase'] = 'publish_intent';
                $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
                self::boundary('publish_intent', $item);
            }
            if ($item['stage'] === 'ready') { $item = self::advance($item, 'switch_intent'); if (is_wp_error($item)) { return $item; } }
            if ($observed === 'before') {
                $admission = WP_Seed_Pixel_Storage_Budget::admit($r['peak_bytes'], $dir); if (is_wp_error($admission)) { return $admission; }
                $published = self::new_files($r, $dir, true); if (is_wp_error($published)) { return $published; }
                self::boundary('files_published', $item);
                $old = self::old_files($r, $dir); if (is_wp_error($old)) { return $old; }
                $switched = self::database($r, false); if (is_wp_error($switched) || $switched !== 'after') { return is_wp_error($switched) ? $switched : new WP_Error('VERIFY_FAILED'); }
                self::boundary('database_switched', $item);
            }
            $healthy = self::new_files($r, $dir); if (is_wp_error($healthy)) { return $healthy; }
            $r['phase'] = 'switched'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            foreach (array('switched', 'verified') as $stage) {
                if ($item['stage'] === ($stage === 'switched' ? 'switch_intent' : 'switched')) { $item = self::advance($item, $stage); if (is_wp_error($item)) { return $item; } }
            }
            foreach ($r['new_files'] as $relative => $file) {
                $private = $dir . '/' . $file['private'];
                if (file_exists($private)) {
                    $public = self::absolute($relative);
                    if (is_wp_error($public) || !self::identity($private, $file, 2) || stat($private)['ino'] !== stat($public)['ino'] || !WP_Seed_Pixel_Authority::valid_all() || !unlink($private) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('EVIDENCE_INVALID'); }
                }
            }
            $clean = self::cleanup_profiles($item, $r, $dir); if (is_wp_error($clean)) { return $clean; }
            $r['phase'] = 'retained'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            $item = self::advance($item, 'retained'); if (is_wp_error($item)) { return $item; }
            self::complete($item);
            self::boundary('retained', $item);
            return self::view($item, $r, $dir);
        });
    }

    public static function resume($id, $generation) {
        $b = self::record($id);
        if (!is_array($b) || !hash_equals($b['record']['generation'], $generation) || empty($b['record']['approved'])
            || !in_array($b['record']['phase'], array('publish_intent', 'switched', 'retained'), true)) { return new WP_Error('CONFIRMATION_REQUIRED'); }
        return self::convert($id, $generation, true, $b['record']['approved']['provenance'] === true);
    }

    public static function restore($id, $generation) {
        return self::owned($id, static function () use ($id, $generation) {
            $bundle = self::record($id); if (is_wp_error($bundle) || !$bundle) { return new WP_Error('JOB_REQUIRED'); }
            $item = self::claim($bundle['item']); if (is_wp_error($item)) { return $item; }
            $r = $bundle['record']; $dir = $bundle['directory'];
            if (!hash_equals($r['generation'], $generation) || !in_array($r['phase'], array('retained', 'restore_intent', 'restored'), true)) { return new WP_Error('INVALID_STATE'); }
            if ($r['phase'] === 'restored') {
                if (self::observe($r) !== 'before' || is_wp_error(self::old_files($r, $dir, false))) { return new WP_Error('SOURCE_CHANGED'); }
                if ($item['stage'] === 'recovery_required') { $item = self::advance($item, 'rolled_back'); if (is_wp_error($item)) { return $item; } }
                self::complete($item);
                return self::view($item, $r, $dir);
            }
            $old = self::old_files($r, $dir, true, false, $r['phase'] === 'restore_intent' && self::observe($r) === 'before'); if (is_wp_error($old)) { return $old; }
            if ($r['phase'] === 'retained') {
                $healthy = self::new_files($r, $dir); if (is_wp_error($healthy)) { return $healthy; }
                $r['phase'] = 'restore_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            }
            if ($item['stage'] === 'retained') { $item = self::advance($item, 'recovery_required'); if (is_wp_error($item)) { return $item; } }
            $observed = self::observe($r); if (is_wp_error($observed)) { return $observed; }
            if ($observed === 'after') { $back = self::database($r, true); if (is_wp_error($back) || $back !== 'before') { return new WP_Error('VERIFY_FAILED'); } }
            self::boundary('restore_database', $item);
            foreach ($r['new_files'] as $relative => $expected) {
                $path = self::absolute($relative, false); if (is_wp_error($path)) { return $path; }
                if (file_exists($path)) {
                    if (!self::identity($path, $expected) || !WP_Seed_Pixel_Authority::valid_all() || !unlink($path) || !WP_Seed_Pixel_Master_Storage::sync_directory(dirname($path))) { return new WP_Error('EVIDENCE_INVALID'); }
                }
            }
            $clean = self::cleanup_private($item, $r, $dir); if (is_wp_error($clean)) { return $clean; }
            $r['phase'] = 'restored'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            $item = self::advance($item, 'rolled_back'); if (is_wp_error($item)) { return $item; }
            self::complete($item);
            return self::view($item, $r, $dir);
        });
    }

    public static function purge($id, $generation, $confirmed = false, $urls = false) {
        return self::owned($id, static function () use ($id, $generation, $confirmed, $urls) {
            $bundle = self::record($id); if (is_wp_error($bundle) || !$bundle) { return new WP_Error('JOB_REQUIRED'); }
            $item = self::claim($bundle['item']); if (is_wp_error($item)) { return $item; }
            $r = $bundle['record']; $dir = $bundle['directory'];
            if (!hash_equals($r['generation'], $generation) || $confirmed !== true || $urls !== true || !in_array($r['phase'], array('retained', 'purge_intent', 'purged'), true)) { return new WP_Error('CONFIRMATION_REQUIRED'); }
            if ($r['phase'] === 'purged') {
                if (self::observe($r) !== 'after' || is_wp_error(self::new_files($r, $dir))) { return new WP_Error('SOURCE_CHANGED'); }
                foreach ($r['before']['files'] as $relative => $expected) {
                    $p = self::absolute($relative, false); $backup = $dir . '/' . $r['backup'][$relative];
                    if (is_wp_error($p) || file_exists($p) || is_link($p) || file_exists($backup) || is_link($backup)) { return new WP_Error('EVIDENCE_INVALID'); }
                }
                if ($item['stage'] === 'purge_intent') { $item = self::advance($item, 'purged'); if (is_wp_error($item)) { return $item; } }
                self::complete($item);
                return self::view($item, $r, $dir);
            }
            if (self::observe($r) !== 'after') { return new WP_Error('SOURCE_CHANGED'); }
            $healthy = self::new_files($r, $dir); if (is_wp_error($healthy)) { return $healthy; }
            $references = self::references($r['before']); if (is_wp_error($references)) { return $references; }
            if (array_filter($references)) { return new WP_Error('OLD_URL_REFERENCED'); }
            $old = self::old_files($r, $dir, true, $r['phase'] === 'purge_intent'); if (is_wp_error($old)) { return $old; }
            $r['phase'] = 'purge_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            if ($item['stage'] === 'retained') { $item = self::advance($item, 'purge_intent'); if (is_wp_error($item)) { return $item; } }
            self::boundary('purge_intent', $item);
            foreach ($r['before']['files'] as $relative => $expected) {
                $paths = array(array(self::absolute($relative, false), $r['original_identity'][$relative]), array($dir . '/' . $r['backup'][$relative], $r['backup_identity'][$relative]));
                foreach ($paths as $slot) {
                    list($path, $identity) = $slot;
                    if (is_wp_error($path)) { return $path; }
                    if (file_exists($path)) {
                        $item = self::renew($item); if (is_wp_error($item)) { return $item; }
                        if (!self::identity($path, array_merge($expected, array('identity' => $identity))) || self::observe($r) !== 'after' || !WP_Seed_Pixel_Authority::valid_all() || !unlink($path) || !WP_Seed_Pixel_Master_Storage::sync_directory(dirname($path))) { return new WP_Error('PURGE_FAILED'); }
                        self::boundary('purge_file', $item);
                    }
                }
            }
            $r['phase'] = 'purged'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
            $item = self::advance($item, 'purged'); if (is_wp_error($item)) { return $item; }
            self::complete($item);
            return self::view($item, $r, $dir);
        });
    }

    public static function discard($id, $generation) {
        return self::owned($id, static function () use ($id, $generation) {
            $b = self::record($id); if (!is_array($b) || !hash_equals($b['record']['generation'], $generation)) { return new WP_Error('INVALID_STATE'); }
            $item = self::claim($b['item']); if (is_wp_error($item)) { return $item; }
            return self::discard_owned($item, $b['record'], $b['directory']);
        });
    }

    private static function discard_owned(array $item, array $r, $dir) {
        if (!in_array($r['phase'], array('preparing', 'ready', 'discard_intent', 'discarded'), true)) { return new WP_Error('INVALID_STATE'); }
        $fresh = WP_Seed_Pixel_Master_Adapter::snapshot($r['before']['attachment_id']);
        if (is_wp_error($fresh) || $fresh !== $r['before']) { return new WP_Error('SOURCE_CHANGED'); }
        $r['phase'] = 'discard_intent'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        $clean = self::cleanup_private($item, $r, $dir); if (is_wp_error($clean)) { return $clean; }
        $r['phase'] = 'discarded'; $r = WP_Seed_Pixel_Master_Storage::save($dir, $r); if (is_wp_error($r)) { return $r; }
        if (!in_array($item['stage'], array('skipped', 'failed'), true)) { $item = WP_Seed_Pixel_Job_Store::transition($item, $item['lease'], 'failed', 'ANALYSIS_DISCARDED'); if (is_wp_error($item)) { return $item; } }
        self::complete($item);
        return self::view($item, $r, $dir);
    }

    private static function cleanup_private(array $item, array $r, $dir) {
        $slots = array();
        foreach ($r['backup'] as $relative => $name) { $slots[$name] = array_merge($r['before']['files'][$relative], array('identity' => $r['backup_identity'][$relative])); }
        foreach ($r['new_files'] as $file) { $slots[$file['private']] = $file; }
        if ($r['candidate']) { $slots['new-master.jpg'] = $r['candidate']; }
        elseif (isset($r['encoding_slot'])) { $slots['new-master.jpg'] = array('identity' => $r['encoding_slot'], 'unverified' => true); }
        foreach ($r['derivative_slots'] ?? array() as $name => $identity) { if (!isset($slots[$name])) { $slots[$name] = array('identity' => $identity, 'unverified' => true); } }
        foreach ($r['profile_slots'] ?? array() as $key => $identity) {
            $slots['profile-' . $key . '.jpg'] = $r['profiles'][$key] ?? array('identity' => $identity, 'unverified' => true);
        }
        foreach (scandir($dir) as $name) { if (!in_array($name, array('.', '..', 'journal.json'), true) && !isset($slots[$name])) { return new WP_Error('EVIDENCE_INVALID'); } }
        foreach ($slots as $name => $expected) {
            $path = $dir . '/' . $name;
            if (!file_exists($path) && !is_link($path)) { continue; }
            $item = self::renew($item); if (is_wp_error($item)) { return $item; }
            if (!empty($expected['unverified'])) {
                $s = lstat($path); $identity = $expected['identity'];
                $valid = !is_link($path) && is_file($path) && $s['nlink'] === 1 && $s['uid'] === fileowner($dir) && $s['dev'] === $identity['dev'] && $s['ino'] === $identity['ino'];
            } else { $valid = self::identity($path, $expected); }
            if (!$valid || !WP_Seed_Pixel_Authority::valid_all() || !unlink($path) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('EVIDENCE_INVALID'); }
        }
        return true;
    }

    private static function cleanup_profiles(array $item, array $r, $dir) {
        foreach ($r['profiles'] ?? array() as $key => $expected) {
            $path = $dir . '/profile-' . $key . '.jpg';
            if (!file_exists($path) && !is_link($path)) { continue; }
            $item = self::renew($item); if (is_wp_error($item)) { return $item; }
            if (!self::identity($path, $expected) || !WP_Seed_Pixel_Authority::valid_all() || !unlink($path)
                || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return new WP_Error('EVIDENCE_INVALID'); }
            self::boundary('profile_cleanup', $item);
        }
        return true;
    }

    private static function complete(array $item) {
        global $wpdb;
        $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('status' => 'completed', 'updated' => time(), 'lease' => '', 'lease_until' => 0), array('id' => $item['job_id'], 'lease' => $item['lease']));
    }

    public static function view(array $item, array $r, $dir) {
        $old = array_sum(array_column($r['before']['files'], 'bytes'));
        $new = array_sum(array_column($r['new_files'], 'bytes'));
        $recovery = $compatibility = $temporary = 0;
        foreach ($r['before']['files'] as $relative => $expected) {
            $path = self::absolute($relative, false);
            if (!is_wp_error($path) && is_file($path)) { $compatibility += filesize($path); }
            if (isset($r['backup'][$relative]) && is_file($dir . '/' . $r['backup'][$relative])) { $recovery += filesize($dir . '/' . $r['backup'][$relative]); }
        }
        foreach ($r['new_files'] as $file) {
            $path = $dir . '/' . $file['private'];
            if (is_file($path) && lstat($path)['nlink'] === 1) { $temporary += filesize($path); }
        }
        foreach ($r['profiles'] ?? array() as $key => $profile) {
            $path = $dir . '/profile-' . $key . '.jpg';
            if (is_file($path)) { $temporary += filesize($path); }
        }
        $after = in_array($r['phase'], array('retained', 'purge_intent', 'purged'), true);
        $audit = filesize($dir . '/journal.json');
        $footprint = $compatibility + $recovery + $temporary + ($after ? $new : 0) + $audit;
        return array('item_id' => (int) $item['id'], 'job_id' => (int) $item['job_id'], 'phase' => $r['phase'], 'generation' => $r['generation'],
            'candidate' => $r['candidate'], 'profiles' => $r['profiles'] ?? array(), 'selected_profile' => $r['selected_profile'] ?? null,
            'original_bytes' => $r['before']['bytes'], 'old_graph_bytes' => $old, 'new_graph_bytes' => $new,
            'active_saving_bytes' => $after ? $old - $new : 0, 'recovery_bytes' => $recovery, 'compatibility_bytes' => $compatibility,
            'temporary_bytes' => $temporary, 'audit_bytes' => $audit, 'added_bytes' => max(0, $footprint - $old),
            'freed_bytes' => max(0, $old - $footprint), 'peak_reserved_bytes' => $r['peak_bytes'],
            'restore_available' => $r['phase'] === 'retained' && self::observe($r) === 'after' && !is_wp_error(self::old_files($r, $dir)) && !is_wp_error(self::new_files($r, $dir, false)),
            'references' => $r['references'], 'physical_quota_saving_measured' => false);
    }
}
