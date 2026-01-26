<?php
/**
 * ImgPro CDN Onboarding Wizard
 *
 * Handles the 4-step onboarding experience for new users.
 *
 * @package ImgPro_CDN
 * @since   0.1.7
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Onboarding wizard class
 *
 * @since 0.1.7
 */
class ImgPro_CDN_Onboarding {

    /**
     * Settings instance
     *
     * @since 0.1.7
     * @var ImgPro_CDN_Settings
     */
    private $settings;

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
     * @since 0.1.7
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
        $this->plan_selector = new ImgPro_CDN_Plan_Selector($settings);
    }

    /**
     * Check if onboarding should be shown
     *
     * Show onboarding if:
     * - Not completed AND
     * - Either in onboarding flow (step > 1) OR no existing subscription
     *
     * @since 0.1.7
     * @return bool
     */
    public function should_show_onboarding() {
        $all_settings = $this->settings->get_all();

        // Already completed onboarding
        if (!empty($all_settings['onboarding_completed'])) {
            return false;
        }

        // Using self-hosted mode with valid configuration - skip onboarding
        if (ImgPro_CDN_Settings::MODE_CLOUDFLARE === ($all_settings['setup_mode'] ?? '') && !empty($all_settings['cdn_url'])) {
            return false;
        }

        // If we're mid-onboarding (step > 1), continue showing it
        $current_step = (int) ($all_settings['onboarding_step'] ?? 1);
        if ($current_step > 1) {
            return true;
        }

        // For step 1: only show if no existing subscription
        $tier = $all_settings['cloud_tier'] ?? '';
        if (in_array($tier, [ImgPro_CDN_Settings::TIER_FREE, ImgPro_CDN_Settings::TIER_UNLIMITED, ImgPro_CDN_Settings::TIER_PRO, ImgPro_CDN_Settings::TIER_ACTIVE], true)) {
            return false;
        }

        return true;
    }

    /**
     * Get current onboarding step
     *
     * @since 0.1.7
     * @return int Step number (1-4).
     */
    public function get_current_step() {
        return (int) $this->settings->get('onboarding_step', 1);
    }

    /**
     * Render the onboarding wizard
     *
     * @since 0.1.7
     * @return void
     */
    public function render() {
        $step = $this->get_current_step();
        ?>
        <div class="imgpro-onboarding-wrapper">
            <div class="imgpro-onboarding-brand">
                <span class="imgpro-brand-name"><?php esc_html_e('Bandwidth Saver', 'bandwidth-saver'); ?></span>
            </div>
            <div class="imgpro-onboarding" data-step="<?php echo esc_attr($step); ?>">
                <?php
                switch ($step) {
                    case 1:
                        $this->render_step_welcome();
                        break;
                    case 2:
                        $this->render_step_connect();
                        break;
                    case 3:
                        $this->render_step_activate();
                        break;
                    case 4:
                        $this->render_step_success();
                        break;
                    default:
                        $this->render_step_welcome();
                }
                ?>
                <?php $this->render_progress_dots($step); ?>
            </div>
            <?php $this->render_skip_link(); ?>
        </div>
        <?php
        // Render plan selector modal (available throughout onboarding)
        $this->plan_selector->render_modal_wrapper();
    }

    /**
     * Step 1: Welcome - Show value proposition
     *
     * @since 0.1.7
     * @return void
     */
    private function render_step_welcome() {
        ?>
        <div class="imgpro-onboarding-content imgpro-onboarding-step-1">
            <h1><?php esc_html_e('Speed up your media', 'bandwidth-saver'); ?></h1>

            <p class="imgpro-onboarding-description">
                <?php esc_html_e('Slow media hurts your SEO and drives visitors away.', 'bandwidth-saver'); ?><br><?php esc_html_e('Speed it up in 60 seconds.', 'bandwidth-saver'); ?>
            </p>

            <ul class="imgpro-onboarding-benefits">
                <li>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M16.667 5L7.5 14.167 3.333 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><strong><?php esc_html_e('Better SEO', 'bandwidth-saver'); ?></strong> — <?php esc_html_e('speed improves your ranking', 'bandwidth-saver'); ?></span>
                </li>
                <li>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M16.667 5L7.5 14.167 3.333 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><strong><?php esc_html_e('Global delivery', 'bandwidth-saver'); ?></strong> — <?php esc_html_e('media loads from edge servers', 'bandwidth-saver'); ?></span>
                </li>
                <li>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M16.667 5L7.5 14.167 3.333 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><strong><?php esc_html_e('All media types', 'bandwidth-saver'); ?></strong> — <?php esc_html_e('images, video, audio & HLS', 'bandwidth-saver'); ?></span>
                </li>
            </ul>

            <div class="imgpro-onboarding-pills">
                <span class="imgpro-onboarding-pill">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M16.667 5L7.5 14.167 3.333 10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e('No DNS changes', 'bandwidth-saver'); ?>
                </span>
                <span class="imgpro-onboarding-pill">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M16.667 5L7.5 14.167 3.333 10" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php esc_html_e('No external accounts', 'bandwidth-saver'); ?>
                </span>
            </div>

            <div class="imgpro-onboarding-actions">
                <button type="button" class="imgpro-btn imgpro-btn-primary imgpro-btn-lg" id="imgpro-onboarding-start">
                    <?php esc_html_e('Get Started', 'bandwidth-saver'); ?>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4.167 10h11.666M10 4.167L15.833 10 10 15.833" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>

            <p class="imgpro-onboarding-hint">
                <?php esc_html_e('$19.99/mo to support the service.', 'bandwidth-saver'); ?>
                <button type="button" class="imgpro-btn-link imgpro-open-plan-selector"><?php esc_html_e('Learn more', 'bandwidth-saver'); ?></button>
            </p>
        </div>
        <?php
    }

    /**
     * Step 2: Connect - Create free account
     *
     * @since 0.1.7
     * @return void
     */
    private function render_step_connect() {
        $admin_email = get_option('admin_email');
        $site_url = get_site_url();
        ?>
        <div class="imgpro-onboarding-content imgpro-onboarding-step-2">
            <h1><?php esc_html_e('Create your account', 'bandwidth-saver'); ?></h1>

            <p class="imgpro-onboarding-description">
                <?php esc_html_e('Enter your email to set up your Media CDN.', 'bandwidth-saver'); ?>
            </p>

            <form id="imgpro-onboarding-connect-form" class="imgpro-onboarding-form">
                <div class="imgpro-form-group">
                    <label for="imgpro-email"><?php esc_html_e('Email', 'bandwidth-saver'); ?></label>
                    <input
                        type="email"
                        id="imgpro-email"
                        name="email"
                        value="<?php echo esc_attr($admin_email); ?>"
                        required
                        autocomplete="email"
                    >
                </div>

                <div class="imgpro-form-group">
                    <label for="imgpro-site-url"><?php esc_html_e('Site URL', 'bandwidth-saver'); ?></label>
                    <input
                        type="text"
                        id="imgpro-site-url"
                        value="<?php echo esc_attr($site_url); ?>"
                        readonly
                        disabled
                        class="imgpro-input-readonly"
                    >
                    <span class="imgpro-input-hint"><?php esc_html_e('Auto-detected from WordPress', 'bandwidth-saver'); ?></span>
                </div>

                <div class="imgpro-form-group imgpro-form-checkbox">
                    <label class="imgpro-checkbox-label">
                        <input type="checkbox" name="marketing_opt_in" value="1">
                        <span class="imgpro-checkbox-text"><?php esc_html_e('Send me tips to speed up my site', 'bandwidth-saver'); ?></span>
                    </label>
                </div>

                <div class="imgpro-onboarding-actions">
                    <button type="submit" class="imgpro-btn imgpro-btn-primary imgpro-btn-lg imgpro-btn-full">
                        <span class="imgpro-btn-text"><?php esc_html_e('Create Account', 'bandwidth-saver'); ?></span>
                        <span class="imgpro-btn-loading">
                            <svg class="imgpro-spinner" width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="50" stroke-linecap="round"/></svg>
                            <?php esc_html_e('Creating...', 'bandwidth-saver'); ?>
                        </span>
                        <svg class="imgpro-btn-icon" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4.167 10h11.666M10 4.167L15.833 10 10 15.833" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>

                <p class="imgpro-onboarding-terms">
                    <?php
                    printf(
                        wp_kses(
                            /* translators: %s: Terms of Service link */
                            __('By continuing, you agree to our <a href="%s" target="_blank">Terms of Service</a>', 'bandwidth-saver'),
                            ['a' => ['href' => [], 'target' => []]]
                        ),
                        'https://wordpress.org/plugins/bandwidth-saver/'
                    );
                    ?>
                </p>
            </form>

        </div>
        <?php
    }

    /**
     * Step 3: Activate - Enable the CDN
     *
     * @since 0.1.7
     * @return void
     */
    private function render_step_activate() {
        ?>
        <div class="imgpro-onboarding-content imgpro-onboarding-step-3">
            <h1><?php esc_html_e('Ready to go', 'bandwidth-saver'); ?></h1>

            <p class="imgpro-onboarding-description">
                <?php esc_html_e('Toggle on to start serving media from the CDN.', 'bandwidth-saver'); ?>
            </p>

            <div class="imgpro-activate-card" id="imgpro-activate-card">
                <div class="imgpro-activate-info">
                    <div class="imgpro-activate-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                    </div>
                    <div class="imgpro-activate-text">
                        <strong><?php esc_html_e('Media CDN', 'bandwidth-saver'); ?></strong>
                        <span><?php esc_html_e('Serve media from edge servers worldwide', 'bandwidth-saver'); ?></span>
                    </div>
                </div>
                <label class="imgpro-toggle" for="imgpro-activate-toggle">
                    <input type="checkbox" id="imgpro-activate-toggle">
                    <span class="imgpro-toggle-slider"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Enable Media CDN', 'bandwidth-saver'); ?></span>
                </label>
            </div>

            <div class="imgpro-activate-details">
                <h3><?php esc_html_e('What happens next:', 'bandwidth-saver'); ?></h3>
                <ul>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>
                        <?php esc_html_e('Media URLs on your public pages point to the CDN', 'bandwidth-saver'); ?>
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>
                        <?php esc_html_e('Each file is cached on first request', 'bandwidth-saver'); ?>
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>
                        <?php esc_html_e('Future requests load from the nearest edge server', 'bandwidth-saver'); ?>
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>
                        <?php esc_html_e('Your original files stay safe on your server', 'bandwidth-saver'); ?>
                    </li>
                    <li>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" fill="currentColor"/></svg>
                        <?php esc_html_e('If anything goes wrong, media loads directly from your site', 'bandwidth-saver'); ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Step 4: Success - Show confirmation
     *
     * @since 0.1.7
     * @return void
     */
    private function render_step_success() {
        $site_url = get_site_url();
        ?>
        <div class="imgpro-onboarding-content imgpro-onboarding-step-4">
            <div class="imgpro-success-icon">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <circle cx="24" cy="24" r="24" fill="currentColor" fill-opacity="0.1"/>
                    <path d="M32 18L21 29l-5-5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h1><?php esc_html_e('You\'re all set!', 'bandwidth-saver'); ?></h1>

            <p class="imgpro-onboarding-description">
                <?php esc_html_e('Your media is now being served from edge locations around the world. Visit your site to start caching.', 'bandwidth-saver'); ?>
            </p>

            <div class="imgpro-onboarding-actions imgpro-onboarding-actions-split">
                <a href="<?php echo esc_url($site_url); ?>" target="_blank" class="imgpro-btn imgpro-btn-secondary imgpro-btn-lg">
                    <?php esc_html_e('View Site', 'bandwidth-saver'); ?>
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M12 8.667V12a1.333 1.333 0 01-1.333 1.333H4A1.333 1.333 0 012.667 12V5.333A1.333 1.333 0 014 4h3.333M10 2h4v4M6.667 9.333L14 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <button type="button" class="imgpro-btn imgpro-btn-primary imgpro-btn-lg" id="imgpro-onboarding-complete">
                    <?php esc_html_e('Go to Dashboard', 'bandwidth-saver'); ?>
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4.167 10h11.666M10 4.167L15.833 10 10 15.833" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render progress dots
     *
     * @since 0.1.7
     * @param int $current_step Current step number.
     * @return void
     */
    private function render_progress_dots($current_step) {
        ?>
        <div class="imgpro-progress-dots" role="progressbar" aria-valuenow="<?php echo esc_attr($current_step); ?>" aria-valuemin="1" aria-valuemax="4">
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <?php
                /* translators: %d: step number */
                $step_label = sprintf(esc_attr__('Step %d', 'bandwidth-saver'), $i);
                ?>
                <span class="imgpro-progress-dot <?php echo esc_attr( $i < $current_step ? 'completed' : '' ); ?> <?php echo esc_attr( $i === $current_step ? 'active' : '' ); ?>" aria-label="<?php echo esc_attr($step_label); ?>"></span>
            <?php endfor; ?>
        </div>
        <?php
    }

    /**
     * Render skip link for users who want self-hosted
     *
     * @since 0.1.7
     * @return void
     */
    private function render_skip_link() {
        $self_host_url = add_query_arg([
            'tab' => ImgPro_CDN_Settings::MODE_CLOUDFLARE,
            'skip_onboarding' => '1',
            '_wpnonce' => wp_create_nonce('imgpro_skip_onboarding')
        ], admin_url('options-general.php?page=imgpro-cdn-settings'));
        ?>
        <div class="imgpro-onboarding-skip">
            <span><?php esc_html_e('Prefer to use your own Cloudflare account?', 'bandwidth-saver'); ?></span>
            <a href="<?php echo esc_url($self_host_url); ?>"><?php esc_html_e('Self-host instead', 'bandwidth-saver'); ?></a>
        </div>
        <?php
    }
}
