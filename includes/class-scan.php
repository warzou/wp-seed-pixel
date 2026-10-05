<?php
defined('ABSPATH') || exit;

/** Only these scan tables are writable. Image-processing state is not used. */
final class WP_Seed_Pixel_Scan {
    const SCHEMA = 1;

    private static function table($suffix) { global $wpdb; return $wpdb->prefix . 'seed_pixel_' . $suffix; }

    public static function install() {
        global $wpdb;
        if ((int) get_option('wp_seed_pixel_scan_schema') === self::SCHEMA) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE ' . self::table('jobs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            scan_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            ceiling bigint(20) unsigned NOT NULL DEFAULT 0,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            actor bigint(20) unsigned NOT NULL,
            lease varchar(64) NOT NULL DEFAULT '',
            lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
            created bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");
        dbDelta('CREATE TABLE ' . self::table('items') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            kind varchar(20) NOT NULL,
            item_key varchar(64) NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            stage varchar(20) NOT NULL,
            bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            role varchar(30) NOT NULL DEFAULT '',
            owners bigint(20) unsigned NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY item_identity (job_id,kind,item_key),
            KEY job_kind (job_id,kind),
            KEY job_stage (job_id,kind,stage)
        ) $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table('jobs'))) !== self::table('jobs') || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table('items'))) !== self::table('items')) {
            return new WP_Error('pixel_scan_schema', __('Scan storage could not be prepared.', 'wp-seed-pixel'));
        }
        update_option('wp_seed_pixel_scan_schema', self::SCHEMA, false);
        return true;
    }

    public static function start() {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel')); }
        $installed = self::install(); if (is_wp_error($installed)) { return $installed; }
        $current = self::current();
        if ($current && in_array($current['status'], array('running', 'paused'), true)) { return new WP_Error('pixel_scan_active', __('Finish or cancel the current analysis first.', 'wp-seed-pixel')); }
        $ceiling = (int) $wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts WHERE post_type='attachment' AND post_status <> 'trash'");
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='attachment' AND post_status <> 'trash' AND ID <= %d", $ceiling));
        $ok = $wpdb->insert(self::table('jobs'), array('kind' => 'scan', 'status' => $total ? 'running' : 'complete', 'scan_cursor' => 0, 'ceiling' => $ceiling, 'total' => $total, 'actor' => get_current_user_id(), 'created' => time()));
        if (!$ok) { return new WP_Error('pixel_scan_store', __('Analysis state could not be saved.', 'wp-seed-pixel')); }
        return self::status((int) $wpdb->insert_id);
    }

    public static function current() {
        global $wpdb;
        if ((int) get_option('wp_seed_pixel_scan_schema') !== self::SCHEMA) { return null; }
        $id = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . self::table('jobs') . " WHERE kind='scan'");
        return $id ? self::status($id) : null;
    }

    private static function job($id) {
        global $wpdb;
        if ((int) get_option('wp_seed_pixel_scan_schema') !== self::SCHEMA) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('jobs') . " WHERE id=%d AND kind='scan'", $id), ARRAY_A);
    }

    public static function status($id) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel')); }
        $job = self::job($id); if (!$job) { return new WP_Error('pixel_scan_missing', __('Analysis not found.', 'wp-seed-pixel')); }
        $items = self::table('items');
        $counts = $wpdb->get_results($wpdb->prepare("SELECT stage,COUNT(*) AS count FROM $items WHERE job_id=%d AND kind='attachment' GROUP BY stage", $id), ARRAY_A);
        $health = array(); $done = 0;
        foreach ($counts as $row) { $health[$row['stage']] = (int) $row['count']; $done += (int) $row['count']; }
        $roles = array_fill_keys(WP_Seed_Pixel_Analyzer::ROLES, 0);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT role,SUM(bytes) AS bytes FROM $items WHERE job_id=%d AND kind='file' GROUP BY role", $id), ARRAY_A) as $row) { $roles[$row['role']] = (int) $row['bytes']; }
        $potential = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(bytes) FROM $items WHERE job_id=%d AND kind='file' AND role='preserved_original' AND owners=1 AND stage='potential'", $id));
        $uncertain = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(bytes) FROM $items WHERE job_id=%d AND kind='file' AND (role='unattributed' OR owners>1 OR stage='review')", $id));
        return array('id' => (int) $id, 'status' => $job['status'], 'created' => (int) $job['created'], 'done' => $done, 'total' => (int) $job['total'], 'health' => $health, 'storage' => array('unique_logical_bytes' => array_sum($roles), 'by_role' => $roles, 'potential_original_bytes' => $potential, 'uncertain_bytes' => $uncertain, 'reclaimed_bytes' => 0, 'allocated_bytes' => null, 'quota_bytes' => null), 'files' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE job_id=%d AND kind='file' AND stage<>'missing'", $id)), 'page_count' => max(1, (int) ceil($done / 20)), 'deep_analysis' => false);
    }

    public static function control($id, $status) {
        global $wpdb;
        if (!current_user_can('manage_options') || !in_array($status, array('paused', 'running', 'cancelled'), true)) { return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel')); }
        $job = self::job($id);
        if (!$job || !in_array($job['status'], array('running', 'paused'), true)) { return new WP_Error('pixel_scan_finished', __('This analysis has already finished.', 'wp-seed-pixel')); }
        $wpdb->update(self::table('jobs'), array('status' => $status), array('id' => $id));
        return self::status($id);
    }

    public static function step($id) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel')); }
        $jobs = self::table('jobs'); $items = self::table('items'); $token = bin2hex(random_bytes(16));
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $jobs SET lease=%s,lease_until=%d WHERE id=%d AND status='running' AND lease_until < %d", $token, time() + 60, $id, time()));
        if (!$claimed) { $job = self::job($id); return $job && $job['status'] !== 'running' ? self::status($id) : new WP_Error('pixel_scan_busy', __('Analysis is busy. Resume later.', 'wp-seed-pixel')); }
        try {
            $job = self::job($id);
            $next = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='attachment' AND post_status<>'trash' AND ID>%d AND ID<=%d ORDER BY ID LIMIT 1", $job['scan_cursor'], $job['ceiling']));
            if (!$next) { $wpdb->update($jobs, array('status' => 'complete'), array('id' => $id, 'lease' => $token)); return self::status($id); }
            $result = WP_Seed_Pixel_Analyzer::analyze($next);
            if (is_wp_error($result)) { $result = array('attachment_id' => $next, 'title' => '', 'health' => 'needs_review', 'issues' => array($result->get_error_code()), 'files' => array(), 'storage' => array(), 'opportunities' => array(), 'stale' => false); }
            if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('pixel_scan_transaction', __('Analysis state could not be saved.', 'wp-seed-pixel')); }
            try {
                foreach ($result['files'] as $file) {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='file' AND item_key=%s", $id, $file['identity']), ARRAY_A);
                    $record = $row ? json_decode($row['data'], true) : array('file' => $file, 'first_owner' => $next, 'last_owner' => 0, 'owner_count' => 0);
                    if (!is_array($record)) { throw new RuntimeException('Invalid owned scan state'); }
                    if ($next > $record['last_owner']) { $record['owner_count']++; $record['last_owner'] = $next; }
                    $record['file']['roles'] = array_values(array_unique(array_merge($record['file']['roles'], $file['roles'])));
                    $role = WP_Seed_Pixel_Analyzer::primary_role($record['file']['roles']);
                    $shared = $record['owner_count'] > 1;
                    $stage = !$file['exists'] ? 'missing' : ($shared || $file['link_count'] > 1 || !$file['readable'] ? 'review' : ($role === 'preserved_original' && !empty($result['storage']['potential_original_bytes']) ? 'potential' : 'observed'));
                    $values = array('job_id' => $id, 'kind' => 'file', 'item_key' => $file['identity'], 'attachment_id' => $next, 'stage' => $stage, 'bytes' => $file['logical_bytes'], 'role' => $role, 'owners' => $record['owner_count'], 'data' => wp_json_encode($record));
                    $saved = $row ? $wpdb->update($items, $values, array('id' => $row['id'])) : $wpdb->insert($items, $values);
                    if ($saved === false) { throw new RuntimeException('File ledger update failed'); }
                    if ($shared) {
                        $result['health'] = 'needs_review'; $result['issues'][] = 'shared_file';
                        if ($wpdb->query($wpdb->prepare("UPDATE $items SET stage='needs_review' WHERE job_id=%d AND kind='attachment' AND attachment_id=%d", $id, $record['first_owner'])) === false) { throw new RuntimeException('Shared-file classification failed'); }
                    }
                }
                $values = array('job_id' => $id, 'kind' => 'attachment', 'item_key' => (string) $next, 'attachment_id' => $next, 'stage' => $result['health'], 'data' => wp_json_encode($result));
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $items WHERE job_id=%d AND kind='attachment' AND item_key=%s", $id, (string) $next));
                $saved = $exists ? $wpdb->update($items, $values, array('id' => $exists)) : $wpdb->insert($items, $values);
                if ($saved === false || $wpdb->query($wpdb->prepare("UPDATE $jobs SET scan_cursor=%d WHERE id=%d AND lease=%s AND lease_until>=%d", $next, $id, $token, time())) !== 1) { throw new RuntimeException('Scan checkpoint conflict'); }
                if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Scan commit failed'); }
            } catch (Throwable $error) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('pixel_scan_store', __('Analysis state could not be saved.', 'wp-seed-pixel'));
            }
            return self::status($id);
        } finally { $wpdb->update($jobs, array('lease' => '', 'lease_until' => 0), array('id' => $id, 'lease' => $token)); }
    }

    public static function results($id, $page = 1, $filter = '') {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel')); }
        $page = max(1, (int) $page); $items = self::table('items');
        $where = $wpdb->prepare("job_id=%d AND kind='attachment'", $id);
        if (in_array($filter, array('healthy', 'needs_review', 'missing', 'unsupported'), true)) { $where .= $wpdb->prepare(' AND stage=%s', $filter); }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $items WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT data,stage,attachment_id FROM $items WHERE $where ORDER BY attachment_id LIMIT 20 OFFSET %d", ($page - 1) * 20), ARRAY_A);
        $result = array();
        foreach ($rows as $row) {
            if (!current_user_can('edit_post', (int) $row['attachment_id'])) { continue; }
            $record = json_decode($row['data'], true); if (!is_array($record)) { continue; }
            if ($record['health'] !== $row['stage']) { $record['issues'][] = 'shared_file'; }
            $record['issues'] = array_values(array_unique($record['issues']));
            $record['health'] = $row['stage'];
            $record['stale'] = !isset($record['metadata_revision']) || WP_Seed_Pixel_Analyzer::revision((int) $row['attachment_id']) !== $record['metadata_revision'];
            if (!$record['stale']) {
                $fresh = WP_Seed_Pixel_Analyzer::analyze((int) $row['attachment_id']);
                $record['stale'] = is_wp_error($fresh) || wp_json_encode($fresh['files']) !== wp_json_encode($record['files']);
                if (!is_wp_error($fresh)) { $record['preview_url'] = $fresh['preview_url']; }
            }
            $result[] = $record;
        }
        return array('items' => $result, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20)), 'total' => $total);
    }
}
