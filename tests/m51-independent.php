<?php
require __DIR__ . '/m3-runtime.php';
$root = getenv('PIXEL_M3_ROOT');
if ($root !== '/home/warzy/.cache/wp-seed-pixel-m3-environment') { throw new RuntimeException('Owned runtime required.'); }
$at = microtime(true) + 3; $processes = array(); $rows = array();
foreach (array(96001, 96002) as $id) {
    $ext = "$root/root/usr/lib/php/20250925";
    $p = proc_open(array(PHP_BINARY, '-d', "extension=$ext/gd.so", '-d', "extension=$ext/mysqli.so", '-d', "extension=$ext/imagick.so", __DIR__ . '/m51-lock-worker.php', (string) $id, (string) $at, '3'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($p)) { throw new RuntimeException('Worker unavailable.'); }
    fclose($pipes[0]); $processes[] = array($p, $pipes);
}
foreach ($processes as [$p, $pipes]) {
    $rows[] = json_decode(fgets($pipes[1]), true);
    $tail = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || $tail || $error) { throw new RuntimeException('Independent worker failed.'); }
}
$checks = array('two_distinct_processes' => $rows[0]['pid'] !== $rows[1]['pid'], 'both_independent_items_owned' => $rows[0]['owned'] && $rows[1]['owned'], 'claims_nonblocking' => max(array_column($rows, 'ms')) < 1000);
if (in_array(false, $checks, true)) { throw new RuntimeException('Independent ownership failed.'); }
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/authority-gate/independent.json', wp_json_encode(array('checks' => $checks, 'database' => $GLOBALS['wpdb']->get_var('SELECT VERSION()')), JSON_PRETTY_PRINT));
echo "3 independent-process authority checks passed.\n";
