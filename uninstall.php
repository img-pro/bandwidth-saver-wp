<?php
/**
 * ImgPro CDN Uninstall
 *
 * Removes all plugin data when uninstalled
 *
 * @package ImgPro_CDN
 */

// Exit if accessed directly or not in uninstall context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Fires before ImgPro CDN plugin data is deleted
 *
 * Allows other code to perform cleanup before uninstall
 */
do_action('imgpro_cdn_before_uninstall');

// Load the security class for capability removal
require_once plugin_dir_path(__FILE__) . 'includes/class-imgpro-cdn-security.php';

// SECURITY: Remove custom capability from all roles on main site
ImgPro_CDN_Security::remove_capability_from_all();

// Delete plugin options
delete_option('imgpro_cdn_settings');
delete_option('imgpro_cdn_version');

// Delete known transients
delete_transient('imgpro_cdn_pending_payment');
delete_transient('imgpro_cdn_tiers');
delete_transient('imgpro_cdn_site_data');
delete_transient('imgpro_cdn_payment_pending_recovery');
delete_transient('imgpro_cdn_last_sync');

// Short-lived transients that will expire naturally (no explicit cleanup needed):
// - imgpro_usage_* (5 min TTL) - usage analytics cache
// - imgpro_rl_* (60 sec TTL) - rate limiting
// - imgpro_cdn_pricing (legacy, no longer created)
//
// These have hashed keys and short TTLs, so we let WordPress handle expiration
// rather than running direct DB queries that could fail plugin check.

// For multisite installations
if (is_multisite()) {
    // Get all sites with pagination for better performance on large networks
    $imgpro_page = 1;
    $imgpro_per_page = 100;

    while (true) {
        $imgpro_sites = get_sites([
            'number' => $imgpro_per_page,
            'offset' => ($imgpro_page - 1) * $imgpro_per_page,
        ]);

        if (empty($imgpro_sites)) {
            break;
        }

        foreach ($imgpro_sites as $imgpro_site) {
            switch_to_blog($imgpro_site->blog_id);

            // SECURITY: Remove custom capability from all roles on this site
            ImgPro_CDN_Security::remove_capability_from_all();

            // Delete options for this site
            delete_option('imgpro_cdn_settings');
            delete_option('imgpro_cdn_version');

            // Delete transients for this site
            delete_transient('imgpro_cdn_pending_payment');
            delete_transient('imgpro_cdn_tiers');
            delete_transient('imgpro_cdn_site_data');
            delete_transient('imgpro_cdn_payment_pending_recovery');
            delete_transient('imgpro_cdn_last_sync');

            // Short-lived transients (imgpro_usage_*, imgpro_rl_*) expire naturally

            restore_current_blog();
        }

        $imgpro_page++;
    }
}

/**
 * Fires after ImgPro CDN plugin data is deleted
 *
 * Allows other code to perform final cleanup after uninstall
 */
do_action('imgpro_cdn_after_uninstall');
