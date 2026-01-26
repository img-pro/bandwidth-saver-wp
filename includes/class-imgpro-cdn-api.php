<?php
/**
 * ImgPro CDN API Client
 *
 * Single point of contact for all API communication with the billing service.
 * Handles requests, responses, errors, and caching.
 *
 * @package ImgPro_CDN
 * @since   0.1.7
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Client class
 *
 * @since 0.1.7
 */
class ImgPro_CDN_API {

    /**
     * API base URL
     *
     * @var string
     */
    const BASE_URL = 'https://cloud.wp.img.pro';

    /**
     * Cache TTL in seconds (1 hour) - for static data like tiers
     *
     * @var int
     */
    const CACHE_TTL = 3600;

    /**
     * Cache TTL for usage data in seconds (5 minutes)
     * Usage data changes frequently, so shorter TTL keeps stats fresh
     *
     * @var int
     */
    const USAGE_CACHE_TTL = 300;

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    const TIMEOUT = 15;

    /**
     * Singleton instance
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * In-memory cache for current request
     *
     * @var array|null
     */
    private $site_cache = null;

    /**
     * API key for Bearer token authentication (v0.2.0+)
     *
     * @var string|null
     */
    private $api_key = null;

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {}

    /**
     * Set API key for Bearer token authentication
     *
     * This allows using modern v0.2.0+ endpoints with Bearer tokens.
     *
     * @since 0.2.0
     * @param string $api_key API key.
     * @return self Fluent interface.
     */
    public function set_api_key($api_key) {
        $this->api_key = $api_key;
        return $this;
    }

    /**
     * Get stored API key
     *
     * @since 0.2.0
     * @return string|null
     */
    public function get_api_key() {
        return $this->api_key;
    }

    // =========================================================================
    // PUBLIC API METHODS
    // =========================================================================

