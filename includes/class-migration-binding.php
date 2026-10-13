<?php
defined('ABSPATH') || exit;

/** Destination evidence epoch. Historical source journals are never used as writable target journals. */
final class WP_Seed_Pixel_Migration_Binding {
    const VERSION = 'migration-binding-1';
    const PLAN = '.migration-binding.json';
    const SOURCE = 'migration-source';

    private static function fail($code) { return new WP_Error('MIGRATION_' . $code); }
    private static function rows() {
        global $wpdb; $out = array();
        foreach (array('jobs','items') as $kind) {
            $out[$kind] = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table($kind) . ' ORDER BY id LIMIT 10001', ARRAY_A);
            if ($wpdb->last_error || !is_array($out[$kind]) || count($out[$kind]) > 10000) { return self::fail('SQL_CONFLICT'); }
        }
        return $out;
    }
    private static function relative($name) {
        return is_string($name) && $name !== '' && !str_starts_with($name,'/') && !str_contains($name,'\\')
            && !str_contains($name,':') && !preg_match('~(?:^|/)\.{0,2}(?:/|$)|[\x00-\x1f]~',$name);
    }
    private static function json($path) {
        $s = @lstat($path);
        if (!$s || !is_file($path) || is_link($path) || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()
            || ($s['mode'] & 0022) || $s['size'] > 16777216) { return self::fail('EVIDENCE_INVALID'); }
        $r = json_decode(file_get_contents($path),true);
        return is_array($r) ? $r : self::fail('EVIDENCE_INVALID');
    }
    private static function private_directory($path) {
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) { return false; }
        $cursor = '';
        foreach (explode('/',trim($path,'/')) as $part) { $cursor .= '/' . $part; if (is_link($cursor)) { return false; } }
        $s = lstat($path); return $s['uid'] === posix_geteuid() && ($s['mode'] & 0777) === 0700;
    }
    private static function write($path, $bytes) {
        if (!self::private_directory(dirname($path))) { return false; }
        if (file_exists($path) || is_link($path)) {
            $s = lstat($path);
            return is_file($path) && !is_link($path) && $s['nlink'] === 1 && $s['uid'] === posix_geteuid()
                && ($s['mode'] & 0077) === 0 && file_get_contents($path) === $bytes;
        }
        $old = umask(0077); try { $f = @fopen($path,'xb'); } finally { umask($old); }
        if (!$f) { return false; }
        try { return fwrite($f,$bytes) === strlen($bytes) && fflush($f) && fsync($f); } finally { fclose($f); }
    }
    private static function copy($from, $to, array $expected) {
        if (!WP_Seed_Pixel_Authority::valid_all() || is_link($from) || !is_file($from)
            || lstat($from)['nlink'] !== 1 || filesize($from) !== $expected['bytes'] || hash_file('sha256',$from) !== $expected['sha256']) { return false; }
        if (file_exists($to) || is_link($to)) {
            $s = lstat($to);
            return !is_link($to) && is_file($to) && $s['nlink'] === 1 && $s['uid'] === posix_geteuid()
                && ($s['mode'] & 0077) === 0 && $s['size'] === $expected['bytes'] && hash_file('sha256',$to) === $expected['sha256'];
        }
        $parent = dirname($to); $anchor = $parent;
        while (!file_exists($anchor) && !is_link($anchor)) { $anchor = dirname($anchor); }
        if (!self::private_directory($anchor)) { return false; }
        if (!is_dir($parent) && !mkdir($parent,0700,true)) { return false; }
        if (!self::private_directory($parent)) { return false; }
        $old = umask(0077); try { $in = fopen($from,'rb'); $out = @fopen($to,'xb'); } finally { umask($old); }
        if (!$out) { fclose($in); return false; }
        try { $n = stream_copy_to_stream($in,$out); $ok = $n === $expected['bytes'] && fflush($out) && fsync($out); }
        finally { fclose($in); fclose($out); }
        return $ok && hash_file('sha256',$to) === $expected['sha256'];
    }
    private static function native($id) {
        global $wpdb; $out = array();
        foreach (WP_Seed_Pixel_Master_Adapter::KEYS as $key) {
            $out[$key] = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",$id,$key));
            if ($wpdb->last_error || count($out[$key]) > 1) { return self::fail('NATIVE_CONFLICT'); }
        }
        return $out;
    }
    /** Use the engine's ordinary guards; transport alone never establishes recoverability. */
    public static function availability(array $item) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); if (is_wp_error($r) || !$r) { return self::fail('EVIDENCE_INVALID'); }
        $absent = self::check_absences($item,$r,$dir); if (is_wp_error($absent)) { return $absent; }
        if ($item['action'] === 'convert') {
            return WP_Seed_Pixel_Format_Conversion::view($item,$r,$dir)['restore_available'];
        }
        $q = WP_Seed_Pixel_Quarantine::inspect($item);
        return is_wp_error($q) ? $q : !empty($q['rollback_available']);
    }
    public static function check_absences(array $item,array $r,$dir = null) {
        $paths = array();
        if (isset($r['retired_original']) && in_array($item['stage'],array('retained','purged'),true)) { $paths[] = $r['retired_original']['relative']; }
        if ($item['action'] === 'convert' && $item['stage'] === 'purged') { $paths = array_merge($paths,array_keys($r['before']['files'])); }
        foreach ($paths as $relative) {
            if (!self::relative($relative)) { return self::fail('EVIDENCE_INVALID'); }
            $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative,false);
            if (is_wp_error($path) || file_exists($path) || is_link($path)) { return self::fail('UNEXPECTED_PUBLIC_ORIGINAL'); }
        }
        if ($dir && $item['stage'] === 'purged') {
            $names = $item['action'] === 'convert' ? array_values($r['backup']) : array('recovery.jpg');
            foreach ($names as $name) {
                if (!self::relative($name) || basename($name) !== $name || file_exists($dir . '/' . $name) || is_link($dir . '/' . $name)) { return self::fail('UNEXPECTED_RECOVERY'); }
            }
        }
        return true;
    }
    public static function terminal(array $item) {
        $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); if (is_wp_error($dir)) { return $dir; }
        $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); if (is_wp_error($r) || !$r) { return self::fail('EVIDENCE_INVALID'); }
        $absent = self::check_absences($item,$r,$dir); if (is_wp_error($absent)) { return $absent; }
        if ($item['action'] === 'convert') { return WP_Seed_Pixel_Format_Conversion::migration_terminal($item,$r,$dir); }
        $q = WP_Seed_Pixel_Quarantine::inspect($item);
        return is_wp_error($q) ? $q : $q['state'] === 'purged' && $q['active_delta'] !== null && !$q['rollback_available'];
    }
    private static function identity($path) {
        clearstatcache(true,$path); $s = @lstat($path);
        if (!$s || !is_file($path) || is_link($path) || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid() || ($s['mode'] & 0022)) { return self::fail('EVIDENCE_INVALID'); }
        return array('dev'=>$s['dev'],'ino'=>$s['ino']);
    }
    private static function operational(array $r,array &$data,$dir,$archive,$name,array $proof) {
        if (isset($r['graph_version'])) {
            if ($r['phase'] !== 'graph_committed') { return self::fail('ENGINE_NOT_CERTIFIED'); }
            foreach ($r['graph_files'] as $relative=>&$file) {
                $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative);
                if (is_wp_error($path) || !self::relative($file['original']) || basename($file['original']) !== $file['original']) { return self::fail('EVIDENCE_INVALID'); }
                $expected = $proof['files'][$name . '/' . $file['original']] ?? null;
                if (!$expected || !self::copy($archive . '/files/' . $name . '/' . $file['original'],$dir . '/' . $file['original'],$expected)) { return self::fail('COPY_FAILED'); }
                $s = lstat($path); $file['uid'] = $s['uid']; $file['gid'] = $s['gid']; $file['mode'] = $s['mode'] & 0777;
                $q = self::identity($dir . '/' . $file['original']); if (is_wp_error($q)) { return $q; }
                $r['quarantine']['files'][$relative] = array_merge($r['quarantine']['files'][$relative],$q);
            } unset($file);
            $r['quarantine']['allocated_bytes'] = null;
        } elseif (isset($r['generation'],$r['backup'],$r['new_files'])) {
            if ($r['phase'] !== 'retained') { return self::fail('ENGINE_NOT_CERTIFIED'); }
            foreach ($r['backup'] as $relative=>$private) {
                if (!self::relative($private) || basename($private) !== $private) { return self::fail('EVIDENCE_INVALID'); }
                $expected = $proof['files'][$name . '/' . $private] ?? null;
                if (!$expected || !self::copy($archive . '/files/' . $name . '/' . $private,$dir . '/' . $private,$expected)) { return self::fail('COPY_FAILED'); }
                $r['backup_identity'][$relative] = self::identity($dir . '/' . $private);
                $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative);
                if (is_wp_error($path) || is_wp_error($r['backup_identity'][$relative])) { return self::fail('EVIDENCE_INVALID'); }
                $r['original_identity'][$relative] = self::identity($path); if (is_wp_error($r['original_identity'][$relative])) { return self::fail('EVIDENCE_INVALID'); }
                $s = lstat($path); $r['original_attributes'][$relative] = array('mode'=>$s['mode'] & 0777,'uid'=>$s['uid'],'gid'=>$s['gid']);
            }
            foreach ($r['new_files'] as $relative=>&$file) {
                $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative);
                if (is_wp_error($path)) { return $path; }
                $file['identity'] = self::identity($path); if (is_wp_error($file['identity'])) { return $file['identity']; }
            } unset($file);
        } else {
            if ($r['phase'] !== 'retained' || !isset($r['quarantine'])) { return self::fail('ENGINE_NOT_CERTIFIED'); }
            $expected = $proof['files'][$name . '/recovery.jpg'] ?? null;
            if (!$expected || !self::copy($archive . '/files/' . $name . '/recovery.jpg',$dir . '/recovery.jpg',$expected)) { return self::fail('COPY_FAILED'); }
            $identity = self::identity($dir . '/recovery.jpg'); if (is_wp_error($identity)) { return $identity; }
            $r['quarantine'] = array_merge($r['quarantine'],$identity);
            if (isset($r['retired_original'])) {
                $r['retired_original']['uid'] = $r['before']['uid']; $r['retired_original']['gid'] = $r['before']['gid'];
                $data['original'] = $r['retired_original'];
            }
        }
        return $r;
    }
    private static function project_rows(array $rows, array $context) {
        foreach (array('_seed_pixel_manifest','_seed_pixel_history') as $key) {
            foreach ($rows[$key] ?? array() as $index=>$raw) {
                $value = is_serialized($raw) ? unserialize($raw,array('allowed_classes'=>false)) : $raw;
                if (!is_array($value)) { return self::fail('REFERENCE_SCHEMA'); }
                $manifests = $key === '_seed_pixel_manifest' ? array($value) : $value;
                foreach ($manifests as &$m) {
                    if (!is_array($m) || !is_array($m['files'] ?? null)) { return self::fail('REFERENCE_SCHEMA'); }
                    foreach ($m['files'] as &$f) {
                        if (isset($f['path'])) {
                            $prefix = rtrim($context['uploads'],'/') . '/';
                            if (!is_string($f['path']) || !str_starts_with($f['path'],$prefix)) { return self::fail('REFERENCE_SCHEMA'); }
                            $relative = substr($f['path'],strlen($prefix));
                            if (!self::relative($relative) || (isset($f['relative']) && $f['relative'] !== $relative)) { return self::fail('REFERENCE_SCHEMA'); }
                            $f['path'] = wp_upload_dir(null,false)['basedir'] . '/' . $relative;
                        }
                    } unset($f);
                } unset($m);
                $rows[$key][$index] = maybe_serialize($key === '_seed_pixel_manifest' ? $manifests[0] : $manifests);
            }
        }
        return $rows;
    }
    private static function snapshot(array $before, array $context, $historical_missing = false) {
        if (!self::relative($before['relative'] ?? null)) { return self::fail('REFERENCE_SCHEMA'); }
        $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $before['relative']);
        if (is_wp_error($path) && $historical_missing) {
            $absent = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $before['relative'],false);
            if (is_wp_error($absent) || file_exists($absent) || is_link($absent)) { return self::fail('EVIDENCE_INVALID'); }
            $path = WP_Seed_Pixel_Files::path(get_attached_file($before['attachment_id']));
        }
        if (is_wp_error($path)) { return $path; }
        $s = lstat($path); $post = get_post($before['attachment_id']);
        if (!$post || $post->guid !== $before['guid'] || $s['uid'] !== posix_geteuid() || $s['nlink'] !== 1 || ($s['mode'] & 0022)) { return self::fail('NATIVE_CONFLICT'); }
        $before['url'] = rtrim(wp_upload_dir(null,false)['baseurl'],'/') . '/' . $before['relative'];
        $before['uid'] = $s['uid']; $before['gid'] = $s['gid']; $before['mode'] = $s['mode'] & 0777;
        $rows = self::project_rows($before['rows'],$context); if (is_wp_error($rows)) { return $rows; } $before['rows'] = $rows;
        return $before;
    }
    private static function stage(array $proof, $bundle, array $state, $sha) {
        $root = $state['path']; $archive = $root . '/' . self::SOURCE;
        if (!is_dir($archive) && !mkdir($archive,0700)) { return self::fail('WRITE_FAILED'); }
        if (!self::private_directory($archive)) { return self::fail('FOREIGN_DIRECTORY'); }
        foreach ($proof['files'] as $name=>$expected) {
            if (!self::relative($name) || !self::copy($bundle . '/files/' . $name,$archive . '/files/' . $name,$expected)) { return self::fail('COPY_FAILED'); }
        }
        $manifest = file_get_contents($bundle . '/manifest.json');
        if (!hash_equals($sha,hash('sha256',$manifest)) || !self::write($archive . '/manifest.json',$manifest)) { return self::fail('EVIDENCE_INVALID'); }
        $plan = array('version'=>self::VERSION,'source_sha256'=>$sha,'target_site'=>$state['site'],
            'target_uploads'=>wp_upload_dir(null,false)['basedir'],'target_home'=>home_url(),'records'=>array(),'items'=>array(),'native'=>array());
        foreach ($proof['public'] as $id=>$snapshot) {
            $current = self::native($id); if (is_wp_error($current) || $current !== $snapshot['rows']) { return self::fail('NATIVE_CONFLICT'); }
            $desired = self::project_rows($current,$proof['context']); if (is_wp_error($desired)) { return $desired; }
            $plan['native'][$id] = array('old'=>$current,'new'=>$desired);
        }
        $job_kinds = array_column($proof['sql']['jobs'],'kind','id');
        foreach ($proof['sql']['items'] as $item) {
            if ($item['kind'] !== 'operation') { continue; }
            if ($job_kinds[$item['job_id']] === 'simulation') { continue; }
            $id = (int)$item['attachment_id'];
            if (!current_user_can('edit_post',$id)) { return self::fail('PERMISSION_DENIED'); }
            $name = 'm3-' . (int)$item['job_id'] . '-' . (int)$item['id'];
            $source = $archive . '/files/' . $name . '/journal.json';
            if (!file_exists($source) && $item['stage'] !== 'retained') { continue; }
            $r = self::json($source); if (is_wp_error($r)) { return $r; }
            $checksum = $r['checksum'] ?? ''; unset($r['checksum']); $data = json_decode($item['data'],true);
            if (!hash_equals($checksum,hash('sha256',wp_json_encode($r))) || ($r['before'] ?? null) !== ($data['before'] ?? null)) { return self::fail('EVIDENCE_INVALID'); }
            $before = self::snapshot($r['before'],$proof['context'],$item['stage'] === 'purged'); if (is_wp_error($before)) { return $before; }
            $current = self::native($id); if (is_wp_error($current) || $current !== $proof['public'][$id]['rows']) { return self::fail('NATIVE_CONFLICT'); }
            $desired = self::project_rows($current,$proof['context']); if (is_wp_error($desired)) { return $desired; }
            $plan['native'][$id] = array('old'=>$current,'new'=>$desired);
            $dir = $root . '/' . $name;
            if (!is_dir($dir) && !mkdir($dir,0700)) { return self::fail('WRITE_FAILED'); }
            if (is_link($dir) || realpath($dir) !== $dir || (fileperms($dir) & 0777) !== 0700 || fileowner($dir) !== posix_geteuid()) { return self::fail('FOREIGN_DIRECTORY'); }
            $r['before'] = $before; $data['before'] = $before;
            if ($item['stage'] === 'retained') {
                $r = self::operational($r,$data,$dir,$archive,$name,$proof); if (is_wp_error($r)) { return $r; }
            } else {
                // Terminal history is evidence, not a newly authorized recovery.
                foreach ($proof['files'] as $file_name=>$expected) {
                    if (!str_starts_with($file_name,$name . '/') || $file_name === $name . '/journal.json') { continue; }
                    if (!self::copy($archive . '/files/' . $file_name,$root . '/' . $file_name,$expected)) { return self::fail('COPY_FAILED'); }
                }
                if ($item['action'] === 'convert' && $item['stage'] === 'purged') {
                    foreach ($r['new_files'] as $relative=>&$file) {
                        $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative);
                        if (is_wp_error($path)) { return $path; }
                        $file['identity'] = self::identity($path); if (is_wp_error($file['identity'])) { return $file['identity']; }
                    } unset($file);
                }
                if (isset($r['purge']['allocated_bytes'])) { $r['purge']['allocated_bytes'] = null; }
            }
            $binding = array('version'=>self::VERSION,'source_sha256'=>$sha,'source_journal_sha256'=>hash_file('sha256',$source),
                'source_snapshot_hash'=>$item['snapshot_hash'],'target_site'=>$state['site'],'before_sha256'=>hash('sha256',wp_json_encode($before)));
            $r['migration_binding'] = $binding; $data['migration_binding'] = $binding;
            $new = $item; $new['data'] = wp_json_encode($data); $new['snapshot_hash'] = hash('sha256',$new['data']); $new['revision'] = (string)((int)$item['revision'] + 1);
            $journal = json_decode($item['journal'],true); unset($journal['checksum']);
            if (isset($r['quarantine'])) { $journal['quarantine_hash'] = hash('sha256',wp_json_encode($r['quarantine'])); }
            $journal['migration_binding'] = $binding; $journal['checksum'] = hash('sha256',wp_json_encode($journal)); $new['journal'] = wp_json_encode($journal);
            $r['checksum'] = hash('sha256',wp_json_encode($r));
            if (!self::write($dir . '/journal.json',wp_json_encode($r)) || !WP_Seed_Pixel_Master_Storage::sync_directory($dir)) { return self::fail('WRITE_FAILED'); }
            $plan['records'][$item['id']] = $r; $plan['items'][$item['id']] = array('old'=>$item,'new'=>$new);
        }
        $plan['checksum'] = hash('sha256',wp_json_encode($plan));
        return self::write($root . '/' . self::PLAN,wp_json_encode($plan)) && WP_Seed_Pixel_Master_Storage::sync_directory($root) ? $plan : self::fail('WRITE_FAILED');
    }
    private static function commit(array $plan, array $proof) {
        global $wpdb;
        $rows = self::rows(); $desired = $proof['sql'];
        foreach ($desired['items'] as &$item) { $item = $plan['items'][$item['id']]['new'] ?? $item; } unset($item);
        if ($rows === $desired) {
            foreach ($plan['native'] as $id=>$values) { if (self::native($id) !== $values['new']) { return self::fail('NATIVE_CONFLICT'); } }
            return true;
        }
        if ($rows !== $proof['sql']) { return self::fail('TARGET_SQL_CONFLICT'); }
        foreach (array(WP_Seed_Pixel_Job_Store::table('jobs'),WP_Seed_Pixel_Job_Store::table('items'),$wpdb->postmeta,$wpdb->options) as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table),ARRAY_A);
            if (!$status || strtoupper($status['Engine']) !== 'INNODB') { return self::fail('TRANSACTION_UNAVAILABLE'); }
        }
        if ($wpdb->query('START TRANSACTION') === false) { return self::fail('SQL_CONFLICT'); }
        try {
            foreach ($plan['native'] as $id=>$values) {
                foreach ($values['old'] as $key=>$old) {
                    $current = $wpdb->get_results($wpdb->prepare("SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id FOR UPDATE",$id,$key),ARRAY_A);
                    if ($wpdb->last_error || array_column($current,'meta_value') !== $old) { throw new RuntimeException('NATIVE_CONFLICT'); }
                    if ($old === $values['new'][$key]) { continue; }
                    if (count($old) !== 1 || count($values['new'][$key]) !== 1 || $wpdb->update($wpdb->postmeta,array('meta_value'=>$values['new'][$key][0]),array('meta_id'=>$current[0]['meta_id'],'meta_value'=>$old[0])) !== 1) { throw new RuntimeException('NATIVE_CONFLICT'); }
                }
            }
            foreach ($plan['items'] as $entry) {
                $old = $entry['old']; $new = $entry['new'];
                $current = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE id=%d FOR UPDATE',$old['id']),ARRAY_A);
                if ($current !== $old || $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'),array('data'=>$new['data'],'snapshot_hash'=>$new['snapshot_hash'],'journal'=>$new['journal'],'revision'=>$new['revision']),array('id'=>$old['id'],'revision'=>$old['revision'],'data'=>$old['data'])) !== 1) { throw new RuntimeException('TARGET_SQL_CONFLICT'); }
            }
            if (!WP_Seed_Pixel_Authority::valid_all() || self::rows() !== $desired) { throw new RuntimeException('TARGET_SQL_CONFLICT'); }
            if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('SQL_CONFLICT'); }
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); return self::fail($e->getMessage()); }
        foreach (array_keys($plan['native']) as $id) { wp_cache_delete($id,'post_meta'); clean_post_cache($id); }
        return true;
    }
    public static function bind($bundle,$sha,array $webroots) {
        $v = WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle,$sha,$webroots); if (is_wp_error($v)) { return $v; }
        $proof = self::json($bundle . '/manifest.json'); if (is_wp_error($proof)) { return $proof; }
        $uploads = realpath(wp_upload_dir(null,false)['basedir']); $source_uploads = rtrim($proof['context']['uploads'],'/');
        $identity = $proof['context']['uploads_identity'] ?? null; $s = $uploads ? stat($uploads) : null;
        if (!$identity || !$s || $uploads === $source_uploads || str_starts_with($uploads,$source_uploads . '/')
            || str_starts_with($source_uploads,$uploads . '/') || ($identity['dev'] === $s['dev'] && $identity['ino'] === $s['ino'])
            || realpath(ABSPATH) !== rtrim(ABSPATH,'/') || str_starts_with(realpath(ABSPATH),$proof['context']['wordpress'] . '/')
            || str_starts_with($proof['context']['wordpress'],rtrim(ABSPATH,'/') . '/')) { return self::fail('SHARED_UPLOADS'); }
        if (realpath(ABSPATH) === $proof['context']['wordpress']
            || hash('sha256',$GLOBALS['wpdb']->get_var('SELECT DATABASE()') . ':' . $GLOBALS['wpdb']->prefix) === $proof['context']['authority_namespace']) { return self::fail('SHARED_AUTHORITY'); }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return $lock; } $images = array();
        try {
            $job_kinds = array_column($proof['sql']['jobs'],'kind','id');
            foreach ($proof['sql']['items'] as $item) {
                if ($item['kind'] !== 'operation') { continue; }
                if ($job_kinds[$item['job_id']] === 'simulation') { continue; }
                if ($item['stage'] !== 'retained') { continue; }
                $record = self::json($bundle . '/files/m3-' . (int)$item['job_id'] . '-' . (int)$item['id'] . '/journal.json');
                if (is_wp_error($record) || (isset($record['graph_version'])
                    ? $record['graph_version'] !== WP_Seed_Pixel_Metadata_Graph_Transaction::VERSION || $record['phase'] !== 'graph_committed'
                    : $record['phase'] !== 'retained')) { return self::fail('ENGINE_NOT_CERTIFIED'); }
                $absent = self::check_absences($item,$record); if (is_wp_error($absent)) { return $absent; }
            }
            foreach ($proof['sql']['items'] as $item) {
                if ($item['kind'] !== 'operation' || $item['stage'] !== 'purged') { continue; }
                $record = self::json($bundle . '/files/m3-' . (int)$item['job_id'] . '-' . (int)$item['id'] . '/journal.json');
                if (is_wp_error($record)) { return $record; }
                $absent = self::check_absences($item,$record); if (is_wp_error($absent)) { return $absent; }
            }
            foreach (array_keys($proof['public']) as $id) {
                $l = WP_Seed_Pixel_Files::lock((int)$id); if (is_wp_error($l)) { return $l; } $images[] = $l;
            }
            $pending = get_option(WP_Seed_Pixel_Recovery_Setup::OPTION,null);
            if (!is_array($pending) || ($pending['migration'] ?? '') !== $sha) {
                if (self::rows() !== $proof['sql']) { return self::fail('TARGET_SQL_CONFLICT'); }
                foreach ($proof['public'] as $id=>$snapshot) {
                    if (self::native($id) !== $snapshot['rows']) { return self::fail('NATIVE_CONFLICT'); }
                    foreach ($snapshot['files'] as $relative=>$file) {
                        $path = WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'] . '/' . $relative);
                        if (is_wp_error($path) || filesize($path) !== $file['bytes'] || hash_file('sha256',$path) !== $file['sha256']) { return self::fail('PUBLIC_GRAPH_CHANGED'); }
                    }
                }
            }
            $candidate = WP_Seed_Pixel_Recovery_Setup::candidate(); if (is_wp_error($candidate)) { return $candidate; }
            foreach ($webroots as $webroot) {
                $public = realpath($webroot);
                if (!$public || $candidate['path'] === $public || str_starts_with($candidate['path'],$public . '/')) { return self::fail('UNSAFE_LOCATION'); }
            }
            $source_root = $proof['storage']['path'];
            if ($candidate['path'] === $source_root || str_starts_with($candidate['path'],$source_root . '/')
                || str_starts_with($source_root,$candidate['path'] . '/')) { return self::fail('SHARED_STORAGE'); }
            $pending = WP_Seed_Pixel_Recovery_Setup::migration_prepare($proof['storage'],$sha); if (is_wp_error($pending)) { return $pending; }
            $root = $pending['path']; $plan = file_exists($root . '/' . self::PLAN) ? self::json($root . '/' . self::PLAN) : null;
            if (is_wp_error($plan)) { return $plan; }
            if (!$plan) { $plan = self::stage($proof,$bundle,$pending,$sha); if (is_wp_error($plan)) { return $plan; } }
            $hash = $plan['checksum'] ?? ''; $copy = $plan; unset($copy['checksum']);
            if (!hash_equals($hash,hash('sha256',wp_json_encode($copy))) || $plan['source_sha256'] !== $sha || $plan['target_site'] !== $pending['site']
                || $plan['target_uploads'] !== wp_upload_dir(null,false)['basedir'] || $plan['target_home'] !== home_url()) { return self::fail('EVIDENCE_INVALID'); }
            foreach ($plan['records'] as $id=>$record) {
                $path = $root . '/m3-' . $record['job_id'] . '-' . $id . '/journal.json';
                if (self::json($path) !== $record) { return self::fail('EVIDENCE_INVALID'); }
                if ($plan['items'][$id]['new']['stage'] !== 'retained') { continue; }
                foreach ($record['graph_files'] ?? array() as $relative=>$file) {
                    $private = dirname($path) . '/' . $file['original']; $s = @lstat($private); $q = $record['quarantine']['files'][$relative];
                    if (!$s || is_link($private) || $s['nlink'] !== 1 || $s['dev'] !== $q['dev'] || $s['ino'] !== $q['ino']
                        || $s['size'] !== $q['bytes'] || hash_file('sha256',$private) !== $q['sha256']) { return self::fail('ORIGINAL_CHANGED'); }
                }
            }
            do_action('wp_seed_pixel_migration_boundary','staged',$sha);
            $commit = self::commit($plan,$proof); if (is_wp_error($commit)) { return $commit; }
            do_action('wp_seed_pixel_migration_boundary','sql_committed',$sha);
            $ready = WP_Seed_Pixel_Recovery_Setup::migration_ready($pending,true); if (is_wp_error($ready)) { return $ready; }
            WP_Seed_Pixel_Recovery_Setup::apply();
            foreach ($plan['items'] as $id=>$entry) {
                $item = WP_Seed_Pixel_Job_Store::item($id);
                if ($item['stage'] === 'purged' && self::terminal($item) !== true) {
                    WP_Seed_Pixel_Recovery_Setup::migration_ready($ready,false); return self::fail('CERTIFICATION_FAILED');
                }
                if ($item['stage'] !== 'retained') { continue; }
                $q = self::availability($item);
                if (is_wp_error($q) || !$q) {
                    WP_Seed_Pixel_Recovery_Setup::migration_ready($ready,false); return self::fail('CERTIFICATION_FAILED');
                }
            }
            $active = count(array_filter($plan['items'],static function($entry) { return $entry['new']['stage'] === 'retained'; }));
            return array('state'=>'BOUND_VERIFIED','source_sha256'=>$sha,'items'=>count($plan['items']),
                'attachments'=>count($plan['native']),'recoverable_items'=>$active,'restoration_authorized'=>$active > 0);
        } finally { foreach (array_reverse($images) as $image) { WP_Seed_Pixel_Files::unlock($image); } WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function verify_journal($dir,array $record,array $item) {
        if (!isset($record['migration_binding'])) { return true; }
        $b = $record['migration_binding']; $data = json_decode($item['data'],true); $state = WP_Seed_Pixel_Recovery_Setup::verify();
        if (is_wp_error($state) || ($data['migration_binding'] ?? null) !== $b || ($state['migration'] ?? '') !== ($b['source_sha256'] ?? null)
            || ($b['version'] ?? '') !== self::VERSION || $b['target_site'] !== $state['site'] || dirname($dir) !== $state['path']
            || hash('sha256',wp_json_encode($record['before'])) !== $b['before_sha256']) { return self::fail('EVIDENCE_INVALID'); }
        $manifest = $state['path'] . '/' . self::SOURCE . '/manifest.json';
        if (is_wp_error(self::json($manifest)) || hash_file('sha256',$manifest) !== $b['source_sha256']) { return self::fail('EVIDENCE_INVALID'); }
        $source = $state['path'] . '/' . self::SOURCE . '/files/m3-' . (int)$item['job_id'] . '-' . (int)$item['id'] . '/journal.json';
        $source_record = self::json($source);
        if (is_wp_error($source_record) || hash_file('sha256',$source) !== $b['source_journal_sha256']) { return self::fail('EVIDENCE_INVALID'); }
        $proof = self::json($manifest); $original_item = null;
        foreach ($proof['sql']['items'] as $source_item) { if ($source_item['id'] === $item['id']) { $original_item = $source_item; break; } }
        if (!$original_item || $original_item['snapshot_hash'] !== $b['source_snapshot_hash']) { return self::fail('EVIDENCE_INVALID'); }
        $projected = self::snapshot($source_record['before'],$proof['context'],$original_item['stage'] === 'purged');
        if (is_wp_error($projected) || $projected !== $record['before']) { return self::fail('EVIDENCE_INVALID'); }
        foreach (array('candidate','after','witness','graph_version','graph','generation','backup','profiles','selected_profile','references') as $key) {
            if (($source_record[$key] ?? null) !== ($record[$key] ?? null)) { return self::fail('EVIDENCE_INVALID'); }
        }
        $old_purge = $source_record['purge'] ?? array(); $new_purge = $record['purge'] ?? array();
        unset($old_purge['allocated_bytes'],$new_purge['allocated_bytes']);
        if ($old_purge !== $new_purge) { return self::fail('EVIDENCE_INVALID'); }
        foreach ($source_record['new_files'] ?? array() as $relative=>$source_file) {
            $target_file = $record['new_files'][$relative] ?? null;
            if (!is_array($target_file)) { return self::fail('EVIDENCE_INVALID'); }
            unset($source_file['identity'],$target_file['identity']);
            if ($source_file !== $target_file) { return self::fail('EVIDENCE_INVALID'); }
        }
        $prefix = 'm3-' . (int)$item['job_id'] . '-' . (int)$item['id'] . '/';
        foreach ($proof['files'] as $name=>$expected) {
            if (!str_starts_with($name,$prefix)) { continue; }
            $path = $state['path'] . '/' . self::SOURCE . '/files/' . $name;
            $s = @lstat($path);
            if (!$expected || !$s || is_link($path) || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()
                || $s['size'] !== $expected['bytes'] || hash_file('sha256',$path) !== $expected['sha256']) { return self::fail('EVIDENCE_INVALID'); }
        }
        return true;
    }

    public static function gate(array $state) {
        if (!isset($state['migration'])) { return true; }
        $plan = self::json($state['path'] . '/' . self::PLAN); if (is_wp_error($plan)) { return false; }
        $hash = $plan['checksum'] ?? ''; unset($plan['checksum']);
        $manifest = $state['path'] . '/' . self::SOURCE . '/manifest.json';
        return is_string($hash) && hash_equals($hash,hash('sha256',wp_json_encode($plan)))
            && $plan['version'] === self::VERSION && $plan['target_site'] === $state['site']
            && $plan['source_sha256'] === $state['migration'] && $plan['target_home'] === home_url()
            && $plan['target_uploads'] === wp_upload_dir(null,false)['basedir']
            && !is_wp_error(self::json($manifest)) && hash_file('sha256',$manifest) === $state['migration'];
    }

    public static function verify_store(array $state) {
        if (!self::gate($state)) { return self::fail('EVIDENCE_INVALID'); }
        $root = $state['path'] . '/' . self::SOURCE; $proof = self::json($root . '/manifest.json');
        foreach ($proof['files'] as $name=>$file) {
            if (!self::relative($name)) { return self::fail('EVIDENCE_INVALID'); }
            $path = $root . '/files/' . $name; $s = @lstat($path);
            if (!$s || !is_file($path) || is_link($path) || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()
                || $s['size'] !== $file['bytes'] || hash_file('sha256',$path) !== $file['sha256']) { return self::fail('EVIDENCE_INVALID'); }
        }
        return true;
    }
}
