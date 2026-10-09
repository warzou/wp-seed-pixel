<?php
defined('ABSPATH') || exit;

/** Extends M1's two tables; never writes attachment posts, metadata or images. */
final class WP_Seed_Pixel_Job_Store {
    const SCHEMA = 2;
    const ENGINE = 'm2-simulation-1';
    const MAX_STORAGE_JOBS = 10000;
    const TERMINAL = array('retained', 'purged', 'rolled_back', 'skipped', 'cancelled', 'failed', 'needs_review');

    public static function table($suffix) { global $wpdb; return $wpdb->prefix . 'seed_pixel_' . $suffix; }
    public static function install() {
        global $wpdb;
        $version = (int) get_option('wp_seed_pixel_job_schema');
        if ($version > self::SCHEMA) { return new WP_Error('ENGINE_INCOMPATIBLE'); }
        if ($version === self::SCHEMA) { return true; }
        $m1 = WP_Seed_Pixel_Scan::install(); if (is_wp_error($m1)) { return $m1; }
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
            updated bigint(20) unsigned NOT NULL DEFAULT 0,
            schema_version int unsigned NOT NULL DEFAULT 0,
            engine varchar(40) NOT NULL DEFAULT '',
            policy longtext NULL,
            policy_hash varchar(64) NOT NULL DEFAULT '',
            plan_hash varchar(64) NOT NULL DEFAULT '',
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            failures int unsigned NOT NULL DEFAULT 0,
            error_code varchar(40) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY status_updated (status,updated),
            KEY kind_status (kind,status)
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
            action varchar(20) NOT NULL DEFAULT '',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            lease varchar(64) NOT NULL DEFAULT '',
            lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts int unsigned NOT NULL DEFAULT 0,
            error_code varchar(40) NOT NULL DEFAULT '',
            error_class varchar(20) NOT NULL DEFAULT '',
            journal longtext NULL,
            receipt longtext NULL,
            snapshot_hash varchar(64) NOT NULL DEFAULT '',
            updated bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY item_identity (job_id,kind,item_key),
            KEY job_kind (job_id,kind),
            KEY job_stage (job_id,kind,stage),
            KEY attachment_stage (attachment_id,kind,stage),
            KEY job_cursor (job_id,kind,id)
        ) $charset;");
        foreach (array('jobs' => array('policy', 'revision', 'engine', 'plan_hash'), 'items' => array('revision', 'journal', 'receipt', 'error_class')) as $table => $required) {
            $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . self::table($table));
            if (array_diff($required, $columns)) { return new WP_Error('SCHEMA_FAILED'); }
        }
        update_option('wp_seed_pixel_job_schema', self::SCHEMA, false);
        return true;
    }

    public static function job($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('jobs') . " WHERE id=%d AND kind IN ('plan','simulation','replace','retire','convert')", $id), ARRAY_A);
    }
    public static function item($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('items') . ' WHERE id=%d', $id), ARRAY_A); }

    /** A live SQL session fences takeover even during an expensive stage. Never renew a foreign token. */
    public static function renew(array $item, $token) {
        global $wpdb;
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $item['attachment_id']) || !$token) { return new WP_Error('LOCKED'); }
        $until = time() + 60;
        $r = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('items') . ' SET lease_until=%d WHERE id=%d AND revision=%d AND lease=%s', $until, $item['id'], $item['revision'], $token));
        if ($r === false) { return new WP_Error('LOCKED'); }
        $fresh = self::item($item['id']);
        if (!$fresh || $fresh['lease'] !== $token || (int) $fresh['revision'] !== (int) $item['revision'] || (int) $fresh['lease_until'] !== $until) { return new WP_Error('LOCKED'); }
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table('jobs') . ' SET lease_until=%d WHERE id=%d AND lease=%s', $until, $item['job_id'], $token));
        return $wpdb->last_error || !WP_Seed_Pixel_Authority::valid_all() ? new WP_Error('LOCKED') : $fresh;
    }

    public static function insert_job($kind, $source, array $policy, $total) {
        global $wpdb;
        // Stop admission, never erase recovery receipts to make room for new work.
        if (in_array($kind, array('replace', 'retire', 'convert'), true)) {
            $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('jobs') . " WHERE kind IN ('replace','retire','convert')");
            if ($wpdb->last_error || $count === null) { return new WP_Error('STORE_FAILED'); }
            if ((int) $count >= self::MAX_STORAGE_JOBS) { return new WP_Error('HISTORY_FULL'); }
        }
        $values = array('kind' => $kind, 'status' => 'queued', 'source_id' => $source, 'total' => $total,
            'actor' => get_current_user_id(), 'created' => time(), 'updated' => time(), 'schema_version' => self::SCHEMA,
            'engine' => self::ENGINE, 'policy' => wp_json_encode($policy), 'policy_hash' => WP_Seed_Pixel_Policy::hash($policy),
            'plan_hash' => hash('sha256', 'pixel-plan-v1:' . WP_Seed_Pixel_Policy::hash($policy)));
        if (!$wpdb->insert(self::table('jobs'), $values)) { return new WP_Error('STORE_FAILED'); }
        return (int) $wpdb->insert_id;
    }

    public static function error_class($code) {
        if (in_array($code, array('STORE_FAILED', 'LOW_DISK', 'QUOTA_UNKNOWN', 'CEILING_EXCEEDED', 'BACKEND_UNAVAILABLE', 'SCHEMA_FAILED', 'HISTORY_FULL'), true)) { return 'systemic'; }
        if (in_array($code, array('LOCKED', 'CLAIM_CONFLICT', 'METADATA_CONFLICT'), true)) { return 'conflict'; }
        if (in_array($code, array('CANDIDATE_INVALID', 'BACKUP_FAILED', 'VERIFY_FAILED', 'SWAP_FAILED', 'PURGE_FAILED', 'REQUEST_INTERRUPTED'), true)) { return 'retryable'; }
        if (in_array($code, array('UNSUPPORTED_FORMAT', 'UNSUPPORTED_STORAGE'), true)) { return 'unsupported'; }
        return 'review';
    }

    /** Only a fenced owner may append a transition. Journal is bounded and checksummed. */
    public static function transition(array $item, $token, $stage, $code = '', array $receipt = array()) {
        global $wpdb;
        if (in_array($item['action'], array('replace', 'retire', 'convert'), true) && !WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        $allowed = array('queued' => array('preparing', 'failed', 'needs_review', 'skipped'), 'preparing' => array('ready', 'failed', 'needs_review', 'skipped'),
            'ready' => array('switch_intent', 'failed', 'needs_review'), 'switch_intent' => array('switched', 'recovery_required'),
            'switched' => array('verified', 'recovery_required'), 'verified' => array('retained', 'recovery_required'),
            'retained' => in_array($item['action'], array('replace', 'retire', 'convert'), true) ? array('retained', 'recovery_required', 'purge_intent') : array(),
            'purge_intent' => array('purged', 'retained', 'needs_review'),
            'recovery_required' => array('switch_intent', 'switched', 'verified', 'needs_review', 'rolled_back'));
        if (class_exists('WP_Seed_Pixel_Metadata_Graph_Transaction') && WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item)
            && in_array($item['stage'], array('queued','preparing','ready'), true) && $stage === 'recovery_required') { $allowed[$item['stage']][] = 'recovery_required'; }
        if (!in_array($stage, $allowed[$item['stage']] ?? array(), true)) { return new WP_Error('CLAIM_CONFLICT'); }
        $journal = json_decode((string) $item['journal'], true) ?: array('events' => array(), 'dropped' => 0);
        unset($journal['checksum']);
        // Preserve historical item measurements across retain/purge receipts.
        // These are logical bytes, not filesystem allocation or provider quota.
        foreach (array('active_delta', 'recovery_bytes') as $key) {
            if (isset($receipt[$key]) && is_int($receipt[$key]) && $receipt[$key] >= 0) { $journal['storage'][$key] = $receipt[$key]; }
        }
        if ($stage === 'purged' && !empty($receipt['physical_absence_verified']) && isset($receipt['removed_bytes'])) {
            $journal['storage']['removed_bytes'] = (int) $receipt['removed_bytes'];
        }
        if (isset($receipt['quarantine_hash'])) { $journal['quarantine_hash'] = $receipt['quarantine_hash']; }
        $journal['events'][] = array('revision' => (int) $item['revision'] + 1, 'from' => $item['stage'], 'stage' => $stage,
            'attempt' => (int) $item['attempts'], 'code' => $code, 'at' => time(), 'simulation' => !in_array($item['action'], array('replace', 'retire', 'convert'), true));
        while (count($journal['events']) > 32) { array_shift($journal['events']); $journal['dropped']++; }
        $payload = wp_json_encode($journal);
        $journal['checksum'] = hash('sha256', $payload);
        $result = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('items') .
            ' SET stage=%s,revision=revision+1,error_code=%s,error_class=%s,journal=%s,receipt=%s,updated=%d WHERE id=%d AND revision=%d AND lease=%s AND lease_until>=%d',
            $stage, $code, $code ? self::error_class($code) : '', wp_json_encode($journal), wp_json_encode($receipt), time(), $item['id'], $item['revision'], $token, time()));
        return $result === 1 ? self::item($item['id']) : new WP_Error('STORE_FAILED');
    }

    public static function journal_valid(array $item) {
        if (empty($item['journal']) || $item['journal'] === '[]') { return true; }
        $j = json_decode($item['journal'], true);
        if (!is_array($j) || !isset($j['checksum'])) { return false; }
        $hash = $j['checksum']; unset($j['checksum']);
        return hash_equals($hash, hash('sha256', wp_json_encode($j)));
    }

    public static function record_cleanup(array $item, $token, array $receipt) {
        global $wpdb;
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid((int) $item['attachment_id'])
            || !self::journal_valid($item) || $item['action'] !== 'replace'
            || !in_array($item['stage'], array('skipped', 'failed', 'needs_review', 'cancelled'), true)
            || ($receipt['cleanup_unreplaced'] ?? false) !== true || ($receipt['recovery_bytes'] ?? -1) !== 0) { return new WP_Error('EVIDENCE_INVALID'); }
        $j = json_decode($item['journal'], true); unset($j['checksum']);
        $j['storage'] = array('active_delta' => 0, 'recovery_bytes' => 0);
        $j['cleanup_unreplaced'] = $receipt;
        $j['checksum'] = hash('sha256', wp_json_encode($j));
        $merged = array_merge(json_decode($item['receipt'], true) ?: array(), $receipt);
        $ok = $wpdb->query($wpdb->prepare('UPDATE ' . self::table('items') .
            ' SET revision=revision+1,journal=%s,receipt=%s,updated=%d WHERE id=%d AND revision=%d AND lease=%s AND lease_until>=%d',
            wp_json_encode($j), wp_json_encode($merged), time(), $item['id'], $item['revision'], $token, time()));
        return $ok === 1 ? self::item($item['id']) : new WP_Error('STORE_FAILED');
    }

    /** Only explicitly requested, old, fully terminal simulation history is eligible. */
    public static function prune($before, $limit = 20) {
        global $wpdb;
        if (!current_user_can('manage_options')) { return new WP_Error('PERMISSION_DENIED'); }
        $schema = (int) get_option('wp_seed_pixel_job_schema');
        if ($schema > self::SCHEMA) { return new WP_Error('ENGINE_INCOMPATIBLE'); }
        if ($schema < self::SCHEMA) { return 0; }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return new WP_Error('LOCKED'); }
        try {
            $jobs = self::table('jobs'); $items = self::table('items'); $deleted = 0;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $jobs WHERE kind='simulation' AND status IN ('completed','completed_errors','cancelled') AND updated<%d AND lease_until=0 ORDER BY id LIMIT %d", min((int) $before, time() - 86400 * 30), min(20, max(1, (int) $limit))));
            foreach ($ids as $id) {
                if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE job_id=%d AND (lease_until<>0 OR stage NOT IN ('retained','skipped','cancelled'))", $id))) { continue; }
                if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
                if ($wpdb->delete($items, array('job_id' => $id)) === false || !$wpdb->delete($jobs, array('id' => $id))) { $wpdb->query('ROLLBACK'); return new WP_Error('STORE_FAILED'); }
                if ($wpdb->query('COMMIT') === false) { return new WP_Error('STORE_FAILED'); }
                $deleted++;
            }
            return $deleted;
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }
}
