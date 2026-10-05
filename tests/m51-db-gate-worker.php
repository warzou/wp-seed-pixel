<?php
require __DIR__ . '/m3-runtime.php';
if ($argv[1] === 'deadlock') {
    $db = mysqli_init(); mysqli_real_connect($db,'localhost','root','','pixel_m3',0,getenv('PIXEL_M3_ROOT').'/mysql.sock');
    mysqli_report(MYSQLI_REPORT_OFF);
    $table = WP_Seed_Pixel_Job_Store::table('items');
    $a=(int)$argv[2];$b=(int)$argv[3];$at=(float)$argv[4];
    mysqli_query($db,'START TRANSACTION');
    if (!mysqli_query($db,"UPDATE $table SET attempts=attempts+1 WHERE id=$a")) { throw new RuntimeException('First row lock unavailable'); }
    while (microtime(true)<$at) { usleep(1000); }
    $ok=mysqli_query($db,"UPDATE $table SET attempts=attempts+1 WHERE id=$b");
    $errno=mysqli_errno($db);
    mysqli_query($db,$ok?'COMMIT':'ROLLBACK');mysqli_close($db);
    echo wp_json_encode(array('committed'=>(bool)$ok,'errno'=>$errno))."\n";exit;
}
$id = (int) $argv[1]; $token = $argv[2]; $at = (float) $argv[3];
$item = WP_Seed_Pixel_Job_Store::item($id);
while (microtime(true) < $at) { usleep(1000); }
$result = WP_Seed_Pixel_Job_Store::transition($item, $token, 'preparing');
echo wp_json_encode(array('won' => !is_wp_error($result), 'revision' => is_wp_error($result) ? null : (int) $result['revision'])) . "\n";
