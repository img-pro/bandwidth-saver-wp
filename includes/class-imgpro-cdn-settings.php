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
     * Sync paused: the App is suspended or blocked by img.pro
     *
     * @since 2.0.0
     * @var string
     */
    const PAUSE_APP = 'app';

    /**
     * img.pro dashboard where users create Apps and API keys
     *
     * @since 2.0.0
     * @var string
     */
    const DASHBOARD_URL = 'https://img.pro/apps';

    /**
     * Default settings
     *
     * @since 0.1.0
     * @var array
     */
    private $defaults = [
        'api_key'      => '',    // Encrypted img.pro API key
        'enabled'      => false, // Serve synced images from img.pro
        'pause_reason' => '',    // Why sync is paused (see PAUSE_* constants)
        'pause_detail' => '',    // Message returned by img.pro when sync paused
        'removing'     => false, // Remove-all in progress
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
        $current = $this->get_all();
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

        if (isset($settings['pause_reason'])) {
            $reason = sanitize_key($settings['pause_reason']);
            if (in_array($reason, [self::PAUSE_NONE, self::PAUSE_QUOTA, self::PAUSE_AUTH, self::PAUSE_APP], true)) {
                $validated['pause_reason'] = $reason;
            }
        }

        if (isset($settings['pause_detail'])) {
            $validated['pause_detail'] = sanitize_text_field($settings['pause_detail']);
        }

        if (isset($settings['removing'])) {
            $validated['removing'] = (bool) $settings['removing'];
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
     * Label identifying this site's images inside the img.pro App
     *
     * Several sites can share one App, so every upload is labelled
     * with the site's home URL (host plus path, no scheme).
     *
     * @since 2.0.0
     * @return string
     */
    public static function get_site_label() {
        $home = (string) home_url();
        $label = strtolower(preg_replace('#^https?://#i', '', untrailingslashit($home)));
        // Label values may not contain commas and are capped at 128 characters
        $label = str_replace(',', '', $label);

        /**
         * Filter the site label used to tag images in img.pro.
         *
         * @since 2.0.0
         * @param string $label Site label (host and path of home_url()).
         */
        $label = (string) apply_filters('imgpro_cdn_site_label', $label);

        return substr(trim($label), 0, 128);
    }
}
