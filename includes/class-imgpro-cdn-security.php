<?php
/**
 * ImgPro CDN Security Utilities
 *
 * Provides security helpers for nonce management, rate limiting,
 * and capability checks.
 *
 * @package ImgPro_CDN
 * @since   0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security utility class
 *
 * @since 0.2.0
 */
class ImgPro_CDN_Security {

    /**
     * Custom capability for plugin management
     *
     * @var string
     */
    const CAPABILITY = 'manage_imgpro_cdn';

    /**
     * Nonce action prefix
     *
     * @var string
     */
    const NONCE_PREFIX = 'imgpro_cdn_';

    /**
     * Rate limit window in seconds
     *
     * @var int
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Rate limit maximum requests per window
     *
     * @var int
     */
    const RATE_LIMIT_MAX = 30;

    /**
     * AJAX action nonce mappings
     * Maps AJAX action names to their nonce action strings
     *
     * @var array
     */
    private static $nonce_actions = [
        'imgpro_cdn_connect'         => 'connect',
        'imgpro_cdn_disconnect'      => 'connect',
        'imgpro_cdn_sync_status'     => 'sync',
        'imgpro_cdn_sync_step'       => 'sync',
        'imgpro_cdn_retry_failed'    => 'sync',
        'imgpro_cdn_resume_sync'     => 'sync',
        'imgpro_cdn_remove_all'      => 'remove_all',
        'imgpro_cdn_copy_attachment' => 'copy',
    ];

    /**
     * Get nonce action string for an AJAX action
     *
     * @param string $ajax_action The AJAX action name (e.g., 'imgpro_cdn_sync_step').
     * @return string Full nonce action string.
     */
    public static function get_nonce_action($ajax_action) {
        $action = self::$nonce_actions[$ajax_action] ?? 'default';
        return self::NONCE_PREFIX . $action;
    }

    /**
     * Create nonce for an AJAX action
     *
     * @param string $ajax_action The AJAX action name.
     * @return string Nonce string.
     */
    public static function create_nonce($ajax_action) {
        return wp_create_nonce(self::get_nonce_action($ajax_action));
    }

    /**
     * Verify nonce for an AJAX action
     *
     * @param string $nonce       The nonce to verify.
     * @param string $ajax_action The AJAX action name.
     * @return bool True if valid.
     */
    public static function verify_nonce($nonce, $ajax_action) {
        if (empty($nonce)) {
            return false;
        }
        return wp_verify_nonce($nonce, self::get_nonce_action($ajax_action));
    }

    /**
     * Check if current user has permission (capability check only)
     *
     * Sends JSON error and exits if validation fails.
     *
     * @return void
     */
    public static function check_permission() {
        if (!self::current_user_can()) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'bandwidth-saver'),
                'code' => 'permission_denied',
            ]);
        }
    }

    /**
     * Check if rate limit is exceeded for an action
     *
     * Uses transients to track request counts per user per action.
     *
     * @param string $action Action identifier.
     * @param int    $max    Maximum requests per window (optional).
     * @param int    $window Window in seconds (optional).
     * @return bool True if rate limited (should block), false if OK.
     */
    public static function is_rate_limited($action, $max = null, $window = null) {
        $max = $max ?? self::RATE_LIMIT_MAX;
        $window = $window ?? self::RATE_LIMIT_WINDOW;

        $user_id = get_current_user_id();
        $key = 'imgpro_rl_' . md5($action . '_' . $user_id);

        $data = get_transient($key);
        if (false === $data) {
            // First request - start tracking
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return false;
        }

        // Check if window has expired
        if (time() - $data['start'] >= $window) {
            // Window expired - reset
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return false;
        }

        // Increment count
        $data['count']++;
        set_transient($key, $data, $window);

        // Check if exceeded
        return $data['count'] > $max;
    }

    /**
     * Check rate limit and send error if exceeded
     *
     * @param string $action Action identifier.
     * @return void
     */
    public static function check_rate_limit($action) {
        if (self::is_rate_limited($action)) {
            wp_send_json_error([
                'message' => __('Too many requests. Please wait a moment and try again.', 'bandwidth-saver'),
                'code' => 'rate_limited',
            ], 429);
        }
    }

    /**
     * Check if current user has plugin management capability
     *
     * Falls back to manage_options if custom capability not set.
     *
     * @return bool True if user can manage plugin.
     */
    public static function current_user_can() {
        // Check custom capability first, fall back to manage_options
        return current_user_can(self::CAPABILITY) || current_user_can('manage_options');
    }

    /**
     * Capability the settings page is registered with
     *
     * Matches current_user_can(): the plugin's own capability when the user
     * has it, so a role granted only that capability can open the page its
     * notices link to, and manage_options otherwise (a site whose roles
     * were never granted it, such as a network site the plugin has not
     * caught up with yet).
     *
     * @return string
     */
    public static function menu_capability() {
        return current_user_can(self::CAPABILITY) ? self::CAPABILITY : 'manage_options';
    }

    /**
     * Grant plugin capability to administrators
     *
     * Call this on plugin activation.
     *
     * @return void
     */
    public static function grant_capability_to_admins() {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap(self::CAPABILITY);
        }
    }

    /**
     * Remove plugin capability from all roles
     *
     * Call this on plugin deactivation/uninstall.
     *
     * @return void
     */
    public static function remove_capability_from_all() {
        foreach (wp_roles()->roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap(self::CAPABILITY);
            }
        }
    }

    /**
     * Get all nonces for JavaScript localization
     *
     * @return array Array of nonce action => nonce value pairs.
     */
    public static function get_all_nonces() {
        $nonces = [];
        foreach (self::$nonce_actions as $ajax_action => $action) {
            // Use the short action name as key to avoid duplication
            $nonces[$action] = wp_create_nonce(self::NONCE_PREFIX . $action);
        }
        return $nonces;
    }
}
