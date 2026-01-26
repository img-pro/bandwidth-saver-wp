<?php
/**
 * ImgPro CDN Core
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin orchestrator class
 *
 * Implements singleton pattern to manage plugin lifecycle and coordinate
 * between Settings, Rewriter, and Admin components.
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Core {

    /**
     * Plugin instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Core|null
     */
    private static $instance = null;

    /**
     * Settings instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Rewriter instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Rewriter
     */
    private $rewriter;

    /**
     * Admin instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Admin|null
     */
    private $admin;

    /**
     * Admin AJAX handler instance
     *
     * @since 0.1.2
     * @var ImgPro_CDN_Admin_Ajax|null
     */
    private $admin_ajax;

    /**
     * Get plugin instance
     *
     * @since 0.1.0
     * @return ImgPro_CDN_Core
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     *
     * @since 0.1.0
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Initialize plugin
     *
     * @since 0.1.0
     * @return void
     */
    private function init() {
        // Initialize settings
        $this->settings = new ImgPro_CDN_Settings();

        // Initialize rewriter
        $this->rewriter = new ImgPro_CDN_Rewriter($this->settings);
        $this->rewriter->init();

        // Initialize admin (only in admin area)
        if (is_admin()) {
            $this->admin = new ImgPro_CDN_Admin($this->settings);
            $this->admin->register_hooks();

            $this->admin_ajax = new ImgPro_CDN_Admin_Ajax($this->settings);
            $this->admin_ajax->register_hooks();
        }

        // Register core hooks
        $this->register_hooks();
    }

    /**
     * Register plugin hooks
     *
     * @since 0.1.0
     * @return void
     */
    private function register_hooks() {
        // Add settings link to plugins page
        add_filter('plugin_action_links_' . IMGPRO_CDN_PLUGIN_BASENAME, [$this, 'add_action_links']);

        // Handle plugin upgrades
        add_action('admin_init', [$this, 'check_version']);

        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * Enqueue frontend assets
     *
     * Loads CSS to prevent broken image flash and JavaScript for
     * lazy loading handling, error fallback, and dynamic content support.
     *
     * @since 0.1.0
     * @return void
     */
    public function enqueue_frontend_assets() {
        // Only enqueue if CDN is active (mode-specific enabled state)
        if (!ImgPro_CDN_Settings::is_cdn_active($this->settings->get_all())) {
            return;
        }

        // Enqueue frontend CSS (prevents broken image flash)
        $css_file = IMGPRO_CDN_PLUGIN_DIR . 'assets/css/imgpro-cdn-frontend.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'imgpro-cdn-frontend',
                IMGPRO_CDN_PLUGIN_URL . 'assets/css/imgpro-cdn-frontend.css',
                [],
                IMGPRO_CDN_VERSION,
                'all'
            );
        }

        // Enqueue frontend JavaScript (lazy loading, error handling, dynamic content)
        $js_file = IMGPRO_CDN_PLUGIN_DIR . 'assets/js/imgpro-cdn.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'imgpro-cdn',
                IMGPRO_CDN_PLUGIN_URL . 'assets/js/imgpro-cdn.js',
                [],
                IMGPRO_CDN_VERSION,
                false // Load in header
            );

            // Pass config to JavaScript
            wp_localize_script('imgpro-cdn', 'imgproCdnConfig', [
                'debug' => $this->settings->get('debug_mode', false),
            ]);
        }
    }

    /**
     * Add action links to plugins page
     *
     * @since 0.1.0
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=imgpro-cdn-settings')),
            esc_html__('Settings', 'bandwidth-saver')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Check plugin version and run upgrades if needed
     *
     * @since 0.1.0
     * @return void
     */
    public function check_version() {
        $current_version = get_option('imgpro_cdn_version');

        if ($current_version !== IMGPRO_CDN_VERSION) {
            $this->upgrade($current_version);
            update_option('imgpro_cdn_version', IMGPRO_CDN_VERSION, false);
        }
    }

    /**
     * Run upgrade routines
     *
     * @since 0.1.0
     * @param string|false $old_version Previous version number or false if new install.
     * @return void
     */
    private function upgrade($old_version) {
        // SECURITY: Migrate plaintext API keys to encrypted storage
        // This runs on every upgrade to ensure all keys are encrypted
        $this->maybe_encrypt_api_key();

        /**
         * Fires after ImgPro CDN upgrade routines have completed
         *
         * @since 0.1.0
         * @param string|false $old_version Previous version number or false if new install.
         * @param string       $new_version New version number.
         */
        do_action('imgpro_cdn_upgraded', $old_version, IMGPRO_CDN_VERSION);
    }

    /**
     * Encrypt existing plaintext API key if needed
     *
     * SECURITY: Migrates plaintext API keys to encrypted storage.
     * Safe to run multiple times - skips already encrypted keys.
     *
     * @since 0.2.0
     * @return void
     */
    private function maybe_encrypt_api_key() {
        $settings = get_option(ImgPro_CDN_Settings::OPTION_KEY, []);

        if (empty($settings['cloud_api_key'])) {
            return;
        }

        // Skip if already encrypted
        if (ImgPro_CDN_Crypto::is_encrypted($settings['cloud_api_key'])) {
            return;
        }

        // Encrypt the API key
        $settings['cloud_api_key'] = ImgPro_CDN_Crypto::encrypt($settings['cloud_api_key']);

        // Save without triggering validation (already sanitized)
        update_option(ImgPro_CDN_Settings::OPTION_KEY, $settings);

        // Clear settings cache
        $this->settings->clear_cache();
    }

    /**
     * Plugin activation
     *
     * @since 0.1.0
     * @return void
     */
    public static function activate() {
        // Check capabilities (shouldn't be needed but defense in depth)
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // SECURITY: Grant custom capability to administrators
        ImgPro_CDN_Security::grant_capability_to_admins();

        // Store plugin version (autoload=false for performance)
        update_option('imgpro_cdn_version', IMGPRO_CDN_VERSION, false);

        /**
         * Fires after ImgPro CDN activation
         */
        do_action('imgpro_cdn_activated');
    }

    /**
     * Plugin deactivation
     *
     * @since 0.1.0
     * @return void
     */
    public static function deactivate() {
        // Check capabilities
        if (!current_user_can('activate_plugins')) {
            return;
        }

        /**
         * Fires after ImgPro CDN deactivation
         */
        do_action('imgpro_cdn_deactivated');
    }

    /**
     * Get settings instance
     *
     * @since 0.1.0
     * @return ImgPro_CDN_Settings Settings instance.
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get rewriter instance
     *
     * @since 0.1.0
     * @return ImgPro_CDN_Rewriter Rewriter instance.
     */
    public function get_rewriter() {
        return $this->rewriter;
    }

    /**
     * Get admin instance
     *
     * @since 0.1.0
     * @return ImgPro_CDN_Admin|null Admin instance or null if not in admin area.
     */
    public function get_admin() {
        return $this->admin;
    }

    /**
     * Prevent cloning
     *
     * @since 0.1.0
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     *
     * @since 0.1.0
     * @throws Exception When attempting to unserialize singleton.
     * @return void
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
