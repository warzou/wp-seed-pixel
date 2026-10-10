<?php
defined('ABSPATH') || exit;

/** Native WordPress state, identity and narrowly scoped metadata CAS. */
final class WP_Seed_Pixel_Master_Adapter {
    const KEYS = array('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_seed_pixel_manifest', '_seed_pixel_history', '_seed_pixel_master_state');

    private static function transactional() {
        global $wpdb;
        foreach (array($wpdb->postmeta, WP_Seed_Pixel_Job_Store::table('jobs'), WP_Seed_Pixel_Job_Store::table('items')) as $table) {
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table), ARRAY_A);
            if (!$row || strtoupper($row['Engine']) !== 'INNODB') { return false; }
        }
        return true;
    }

    public static function snapshot($id, $transitional = false, $metadata_graph = false) {
        global $wpdb;
        if (!self::transactional()) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment' || !in_array($post->post_mime_type, array('image/jpeg', 'image/png'), true) || $post->post_status === 'trash'
            || !current_user_can('manage_options') || !current_user_can('edit_post', $id) || is_multisite()) { return new WP_Error('PERMISSION_DENIED'); }
        $path = get_attached_file($id, true); $safe = WP_Seed_Pixel_Files::path($path);
        if (is_wp_error($safe) || get_attached_file($id) !== $path) { return new WP_Error('UNSUPPORTED_STORAGE'); }
        $a = WP_Seed_Pixel_Analyzer::analyze($id);
        if (is_wp_error($a)) { return new WP_Error('NEEDS_REVIEW'); }
        if (!$a['extra_inventory_complete']) { return new WP_Error('INVENTORY_INCOMPLETE'); }
        if (!$transitional && $a['health'] !== 'healthy') { return new WP_Error('NEEDS_REVIEW'); }
        $rows = array();
        foreach (self::KEYS as $key) {
            $values = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key));
            if (count($values) > 1) { return new WP_Error('METADATA_CONFLICT'); }
            $rows[$key] = $values;
        }
        if (!$rows['_wp_attached_file'] || !$rows['_wp_attachment_metadata'] || $rows['_wp_attachment_backup_sizes']) { return new WP_Error('NEEDS_REVIEW'); }
        $meta = maybe_unserialize($rows['_wp_attachment_metadata'][0]); $info = @getimagesize($path);
        if (!is_array($meta) || !$info || $meta['file'] !== $rows['_wp_attached_file'][0] || (!$transitional && ($meta['width'] !== $info[0] || $meta['height'] !== $info[1]))) { return new WP_Error('METADATA_CONFLICT'); }
        $needle = '%' . $wpdb->esc_like(basename($path)) . '%';
        if ($wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id<>%d AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_seed_pixel_manifest','_seed_pixel_history') AND meta_value LIKE %s LIMIT 1", $id, $needle))) { return new WP_Error('SHARED_PATH'); }
        $files = array();
        foreach ($a['files'] as $file) {
            $p = WP_Seed_Pixel_Files::path(wp_upload_dir(null, false)['basedir'] . '/' . $file['relative_path']);
            if (is_wp_error($p) || $file['link_count'] > 1) { return new WP_Error('SHARED_PATH'); }
            $files[$file['relative_path']] = array('sha256' => hash_file('sha256', $p), 'bytes' => filesize($p), 'roles' => $file['roles']);
        }
        $manifest = maybe_unserialize($rows['_seed_pixel_manifest'][0] ?? '');
        foreach ((array) ($manifest['files'] ?? array()) as $f) {
            if (!$metadata_graph && ($f['kind'] ?? '') === 'master' && (($f['path'] ?? '') === $path || ($f['relative'] ?? '') === $meta['file'])) { return new WP_Error('NEEDS_REVIEW'); }
        }
        $snapshot = array('attachment_id' => (int) $id, 'relative' => $meta['file'], 'url' => wp_get_attachment_url($id), 'mime' => $post->post_mime_type,
            'guid' => $post->guid, 'rows' => $rows, 'files' => $files, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path),
            'width' => $info[0], 'height' => $info[1], 'mode' => fileperms($path) & 0777, 'uid' => fileowner($path), 'gid' => filegroup($path));
        if ($metadata_graph && !$transitional) {
            $references = self::graph_reference_rows($snapshot);
            if (is_wp_error($references)) { return $references; }
        }
        return $snapshot;
    }

    /** Derive only integrity witnesses; paths, roles and every other field stay unchanged. */
    public static function graph_reference_rows(array $before, array $plan = array()) {
        $result = array(); $root = rtrim(wp_upload_dir(null, false)['basedir'], '/\\') . '/';
        foreach (array('_seed_pixel_manifest', '_seed_pixel_history') as $key) {
            $rows = $before['rows'][$key]; $result[$key] = $rows;
            if (!$rows) { continue; }
            $value = maybe_unserialize($rows[0]);
            if (!is_array($value)) { return new WP_Error('METADATA_CONFLICT'); }
            $manifests = $key === '_seed_pixel_manifest' ? array($value) : $value;
            foreach ($manifests as &$manifest) {
                if (!is_array($manifest) || (int) ($manifest['attachment_id'] ?? 0) !== $before['attachment_id'] || !is_array($manifest['files'] ?? null)) { return new WP_Error('METADATA_CONFLICT'); }
                foreach ($manifest['files'] as &$resource) {
                    if (!is_array($resource)) { return new WP_Error('METADATA_CONFLICT'); }
                    $path = $resource['path'] ?? (isset($resource['relative']) ? $root . $resource['relative'] : null);
                    if (!is_string($path) || strpos($path, $root) !== 0) { return new WP_Error('METADATA_CONFLICT'); }
                    $relative = substr($path, strlen($root)); $file = $before['files'][$relative] ?? null;
                    if (!$file || !in_array($key === '_seed_pixel_manifest' ? 'pixel_current' : 'pixel_history', $file['roles'], true)
                        || ($resource['sha256'] ?? null) !== $file['sha256'] || ($resource['bytes'] ?? null) !== $file['bytes']) { return new WP_Error('METADATA_CONFLICT'); }
                    $image = @getimagesize($path);
                    if (!$image || ($resource['width'] ?? null) !== $image[0] || ($resource['height'] ?? null) !== $image[1]
                        || (isset($resource['relative']) && $resource['relative'] !== $relative)) { return new WP_Error('METADATA_CONFLICT'); }
                    if ($key === '_seed_pixel_manifest' && ($resource['kind'] ?? '') === 'master' && ($relative !== $before['relative'] || ($resource['width'] ?? null) !== $before['width'] || ($resource['height'] ?? null) !== $before['height'])) { return new WP_Error('METADATA_CONFLICT'); }
                    if ($plan) {
                        $entry = $plan['files'][$relative] ?? null;
                        if (!$entry || $entry['before_sha256'] !== $file['sha256'] || $entry['before_bytes'] !== $file['bytes'] || $entry['roles'] !== $file['roles']) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
                        $resource['sha256'] = $entry['after_sha256']; $resource['bytes'] = $entry['after_bytes'];
                    }
                }
                unset($resource);
                // Historical generation source hashes are not necessarily current master witnesses.
                if (($manifest['master_sha256'] ?? null) === $before['sha256']) {
                    if (($manifest['master_bytes'] ?? null) !== $before['bytes']) { return new WP_Error('METADATA_CONFLICT'); }
                    if ($plan) {
                        $manifest['master_sha256'] = $plan['files'][$before['relative']]['after_sha256'];
                        $manifest['master_bytes'] = $plan['files'][$before['relative']]['after_bytes'];
                    }
                } elseif ($key === '_seed_pixel_manifest') { return new WP_Error('METADATA_CONFLICT'); }
            }
            unset($manifest);
            if ($plan) { $result[$key] = array(maybe_serialize($key === '_seed_pixel_manifest' ? $manifests[0] : $manifests)); }
        }
        return $result;
    }

    public static function path(array $snapshot) {
        return WP_Seed_Pixel_Files::path(wp_upload_dir(null, false)['basedir'] . '/' . $snapshot['relative']);
    }

    public static function metadata(array $snapshot, array $candidate) {
        $meta = maybe_unserialize($snapshot['rows']['_wp_attachment_metadata'][0]);
        foreach ((array) ($meta['sizes'] ?? array()) as $size) {
            if ($size['width'] > $candidate['width'] || $size['height'] > $candidate['height']) { return new WP_Error('DIMENSION_CONFLICT'); }
        }
        $meta['width'] = $candidate['width']; $meta['height'] = $candidate['height']; $meta['filesize'] = $candidate['bytes'];
        return $meta;
    }

    public static function observe(array $record) {
        $before = $record['before']; $path = self::path($before);
        if (is_wp_error($path)) { return new WP_Error('SOURCE_MISSING'); }
        $fresh = self::snapshot($before['attachment_id'], true, isset($record['graph_version']));
        $references = isset($record['graph_version']) ? self::graph_reference_rows($before, $record['graph_plan']) : array();
        if (is_wp_error($references)) { return $references; }
        // A valid replacement changes only this native row and the owned master witness.
        if (is_wp_error($fresh)) { return $fresh; }
        $hash = $fresh['sha256'];
        if ($hash !== $before['sha256'] && $hash !== ($record['candidate']['sha256'] ?? '')) { return new WP_Error('SOURCE_CHANGED'); }
        foreach ($before['rows'] as $key => $rows) {
            if ($key === '_wp_attachment_metadata' || $key === '_seed_pixel_master_state') { continue; }
            if (isset($references[$key]) && $fresh['rows'][$key] === $references[$key]) { continue; }
            if ($fresh['rows'][$key] !== $rows) { return new WP_Error('METADATA_CONFLICT'); }
        }
        if ($fresh['url'] !== $before['url'] || $fresh['guid'] !== $before['guid']) { return new WP_Error('METADATA_CONFLICT'); }
        if ($fresh['mode'] !== $before['mode'] || $fresh['uid'] !== $before['uid'] || $fresh['gid'] !== $before['gid']) { return new WP_Error('METADATA_CONFLICT'); }
        foreach ($before['files'] as $relative => $file) {
            if ($relative === ($record['retired_original']['relative'] ?? '') && !isset($fresh['files'][$relative])) { continue; }
            if (isset($record['graph_version'])) {
                $e = $record['graph_plan']['files'][$relative] ?? null; $f = $fresh['files'][$relative] ?? null;
                if (!$e || !$f || $f['roles'] !== $e['roles']
                    || !in_array(array($f['sha256'], $f['bytes']), array(array($e['before_sha256'], $e['before_bytes']), array($e['after_sha256'], $e['after_bytes'])), true)) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
            } elseif ($relative !== $before['relative'] && ($fresh['files'][$relative]['sha256'] ?? '') !== $file['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
        }
        if (isset($record['graph_version']) && array_keys($fresh['files']) !== array_keys($before['files'])) { return new WP_Error('METADATA_GRAPH_CHANGED'); }
        $native = $fresh['rows']['_wp_attachment_metadata'];
        $after = isset($record['after']) ? array(maybe_serialize($record['after'])) : array();
        if ($native !== $before['rows']['_wp_attachment_metadata'] && $native !== $after) { return new WP_Error('METADATA_CONFLICT'); }
        $witness = $fresh['rows']['_seed_pixel_master_state'];
        if ($witness !== $before['rows']['_seed_pixel_master_state'] && $witness !== array(maybe_serialize($record['witness'] ?? array()))) { return new WP_Error('METADATA_CONFLICT'); }
        $references_after = true; $references_before = true;
        foreach ($references as $key => $rows) {
            $references_after = $references_after && $fresh['rows'][$key] === $rows;
            $references_before = $references_before && $fresh['rows'][$key] === $before['rows'][$key];
        }
        return array('hash' => $hash, 'meta_after' => $native === $after, 'witness_after' => $witness === array(maybe_serialize($record['witness'] ?? array())),
            'meta_before' => $native === $before['rows']['_wp_attachment_metadata'], 'witness_before' => $witness === $before['rows']['_seed_pixel_master_state'],
            'references_after' => $references_after, 'references_before' => $references_before);
    }

    public static function reconcile(array $record, $restore = false) {
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $record['before']['attachment_id'])) { return new WP_Error('LOCKED'); }
        global $wpdb;
        $before = $record['before']; $id = $before['attachment_id'];
        $observed = self::observe($record); if (is_wp_error($observed)) { return $observed; }
        $desired_hash = $restore ? $before['sha256'] : $record['candidate']['sha256'];
        if ($observed['hash'] !== $desired_hash) { return new WP_Error('SOURCE_CHANGED'); }
        $desired = $restore ? maybe_unserialize($before['rows']['_wp_attachment_metadata'][0]) : $record['after'];
        if (apply_filters('wp_update_attachment_metadata', $desired, $id) !== $desired) { return new WP_Error('METADATA_CONFLICT'); }
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid($id)) { return new WP_Error('LOCKED'); }
        $desired_rows = $before['rows'];
        $references = isset($record['graph_version']) ? self::graph_reference_rows($before, $record['graph_plan']) : array();
        if (is_wp_error($references)) { return $references; }
        if (!$restore) { $desired_rows = array_replace($desired_rows, $references); }
        $desired_rows['_wp_attachment_metadata'] = array(maybe_serialize($desired));
        if (!$restore) { $desired_rows['_seed_pixel_master_state'] = array(maybe_serialize($record['witness'])); }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
        try {
            foreach (self::KEYS as $key) {
                $current = $wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s FOR UPDATE", $id, $key), ARRAY_A);
                $old = array_column($current, 'meta_value');
                if ($key !== '_wp_attachment_metadata' && $key !== '_seed_pixel_master_state' && !isset($references[$key])) {
                    if ($old !== $before['rows'][$key]) { throw new RuntimeException('METADATA_CONFLICT'); }
                    continue;
                }
                $allowed = array($before['rows'][$key], $references[$key] ?? ($key === '_wp_attachment_metadata' ? array(maybe_serialize($record['after'])) : array(maybe_serialize($record['witness']))));
                if (!in_array($old, $allowed, true)) { throw new RuntimeException('METADATA_CONFLICT'); }
                if ($old === $desired_rows[$key]) { continue; }
                if (!$desired_rows[$key]) { $ok = $wpdb->delete($wpdb->postmeta, array('meta_id' => $current[0]['meta_id'])); }
                elseif (!$current) { $ok = $wpdb->insert($wpdb->postmeta, array('post_id' => $id, 'meta_key' => $key, 'meta_value' => $desired_rows[$key][0])); }
                else { $ok = $wpdb->update($wpdb->postmeta, array('meta_value' => $desired_rows[$key][0]), array('meta_id' => $current[0]['meta_id'], 'meta_value' => $old[0])); }
                if ($ok !== 1) { throw new RuntimeException('STORE_FAILED'); }
            }
            $path = self::path($before);
            if (is_wp_error($path) || hash_file('sha256', $path) !== $desired_hash) { throw new RuntimeException('SOURCE_CHANGED'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('STORE_FAILED'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return new WP_Error($e->getMessage()); }
        wp_cache_delete($id, 'post_meta'); clean_post_cache($id);
        return self::observe($record);
    }
}
