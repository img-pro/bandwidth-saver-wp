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
 * Remove plugin data for the current site
 *
 * @return void
 */
function imgpro_cdn_uninstall_site() {
    global $wpdb;

    // SECURITY: Remove custom capability from all roles
    ImgPro_CDN_Security::remove_capability_from_all();

    $options = [
        'imgpro_cdn_settings',
        'imgpro_cdn_version',
        'imgpro_cdn_db_version',
        'imgpro_cdn_sync_state',
        'imgpro_cdn_sync_lock',
        'imgpro_cdn_v2_notice',
    ];
    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('imgpro_cdn_usage');

    // Data left by 1.x, in case the plugin is removed before 2.0 ran its upgrade
    $batched_key = get_option('imgpro_cdn_batched_cache_key');
    if ($batched_key) {
        delete_transient($batched_key);
    }
    delete_option('imgpro_cdn_batched_cache_key');
    foreach (['imgpro_cdn_pending_payment', 'imgpro_cdn_tiers', 'imgpro_cdn_site_data', 'imgpro_cdn_payment_pending_recovery', 'imgpro_cdn_last_sync'] as $transient) {
        delete_transient($transient);
    }

    wp_clear_scheduled_hook('imgpro_cdn_sync');
    wp_clear_scheduled_hook('imgpro_cdn_sync_hourly');

    $table = $wpdb->prefix . 'imgpro_files';
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
}

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
            imgpro_cdn_uninstall_site();
            restore_current_blog();
        }

        $imgpro_page++;
    }
} else {
    imgpro_cdn_uninstall_site();
}

/**
 * Fires after plugin data is removed
 */
do_action('imgpro_cdn_after_uninstall');