    /**
     * Get site data
     *
     * Primary method for fetching site information. Returns cached data
     * if available and not expired, otherwise fetches fresh data.
     *
     * @param string $api_key      Site API key.
     * @param bool   $force_refresh Force fresh fetch, ignoring cache.
     * @return array|WP_Error Site data array or error.
     */
    public function get_site($api_key, $force_refresh = false) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Check cache first (unless forcing refresh)
        if (!$force_refresh) {
            $cached = $this->get_cached_site();
            if ($cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token authentication (v0.2.0+)
        $this->set_api_key($api_key);

        // Fetch from API using modern endpoint
        // v0.2.0+: GET /api/site with Bearer token
        // Legacy: GET /api/sites/:api_key (fallback for errors)
        $response = $this->request('GET', '/api/site');

        if (is_wp_error($response)) {
            return $response;
        }

        // Extract site data from response
        $site = $response['site'] ?? $response;
        $this->cache_site($site);

        return $site;
    }

    /**
     * Get full site data in a single batched request
     *
     * Fetches site info, domains, and optionally tiers in one API call.
     * This is more efficient than calling get_site() + get_source_urls() + get_tiers()
     * separately, especially on admin page load.
     *
     * @since 0.2.2
     * @param string $api_key       Site API key.
     * @param array  $include       Data to include: 'domains', 'tiers', 'usage'. Default: ['domains'].
     * @param bool   $force_refresh Force fresh fetch, ignoring cache.
     * @return array|WP_Error Site data array with requested includes, or error.
     */
    public function get_site_with_includes($api_key, $include = ['domains'], $force_refresh = false) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Build cache key based on includes
        $cache_key = 'imgpro_site_' . md5($api_key . implode(',', $include));

        // Check cache first (unless forcing refresh)
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token authentication
        $this->set_api_key($api_key);

        // Build include parameter
        $include_param = implode(',', array_map('sanitize_key', $include));

        // Fetch from /api/site with includes
        $response = $this->request('GET', '/api/site', ['include' => $include_param]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Cache the full response - use shorter TTL when usage data is included
        $ttl = in_array('usage', $include, true) ? self::USAGE_CACHE_TTL : self::CACHE_TTL;

        // Cache the site data separately for get_site() compatibility
        if (isset($response['site'])) {
            $this->cache_site($response['site'], $ttl);
        }
        set_transient($cache_key, $response, $ttl);

        return $response;
    }

    /**
     * Get site data with optional includes (alias for get_site_with_includes)
     *
     * @deprecated Use get_site_with_includes() instead
     * @param string $api_key       Site API key.
     * @param array  $include       Data to include.
     * @param bool   $force_refresh Force fresh fetch.
     * @return array|WP_Error Site data or error.
     */
    public function get_site_full($api_key, $include = ['domains'], $force_refresh = false) {
        return $this->get_site_with_includes($api_key, $include, $force_refresh);
    }

    /**
     * Request account recovery (step 1)
     *
     * Sends a verification code to the registered email address.
     *
     * @since 0.1.9
     * @param string $site_url WordPress site URL.
     * @return array|WP_Error Response with email_hint or error.
     */
    public function request_recovery($site_url) {
        if (empty($site_url)) {
            return new WP_Error('missing_site_url', __('Site URL is required', 'bandwidth-saver'));
        }

        // Public endpoint - clear any stale API key
        $this->set_api_key(null);

        return $this->request('POST', '/api/recovery/request', [
            'site_url' => $site_url,
        ]);
    }

    /**
     * Verify recovery code (step 2)
     *
     * Verifies the code sent to email and returns site data if valid.
     *
     * @since 0.1.9
     * @param string $site_url WordPress site URL.
     * @param string $code     6-digit verification code from email.
     * @return array|WP_Error Site data array or error.
     */
    public function verify_recovery($site_url, $code) {
        if (empty($site_url)) {
            return new WP_Error('missing_site_url', __('Site URL is required', 'bandwidth-saver'));
        }
        if (empty($code)) {
            return new WP_Error('missing_code', __('Verification code is required', 'bandwidth-saver'));
        }

        // Public endpoint - clear any stale API key
        $this->set_api_key(null);

        $response = $this->request('POST', '/api/recovery/verify', [
            'site_url' => $site_url,
            'code'     => $code,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $site = $response['site'] ?? $response;
        $this->cache_site($site);

        return $site;
    }

    /**
     * Create new site account
     *
     * Email is optional for free accounts. If the site_url already exists:
     * - If existing site has NO email: returns existing site (reconnection)
     * - If existing site HAS email: returns conflict (requires recovery)
     *
     * @param string|null $email          User email (optional for free tier).
     * @param string      $site_url       WordPress site URL.
     * @param bool        $marketing_opt_in Marketing consent.
     * @return array|WP_Error Site data array or error.
     */
    public function create_site($email, $site_url, $marketing_opt_in = false) {
        if (empty($site_url)) {
            return new WP_Error('missing_fields', __('Site URL is required', 'bandwidth-saver'));
        }

        // Public endpoint - clear any stale API key
        $this->set_api_key(null);

        $data = [
            'site_url'         => $site_url,
            'marketing_opt_in' => $marketing_opt_in,
        ];

        // Only include email if provided
        if (!empty($email)) {
            $data['email'] = $email;
        }

        $response = $this->request('POST', '/api/sites', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        $site = $response['site'] ?? $response;

        // Check if this was a reconnection (existing account without email)
        $reconnected = !empty($response['reconnected']);
        if ($reconnected) {
            $site['_reconnected'] = true;
        }

        $this->cache_site($site);

        return $site;
    }

    /**
     * Create Stripe checkout session for upgrade
     *
     * @param string $api_key Site API key.
     * @param string $tier_id Target tier ID (default: 'unlimited').
     * @return array|WP_Error Checkout data with URL or error.
     */
    public function create_checkout($api_key, $tier_id = 'unlimited') {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        return $this->request('POST', '/api/checkout', [
            'tier_id' => $tier_id,
        ]);
    }

    /**
     * Create Stripe billing portal session
     *
     * @param string $api_key Site API key.
     * @return array|WP_Error Portal data with URL or error.
     */
    public function create_portal($api_key) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        return $this->request('POST', '/api/portal');
    }

    /**
     * Set custom domain
     *
     * @param string $api_key Site API key.
     * @param string $domain  Custom domain name.
     * @return array|WP_Error Domain data or error.
     */
    public function set_domain($api_key, $domain) {
        if (empty($api_key) || empty($domain)) {
            return new WP_Error('missing_fields', __('API key and domain are required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('PUT', '/api/domain', [
            'domain' => $domain,
        ]);

        if (!is_wp_error($response)) {
            $this->invalidate_cache();
        }

        return $response;
    }

    /**
     * Get custom domain status
     *
     * Always fetches fresh data (not cached) since domain verification
     * status can change at any time.
     *
     * @param string $api_key Site API key.
     * @return array|WP_Error Domain status data or error.
     */
    public function get_domain($api_key) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        return $this->request('GET', '/api/domain');
    }

    /**
     * Remove custom domain
     *
     * @param string $api_key Site API key.
     * @return array|WP_Error Success response or error.
     */
    public function remove_domain($api_key) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('DELETE', '/api/domain');

        if (!is_wp_error($response)) {
            $this->invalidate_cache();
        }

        return $response;
    }

    /**
     * Get source URLs (origin domains)
     *
     * Retrieves the list of allowed source domains for this site.
     * These are the origins that the CDN will proxy images from.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @return array|WP_Error Array of source URLs or error.
     */
    public function get_source_urls($api_key, $full_response = false) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('GET', '/api/source-urls');

        if (is_wp_error($response)) {
            return $response;
        }

        // Return full response if requested (includes count, max_domains, tier_name)
        if ($full_response) {
            return $response;
        }

        return $response['domains'] ?? [];
    }

    /**
     * Add source URL (origin domain)
     *
     * Adds a new allowed source domain for this site.
     * Subject to tier limits (Free: 1, Lite: 3, Pro: 5, Business: 10).
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param string $domain  Domain to add (e.g., "cdn.example.com").
     * @return array|WP_Error Success response or error.
     */
    public function add_source_url($api_key, $domain) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        if (empty($domain)) {
            return new WP_Error('missing_domain', __('Domain is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('POST', '/api/source-urls', [
            'domain' => $domain,
        ]);

        if (!is_wp_error($response)) {
            $this->invalidate_cache();
        }

        return $response;
    }

    /**
     * Remove source URL (origin domain)
     *
     * Removes an allowed source domain from this site.
     * Cannot remove the primary domain.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param string $domain  Domain to remove.
     * @return array|WP_Error Success response or error.
     */
    public function remove_source_url($api_key, $domain) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        if (empty($domain)) {
            return new WP_Error('missing_domain', __('Domain is required', 'bandwidth-saver'));
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('DELETE', '/api/source-urls/' . rawurlencode($domain));

        if (!is_wp_error($response)) {
            $this->invalidate_cache();
        }

        return $response;
    }

    /**
     * Get usage insights
     *
     * Fetches comprehensive usage analytics including projections,
     * average daily usage, and historical data.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param bool   $use_cache Use cached data if available (default: true).
     * @return array|WP_Error Usage insights or error.
     */
    public function get_usage_insights($api_key, $use_cache = true) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        // Check cache
        if ($use_cache) {
            $cache_key = 'imgpro_insights_' . md5($api_key);
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('GET', '/api/usage/insights');

        if (is_wp_error($response)) {
            return $response;
        }

        // Cache for 5 minutes (insights change less frequently)
        if ($use_cache) {
            set_transient($cache_key, $response, 300);
        }

        return $response;
    }

    /**
     * Get daily usage rollups
     *
     * Fetches daily aggregated usage data for charts.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param int    $days    Number of days to fetch (max 90, default 30).
     * @param bool   $use_cache Use cached data if available (default: true).
     * @return array|WP_Error Daily usage data or error.
     */
    public function get_daily_usage($api_key, $days = 30, $use_cache = true) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        $days = min(90, max(1, intval($days)));

        // Check cache
        if ($use_cache) {
            $cache_key = 'imgpro_daily_' . md5($api_key . '_' . $days);
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('GET', '/api/usage/daily', ['days' => $days]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Cache for 5 minutes
        if ($use_cache) {
            set_transient($cache_key, $response, 300);
        }

        return $response;
    }

    /**
     * Get usage analytics data (insights + daily chart)
     *
     * Uses /api/site?include=usage to fetch both insights and daily chart data
     * in a single request, reducing page load API calls.
     *
     * @since 0.2.2
     * @param string $api_key   Site API key.
     * @param int    $days      Number of days for daily chart (max 90, default 30).
     * @param bool   $use_cache Use cached data if available (default: true).
     * @return array|WP_Error Usage analytics data or error.
     */
    public function get_usage_analytics($api_key, $days = 30, $use_cache = true) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        $days = min(90, max(1, intval($days)));

        // Check cache
        if ($use_cache) {
            $cache_key = 'imgpro_usage_' . md5($api_key . '_' . $days);
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token
        $this->set_api_key($api_key);

        // Use /api/site?include=usage instead of dedicated endpoint
        $response = $this->request('GET', '/api/site', [
            'include' => 'usage',
            'days' => $days,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Transform response to match expected format
        // /api/site?include=usage returns: { site: {...}, usage: { insights: {...}, daily: {...} } }
        $result = [
            'insights' => $response['usage']['insights'] ?? [],
            'daily' => $response['usage']['daily'] ?? [],
        ];

        // Cache for 5 minutes
        if ($use_cache) {
            set_transient($cache_key, $result, 300);
        }

        return $result;
    }

    /**
     * Get hourly usage data
     *
     * Fetches hourly usage data for detailed analysis.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param int    $hours   Number of hours to fetch (max 720, default 168).
     * @param bool   $use_cache Use cached data if available (default: true).
     * @return array|WP_Error Hourly usage data or error.
     */
    public function get_hourly_usage($api_key, $hours = 168, $use_cache = true) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        $hours = min(720, max(1, intval($hours)));

        // Check cache
        if ($use_cache) {
            $cache_key = 'imgpro_hourly_' . md5($api_key . '_' . $hours);
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('GET', '/api/usage/hourly', ['hours' => $hours]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Cache for 5 minutes
        if ($use_cache) {
            set_transient($cache_key, $response, 300);
        }

        return $response;
    }

    /**
     * Get historical billing periods
     *
     * Fetches archived billing period data for history.
     *
     * @since 0.2.0
     * @param string $api_key Site API key.
     * @param int    $limit   Number of periods to fetch (max 100, default 12).
     * @param bool   $use_cache Use cached data if available (default: true).
     * @return array|WP_Error Historical periods or error.
     */
    public function get_usage_periods($api_key, $limit = 12, $use_cache = true) {
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('API key is required', 'bandwidth-saver'));
        }

        $limit = min(100, max(1, intval($limit)));

        // Check cache
        if ($use_cache) {
            $cache_key = 'imgpro_periods_' . md5($api_key . '_' . $limit);
            $cached = get_transient($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        // Set API key for Bearer token (v0.2.0+)
        $this->set_api_key($api_key);

        $response = $this->request('GET', '/api/usage/periods', ['limit' => $limit]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Cache for 1 hour (historical data doesn't change)
        if ($use_cache) {
            set_transient($cache_key, $response, 3600);
        }

        return $response;
    }

    // =========================================================================
    // DATA HELPERS
    // =========================================================================

    /**
     * Get all available tiers
     *
     * Returns cached tiers or fetches from API.
     *
     * @param bool $force_refresh Force fresh fetch.
     * @return array Tiers data.
     */
    public function get_tiers($force_refresh = false) {
        // Check cache first
        if (!$force_refresh) {
            $cached = get_transient('imgpro_cdn_tiers');
            if (false !== $cached) {
                return $cached;
            }
        }

        // Public endpoint - clear any stale API key
        $this->set_api_key(null);

        // Fetch from API
        $response = $this->request('GET', '/api/tiers');

        if (is_wp_error($response)) {
            // Return fallback tiers
            return $this->get_fallback_tiers();
        }

        $tiers = $response['tiers'] ?? [];

        // Normalize tiers: map 'storage' to 'cache' for backwards compatibility
        $tiers = array_map([$this, 'normalize_tier'], $tiers);

        set_transient('imgpro_cdn_tiers', $tiers, self::CACHE_TTL);

        return $tiers;
    }

    /**
     * Normalize tier data for backwards compatibility
     *
     * Maps 'storage' to 'cache' in limits for API responses
     * that haven't been updated yet.
     *
     * @param array $tier Raw tier data.
     * @return array Normalized tier data.
     */
    private function normalize_tier($tier) {
        // Map storage -> cache if cache doesn't exist
        if (isset($tier['limits']['storage']) && !isset($tier['limits']['cache'])) {
            $tier['limits']['cache'] = $tier['limits']['storage'];
            unset($tier['limits']['storage']);
        }
        return $tier;
    }

    /**
     * Get fallback tiers when API is unavailable
     *
     * Returns Trial + Unlimited tiers for the new pricing model.
     *
     * @return array Fallback tiers.
     */
    private function get_fallback_tiers() {
        // Single-tier model: all users get the same features regardless of payment status
        return [
            [
                'id' => 'free',
                'name' => 'Media CDN',
                'description' => 'Media CDN Service',
                'highlight' => false,
                'price' => ['cents' => 0, 'formatted' => 'Free', 'period' => null],
                'limits' => [
                    'bandwidth' => ['bytes' => null, 'formatted' => 'Unlimited', 'unlimited' => true],
                    'cache' => ['bytes' => null, 'formatted' => 'Unlimited', 'unlimited' => true],
                    'domains' => ['max' => null, 'unlimited' => true],
                ],
                'features' => ['custom_domain' => true, 'priority_support' => false, 'video_support' => true, 'audio_support' => true],
            ],
            [
                'id' => 'unlimited',
                'name' => 'Media CDN',
                'description' => 'Media CDN Service',
                'highlight' => true,
                'price' => ['cents' => 1999, 'formatted' => '$19.99', 'period' => '/mo'],
                'limits' => [
                    'bandwidth' => ['bytes' => null, 'formatted' => 'Unlimited', 'unlimited' => true],
                    'cache' => ['bytes' => null, 'formatted' => 'Unlimited', 'unlimited' => true],
                    'domains' => ['max' => null, 'unlimited' => true],
                ],
                'features' => ['custom_domain' => true, 'priority_support' => true, 'video_support' => true, 'audio_support' => true],
            ],
        ];
    }

    /**
     * Get pricing information
     *
     * Returns pricing from cached site data, or fallback defaults.
     * No longer uses a separate transient - derives from site_data['tier']['price'].
     *
     * @return array Pricing data.
     */
    public function get_pricing() {
        // Extract from cached site data
        $site = $this->get_cached_site();
        if ($site && isset($site['tier']['price'])) {
            return $this->format_pricing($site['tier']['price']);
        }

        // Fallback defaults (Unlimited tier pricing)
        return [
            'amount'    => 1999,
            'currency'  => 'USD',
            'interval'  => 'month',
            'formatted' => [
                'amount' => '$19.99',
                'period' => '/mo',
                'full'   => '$19.99/mo',
            ],
        ];
    }

    /**
     * Extract tier ID from site data
     *
     * @param array $site Site data.
     * @return string Tier ID.
     */
    public function get_tier_id($site) {
        if (isset($site['tier']['id'])) {
            return $site['tier']['id'];
        }
        if (isset($site['tier']) && is_string($site['tier'])) {
            return $site['tier'];
        }
        return 'free';
    }

    /**
     * Extract usage data from site
     *
     * Bandwidth is the primary metric (resets monthly).
     * Cache is secondary (LRU-managed, auto-regulated).
     *
     * @param array $site Site data.
     * @return array Normalized usage data.
     */
    public function get_usage($site) {
        $usage = $site['usage'] ?? [];

        return [
            'bandwidth_used'   => $usage['bandwidth']['used_bytes'] ?? 0,
            'bandwidth_limit'  => $usage['bandwidth']['limit_bytes'] ?? 0,
            'cache_limit'      => $usage['cache']['limit_bytes'] ?? 0,
            'cache_hits'       => $usage['cache_hits'] ?? 0,
            'cache_misses'     => $usage['cache_misses'] ?? 0,
            'period_start'     => $usage['period_start'] ?? null,
            'period_end'       => $usage['period_end'] ?? null,
        ];
    }

    /**
     * Extract custom domain data from site
     *
     * @param array $site Site data.
     * @return array|null Domain data or null if not configured.
     */
    public function get_custom_domain($site) {
        $domain = $site['custom_domain'] ?? null;

        if (!$domain || !is_array($domain) || empty($domain['domain'])) {
            return null;
        }

        return [
            'domain' => $domain['domain'],
            'status' => $domain['status'] ?? 'pending_dns',
        ];
    }

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Get cached site data
     *
     * @return array|null Cached site data or null.
     */
    public function get_cached_site() {
        // Check in-memory cache first (same request)
        if (null !== $this->site_cache) {
            return $this->site_cache;
        }

        // Check transient cache
        $cached = get_transient('imgpro_cdn_site_data');
        if (false !== $cached) {
            $this->site_cache = $cached;
            return $cached;
        }

        return null;
    }

    /**
     * Invalidate all cached data
     *
     * Call this after mutations that change site state.
     */
    public function invalidate_cache() {
        $this->site_cache = null;
        delete_transient('imgpro_cdn_site_data');
        delete_transient('imgpro_cdn_last_sync');
    }

    /**
     * Check if cache is fresh
     *
     * @return bool True if cache exists and is not expired.
     */
    public function is_cache_fresh() {
        return false !== get_transient('imgpro_cdn_site_data');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Make HTTP request to API
     *
     * @param string     $method   HTTP method (GET, POST, PUT, DELETE).
     * @param string     $endpoint API endpoint path.
     * @param array|null $data     Request data (query params for GET, body for others).
     * @return array|WP_Error Response data or error.
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->get_base_url() . $endpoint;

        $user_agent = $this->get_user_agent();

        $args = [
            'method'     => $method,
            'timeout'    => self::TIMEOUT,
            'user-agent' => $user_agent, // WordPress-specific key
            'headers'    => [
                'Content-Type'        => 'application/json',
                'Accept'              => 'application/json',
                'X-Plugin-User-Agent' => $user_agent, // Backup header
            ],
        ];

        // Add Bearer token authentication if API key is set (v0.2.0+)
        if ($this->api_key) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
        }

        // GET requests: add data as query params
        if ('GET' === $method && $data) {
            $url = add_query_arg(array_map('rawurlencode', $data), $url);
        }
        // Other methods: add data as JSON body
        elseif ($data) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        // Handle connection errors
        if (is_wp_error($response)) {
            do_action('imgpro_cdn_api_error', $response, $endpoint);
            return new WP_Error(
                'connection_error',
                __('Could not connect to the service. Please try again.', 'bandwidth-saver'),
                ['original' => $response]
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        // Handle JSON decode errors
        if (null === $body && '' !== $raw_body) {
            $json_error = json_last_error_msg();
            do_action('imgpro_cdn_api_error', [
                'status'   => $status_code,
                'message'  => 'Invalid JSON response: ' . $json_error,
                'endpoint' => $endpoint,
            ], $endpoint);

            return new WP_Error(
                'json_error',
                __('Invalid response from server. Please try again.', 'bandwidth-saver'),
                [
                    'status'    => $status_code,
                    'raw_body'  => substr($raw_body, 0, 500), // Truncate for debugging
                    'json_error' => $json_error,
                ]
            );
        }

        // Handle API errors
        if ($status_code >= 400) {
            $error_message = $body['error'] ?? __('An error occurred', 'bandwidth-saver');
            $error_code = $this->get_error_code($status_code);

            do_action('imgpro_cdn_api_error', [
                'status'   => $status_code,
                'message'  => $error_message,
                'endpoint' => $endpoint,
            ], $endpoint);

            return new WP_Error($error_code, $error_message, [
                'status' => $status_code,
                'body'   => $body,
            ]);
        }

        return $body ?? [];
    }

    /**
     * Get User-Agent string for API requests
     *
     * Format: BandwidthSaver/{version} WordPress/{wp_version} PHP/{php_version}
     * This allows the API to track plugin versions and optimize responses.
     *
     * @since 0.2.2
     * @return string User-Agent string.
     */
    private function get_user_agent() {
        global $wp_version;

        $plugin_version = defined('IMGPRO_CDN_VERSION') ? IMGPRO_CDN_VERSION : 'unknown';

        return sprintf(
            'BandwidthSaver/%s WordPress/%s PHP/%s',
            $plugin_version,
            $wp_version,
            PHP_VERSION
        );
    }

    /**
     * Get API base URL
     *
     * SECURITY: Only HTTPS URLs are allowed to prevent credential leakage.
     *
     * @return string Base URL.
     */
    private function get_base_url() {
        $url = apply_filters('imgpro_cdn_api_base_url', self::BASE_URL);

        // SECURITY: Enforce HTTPS to prevent credential leakage via downgrade attacks
        if (strpos($url, 'https://') !== 0) {
            // Log the attempt and fall back to default
            do_action('imgpro_cdn_api_error', [
                'message' => 'Non-HTTPS API URL rejected',
                'attempted_url' => $url,
            ], 'base_url_validation');

            return self::BASE_URL;
        }

        return $url;
    }

    /**
     * Cache site data
     *
     * @param array $site Site data to cache.
     * @param int   $ttl  Cache TTL in seconds.
     */
    private function cache_site($site, $ttl = self::CACHE_TTL) {
        $this->site_cache = $site;
        set_transient('imgpro_cdn_site_data', $site, $ttl);
    }

    /**
     * Format pricing data
     *
     * @param array $price Raw price data from API.
     * @return array Formatted pricing.
     */
    private function format_pricing($price) {
        $amount = $price['formatted'] ?? '$14.99';
        $period = $price['period'] ?? '/mo';

        return [
            'amount'    => $price['cents'] ?? 1499,
            'currency'  => 'USD',
            'interval'  => 'month',
            'formatted' => [
                'amount' => $amount,
                'period' => $period,
                'full'   => $amount . $period,
            ],
        ];
    }

    /**
     * Map HTTP status code to error code
     *
     * @param int $status HTTP status code.
     * @return string Error code.
     */
    private function get_error_code($status) {
        switch ($status) {
            case 400:
                return 'bad_request';
            case 401:
            case 403:
                return 'unauthorized';
            case 404:
                return 'not_found';
            case 409:
                return 'conflict';
            case 429:
                return 'rate_limited';
            default:
                return 'api_error';
        }
    }
}
