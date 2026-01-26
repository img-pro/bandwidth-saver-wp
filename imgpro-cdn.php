<?php
/**
 * Plugin Name: Bandwidth Saver: Unlimited Media CDN
 * Plugin URI: https://github.com/img-pro/bandwidth-saver
 * Description: Unlimited media CDN for images, video, audio, and HLS streaming. $19.99/mo for unlimited bandwidth.
 * Version: 1.0
 * Author: ImgPro
 * Author URI: https://img.pro
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bandwidth-saver
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package ImgPro_CDN
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check WordPress version requirement
if (version_compare(get_bloginfo('version'), '6.2', '<')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Bandwidth Saver requires WordPress 6.2 or higher. Please update WordPress to use this plugin.', 'bandwidth-saver'); ?></p>
        </div>
        <?php
    });
    return;
}

// Check PHP version requirement
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Bandwidth Saver requires PHP 7.4 or higher. Contact your hosting provider to upgrade.', 'bandwidth-saver'); ?></p>
        </div>
        <?php
    });
    return;
}

// Define plugin constants
if (!defined('IMGPRO_CDN_VERSION')) {
    define('IMGPRO_CDN_VERSION', '1.0');
}
if (!defined('IMGPRO_CDN_PLUGIN_DIR')) {
    define('IMGPRO_CDN_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('IMGPRO_CDN_PLUGIN_URL')) {
    define('IMGPRO_CDN_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('IMGPRO_CDN_PLUGIN_FILE')) {
    define('IMGPRO_CDN_PLUGIN_FILE', __FILE__);
}
if (!defined('IMGPRO_CDN_PLUGIN_BASENAME')) {
    define('IMGPRO_CDN_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

// Load required files
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-crypto.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-security.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-api.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-settings.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-rewriter.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-plan-selector.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-onboarding.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-admin.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-admin-ajax.php';
require_once IMGPRO_CDN_PLUGIN_DIR . 'includes/class-imgpro-cdn-core.php';

// Initialize the plugin
add_action('plugins_loaded', ['ImgPro_CDN_Core', 'get_instance']);

// Activation and deactivation hooks
register_activation_hook(__FILE__, ['ImgPro_CDN_Core', 'activate']);
register_deactivation_hook(__FILE__, ['ImgPro_CDN_Core', 'deactivate']);

/**
 * Add version info to HTML output for easy production verification
 * Only adds on frontend when not in admin
 *
 * @since 0.1.0
 * @return void
 */
function imgpro_cdn_add_version_html() {
    if (!is_admin()) {
        echo "\n<!-- Media CDN by ImgPro v" . esc_attr(IMGPRO_CDN_VERSION) . " -->\n";
    }
}
add_action('wp_footer', 'imgpro_cdn_add_version_html', 999);

/**
 * Add version header to HTTP response for easy curl checking
 * Only adds on frontend when plugin is enabled
 *
 * @since 0.1.0
 * @return void
 */
function imgpro_cdn_add_version_header() {
    if (!is_admin() && !headers_sent()) {
        header('X-Image-CDN-Version: ' . sanitize_text_field(IMGPRO_CDN_VERSION));
    }
}
add_action('send_headers', 'imgpro_cdn_add_version_header');
