<?php
/**
 * ImgPro CDN Admin AJAX Handlers
 *
 * @package ImgPro_CDN
 * @since   0.1.2
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handlers for admin operations
 *
 * Handles all AJAX operations including toggle, checkout,
 * subscription management, free registration, and account recovery.
 *
 * @since 0.1.2
 */
class ImgPro_CDN_Admin_Ajax {

    /**
     * Settings instance
     *
     * @since 0.1.2
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * API client instance
     *
     * @since 0.1.7
     * @var ImgPro_CDN_API
     */
    private $api;

    /**
     * Constructor
     *
     * @since 0.1.2
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
        $this->api = ImgPro_CDN_API::instance();
    }

    /**
     * Register AJAX hooks
     *
     * @since 0.1.2
     * @return void
     */
    public function register_hooks() {
        add_action('wp_ajax_imgpro_cdn_toggle_enabled', [$this, 'ajax_toggle_enabled']);
        add_action('wp_ajax_imgpro_cdn_checkout', [$this, 'ajax_checkout']);
        add_action('wp_ajax_imgpro_cdn_manage_subscription', [$this, 'ajax_manage_subscription']);
        // REMOVED: imgpro_cdn_recover_account - deprecated since v0.1.9
        // Use imgpro_cdn_request_recovery + imgpro_cdn_verify_recovery instead
        add_action('wp_ajax_imgpro_cdn_request_recovery', [$this, 'ajax_request_recovery']);
        add_action('wp_ajax_imgpro_cdn_verify_recovery', [$this, 'ajax_verify_recovery']);
        add_action('wp_ajax_imgpro_cdn_add_custom_domain', [$this, 'ajax_add_custom_domain']);
        add_action('wp_ajax_imgpro_cdn_check_custom_domain', [$this, 'ajax_check_custom_domain']);
        add_action('wp_ajax_imgpro_cdn_remove_custom_domain', [$this, 'ajax_remove_custom_domain']);
        add_action('wp_ajax_imgpro_cdn_remove_cdn_domain', [$this, 'ajax_remove_cdn_domain']);

        // New endpoints for free tier
        add_action('wp_ajax_imgpro_cdn_free_register', [$this, 'ajax_free_register']);
        add_action('wp_ajax_imgpro_cdn_update_onboarding_step', [$this, 'ajax_update_onboarding_step']);
        add_action('wp_ajax_imgpro_cdn_complete_onboarding', [$this, 'ajax_complete_onboarding']);
        add_action('wp_ajax_imgpro_cdn_sync_stats', [$this, 'ajax_sync_stats']);
        add_action('wp_ajax_imgpro_cdn_health_check', [$this, 'ajax_health_check']);

        // Analytics endpoints
        add_action('wp_ajax_imgpro_cdn_get_usage', [$this, 'ajax_get_usage']);
        add_action('wp_ajax_imgpro_cdn_get_insights', [$this, 'ajax_get_insights']);
        add_action('wp_ajax_imgpro_cdn_get_daily_usage', [$this, 'ajax_get_daily_usage']);
        add_action('wp_ajax_imgpro_cdn_get_usage_periods', [$this, 'ajax_get_usage_periods']);

        // Source URL management endpoints
        add_action('wp_ajax_imgpro_cdn_get_source_urls', [$this, 'ajax_get_source_urls']);
        add_action('wp_ajax_imgpro_cdn_add_source_url', [$this, 'ajax_add_source_url']);
        add_action('wp_ajax_imgpro_cdn_remove_source_url', [$this, 'ajax_remove_source_url']);
    }

    /**
     * Generate cryptographically secure API key
     *
     * @since 0.1.2
     * @return string API key in format: imgpro_[64 hex chars].
     */
    private function generate_api_key() {
        $random_bytes = random_bytes(32);
        $hex = bin2hex($random_bytes);
        return 'imgpro_' . $hex;
    }

    /**
     * Handle account conflict - trigger secure recovery flow
     *
     * DRY helper to avoid duplicate code in ajax_free_register() and ajax_checkout()
     *
     * @since 0.2.0
     * @param string $site_url The site URL
     * @return void Sends JSON response and exits
     */
    private function handle_account_conflict($site_url) {
        // Clear any stale API key from singleton before calling public endpoint
        // This prevents Bearer tokens from previous authenticated calls being sent
        $this->api->set_api_key(null);

        // Automatically request recovery code
        $recovery_result = $this->api->request_recovery($site_url);

        if (!is_wp_error($recovery_result)) {
            // Recovery email sent - tell JS to show verification modal
            wp_send_json_error([
                'message'       => __('We found an existing account for this site.', 'bandwidth-saver'),
                'show_recovery' => true,
                'email_hint'    => $recovery_result['email_hint'] ?? null,
                'code'          => 'account_exists',
            ]);
            return;
        }

        // Recovery request failed
        wp_send_json_error([
            'message' => __('An account exists but recovery failed. Please try again.', 'bandwidth-saver'),
            'code'    => 'recovery_failed',
        ]);
    }

