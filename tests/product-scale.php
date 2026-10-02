<?php
require __DIR__ . '/runtime.php';
$ids = array();
$tests = array();
for ($i = 0; $i < 1000; ++$i) {
    $ids[] = (int) wp_insert_attachment(array('post_title' => 'Synthetic scale fixture ' . $i, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'));
}
foreach (array(10, 100, 1000) as $size) {
    update_option(WP_Seed_Pixel_Batch::OPTION, array(), false);
    wp_cache_flush();
    $queries = $wpdb->num_queries;
    $start = microtime(true);
    $batch = WP_Seed_Pixel_Batch::start('balanced', true, array_slice($ids, 0, $size));
    $query_count = $wpdb->num_queries - $queries;
    $pass = !is_wp_error($batch) && $batch['total'] === $size && $query_count < 30;
    $tests[] = array('test' => 'Selected queue ' . $size, 'status' => $pass ? 'PASS' : 'FAIL', 'queries' => $query_count, 'seconds' => round(microtime(true) - $start, 4));
    if (!$pass) { throw new RuntimeException('Scale validation failed'); }
    WP_Seed_Pixel_Batch::pause(true);
    $tests[] = array('test' => 'Paused queue ' . $size . ' does not advance', 'status' => WP_Seed_Pixel_Batch::step()['processed'] === 0 ? 'PASS' : 'FAIL');
}
WP_Seed_Pixel_Batch::pause(false);
for ($i = 0; $i < 10; ++$i) { $batch = WP_Seed_Pixel_Batch::step(); }
$tests[] = array('test' => 'Large selection advances exactly ten items', 'status' => $batch['processed'] === 10 && $batch['skipped'] === 10 ? 'PASS' : 'FAIL');
WP_Seed_Pixel_Batch::pause(true);
$tests[] = array('test' => 'Large selection persists cursor for resumption', 'status' => WP_Seed_Pixel_Batch::current()['cursor'] === $ids[9] ? 'PASS' : 'FAIL');
update_option(WP_Seed_Pixel_Batch::OPTION, array(), false);
file_put_contents(dirname(__DIR__) . '/reports/product-ux/scale-tests.json', wp_json_encode($tests, JSON_PRETTY_PRINT));
echo wp_json_encode(array_count_values(array_column($tests, 'status')));
exit(in_array('FAIL', array_column($tests, 'status'), true) ? 1 : 0);
