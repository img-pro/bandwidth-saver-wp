<?php
/**
 * ImgPro CDN Plan Selector Component
 *
 * Single-tier subscription UI. Shows payment prompt for unpaid users
 * and subscription status for active subscribers.
 *
 * @package ImgPro_CDN
 * @since   0.1.7
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plan Selector class
 *
 * @since 0.1.7
 */
class ImgPro_CDN_Plan_Selector {

    /**
     * API client instance
     *
     * @var ImgPro_CDN_API
     */
    private $api;

    /**
     * Settings instance
     *
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Constructor
     *
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
        $this->api = ImgPro_CDN_API::instance();
    }

    /**
     * Render the plan selector
     *
     * @param string $context   Context: 'modal', 'inline', 'onboarding'.
     * @param string $current_tier Current tier ID (or empty for none).
     * @return void
     */
    public function render($context = 'modal', $current_tier = '') {
        $all_settings = $this->settings->get_all();
        $is_paid = ImgPro_CDN_Settings::is_paid($all_settings);

        $wrapper_class = 'imgpro-plan-selector';
        if ('modal' === $context) {
            $wrapper_class .= ' imgpro-plan-selector--modal';
        } elseif ('onboarding' === $context) {
            $wrapper_class .= ' imgpro-plan-selector--onboarding';
        }
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
            <?php if ('modal' === $context): ?>
            <div class="imgpro-plan-selector__header">
                <h2><?php echo $is_paid ? esc_html__('Subscription Active', 'bandwidth-saver') : esc_html__('Activate Your Subscription', 'bandwidth-saver'); ?></h2>
                <button type="button" class="imgpro-plan-selector__close" aria-label="<?php esc_attr_e('Close', 'bandwidth-saver'); ?>">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <?php endif; ?>

            <?php if ($is_paid): ?>
                <?php $this->render_subscription_active(); ?>
            <?php else: ?>
                <?php $this->render_subscription_card(); ?>
            <?php endif; ?>

            <?php if (!$is_paid): ?>
            <div class="imgpro-plan-selector__footer">
                <button type="button" class="imgpro-btn imgpro-btn-primary imgpro-btn-lg imgpro-btn-full" id="imgpro-plan-checkout" data-tier-id="unlimited">
                    <span class="imgpro-btn-text"><?php esc_html_e('Activate Subscription', 'bandwidth-saver'); ?></span>
                    <span class="imgpro-btn-loading">
                        <svg class="imgpro-spinner" width="20" height="20" viewBox="0 0 20 20">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="50" stroke-linecap="round"/>
                        </svg>
                        <?php esc_html_e('Redirecting...', 'bandwidth-saver'); ?>
                    </span>
                    <svg class="imgpro-btn-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M4.167 10h11.666M10 4.167L15.833 10 10 15.833" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>

            <p class="imgpro-plan-selector__hint">
                <?php esc_html_e('7-day money-back guarantee. Cancel anytime.', 'bandwidth-saver'); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the subscription card for unpaid users
     *
     * @return void
     */
    private function render_subscription_card() {
        ?>
        <div class="imgpro-plan-card imgpro-plan-card--single"
             data-tier-id="unlimited"
             data-tier-name="Unlimited"
             data-tier-price="$19.99/mo">

            <div class="imgpro-plan-card__header">
                <h3 class="imgpro-plan-card__name"><?php esc_html_e('Media CDN', 'bandwidth-saver'); ?></h3>
                <p class="imgpro-plan-card__description"><?php esc_html_e('Support the service you\'re already using.', 'bandwidth-saver'); ?></p>
            </div>

            <div class="imgpro-plan-card__price">
                <span class="imgpro-plan-card__amount">$19.99</span>
                <span class="imgpro-plan-card__period">/mo</span>
            </div>

            <ul class="imgpro-plan-card__features">
                <li class="imgpro-plan-card__feature">
                    <svg class="imgpro-plan-card__feature-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M13.333 4L6 11.333 2.667 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php esc_html_e('300+ global edge servers', 'bandwidth-saver'); ?></span>
                </li>
                <li class="imgpro-plan-card__feature">
                    <svg class="imgpro-plan-card__feature-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M13.333 4L6 11.333 2.667 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php esc_html_e('Images, video, audio & HLS streaming', 'bandwidth-saver'); ?></span>
                </li>
                <li class="imgpro-plan-card__feature">
                    <svg class="imgpro-plan-card__feature-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M13.333 4L6 11.333 2.667 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php esc_html_e('Custom domain (cdn.yoursite.com)', 'bandwidth-saver'); ?></span>
                </li>
                <li class="imgpro-plan-card__feature">
                    <svg class="imgpro-plan-card__feature-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M13.333 4L6 11.333 2.667 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php esc_html_e('Unlimited requests', 'bandwidth-saver'); ?></span>
                </li>
                <li class="imgpro-plan-card__feature">
                    <svg class="imgpro-plan-card__feature-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M13.333 4L6 11.333 2.667 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span><?php esc_html_e('Priority support', 'bandwidth-saver'); ?></span>
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * Render the subscription active confirmation
     *
     * @return void
     */
    private function render_subscription_active() {
        ?>
        <div class="imgpro-plan-active">
            <div class="imgpro-plan-active__icon">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <circle cx="24" cy="24" r="24" fill="#10b981" fill-opacity="0.1"/>
                    <path d="M32 18L21 29L16 24" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h3 class="imgpro-plan-active__title"><?php esc_html_e('Subscription Active', 'bandwidth-saver'); ?></h3>
            <p class="imgpro-plan-active__description">
                <?php esc_html_e('Thank you for supporting the Media CDN. Your subscription keeps the service running.', 'bandwidth-saver'); ?>
            </p>
            <button type="button" class="imgpro-btn imgpro-btn-secondary" id="imgpro-manage-subscription">
                <?php esc_html_e('Manage Subscription', 'bandwidth-saver'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render the modal overlay wrapper
     *
     * @return void
     */
    public function render_modal_wrapper() {
        ?>
        <div class="imgpro-plan-modal" id="imgpro-plan-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="imgpro-plan-modal-title">
            <div class="imgpro-plan-modal__backdrop"></div>
            <div class="imgpro-plan-modal__content">
                <?php $this->render('modal'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a compact subscription CTA that opens the plan selector
     *
     * @param string $context Context for styling: 'card', 'inline', 'alert'.
     * @return void
     */
    public function render_upgrade_cta($context = 'card') {
        $all_settings = $this->settings->get_all();

        // Don't show CTA if already paid
        if (ImgPro_CDN_Settings::is_paid($all_settings)) {
            return;
        }

        $cta_class = 'imgpro-upgrade-cta';
        if ($context) {
            $cta_class .= ' imgpro-upgrade-cta--' . $context;
        }
        ?>
        <div class="<?php echo esc_attr($cta_class); ?>">
            <?php if ('card' === $context): ?>
                <div class="imgpro-upgrade-cta__icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>
                <div class="imgpro-upgrade-cta__content">
                    <h4><?php esc_html_e('Support This Service', 'bandwidth-saver'); ?></h4>
                    <p><?php esc_html_e('Activate your subscription to keep the CDN running.', 'bandwidth-saver'); ?></p>
                </div>
            <?php endif; ?>
            <button type="button" class="imgpro-btn imgpro-btn-primary imgpro-open-plan-selector">
                <?php esc_html_e('Activate Subscription', 'bandwidth-saver'); ?>
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M3.333 8h9.334M8 3.333L12.667 8 8 12.667" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        <?php
    }
}
