<?php
defined('ABSPATH') || exit;

/** Read-only transaction planning. This class does not authorize a graph write. */
final class WP_Seed_Pixel_Metadata_Public_Graph {
    const VERSION = 'metadata-public-graph-1';
    const WRITE_CERTIFIED = false;
    const MAX_FILES = 256;
    const MAX_BYTES = 134217728;

    /** UI-compatible analysis; approval binds every file, not just the master. */
    public static function analyze(array $before) {
        $p = self::plan($before); if (is_wp_error($p)) { return $p; }
        if (!$p['admissible']) { return new WP_Error($p['blockers'][0]['code']); }
        $categories = array();
        foreach ($p['manifest']['files'] as $file) { $categories = array_merge($categories, $file['categories']); }
        $master = $p['manifest']['files'][$before['relative']];
        $master['categories'] = array_values(array_unique($categories));
        $master['removed_bytes'] = $p['metadata_bytes_removed'];
        return array('master' => $master, 'signature' => $p['signature'], 'plan' => $p);
    }

    private static function classification($code) {
        if ($code === 'METADATA_ORIENTATION') { return 'C'; }
        if ($code === 'METADATA_PROVENANCE') { return 'D'; }
        if (in_array($code, array('METADATA_INVALID', 'CANDIDATE_INVALID'), true)) { return 'F'; }
        if (in_array($code, array('METADATA_MISSING', 'METADATA_UNREADABLE'), true)) { return 'G'; }
        return 'E';
    }

    /** The caller supplies a fresh, ownership-checked Master_Adapter snapshot. */
    public static function plan(array $before) {
        if (!isset($before['attachment_id'], $before['relative'], $before['files'])
            || !is_int($before['attachment_id']) || $before['attachment_id'] < 1
            || !is_string($before['relative']) || !is_array($before['files'])
            || !$before['files'] || count($before['files']) > self::MAX_FILES
            || !isset($before['files'][$before['relative']])) { return new WP_Error('INVENTORY_INCOMPLETE'); }
        $root = wp_upload_dir(null, false)['basedir'];
        $files = array(); $blocks = array(); $total = 0; $removed = 0; $modified = 0; $identities = array();
        foreach ($before['files'] as $relative => $expected) {
            // Paths come from native mappings, never guessed filenames or a recursive scan.
            if (!is_string($relative) || $relative === '' || $relative[0] === '/'
                || strpos($relative, '\\') !== false || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $relative)
                || !is_array($expected) || !is_string($expected['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $expected['sha256'])
                || !is_int($expected['bytes'] ?? null) || $expected['bytes'] < 1) {
                return new WP_Error('INVENTORY_INCOMPLETE');
            }
            if ($expected['bytes'] > self::MAX_BYTES - $total) { return new WP_Error('METADATA_LIMIT'); }
            $total += $expected['bytes'];
            $roles = $expected['roles'] ?? array();
            $known_roles = array('operational', 'preserved_original', 'pixel_current', 'pixel_history', 'wp_sizes', 'edit_backups');
            if (!is_array($roles) || !$roles) { return new WP_Error('INVENTORY_INCOMPLETE'); }
            foreach ($roles as $role) { if (!is_string($role) || !in_array($role, $known_roles, true)) { return new WP_Error('INVENTORY_INCOMPLETE'); } }
            $path = $root . '/' . $relative;
            $code = null; $scan = null;
            clearstatcache(true, $path);
            if (!file_exists($path)) { $code = 'METADATA_MISSING'; }
            elseif (!is_readable($path)) { $code = 'METADATA_UNREADABLE'; }
            else {
                $safe = WP_Seed_Pixel_Files::path($path);
                if (is_wp_error($safe)) { return $safe; }
                $stat = lstat($safe);
                if (!$stat || !is_file($safe) || is_link($safe) || $stat['nlink'] !== 1) { return new WP_Error('SHARED_PATH'); }
                $identity = $stat['dev'] . ':' . $stat['ino'];
                if (isset($identities[$identity])) { return new WP_Error('SHARED_PATH'); }
                $identities[$identity] = true;
                if ($stat['size'] !== $expected['bytes'] || hash_file('sha256', $safe) !== $expected['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
                $scan = WP_Seed_Pixel_Metadata::read($safe);
                if (is_wp_error($scan)) { $code = $scan->get_error_code(); $scan = null; }
                elseif ($scan['sha256'] !== $expected['sha256']) { return new WP_Error('SOURCE_CHANGED'); }
            }
            $class = $code ? self::classification($code) : ($scan['categories'] ? 'B' : 'A');
            $entry = array('relative' => $relative, 'roles' => $roles, 'classification' => $class,
                'before_sha256' => $expected['sha256'], 'before_bytes' => $expected['bytes'], 'error_code' => $code);
            if ($scan) {
                $entry += array('format' => $scan['format'], 'width' => $scan['width'], 'height' => $scan['height'],
                    'categories' => $scan['categories'], 'removed_bytes' => $scan['removed_bytes'],
                    'after_sha256' => $scan['filtered_sha256'], 'after_bytes' => $scan['bytes'],
                    'image_sha256' => $scan['image_sha256'], 'color_sha256' => $scan['color_sha256']);
                $removed += $scan['removed_bytes'];
                if ($class === 'B') { $modified++; }
                // Filter output is deliberately not persisted or returned by the planner.
                unset($scan['data']);
            }
            if ($code) { $blocks[] = array('relative' => $relative, 'classification' => $class, 'code' => $code); }
            $files[$relative] = $entry;
        }
        ksort($files);
        $manifest = array('version' => self::VERSION, 'attachment_id' => $before['attachment_id'],
            'master' => $before['relative'], 'files' => $files);
        return array('manifest' => $manifest, 'signature' => hash('sha256', wp_json_encode($manifest)),
            'admissible' => !$blocks, 'blockers' => $blocks, 'no_op' => !$blocks && !$modified,
            'public_bytes' => $total, 'modified_files' => $modified, 'clean_files' => count($files) - $modified - count($blocks),
            'metadata_bytes_removed' => $removed, 'write_certified' => self::WRITE_CERTIFIED);
    }
}
