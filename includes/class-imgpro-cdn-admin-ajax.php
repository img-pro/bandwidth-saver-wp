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
 * AJAX endpoints behind the settings page
 *
 * @since 0.1.2
 */
class ImgPro_CDN_Admin_Ajax {

    /**
     * Seconds of sync work per admin-driven step
     *
     * @since 2.0.0
     * @var int
     */
    const STEP_BUDGET = 8;

    /**
     * Settings instance
     *
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Sync instance
     *
     * @var ImgPro_CDN_Sync
     */
    private $sync;

    /**
     * Constructor
     *
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @param ImgPro_CDN_Sync     $sync     Sync instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings, ImgPro_CDN_Sync $sync) {
        $this->settings = $settings;
        $this->sync = $sync;
    }

    /**
     * Register AJAX hooks
     *
     * @return void
     */
    public function register_hooks() {
        add_action('wp_ajax_imgpro_cdn_connect', [$this, 'ajax_connect']);
        add_action('wp_ajax_imgpro_cdn_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_imgpro_cdn_toggle_enabled', [$this, 'ajax_toggle_enabled']);
        add_action('wp_ajax_imgpro_cdn_sync_status', [$this, 'ajax_sync_status']);
        add_action('wp_ajax_imgpro_cdn_sync_step', [$this, 'ajax_sync_step']);
        add_action('wp_ajax_imgpro_cdn_retry_failed', [$this, 'ajax_retry_failed']);
        add_action('wp_ajax_imgpro_cdn_resume_sync', [$this, 'ajax_resume_sync']);
        add_action('wp_ajax_imgpro_cdn_remove_all', [$this, 'ajax_remove_all']);
    }

    /**
     * Validate and store an img.pro API key
     *
     * @return void
     */
    public function ajax_connect() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_connect'), 'nonce');
        ImgPro_CDN_Security::check_permission();
        ImgPro_CDN_Security::check_rate_limit('connect');

        $api_key = isset($_POST['api_key']) ? ImgPro_CDN_Settings::sanitize_api_key(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
        if ('' === $api_key) {
            wp_send_json_error(['message' => __('Paste your img.pro API key.', 'bandwidth-saver')]);
        }

        $api = new ImgPro_CDN_API($api_key);

        $usage = $api->get_usage();
        if (is_wp_error($usage)) {
            wp_send_json_error(['message' => $this->describe_key_error($usage)]);
        }

        $write = $api->check_write_access();
        if (is_wp_error($write)) {
            wp_send_json_error(['message' => $this->describe_key_error($write)]);
        }

        $this->settings->update([
            'api_key'      => $api_key,
            'enabled'      => true,
            'pause_reason' => ImgPro_CDN_Settings::PAUSE_NONE,
            'pause_detail' => '',
            'removing'     => false,
        ]);
        set_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT, $usage, MINUTE_IN_SECONDS);
        delete_option(ImgPro_CDN_Core::UPGRADE_NOTICE_OPTION);

        $this->sync->start();

        wp_send_json_success(['message' => __('Connected.', 'bandwidth-saver')]);
    }

    /**
     * Explain why a key was rejected
     *
     * @param WP_Error $error API error.
     * @return string
     */
    private function describe_key_error($error) {
        $data   = (array) $error->get_error_data();
        $status = (int) ($data['status'] ?? 0);
        $code   = $error->get_error_code();

        if (401 === $status) {
            return __('img.pro did not recognize this key. Check that you copied all of it and that it has not been revoked.', 'bandwidth-saver');
        }
        if ('forbidden' === $code) {
            return __('This key cannot upload images. Create a key with Read and Write permission.', 'bandwidth-saver');
        }
        if (in_array($code, ['app_suspended', 'app_blocked'], true)) {
            return __('img.pro has paused this App. Check your App on img.pro.', 'bandwidth-saver');
        }
        if ('connection_error' === $code) {
            /* translators: %s: connection error message */
            return sprintf(__('Could not reach img.pro: %s', 'bandwidth-saver'), $error->get_error_message());
        }

        return $error->get_error_message();
    }

    /**
     * Forget the key; images stay on img.pro
     *
     * @return void
     */
    public function ajax_disconnect() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_disconnect'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        $this->sync->disconnect();
        delete_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT);

        wp_send_json_success(['connected' => false]);
    }

    /**
     * Delete this site's images from img.pro, then disconnect
     *
     * @return void
     */
    public function ajax_remove_all() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_remove_all'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        if (!$this->settings->is_connected()) {
            wp_send_json_error(['message' => __('img.pro is not connected.', 'bandwidth-saver')]);
        }

        $this->sync->start_removal();
        wp_send_json_success($this->status_payload());
    }

    /**
     * Turn serving from img.pro on or off
     *
     * @return void
     */
    public function ajax_toggle_enabled() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_toggle_enabled'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        $enabled = isset($_POST['enabled']) && '1' === sanitize_text_field(wp_unslash($_POST['enabled']));
        if (!$this->settings->is_connected() || $this->settings->get('removing')) {
            wp_send_json_error(['message' => __('img.pro is not connected.', 'bandwidth-saver')]);
        }

        $this->settings->update(['enabled' => $enabled]);
        wp_send_json_success(['enabled' => $enabled]);
    }

    /**
     * Current sync status
     *
     * @return void
     */
    public function ajax_sync_status() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_sync_status'), 'nonce');
        ImgPro_CDN_Security::check_permission();
        wp_send_json_success($this->status_payload());
    }

    /**
     * Run a short burst of sync work, then report status
     *
     * The settings page calls this repeatedly while it is open, so large
     * libraries sync quickly even on sites with little traffic.
     *
     * @return void
     */
    public function ajax_sync_step() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_sync_step'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        if ($this->settings->is_connected()) {
            $this->sync->run(self::STEP_BUDGET);
            $this->settings->clear_cache();
        }

        wp_send_json_success($this->status_payload());
    }

    /**
     * Queue failed files again
     *
     * @return void
     */
    public function ajax_retry_failed() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_retry_failed'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        ImgPro_CDN_Files::retry_failed();
        ImgPro_CDN_Sync::schedule_soon();

        wp_send_json_success($this->status_payload());
    }

    /**
     * Resume after a quota or App pause
     *
     * @return void
     */
    public function ajax_resume_sync() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_resume_sync'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        $this->sync->resume();
        delete_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT);

        wp_send_json_success($this->status_payload());
    }

    /**
     * Status payload for the settings page
     *
     * @return array
     */
    private function status_payload() {
        if (!$this->settings->is_connected()) {
            return ['connected' => false];
        }

        $usage = ImgPro_CDN_Admin::get_usage($this->settings);

        return [
            'connected' => true,
            'enabled'   => (bool) $this->settings->get('enabled'),
            'status'    => $this->sync->get_status(),
            'images'    => is_array($usage) ? ImgPro_CDN_Admin::format_image_counts(ImgPro_CDN_Admin::get_image_counts($usage)) : null,
        ];
    }
}
