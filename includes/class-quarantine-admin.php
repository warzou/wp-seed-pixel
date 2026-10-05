<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Quarantine_Admin {
    public static function boot() {
        if (!WP_Seed_Pixel_Quarantine::enabled()) { return; }
        add_action('admin_menu', static function () { add_media_page(__('Retained image versions', 'wp-seed-pixel'), __('Pixel - Retained versions', 'wp-seed-pixel'), 'manage_options', 'wp-seed-pixel-quarantine', array(__CLASS__, 'page')); });
        add_action('admin_post_wp_seed_pixel_quarantine', array(__CLASS__, 'submit'));
    }
    public static function submit() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options') || !WP_Seed_Pixel_Quarantine::enabled()) { wp_die(esc_html__('Permission denied.', 'wp-seed-pixel'), '', array('response' => 403)); }
        check_admin_referer('wp_seed_pixel_quarantine');
        $id = absint($_POST['job_id'] ?? 0); $action = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        $approval = array('version' => WP_Seed_Pixel_Quarantine::AUTHORIZATION, 'generation' => sanitize_text_field(wp_unslash($_POST['generation'] ?? '')),
            'irreversible' => isset($_POST['irreversible']) && $_POST['irreversible'] === '1');
        $r = WP_Seed_Pixel_Jobs::quarantine_action($id, $action, $approval, absint($_POST['item_id'] ?? 0));
        $code = is_wp_error($r) ? $r->get_error_code() : 'SUCCESS';
        wp_safe_redirect(add_query_arg(array('page' => 'wp-seed-pixel-quarantine', 'result' => $code), admin_url('upload.php'))); exit;
    }
    public static function page() {
        global $wpdb;
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap"><h1>' . esc_html__('Retained image versions', 'wp-seed-pixel') . '</h1>';
        echo '<p>' . esc_html__('Retained versions still occupy disk space. Permanent deletion removes Pixel restoration for that version.', 'wp-seed-pixel') . '</p>';
        echo '<p>' . esc_html__('Twenty retained operations per page. File space excludes database overhead; hosting quota remains unknown.', 'wp-seed-pixel') . '</p>';
        if ((int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { echo '</div>'; return; }
        if (isset($_GET['result'])) { echo '<p role="status">' . esc_html($_GET['result'] === 'SUCCESS' ? __('Operation completed.', 'wp-seed-pixel') : __('Operation stopped. Review the image state before trying again.', 'wp-seed-pixel')) . '</p>'; }
        $page = max(1, absint($_GET['results_page'] ?? 1));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' AND action IN ('replace','retire') AND stage IN ('retained','purged','rolled_back','recovery_required','purge_intent') ORDER BY id DESC LIMIT 20 OFFSET %d", ($page-1)*20), ARRAY_A);
        foreach ($rows as $item) {
            if (!current_user_can('edit_post', $item['attachment_id'])) { continue; }
            $v = WP_Seed_Pixel_Quarantine::inspect($item);
            if (is_wp_error($v)) { continue; }
            echo '<hr><h2>' . esc_html(get_the_title($item['attachment_id'])) . ' (#' . (int) $item['attachment_id'] . ')</h2>';
            echo '<p>' . esc_html($v['kind'] === 'preserved_original' ? __('Preserved full-resolution original', 'wp-seed-pixel') : __('Previous operational image', 'wp-seed-pixel')) . '</p>';
            echo '<dl>';
            foreach (array('quarantine_bytes' => __('Retained bytes', 'wp-seed-pixel'), 'potential_purge_bytes' => __('Recoverable space after permanent deletion', 'wp-seed-pixel'), 'net_reclaimed_bytes' => __('Net file space reclaimed', 'wp-seed-pixel')) as $k => $label) {
                echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($v[$k] === null ? __('Needs review', 'wp-seed-pixel') : number_format_i18n($v[$k]) . ' B') . '</dd>';
            }
            echo '</dl><p>' . esc_html($v['rollback_available'] ? __('Restoration available', 'wp-seed-pixel') : __('Restoration unavailable', 'wp-seed-pixel')) . '</p>';
            if (!$v['rollback_available']) { continue; }
            foreach (array('restore' => __('Restore this version', 'wp-seed-pixel'), 'purge' => __('Delete permanently', 'wp-seed-pixel')) as $action => $label) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('wp_seed_pixel_quarantine');
                foreach (array('action' => 'wp_seed_pixel_quarantine', 'operation' => $action, 'job_id' => $item['job_id'], 'item_id' => $item['id'], 'generation' => $v['generation']) as $k => $value) { echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($value) . '">'; }
                if ($action === 'purge') { echo '<p><label><input type="checkbox" name="irreversible" value="1" required> ' . esc_html__('I understand this version cannot be restored by Pixel after permanent deletion.', 'wp-seed-pixel') . '</label></p>'; }
                submit_button($label, 'secondary', 'submit', true); echo '</form>';
            }
        }
        if ($page > 1) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page'=>'wp-seed-pixel-quarantine','results_page'=>$page-1),admin_url('upload.php'))) . '">' . esc_html__('Previous','wp-seed-pixel') . '</a> '; }
        if (count($rows)===20) { echo '<a class="button" href="' . esc_url(add_query_arg(array('page'=>'wp-seed-pixel-quarantine','results_page'=>$page+1),admin_url('upload.php'))) . '">' . esc_html__('Next','wp-seed-pixel') . '</a>'; }
        echo '</div>';
    }
}
