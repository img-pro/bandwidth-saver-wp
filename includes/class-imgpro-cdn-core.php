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
 * between Settings, Sync, Rewriter and Admin components.
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Core {

    /**
     * Option set when upgrading from 1.x, until the admin connects a key
     *
     * @since 2.0.0
     * @var string
     */
    const UPGRADE_NOTICE_OPTION = 'imgpro_cdn_v2_notice';

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
     * Sync instance
     *
     * @since 2.0.0
     * @var ImgPro_CDN_Sync
     */
    private $sync;

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
        $this->settings = new ImgPro_CDN_Settings();

        // Create the file map table on first load (covers multisite subsites)
        ImgPro_CDN_Files::maybe_install();

        $this->sync = new ImgPro_CDN_Sync($this->settings);
        $this->sync->register_hooks();

        $this->rewriter = new ImgPro_CDN_Rewriter($this->settings);
        $this->rewriter->init();

        if (is_admin()) {
            $this->admin = new ImgPro_CDN_Admin($this->settings, $this->sync);
            $this->admin->register_hooks();

            $this->admin_ajax = new ImgPro_CDN_Admin_Ajax($this->settings, $this->sync);
            $this->admin_ajax->register_hooks();
        }

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
        if ($old_version && version_compare($old_version, '2.0.0', '<')) {
            self::remove_legacy_data();
            $this->settings->reset();
            update_option(self::UPGRADE_NOTICE_OPTION, 1, false);
        }

        ImgPro_CDN_Files::install();

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
     * Delete data left by 1.x (managed CDN account, billing and usage caches)
     *
     * 2.0 is a clean break: the managed CDN and self-hosted worker are gone,
     * and sites connect their own img.pro App instead.
     *
     * @since 2.0.0
     * @return void
     */
    public static function remove_legacy_data() {
        $batched_key = get_option('imgpro_cdn_batched_cache_key');
        if ($batched_key) {
            delete_transient($batched_key);
        }
        delete_option('imgpro_cdn_batched_cache_key');

        foreach (['imgpro_cdn_pending_payment', 'imgpro_cdn_tiers', 'imgpro_cdn_site_data', 'imgpro_cdn_payment_pending_recovery', 'imgpro_cdn_last_sync'] as $transient) {
            delete_transient($transient);
        }
    }

    /**
     * Plugin activation
     *
     * @since 0.1.0
     * @return void
     */
    public static function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // SECURITY: Grant custom capability to administrators
        ImgPro_CDN_Security::grant_capability_to_admins();

        ImgPro_CDN_Files::install();

        /**
         * Fires after ImgPro CDN activation
         */
        do_action('imgpro_cdn_activated');
    }

    /**
     * Plugin deactivation
     *
     * Stops background syncing. Settings, the file map and the images on
     * img.pro are kept so reactivating picks up where it left off.
     *
     * @since 0.1.0
     * @return void
     */
    public static function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        ImgPro_CDN_Sync::unschedule();
        delete_option(ImgPro_CDN_Sync::LOCK_OPTION);

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
     * Get sync instance
     *
     * @since 2.0.0
     * @return ImgPro_CDN_Sync Sync instance.
     */
    public function get_sync() {
        return $this->sync;
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
