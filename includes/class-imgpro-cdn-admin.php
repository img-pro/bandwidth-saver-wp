<?php
/**
 * ImgPro Admin Interface
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface class
 *
 * Handles settings page rendering, subscription management,
 * and admin UI. AJAX operations are handled by ImgPro_CDN_Admin_Ajax.
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Admin {

    /**
     * Settings instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Onboarding instance
     *
     * @since 0.1.7
     * @var ImgPro_CDN_Onboarding
     */
    private $onboarding;

    /**
     * API client instance
     *
     * @since 0.1.7
     * @var ImgPro_CDN_API
     */
    private $api;

    /**
     * Plan selector instance
     *
     * @since 0.1.7
     * @var ImgPro_CDN_Plan_Selector
     */
    private $plan_selector;

    /**
     * Constructor
     *
     * @since 0.1.0
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
        $this->onboarding = new ImgPro_CDN_Onboarding($settings);
        $this->api = ImgPro_CDN_API::instance();
        $this->plan_selector = new ImgPro_CDN_Plan_Selector($settings);
    }

    /**
     * Register admin hooks
     *
     * @since 0.1.0
     * @return void
     */
    public function register_hooks() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_skip_onboarding']);
        add_action('admin_init', [$this, 'handle_payment_return']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Handle skip onboarding request
     *
     * @since 0.1.7
     * @return void
     */
    public function handle_skip_onboarding() {
        // Early return if params not present (nonce verified below)
        if ( ! isset( $_GET['skip_onboarding'], $_GET['_wpnonce'] ) ) {
            return;
        }

        // Verify nonce
        $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'imgpro_skip_onboarding' ) ) {
            wp_die(esc_html__('Security check failed.', 'bandwidth-saver'), '', ['response' => 403]);
        }

        if (!ImgPro_CDN_Security::current_user_can()) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'bandwidth-saver'), '', ['response' => 403]);
        }

        // Mark onboarding as completed (skipped)
        $this->settings->update([
            'onboarding_completed' => true,
            'setup_mode' => ImgPro_CDN_Settings::MODE_CLOUDFLARE,
        ]);

        // Redirect to clean URL
        wp_safe_redirect(admin_url('options-general.php?page=imgpro-cdn-settings&tab=cloudflare'));
        exit;
    }

    /**
     * Handle payment return from Stripe checkout
     *
     * This method handles external redirects from Stripe after payment completion.
     * External payment providers cannot include WordPress nonces in their redirect URLs,
     * so we rely on capability checks and sanitization for security instead.
     *
     * SECURITY: This is safe because:
     * 1. We verify the user has manage_options capability before any action
     * 2. All GET parameters are sanitized
     * 3. The only action taken is syncing the user's own account data from our API
     * 4. No destructive operations are performed based on GET parameters
     *
     * @since 0.1.6
     * @return void
     */
    public function handle_payment_return() {
        // Check capability first - this is the primary security gate for external redirects
        if ( ! ImgPro_CDN_Security::current_user_can() ) {
            return;
        }

        // Get and validate payment return parameters from Stripe redirect
        $params = $this->get_payment_return_params();
        if ( ! $params['is_valid'] ) {
            return;
        }

        // Sync account from cloud using stored API key
        $settings = $this->settings->get_all();
        $api_key = $settings['cloud_api_key'] ?? '';

        if (empty($api_key)) {
            // No API key - can't sync, set transient to retry
            set_transient('imgpro_cdn_payment_pending_recovery', true, 60);
            return;
        }

        $site = $this->api->get_site($api_key, true); // Force refresh

        if (!is_wp_error($site)) {
            // Save refreshed site data
            $this->save_site_to_settings($site);

            // Enable if subscription is valid
            $tier_id = $this->api->get_tier_id($site);
            $valid_tiers = [ImgPro_CDN_Settings::TIER_FREE, ImgPro_CDN_Settings::TIER_UNLIMITED, ImgPro_CDN_Settings::TIER_LITE, ImgPro_CDN_Settings::TIER_PRO, ImgPro_CDN_Settings::TIER_BUSINESS, ImgPro_CDN_Settings::TIER_ACTIVE];

            if (in_array($tier_id, $valid_tiers, true)) {
                $this->settings->update([
                    'cloud_enabled' => true,
                    'onboarding_completed' => true,
                ]);
                delete_transient('imgpro_cdn_pending_payment');
                wp_safe_redirect(admin_url('options-general.php?page=imgpro-cdn-settings&tab=cloud&activated=1'));
                exit;
            }

            // Site found but tier not ready yet (webhook may be pending)
            delete_transient('imgpro_cdn_pending_payment');
            wp_safe_redirect(admin_url('options-general.php?page=imgpro-cdn-settings&tab=cloud&subscription_pending=1'));
            exit;
        }

        // If sync fails, set a transient to show a notice and try again later
        set_transient('imgpro_cdn_payment_pending_recovery', true, 60);
    }

    /**
     * Extract and validate payment return parameters from external redirect
     *
     * This method centralizes GET parameter handling for external payment provider redirects.
     * Since external services like Stripe cannot include WordPress nonces, we must access
     * GET data directly. The calling method MUST verify user capabilities before acting
     * on this data.
     *
     * @since 0.2.5
     * @return array {
     *     @type bool   $is_valid       Whether this is a valid payment return.
     *     @type string $payment_status The payment status ('success' or 'cancelled').
     * }
     */
    private function get_payment_return_params() {
        $result = [
            'is_valid'       => false,
            'payment_status' => '',
        ];

        // Retrieve page parameter - must be our settings page
        // Using filter_input for cleaner GET access without triggering PHPCS nonce warnings
        $page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( 'imgpro-cdn-settings' !== $page ) {
            return $result;
        }

        // Retrieve payment status parameter
        $payment_status = filter_input( INPUT_GET, 'payment', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( 'success' !== $payment_status ) {
            return $result;
        }

        $result['is_valid']       = true;
        $result['payment_status'] = $payment_status;

        return $result;
    }

    /**
     * Save site data from API response to local settings
     *
     * @since 0.1.7
     * @param array $site Site data from API.
     * @return void
     */
    private function save_site_to_settings($site) {
        $tier_id = $this->api->get_tier_id($site);
        $usage = $this->api->get_usage($site);
        $domain = $this->api->get_custom_domain($site);

        // Check if tier is changing - if so, invalidate cached data
        $current_settings = $this->settings->get_all();
        $old_tier = $current_settings['cloud_tier'] ?? '';
        if ($old_tier !== $tier_id) {
            // Tier changed - clear all cached data to ensure fresh limits are shown
            delete_transient('imgpro_cdn_site_data');
            delete_transient('imgpro_cdn_tiers');
        }

        // Note: api_key is NOT updated from response - it's already stored locally
        // and the API no longer returns it (avoid storing secrets in caches)
        $update_data = [
            'cloud_email' => $site['email'] ?? '',
            'cloud_tier' => $tier_id,
            'setup_mode' => ImgPro_CDN_Settings::MODE_CLOUD,
            'bandwidth_used' => $usage['bandwidth_used'],
            'cache_hits' => $usage['cache_hits'],
            'cache_misses' => $usage['cache_misses'],
            'stats_updated_at' => time(),
            // Always set limits (use defaults for free tier when API returns 0)
            'bandwidth_limit' => $usage['bandwidth_limit'] ?: ImgPro_CDN_Settings::FREE_BANDWIDTH_LIMIT,
            'cache_limit' => $usage['cache_limit'] ?: ImgPro_CDN_Settings::FREE_CACHE_LIMIT,
        ];

        if ($domain) {
            $update_data['custom_domain'] = $domain['domain'];
            $update_data['custom_domain_status'] = $domain['status'];
        }

        $this->settings->update($update_data);
    }

    /**
     * Cached site data from sync (used to avoid duplicate API calls)
     *
     * @since 0.2.2
     * @var array|null
     */
    private $synced_data = null;

    /**
     * Sync site data from cloud API
     *
     * Uses cached data if available and fresh, otherwise fetches from API.
     * Updates local settings with any changes from the cloud.
     *
     * @since 0.1.7
     * @return void
     */
    private function sync_site_data() {
        $settings = $this->settings->get_all();
        $api_key = $settings['cloud_api_key'] ?? '';

        if (empty($api_key)) {
            return; // No account to sync
        }

        // Use batched endpoint for efficiency (v0.2.2+)
        // This fetches site + domains + tiers + usage in one request
        $response = $this->api->get_site_full($api_key, ['domains', 'tiers', 'usage']);

        if (is_wp_error($response)) {
            return; // Silently fail - cached data will be used
        }

        // Store for later use by enqueue_admin_assets()
        $this->synced_data = $response;

        $site = $response['site'] ?? null;
        if (!$site) {
            return;
        }

        // Update local settings if data has changed
        $tier_id = $this->api->get_tier_id($site);
        $usage = $this->api->get_usage($site);

        $settings_changed = false;
        $update_data = [];

        // Update tier if changed
        if ($tier_id !== ($settings['cloud_tier'] ?? '')) {
            $update_data['cloud_tier'] = $tier_id;
            $settings_changed = true;

            // Disable if subscription is inactive
            if (ImgPro_CDN_Settings::is_subscription_inactive(['cloud_tier' => $tier_id])) {
                $update_data['cloud_enabled'] = false;
            }
        }

        // Update usage stats
        if ($usage['bandwidth_used'] !== ($settings['bandwidth_used'] ?? 0)) {
            $update_data['bandwidth_used'] = $usage['bandwidth_used'];
            $settings_changed = true;
        }
        // Update custom domain if present
        $domain = $this->api->get_custom_domain($site);
        if ($domain && $domain['status'] !== ($settings['custom_domain_status'] ?? '')) {
            $update_data['custom_domain'] = $domain['domain'];
            $update_data['custom_domain_status'] = $domain['status'];
            $settings_changed = true;
        }

        if ($settings_changed) {
            $update_data['stats_updated_at'] = time();
            $this->settings->update($update_data);
        }
    }

    /**
     * Enqueue admin assets
     *
     * @since 0.1.0
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ('settings_page_imgpro-cdn-settings' !== $hook) {
            return;
        }

        // Sync site data early so we have tiers available for localized script
        // This also populates $this->synced_data for use later
        $this->sync_site_data();

        // Enqueue admin CSS
        $css_file = dirname(__FILE__) . '/../admin/css/imgpro-cdn-admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'imgpro-cdn-admin',
                plugins_url('admin/css/imgpro-cdn-admin.css', dirname(__FILE__)),
                [],
                IMGPRO_CDN_VERSION . '.' . filemtime($css_file)
            );
        }

        // Enqueue Chart.js for analytics
        wp_enqueue_script(
            'chartjs',
            plugin_dir_url(dirname(__FILE__)) . 'admin/js/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Enqueue admin JS
        $js_file = dirname(__FILE__) . '/../admin/js/imgpro-cdn-admin.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'imgpro-cdn-admin',
                plugins_url('admin/js/imgpro-cdn-admin.js', dirname(__FILE__)),
                ['jquery', 'chartjs'],
                IMGPRO_CDN_VERSION . '.' . filemtime($js_file),
                true
            );
        }

        if (file_exists($js_file)) {

            $all_settings = $this->settings->get_all();
            $pricing = $this->get_pricing();

            // Use cached tiers from sync_site_data() if available, otherwise fetch
            $tiers = $this->synced_data['tiers'] ?? $this->api->get_tiers();

            // Index tiers by ID for easy JavaScript lookup
            $tiers_by_id = [];
            foreach ($tiers as $tier) {
                $tiers_by_id[$tier['id']] = $tier;
            }

            // SECURITY: Get all nonces from the security class for granular action verification
            $nonces = ImgPro_CDN_Security::get_all_nonces();

            // Localize script
            wp_localize_script('imgpro-cdn-admin', 'imgproCdnAdmin', [
                // Nonces - granular per action
                'nonces' => $nonces,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'settingsUrl' => admin_url('options-general.php?page=imgpro-cdn-settings'),
                'tier' => $all_settings['cloud_tier'] ?? ImgPro_CDN_Settings::TIER_NONE,
                'bandwidthLimit' => ImgPro_CDN_Settings::get_bandwidth_limit($all_settings),
                'cacheLimit' => ImgPro_CDN_Settings::get_cache_limit($all_settings),
                'pricing' => $pricing,
                'tiers' => $tiers_by_id,
                // Pre-load source domains from sync to avoid separate AJAX call
                'sourceDomains' => $this->synced_data['domains'] ?? null,
                // Pre-load usage analytics from sync (insights + daily chart)
                'usage' => $this->transform_usage_for_js($this->synced_data['usage'] ?? null),
                'i18n' => [
                    'activeLabel' => __('CDN Active', 'bandwidth-saver'),
                    'inactiveLabel' => __('CDN Off', 'bandwidth-saver'),
                    'activeMessage' => sprintf(
                        /* translators: 1: opening span tag, 2: closing span tag, 3: opening span tag, 4: closing span tag */
                        __('%1$sYour media is loading faster.%2$s %3$sVisitors get a better experience.%4$s', 'bandwidth-saver'),
                        '<span class="imgpro-cdn-nowrap imgpro-cdn-hide-mobile">',
                        '</span>',
                        '<span class="imgpro-cdn-nowrap">',
                        '</span>'
                    ),
                    'disabledMessage' => __('Turn on to speed up your media', 'bandwidth-saver'),
                    // Button states
                    'creatingCheckout' => __('Creating checkout...', 'bandwidth-saver'),
                    'creatingAccount' => __('Creating account...', 'bandwidth-saver'),
                    'recovering' => __('Recovering...', 'bandwidth-saver'),
                    'openingPortal' => __('Opening portal...', 'bandwidth-saver'),
                    'activating' => __('Activating...', 'bandwidth-saver'),
                    // Error messages
                    'checkoutError' => __('Could not create checkout. Please try again.', 'bandwidth-saver'),
                    'registrationError' => __('Could not create account. Please try again.', 'bandwidth-saver'),
                    'recoverError' => __('Could not recover account. Please try again.', 'bandwidth-saver'),
                    'portalError' => __('Could not open subscription portal. Please try again.', 'bandwidth-saver'),
                    'genericError' => __('Something went wrong. Please try again.', 'bandwidth-saver'),
                    'timeoutError' => __('Request timed out. Please check your connection and try again.', 'bandwidth-saver'),
                    'settingsError' => __('Could not save settings. Please try again.', 'bandwidth-saver'),
                    // Confirm dialogs
                    'recoverConfirm' => __('This will send a verification code to your registered email. Continue?', 'bandwidth-saver'),
                    // Recovery verification
                    'accountFound' => __('Welcome Back', 'bandwidth-saver'),
                    'accountFoundDesc' => __('We found an existing account for this site. To restore access, enter the verification code sent to:', 'bandwidth-saver'),
                    'codeExpires' => __('The code expires in 15 minutes.', 'bandwidth-saver'),
                    'verify' => __('Verify', 'bandwidth-saver'),
                    'verifying' => __('Verifying...', 'bandwidth-saver'),
                    'cancel' => __('Cancel', 'bandwidth-saver'),
                    'invalidCode' => __('Please enter a valid 6-digit code.', 'bandwidth-saver'),
                    'verificationFailed' => __('Verification failed. Please check your code and try again.', 'bandwidth-saver'),
                    'accountRecovered' => __('Account recovered!', 'bandwidth-saver'),
                    // Success messages
                    'subscriptionActivated' => __('You\'re all set! Your media will now load faster for visitors worldwide.', 'bandwidth-saver'),
                    'subscriptionUpgraded' => __('Subscription activated. Thank you for your support!', 'bandwidth-saver'),
                    'accountCreated' => __('Account created! Toggle on to start speeding up your media.', 'bandwidth-saver'),
                    'checkoutCancelled' => __('Checkout cancelled. You can try again anytime.', 'bandwidth-saver'),
                    // Toggle UI text
                    'cdnActiveHeading' => __('Your media is loading faster', 'bandwidth-saver'),
                    'cdnInactiveHeading' => __('Media CDN is Off', 'bandwidth-saver'),
                    'cdnActiveDesc' => __('Visitors worldwide are getting faster page loads.', 'bandwidth-saver'),
                    'cdnInactiveDesc' => __('Turn on to speed up your media.', 'bandwidth-saver'),
                    // Custom domain
                    'addingDomain' => __('Adding domain...', 'bandwidth-saver'),
                    'checkingStatus' => __('Checking...', 'bandwidth-saver'),
                    'removingDomain' => __('Removing...', 'bandwidth-saver'),
                    'domainAdded' => __('Domain added. Configure your DNS to complete setup.', 'bandwidth-saver'),
                    'domainRemoved' => __('Custom domain removed.', 'bandwidth-saver'),
                    'domainActive' => __('Custom domain is active.', 'bandwidth-saver'),
                    'confirmRemoveDomain' => __('Remove this custom domain? Media will be served from the default domain.', 'bandwidth-saver'),
                    'confirmRemoveCdnDomain' => __('Remove this CDN domain? The Media CDN will be disabled.', 'bandwidth-saver'),
                    'cdnDomainRemoved' => __('CDN domain removed.', 'bandwidth-saver'),
                    // Upgrade prompts
                    'upgradeTitle' => __('Need more bandwidth?', 'bandwidth-saver'),
                    'upgradeSubtitle' => __('Upgrade for more bandwidth and custom domain support.', 'bandwidth-saver'),
                    // Plan selector
                    'select' => __('Select', 'bandwidth-saver'),
                    'selected' => __('Selected', 'bandwidth-saver'),
                ]
            ]);
        }
    }

    /**
     * Transform usage data from API format to JS-friendly format
     *
     * @since 0.2.2
     * @param array|null $usage Raw usage data from API.
     * @return array|null Transformed usage data for JavaScript.
     */
    private function transform_usage_for_js($usage) {
        if (empty($usage)) {
            return null;
        }

        $insights = $usage['insights'] ?? [];

        return [
            'insights' => [
                'avg_daily_bandwidth' => isset($insights['bandwidth']['avg_daily'])
                    ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['avg_daily'])
                    : null,
                'projected_period_bandwidth' => isset($insights['bandwidth']['projected'])
                    ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['projected'])
                    : null,
                'cache_hit_rate' => $insights['recent']['cache_hit_rate'] ?? null,
                'cache_hits' => $insights['recent']['cache_hits'] ?? null,
                'cache_misses' => $insights['recent']['cache_misses'] ?? null,
                'days_remaining' => $insights['period']['days_remaining'] ?? null,
                'total_requests' => $insights['recent']['requests'] ?? null,
                // New request-focused fields (v1.0+)
                'requests' => $insights['requests'] ?? null,
                'period' => $insights['period'] ?? null,
            ],
            'daily' => $usage['daily'] ?? [],
        ];
    }

    /**
     * Add admin menu page
     *
     * @since 0.1.0
     * @return void
     */
    public function add_menu_page() {
        add_options_page(
            esc_html__('Bandwidth Saver', 'bandwidth-saver'),
            esc_html__('Bandwidth Saver', 'bandwidth-saver'),
            'manage_options',
            'imgpro-cdn-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     *
     * @since 0.1.0
     * @return void
     */
    public function register_settings() {
        register_setting(
            'imgpro_cdn_settings_group',
            ImgPro_CDN_Settings::OPTION_KEY,
            [$this, 'sanitize_settings']
        );
    }

    /**
     * Sanitize settings
     *
     * @since 0.1.0
     * @param array $input Posted form data.
     * @return array Complete settings array to be saved.
     */
    public function sanitize_settings($input) {
        $existing = $this->settings->get_all();
        $validated = $this->settings->validate($input);
        $merged = array_merge($existing, $validated);

        // Handle unchecked checkboxes
        if (isset($input['_has_enabled_field'])) {
            if (!isset($input['enabled'])) {
                $merged['enabled'] = false;
            }
            if (!isset($input['debug_mode'])) {
                $merged['debug_mode'] = false;
            }
        }

        // Auto-disable if mode not valid
        $enabled_field_submitted = isset($input['_has_enabled_field']);
        $mode_is_changing = isset($input['setup_mode']) && ($input['setup_mode'] !== ($existing['setup_mode'] ?? ''));

        if ($enabled_field_submitted || $mode_is_changing) {
            if (!ImgPro_CDN_Settings::is_mode_valid($merged['setup_mode'] ?? '', $merged)) {
                $merged['enabled'] = false;
            }
        }

        return $merged;
    }

    /**
     * Render inline notices
     *
     * @since 0.1.0
     * @return void
     */
    private function render_inline_notices() {
        // Show waiting notice if recovery is pending (webhook hasn't processed yet)
        if (get_transient('imgpro_cdn_payment_pending_recovery')) {
            delete_transient('imgpro_cdn_payment_pending_recovery');
            ?>
            <div class="imgpro-notice imgpro-notice-info">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M10 6v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <div>
                    <strong><?php esc_html_e('Payment received. Setting up your account...', 'bandwidth-saver'); ?></strong>
                    <p><?php esc_html_e('Refresh this page in a few seconds to complete activation.', 'bandwidth-saver'); ?></p>
                </div>
            </div>
            <?php
        }

        if (filter_input(INPUT_GET, 'activated', FILTER_VALIDATE_BOOLEAN)) {
            $tier = $this->settings->get('cloud_tier', ImgPro_CDN_Settings::TIER_FREE);
            $is_free = ImgPro_CDN_Settings::TIER_FREE === $tier;
            ?>
            <div class="imgpro-notice imgpro-notice-success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M6 10l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <p><strong>
                    <?php if ($is_free): ?>
                        <?php esc_html_e('Account activated. Your media now loads from the global edge network.', 'bandwidth-saver'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Subscription activated. Your media now loads from the global edge network.', 'bandwidth-saver'); ?>
                    <?php endif; ?>
                </strong></p>
            </div>
            <?php
        }

        if (filter_input(INPUT_GET, 'subscription_pending', FILTER_VALIDATE_BOOLEAN)) {
            ?>
            <div class="imgpro-notice imgpro-notice-info">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M10 6v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <div>
                    <strong><?php esc_html_e('Subscription received! Activating your account...', 'bandwidth-saver'); ?></strong>
                    <p><?php esc_html_e('Enable the CDN toggle below to start serving media faster.', 'bandwidth-saver'); ?></p>
                </div>
            </div>
            <?php
        }

        if (filter_input(INPUT_GET, 'settings-updated', FILTER_VALIDATE_BOOLEAN)) {
            ?>
            <div class="imgpro-notice imgpro-notice-success">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M6 10l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <p><strong><?php esc_html_e('Settings saved.', 'bandwidth-saver'); ?></strong></p>
            </div>
            <?php
        }
    }

    /**
     * Render settings page
     *
     * @since 0.1.0
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Note: sync_site_data() is already called in enqueue_admin_assets()
        // which runs before render, so $this->synced_data is already populated

        $settings = $this->settings->get_all();

        // Onboarding wizard disabled - frictionless activation via toggle
        // Users now toggle ON directly, which auto-registers if needed
        // Keeping onboarding code for potential future use (e.g., guided tours)

        // Handle mode switching (nonce verified immediately after isset check)
        if ( isset( $_GET['switch_mode'], $_GET['_wpnonce'] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            if ( ! wp_verify_nonce( $nonce, 'imgpro_switch_mode' ) ) {
                wp_die( esc_html__( 'Security check failed', 'bandwidth-saver' ) );
            }

            $new_mode = sanitize_text_field( wp_unslash( $_GET['switch_mode'] ) );
            if (in_array($new_mode, [ImgPro_CDN_Settings::MODE_CLOUD, ImgPro_CDN_Settings::MODE_CLOUDFLARE], true)) {
                $old_mode = $settings['setup_mode'] ?? '';
                $was_enabled = $settings['enabled'] ?? false;
                $new_mode_is_valid = ImgPro_CDN_Settings::is_mode_valid($new_mode, $settings);

                $settings['setup_mode'] = $new_mode;

                if ($new_mode_is_valid) {
                    if (!empty($settings['previously_enabled'])) {
                        $settings['enabled'] = true;
                        $settings['previously_enabled'] = false;
                    }
                } else {
                    if ($was_enabled) {
                        $settings['previously_enabled'] = true;
                    }
                    $settings['enabled'] = false;
                }

                update_option(ImgPro_CDN_Settings::OPTION_KEY, $settings);
                $this->settings->clear_cache();
            }
        }

        // Determine current tab
        $current_tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

        if (empty($current_tab)) {
            $current_tab = !empty($settings['setup_mode']) ? $settings['setup_mode'] : ImgPro_CDN_Settings::MODE_CLOUD;
        }

        ?>
        <div class="wrap imgpro-admin">
            <?php $this->render_header($settings, $current_tab); ?>

            <hr class="wp-header-end">

            <?php $this->render_inline_notices(); ?>

            <?php $this->render_tabs($current_tab, $settings); ?>

            <div class="imgpro-tab-content">
                <?php
                if (ImgPro_CDN_Settings::MODE_CLOUD === $current_tab) {
                    $this->render_cloud_tab($settings);
                } else {
                    $this->render_cloudflare_tab($settings);
                }
                ?>
            </div>

            <?php $this->render_footer(); ?>

            <?php // Plan selector modal (for upgrade CTAs) ?>
            <?php $this->plan_selector->render_modal_wrapper(); ?>
        </div>
        <?php
    }

    /**
     * Render onboarding page wrapper
     *
     * @since 0.1.7
     * @return void
     */
    private function render_onboarding_page() {
        ?>
        <div class="wrap imgpro-admin imgpro-admin-onboarding">
            <?php $this->onboarding->render(); ?>
        </div>
        <?php
    }

    /**
     * Render page header
     *
     * Status badge reflects the current tab's mode-specific enabled state.
     *
     * @since 0.1.7
     * @param array  $settings    Plugin settings.
     * @param string $current_tab Current tab/mode being viewed.
     * @return void
     */
    private function render_header($settings, $current_tab = '') {
        $mode = $current_tab ?: ($settings['setup_mode'] ?? '');
        $is_mode_configured = ImgPro_CDN_Settings::is_mode_valid($mode, $settings);
        $is_mode_enabled = ImgPro_CDN_Settings::is_mode_enabled($mode, $settings);
        $is_active = $is_mode_configured && $is_mode_enabled;
        ?>
        <div class="imgpro-header">
            <div class="imgpro-header-brand">
                <div>
                    <h1><?php esc_html_e('Bandwidth Saver', 'bandwidth-saver'); ?></h1>
                    <p class="imgpro-tagline"><?php esc_html_e('Faster media for visitors worldwide', 'bandwidth-saver'); ?></p>
                </div>
            </div>
            <div class="imgpro-header-meta">
                <?php if ($is_mode_configured): ?>
                    <span class="imgpro-status-badge <?php echo esc_attr( $is_active ? 'imgpro-status-active' : 'imgpro-status-inactive' ); ?>" id="imgpro-status-badge" data-mode="<?php echo esc_attr($mode); ?>">
                        <span class="imgpro-status-dot"></span>
                        <span class="imgpro-status-text"><?php echo $is_active ? esc_html__('CDN Active', 'bandwidth-saver') : esc_html__('CDN Inactive', 'bandwidth-saver'); ?></span>
                    </span>
                <?php endif; ?>
                <span class="imgpro-version">v<?php echo esc_html(IMGPRO_CDN_VERSION); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render CDN toggle card
     *
     * Each mode has its own independent enabled state.
     *
     * @since 0.1.7
     * @param array  $settings   Plugin settings.
     * @param string $setup_mode Current setup mode.
     * @return void
     */
    private function render_toggle_card($settings, $setup_mode = '') {
        $mode = $setup_mode ?: ($settings['setup_mode'] ?? '');
        $is_enabled = ImgPro_CDN_Settings::is_mode_enabled($mode, $settings);

        // Determine field name based on mode
        $field_name = ImgPro_CDN_Settings::MODE_CLOUD === $mode
            ? 'imgpro_cdn_settings[cloud_enabled]'
            : 'imgpro_cdn_settings[cloudflare_enabled]';
        ?>
        <div class="imgpro-toggle-card <?php echo esc_attr( $is_enabled ? 'is-active' : 'is-inactive' ); ?>" id="imgpro-toggle-card" data-mode="<?php echo esc_attr($mode); ?>">
            <form method="post" action="options.php" class="imgpro-toggle-form">
                <?php settings_fields('imgpro_cdn_settings_group'); ?>
                <input type="hidden" name="imgpro_cdn_settings[setup_mode]" value="<?php echo esc_attr($mode); ?>">

                <div class="imgpro-toggle-content">
                    <div class="imgpro-toggle-info">
                        <div class="imgpro-toggle-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                        </div>
                        <div>
                            <h2 id="imgpro-toggle-heading">
                                <?php echo $is_enabled
                                    ? esc_html__('Your media is loading faster', 'bandwidth-saver')
                                    : esc_html__('Media CDN is Off', 'bandwidth-saver'); ?>
                            </h2>
                            <p id="imgpro-toggle-description">
                                <?php echo $is_enabled
                                    ? esc_html__('Visitors worldwide are getting faster page loads.', 'bandwidth-saver')
                                    : esc_html__('Turn on to speed up your media.', 'bandwidth-saver'); ?>
                            </p>
                        </div>
                    </div>

                    <label class="imgpro-toggle" for="imgpro-cdn-enabled">
                        <input
                            type="checkbox"
                            id="imgpro-cdn-enabled"
                            name="<?php echo esc_attr($field_name); ?>"
                            value="1"
                            <?php checked($is_enabled, true); ?>
                            role="switch"
                            aria-checked="<?php echo esc_attr( $is_enabled ? 'true' : 'false' ); ?>"
                        >
                        <span class="imgpro-toggle-slider"></span>
                        <span class="screen-reader-text"><?php esc_html_e('Toggle Media CDN', 'bandwidth-saver'); ?></span>
                    </label>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render subscription alerts (cancelled, past_due)
     *
     * @since 0.1.7
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_subscription_alerts($settings) {
        $is_cancelled = ImgPro_CDN_Settings::is_subscription_inactive($settings);
        $is_past_due = ImgPro_CDN_Settings::is_past_due($settings);

        if ($is_cancelled) {
            $this->render_subscription_alert('cancelled', $settings);
        } elseif ($is_past_due) {
            $this->render_subscription_alert('past_due', $settings);
        }
    }

    /**
     * Render stats grid
     *
     * @since 0.1.7
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_stats_grid($settings) {
        $bandwidth_used = $settings['bandwidth_used'] ?? 0;
        $bandwidth_limit = ImgPro_CDN_Settings::get_bandwidth_limit($settings);
        $bandwidth_percentage = ImgPro_CDN_Settings::get_bandwidth_percentage($settings);

        // Calculate days remaining in billing period
        $period_end = $settings['billing_period_end'] ?? 0;
        $now = time();
        $days_remaining = $period_end > 0 ? max(0, ceil(($period_end - $now) / 86400)) : 0;
        ?>

        <!-- Analytics Section -->
        <div class="imgpro-analytics-section" id="imgpro-analytics-section">

            <!-- Quick Stats Grid -->
            <div class="imgpro-stats-grid" id="imgpro-stats-grid">
                <!-- Requests Card (populated by JS) -->
                <div class="imgpro-stat-card">
                    <div class="imgpro-stat-header">
                        <span class="imgpro-stat-label"><?php esc_html_e('Requests', 'bandwidth-saver'); ?></span>
                    </div>
                    <div class="imgpro-stat-value" id="imgpro-stat-total-requests">
                        <span class="imgpro-stat-loading">—</span>
                    </div>
                    <p class="imgpro-stat-hint"><?php esc_html_e('Last 7 days', 'bandwidth-saver'); ?></p>
                </div>

                <!-- Cached Media Card (populated by JS) -->
                <div class="imgpro-stat-card">
                    <div class="imgpro-stat-header">
                        <span class="imgpro-stat-label"><?php esc_html_e('Cached', 'bandwidth-saver'); ?></span>
                    </div>
                    <div class="imgpro-stat-value" id="imgpro-stat-cached">
                        <span class="imgpro-stat-loading">—</span>
                    </div>
                    <p class="imgpro-stat-hint"><?php esc_html_e('Last 7 days', 'bandwidth-saver'); ?></p>
                </div>

                <!-- Served by CDN Card (populated by JS) -->
                <div class="imgpro-stat-card">
                    <div class="imgpro-stat-header">
                        <span class="imgpro-stat-label"><?php esc_html_e('Served by CDN', 'bandwidth-saver'); ?></span>
                    </div>
                    <div class="imgpro-stat-value" id="imgpro-stat-cache-hit-rate">
                        <span class="imgpro-stat-loading">—</span>
                    </div>
                    <p class="imgpro-stat-hint"><?php esc_html_e('Last 7 days', 'bandwidth-saver'); ?></p>
                </div>
            </div>

            <!-- Usage Chart -->
            <div class="imgpro-chart-card">
                <div class="imgpro-chart-header">
                    <h3><?php esc_html_e('Request Activity', 'bandwidth-saver'); ?></h3>
                    <div class="imgpro-chart-controls">
                        <button type="button" class="imgpro-stat-refresh" id="imgpro-refresh-stats" title="<?php esc_attr_e('Refresh stats', 'bandwidth-saver'); ?>">
                            <!-- Heroicon: arrow-path (outline) -->
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </button>
                        <select id="imgpro-chart-period" class="imgpro-chart-period-select">
                            <option value="7"><?php esc_html_e('Last 7 days', 'bandwidth-saver'); ?></option>
                            <option value="30" selected><?php esc_html_e('Last 30 days', 'bandwidth-saver'); ?></option>
                            <option value="90"><?php esc_html_e('Last 90 days', 'bandwidth-saver'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="imgpro-chart-body">
                    <div class="imgpro-chart-loading" id="imgpro-chart-loading">
                        <svg class="imgpro-spinner" width="32" height="32" viewBox="0 0 32 32" fill="none">
                            <circle cx="16" cy="16" r="14" stroke="currentColor" stroke-width="4" stroke-opacity="0.2"/>
                            <path d="M16 2a14 14 0 0 1 14 14" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                        <p><?php esc_html_e('Loading chart...', 'bandwidth-saver'); ?></p>
                    </div>
                    <canvas id="imgpro-usage-chart" width="800" height="300"></canvas>
                    <div class="imgpro-chart-empty" id="imgpro-chart-empty" style="display: none;">
                        <!-- Heroicon: chart-bar (outline) -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="48" height="48" opacity="0.3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                        <p><?php esc_html_e('No usage data yet', 'bandwidth-saver'); ?></p>
                        <p class="imgpro-text-muted"><?php esc_html_e('Data will appear once you start using the CDN', 'bandwidth-saver'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Insights Grid -->
            <div class="imgpro-insights-grid" id="imgpro-insights-grid">
                <div class="imgpro-insight-card">
                    <div class="imgpro-insight-icon">
                        <!-- Heroicon: cursor-arrow-rays (outline) -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.042 21.672 13.684 16.6m0 0-2.51 2.225.569-9.47 5.227 7.917-3.286-.672ZM12 2.25V4.5m5.834.166-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243-1.59-1.59" />
                        </svg>
                    </div>
                    <div class="imgpro-insight-content">
                        <div class="imgpro-insight-label"><?php esc_html_e('Total Requests', 'bandwidth-saver'); ?></div>
                        <div class="imgpro-insight-value" id="imgpro-requests-total">
                            <span class="imgpro-stat-loading">—</span>
                        </div>
                    </div>
                </div>

                <div class="imgpro-insight-card">
                    <div class="imgpro-insight-icon">
                        <!-- Heroicon: chart-bar (outline) -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                    </div>
                    <div class="imgpro-insight-content">
                        <div class="imgpro-insight-label"><?php esc_html_e('Avg. Daily', 'bandwidth-saver'); ?></div>
                        <div class="imgpro-insight-value" id="imgpro-requests-avg-daily">
                            <span class="imgpro-stat-loading">—</span>
                        </div>
                    </div>
                </div>

                <div class="imgpro-insight-card">
                    <div class="imgpro-insight-icon">
                        <!-- Heroicon: clock (outline) -->
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>
                    <div class="imgpro-insight-content">
                        <div class="imgpro-insight-label"><?php esc_html_e('Days Until Reset', 'bandwidth-saver'); ?></div>
                        <div class="imgpro-insight-value" id="imgpro-insight-days">
                            <?php echo esc_html($days_remaining); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Render subscription alert banner
     *
     * @since 0.1.7
     * @param string $type     Alert type ('cancelled', 'past_due', 'suspended').
     * @param array  $settings Plugin settings.
     * @return void
     */
    private function render_subscription_alert($type, $settings) {
        $pricing = $this->get_pricing();

        // Allowed SVG elements and attributes for wp_kses
        $allowed_svg = [
            'svg'    => [
                'width'       => true,
                'height'      => true,
                'viewbox'     => true,
                'fill'        => true,
                'xmlns'       => true,
            ],
            'circle' => [
                'cx'           => true,
                'cy'           => true,
                'r'            => true,
                'stroke'       => true,
                'stroke-width' => true,
            ],
            'path'   => [
                'd'               => true,
                'stroke'          => true,
                'stroke-width'    => true,
                'stroke-linecap'  => true,
                'stroke-linejoin' => true,
            ],
            'line'   => [
                'x1'             => true,
                'y1'             => true,
                'x2'             => true,
                'y2'             => true,
                'stroke'         => true,
                'stroke-width'   => true,
                'stroke-linecap' => true,
            ],
        ];

        if ( 'cancelled' === $type ) {
            $icon        = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
            $title       = __( 'Your subscription has ended', 'bandwidth-saver' );
            $message     = __( 'Your subscription has been cancelled. CDN functionality is disabled until you resubscribe.', 'bandwidth-saver' );
            $button_text = __( 'Resubscribe', 'bandwidth-saver' );
            $button_id   = 'imgpro-resubscribe';
            $alert_class = 'is-error';
        } elseif ( 'past_due' === $type ) {
            $icon        = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
            $title       = __( 'Payment failed', 'bandwidth-saver' );
            $message     = __( 'We couldn\'t process your last payment. Please update your payment method to avoid service interruption.', 'bandwidth-saver' );
            $button_text = __( 'Update Payment', 'bandwidth-saver' );
            $button_id   = 'imgpro-update-payment';
            $alert_class = 'is-warning';
        } else {
            $icon        = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
            $title       = __( 'Subscription suspended', 'bandwidth-saver' );
            $message     = __( 'Your subscription has been suspended. Please contact support or update your payment method.', 'bandwidth-saver' );
            $button_text = __( 'Manage Subscription', 'bandwidth-saver' );
            $button_id   = 'imgpro-manage-subscription-alert';
            $alert_class = 'is-error';
        }
        ?>
        <div class="imgpro-subscription-alert <?php echo esc_attr( $alert_class ); ?>">
            <div class="imgpro-subscription-alert-icon">
                <?php echo wp_kses( $icon, $allowed_svg ); ?>
            </div>
            <div class="imgpro-subscription-alert-content">
                <strong><?php echo esc_html($title); ?></strong>
                <p><?php echo esc_html($message); ?></p>
            </div>
            <button type="button" class="imgpro-btn imgpro-btn-primary" id="<?php echo esc_attr($button_id); ?>">
                <?php echo esc_html($button_text); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render navigation tabs
     *
     * @since 0.1.0
     * @param string $current_tab Current active tab.
     * @param array  $settings    Plugin settings.
     * @return void
     */
    private function render_tabs($current_tab, $settings) {
        $base_url = admin_url('options-general.php?page=imgpro-cdn-settings');

        $cloud_url = add_query_arg([
            'tab' => ImgPro_CDN_Settings::MODE_CLOUD,
            'switch_mode' => ImgPro_CDN_Settings::MODE_CLOUD,
            '_wpnonce' => wp_create_nonce('imgpro_switch_mode')
        ], $base_url);

        $cloudflare_url = add_query_arg([
            'tab' => ImgPro_CDN_Settings::MODE_CLOUDFLARE,
            'switch_mode' => ImgPro_CDN_Settings::MODE_CLOUDFLARE,
            '_wpnonce' => wp_create_nonce('imgpro_switch_mode')
        ], $base_url);

        ?>
        <nav class="imgpro-tabs" role="tablist">
            <a href="<?php echo esc_url($cloud_url); ?>"
               class="imgpro-tab <?php echo ImgPro_CDN_Settings::MODE_CLOUD === $current_tab ? 'is-active' : ''; ?>"
               role="tab"
               aria-selected="<?php echo ImgPro_CDN_Settings::MODE_CLOUD === $current_tab ? 'true' : 'false'; ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                <?php esc_html_e('Managed', 'bandwidth-saver'); ?>
            </a>
            <a href="<?php echo esc_url($cloudflare_url); ?>"
               class="imgpro-tab <?php echo ImgPro_CDN_Settings::MODE_CLOUDFLARE === $current_tab ? 'is-active' : ''; ?>"
               role="tab"
               aria-selected="<?php echo ImgPro_CDN_Settings::MODE_CLOUDFLARE === $current_tab ? 'true' : 'false'; ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                <?php esc_html_e('Self-Host', 'bandwidth-saver'); ?>
            </a>
        </nav>
        <?php
    }

    /**
     * Get pricing from API
     *
     * Uses the API client which handles caching internally.
     *
     * @since 0.1.0
     * @return array
     */
    private function get_pricing() {
        return $this->api->get_pricing();
    }

    /**
     * Render Cloud tab
     *
     * @since 0.1.0
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_cloud_tab($settings) {
        $tier = $settings['cloud_tier'] ?? ImgPro_CDN_Settings::TIER_NONE;
        $has_subscription = in_array($tier, [ImgPro_CDN_Settings::TIER_FREE, ImgPro_CDN_Settings::TIER_UNLIMITED, ImgPro_CDN_Settings::TIER_LITE, ImgPro_CDN_Settings::TIER_PRO, ImgPro_CDN_Settings::TIER_BUSINESS, ImgPro_CDN_Settings::TIER_ACTIVE, ImgPro_CDN_Settings::TIER_PAST_DUE], true);
        ?>
        <div class="imgpro-tab-panel" role="tabpanel">
            <?php if (!$has_subscription): ?>
                <?php // New install - show toggle card (will auto-register on first enable) ?>
                <?php $this->render_toggle_card($settings, ImgPro_CDN_Settings::MODE_CLOUD); ?>
                <p class="imgpro-safety-note">
                    <?php esc_html_e('Your original files stay on your server. Turning the CDN off or deactivating the plugin will not break your site — URLs simply return to normal.', 'bandwidth-saver'); ?>
                </p>
            <?php else: ?>
                <?php $this->render_cloud_settings($settings); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Cloud settings (for active users)
     *
     * Layout hierarchy:
     * 1. CDN Toggle
     * 2. Account Card
     * 3. Stats Grid
     * 4. Custom Domain
     * 5. Advanced Settings
     *
     * @since 0.1.7
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_cloud_settings($settings) {
        $email = $settings['cloud_email'] ?? '';
        $custom_domain = $settings['custom_domain'] ?? '';
        $domain_status = $settings['custom_domain_status'] ?? '';
        $has_custom_domain = !empty($custom_domain);
        $needs_attention = $has_custom_domain && 'active' !== $domain_status;
        ?>
        <div class="imgpro-cloud-dashboard">
            <?php // Subscription Alerts ?>
            <?php $this->render_subscription_alerts($settings); ?>

            <?php // 1. CDN Toggle ?>
            <?php $this->render_toggle_card($settings, ImgPro_CDN_Settings::MODE_CLOUD); ?>

            <p class="imgpro-safety-note">
                <?php esc_html_e('Your original files stay on your server. Turning the CDN off or deactivating the plugin will not break your site — URLs simply return to normal.', 'bandwidth-saver'); ?>
            </p>

            <?php // 2. Stats Grid ?>
            <?php $this->render_stats_grid($settings); ?>

            <?php // 3. Account Card ?>
            <?php $this->render_account_card($settings, $email); ?>

            <?php // 4. Custom Domain Section ?>
            <?php $this->render_custom_domain_section($settings); ?>

            <?php // 5. Source URLs Section ?>
            <?php $this->render_source_urls_section($settings); ?>

            <?php // Custom Domain Pending Notice (if DNS needs attention) ?>
            <?php if ($needs_attention): ?>
                <?php $this->render_custom_domain_pending($settings); ?>
            <?php endif; ?>

            <?php // 5. Developer Options (only shown when WP_DEBUG is enabled) ?>
            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
            <div class="imgpro-card imgpro-dev-options">
                <form method="post" action="options.php" class="imgpro-dev-options-form">
                    <?php settings_fields('imgpro_cdn_settings_group'); ?>
                    <input type="hidden" name="imgpro_cdn_settings[_has_enabled_field]" value="1">
                    <input type="hidden" name="imgpro_cdn_settings[setup_mode]" value="<?php echo esc_attr(ImgPro_CDN_Settings::MODE_CLOUD); ?>">
                    <input type="hidden" name="imgpro_cdn_settings[enabled]" value="<?php echo esc_attr( $settings['enabled'] ? '1' : '0' ); ?>">
                    <input type="hidden" name="imgpro_cdn_settings[cdn_url]" value="<?php echo esc_attr($settings['cdn_url']); ?>">

                    <div class="imgpro-card-header">
                        <h3><?php esc_html_e('Developer Options', 'bandwidth-saver'); ?></h3>
                    </div>
                    <div class="imgpro-card-body">
                        <label class="imgpro-dev-checkbox">
                            <input
                                type="checkbox"
                                name="imgpro_cdn_settings[debug_mode]"
                                value="1"
                                <?php checked($settings['debug_mode'], true); ?>
                            >
                            <span class="imgpro-dev-checkbox-text"><?php esc_html_e('Enable debug logging', 'bandwidth-saver'); ?></span>
                        </label>
                        <p class="imgpro-help-text"><?php esc_html_e('Logs CDN operations to browser console.', 'bandwidth-saver'); ?></p>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render account card (unified single-tier model)
     *
     * Shows subscription status and payment prompt for unpaid users.
     * All users get the same features regardless of payment status.
     *
     * @since 0.1.6
     * @param array  $settings Plugin settings.
     * @param string $email    User email.
     * @return void
     */
    private function render_account_card($settings, $email) {
        $is_paid = ImgPro_CDN_Settings::is_paid($settings);
        ?>
        <div class="imgpro-account-card <?php echo $is_paid ? 'imgpro-account-card--active' : 'imgpro-account-card--pending'; ?>">
            <div class="imgpro-account-card__main">
                <div class="imgpro-account-card__content">
                    <?php if ($is_paid): ?>
                        <div class="imgpro-account-card__status">
                            <svg class="imgpro-account-card__status-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="10" fill="#10b981" fill-opacity="0.1"/>
                                <path d="M14 7L8.5 12.5 6 10" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="imgpro-account-card__status-text"><?php esc_html_e('Subscription Active', 'bandwidth-saver'); ?></span>
                        </div>
                        <span class="imgpro-account-card__description"><?php esc_html_e('Unlimited media delivery from 300+ edge servers.', 'bandwidth-saver'); ?></span>
                    <?php else: ?>
                        <strong class="imgpro-account-card__headline"><?php esc_html_e('Enjoying the Media CDN?', 'bandwidth-saver'); ?></strong>
                        <span class="imgpro-account-card__description"><?php esc_html_e('Activate your subscription to support continued development.', 'bandwidth-saver'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="imgpro-account-card__actions">
                    <?php if ($is_paid): ?>
                        <button type="button" class="imgpro-btn imgpro-btn-secondary" id="imgpro-manage-subscription">
                            <?php esc_html_e('Manage Subscription', 'bandwidth-saver'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="imgpro-btn imgpro-btn-primary imgpro-open-plan-selector">
                            <?php esc_html_e('Activate Subscription', 'bandwidth-saver'); ?>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3.333 8h9.334M8 3.333L12.667 8 8 12.667" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="imgpro-account-card__footer">
                <?php if (!empty($email)): ?>
                    <span><?php echo esc_html($email); ?></span>
                <?php endif; ?>
                <?php if ($is_paid && !empty($email)): ?>
                    <span class="imgpro-separator">·</span>
                    <span class="imgpro-account-card__price"><?php esc_html_e('$19.99/mo', 'bandwidth-saver'); ?></span>
                <?php elseif (!$is_paid): ?>
                    <?php if (!empty($email)): ?>
                        <span class="imgpro-separator">·</span>
                    <?php endif; ?>
                    <span class="imgpro-account-card__price"><?php esc_html_e('$19.99/mo', 'bandwidth-saver'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Cloudflare (Self-Host) tab
     *
     * Layout hierarchy (when configured):
     * 1. CDN Toggle
     * 2. CDN Domain Card
     * 3. Advanced Settings
     *
     * @since 0.1.0
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_cloudflare_tab($settings) {
        $is_configured = !empty($settings['cdn_url']);
        ?>
        <div class="imgpro-tab-panel" role="tabpanel">
            <?php if (!$is_configured): ?>
                <?php // Setup instructions for unconfigured state ?>
                <div class="imgpro-card imgpro-setup-card">
                    <h2><?php esc_html_e('Use Your Own Cloudflare Account', 'bandwidth-saver'); ?></h2>
                    <p><?php esc_html_e('For technical users who prefer running Cloudflare on their own account. You pay Cloudflare directly (usually $0/month on the free tier).', 'bandwidth-saver'); ?></p>

                    <ol class="imgpro-steps-list">
                        <li>
                            <strong><?php esc_html_e('Create a Cloudflare Account', 'bandwidth-saver'); ?></strong>
                            <span><?php esc_html_e('Free at cloudflare.com', 'bandwidth-saver'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Deploy the Worker', 'bandwidth-saver'); ?></strong>
                            <span><?php esc_html_e('Follow the setup guide on GitHub', 'bandwidth-saver'); ?></span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Enter Domain Below', 'bandwidth-saver'); ?></strong>
                            <span><?php esc_html_e('Add your CDN domain here to activate', 'bandwidth-saver'); ?></span>
                        </li>
                    </ol>

                    <div class="imgpro-setup-actions">
                        <a href="https://github.com/img-pro/bandwidth-saver-worker#quick-start" target="_blank" class="imgpro-btn imgpro-btn-primary">
                            <?php esc_html_e('View Setup Guide', 'bandwidth-saver'); ?>
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M12 8.667V12a1.333 1.333 0 01-1.333 1.333H4A1.333 1.333 0 012.667 12V5.333A1.333 1.333 0 014 4h3.333M10 2h4v4M6.667 9.333L14 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                        <span class="imgpro-setup-time">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3l2 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <?php esc_html_e('~15 minutes', 'bandwidth-saver'); ?>
                        </span>
                    </div>
                </div>

                <?php // CDN Domain input form (unconfigured) ?>
                <form method="post" action="options.php" class="imgpro-settings-form">
                    <?php settings_fields('imgpro_cdn_settings_group'); ?>
                    <input type="hidden" name="imgpro_cdn_settings[setup_mode]" value="<?php echo esc_attr(ImgPro_CDN_Settings::MODE_CLOUDFLARE); ?>">

                    <div class="imgpro-custom-domain-card" id="imgpro-cdn-domain-section">
                        <div class="imgpro-custom-domain-header">
                            <h4><?php esc_html_e('CDN Domain', 'bandwidth-saver'); ?></h4>
                            <p><?php esc_html_e('The domain pointing to your Cloudflare Worker.', 'bandwidth-saver'); ?></p>
                        </div>
                        <div class="imgpro-custom-domain-form">
                            <div class="imgpro-custom-domain-input-group">
                                <input
                                    type="text"
                                    id="cdn_url"
                                    name="imgpro_cdn_settings[cdn_url]"
                                    value=""
                                    placeholder="cdn.yourdomain.com"
                                    class="imgpro-input"
                                >
                                <button type="submit" class="imgpro-btn imgpro-btn-primary">
                                    <?php esc_html_e('Add Domain', 'bandwidth-saver'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="imgpro-alt-option">
                    <p>
                        <strong><?php esc_html_e('Prefer a simpler setup?', 'bandwidth-saver'); ?></strong>
                        <?php esc_html_e('The Managed option works in under a minute with no configuration.', 'bandwidth-saver'); ?>
                        <a href="<?php echo esc_url(add_query_arg(['tab' => ImgPro_CDN_Settings::MODE_CLOUD, 'switch_mode' => ImgPro_CDN_Settings::MODE_CLOUD, '_wpnonce' => wp_create_nonce('imgpro_switch_mode')], admin_url('options-general.php?page=imgpro-cdn-settings'))); ?>">
                            <?php esc_html_e('Try Managed instead', 'bandwidth-saver'); ?>
                        </a>
                    </p>
                </div>

            <?php else: ?>
                <?php // Configured state: Toggle → Domain → Advanced ?>
                <div class="imgpro-selfhost-dashboard">
                    <?php // 1. CDN Toggle ?>
                    <?php $this->render_toggle_card($settings, ImgPro_CDN_Settings::MODE_CLOUDFLARE); ?>

                    <?php // 2. CDN Domain Card ?>
                    <div class="imgpro-custom-domain-card" id="imgpro-cdn-domain-section">
                        <div class="imgpro-custom-domain-header">
                            <h4><?php esc_html_e('CDN Domain', 'bandwidth-saver'); ?></h4>
                            <p><?php esc_html_e('The domain pointing to your Cloudflare Worker.', 'bandwidth-saver'); ?></p>
                        </div>
                        <div class="imgpro-custom-domain-configured">
                            <div class="imgpro-custom-domain-info">
                                <code class="imgpro-custom-domain-value"><?php echo esc_html($settings['cdn_url']); ?></code>
                                <span class="imgpro-domain-badge imgpro-domain-badge-active">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M10 3L4.5 8.5 2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <?php esc_html_e('Active', 'bandwidth-saver'); ?>
                                </span>
                            </div>
                            <button type="button" class="imgpro-btn imgpro-btn-sm imgpro-btn-ghost imgpro-btn-danger" id="imgpro-remove-cdn-domain">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M11 3L3 11M3 3l8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                <?php esc_html_e('Remove', 'bandwidth-saver'); ?>
                            </button>
                        </div>
                    </div>

                    <?php // 3. Developer Options (only shown when WP_DEBUG is enabled) ?>
                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                    <div class="imgpro-card imgpro-dev-options">
                        <form method="post" action="options.php" class="imgpro-dev-options-form">
                            <?php settings_fields('imgpro_cdn_settings_group'); ?>
                            <input type="hidden" name="imgpro_cdn_settings[_has_enabled_field]" value="1">
                            <input type="hidden" name="imgpro_cdn_settings[setup_mode]" value="<?php echo esc_attr(ImgPro_CDN_Settings::MODE_CLOUDFLARE); ?>">
                            <input type="hidden" name="imgpro_cdn_settings[enabled]" value="<?php echo esc_attr( $settings['enabled'] ? '1' : '0' ); ?>">
                            <input type="hidden" name="imgpro_cdn_settings[cdn_url]" value="<?php echo esc_attr($settings['cdn_url']); ?>">

                            <div class="imgpro-card-header">
                                <h3><?php esc_html_e('Developer Options', 'bandwidth-saver'); ?></h3>
                            </div>
                            <div class="imgpro-card-body">
                                <label class="imgpro-dev-checkbox">
                                    <input
                                        type="checkbox"
                                        name="imgpro_cdn_settings[debug_mode]"
                                        value="1"
                                        <?php checked($settings['debug_mode'], true); ?>
                                    >
                                    <span class="imgpro-dev-checkbox-text"><?php esc_html_e('Enable debug logging', 'bandwidth-saver'); ?></span>
                                </label>
                                <p class="imgpro-help-text"><?php esc_html_e('Logs CDN operations to browser console.', 'bandwidth-saver'); ?></p>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Parse custom domain
     *
     * @since 0.1.6
     * @param string $domain Full domain name.
     * @return array
     */
    private function parse_custom_domain($domain) {
        $parts = explode('.', $domain);
        $num_parts = count($parts);

        $two_part_tlds = ['co.uk', 'com.au', 'co.nz', 'com.br', 'co.jp', 'com.mx', 'org.uk', 'net.au'];
        $last_two = $num_parts >= 2 ? $parts[$num_parts - 2] . '.' . $parts[$num_parts - 1] : '';

        if (in_array($last_two, $two_part_tlds, true)) {
            if ($num_parts < 3) {
                return ['subdomain' => '', 'root' => $domain, 'is_root_domain' => true];
            }
            if ($num_parts === 3) {
                return ['subdomain' => '', 'root' => $domain, 'is_root_domain' => true];
            }
            return [
                'subdomain' => implode('.', array_slice($parts, 0, -3)),
                'root' => implode('.', array_slice($parts, -3)),
                'is_root_domain' => false,
            ];
        }

        if ($num_parts < 2) {
            return ['subdomain' => '', 'root' => $domain, 'is_root_domain' => true];
        }
        if ($num_parts === 2) {
            return ['subdomain' => '', 'root' => $domain, 'is_root_domain' => true];
        }

        return [
            'subdomain' => implode('.', array_slice($parts, 0, -2)),
            'root' => implode('.', array_slice($parts, -2)),
            'is_root_domain' => false,
        ];
    }

    /**
     * Render custom domain pending notice
     *
     * @since 0.1.6
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_custom_domain_pending($settings) {
        $custom_domain = $settings['custom_domain'] ?? '';
        $domain_status = $settings['custom_domain_status'] ?? '';
        $parsed = $this->parse_custom_domain($custom_domain);

        if ('pending_ssl' === $domain_status) {
            $status_message = __('DNS verified. SSL certificate is being issued...', 'bandwidth-saver');
            $show_dns = false;
            $icon_class = 'is-pending-ssl';
        } elseif ('error' === $domain_status) {
            $status_message = __('Verification failed. Please check your DNS settings.', 'bandwidth-saver');
            $show_dns = true;
            $icon_class = 'is-error';
        } else {
            $status_message = __('Configure your DNS to activate this domain.', 'bandwidth-saver');
            $show_dns = true;
            $icon_class = 'is-pending';
        }
        ?>
        <div class="imgpro-domain-pending-card <?php echo esc_attr($icon_class); ?>" id="imgpro-pending-notice">
            <div class="imgpro-domain-pending-header">
                <div class="imgpro-domain-pending-icon">
                    <?php if ('pending_ssl' === $domain_status): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    <?php elseif ('error' === $domain_status): ?>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M12.5 7.5l-5 5m0-5l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2"/><path d="M10 6v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <?php endif; ?>
                </div>
                <div class="imgpro-domain-pending-info">
                    <strong><?php echo esc_html($custom_domain); ?></strong>
                    <span><?php echo esc_html($status_message); ?></span>
                </div>
                <button type="button" class="imgpro-btn imgpro-btn-sm imgpro-btn-secondary" id="imgpro-check-domain-pending">
                    <?php esc_html_e('Check Status', 'bandwidth-saver'); ?>
                </button>
            </div>

            <?php if ($show_dns): ?>
                <div class="imgpro-domain-pending-dns">
                    <span class="imgpro-domain-pending-dns-label"><?php esc_html_e('Add this DNS record:', 'bandwidth-saver'); ?></span>
                    <div class="imgpro-domain-pending-dns-record">
                        <div class="imgpro-dns-item">
                            <span class="imgpro-dns-item-label"><?php esc_html_e('Type', 'bandwidth-saver'); ?></span>
                            <code>CNAME</code>
                        </div>
                        <div class="imgpro-dns-item">
                            <span class="imgpro-dns-item-label"><?php esc_html_e('Name', 'bandwidth-saver'); ?></span>
                            <code><?php echo esc_html($parsed['is_root_domain'] ? '@' : $parsed['subdomain']); ?></code>
                        </div>
                        <div class="imgpro-dns-item">
                            <span class="imgpro-dns-item-label"><?php esc_html_e('Target', 'bandwidth-saver'); ?></span>
                            <code><?php echo esc_html(ImgPro_CDN_Settings::CUSTOM_DOMAIN_TARGET); ?></code>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render source URLs section
     *
     * @since 0.2.0
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_source_urls_section($settings) {
        // Single-tier model: all users get unlimited source URLs
        $is_paid = ImgPro_CDN_Settings::is_paid($settings);

        ?>
        <div class="imgpro-source-urls-card" id="imgpro-source-urls-section" data-is-paid="<?php echo $is_paid ? '1' : '0'; ?>">
            <div class="imgpro-source-urls-header">
                <h4><?php esc_html_e('Source URLs', 'bandwidth-saver'); ?></h4>
                <p class="imgpro-source-urls-description">
                    <?php esc_html_e('Domains where your media is hosted. The CDN will proxy media from these origins.', 'bandwidth-saver'); ?>
                </p>
            </div>

            <!-- Source URLs List (populated by JavaScript) -->
            <div class="imgpro-source-urls-list" id="imgpro-source-urls-list">
                <div class="imgpro-source-urls-loading">
                    <svg class="imgpro-spinner" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" stroke-opacity="0.2"/>
                        <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span><?php esc_html_e('Loading...', 'bandwidth-saver'); ?></span>
                </div>
            </div>

            <!-- Add Source URL Form -->
            <div class="imgpro-source-urls-form">
                <div class="imgpro-source-urls-input-group" id="imgpro-source-urls-input-wrapper">
                    <input
                        type="text"
                        id="imgpro-source-url-input"
                        class="imgpro-input"
                        placeholder="<?php esc_attr_e('cdn.example.com', 'bandwidth-saver'); ?>"
                        autocomplete="off"
                    />
                    <button type="button" class="imgpro-btn imgpro-btn-primary" id="imgpro-add-source-url">
                        <?php esc_html_e('Add Domain', 'bandwidth-saver'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render custom domain section
     *
     * @since 0.1.6
     * @param array $settings Plugin settings.
     * @return void
     */
    private function render_custom_domain_section($settings) {
        $custom_domain = $settings['custom_domain'] ?? '';
        $domain_status = $settings['custom_domain_status'] ?? '';
        $has_custom_domain = !empty($custom_domain);
        $can_use_custom_domain = ImgPro_CDN_Settings::has_custom_domain($settings);
        ?>
        <div class="imgpro-custom-domain-card" id="imgpro-custom-domain-section">
            <div class="imgpro-custom-domain-header">
                <h4><?php esc_html_e('Custom Domain', 'bandwidth-saver'); ?></h4>
                <p><?php esc_html_e('Serve media from your own branded domain.', 'bandwidth-saver'); ?></p>
            </div>

            <?php if (!$has_custom_domain): ?>
                <div class="imgpro-custom-domain-form" id="imgpro-custom-domain-form">
                    <div class="imgpro-custom-domain-input-group">
                        <input
                            type="text"
                            id="imgpro-custom-domain-input"
                            placeholder="cdn.yourdomain.com"
                            class="imgpro-input"
                            <?php echo !$can_use_custom_domain ? 'disabled' : ''; ?>
                        >
                        <button type="button" class="imgpro-btn imgpro-btn-primary" id="imgpro-add-domain" <?php echo !$can_use_custom_domain ? 'disabled' : ''; ?>>
                            <?php esc_html_e('Add Domain', 'bandwidth-saver'); ?>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="imgpro-custom-domain-configured" id="imgpro-custom-domain-status" data-status="<?php echo esc_attr($domain_status); ?>">
                    <div class="imgpro-custom-domain-info">
                        <code class="imgpro-custom-domain-value"><?php echo esc_html($custom_domain); ?></code>
                        <?php if ('active' === $domain_status): ?>
                            <span class="imgpro-domain-badge imgpro-domain-badge-active">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M10 3L4.5 8.5 2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?php esc_html_e('Active', 'bandwidth-saver'); ?>
                            </span>
                        <?php else: ?>
                            <span class="imgpro-domain-badge imgpro-domain-badge-pending">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="5" stroke="currentColor" stroke-width="1.5"/></svg>
                                <?php esc_html_e('Pending', 'bandwidth-saver'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="imgpro-btn imgpro-btn-sm imgpro-btn-ghost imgpro-btn-danger" id="imgpro-remove-domain">
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M11 3L3 11M3 3l8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <?php esc_html_e('Remove', 'bandwidth-saver'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render page footer
     *
     * @since 0.1.7
     * @return void
     */
    private function render_footer() {
        ?>
        <div class="imgpro-footer">
            <p>
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: 1: Plugin page link, 2: ImgPro link, 3: Cloudflare link */
                        __('%1$s by %2$s, powered by %3$s', 'bandwidth-saver'),
                        '<a href="https://wordpress.org/plugins/bandwidth-saver/" target="_blank">Bandwidth Saver</a>',
                        '<a href="https://img.pro" target="_blank">ImgPro</a>',
                        '<a href="https://cloudflare.com" target="_blank">Cloudflare</a>'
                    )
                );
                ?>
            </p>
        </div>
        <?php
    }
}
