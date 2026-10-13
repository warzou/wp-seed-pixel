<?php
declare(strict_types=1);

/** Definitions-only source reader. Never include wp-config, wp-settings, plugins or drop-ins. */
function seed_export_plain(string $path): string {
    if ($path === '' || $path[0] !== '/' || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f]~', $path)) {
        throw new RuntimeException('EXPORT_UNSAFE_PATH');
    }
    $cursor = '';
    foreach (explode('/', trim($path, '/')) as $part) {
        $cursor .= '/' . $part;
        if (is_link($cursor)) { throw new RuntimeException('EXPORT_SYMLINK'); }
    }
    if (realpath($path) !== $path) { throw new RuntimeException('EXPORT_NONCANONICAL_PATH'); }
    return $path;
}
function seed_export_config(string $web): array {
    $raw = file_get_contents($web . '/wp-config.php'); $result = [];
    $tokens = token_get_all($raw);
    $significant = array_values(array_filter($tokens, fn($t)=>!is_array($t) || !in_array($t[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)));
    $literal = static function($token): string {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) { throw new RuntimeException('EXPORT_LITERAL_CONFIG_REQUIRED'); }
        $s = $token[1];
        return $s[0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($s,1,-1)) : stripcslashes(substr($s,1,-1));
    };
    for ($i=0; $i<count($significant); $i++) {
        $t = $significant[$i];
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'define'
            && ($significant[$i+1]??null) === '(' && is_array($significant[$i+2]??null)
            && $significant[$i+2][0] === T_CONSTANT_ENCAPSED_STRING) {
            $key = $literal($significant[$i+2]);
            if (in_array($key,['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','DB_CHARSET','WP_HOME','WP_SITEURL','UPLOADS'],true)) {
                if (($significant[$i+3]??null) !== ',' || ($significant[$i+5]??null) !== ')') { throw new RuntimeException('EXPORT_DYNAMIC_CONFIG_REFUSED'); }
                if (isset($result[$key])) { throw new RuntimeException('EXPORT_DUPLICATE_CONFIG'); }
                $value=$significant[$i+4];
                $result[$key] = $key==='WP_SITEURL' && is_array($value) && $value[0]===T_STRING && $value[1]==='WP_HOME'
                    ? ($result['WP_HOME']??throw new RuntimeException('EXPORT_DYNAMIC_CONFIG_REFUSED')) : $literal($value);
            }
        }
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$table_prefix') {
            if (($significant[$i+1]??null) !== '=') { throw new RuntimeException('EXPORT_DYNAMIC_PREFIX'); }
            $result['prefix'] = $literal($significant[$i+2]);
        }
    }
    foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','prefix'] as $key) {
        if (!isset($result[$key])) { throw new RuntimeException('EXPORT_CONFIG_INCOMPLETE'); }
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/D',$result['prefix']) || isset($result['UPLOADS'])) {
        throw new RuntimeException('EXPORT_CUSTOM_LAYOUT_NOT_CERTIFIED');
    }
    return $result;
}
function seed_export_runtime(array $request): array {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH') || isset($GLOBALS['wpdb'])) { throw new RuntimeException('EXPORT_FRESH_CLI_REQUIRED'); }
    foreach (['wordpress','collector','webroot'] as $key) { seed_export_plain($request[$key]); }
    $web = $request['wordpress']; $cfg = seed_export_config($web);
    if ($cfg['DB_NAME'] !== $request['database'] || $cfg['DB_USER'] !== $request['database_user']
        || $cfg['prefix'] !== $request['prefix']) { throw new RuntimeException('EXPORT_SQL_SCOPE'); }
    if ($request['webroot'] !== $web) { throw new RuntimeException('EXPORT_NESTED_INSTALL_NOT_CERTIFIED'); }
    define('ABSPATH',$web.'/'); define('WPINC','wp-includes'); define('MULTISITE',false);
    define('WP_CONTENT_DIR',$web.'/wp-content'); define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');
    define('WP_DEBUG',false); define('WP_DEBUG_DISPLAY',false); define('WP_DEBUG_LOG',false);
    define('WP_CACHE',false); define('DISABLE_WP_CRON',true); define('WP_HTTP_BLOCK_EXTERNAL',true);
    define('WP_ENVIRONMENT_TYPE','staging'); define('WP_MEMORY_LIMIT','512M'); define('WP_MAX_MEMORY_LIMIT','512M');
    define('DB_NAME',$cfg['DB_NAME']); define('DB_USER',$cfg['DB_USER']); define('DB_HOST',$cfg['DB_HOST']);
    define('DB_PASSWORD',$cfg['DB_PASSWORD']); define('DB_CHARSET',$cfg['DB_CHARSET']??'utf8mb4'); define('DB_COLLATE','');
    if (isset($cfg['WP_HOME'])) { define('WP_HOME',$cfg['WP_HOME']); }
    if (isset($cfg['WP_SITEURL'])) { define('WP_SITEURL',$cfg['WP_SITEURL']); }
    $GLOBALS['table_prefix']=$cfg['prefix']; $GLOBALS['blog_id']=1;
    $_SERVER['DOCUMENT_ROOT']=$request['webroot']; $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['HTTP_HOST']=parse_url($request['home'],PHP_URL_HOST);
    $_SERVER['HTTPS']=parse_url($request['home'],PHP_URL_SCHEME)==='https'?'on':'off';
    $core = ['version.php','compat-utf8.php','compat.php','load.php','default-constants.php','plugin.php',
        'class-wp-list-util.php','class-wp-token-map.php','utf8.php','formatting.php','meta.php','functions.php',
        'class-wp-meta-query.php','class-wp-error.php','pomo/mo.php','l10n/class-wp-translation-controller.php',
        'l10n/class-wp-translations.php','l10n/class-wp-translation-file.php','l10n/class-wp-translation-file-mo.php',
        'l10n/class-wp-translation-file-php.php','l10n.php','class-wp-textdomain-registry.php','class-wpdb.php',
        'cache.php','capabilities.php','class-wp-roles.php','class-wp-role.php','class-wp-user.php',
        'user.php','post.php','class-wp-post.php','class-wp-post-type.php','taxonomy.php','class-wp-taxonomy.php','link-template.php','shortcodes.php','media.php',
        'post-template.php','general-template.php','theme.php','http.php','class-wp-http.php','pluggable.php'];
    $loaded = [];
    if (isset($request['expected_core_sha256'])) {
        foreach ($request['expected_core_sha256'] as $relative=>$sha) {
            $path=seed_export_plain($web.'/wp-includes/'.$relative);
            if (!preg_match('/^[a-f0-9]{64}$/D',$sha) || hash_file('sha256',$path)!==$sha) { throw new RuntimeException('EXPORT_CORE_INTEGRITY_REFUSED'); }
        }
    }
    foreach ($core as $relative) {
        $path = seed_export_plain($web.'/wp-includes/'.$relative);
        if (!is_file($path)) { throw new RuntimeException('EXPORT_CORE_DEFINITION_MISSING'); }
        $loaded[$relative]=hash_file('sha256',$path); require_once $path;
    }
    wp_initial_constants();
    $GLOBALS['wp_version']=$wp_version;
    require_once __DIR__.'/standalone-db.php';
    $GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
    wp_cache_init(); wp_cache_add_global_groups(['users','userlogins','usermeta','user_meta','useremail','userslugs','site-options']);
    $GLOBALS['wpdb']=new Seed_Export_Read_Wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    $GLOBALS['wpdb']->suppress_errors(true);
    $GLOBALS['wpdb']->set_prefix($cfg['prefix']); $GLOBALS['wpdb']->set_blog_id(1);
    $GLOBALS['wpdb']->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    if (get_option('home') !== $request['home'] || get_option('siteurl') !== $request['home']) { throw new RuntimeException('EXPORT_HOME_DIVERGENCE'); }
    define('WP_CONTENT_URL',$request['home'].'/wp-content'); define('WP_PLUGIN_URL',WP_CONTENT_URL.'/plugins');
    $GLOBALS['wp_roles']=new WP_Roles();
    register_post_type('attachment',['capability_type'=>'post','map_meta_cap'=>true,'public'=>true,
        '_builtin'=>true,'capabilities'=>['create_posts'=>'upload_files'],'rewrite'=>false,'query_var'=>false,
        'hierarchical'=>false,'supports'=>['title','author','comments']]);
    wp_set_current_user((int)$request['actor']);
    if (!current_user_can('manage_options')) { throw new RuntimeException('EXPORT_ACTOR_PERMISSION'); }
    $header=file_get_contents($web.'/wp-content/plugins/wp-seed-pixel/wp-seed-pixel.php',false,null,0,8192);
    if (!preg_match('/^\s*\*?\s*Version:\s*(\S+)/m',$header,$m)) { throw new RuntimeException('EXPORT_SOURCE_PIXEL_VERSION'); }
    define('WP_SEED_PIXEL_VERSION',$m[1]); define('WP_SEED_PIXEL_ENGINE_VERSION','0.3.1');
    foreach (['authority','files','color','analyzer','policy','job-store','master-adapter','storage-budget','master-storage',
        'quarantine','recovery-setup','migration-evidence','migration-binding','format-processor','format-conversion',
        'metadata','metadata-public-graph','metadata-graph-transaction','metadata-admin','store'] as $name) {
        $path=seed_export_plain($request['collector'].'/includes/class-'.$name.'.php');
        $loaded['pixel/'.$name]=hash_file('sha256',$path); require_once $path;
    }
    WP_Seed_Pixel_Recovery_Setup::apply();
    add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MAX);
    add_filter('pre_http_request',static fn()=>new WP_Error('EXPORT_HTTP_BLOCKED'),PHP_INT_MAX);
    // No default filters, theme, MU/plugin code, cache drop-in, init or shutdown actions are run.
    if (did_action('init') || did_action('plugins_loaded') || did_action('wp_loaded')) { throw new RuntimeException('EXPORT_BOOTSTRAP_OCCURRED'); }
    foreach (get_included_files() as $path) {
        if (str_starts_with($path,$web.'/wp-includes/')) {
            $relative=substr($path,strlen($web.'/wp-includes/'));
            $loaded[$relative]=hash_file('sha256',$path);
            if (isset($request['expected_core_sha256']) && ($request['expected_core_sha256'][$relative]??null)!==$loaded[$relative]) {
                throw new RuntimeException('EXPORT_UNCERTIFIED_CORE_DEFINITION');
            }
        }
    }
    return ['loaded_sha256'=>$loaded, 'source_pixel_version'=>WP_SEED_PIXEL_VERSION];
}
