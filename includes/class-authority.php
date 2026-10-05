<?php
defined('ABSPATH') || exit;

/** Session ownership supplements durable M2 tokens; lease expiry cannot steal it. */
final class WP_Seed_Pixel_Authority {
    private static $owners = array();

    public static function acquire($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id < 0 || isset(self::$owners[$id])) { return new WP_Error('pixel_locked'); }
        $database = $wpdb->get_var('SELECT DATABASE()');
        if (!$database || $wpdb->last_error) { return new WP_Error('pixel_lock_unavailable'); }
        $name = 'seed-pixel:' . hash('sha256', $database . ':' . $wpdb->prefix . ':' . $id);
        $name = substr($name, 0, 64);
        $claimed = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name));
        if ((string) $claimed !== '1' || $wpdb->last_error) { return new WP_Error($claimed === null ? 'pixel_lock_unavailable' : 'pixel_locked'); }
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        $owner = (object) array('id' => $id, 'name' => $name, 'connection' => (string) $connection, 'token' => bin2hex(random_bytes(24)));
        self::$owners[$id] = $owner;
        if (!self::valid($id)) { self::release($owner); return new WP_Error('pixel_lock_unavailable'); }
        return $owner;
    }

    public static function valid($id) {
        global $wpdb;
        $owner = self::$owners[(int) $id] ?? null;
        if (!$owner) { return false; }
        $r = $wpdb->get_row($wpdb->prepare('SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS owner_id', $owner->name), ARRAY_A);
        return !$wpdb->last_error && is_array($r) && (string) $r['connection_id'] === $owner->connection
            && (string) $r['owner_id'] === $owner->connection;
    }

    public static function valid_all() {
        if (!self::$owners) { return false; }
        foreach (array_keys(self::$owners) as $id) { if (!self::valid($id)) { return false; } }
        return true;
    }

    public static function release($owner) {
        global $wpdb;
        if (!is_object($owner) || !isset($owner->id) || (self::$owners[$owner->id] ?? null) !== $owner) { return false; }
        // A reconnected session or stale handle must never release somebody else's lock.
        $valid = self::valid($owner->id);
        $released = $valid && (string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $owner->name)) === '1' && !$wpdb->last_error;
        unset(self::$owners[$owner->id]);
        return $released;
    }
}