    /**
     * Auto-register a cloud account when toggling on for the first time
     *
     * This enables frictionless activation without requiring email upfront.
     * Email is collected later when user upgrades via Stripe.
     *
     * @since 0.2.3
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private function auto_register_cloud_account() {
        $site_url = get_site_url();

        // Register without email (frictionless free activation)
        $result = $this->api->create_site(null, $site_url, false);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            // Handle existing account with email - needs recovery
            if ('conflict' === $error_code) {
                // Clear any stale API key from singleton before calling public endpoint
                $this->api->set_api_key(null);

                // Automatically request recovery code
                $recovery_result = $this->api->request_recovery($site_url);

                if (!is_wp_error($recovery_result)) {
                    // Check if this is a no-email account (no recovery needed)
                    if (!empty($recovery_result['no_recovery_needed'])) {
                        // This shouldn't happen since we just tried to register
                        // But handle it gracefully - try again
                        return new WP_Error('registration_failed', __('Registration failed. Please try again.', 'bandwidth-saver'));
                    }

                    // Recovery email sent
                    return new WP_Error('account_exists', __('We found an existing account for this site. Please check your email for a verification code.', 'bandwidth-saver'), [
                        'show_recovery' => true,
                        'email_hint' => $recovery_result['email_hint'] ?? null,
                    ]);
                }

                return new WP_Error('recovery_failed', __('An account exists but recovery failed. Please try again.', 'bandwidth-saver'));
            }

            // Other API errors
            return $result;
        }

        // Success - save site data to settings
        $this->save_site_data_to_settings($result);

        return true;
    }

    /**
     * Save site data from API response to plugin settings
     *
     * @since 0.2.3
     * @param array $site Site data from API
     * @return void
     */
    private function save_site_data_to_settings($site) {
        $data = [
            'setup_mode'           => ImgPro_CDN_Settings::MODE_CLOUD,
            'cloud_api_key'        => $site['api_key'] ?? '',
            'cloud_tier'           => $site['tier']['id'] ?? ImgPro_CDN_Settings::TIER_FREE,
            'onboarding_completed' => true,
        ];

        // Only set email if provided
        if (!empty($site['email'])) {
            $data['cloud_email'] = $site['email'];
        }

        // Extract source domains if available
        if (!empty($site['domains'])) {
            $domains = [];
            foreach ($site['domains'] as $domain_info) {
                if (!empty($domain_info['domain'])) {
                    $domains[] = $domain_info['domain'];
                }
            }
            if (!empty($domains)) {
                $data['source_urls'] = $domains;
            }
        }

        $this->settings->update($data);
    }

    /**
     * AJAX handler for toggling CDN enabled state
     *
     * @since 0.1.2
     * @return void
     */
    public function ajax_toggle_enabled() {
        // SECURITY: Verify nonce inline so WordPress recognizes the check
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_toggle_enabled'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('toggle_enabled');

        $enabled = isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash($_POST['enabled']));
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : '';

        // Validate mode
        if (!in_array($mode, [ImgPro_CDN_Settings::MODE_CLOUD, ImgPro_CDN_Settings::MODE_CLOUDFLARE], true)) {
            wp_send_json_error(['message' => __('Invalid mode specified', 'bandwidth-saver')]);
            return;
        }

        $current_settings = $this->settings->get_all();

        $auto_registered = false;

        // Check if the mode is properly configured before allowing enable
        if ($enabled && !ImgPro_CDN_Settings::is_mode_valid($mode, $current_settings)) {
            // For cloud mode: auto-register if not yet set up
            if (ImgPro_CDN_Settings::MODE_CLOUD === $mode) {
                $registration_result = $this->auto_register_cloud_account();
                if (is_wp_error($registration_result)) {
                    $error_data = $registration_result->get_error_data();

                    // Check if this needs recovery flow (account with email)
                    if (!empty($error_data['show_recovery'])) {
                        wp_send_json_error([
                            'message'       => $registration_result->get_error_message(),
                            'code'          => $registration_result->get_error_code(),
                            'show_recovery' => true,
                            'email_hint'    => $error_data['email_hint'] ?? null,
                        ]);
                        return;
                    }

                    wp_send_json_error([
                        'message' => $registration_result->get_error_message(),
                        'code' => $registration_result->get_error_code(),
                    ]);
                    return;
                }
                // Registration succeeded - refresh settings and flag for page reload
                $this->settings->clear_cache();
                $current_settings = $this->settings->get_all();
                $auto_registered = true;
            } else {
                // Cloudflare mode still requires manual setup
                wp_send_json_error(['message' => __('Please complete setup first before enabling.', 'bandwidth-saver')]);
                return;
            }
        }

        // Determine the field key for this mode
        $enabled_key = ImgPro_CDN_Settings::MODE_CLOUD === $mode ? 'cloud_enabled' : 'cloudflare_enabled';

        // Check if already in desired state
        $current_enabled = ImgPro_CDN_Settings::is_mode_enabled($mode, $current_settings);
        if ($current_enabled === $enabled) {
            $message = $enabled
                ? __('Media CDN is active. Media is loading from Cloudflare.', 'bandwidth-saver')
                : __('Media CDN is disabled. Media is loading from your server.', 'bandwidth-saver');

            wp_send_json_success(['message' => $message]);
            return;
        }

        // Update the mode-specific enabled state
        $current_settings[$enabled_key] = $enabled;

        // Also update setup_mode if enabling this mode
        if ($enabled) {
            $current_settings['setup_mode'] = $mode;
        }

        $result = update_option(ImgPro_CDN_Settings::OPTION_KEY, $current_settings);
        $this->settings->clear_cache();

