<?php
require __DIR__ . '/runtime.php';
$r = WP_Seed_Pixel_Recovery_Setup::verify();
if (getenv('PIXEL_RESET_ALLOW_ABSENT') === '1' && get_option(WP_Seed_Pixel_Recovery_Setup::OPTION, null) === null) {
    $web = realpath(ABSPATH); $site = hash('sha256', $web . ':' . $GLOBALS['wpdb']->prefix);
    foreach (array(dirname($web), dirname(dirname($web))) as $parent) {
        $path = $parent . '/.wp-seed-pixel-' . substr($site, 0, 24);
        if (!file_exists($path) && !is_link($path)) { continue; }
        if (!str_starts_with($path, '/home/warzy/.cache/wp-seed-pixel-m3-environment/') || is_link($path)
            || !is_file($path . '/.pixel-owner.json') || is_link($path . '/.pixel-owner.json')) { throw new RuntimeException('Unproven disposable recovery directory'); }
        $marker = json_decode(file_get_contents($path . '/.pixel-owner.json'), true);
        if (!is_array($marker) || $marker['site'] !== $site) { throw new RuntimeException('Disposable recovery marker mismatch'); }
        $r = WP_Seed_Pixel_Recovery_Setup::verify(array_merge($marker, array('path' => $path, 'webroot' => $web)));
        break;
    }
    if (is_wp_error($r) && $r->get_error_code() === 'NOT_PREPARED') {
        echo 'No disposable automatic recovery configuration to remove.'; exit;
    }
}
if (is_wp_error($r) || !str_starts_with($r['path'], '/home/warzy/.cache/wp-seed-pixel-m3-environment/')) { throw new RuntimeException('Owned disposable directory required'); }
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r['path'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
$entries = iterator_to_array($it, false);
foreach ($entries as $file) {
    if (!str_starts_with($file->getPathname(), $r['path'] . '/') || lstat($file->getPathname())['uid'] !== posix_geteuid()) { throw new RuntimeException('Unexpected cleanup entry'); }
    if ($file->isLink()) {
        $target = $file->getLinkTarget();
        if (getenv('PIXEL_RESET_ALLOW_ABSENT') !== '1' || !str_starts_with($target, '/home/warzy/.cache/wp-seed-pixel-m3-environment/')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $target)) { throw new RuntimeException('Unproven synthetic link'); }
    }
}
foreach ($entries as $file) {
    if ($file->getPathname() === $r['path'] . '/.pixel-owner.json') { continue; }
    if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
}
unlink($r['path'] . '/.pixel-owner.json');
rmdir($r['path']); delete_option(WP_Seed_Pixel_Recovery_Setup::OPTION);
echo 'Owned disposable recovery state removed.';
