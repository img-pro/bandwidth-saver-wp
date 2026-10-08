<?php
/**
 * ImgPro CDN Settings Management
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings management class
 *
 * Handles storage, retrieval and validation of plugin settings.
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Settings {

    /**
     * Option key for storing settings
     *
     * @since 0.1.0
     * @var string
     */
    const OPTION_KEY = 'imgpro_cdn_settings';

    /**
     * Sync is running normally
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_NONE = '';

    /**
     * Sync paused: the App reached its plan's image limit
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_QUOTA = 'quota';

    /**
     * Sync paused: the API key was rejected or lacks write permission
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_AUTH = 'auth';

    /**
     * Sync paused: the App is paused, or blocked, and the key is App-wide
     * (img.pro answers both with app_suspended)
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_APP = 'app';

    /**
     * Sync paused: the App is blocked and refuses every write
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_BLOCKED = 'blocked';

    /**
     * Sync paused: the App's storage is being deleted
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_DELETING = 'deleting';

    /**
     * img.pro dashboard where users create Apps and API keys
     *
     * @since 2.0.0
     * @var string
     */
    const DASHBOARD_URL = 'https://img.pro/apps';

    /**
     * img.pro Billing, where the App's owner changes its plan
     *
     * @since 2.0.0
     * @var string
     */
    const BILLING_URL = 'https://img.pro/billing';

    /**
     * Default settings
     *
     * @since 0.1.0
     * @var array
     */
    private $defaults = [
        'api_key'      => '',    // Encrypted img.pro API key
        'enabled'      => false, // Serve synced images from img.pro
        'remote'       => true,  // Also copy images pages load from other websites
        'pause_reason' => '',    // Why sync is paused (see PAUSE_* constants)
        'pause_detail' => '',    // Message returned by img.pro when sync paused
        'removing'     => false, // Remove-all in progress
        'site_label'   => '',    // Label this site's images carry in img.pro, fixed at connect time
    ];

    /**
     * Cached settings
     *
     * @since 0.1.0
     * @var array|null
     */
    private $settings = null;

    /**
     * Get all settings
     *
     * @since 0.1.0
     * @return array
     */
    public function get_all() {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $stored = get_option(self::OPTION_KEY, []);
        $this->settings = wp_parse_args(is_array($stored) ? $stored : [], $this->defaults);

        return $this->settings;
    }

    /**
     * Get specific setting
     *
     * @since 0.1.0
     * @param string $key     Setting key.
     * @param mixed  $default Default value if not found.
     * @return mixed
     */
    public function get($key, $default = null) {
        $settings = $this->get_all();

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        return $default !== null ? $default : ($this->defaults[$key] ?? null);
    }

    /**
     * Update settings
     *
     * @since 0.1.0
     * @param array $new_settings New settings to merge.
     * @return bool
     */
    public function update($new_settings) {
        // Merge into the stored value, not this request's copy: a sync run
        // can last a minute while the admin changes settings elsewhere.
        $current = $this->refresh();
        $validated = $this->validate($new_settings);
        $updated = array_merge($current, $validated);

        // Settings are used on every page load, so autoload=true (default)
        $result = update_option(self::OPTION_KEY, $updated, true);
        $this->settings = null; // Clear cache

        /**
         * Fires after ImgPro CDN settings are updated
         *
         * @param array $updated The new settings
         * @param array $current The previous settings
         */
        do_action('imgpro_cdn_settings_updated', $updated, $current);

        return $result;
    }

    /**
     * Validate settings
     *
     * @since 0.1.0
     * @param array $settings Settings to validate.
     * @return array
     */
    public function validate($settings) {
        $validated = [];

        if (isset($settings['api_key'])) {
            $api_key = self::sanitize_api_key($settings['api_key']);
            // SECURITY: Encrypt API key before storage
            if ('' !== $api_key && !ImgPro_CDN_Crypto::is_encrypted($api_key)) {
                $api_key = ImgPro_CDN_Crypto::encrypt($api_key);
            }
            $validated['api_key'] = $api_key;
        }

        if (isset($settings['enabled'])) {
            $validated['enabled'] = (bool) $settings['enabled'];
        }

        if (isset($settings['remote'])) {
            $validated['remote'] = (bool) $settings['remote'];
        }

        if (isset($settings['pause_reason'])) {
            $reason = sanitize_key($settings['pause_reason']);
            if (in_array($reason, [self::PAUSE_NONE, self::PAUSE_QUOTA, self::PAUSE_AUTH, self::PAUSE_APP, self::PAUSE_BLOCKED, self::PAUSE_DELETING], true)) {
                $validated['pause_reason'] = $reason;
            }
        }

        if (isset($settings['pause_detail'])) {
            $validated['pause_detail'] = sanitize_text_field($settings['pause_detail']);
        }

        if (isset($settings['removing'])) {
            $validated['removing'] = (bool) $settings['removing'];
        }

        if (isset($settings['site_label'])) {
            $validated['site_label'] = self::clean_label($settings['site_label']);
        }

        return $validated;
    }

    /**
     * Sanitize an API key as pasted by the user
     *
     * Keys are opaque tokens; strip whitespace and anything outside
     * the URL-safe character set.
     *
     * @since 2.0.0
     * @param string $api_key Raw key.
     * @return string Sanitized key.
     */
    public static function sanitize_api_key($api_key) {
        if (!is_string($api_key)) {
            return '';
        }
        if (ImgPro_CDN_Crypto::is_encrypted($api_key)) {
            return $api_key;
        }
        return preg_replace('/[^A-Za-z0-9_\-\.]/', '', trim($api_key));
    }

    /**
     * Get decrypted API key
     *
     * SECURITY: API keys are stored encrypted. Use this method to get
     * the plaintext key for API requests.
     *
     * @since 0.2.0
     * @return string Decrypted API key or empty string.
     */
    public function get_api_key() {
        $encrypted_key = $this->get('api_key', '');

        if (empty($encrypted_key)) {
            return '';
        }

        return (string) ImgPro_CDN_Crypto::decrypt($encrypted_key);
    }

    /**
     * Whether an img.pro API key is stored
     *
     * @since 2.0.0
     * @return bool
     */
    public function is_connected() {
        return '' !== $this->get_api_key();
    }

    /**
     * Whether images should be served from img.pro on this request
     *
     * @since 2.0.0
     * @return bool
     */
    public function is_serving() {
        return $this->is_connected() && (bool) $this->get('enabled') && !$this->get('removing');
    }

    /**
     * Whether the background sync may upload files
     *
     * @since 2.0.0
     * @return bool
     */
    public function can_sync() {
        return $this->is_connected() && self::PAUSE_NONE === $this->get('pause_reason') && !$this->get('removing');
    }

    /**
     * Reset to defaults
     *
     * @since 0.1.0
     * @return bool
     */
    public function reset() {
        $this->settings = null;
        return update_option(self::OPTION_KEY, $this->defaults, true);
    }

    /**
     * Clear the settings cache
     *
     * Call this after direct update_option() calls to ensure
     * subsequent get_all() calls return fresh data.
     *
     * @since 0.1.0
     * @return void
     */
    public function clear_cache() {
        $this->settings = null;
    }

    /**
     * Reload settings from the database
     *
     * Reads the row itself: the settings are autoloaded, so get_option()
     * answers from this request's copy of the autoloaded options, which
     * persistent object caches also keep for the request. A sync run, and
     * update()'s read-merge-write, must see pauses, disconnects and removals
     * other requests saved since. Reloading through core would mean
     * dropping and reloading every autoloaded option on each call.
     *
     * @since 2.0.0
     * @return array Fresh settings.
     */
    public function refresh() {
        global $wpdb;

        $raw = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION_KEY)
        );
        $stored = null === $raw ? [] : maybe_unserialize($raw);
        $stored = is_array($stored) ? $stored : [];

        // Keep update_option() from comparing against a stale cached copy
        if (get_option(self::OPTION_KEY, []) !== $stored) {
            wp_cache_delete(self::OPTION_KEY, 'options');
            wp_cache_delete('alloptions', 'options');
        }

        $this->settings = wp_parse_args($stored, $this->defaults);
        return $this->settings;
    }

    /**
     * Whether the plugin is active on the site WordPress is working on
     *
     * Inside switch_to_blog() that site can be one where the plugin was
     * deactivated, which keeps its settings and file map but no longer
     * maintains them. Read from the options: is_plugin_active() is not
     * loaded on the frontend.
     *
     * @since 2.0.0
     * @return bool
     */
    public static function plugin_active_here() {
        if (in_array(IMGPRO_CDN_PLUGIN_BASENAME, (array) get_option('active_plugins', []), true)) {
            return true;
        }
        if (!is_multisite()) {
            return false;
        }
        $network = (array) get_site_option('active_sitewide_plugins', []);
        return isset($network[IMGPRO_CDN_PLUGIN_BASENAME]);
    }

    /**
     * Label identifying this site's images inside the img.pro App
     *
     * Several sites can share one App, so every upload is labelled
     * with the site's home URL (host plus path, no scheme). The label is
     * stored when the site connects, so moving the site to a new address
     * does not orphan the images it already uploaded.
     *
     * @since 2.0.0
     * @return string
     */
    public static function get_site_label() {
        $settings = get_option(self::OPTION_KEY, []);
        if (is_array($settings) && !empty($settings['site_label'])) {
            return (string) $settings['site_label'];
        }
        return self::current_site_label();
    }

    /**
     * Label for the site's current address
     *
     * Differs from get_site_label() after the site moves to a new
     * address, or on a copy of the site such as a staging site.
     *
     * @since 2.0.0
     * @return string
     */
    public static function current_site_label() {
        $home = (string) home_url();
        $label = strtolower(preg_replace('#^https?://#i', '', untrailingslashit($home)));
        /**
         * Filter the site label used to tag images in img.pro.
         *
         * @since 2.0.0
         * @param string $label Site label (host and path of home_url()).
         */
        $label = (string) apply_filters('imgpro_cdn_site_label', $label);

        return self::clean_label($label);
    }

    /**
     * Make a string safe to use as a label value
     *
     * img.pro refuses a label value with a comma, with whitespace at either
     * end, or longer than 128 characters as JavaScript counts them (UTF-16
     * units, so a character outside the Basic Multilingual Plane counts
     * twice). It is cut before it is trimmed, so a cut never leaves a space
     * at the end, and never inside a multibyte character.
     *
     * @since 2.0.0
     * @param string $label Raw label.
     * @return string
     */
    public static function clean_label($label) {
        $label = str_replace(',', '', sanitize_text_field((string) $label));

        $label = mb_substr($label, 0, 128);
        while ('' !== $label && mb_strlen($label) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $label) > 128) {
            $label = mb_substr($label, 0, -1);
        }

        // Trim as JavaScript does, which includes non-breaking spaces
        return (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $label);
    }
}
