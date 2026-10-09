<?php
// Renders the actual panel, with explicitly synthetic WordPress read adapters.
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-metadata.php';
require dirname(__DIR__).'/includes/class-metadata-admin.php';
$translations=json_decode(file_get_contents(dirname(__DIR__).'/tools/i18n-fr.json'),true);
function __($s,$domain=null) { global $translations; return $translations[$s]??$s; }
function esc_html($s) { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_html_e($s,$domain=null) { echo esc_html(__($s,$domain)); }
function esc_attr_e($s,$domain=null) { echo esc_attr(__($s,$domain)); }
function current_user_can($capability,$id=null) { return true; }
function get_post_type($id) { return 'attachment'; }
function get_post_mime_type($id) { return 'image/jpeg'; }
function is_multisite() { return false; }
function get_post_meta($id,$key,$single) { return array(); }
function is_wp_error($value) { return false; }
$analysis=array('master'=>array('categories'=>array('gps','device','date','author','software')),'signature'=>str_repeat('a',64));
echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pixel : recette synthétique Métadonnées</title>';
echo '<style>body{font:14px system-ui;margin:0;background:#f0f0f1;color:#1d2327}main{max-width:720px;margin:32px auto;padding:16px;background:white}h1{font-size:22px;margin:0 0 16px}.button{background:#f6f7f7;border:1px solid #2271b1;border-radius:3px;color:#135e96;padding:6px 12px;cursor:pointer}p{line-height:1.55}button:focus-visible,input:focus-visible{outline:2px solid #2271b1;outline-offset:3px}@media(max-width:760px){main{margin:12px 0}}</style>';
echo '<style>'.file_get_contents(dirname(__DIR__).'/assets/metadata.css').'</style><main><h1>Pièce jointe synthétique</h1>';
echo WP_Seed_Pixel_Metadata_Admin::panel(17,$analysis);
echo '</main><script>window.wpSeedPixelMetadata={url:"https://fixture.invalid/ajax",nonce:"synthetic",working:"Analyse en cours…",failed:"Analyse interrompue"};</script>';
echo '<script>'.file_get_contents(dirname(__DIR__).'/assets/metadata.js').'</script></html>';
