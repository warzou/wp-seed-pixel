<?php
$root = $argv[1] ?? '';
if (basename($root) !== 'codex-pixel-060-metadata-audit-20261007') { exit(2); }
$files = glob($root . '/fixtures/*');
$differences = array();
for ($round = 0; $round < 50; $round++) {
    foreach ($files as $path) {
        clearstatcache(true, $path);
        $before = lstat($path);
        $bytes = file_get_contents($path);
        $after = lstat($path);
        if ($after !== $before) {
            $changed = array();
            foreach ($before as $key => $value) { if (!is_int($key) && $after[$key] !== $value) { $changed[$key] = array($value, $after[$key]); } }
            $differences[] = array('file' => basename($path), 'round' => $round, 'changed' => $changed, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes));
        }
    }
}
file_put_contents($root . '/outputs/stat-diagnostic.json', json_encode($differences, JSON_PRETTY_PRINT));
echo json_encode($differences, JSON_PRETTY_PRINT), "\n";
