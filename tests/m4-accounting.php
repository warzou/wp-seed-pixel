<?php
require __DIR__ . '/m4-runtime.php';
$checks = array(); $details = array();
function m4_measure($roots) {
    $out = array('logical' => 0, 'allocated' => 0, 'files' => 0);
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) { continue; }
            clearstatcache(true, $file->getPathname()); $s = lstat($file->getPathname());
            $out['logical'] += $s['size']; $out['allocated'] += $s['blocks'] * 512; $out['files']++;
        }
    }
    return $out;
}
foreach (array('master', 'original') as $kind) {
    if ($kind === 'master') { list($id, $job, $view) = m4_replaced(); }
    else { list($id, $job) = m4_original(); WP_Seed_Pixel_Jobs::step($job); $view = WP_Seed_Pixel_Quarantine::inspect(m4_item($job)); }
    $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item); $q = $dir . '/recovery.jpg';
    $roots = array($dir, dirname(get_attached_file($id))); $before = m4_measure($roots);
    $qstat = lstat($q); $r = WP_Seed_Pixel_Quarantine::record($item); $samples = array();
    $sample = static function ($name) use (&$samples, $roots) { $samples[$name] = m4_measure($roots); };
    add_action('wp_seed_pixel_m4_boundary', $sample);
    $p = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view)); remove_action('wp_seed_pixel_m4_boundary', $sample);
    $after = m4_measure($roots);
    m4_check(!is_wp_error($p) && !file_exists($q), $kind . ' physical removal verified');
    m4_check($before['files'] - $after['files'] === 1, $kind . ' exactly one owned file removed');
    m4_check($before['logical'] - $after['logical'] === $qstat['size'] - ($p['audit_bytes'] - $view['audit_bytes']), $kind . ' measured net logical delta exact');
    m4_check($before['allocated'] - $after['allocated'] === $qstat['blocks'] * 512, $kind . ' allocated file blocks reclaimed');
    m4_check($p['removed_bytes'] === $qstat['size'] && $p['quarantine_bytes'] === 0 && $p['quota_bytes'] === null, $kind . ' honest observed bytes no hosting quota claim');
    m4_check(max(array_column($samples, 'logical')) <= $before['logical'] + 16384, $kind . ' purge transient overhead bounded observed');
    $details[$kind] = array('before' => $before, 'after' => $after, 'source_bytes' => $qstat['size'], 'source_allocated' => $qstat['blocks'] * 512, 'samples' => $samples);
}
m4_report('accounting', $details);
