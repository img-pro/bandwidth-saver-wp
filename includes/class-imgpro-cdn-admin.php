<?php
/**
 * ImgPro CDN Admin Interface
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page under Settings > Bandwidth Saver
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Admin {

    /**
     * Settings page slug
     *
     * @since 2.0.0
     * @var string
     */
    const PAGE_SLUG = 'imgpro-cdn-settings';

    /**
     * Transient caching the img.pro usage response
     *
     * @since 2.0.0
     * @var string
     */
    const USAGE_TRANSIENT = 'imgpro_cdn_usage';

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
     * Constructor
     *
     * @since 0.1.0
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @param ImgPro_CDN_Sync     $sync     Sync instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings, ImgPro_CDN_Sync $sync) {
        $this->settings = $settings;
        $this->sync = $sync;
    }

    /**
     * Register admin hooks
     *
     * @since 0.1.0
     * @return void
     */
    public function register_hooks() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'render_upgrade_notice']);
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
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets on the settings page only
     *
     * @since 0.1.0
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets($hook) {
        if ('settings_page_' . self::PAGE_SLUG !== $hook) {
            return;
        }

        wp_enqueue_style(
            'imgpro-cdn-admin',
            IMGPRO_CDN_PLUGIN_URL . 'admin/css/imgpro-cdn-admin.css',
            [],
            IMGPRO_CDN_VERSION
        );

        wp_enqueue_script(
            'imgpro-cdn-admin',
            IMGPRO_CDN_PLUGIN_URL . 'admin/js/imgpro-cdn-admin.js',
            [],
            IMGPRO_CDN_VERSION,
            true
        );

        wp_localize_script('imgpro-cdn-admin', 'imgproCdnAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonces'    => ImgPro_CDN_Security::get_all_nonces(),
            'connected' => $this->settings->is_connected(),
            'status'    => $this->settings->is_connected() ? $this->sync->get_status() : null,
            'i18n'      => [
                'connecting'     => __('Checking your key…', 'bandwidth-saver'),
                'connect'        => __('Connect', 'bandwidth-saver'),
                'genericError'   => __('Something went wrong. Please try again.', 'bandwidth-saver'),
                'toggleOn'       => self::toggle_text(true),
                'toggleOff'      => self::toggle_text(false),
                'confirmDisconnect' => __('Stop serving images from img.pro? Your images stay in your img.pro App.', 'bandwidth-saver'),
                'confirmRemove'  => __('Delete every image this site uploaded to img.pro, then disconnect? This cannot be undone.', 'bandwidth-saver'),
                /* translators: 1: number of files on img.pro, 2: total number of files */
                'progress'       => __('%1$s of %2$s files on img.pro', 'bandwidth-saver'),
                'phases'         => [
                    'scanning'  => __('Scanning your media library…', 'bandwidth-saver'),
                    'matching'  => __('Checking for images already in your img.pro App…', 'bandwidth-saver'),
                    'uploading' => __('Copying images to img.pro…', 'bandwidth-saver'),
                    'waiting'   => __('img.pro asked us to slow down. Sync will continue shortly.', 'bandwidth-saver'),
                    'paused'    => __('Sync is paused.', 'bandwidth-saver'),
                    'removing'  => __('Deleting this site\'s images from img.pro…', 'bandwidth-saver'),
                    'idle'      => __('All images are in sync.', 'bandwidth-saver'),
                ],
            ],
        ]);
    }

    /**
     * Tell sites upgrading from 1.x that they need to connect img.pro
     *
     * Shown on the dashboard and plugins screens until a key is connected
     * or the notice is dismissed by visiting the settings page.
     *
     * @since 2.0.0
     * @return void
     */
    public function render_upgrade_notice() {
        if (!get_option(ImgPro_CDN_Core::UPGRADE_NOTICE_OPTION) || !ImgPro_CDN_Security::current_user_can()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['dashboard', 'plugins'], true)) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Bandwidth Saver 2.0 now runs on your own img.pro account.', 'bandwidth-saver'); ?></strong>
                <?php esc_html_e('The old managed CDN has been shut down, so your images load from your own server until you connect an img.pro API key.', 'bandwidth-saver'); ?>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Connect img.pro', 'bandwidth-saver'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Cached img.pro usage, refreshed every minute
     *
     * @since 2.0.0
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @param bool                $force    Skip the cache.
     * @return array|null Usage object, or null when unavailable.
     */
    public static function get_usage($settings, $force = false) {
        if (!$force) {
            $cached = get_transient(self::USAGE_TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $api = new ImgPro_CDN_API($settings->get_api_key());
        $usage = $api->get_usage();
        if (is_wp_error($usage)) {
            return null;
        }

        set_transient(self::USAGE_TRANSIENT, $usage, MINUTE_IN_SECONDS);
        return $usage;
    }

    /**
     * Images stored in the App and the plan's limit
     *
     * @since 2.0.0
     * @param array|null $usage Usage object.
     * @return array{used: int|null, limit: int|null}
     */
    public static function get_image_counts($usage) {
        if (!is_array($usage)) {
            return ['used' => null, 'limit' => null];
        }
        $used  = $usage['limits']['images']['used'] ?? ($usage['totals']['images_stored'] ?? null);
        $limit = $usage['limits']['images']['limit'] ?? null;

        return [
            'used'  => null === $used ? null : (int) $used,
            'limit' => null === $limit ? null : (int) $limit,
        ];
    }

    /**
     * "1,234 of 5,000" style text for the App's image count
     *
     * @since 2.0.0
     * @param array $counts Result of get_image_counts().
     * @return string
     */
    public static function format_image_counts($counts) {
        if (null !== $counts['used'] && null !== $counts['limit']) {
            /* translators: 1: images stored, 2: plan limit */
            return sprintf(__('%1$s of %2$s', 'bandwidth-saver'), number_format_i18n($counts['used']), number_format_i18n($counts['limit']));
        }
        if (null !== $counts['used']) {
            return number_format_i18n($counts['used']);
        }
        return '—';
    }

    /**
     * Render the settings page
     *
     * @since 0.1.0
     * @return void
     */
    public function render_settings_page() {
        if (!ImgPro_CDN_Security::current_user_can()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'bandwidth-saver'));
        }

        // Visiting the page is enough to retire the upgrade notice
        delete_option(ImgPro_CDN_Core::UPGRADE_NOTICE_OPTION);

        ?>
        <div class="wrap imgpro-admin">
            <div class="imgpro-header">
                <div>
                    <h1><?php esc_html_e('Bandwidth Saver', 'bandwidth-saver'); ?></h1>
                    <p class="imgpro-tagline"><?php esc_html_e('Image CDN for WordPress, powered by img.pro', 'bandwidth-saver'); ?></p>
                </div>
                <span class="imgpro-version">v<?php echo esc_html(IMGPRO_CDN_VERSION); ?></span>
            </div>

            <?php
            if ($this->settings->is_connected()) {
                $this->render_connected();
            } else {
                $this->render_leftover_notice();
                $this->render_connect_card();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Card asking for an img.pro API key
     *
     * @since 2.0.0
     * @param bool $reconnect Whether a key is already stored (replacing it).
     * @return void
     */
    private function render_connect_card($reconnect = false) {
        ?>
        <div class="imgpro-card">
            <h2><?php echo $reconnect ? esc_html__('Replace your img.pro API key', 'bandwidth-saver') : esc_html__('Connect img.pro', 'bandwidth-saver'); ?></h2>
            <?php if (!$reconnect) : ?>
                <p><?php esc_html_e('Bandwidth Saver copies your media library to your own img.pro App and serves it from img.pro\'s global CDN. Your original files stay on your server.', 'bandwidth-saver'); ?></p>
            <?php endif; ?>
            <ol class="imgpro-steps">
                <li>
                    <?php
                    printf(
                        /* translators: %s: link to the img.pro dashboard */
                        esc_html__('Sign in at %s and create an App (the free plan works).', 'bandwidth-saver'),
                        '<a href="' . esc_url(ImgPro_CDN_Settings::DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">img.pro</a>'
                    );
                    ?>
                </li>
                <li><?php esc_html_e('In the App, open API Keys and create a key with Read and Write permission.', 'bandwidth-saver'); ?></li>
                <li><?php esc_html_e('Paste the key here.', 'bandwidth-saver'); ?></li>
            </ol>
            <form id="imgpro-connect-form" class="imgpro-connect-form" autocomplete="off">
                <label for="imgpro-api-key" class="screen-reader-text"><?php esc_html_e('img.pro API key', 'bandwidth-saver'); ?></label>
                <input type="password" id="imgpro-api-key" class="regular-text" placeholder="img_sk_live_…" spellcheck="false" required>
                <button type="submit" class="button button-primary"><?php esc_html_e('Connect', 'bandwidth-saver'); ?></button>
            </form>
            <p class="imgpro-form-error" id="imgpro-connect-error" role="alert" hidden></p>
            <p class="description">
                <?php esc_html_e('Every file WordPress creates for an image (the full size and each thumbnail size) is stored as one image in your App. img.pro plans are limited by the number of stored images.', 'bandwidth-saver'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Page body once a key is connected
     *
     * @since 2.0.0
     * @return void
     */
    private function render_connected() {
        $status  = $this->sync->get_status();
        $enabled = (bool) $this->settings->get('enabled');
        $removing = (bool) $this->settings->get('removing');
        $usage   = self::get_usage($this->settings);

        $this->render_pause_notice($status);
        $this->render_address_notice();
        ?>
        <div class="imgpro-card">
            <div class="imgpro-toggle-row">
                <div>
                    <h2><?php esc_html_e('Serve images from img.pro', 'bandwidth-saver'); ?></h2>
                    <p class="imgpro-muted" id="imgpro-toggle-text">
                        <?php echo esc_html(self::toggle_text($enabled)); ?>
                    </p>
                </div>
                <label class="imgpro-switch">
                    <input type="checkbox" id="imgpro-enabled" <?php checked($enabled); ?> <?php disabled($removing); ?>>
                    <span class="imgpro-switch-slider" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Serve images from img.pro', 'bandwidth-saver'); ?></span>
                </label>
            </div>
        </div>

        <div class="imgpro-card" id="imgpro-sync-card">
            <h2><?php esc_html_e('Media sync', 'bandwidth-saver'); ?></h2>
            <p class="imgpro-phase" id="imgpro-phase"></p>
            <div class="imgpro-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr($status['percent']); ?>">
                <div class="imgpro-progress-bar" id="imgpro-progress-bar" style="width: <?php echo esc_attr($status['percent']); ?>%"></div>
            </div>
            <p class="imgpro-muted" id="imgpro-progress-text"></p>
            <ul class="imgpro-counts">
                <li><span id="imgpro-count-pending"><?php echo esc_html(number_format_i18n($status['pending'])); ?></span> <?php esc_html_e('waiting', 'bandwidth-saver'); ?></li>
                <li><span id="imgpro-count-failed"><?php echo esc_html(number_format_i18n($status['failed'])); ?></span> <?php esc_html_e('failed', 'bandwidth-saver'); ?></li>
                <li><span id="imgpro-count-skipped"><?php echo esc_html(number_format_i18n($status['skipped'])); ?></span> <?php esc_html_e('stay on your server (format or size not supported)', 'bandwidth-saver'); ?></li>
            </ul>
            <div class="imgpro-actions">
                <button type="button" class="button" id="imgpro-retry" <?php echo $status['failed'] ? '' : 'hidden'; ?>><?php esc_html_e('Retry failed files', 'bandwidth-saver'); ?></button>
                <button type="button" class="button button-primary" id="imgpro-resume" <?php echo ('paused' === $status['phase'] && ImgPro_CDN_Settings::PAUSE_AUTH !== $status['pause_reason']) ? '' : 'hidden'; ?>><?php esc_html_e('Resume sync', 'bandwidth-saver'); ?></button>
            </div>
            <?php $this->render_failures(); ?>
        </div>

        <div class="imgpro-card">
            <h2><?php esc_html_e('img.pro App', 'bandwidth-saver'); ?></h2>
            <?php $this->render_usage($usage); ?>
            <p>
                <a href="<?php echo esc_url(ImgPro_CDN_Settings::DASHBOARD_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Manage your App and plan on img.pro', 'bandwidth-saver'); ?></a>
            </p>
        </div>

        <?php
        if (ImgPro_CDN_Settings::PAUSE_AUTH === $status['pause_reason']) {
            $this->render_connect_card(true);
        }
        ?>

        <div class="imgpro-card imgpro-card-danger">
            <h2><?php esc_html_e('Disconnect', 'bandwidth-saver'); ?></h2>
            <p class="imgpro-muted"><?php esc_html_e('Disconnecting stops serving images from img.pro and forgets your API key. Choose whether the copies in your img.pro App should be kept or deleted.', 'bandwidth-saver'); ?></p>
            <div class="imgpro-actions">
                <button type="button" class="button" id="imgpro-disconnect"><?php esc_html_e('Disconnect and keep images', 'bandwidth-saver'); ?></button>
                <button type="button" class="button imgpro-button-danger" id="imgpro-remove-all" <?php disabled($removing); ?>><?php esc_html_e('Delete images from img.pro and disconnect', 'bandwidth-saver'); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Description under the serving toggle
     *
     * @since 2.0.0
     * @param bool $enabled Whether serving is on.
     * @return string
     */
    private static function toggle_text($enabled) {
        return $enabled
            ? __('On. Images that finished copying load from img.pro; the rest load from your server.', 'bandwidth-saver')
            : __('Off. All images load from your server. Copying to img.pro continues in the background.', 'bandwidth-saver');
    }

    /**
     * Banner explaining why sync is paused
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return void
     */
    private function render_pause_notice($status) {
        $messages = [
            ImgPro_CDN_Settings::PAUSE_QUOTA => __('Your img.pro App reached its plan\'s image limit. Images already copied keep loading from img.pro and new ones load from your server. Upgrade your img.pro plan or free up space, then resume.', 'bandwidth-saver'),
            ImgPro_CDN_Settings::PAUSE_AUTH  => __('img.pro rejected your API key, or the key cannot upload. Paste a key with Read and Write permission below.', 'bandwidth-saver'),
            ImgPro_CDN_Settings::PAUSE_APP   => __('img.pro has paused this App. Check your App on img.pro, then resume.', 'bandwidth-saver'),
        ];

        if (empty($messages[$status['pause_reason']])) {
            return;
        }
        ?>
        <div class="imgpro-alert imgpro-alert-warning" role="status">
            <p><?php echo esc_html($messages[$status['pause_reason']]); ?></p>
            <?php if (!empty($status['pause_detail'])) : ?>
                <p class="imgpro-muted">
                    <?php
                    /* translators: %s: error message returned by img.pro */
                    printf(esc_html__('img.pro said: %s', 'bandwidth-saver'), esc_html($status['pause_detail']));
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Banner shown when the site's address no longer matches its label
     *
     * The label stored at connection keeps identifying the site's images
     * after a move. A copy of the site (a staging site, say) inherits the
     * same key, label and file map, so deleting media there would delete
     * the original site's images.
     *
     * @since 2.0.0
     * @return void
     */
    private function render_address_notice() {
        $label   = ImgPro_CDN_Settings::get_site_label();
        $current = ImgPro_CDN_Settings::current_site_label();
        if ($label === $current) {
            return;
        }
        ?>
        <div class="imgpro-alert imgpro-alert-warning" role="status">
            <p>
                <?php
                /* translators: 1: address the site connected from, 2: the site's current address */
                printf(esc_html__('This site connected to img.pro as %1$s, but its address is now %2$s.', 'bandwidth-saver'), '<strong>' . esc_html($label) . '</strong>', '<strong>' . esc_html($current) . '</strong>');
                ?>
            </p>
            <p><?php esc_html_e('If you moved the site, there is nothing to do: its images on img.pro keep the old address as their label.', 'bandwidth-saver'); ?></p>
            <p><?php esc_html_e('If this is a copy of another site, such as a staging site, click "Disconnect and keep images" below. While connected, deleting media here also deletes the original site\'s images from img.pro.', 'bandwidth-saver'); ?></p>
        </div>
        <?php
    }

    /**
     * Banner shown after "delete images" finished with some images left
     *
     * @since 2.0.0
     * @return void
     */
    private function render_leftover_notice() {
        $leftover = (int) get_option(ImgPro_CDN_Sync::LEFTOVER_OPTION, 0);
        if ($leftover < 1) {
            return;
        }
        ?>
        <div class="imgpro-alert imgpro-alert-warning" role="status">
            <p>
                <?php
                printf(
                    /* translators: %s: number of images */
                    esc_html(_n('%s image could not be deleted from img.pro. You can delete it in your img.pro App.', '%s images could not be deleted from img.pro. You can delete them in your img.pro App.', $leftover, 'bandwidth-saver')),
                    esc_html(number_format_i18n($leftover))
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Most recent upload failures
     *
     * @since 2.0.0
     * @return void
     */
    private function render_failures() {
        $failures = ImgPro_CDN_Files::get_recent_failures(5);
        if (empty($failures)) {
            return;
        }
        ?>
        <details class="imgpro-failures">
            <summary><?php esc_html_e('Recent failures', 'bandwidth-saver'); ?></summary>
            <ul>
                <?php foreach ($failures as $failure) : ?>
                    <li><code><?php echo esc_html($failure->file); ?></code> <?php echo esc_html((string) $failure->error); ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    /**
     * Plan and image count of the connected App
     *
     * @since 2.0.0
     * @param array|null $usage Usage object.
     * @return void
     */
    private function render_usage($usage) {
        if (!is_array($usage)) {
            echo '<p class="imgpro-muted">' . esc_html__('Usage is unavailable right now.', 'bandwidth-saver') . '</p>';
            return;
        }

        $plans = [
            'free'      => __('Free', 'bandwidth-saver'),
            'pro'       => __('Pro', 'bandwidth-saver'),
            'unlimited' => __('Unlimited', 'bandwidth-saver'),
        ];
        $plan   = isset($usage['plan']) ? (string) $usage['plan'] : '';
        $counts = self::get_image_counts($usage);
        ?>
        <dl class="imgpro-usage">
            <dt><?php esc_html_e('Plan', 'bandwidth-saver'); ?></dt>
            <dd><?php echo esc_html($plans[$plan] ?? ucfirst($plan)); ?></dd>
            <dt><?php esc_html_e('Images stored in the App', 'bandwidth-saver'); ?></dt>
            <dd id="imgpro-usage-images"><?php echo esc_html(self::format_image_counts($counts)); ?></dd>
        </dl>
        <?php
        if (null !== $counts['used'] && $counts['limit'] && $counts['used'] >= 0.9 * $counts['limit']) {
            echo '<p class="imgpro-muted">' . esc_html__('Your App is close to its image limit. When it is full, new images keep loading from your server.', 'bandwidth-saver') . '</p>';
        }
    }
}
