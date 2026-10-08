<?php
/**
 * Uninstall Bandwidth Saver
 *
 * Removes plugin options, scheduled events and the file map table.
 * Images already copied to img.pro stay in the user's img.pro App; the
 * settings page offers a button to delete them before uninstalling.
 *
 * @package ImgPro_CDN
 */

// Exit if accessed directly or not in uninstall context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Fires before plugin data is removed
 */
do_action('imgpro_cdn_before_uninstall');

// Load the security class for capability removal
require_once plugin_dir_path(__FILE__) . 'includes/class-imgpro-cdn-security.php';

/**
 * Whether another installed plugin shares this one's data names
 *
 * Unlimited CDN, the plugin Bandwidth Saver 1.x grew out of, keeps its
 * account under the same option, transient and capability names (and the
 * same main file name). Deleting them would disconnect its CDN.
 *
 * @return bool
 */
function imgpro_cdn_uninstall_names_shared() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach (array_keys(get_plugins()) as $imgpro_plugin) {
        if ('imgpro-cdn.php' === basename($imgpro_plugin) && dirname($imgpro_plugin) !== basename(__DIR__)) {
            return true;
        }
    }
    return false;
}

/**
 * Remove plugin data for the current site
 *
 * @param bool $shared Whether another plugin uses the shared names.
 * @return void
 */
function imgpro_cdn_uninstall_site($shared) {
    global $wpdb;

    // Names only Bandwidth Saver 2.0 uses
    foreach (['imgpro_cdn_db_version', 'imgpro_cdn_sync_state', 'imgpro_cdn_sync_lock', 'imgpro_cdn_v2_notice', 'imgpro_cdn_removal_leftover', 'imgpro_cdn_other_app_leftover', 'imgpro_cdn_activated', 'imgpro_cdn_caught_up'] as $option) {
        delete_option($option);
    }
    delete_transient('imgpro_cdn_usage');
    delete_transient('imgpro_cdn_install_retry');
    // Per-user transients kept a minute: Media Library copy results and
    // rate limits. Unlimited CDN's rate limits share the prefix, and losing
    // a minute-long count there is harmless.
    $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like('_transient_imgpro_cdn_copy_result_') . '%',
            $wpdb->esc_like('_transient_timeout_imgpro_cdn_copy_result_') . '%',
            $wpdb->esc_like('_transient_imgpro_rl_') . '%',
            $wpdb->esc_like('_transient_timeout_imgpro_rl_') . '%'
        )
    );

    wp_clear_scheduled_hook('imgpro_cdn_sync');
    wp_clear_scheduled_hook('imgpro_cdn_sync_hourly');

    $table = $wpdb->prefix . 'imgpro_files';
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));

    // Shared names: the settings only when they are 2.0's own
    $settings = get_option('imgpro_cdn_settings');
    $own      = is_array($settings) && array_key_exists('api_key', $settings) && !array_key_exists('cloud_api_key', $settings);
    if (!$shared || $own) {
        delete_option('imgpro_cdn_settings');
    }
    if ($shared) {
        return;
    }

    // SECURITY: Remove custom capability from all roles
    ImgPro_CDN_Security::remove_capability_from_all();
    delete_option('imgpro_cdn_version');

    // Data left by 1.x, in case the plugin is removed before 2.0 ran its upgrade
    $batched_key = get_option('imgpro_cdn_batched_cache_key');
    if ($batched_key) {
        delete_transient($batched_key);
    }
    delete_option('imgpro_cdn_batched_cache_key');
    foreach (['imgpro_cdn_pending_payment', 'imgpro_cdn_tiers', 'imgpro_cdn_site_data', 'imgpro_cdn_payment_pending_recovery', 'imgpro_cdn_last_sync'] as $transient) {
        delete_transient($transient);
    }
}

// This file runs inside a function (uninstall_plugin()), so globals are not in scope
global $wpdb;

$imgpro_shared = imgpro_cdn_uninstall_names_shared();

if (is_multisite()) {
    // Paginate to keep memory flat on large networks
    $imgpro_page = 1;
    $imgpro_per_page = 100;

    while (true) {
        $imgpro_sites = get_sites([
            'number' => $imgpro_per_page,
            'offset' => ($imgpro_page - 1) * $imgpro_per_page,
            'fields' => 'ids',
        ]);

        if (empty($imgpro_sites)) {
            break;
        }

        foreach ($imgpro_sites as $imgpro_site_id) {
            switch_to_blog($imgpro_site_id);
            imgpro_cdn_uninstall_site($imgpro_shared);
            restore_current_blog();
        }

        $imgpro_page++;
    }

    delete_site_option('imgpro_cdn_activated');

    // A subsite that activated the plugin on its own and was deleted later
    // left its table behind: drop the tables of sites that no longer exist
    $imgpro_tables = $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->base_prefix) . '%' . $wpdb->esc_like('_imgpro_files'))
    );
    foreach ($imgpro_tables as $imgpro_table) {
        if (preg_match('/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+)_imgpro_files$/', $imgpro_table, $imgpro_match) && !get_site((int) $imgpro_match[1])) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $imgpro_table));
        }
    }
} else {
    imgpro_cdn_uninstall_site($imgpro_shared);
}

/**
 * Fires after plugin data is removed
 */
do_action('imgpro_cdn_after_uninstall');
