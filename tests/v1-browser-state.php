<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$id = (int) $wpdb->get_var('SELECT attachment_id FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' AND action='replace' AND stage='purged' ORDER BY id DESC LIMIT 1");
if (!$id) { throw new RuntimeException('M6 purged fixture required'); }
echo wp_json_encode(array('purged_id' => $id));
