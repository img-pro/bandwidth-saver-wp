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
 * Handles storage, retrieval, validation, and sanitization of plugin settings.
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
     * Setup mode: Cloud (Managed)
     *
     * @since 0.1.2
     * @var string
     */
    const MODE_CLOUD = 'cloud';

    /**
     * Setup mode: Cloudflare (Self-Hosted)
     *
     * @since 0.1.2
     * @var string
     */
    const MODE_CLOUDFLARE = 'cloudflare';

    /**
     * Subscription tier: None (not registered)
     *
     * @since 0.1.2
     * @var string
     */
    const TIER_NONE = 'none';

    /**
     * Subscription tier: Free
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_FREE = 'free';

    /**
     * Subscription tier: Lite (paid)
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_LITE = 'lite';

    /**
     * Subscription tier: Pro (paid)
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_PRO = 'pro';

    /**
     * Subscription tier: Business (paid, legacy)
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_BUSINESS = 'business';

    /**
     * Subscription tier: Unlimited (paid, $19.99/mo)
     *
     * The main paid tier with unlimited bandwidth and cache.
     *
     * @since 0.3.0
     * @var string
     */
    const TIER_UNLIMITED = 'unlimited';

    /**
     * Subscription tier: Active (legacy, maps to pro)
     *
     * @since 0.1.2
     * @var string
     */
    const TIER_ACTIVE = 'active';

    /**
     * Subscription tier: Cancelled
     *
     * @since 0.1.2
     * @var string
     */
    const TIER_CANCELLED = 'cancelled';

    /**
     * Subscription tier: Past due (payment failed, grace period)
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_PAST_DUE = 'past_due';

    /**
     * Subscription tier: Suspended (no access)
     *
     * @since 0.1.7
     * @var string
     */
    const TIER_SUSPENDED = 'suspended';

    /**
     * Free tier cache limit in bytes (5 GB)
     *
     * Cache is auto-managed via LRU eviction.
     *
     * @since 0.2.0
     * @var int
     */
    const FREE_CACHE_LIMIT = 5368709120;

    /**
     * Lite tier cache limit in bytes (25 GB)
     *
     * @since 0.2.0
     * @var int
     */
    const LITE_CACHE_LIMIT = 26843545600;

    /**
     * Pro tier cache limit in bytes (150 GB)
     *
     * @since 0.2.0
     * @var int
     */
    const PRO_CACHE_LIMIT = 161061273600;

    /**
     * Business tier cache limit in bytes (1 TB)
     *
     * @since 0.2.0
     * @var int
     */
    const BUSINESS_CACHE_LIMIT = 1099511627776;

    /**
     * Free tier bandwidth limit in bytes (100 GB)
     *
     * Bandwidth is the primary metric, resets monthly.
     *
     * @since 0.2.0
     * @var int
     */
    const FREE_BANDWIDTH_LIMIT = 107374182400;

    /**
     * Lite tier bandwidth limit in bytes (250 GB)
     *
     * @since 0.2.0
     * @var int
     */
    const LITE_BANDWIDTH_LIMIT = 268435456000;

    /**
     * Pro tier bandwidth limit in bytes (2 TB)
     *
     * @since 0.2.0
     * @var int
     */
    const PRO_BANDWIDTH_LIMIT = 2199023255552;

    /**
     * Business tier bandwidth limit in bytes (10 TB)
     *
     * @since 0.2.0
     * @var int
     */
    const BUSINESS_BANDWIDTH_LIMIT = 10995116277760;

    /**
     * API base URL for cloud services
     *
     * @since 0.1.3
     * @var string
     */
    const API_BASE_URL = 'https://cloud.wp.img.pro';

    /**
     * Default CDN domain for Cloud (Managed) mode
     *
     * Single-domain architecture: Worker serves images directly from R2.
     *
     * @since 0.1.5
     * @var string
     */
    const CLOUD_CDN_DOMAIN = 'px.img.pro';

    /**
     * Custom domain CNAME target for Cloud (Managed) mode
     *
     * Users point their custom domain CNAME to this target.
     *
     * @since 0.1.6
     * @var string
     */
    const CUSTOM_DOMAIN_TARGET = 'domains.img.pro';

    /**
     * Default settings
     *
     * @since 0.1.0
     * @var array
     */
    private $defaults = [
        // Legacy single enabled flag (kept for backwards compatibility)
        'enabled'            => false,
        'previously_enabled' => false,
        'setup_mode'         => '',

        // Per-mode enabled states (independent toggles)
        'cloud_enabled'      => false,
        'cloudflare_enabled' => false,

        // Cloud mode settings
        'cloud_api_key'   => '',
        'cloud_email'     => '',
        'cloud_tier'      => self::TIER_NONE,

        // Custom domain settings (Cloud mode only)
        'custom_domain'        => '',
        'custom_domain_status' => '', // pending_dns, pending_ssl, active, error

        // Usage stats (synced from Cloud API)
        // Bandwidth is primary metric (monthly reset)
        'bandwidth_used'     => 0,
        'bandwidth_limit'    => 0,
        'cache_limit'        => 0,
        'cache_hits'         => 0,
        'cache_misses'       => 0,
        'stats_updated_at'   => 0,

        // Onboarding state
        'onboarding_completed' => false,
        'onboarding_step'      => 1,
        'marketing_opt_in'     => false,

        // Cloudflare mode settings (single domain - worker serves images directly)
        'cdn_url'         => '',

        // Common settings
        'allowed_domains' => [],  // Deprecated - kept for backward compatibility
        'source_urls'     => [],  // Source URLs (origin domains) synced from API
        'debug_mode'      => false,
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
        $this->settings = wp_parse_args($stored, $this->defaults);

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

        // Auto-configure Cloud mode CDN URL (single domain architecture)
        if (self::MODE_CLOUD === $settings['setup_mode']) {
            if ('cdn_url' === $key) {
                // Use custom domain if active, otherwise default cloud domain
                if (!empty($settings['custom_domain']) && 'active' === $settings['custom_domain_status']) {
                    return $settings['custom_domain'];
                }
                return self::CLOUD_CDN_DOMAIN;
            }
        }

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

        // Setup mode (string: 'cloud' or 'cloudflare')
        if (isset($settings['setup_mode'])) {
            $mode = sanitize_text_field($settings['setup_mode']);
            if (in_array($mode, [self::MODE_CLOUD, self::MODE_CLOUDFLARE], true)) {
                $validated['setup_mode'] = $mode;
            }
        }

        // Enabled (boolean) - legacy, kept for backwards compatibility
        if (isset($settings['enabled'])) {
            $validated['enabled'] = (bool) $settings['enabled'];
        }

        // Per-mode enabled states (boolean)
        if (isset($settings['cloud_enabled'])) {
            $validated['cloud_enabled'] = (bool) $settings['cloud_enabled'];
        }
        if (isset($settings['cloudflare_enabled'])) {
            $validated['cloudflare_enabled'] = (bool) $settings['cloudflare_enabled'];
        }

        // Previously enabled (boolean) - legacy, kept for backwards compatibility
        if (isset($settings['previously_enabled'])) {
            $validated['previously_enabled'] = (bool) $settings['previously_enabled'];
        }

        // Cloud-specific fields
        if (isset($settings['cloud_api_key'])) {
            $api_key = sanitize_text_field($settings['cloud_api_key']);
            // SECURITY: Encrypt API key before storage
            // Skip encryption if already encrypted (prevents double-encryption)
            if (!empty($api_key) && !ImgPro_CDN_Crypto::is_encrypted($api_key)) {
                $api_key = ImgPro_CDN_Crypto::encrypt($api_key);
            }
            $validated['cloud_api_key'] = $api_key;
        }
        if (isset($settings['cloud_email'])) {
            $validated['cloud_email'] = sanitize_email($settings['cloud_email']);
        }
        if (isset($settings['cloud_tier'])) {
            $tier = sanitize_text_field($settings['cloud_tier']);
            if (in_array($tier, [self::TIER_NONE, self::TIER_FREE, self::TIER_LITE, self::TIER_PRO, self::TIER_BUSINESS, self::TIER_UNLIMITED, self::TIER_ACTIVE, self::TIER_CANCELLED, self::TIER_PAST_DUE, self::TIER_SUSPENDED], true)) {
                $validated['cloud_tier'] = $tier;
            }
        }

        // Usage stats (integers)
        // Bandwidth is primary metric (monthly), Cache is secondary (LRU-managed)
        if (isset($settings['bandwidth_used'])) {
            $validated['bandwidth_used'] = absint($settings['bandwidth_used']);
        }
        if (isset($settings['bandwidth_limit'])) {
            $validated['bandwidth_limit'] = absint($settings['bandwidth_limit']);
        }
        if (isset($settings['cache_limit'])) {
            $validated['cache_limit'] = absint($settings['cache_limit']);
        }
        if (isset($settings['cache_hits'])) {
            $validated['cache_hits'] = absint($settings['cache_hits']);
        }
        if (isset($settings['cache_misses'])) {
            $validated['cache_misses'] = absint($settings['cache_misses']);
        }
        if (isset($settings['stats_updated_at'])) {
            $validated['stats_updated_at'] = absint($settings['stats_updated_at']);
        }
        if (isset($settings['billing_period_start'])) {
            $validated['billing_period_start'] = absint($settings['billing_period_start']);
        }
        if (isset($settings['billing_period_end'])) {
            $validated['billing_period_end'] = absint($settings['billing_period_end']);
        }

        // Onboarding state
        if (isset($settings['onboarding_completed'])) {
            $validated['onboarding_completed'] = (bool) $settings['onboarding_completed'];
        }
        if (isset($settings['onboarding_step'])) {
            $step = absint($settings['onboarding_step']);
            $validated['onboarding_step'] = max(1, min(4, $step)); // Clamp 1-4
        }
        if (isset($settings['marketing_opt_in'])) {
            $validated['marketing_opt_in'] = (bool) $settings['marketing_opt_in'];
        }

        // Custom domain (Cloud mode only)
        if (isset($settings['custom_domain'])) {
            $validated['custom_domain'] = self::sanitize_domain($settings['custom_domain']);
        }
        if (isset($settings['custom_domain_status'])) {
            $status = sanitize_text_field($settings['custom_domain_status']);
            if (in_array($status, ['', 'pending_dns', 'pending_ssl', 'active', 'error'], true)) {
                $validated['custom_domain_status'] = $status;
            }
        }

        // CDN URL (domain only - single domain architecture)
        if (isset($settings['cdn_url'])) {
            $cdn_url = self::sanitize_domain($settings['cdn_url']);
            // SECURITY: Validate CDN URL format
            if (!empty($cdn_url) && !self::is_valid_cdn_url($cdn_url)) {
                // Invalid CDN URL - clear it to prevent misconfiguration
                $cdn_url = '';
            }
            $validated['cdn_url'] = $cdn_url;
        }

        // Allowed domains (array) - deprecated, kept for backward compatibility
        if (isset($settings['allowed_domains'])) {
            if (is_string($settings['allowed_domains'])) {
                $domains = array_map('trim', explode("\n", $settings['allowed_domains']));
            } else {
                $domains = (array) $settings['allowed_domains'];
            }

            $validated['allowed_domains'] = array_map(
                [self::class, 'sanitize_domain'],
                array_filter($domains)
            );
        }

        // Source URLs (array) - synced from API, used by rewriter
        if (isset($settings['source_urls'])) {
            $domains = (array) $settings['source_urls'];
            $validated['source_urls'] = array_map(
                [self::class, 'sanitize_domain'],
                array_filter($domains)
            );
        }

        // Debug mode (boolean)
        if (isset($settings['debug_mode'])) {
            $validated['debug_mode'] = (bool) $settings['debug_mode'];
        }

        return $validated;
    }

    /**
     * Sanitize domain name
     *
     * Removes:
     * - Protocol (http://, https://)
     * - Paths and trailing slashes
     * - Leading dots (normalizes .example.com to example.com)
     * - Multiple consecutive dots
     * - Invalid characters
     * Converts to lowercase
     * Validates basic domain format
     *
     * @since 0.1.0
     * @param string $domain Domain to sanitize.
     * @return string Sanitized domain or empty string if invalid.
     */
    public static function sanitize_domain($domain) {
        // Remove protocol
        $domain = preg_replace('#^https?://#i', '', $domain);

        // Remove port number if present
        $domain = preg_replace('#:\d+$#', '', $domain);

        // Remove trailing slashes and paths
        $domain = rtrim($domain, '/');
        $domain = preg_replace('#/.*$#', '', $domain);

        // Sanitize text
        $domain = sanitize_text_field($domain);

        // Remove leading dots (normalize .example.com to example.com)
        $domain = ltrim($domain, '.');

        // Remove multiple consecutive dots (security measure)
        $domain = preg_replace('/\.{2,}/', '.', $domain);

        // Remove trailing dots
        $domain = rtrim($domain, '.');

        // Convert to lowercase
        $domain = strtolower($domain);

        // SECURITY: Handle IDN/punycode domains
        // Convert internationalized domain names to ASCII punycode to prevent homograph attacks
        // Example: "exаmple.com" (with Cyrillic 'а') -> "xn--exmple-4uf.com"
        if (function_exists('idn_to_ascii') && !empty($domain)) {
            // Check if domain contains non-ASCII characters
            if (preg_match('/[^\x20-\x7E]/', $domain)) {
                // Convert to punycode (ASCII-compatible encoding)
                $ascii_domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($ascii_domain !== false) {
                    $domain = $ascii_domain;
                } else {
                    // IDN conversion failed - reject the domain
                    return '';
                }
            }
        }

        // Validate domain format (basic validation)
        // Allows: letters, numbers, hyphens, dots
        // Doesn't allow: spaces, special chars, etc.
        // Note: After IDN conversion, domain should be ASCII-only
        if (!empty($domain) && !preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $domain)) {
            // Invalid domain format
            return '';
        }

        return $domain;
    }

    /**
     * Validate CDN URL format
     *
     * SECURITY: Validates that a CDN URL is properly formatted and safe to use.
     * Prevents misconfiguration that could break image loading or create security issues.
     *
     * @since 0.2.0
     * @param string $cdn_url The CDN URL (domain only) to validate.
     * @return bool True if valid.
     */
    public static function is_valid_cdn_url($cdn_url) {
        // Must not be empty
        if (empty($cdn_url)) {
            return false;
        }

        // Must be a valid domain format (no protocol, path, or query)
        if (preg_match('#[:/\?\#]#', $cdn_url)) {
            return false;
        }

        // Must have at least one dot (TLD required)
        if (strpos($cdn_url, '.') === false) {
            return false;
        }

        // Must not be an IP address (prevents SSRF-like issues)
        if (filter_var($cdn_url, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Must not be a reserved/internal domain
        $reserved_patterns = [
            '/^localhost$/i',
            '/\.local$/i',
            '/\.internal$/i',
            '/\.test$/i',
            '/\.example$/i',
            '/\.invalid$/i',
            '/^127\.\d+\.\d+\.\d+$/',
            '/^10\.\d+\.\d+\.\d+$/',
            '/^192\.168\.\d+\.\d+$/',
            '/^172\.(1[6-9]|2[0-9]|3[01])\.\d+\.\d+$/',
        ];

        foreach ($reserved_patterns as $pattern) {
            if (preg_match($pattern, $cdn_url)) {
                return false;
            }
        }

        // Basic DNS format validation
        // Allows: letters, numbers, hyphens, dots
        // Each label must start and end with alphanumeric
        $labels = explode('.', $cdn_url);
        foreach ($labels as $label) {
            if (empty($label) || strlen($label) > 63) {
                return false;
            }
            // Label must start and end with alphanumeric
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $label)) {
                return false;
            }
        }

        return true;
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
        $settings = $this->get_all();
        $encrypted_key = $settings['cloud_api_key'] ?? '';

        if (empty($encrypted_key)) {
            return '';
        }

        // Decrypt if encrypted, otherwise return as-is (for backwards compatibility)
        return ImgPro_CDN_Crypto::decrypt($encrypted_key);
    }

    /**
     * Reset to defaults
     *
     * @since 0.1.0
     * @return bool
     */
    public function reset() {
        $this->settings = null;
        return update_option(self::OPTION_KEY, $this->defaults);
    }

    /**
     * Delete all settings
     *
     * @since 0.1.0
     * @return bool
     */
    public function delete() {
        $this->settings = null;
        return delete_option(self::OPTION_KEY);
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
     * Get default value for a setting
     *
     * @since 0.1.0
     * @param string $key Setting key.
     * @return mixed
     */
    public function get_default($key) {
        return $this->defaults[$key] ?? null;
    }

    /**
     * Get API base URL with filter support
     *
     * Static method to allow usage without instance.
     *
     * @since 0.1.3
     * @return string API base URL.
     */
    public static function get_api_base_url() {
        /**
         * Filter the API base URL for cloud services.
         *
         * Useful for testing or staging environments.
         *
         * @since 0.1.2
         * @param string $api_base_url The default API base URL.
         */
        return apply_filters('imgpro_cdn_api_base_url', self::API_BASE_URL);
    }

    /**
     * Check if a given mode has valid configuration
     *
     * Cloud mode requires free or paid subscription.
     * Cloudflare mode requires CDN URL to be configured (single domain architecture).
     *
     * @since 0.1.3
     * @param string $mode     The mode to check ('cloud' or 'cloudflare').
     * @param array  $settings The settings array to check against.
     * @return bool True if the mode is properly configured.
     */
    public static function is_mode_valid($mode, $settings) {
        if (self::MODE_CLOUD === $mode) {
            $tier = $settings['cloud_tier'] ?? '';
            // Valid tiers: free (trial), unlimited, lite, pro, business (legacy), active (legacy), past_due (grace period)
            return in_array($tier, [self::TIER_FREE, self::TIER_UNLIMITED, self::TIER_LITE, self::TIER_PRO, self::TIER_BUSINESS, self::TIER_ACTIVE, self::TIER_PAST_DUE], true);
        } elseif (self::MODE_CLOUDFLARE === $mode) {
            return !empty($settings['cdn_url']);
        }
        return false;
    }

    /**
     * Check if a specific mode is enabled
     *
     * Each mode has its own independent enabled state.
     *
     * @since 0.1.7
     * @param string $mode     The mode to check ('cloud' or 'cloudflare').
     * @param array  $settings The settings array to check against.
     * @return bool True if the mode is enabled.
     */
    public static function is_mode_enabled($mode, $settings) {
        if (self::MODE_CLOUD === $mode) {
            return !empty($settings['cloud_enabled']);
        } elseif (self::MODE_CLOUDFLARE === $mode) {
            return !empty($settings['cloudflare_enabled']);
        }
        return false;
    }

    /**
     * Check if CDN is currently active
     *
     * CDN is active when: current mode is valid AND that mode is enabled.
     * This determines whether image rewriting actually happens.
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if CDN is currently active.
     */
    public static function is_cdn_active($settings) {
        $mode = $settings['setup_mode'] ?? '';
        if (empty($mode)) {
            return false;
        }
        return self::is_mode_valid($mode, $settings) && self::is_mode_enabled($mode, $settings);
    }

    /**
     * Check if user has any paid subscription (unlimited or legacy tiers)
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if user has a paid subscription.
     */
    public static function is_paid($settings) {
        $tier = $settings['cloud_tier'] ?? '';
        // past_due still counts as paid (grace period)
        // unlimited is the primary paid tier, legacy tiers (lite, pro, business) still supported
        return in_array($tier, [self::TIER_UNLIMITED, self::TIER_LITE, self::TIER_PRO, self::TIER_BUSINESS, self::TIER_ACTIVE, self::TIER_PAST_DUE], true);
    }

    /**
     * Check if user has a paid subscription (alias for is_paid for backwards compatibility)
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if user has a paid subscription.
     */
    public static function is_pro($settings) {
        return self::is_paid($settings);
    }

    /**
     * Check if tier has custom domain feature
     *
     * All users get custom domain feature (single-tier model).
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool Always true - custom domains available to all users.
     */
    public static function has_custom_domain($settings) {
        // Single-tier model: everyone gets all features including custom domains
        return true;
    }

    /**
     * Check if user is on free tier (trial)
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if user is on free/trial tier.
     */
    public static function is_free($settings) {
        return self::TIER_FREE === ($settings['cloud_tier'] ?? '');
    }

    /**
     * Check if user is on trial tier (alias for is_free)
     *
     * The free tier is now branded as "Trial" in the UI.
     *
     * @since 0.3.0
     * @param array $settings The settings array to check against.
     * @return bool True if user is on trial tier.
     */
    public static function is_trial($settings) {
        return self::is_free($settings);
    }

    /**
     * Check if user is on unlimited tier
     *
     * @since 0.3.0
     * @param array $settings The settings array to check against.
     * @return bool True if user is on unlimited tier.
     */
    public static function is_unlimited($settings) {
        return self::TIER_UNLIMITED === ($settings['cloud_tier'] ?? '');
    }

    /**
     * Check if subscription is cancelled or suspended
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if subscription is cancelled or suspended.
     */
    public static function is_subscription_inactive($settings) {
        $tier = $settings['cloud_tier'] ?? '';
        return in_array($tier, [self::TIER_CANCELLED, self::TIER_SUSPENDED], true);
    }

    /**
     * Check if subscription needs attention (past due)
     *
     * @since 0.1.7
     * @param array $settings The settings array to check against.
     * @return bool True if subscription is past due.
     */
    public static function is_past_due($settings) {
        return self::TIER_PAST_DUE === ($settings['cloud_tier'] ?? '');
    }

    /**
     * Get bandwidth limit for current tier
     *
     * Bandwidth is tracked for reporting purposes only on unlimited tier.
     * Returns -1 for unlimited tier (no limit).
     *
     * @since 0.2.0
     * @param array $settings The settings array to check against.
     * @return int Bandwidth limit in bytes, -1 for unlimited.
     */
    public static function get_bandwidth_limit($settings) {
        $tier = $settings['cloud_tier'] ?? '';
        switch ($tier) {
            case self::TIER_UNLIMITED:
                return -1; // Unlimited
            case self::TIER_BUSINESS:
                return self::BUSINESS_BANDWIDTH_LIMIT;
            case self::TIER_PRO:
            case self::TIER_ACTIVE:
            case self::TIER_PAST_DUE:
                return self::PRO_BANDWIDTH_LIMIT;
            case self::TIER_LITE:
                return self::LITE_BANDWIDTH_LIMIT;
            case self::TIER_FREE:
                return self::FREE_BANDWIDTH_LIMIT;
            default:
                return 0;
        }
    }

    /**
     * Get bandwidth usage percentage
     *
     * Returns 0 for unlimited tier (no percentage applies).
     *
     * @since 0.2.0
     * @param array $settings The settings array to check against.
     * @return float Percentage of bandwidth used (0-100), 0 for unlimited.
     */
    public static function get_bandwidth_percentage($settings) {
        $limit = self::get_bandwidth_limit($settings);
        // Unlimited tier or invalid limit
        if ($limit <= 0) {
            return 0;
        }
        $used = $settings['bandwidth_used'] ?? 0;
        return min(100, ($used / $limit) * 100);
    }

    /**
     * Get cache limit for current tier
     *
     * Cache is auto-managed via LRU eviction.
     * Returns -1 for unlimited tier (no limit).
     *
     * @since 0.2.0
     * @param array $settings The settings array to check against.
     * @return int Cache limit in bytes, -1 for unlimited.
     */
    public static function get_cache_limit($settings) {
        $tier = $settings['cloud_tier'] ?? '';
        switch ($tier) {
            case self::TIER_UNLIMITED:
                return -1; // Unlimited
            case self::TIER_BUSINESS:
                return self::BUSINESS_CACHE_LIMIT;
            case self::TIER_PRO:
            case self::TIER_ACTIVE:
            case self::TIER_PAST_DUE:
                return self::PRO_CACHE_LIMIT;
            case self::TIER_LITE:
                return self::LITE_CACHE_LIMIT;
            case self::TIER_FREE:
                return self::FREE_CACHE_LIMIT;
            default:
                return 0;
        }
    }

    /**
     * Handle API error with action hook for logging
     *
     * Static method for error handling that fires an action hook.
     *
     * @since 0.1.3
     * @param WP_Error|array $error   Error object or error data.
     * @param string         $context Context for logging (e.g., 'checkout', 'recovery').
     * @return void
     */
    public static function handle_api_error($error, $context = '') {
        /**
         * Fires when an API error occurs.
         *
         * @since 0.1.0
         * @param WP_Error|array $error   Error object or error data.
         * @param string         $context Context for the error.
         */
        do_action('imgpro_cdn_api_error', $error, $context);
    }

    /**
     * Format bytes to human readable string
     *
     * @since 0.1.7
     * @param int $bytes Bytes to format.
     * @param int $precision Decimal precision.
     * @return string Formatted string (e.g., "1.5 GB").
     */
    public static function format_bytes($bytes, $precision = 1) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