        if (false !== $result) {
            $message = $enabled
                ? __('Media CDN enabled. Media now loads from the global network.', 'bandwidth-saver')
                : __('Media CDN disabled. Media now loads from your server.', 'bandwidth-saver');

            $response = ['message' => $message];

            // If we just auto-registered, reload page to show full dashboard
            if ($auto_registered) {
                $response['redirect'] = admin_url('options-general.php?page=imgpro-cdn-settings&tab=cloud&activated=1');
            }

            wp_send_json_success($response);
        } else {
            wp_send_json_error(['message' => __('Could not save settings. Please try again.', 'bandwidth-saver')]);
        }
    }

    /**
     * AJAX handler for free tier registration
     *
     * @since 0.1.7
     * @return void
     */
    public function ajax_free_register() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_free_register'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('free_register');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : get_option('admin_email');
        $site_url = get_site_url();
        $marketing_opt_in = isset($_POST['marketing_opt_in']) && '1' === sanitize_text_field(wp_unslash($_POST['marketing_opt_in']));

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'bandwidth-saver')]);
            return;
        }

        // Call API to register
        $result = $this->api->create_site($email, $site_url, $marketing_opt_in);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            // Handle existing account - trigger secure recovery flow
            if ('conflict' === $error_code) {
                $this->handle_account_conflict($site_url);
                return;
            }

            // Connection error - fall back to local registration
            if ('connection_error' === $error_code) {
                $api_key = $this->generate_api_key();
                $this->settings->update([
                    'cloud_api_key' => $api_key,
                    'cloud_email' => $email,
                    'cloud_tier' => ImgPro_CDN_Settings::TIER_FREE,
                    'setup_mode' => ImgPro_CDN_Settings::MODE_CLOUD,
                    'onboarding_step' => 3,
                    'marketing_opt_in' => $marketing_opt_in,
                    'bandwidth_limit' => ImgPro_CDN_Settings::FREE_BANDWIDTH_LIMIT,
                    'cache_limit' => ImgPro_CDN_Settings::FREE_CACHE_LIMIT,
                ]);

                wp_send_json_success([
                    'message' => __('Account created! Ready to activate your CDN.', 'bandwidth-saver'),
                    'next_step' => 3
                ]);
                return;
            }

            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $error_code
            ]);
            return;
        }

        // Success - save site data
        $this->save_site_to_settings($result, $email, $marketing_opt_in);

        wp_send_json_success([
            'message' => __('Account created! Ready to activate your CDN.', 'bandwidth-saver'),
            'next_step' => 3
        ]);
    }

    /**
     * Extract common site data for settings update
     *
     * Consolidates site data extraction used by both save_site_to_settings()
     * and update_settings_from_site() to ensure consistent handling.
     *
     * @since 0.1.9
     * @param array $site Site data from API.
     * @return array Settings data to update.
     */
    private function extract_site_settings($site) {
        $tier_id = $this->api->get_tier_id($site);
        $usage = $this->api->get_usage($site);
        $domain = $this->api->get_custom_domain($site);

        $data = [
            'cloud_tier' => $tier_id,
            'bandwidth_used' => $usage['bandwidth_used'],
            'cache_hits' => $usage['cache_hits'],
            'cache_misses' => $usage['cache_misses'],
            'stats_updated_at' => time(),
            // Always set limits (use defaults for free tier)
            'bandwidth_limit' => $usage['bandwidth_limit'] ?: ImgPro_CDN_Settings::FREE_BANDWIDTH_LIMIT,
            'cache_limit' => $usage['cache_limit'] ?: ImgPro_CDN_Settings::FREE_CACHE_LIMIT,
            // Billing period timestamps (convert ISO strings to Unix timestamps)
            'billing_period_start' => !empty($usage['period_start']) ? strtotime($usage['period_start']) : 0,
            'billing_period_end' => !empty($usage['period_end']) ? strtotime($usage['period_end']) : 0,
        ];

        // Update custom domain if present
        if ($domain) {
            $data['custom_domain'] = $domain['domain'];
            $data['custom_domain_status'] = $domain['status'];
        }

        // Disable if subscription is inactive
        if (ImgPro_CDN_Settings::is_subscription_inactive(['cloud_tier' => $tier_id])) {
            $data['cloud_enabled'] = false;
        }

        return $data;
    }

    /**
     * Save site data from API response to local settings
     *
     * Used after account creation or recovery to store all account details.
     *
     * @since 0.1.7
     * @param array     $site             Site data from API.
     * @param string    $email            User email.
     * @param bool|null $marketing_opt_in Marketing consent. Pass null to preserve existing value.
     * @return void
     */
    private function save_site_to_settings($site, $email, $marketing_opt_in = null) {
        $data = $this->extract_site_settings($site);

        // Add registration-specific fields
        $data['cloud_api_key'] = $site['api_key'] ?? '';
        $data['cloud_email'] = $email;
        $data['setup_mode'] = ImgPro_CDN_Settings::MODE_CLOUD;
        $data['onboarding_step'] = 3;

        // Only update marketing_opt_in if explicitly provided (not null)
        // This preserves the user's existing consent during recovery/upgrades
        if (null !== $marketing_opt_in) {
            $data['marketing_opt_in'] = $marketing_opt_in;
        }

        $this->settings->update($data);
    }

    /**
     * AJAX handler for updating onboarding step
     *
     * @since 0.1.7
     * @return void
     */
    public function ajax_update_onboarding_step() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_update_onboarding_step'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('onboarding');

        $step = isset($_POST['step']) ? absint($_POST['step']) : 1;
        $step = max(1, min(4, $step)); // Clamp to 1-4

        $this->settings->update(['onboarding_step' => $step]);

        wp_send_json_success(['step' => $step]);
    }

    /**
     * AJAX handler for completing onboarding
     *
     * @since 0.1.7
     * @return void
     */
    public function ajax_complete_onboarding() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_complete_onboarding'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('onboarding');

        $this->settings->update([
            'onboarding_completed' => true,
            'onboarding_step' => 4,
            'cloud_enabled' => true,
        ]);

        wp_send_json_success([
            'message' => __('Setup complete!', 'bandwidth-saver'),
            'redirect' => admin_url('options-general.php?page=imgpro-cdn-settings&tab=cloud&activated=1')
        ]);
    }

    /**
     * AJAX handler for syncing stats from API
     *
     * Uses the API client to fetch fresh site data.
     *
     * @since 0.1.7
     * @return void
     */
    public function ajax_sync_stats() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_sync_stats'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('sync_stats');

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Force fetch fresh site data
        $site = $this->api->get_site($api_key, true);

        if (is_wp_error($site)) {
            wp_send_json_error([
                'message' => __('Could not sync stats. Please try again.', 'bandwidth-saver'),
                'code' => 'sync_error'
            ]);
            return;
        }

        // Update local settings with fresh data
        $this->update_settings_from_site($site);

        // Get updated settings for response
        $updated_settings = $this->settings->get_all();
        $bandwidth_limit = ImgPro_CDN_Settings::get_bandwidth_limit($updated_settings);
        $is_unlimited = $bandwidth_limit < 0;

        wp_send_json_success([
            'bandwidth_used' => $updated_settings['bandwidth_used'] ?? 0,
            'bandwidth_limit' => $bandwidth_limit,
            'bandwidth_percentage' => ImgPro_CDN_Settings::get_bandwidth_percentage($updated_settings),
            'cache_hits' => $updated_settings['cache_hits'] ?? 0,
            'cache_misses' => $updated_settings['cache_misses'] ?? 0,
            'is_unlimited' => $is_unlimited,
            'formatted' => [
                'bandwidth_used' => ImgPro_CDN_Settings::format_bytes($updated_settings['bandwidth_used'] ?? 0),
                'bandwidth_limit' => $is_unlimited ? __('Unlimited', 'bandwidth-saver') : ImgPro_CDN_Settings::format_bytes($bandwidth_limit, 0),
            ]
        ]);
    }

    /**
     * Update local settings from site API response
     *
     * Used for syncing stats and refreshing site data.
     *
     * @since 0.1.7
     * @param array $site Site data from API.
     * @return void
     */
    private function update_settings_from_site($site) {
        $this->settings->update($this->extract_site_settings($site));
    }

    /**
     * AJAX handler for Stripe checkout (paid tier upgrade)
     *
     * @since 0.1.2
     * @return void
     */
    public function ajax_checkout() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_checkout'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('checkout');

        // Always upgrade to unlimited tier (single paid tier model)
        // tier_id parameter kept for backwards compatibility but ignored
        $tier_id = 'unlimited';

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();
        $email = get_option('admin_email');
        $site_url = get_site_url();

        // Ensure we have an API key (create or recover site if needed)
        if (empty($api_key)) {
            $site = $this->api->create_site($email, $site_url);

            if (is_wp_error($site)) {
                $error_code = $site->get_error_code();

                // Account already exists - trigger secure recovery flow
                if ('conflict' === $error_code) {
                    $this->handle_account_conflict($site_url);
                    return;
                } else {
                    wp_send_json_error([
                        'message' => __('Could not create account. Please try again.', 'bandwidth-saver'),
                        'code' => $error_code
                    ]);
                    return;
                }
            }

            $api_key = $site['api_key'] ?? '';
            $this->save_site_to_settings($site, $email);
        }

        // Create checkout session with selected tier
        $result = $this->api->create_checkout($api_key, $tier_id);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            if ('connection_error' === $error_code) {
                wp_send_json_error([
                    'message' => __('Could not connect to billing service. Please try again in a moment.', 'bandwidth-saver'),
                    'code' => 'connection_error'
                ]);
                return;
            }

            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $error_code
            ]);
            return;
        }

        // Check if already subscribed - sync the subscription
        if (!empty($result['already_subscribed']) && !empty($result['site'])) {
            $site = $result['site'];
            $this->save_site_to_settings($site, $site['email'] ?? get_option('admin_email'));

            wp_send_json_success([
                'recovered' => true,
                'message' => __('Your subscription has been restored.', 'bandwidth-saver'),
            ]);
            return;
        }

        // Check if subscription was upgraded directly (no checkout needed)
        if (!empty($result['upgraded'])) {
            // Sync site data from API to get new tier limits
            $site_data = $this->api->get_site($api_key);
            if (!is_wp_error($site_data)) {
                $this->save_site_to_settings($site_data, $site_data['email'] ?? get_option('admin_email'));
            }

            wp_send_json_success([
                'upgraded' => true,
                'message' => sprintf(
                    /* translators: %s: tier name */
                    __('Successfully upgraded to %s!', 'bandwidth-saver'),
                    ucfirst($result['tier_id'] ?? 'new plan')
                ),
            ]);
            return;
        }

        if (!empty($result['url'])) {
            set_transient('imgpro_cdn_pending_payment', true, HOUR_IN_SECONDS);
            wp_send_json_success(['checkout_url' => $result['url']]);
        } else {
            wp_send_json_error([
                'message' => __('Could not create checkout. Please try again.', 'bandwidth-saver'),
                'code' => 'invalid_response'
            ]);
        }
    }

    // =========================================================================
    // REMOVED (2024-11-30): ajax_recover_account() - deprecated since v0.1.9
    // Was a shim that redirected to ajax_request_recovery()
    // Clients should use ajax_request_recovery() + ajax_verify_recovery() directly
    // =========================================================================

    /**
     * AJAX handler for requesting account recovery (step 1)
     *
     * Sends a verification code to the registered email.
     *
     * @since 0.1.9
     * @return void
     */
    public function ajax_request_recovery() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_request_recovery'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('recovery');

        // Clear any stale API key from singleton before calling public endpoint
        $this->api->set_api_key(null);

        $site_url = get_site_url();
        $result = $this->api->request_recovery($site_url);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ]);
            return;
        }

        wp_send_json_success([
            'message'    => $result['message'] ?? __('If an account exists, a verification code has been sent to the registered email.', 'bandwidth-saver'),
            'email_hint' => $result['email_hint'] ?? null,
            'step'       => 'verify',
        ]);
    }

    /**
     * AJAX handler for verifying recovery code (step 2)
     *
     * Verifies the code and restores account access.
     *
     * @since 0.1.9
     * @return void
     */
    public function ajax_verify_recovery() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_verify_recovery'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('recovery');

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        if (empty($code) || strlen($code) !== 6) {
            wp_send_json_error([
                'message' => __('Please enter a valid 6-digit verification code.', 'bandwidth-saver'),
                'code'    => 'invalid_code',
            ]);
            return;
        }

        $site_url = get_site_url();
        $site = $this->api->verify_recovery($site_url, $code);

        if (is_wp_error($site)) {
            wp_send_json_error([
                'message' => $site->get_error_message(),
                'code'    => $site->get_error_code(),
            ]);
            return;
        }

        // Save recovered site data
        $email = $site['email'] ?? get_option('admin_email');
        $this->save_site_to_settings($site, $email);

        // Enable CDN if valid subscription
        $current_tier_id = $this->api->get_tier_id($site);
        if (in_array($current_tier_id, [ImgPro_CDN_Settings::TIER_FREE, ImgPro_CDN_Settings::TIER_UNLIMITED, ImgPro_CDN_Settings::TIER_LITE, ImgPro_CDN_Settings::TIER_PRO, ImgPro_CDN_Settings::TIER_BUSINESS, ImgPro_CDN_Settings::TIER_ACTIVE], true)) {
            $this->settings->update([
                'cloud_enabled' => true,
                'onboarding_completed' => true,
                'onboarding_step' => 4, // Mark as fully complete for consistency
            ]);
        }

        // Check if user wanted to upgrade to a specific tier
        $pending_tier_id = isset($_POST['pending_tier_id']) ? sanitize_text_field(wp_unslash($_POST['pending_tier_id'])) : '';

        if (!empty($pending_tier_id)) {
            // Tier priority order (higher = better)
            $tier_priority = [
                ImgPro_CDN_Settings::TIER_FREE      => 1,
                ImgPro_CDN_Settings::TIER_LITE      => 2,
                ImgPro_CDN_Settings::TIER_PRO       => 3,
                ImgPro_CDN_Settings::TIER_BUSINESS  => 4,
                ImgPro_CDN_Settings::TIER_UNLIMITED => 5,
            ];

            $current_priority = $tier_priority[$current_tier_id] ?? 0;
            $pending_priority = $tier_priority[$pending_tier_id] ?? 0;

            // If upgrading to a higher tier, return flag so JS can show confirmation modal
            if ($pending_priority > $current_priority) {
                wp_send_json_success([
                    'message'         => __('Account recovered! Please confirm your upgrade.', 'bandwidth-saver'),
                    'recovered'       => true,
                    'show_upgrade'    => true,
                    'pending_tier_id' => $pending_tier_id,
                ]);
                return;
            }
        }

        wp_send_json_success([
            'message'   => __('Account recovered successfully! Your subscription is now active.', 'bandwidth-saver'),
            'recovered' => true,
        ]);
    }

    /**
     * AJAX handler for managing subscription
     *
     * @since 0.1.2
     * @return void
     */
    public function ajax_manage_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_manage_subscription'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('subscription');

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('No subscription found. Please subscribe first or recover your account.', 'bandwidth-saver')
            ]);
            return;
        }

        $result = $this->api->create_portal($api_key);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            if ('connection_error' === $error_code) {
                wp_send_json_error([
                    'message' => __('Could not connect to billing service. Please try again in a moment.', 'bandwidth-saver'),
                    'code' => 'connection_error'
                ]);
                return;
            }

            if ('unauthorized' === $error_code) {
                wp_send_json_error([
                    'message' => __('Could not verify your subscription. Try recovering your account.', 'bandwidth-saver'),
                    'code' => 'auth_error'
                ]);
                return;
            }

            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $error_code
            ]);
            return;
        }

        if (!empty($result['portal_url'])) {
            wp_send_json_success(['portal_url' => $result['portal_url']]);
        } else {
            wp_send_json_error([
                'message' => __('Could not open subscription portal. Please try again.', 'bandwidth-saver'),
                'code' => 'invalid_response'
            ]);
        }
    }

    /**
     * AJAX handler for adding a custom domain
     *
     * @since 0.1.6
     * @return void
     */
    public function ajax_add_custom_domain() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_add_custom_domain'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('custom_domain');

        $raw_domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
        $domain = ImgPro_CDN_Settings::sanitize_domain($raw_domain);
        if (empty($domain)) {
            wp_send_json_error(['message' => __('Please enter a valid domain (e.g., cdn.example.com)', 'bandwidth-saver')]);
            return;
        }

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('No subscription found. Please subscribe first.', 'bandwidth-saver')
            ]);
            return;
        }

        $result = $this->api->set_domain($api_key, $domain);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            if ('connection_error' === $error_code) {
                wp_send_json_error([
                    'message' => __('Could not connect to service. Please try again.', 'bandwidth-saver'),
                    'code' => 'connection_error'
                ]);
                return;
            }

            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $error_code
            ]);
            return;
        }

        $this->settings->update([
            'custom_domain' => $domain,
            'custom_domain_status' => 'pending_dns',
        ]);

        wp_send_json_success([
            'message' => __('Domain added. Please configure your DNS.', 'bandwidth-saver'),
            'domain' => $domain,
            'status' => 'pending_dns',
            'dns_record' => $result['dns_record'] ?? [
                'type' => 'CNAME',
                'hostname' => $domain,
                'target' => ImgPro_CDN_Settings::CUSTOM_DOMAIN_TARGET
            ]
        ]);
    }

    /**
     * AJAX handler for checking custom domain status
     *
     * @since 0.1.6
     * @return void
     */
    public function ajax_check_custom_domain() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_check_custom_domain'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('custom_domain');

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('No subscription found.', 'bandwidth-saver')
            ]);
            return;
        }

        $result = $this->api->get_domain($api_key);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => __('Could not check status. Please try again.', 'bandwidth-saver'),
                'code' => $result->get_error_code()
            ]);
            return;
        }

        // Update local status if changed
        $settings = $this->settings->get_all();
        if (!empty($result['status']) && $result['status'] !== $settings['custom_domain_status']) {
            $this->settings->update([
                'custom_domain_status' => $result['status'],
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for removing custom domain
     *
     * @since 0.1.6
     * @return void
     */
    public function ajax_remove_custom_domain() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_remove_custom_domain'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('custom_domain');

        // SECURITY: Use get_api_key() to decrypt the stored API key
        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('No subscription found.', 'bandwidth-saver')
            ]);
            return;
        }

        $result = $this->api->remove_domain($api_key);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => __('Could not remove domain. Please try again.', 'bandwidth-saver'),
                'code' => $result->get_error_code()
            ]);
            return;
        }

        $this->settings->update([
            'custom_domain' => '',
            'custom_domain_status' => '',
        ]);

        wp_send_json_success([
            'message' => __('Custom domain removed.', 'bandwidth-saver')
        ]);
    }

    /**
     * AJAX handler for removing CDN domain (self-hosted)
     *
     * @since 0.1.7
     * @return void
     */
    public function ajax_remove_cdn_domain() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_remove_cdn_domain'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('settings');

        // Clear the CDN URL and disable the CDN
        $this->settings->update([
            'cdn_url' => '',
            'cloudflare_enabled' => false,
        ]);

        wp_send_json_success([
            'message' => __('CDN domain removed. The Media CDN has been disabled.', 'bandwidth-saver')
        ]);
    }

    /**
     * AJAX handler for health check
     *
     * Checks CDN connectivity and configuration status.
     * Useful for diagnosing issues without exposing sensitive data.
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_health_check() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_health_check'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('health_check');

        $settings = $this->settings->get_all();
        $health = [
            'plugin_version' => IMGPRO_CDN_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'setup_mode' => $settings['setup_mode'] ?? 'none',
            'cdn_active' => ImgPro_CDN_Settings::is_cdn_active($settings),
            'checks' => [],
        ];

        // Check OpenSSL availability (required for encryption)
        $health['checks']['openssl'] = [
            'status' => function_exists('openssl_encrypt') ? 'ok' : 'warning',
            'message' => function_exists('openssl_encrypt')
                ? __('OpenSSL available', 'bandwidth-saver')
                : __('OpenSSL not available - API keys stored in plaintext', 'bandwidth-saver'),
        ];

        // Check AUTH_KEY configuration
        $auth_key_ok = defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here';
        $health['checks']['auth_key'] = [
            'status' => $auth_key_ok ? 'ok' : 'warning',
            'message' => $auth_key_ok
                ? __('AUTH_KEY properly configured', 'bandwidth-saver')
                : __('AUTH_KEY not properly configured - API key encryption disabled', 'bandwidth-saver'),
        ];

        // Check mode-specific configuration
        if (ImgPro_CDN_Settings::MODE_CLOUD === $settings['setup_mode']) {
            // Cloud mode checks
            $has_api_key = !empty($this->settings->get_api_key());
            $health['checks']['api_key'] = [
                'status' => $has_api_key ? 'ok' : 'error',
                'message' => $has_api_key
                    ? __('API key configured', 'bandwidth-saver')
                    : __('API key missing', 'bandwidth-saver'),
            ];

            $tier = $settings['cloud_tier'] ?? '';
            $tier_valid = in_array($tier, [
                ImgPro_CDN_Settings::TIER_FREE,
                ImgPro_CDN_Settings::TIER_UNLIMITED,
                ImgPro_CDN_Settings::TIER_LITE,
                ImgPro_CDN_Settings::TIER_PRO,
                ImgPro_CDN_Settings::TIER_BUSINESS,
                ImgPro_CDN_Settings::TIER_ACTIVE,
            ], true);
            $health['checks']['subscription'] = [
                'status' => $tier_valid ? 'ok' : 'error',
                'message' => $tier_valid
                    /* translators: %s: subscription tier name (e.g., "free", "pro", "business") */
                    ? sprintf(__('Subscription active (%s)', 'bandwidth-saver'), $tier)
                    : __('No active subscription', 'bandwidth-saver'),
            ];

        } elseif (ImgPro_CDN_Settings::MODE_CLOUDFLARE === $settings['setup_mode']) {
            // Self-hosted mode checks
            $cdn_url = $settings['cdn_url'] ?? '';
            $cdn_valid = !empty($cdn_url) && ImgPro_CDN_Settings::is_valid_cdn_url($cdn_url);
            $health['checks']['cdn_url'] = [
                'status' => $cdn_valid ? 'ok' : 'error',
                'message' => $cdn_valid
                    /* translators: %s: CDN domain URL (e.g., "cdn.example.com") */
                    ? sprintf(__('CDN URL configured: %s', 'bandwidth-saver'), $cdn_url)
                    : __('CDN URL not configured or invalid', 'bandwidth-saver'),
            ];
        }

        // Overall status
        $has_errors = false;
        $has_warnings = false;
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'error') {
                $has_errors = true;
            }
            if ($check['status'] === 'warning') {
                $has_warnings = true;
            }
        }

        $health['overall_status'] = $has_errors ? 'error' : ($has_warnings ? 'warning' : 'ok');

        wp_send_json_success($health);
    }

    /**
     * AJAX handler for getting usage analytics (insights + daily chart)
     *
     * Batched endpoint for analytics section - returns both insights
     * and daily chart data in a single request.
     *
     * @since 0.2.2
     * @return void
     */
    public function ajax_get_usage() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_get_usage'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('analytics');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get days parameter (default 30)
        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        $days = min(90, max(1, $days));

        $data = $this->api->get_usage_analytics($api_key, $days);

        if (is_wp_error($data)) {
            wp_send_json_error([
                'message' => __('Could not fetch usage data. Please try again.', 'bandwidth-saver'),
                'code' => 'usage_error'
            ]);
            return;
        }

        // Transform insights for frontend
        $insights = $data['insights'] ?? [];
        $transformed_insights = [
            'avg_daily_bandwidth' => isset($insights['bandwidth']['avg_daily'])
                ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['avg_daily'])
                : null,
            'projected_period_bandwidth' => isset($insights['bandwidth']['projected'])
                ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['projected'])
                : null,
            'cache_hit_rate' => isset($insights['recent']['cache_hit_rate'])
                ? $insights['recent']['cache_hit_rate']
                : null,
            'cache_hits' => isset($insights['recent']['cache_hits'])
                ? $insights['recent']['cache_hits']
                : null,
            'cache_misses' => isset($insights['recent']['cache_misses'])
                ? $insights['recent']['cache_misses']
                : null,
            'days_remaining' => isset($insights['period']['days_remaining'])
                ? $insights['period']['days_remaining']
                : null,
            'total_requests' => isset($insights['recent']['requests'])
                ? $insights['recent']['requests']
                : null,
            // New request-focused fields (v1.0+)
            'requests' => isset($insights['requests']) ? $insights['requests'] : null,
            'period' => isset($insights['period']) ? $insights['period'] : null,
        ];

        // Extract daily array
        $daily_data = $data['daily'] ?? [];

        wp_send_json_success([
            'insights' => $transformed_insights,
            'daily' => $daily_data,
        ]);
    }

    /**
     * AJAX handler for getting usage insights
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_get_insights() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_get_insights'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('analytics');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        $insights = $this->api->get_usage_insights($api_key);

        if (is_wp_error($insights)) {
            wp_send_json_error([
                'message' => __('Could not fetch insights. Please try again.', 'bandwidth-saver'),
                'code' => 'insights_error'
            ]);
            return;
        }

        // Transform API response to match frontend expectations
        $transformed = [
            'avg_daily_bandwidth' => isset($insights['bandwidth']['avg_daily'])
                ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['avg_daily'])
                : null,
            'projected_period_bandwidth' => isset($insights['bandwidth']['projected'])
                ? ImgPro_CDN_Settings::format_bytes($insights['bandwidth']['projected'])
                : null,
            'cache_hit_rate' => isset($insights['recent']['cache_hit_rate'])
                ? $insights['recent']['cache_hit_rate']
                : null,
            'cache_hits' => isset($insights['recent']['cache_hits'])
                ? $insights['recent']['cache_hits']
                : null,
            'cache_misses' => isset($insights['recent']['cache_misses'])
                ? $insights['recent']['cache_misses']
                : null,
            'days_remaining' => isset($insights['period']['days_remaining'])
                ? $insights['period']['days_remaining']
                : null,
            'total_requests' => isset($insights['recent']['requests'])
                ? $insights['recent']['requests']
                : null,
            // New request-focused fields (v1.0+)
            'requests' => isset($insights['requests']) ? $insights['requests'] : null,
            'period' => isset($insights['period']) ? $insights['period'] : null,
        ];

        wp_send_json_success($transformed);
    }

    /**
     * AJAX handler for getting daily usage data
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_get_daily_usage() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_get_daily_usage'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('analytics');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get days parameter (default 30)
        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        $days = min(90, max(1, $days));

        $daily = $this->api->get_daily_usage($api_key, $days);

        if (is_wp_error($daily)) {
            wp_send_json_error([
                'message' => __('Could not fetch daily usage. Please try again.', 'bandwidth-saver'),
                'code' => 'daily_usage_error'
            ]);
            return;
        }

        // Extract just the daily array (frontend expects array, not object with metadata)
        $daily_data = isset($daily['daily']) ? $daily['daily'] : [];

        wp_send_json_success($daily_data);
    }

    /**
     * AJAX handler for getting historical billing periods
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_get_usage_periods() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_get_usage_periods'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('analytics');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get limit parameter (default 12)
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 12;
        $limit = min(100, max(1, $limit));

        $periods = $this->api->get_usage_periods($api_key, $limit);

        if (is_wp_error($periods)) {
            wp_send_json_error([
                'message' => __('Could not fetch usage periods. Please try again.', 'bandwidth-saver'),
                'code' => 'periods_error'
            ]);
            return;
        }

        wp_send_json_success($periods);
    }

    /**
     * AJAX handler for getting source URLs
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_get_source_urls() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_get_source_urls'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('api');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get full response from API (includes count, max_domains, tier_name)
        $full_response = $this->api->get_source_urls($api_key, true);

        if (is_wp_error($full_response)) {
            wp_send_json_error([
                'message' => __('Could not fetch source URLs. Please try again.', 'bandwidth-saver'),
                'code' => 'source_urls_error'
            ]);
            return;
        }

        $source_urls = $full_response['domains'] ?? [];

        // Sync to local settings for use by rewriter (no API calls on page load)
        $domain_list = array_map(function($item) {
            return is_array($item) && isset($item['domain']) ? $item['domain'] : $item;
        }, $source_urls);
        $this->settings->update(['source_urls' => $domain_list]);

        // Single-tier model: all users get unlimited source URLs
        wp_send_json_success([
            'source_urls' => $source_urls,
            'count' => $full_response['count'] ?? count($source_urls),
            'max_domains' => -1, // Unlimited for all users
            'tier_name' => $full_response['tier_name'] ?? 'Media CDN'
        ]);
    }

    /**
     * AJAX handler for adding a source URL
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_add_source_url() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_add_source_url'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('api');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get and validate domain
        if (!isset($_POST['domain'])) {
            wp_send_json_error(['message' => __('Domain is required.', 'bandwidth-saver')]);
            return;
        }

        $domain = sanitize_text_field(wp_unslash($_POST['domain']));

        if (empty($domain)) {
            wp_send_json_error(['message' => __('Domain cannot be empty.', 'bandwidth-saver')]);
            return;
        }

        $result = $this->api->add_source_url($api_key, $domain);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $error_message = $result->get_error_message();

            // Map API error codes to user-friendly messages
            if ($error_code === 'limit_exceeded') {
                $error_message = __('Domain limit reached for your tier. Upgrade to add more domains.', 'bandwidth-saver');
            } elseif ($error_code === 'already_exists') {
                $error_message = __('This domain is already in your source URL list.', 'bandwidth-saver');
            } elseif ($error_code === 'invalid_domain') {
                $error_message = __('Invalid domain format. Please enter a valid domain (e.g., cdn.example.com).', 'bandwidth-saver');
            }

            wp_send_json_error([
                'message' => $error_message,
                'code' => $error_code
            ]);
            return;
        }

        // Sync to local settings after successful add
        $this->sync_source_urls_to_settings($api_key);

        wp_send_json_success([
            'message' => __('Domain added successfully.', 'bandwidth-saver'),
            'source_url' => $result['source_url'] ?? null
        ]);
    }

    /**
     * AJAX handler for removing a source URL
     *
     * @since 0.2.0
     * @return void
     */
    public function ajax_remove_source_url() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_remove_source_url'))) {
            wp_send_json_error(['message' => __('Security check failed', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('api');

        $api_key = $this->settings->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('No subscription found.', 'bandwidth-saver')]);
            return;
        }

        // Get and validate domain
        if (!isset($_POST['domain'])) {
            wp_send_json_error(['message' => __('Domain is required.', 'bandwidth-saver')]);
            return;
        }

        $domain = sanitize_text_field(wp_unslash($_POST['domain']));

        if (empty($domain)) {
            wp_send_json_error(['message' => __('Domain cannot be empty.', 'bandwidth-saver')]);
            return;
        }

        $result = $this->api->remove_source_url($api_key, $domain);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $error_message = $result->get_error_message();

            // Map API error codes to user-friendly messages
            if ($error_code === 'cannot_remove_primary') {
                $error_message = __('Cannot remove the primary domain.', 'bandwidth-saver');
            } elseif ($error_code === 'not_found') {
                $error_message = __('Domain not found in your source URL list.', 'bandwidth-saver');
            }

            wp_send_json_error([
                'message' => $error_message,
                'code' => $error_code
            ]);
            return;
        }

        // Sync to local settings after successful removal
        $this->sync_source_urls_to_settings($api_key);

        wp_send_json_success([
            'message' => __('Domain removed successfully.', 'bandwidth-saver')
        ]);
    }

    /**
     * Sync source URLs from API to local settings
     *
     * @since 0.2.0
     * @param string $api_key API key.
     * @return void
     */
    private function sync_source_urls_to_settings($api_key) {
        $source_urls = $this->api->get_source_urls($api_key);

        if (is_wp_error($source_urls)) {
            return;
        }

        // Extract domain strings only
        $domain_list = array_map(function($item) {
            return is_array($item) && isset($item['domain']) ? $item['domain'] : $item;
        }, $source_urls);

        $this->settings->update(['source_urls' => $domain_list]);
    }
}
