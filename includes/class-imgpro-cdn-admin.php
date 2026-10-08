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
 * Built from core's settings screens (Settings > Media is the model):
 * sections of h2.title and form-table, core notices and buttons, and a
 * form saved with Save Changes.
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
     * admin-post action and nonce of the settings form
     *
     * @since 2.0.0
     * @var string
     */
    const SAVE_ACTION = 'imgpro_cdn_save_settings';

    /**
     * Transient caching the img.pro usage response
     *
     * @since 2.0.0
     * @var string
     */
    const USAGE_TRANSIENT = 'imgpro_cdn_usage';

    /**
     * Value cached in the usage transient when img.pro could not be reached
     *
     * @since 2.0.0
     * @var string
     */
    const USAGE_UNAVAILABLE = 'unavailable';

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
     * Sync status read for this page load, shared by its script and its markup
     *
     * @since 2.0.0
     * @var array|null
     */
    private $page_status = null;

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
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'save_settings']);
    }

    /**
     * Add admin menu page
     *
     * @since 0.1.0
     * @return void
     */
    public function add_menu_page() {
        $hook = add_options_page(
            esc_html__('Bandwidth Saver', 'bandwidth-saver'),
            esc_html__('Bandwidth Saver', 'bandwidth-saver'),
            ImgPro_CDN_Security::menu_capability(),
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
        if ($hook) {
            add_action('load-' . $hook, [$this, 'lift_lapsed_quota_pause']);
            add_action('load-' . $hook, [$this, 'add_help_tabs']);
        }
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

        wp_register_style('imgpro-cdn-admin', false, [], IMGPRO_CDN_VERSION);
        // Count cells start at the top, so a value lines up with its label
        // whether or not a description follows it
        wp_add_inline_style('imgpro-cdn-admin', '#imgpro-remove-all{margin-left:8px}.imgpro-failures code{word-break:break-all}.form-table.imgpro-counts td{vertical-align:top}');
        wp_enqueue_style('imgpro-cdn-admin');

        wp_enqueue_script(
            'imgpro-cdn-admin',
            IMGPRO_CDN_PLUGIN_URL . 'admin/js/imgpro-cdn-admin.js',
            ['wp-a11y'],
            IMGPRO_CDN_VERSION,
            true
        );

        $status = $this->settings->is_connected() ? $this->page_status() : null;
        wp_localize_script('imgpro-cdn-admin', 'imgproCdnAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonces'    => ImgPro_CDN_Security::get_all_nonces(),
            'connected' => $this->settings->is_connected(),
            'status'    => $status,
            'i18n'      => [
                'connecting'        => __('Checking your key…', 'bandwidth-saver'),
                'connect'           => __('Connect', 'bandwidth-saver'),
                'genericError'      => __('Something went wrong. Please try again.', 'bandwidth-saver'),
                'sessionExpired'    => __('Your session has expired. Reload this page to continue.', 'bandwidth-saver'),
                'retried'           => __('Failed files queued for copying again.', 'bandwidth-saver'),
                'confirmDisconnect' => $this->settings->get('removing')
                    ? __('Stop deleting and disconnect? Images not deleted yet stay in your img.pro App.', 'bandwidth-saver')
                    : __('Stop serving images from img.pro? Your images stay in your img.pro App.', 'bandwidth-saver'),
                'confirmRemove'     => __('Delete every image this site uploaded to img.pro, then disconnect? This cannot be undone.', 'bandwidth-saver'),
            ],
        ]);
    }

    /**
     * Save the settings form, then return to the page
     *
     * Core prints "Settings saved." on a settings page loaded with
     * updated=1.
     *
     * @since 2.0.0
     * @return void
     */
    public function save_settings() {
        check_admin_referer(self::SAVE_ACTION);
        if (!ImgPro_CDN_Security::current_user_can()) {
            wp_die(esc_html__('Sorry, you are not allowed to manage these options.', 'bandwidth-saver'), 403);
        }

        $args = ['page' => self::PAGE_SLUG];
        $this->settings->refresh();
        // Serving cannot start without a key, or while images are being deleted
        if ($this->settings->is_connected() && !$this->settings->get('removing')) {
            $was_remote = (bool) $this->settings->get('remote');
            $remote     = isset($_POST['remote']) && '1' === sanitize_text_field(wp_unslash($_POST['remote']));
            $this->settings->update([
                'enabled' => isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash($_POST['enabled'])),
                'remote'  => $remote,
            ]);
            // Turned off: nothing more is imported from other websites, and
            // copies made stay. Only on the change, which is what the setting
            // controls; the worker drops any import queued after it.
            if ($was_remote && !$remote) {
                ImgPro_CDN_Files::forget_remote_queue();
            }
            $args['updated'] = 1;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
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
                <strong><?php esc_html_e('Bandwidth Saver 2.0 now serves images from your own img.pro App.', 'bandwidth-saver'); ?></strong>
                <?php esc_html_e('Bandwidth Saver no longer uses the old managed CDN or a self-hosted Cloudflare Worker, so your images load from your own server until you connect an img.pro API key. If you use a page cache, clear it, since cached pages still point to the old CDN.', 'bandwidth-saver'); ?>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Connect img.pro', 'bandwidth-saver'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Resume copying when the App has room again
     *
     * The worker checks a full App only now and then; the App's owner may
     * have just upgraded it, or deletions freed room. The page then shows
     * the state as it is rather than a pause that no longer applies.
     *
     * @since 2.0.0
     * @return void
     */
    public function lift_lapsed_quota_pause() {
        if (!ImgPro_CDN_Security::current_user_can() || !$this->settings->is_connected()) {
            return;
        }
        if (ImgPro_CDN_Settings::PAUSE_QUOTA === $this->settings->get('pause_reason') && ImgPro_CDN_Sync::has_room(self::get_usage($this->settings))) {
            $this->sync->resume();
        }
    }

    /**
     * Help tabs of the settings page
     *
     * @since 2.0.0
     * @return void
     */
    public function add_help_tabs() {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id'      => 'overview',
            'title'   => __('Overview', 'bandwidth-saver'),
            'content' =>
                '<p>' . esc_html__('Bandwidth Saver serves the images on your pages from your own img.pro App, so they load from img.pro\'s global network instead of your server. Your original files stay on your server.', 'bandwidth-saver') . '</p>' .
                '<p>' . esc_html__('An image is copied to your App the first time a page shows it. Until its copy is ready, the page loads it from your server. Images no page shows are not copied unless you copy them from the Media Library, so until then they use none of your App\'s images.', 'bandwidth-saver') . '</p>' .
                '<p>' . esc_html__('Images your posts load from other websites, such as an earlier domain of this site or an image inserted from a URL, are copied the same way: img.pro fetches each one from its address the first time a page shows it. The Images from other websites setting on this screen turns this off.', 'bandwidth-saver') . '</p>' .
                '<p>' . esc_html__('WordPress stores each image as several files, its full size and each thumbnail size, and each file is one image in your App. Free stores up to 5,000 images, Pro up to 50,000, and Unlimited has no limits. Sites connected to the same App share it.', 'bandwidth-saver') . '</p>',
        ]);

        $strong = ['strong' => []];
        $screen->add_help_tab([
            'id'      => 'troubleshooting',
            'title'   => __('Troubleshooting', 'bandwidth-saver'),
            'content' =>
                '<p>' . wp_kses(__('<strong>An image still loads from your server.</strong> Its copy may be queued, or the page may come from a page cache saved before the copy existed. Bandwidth Saver changes images in post content, widgets, featured images and images output with wp_get_attachment_image(). Images whose URL a theme or plugin reads directly, such as images in theme CSS, in the page head (social sharing images) or added by scripts, keep their server address; copy them in the Media Library and use their img.pro URL.', 'bandwidth-saver'), $strong) . '</p>' .
                /* translators: %s: Label of the Retry failed files button. */
                '<p>' . wp_kses(sprintf(__('<strong>Copy failed.</strong> Recent failures shows the reason for each file. Once the cause is fixed, click %s.', 'bandwidth-saver'), __('Retry failed files', 'bandwidth-saver')), $strong) . '</p>' .
                /* translators: %s: Label of the Try again now button. */
                '<p>' . wp_kses(sprintf(__('<strong>img.pro is busy.</strong> Copying tries again on its own. Click %s to try at once.', 'bandwidth-saver'), __('Try again now', 'bandwidth-saver')), $strong) . '</p>' .
                /* translators: %s: Label of the Disconnect and keep images button. */
                '<p>' . wp_kses(sprintf(__('<strong>A copy of this site.</strong> If this site is a staging copy of another site, click %s. While connected, deleting media here also deletes the other site\'s images from img.pro. Connecting again afterwards gives the copy its own label and its own copies.', 'bandwidth-saver'), __('Disconnect and keep images', 'bandwidth-saver')), $strong) . '</p>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . esc_html__('For more information:', 'bandwidth-saver') . '</strong></p>' .
            '<p><a href="' . esc_url(ImgPro_CDN_Settings::DASHBOARD_URL) . '">' . esc_html__('Your img.pro Apps', 'bandwidth-saver') . '</a></p>' .
            '<p><a href="https://wordpress.org/support/plugin/bandwidth-saver/">' . esc_html__('Support forums', 'bandwidth-saver') . '</a></p>'
        );
    }

    /**
     * Cached img.pro usage, refreshed every minute
     *
     * Failures are cached for a minute too, so a slow or unreachable
     * img.pro holds up at most one request a minute, not every page load
     * and sync step.
     *
     * @since 2.0.0
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @return array|null Usage object, or null when unavailable.
     */
    public static function get_usage($settings) {
        $cached = get_transient(self::USAGE_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
        if (self::USAGE_UNAVAILABLE === $cached) {
            return null;
        }

        $api = new ImgPro_CDN_API($settings->get_api_key());
        $usage = $api->get_usage();
        if (is_wp_error($usage)) {
            set_transient(self::USAGE_TRANSIENT, self::USAGE_UNAVAILABLE, MINUTE_IN_SECONDS);
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
        // Unlimited has no limit, whatever a response may carry
        if ('unlimited' === ($usage['plan'] ?? '')) {
            $limit = null;
        }

        return [
            'used'  => null === $used ? null : (int) $used,
            'limit' => null === $limit ? null : (int) $limit,
        ];
    }

    /**
     * "1,234 of 5,000" style text for the App's image count
     *
     * @since 2.0.0
     * @param array|null $usage Usage object.
     * @return string
     */
    public static function format_image_counts($usage) {
        $counts = self::get_image_counts($usage);
        if (null !== $counts['used'] && null !== $counts['limit']) {
            /* translators: 1: Images stored, 2: Plan limit. */
            return sprintf(__('%1$s of %2$s', 'bandwidth-saver'), number_format_i18n($counts['used']), number_format_i18n($counts['limit']));
        }
        if (null !== $counts['used']) {
            return number_format_i18n($counts['used']);
        }
        return __('Not available', 'bandwidth-saver');
    }

    /**
     * Why copying is paused
     *
     * @since 2.0.0
     * @param string     $reason PAUSE_* constant.
     * @param array|null $usage  Usage object, for the plan and its limit.
     * @param bool       $short  One sentence for the Media Library, instead
     *                           of the settings page's full explanation.
     * @return string Empty for an unknown reason.
     */
    public static function pause_message($reason, $usage, $short = false) {
        if (ImgPro_CDN_Settings::PAUSE_QUOTA === $reason && ImgPro_CDN_Sync::has_room($usage)) {
            // The App was upgraded, or deletions freed room, since copying paused
            if ($short) {
                return __('Your App was at its plan\'s limit when Bandwidth Saver last copied an image, and it has room again.', 'bandwidth-saver');
            }
            /* translators: %s: Label of the Try again now button. */
            return sprintf(__('Your App was at its plan\'s limit when Bandwidth Saver last copied an image, and it has room again. Click %s to copy again.', 'bandwidth-saver'), __('Try again now', 'bandwidth-saver'));
        }
        if (ImgPro_CDN_Settings::PAUSE_QUOTA === $reason) {
            $plan  = is_array($usage) ? (string) ($usage['plan'] ?? '') : '';
            $limit = self::get_image_counts($usage)['limit'];
            $limit = null === $limit ? '' : number_format_i18n($limit);

            if ('free' === $plan && '' !== $limit && $short) {
                /* translators: %s: The plan's image limit. */
                return sprintf(__('Your App is at the Free limit of %s images, so new images keep loading from their original address. The App\'s owner can upgrade it to Pro in Billing on img.pro.', 'bandwidth-saver'), $limit);
            }
            if ('free' === $plan && '' !== $limit) {
                /* translators: %s: The plan's image limit. */
                return sprintf(__('Your App is at the Free limit of %s images. Images already on img.pro keep loading from it, and new ones keep loading from their original address. The App\'s owner can upgrade it to Pro in Billing on img.pro, or you can delete media you no longer use in WordPress, which deletes its copies too. Copying starts again on its own once the App has room.', 'bandwidth-saver'), $limit);
            }
            if ('pro' === $plan && '' !== $limit && $short) {
                /* translators: %s: The plan's image limit. */
                return sprintf(__('Your App is at the Pro limit of %s images, so new images keep loading from their original address. The App\'s owner can upgrade it to Unlimited in Billing on img.pro.', 'bandwidth-saver'), $limit);
            }
            if ('pro' === $plan && '' !== $limit) {
                /* translators: %s: The plan's image limit. */
                return sprintf(__('Your App is at the Pro limit of %s images. Images already on img.pro keep loading from it, and new ones keep loading from their original address. The App\'s owner can upgrade it to Unlimited in Billing on img.pro, or you can delete media you no longer use in WordPress, which deletes its copies too. Copying starts again on its own once the App has room.', 'bandwidth-saver'), $limit);
            }
            return $short
                ? __('Your App is at its plan\'s limit of images, so new images keep loading from their original address. The App\'s owner can upgrade it in Billing on img.pro.', 'bandwidth-saver')
                : __('Your App is at its plan\'s limit of images. Images already on img.pro keep loading from it, and new ones keep loading from their original address. The App\'s owner can upgrade it in Billing on img.pro, or you can delete media you no longer use in WordPress, which deletes its copies too. Copying starts again on its own once the App has room.', 'bandwidth-saver');
        }

        // img.pro's own words where it has them: a block names no actor and
        // no reason, and a pause is something the App's owner or an admin did.
        // An App-wide key gets the same answer for a paused App and a
        // blocked one, so PAUSE_APP covers both.
        if ($short) {
            $messages = [
                ImgPro_CDN_Settings::PAUSE_AUTH     => __('img.pro did not accept Bandwidth Saver\'s API key, so no images are copied.', 'bandwidth-saver'),
                ImgPro_CDN_Settings::PAUSE_APP      => __('Your img.pro App is paused or unavailable, so no images are copied.', 'bandwidth-saver'),
                ImgPro_CDN_Settings::PAUSE_BLOCKED  => __('This App is unavailable right now. Write to support@img.pro.', 'bandwidth-saver'),
                ImgPro_CDN_Settings::PAUSE_DELETING => __('This App\'s images are being deleted on img.pro, so it takes no new ones.', 'bandwidth-saver'),
            ];
        } else {
            $messages = [
                ImgPro_CDN_Settings::PAUSE_AUTH     => __('img.pro did not accept your API key, or the key cannot upload. Paste a key with Read and Write permission below.', 'bandwidth-saver'),
                /* translators: %s: Label of the Try again now button. */
                ImgPro_CDN_Settings::PAUSE_APP      => sprintf(__('Your img.pro App is paused or unavailable, and this key stops working while it is. Check the App\'s Overview on img.pro, and once the App is active again, click %s. A key with App storage only data access keeps working while the App is paused.', 'bandwidth-saver'), __('Try again now', 'bandwidth-saver')),
                ImgPro_CDN_Settings::PAUSE_BLOCKED  => __('This App is unavailable right now. Write to support@img.pro.', 'bandwidth-saver'),
                ImgPro_CDN_Settings::PAUSE_DELETING => __('This App\'s images are being deleted on img.pro, so it takes no new ones. Connect a key from another App below.', 'bandwidth-saver'),
            ];
        }
        return $messages[$reason] ?? '';
    }

    /**
     * Why copying is paused, reading the App's usage only when it matters
     *
     * Only the quota messages name the plan and its limit, so other pauses
     * cost no request to img.pro (for a rejected key it would fail anyway).
     *
     * @since 2.0.0
     * @param string              $reason   PAUSE_* constant.
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @param bool                $short    See pause_message().
     * @return string
     */
    public static function pause_text($reason, $settings, $short = false) {
        $usage = ImgPro_CDN_Settings::PAUSE_QUOTA === $reason ? self::get_usage($settings) : null;
        return self::pause_message($reason, $usage, $short);
    }

    /**
     * Sentence describing what the worker is doing
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return string
     */
    public static function status_text($status) {
        switch ($status['phase']) {
            case 'scanning':
                return __('Scanning your Media Library…', 'bandwidth-saver');
            case 'matching':
                return __('Checking which files are already in your App…', 'bandwidth-saver');
            case 'uploading':
                /* translators: %s: Number of files. */
                return sprintf(_n('Copying %s file to img.pro…', 'Copying %s files to img.pro…', (int) $status['pending'], 'bandwidth-saver'), number_format_i18n((int) $status['pending']));
            case 'waiting':
                if ('database' === ($status['wait_reason'] ?? '')) {
                    return __('This site\'s database returned an error, so copying waits a little before trying again.', 'bandwidth-saver');
                }
                return empty($status['removing'])
                    ? __('img.pro is busy or did not answer, so copying waits a little before trying again.', 'bandwidth-saver')
                    : __('img.pro is busy or did not answer, so deleting waits a little before trying again.', 'bandwidth-saver');
            case 'paused':
                return empty($status['removing'])
                    ? __('Nothing is copied until the problem described above is solved.', 'bandwidth-saver')
                    : __('Nothing is deleted until the problem described above is solved.', 'bandwidth-saver');
            case 'removing':
                return __('Deleting this site\'s images from img.pro…', 'bandwidth-saver');
        }
        return __('Nothing is waiting to be copied.', 'bandwidth-saver');
    }

    /**
     * "1,234 files" style count
     *
     * @since 2.0.0
     * @param int $count Files.
     * @return string
     */
    public static function count_text($count) {
        /* translators: %s: Number of files. */
        return sprintf(_n('%s file', '%s files', (int) $count, 'bandwidth-saver'), number_format_i18n((int) $count));
    }

    /**
     * Status texts for the settings page script
     *
     * Formatted here, so plurals follow the site's language.
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return array{text: string, counts: string[]}
     */
    public static function status_labels($status) {
        $counts = [];
        foreach (['synced', 'remote', 'pending', 'idle', 'failed', 'skipped', 'deleting'] as $key) {
            $counts[$key] = self::count_text((int) $status[$key]);
        }
        return ['text' => self::status_text($status), 'counts' => $counts];
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
        <div class="wrap">
            <h1><?php esc_html_e('Bandwidth Saver', 'bandwidth-saver'); ?></h1>
            <hr class="wp-header-end">
            <?php
            if ($this->settings->is_connected()) {
                $this->render_connected();
            } else {
                $this->render_leftover_notice();
                $this->render_connect_form();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Form asking for an img.pro API key
     *
     * @since 2.0.0
     * @param bool $reconnect Whether a key is already stored (replacing it).
     * @return void
     */
    private function render_connect_form($reconnect = false) {
        $key_step = __('In the App, open API Keys and choose New key. Keep Read and Write ticked and, if it asks for Data access, keep App storage only. img.pro shows the key once, so copy it.', 'bandwidth-saver');
        ?>
        <h2 class="title"><?php echo $reconnect ? esc_html__('Replace your API key', 'bandwidth-saver') : esc_html__('Connect img.pro', 'bandwidth-saver'); ?></h2>
        <?php if ($reconnect) : ?>
            <p><?php echo esc_html($key_step); ?></p>
        <?php else : ?>
            <p><?php esc_html_e('Bandwidth Saver serves the images on your pages from your own img.pro App. It copies each image to your App the first time a page shows it, and your original files stay on your server.', 'bandwidth-saver'); ?></p>
            <ol>
                <li>
                    <?php
                    printf(
                        /* translators: %s: Link to the img.pro dashboard. */
                        esc_html__('Sign in at %s and create an App. The Free plan works.', 'bandwidth-saver'),
                        '<a href="' . esc_url(ImgPro_CDN_Settings::DASHBOARD_URL) . '">img.pro</a>'
                    );
                    ?>
                </li>
                <li><?php echo esc_html($key_step); ?></li>
                <li><?php esc_html_e('Paste the key below.', 'bandwidth-saver'); ?></li>
            </ol>
        <?php endif; ?>
        <form id="imgpro-connect-form" autocomplete="off">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="imgpro-api-key"><?php esc_html_e('API key', 'bandwidth-saver'); ?></label></th>
                    <td>
                        <input type="password" id="imgpro-api-key" class="regular-text code" placeholder="img_sk_live_…" spellcheck="false" autocomplete="off" aria-describedby="imgpro-api-key-description" required>
                        <p class="description" id="imgpro-api-key-description"><?php esc_html_e('WordPress stores each image as several files, its full size and each thumbnail size, and each file is one image in your App. Free stores up to 5,000 images, Pro up to 50,000, and Unlimited has no limits.', 'bandwidth-saver'); ?></p>
                        <div id="imgpro-connect-error" class="notice notice-error inline hidden"><p></p></div>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e('Connect', 'bandwidth-saver'); ?></button></p>
        </form>
        <?php
    }

    /**
     * Sync status for this page load
     *
     * Read once: the page's script and its markup show the same moment, and
     * the counts walk the whole file map.
     *
     * @since 2.0.0
     * @return array See ImgPro_CDN_Sync::get_status().
     */
    private function page_status() {
        if (null === $this->page_status) {
            $this->page_status = $this->sync->get_status();
        }
        return $this->page_status;
    }

    /**
     * Page body once a key is connected
     *
     * @since 2.0.0
     * @return void
     */
    private function render_connected() {
        $status   = $this->page_status();
        $removing = (bool) $this->settings->get('removing');
        $usage    = self::get_usage($this->settings);

        $this->render_pause_notice($status, $usage);
        $this->render_address_notice();
        $this->render_other_app_notice();

        if ($removing) {
            ?>
            <h2 class="title"><?php esc_html_e('Deleting images from img.pro', 'bandwidth-saver'); ?></h2>
            <?php $this->render_status_line($status); ?>
            <?php
        } else {
            $this->render_serving_form();
            $this->render_copying($status);
        }

        $this->render_usage($usage, $status, $removing);

        if (in_array($status['pause_reason'], [ImgPro_CDN_Settings::PAUSE_AUTH, ImgPro_CDN_Settings::PAUSE_DELETING], true)) {
            $this->render_connect_form(true);
        }
        ?>
        <h2 class="title"><?php esc_html_e('Disconnect', 'bandwidth-saver'); ?></h2>
        <?php if ($removing) : ?>
            <p><?php esc_html_e('Disconnecting now stops deleting and forgets your API key. Images not deleted yet stay in your App.', 'bandwidth-saver'); ?></p>
        <?php else : ?>
            <p><?php esc_html_e('Disconnecting stops serving images from img.pro and forgets your API key. Choose whether to keep this site\'s copies in your App or delete them.', 'bandwidth-saver'); ?></p>
        <?php endif; ?>
        <p>
            <button type="button" class="button" id="imgpro-disconnect"><?php esc_html_e('Disconnect and keep images', 'bandwidth-saver'); ?></button>
            <?php if (!$removing) : ?>
                <button type="button" class="button-link button-link-delete" id="imgpro-remove-all"><?php esc_html_e('Delete images from img.pro and disconnect', 'bandwidth-saver'); ?></button>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Serving on or off, saved with Save Changes
     *
     * @since 2.0.0
     * @return void
     */
    private function render_serving_form() {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <?php wp_nonce_field(self::SAVE_ACTION); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Serve images', 'bandwidth-saver'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span><?php esc_html_e('Serve images', 'bandwidth-saver'); ?></span></legend>
                            <label for="imgpro-enabled">
                                <input name="enabled" type="checkbox" id="imgpro-enabled" value="1" <?php checked((bool) $this->settings->get('enabled')); ?>>
                                <?php esc_html_e('Load images on your pages from img.pro', 'bandwidth-saver'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('When a page shows an image for the first time, Bandwidth Saver copies it to your App, and the page loads it from img.pro once the copy is ready. While this is off, every image loads from its original address and pages ask for no new copies. Copy to img.pro in the Media Library works either way.', 'bandwidth-saver'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Images from other websites', 'bandwidth-saver'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><span><?php esc_html_e('Images from other websites', 'bandwidth-saver'); ?></span></legend>
                            <label for="imgpro-remote">
                                <input name="remote" type="checkbox" id="imgpro-remote" value="1" <?php checked((bool) $this->settings->get('remote')); ?>>
                                <?php esc_html_e('Copy images that pages load from other websites', 'bandwidth-saver'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Posts can show images from other addresses, such as an earlier domain of this site or an image inserted from a URL. img.pro copies such an image from its address the first time a page shows it, and each one counts as an image in your App. Copy only images you have the right to use.', 'bandwidth-saver'); ?></p>
                        </fieldset>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * What is copied, what waits, and what failed
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return void
     */
    private function render_copying($status) {
        $rows = [
            'synced'   => [__('Copied', 'bandwidth-saver'), ''],
            'remote'   => [__('From other websites', 'bandwidth-saver'), __('Copies of images pages load from other websites, included in Copied.', 'bandwidth-saver')],
            'pending'  => [__('Queued', 'bandwidth-saver'), __('Pages showed these files, or someone copied them from the Media Library. They keep loading from their original address until their copies are ready.', 'bandwidth-saver')],
            // Pages ask for copies only while they are served from img.pro
            'idle'     => [__('Not copied yet', 'bandwidth-saver'), $this->settings->is_serving() ? __('Copied when a page shows them.', 'bandwidth-saver') : ''],
            'failed'   => [__('Copy failed', 'bandwidth-saver'), ''],
            'skipped'  => [__('Stays on your server', 'bandwidth-saver'), __('img.pro does not accept these images: formats other than JPEG, PNG, GIF, WebP, AVIF and HEIC, files larger than 20 MB, and animated PNGs. Images missing from the uploads folder, and images img.pro blocked, are counted here too.', 'bandwidth-saver')],
            'deleting' => [__('Being deleted from img.pro', 'bandwidth-saver'), __('Copies of files deleted in WordPress.', 'bandwidth-saver')],
        ];
        ?>
        <h2 class="title"><?php esc_html_e('Copying to img.pro', 'bandwidth-saver'); ?></h2>
        <p><?php esc_html_e('Bandwidth Saver copies an image to your App the first time a page shows it. Images no page shows are not copied, and use none of your App\'s images, until you copy them.', 'bandwidth-saver'); ?></p>
        <?php $this->render_status_line($status); ?>
        <table class="form-table imgpro-counts" role="presentation">
            <?php foreach ($rows as $key => list($label, $description)) : ?>
                <tr id="imgpro-row-<?php echo esc_attr($key); ?>"<?php echo (in_array($key, ['deleting', 'remote'], true) && !$status[$key]) ? ' class="hidden"' : ''; ?>>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td>
                        <?php /* In a paragraph, as in core's tables, so it lines up with its label */ ?>
                        <p><span id="imgpro-count-<?php echo esc_attr($key); ?>"><?php echo esc_html(self::count_text((int) $status[$key])); ?></span></p>
                        <?php if ('failed' === $key) : ?>
                            <?php /* Its own paragraph: a button beside the count would push the count below its label */ ?>
                            <p><button type="button" class="button<?php echo $status['failed'] ? '' : ' hidden'; ?>" id="imgpro-retry"><?php esc_html_e('Retry failed files', 'bandwidth-saver'); ?></button></p>
                            <?php $this->render_failures(); ?>
                        <?php elseif ('skipped' === $key) : ?>
                            <?php $this->render_skipped(); ?>
                        <?php endif; ?>
                        <?php if ('' !== $description) : ?>
                            <p class="description"><?php echo esc_html($description); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p><?php esc_html_e('Each size of an image is a separate file, and each file is one image in your App.', 'bandwidth-saver'); ?></p>
        <p>
            <?php
            printf(
                /* translators: 1: Link to the Media Library, 2: Link to its list view. */
                esc_html__('To copy an image before a page shows it, open it in the %1$s and choose Copy to img.pro. To copy several, switch to %2$s, select them, and choose Copy to img.pro from Bulk actions.', 'bandwidth-saver'),
                '<a href="' . esc_url(admin_url('upload.php')) . '">' . esc_html__('Media Library', 'bandwidth-saver') . '</a>',
                '<a href="' . esc_url(admin_url('upload.php?mode=list')) . '">' . esc_html__('list view', 'bandwidth-saver') . '</a>'
            );
            ?>
        </p>
        <?php
    }

    /**
     * Sentence describing the worker, with Try again now when it applies
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return void
     */
    private function render_status_line($status) {
        ?>
        <p>
            <span id="imgpro-status-text"><?php echo esc_html(self::status_text($status)); ?></span>
            <button type="button" class="button<?php echo self::can_resume($status) ? '' : ' hidden'; ?>" id="imgpro-resume"><?php esc_html_e('Try again now', 'bandwidth-saver'); ?></button>
        </p>
        <?php
    }

    /**
     * Whether Try again now applies to a sync status
     *
     * Quota, paused-App and blocked-App pauses resume once the problem is
     * fixed on img.pro. A key problem, or an App being deleted, needs a new
     * key instead. A wait after repeated errors can grow to an hour, so it
     * can be cut short too.
     *
     * @since 2.0.0
     * @param array $status Sync status.
     * @return bool
     */
    private static function can_resume($status) {
        $needs_key = [ImgPro_CDN_Settings::PAUSE_AUTH, ImgPro_CDN_Settings::PAUSE_DELETING];
        return ('paused' === $status['phase'] && !in_array($status['pause_reason'], $needs_key, true))
            || 'waiting' === $status['phase'];
    }

    /**
     * Notice explaining why copying is paused
     *
     * @since 2.0.0
     * @param array      $status Sync status.
     * @param array|null $usage  Usage object.
     * @return void
     */
    private function render_pause_notice($status, $usage) {
        $message = self::pause_message($status['pause_reason'], $usage);
        if ('' === $message) {
            return;
        }
        // The blocked message is img.pro's own, and a reason the plugin
        // words itself (img.pro sent no words) is not img.pro's; repeating
        // either adds nothing
        $detail      = (string) $status['pause_detail'];
        $show_detail = '' !== $detail && ImgPro_CDN_Settings::PAUSE_BLOCKED !== $status['pause_reason'] && 0 !== strpos($detail, ImgPro_CDN_Files::REASON_PREFIX);
        ?>
        <div class="notice notice-warning" id="imgpro-pause-notice">
            <p><?php echo esc_html($message); ?></p>
            <?php if (ImgPro_CDN_Settings::PAUSE_QUOTA === $status['pause_reason'] && !ImgPro_CDN_Sync::has_room($usage)) : ?>
                <p><a href="<?php echo esc_url(ImgPro_CDN_Settings::BILLING_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open Billing on img.pro', 'bandwidth-saver'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'bandwidth-saver'); ?></span></a></p>
            <?php endif; ?>
            <?php if ($show_detail) : ?>
                <p>
                    <?php
                    /* translators: %s: Error message returned by img.pro. */
                    printf(esc_html__('img.pro said: %s', 'bandwidth-saver'), esc_html($status['pause_detail']));
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Notice shown when the site's address no longer matches its label
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
        <div class="notice notice-warning">
            <p>
                <?php
                /* translators: 1: Address the site connected from, 2: The site's current address. */
                printf(esc_html__('This site connected to img.pro as %1$s, but its address is now %2$s.', 'bandwidth-saver'), '<strong>' . esc_html($label) . '</strong>', '<strong>' . esc_html($current) . '</strong>');
                ?>
            </p>
            <p><?php esc_html_e('If you moved the site, there is nothing to do: its images on img.pro keep the old address as their label.', 'bandwidth-saver'); ?></p>
            <p>
                <?php
                printf(
                    /* translators: %s: Label of the Disconnect and keep images button. */
                    esc_html__('If this is a copy of another site, such as a staging site, click %s below. While connected, deleting media here also deletes the original site\'s images from img.pro. Connecting again afterwards gives this copy its own label and its own copies.', 'bandwidth-saver'),
                    esc_html__('Disconnect and keep images', 'bandwidth-saver')
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Notice, shown once, about copies left in an App this site no longer uses
     *
     * Deletions queued under an earlier key name copies in that App's
     * storage, which the current key cannot delete.
     *
     * @since 2.0.0
     * @return void
     */
    private function render_other_app_notice() {
        $count = (int) get_option(ImgPro_CDN_Sync::OTHER_APP_OPTION, 0);
        if ($count < 1) {
            return;
        }
        delete_option(ImgPro_CDN_Sync::OTHER_APP_OPTION);
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %s: Number of images. */
                    esc_html(_n('%s image this site no longer uses is still in the App it used before, because this site now uses another App. You can delete it in that App on img.pro.', '%s images this site no longer uses are still in the App it used before, because this site now uses another App. You can delete them in that App on img.pro.', $count, 'bandwidth-saver')),
                    esc_html(number_format_i18n($count))
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Notice shown after "delete images" finished with some images left
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
        <div class="notice notice-warning">
            <p>
                <?php
                printf(
                    /* translators: %s: Number of images. */
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
        self::render_file_list(ImgPro_CDN_Files::get_recent_failures(5), __('Recent failures', 'bandwidth-saver'));
    }

    /**
     * Files most recently found to stay on the server, with why
     *
     * @since 2.0.0
     * @return void
     */
    private function render_skipped() {
        self::render_file_list(ImgPro_CDN_Files::get_recent_skipped(5), __('Files that stay on your server', 'bandwidth-saver'));
    }

    /**
     * A collapsed list of files and their reasons
     *
     * @since 2.0.0
     * @param object[] $rows    Rows with file and error.
     * @param string   $summary The list's title.
     * @return void
     */
    private static function render_file_list($rows, $summary) {
        if (empty($rows)) {
            return;
        }
        ?>
        <details class="imgpro-failures">
            <summary><?php echo esc_html($summary); ?></summary>
            <ul class="ul-disc">
                <?php foreach ($rows as $row) : ?>
                    <li><code><?php echo esc_html($row->file); ?></code> <?php echo esc_html(ImgPro_CDN_Files::error_text($row->error)); ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    /**
     * Plan and image count of the connected App
     *
     * @since 2.0.0
     * @param array|null $usage    Usage object.
     * @param array      $status   Sync status.
     * @param bool       $removing Whether the site's images are being deleted.
     * @return void
     */
    private function render_usage($usage, $status, $removing) {
        $plans = [
            'free'      => __('Free', 'bandwidth-saver'),
            'pro'       => __('Pro', 'bandwidth-saver'),
            'unlimited' => __('Unlimited', 'bandwidth-saver'),
        ];
        $plan   = is_array($usage) && isset($usage['plan']) ? (string) $usage['plan'] : '';
        $counts = self::get_image_counts($usage);
        ?>
        <h2 class="title"><?php esc_html_e('Your img.pro App', 'bandwidth-saver'); ?></h2>
        <table class="form-table imgpro-counts" role="presentation">
            <?php if ('' !== $plan) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Plan', 'bandwidth-saver'); ?></th>
                    <?php /* A plan this version does not know yet is a paid one; never print the raw key */ ?>
                    <td><p><?php echo esc_html($plans[$plan] ?? __('Paid plan', 'bandwidth-saver')); ?></p></td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e('Images stored', 'bandwidth-saver'); ?></th>
                <td>
                    <p id="imgpro-usage-images"><?php echo esc_html(self::format_image_counts($usage)); ?></p>
                    <p class="description"><?php esc_html_e('Every site connected to this App counts toward this total.', 'bandwidth-saver'); ?></p>
                </td>
            </tr>
        </table>
        <?php
        // A quota pause has its own notice, and while every image is being
        // deleted the App's room does not matter
        $warn = !$removing && ImgPro_CDN_Settings::PAUSE_QUOTA !== $status['pause_reason'];
        if ($warn && null !== $counts['used'] && $counts['limit'] && $counts['used'] >= 0.9 * $counts['limit']) {
            $room = $counts['limit'] - $counts['used'];
            if ($room > 0) {
                /* translators: %s: Number of images. */
                $message = sprintf(_n('Your App has room for %s more image. When it reaches its limit, new images keep loading from their original address.', 'Your App has room for %s more images. When it reaches its limit, new images keep loading from their original address.', $room, 'bandwidth-saver'), number_format_i18n($room));
            } else {
                $message = self::pause_message(ImgPro_CDN_Settings::PAUSE_QUOTA, $usage, true);
            }
            echo '<div class="notice notice-warning inline"><p>' . esc_html($message) . '</p></div>';
        }
        ?>
        <p><a href="<?php echo esc_url(ImgPro_CDN_Settings::DASHBOARD_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Manage your App on img.pro', 'bandwidth-saver'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'bandwidth-saver'); ?></span></a></p>
        <?php
    }
}
