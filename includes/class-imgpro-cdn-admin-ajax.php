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
 * The Media Library's copy button has its own (see ImgPro_CDN_Media).
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
            wp_send_json_error(['message' => $this->describe_key_error($usage, 'read')]);
        }

        $write = $api->check_write_access();
        if (is_wp_error($write)) {
            wp_send_json_error(['message' => $this->describe_key_error($write, 'write')]);
        }

        // A key replaced while images are being deleted carries on deleting,
        // and a replaced key keeps the Serve images setting as it was saved;
        // a first connection turns serving on
        $previous      = $this->settings->refresh();
        $removing      = (bool) $previous['removing'];
        $was_connected = $this->settings->is_connected();

        // A site whose label names another address is a copy of that site (a
        // staging copy, say) or a site that moved. Connecting it afresh gives
        // it its own label, so it never lists, adopts or deletes the other
        // site's images, and drops the rows it was copied with, including
        // deletions that name the other site's images. A moved site that
        // wants its old copies back keeps its old label with the
        // imgpro_cdn_site_label filter (the label then matches). Replacing
        // the key of a connected site keeps everything.
        $label = (string) $previous['site_label'];
        if (!$was_connected && !$removing && '' !== $label && ImgPro_CDN_Settings::current_site_label() !== $label) {
            $this->settings->update(['site_label' => '']);
            ImgPro_CDN_Files::clear();
        }

        $this->settings->update([
            'api_key'      => $api_key,
            'enabled'      => !$removing && (!$was_connected || (bool) $previous['enabled']),
            'pause_reason' => ImgPro_CDN_Settings::PAUSE_NONE,
            'pause_detail' => '',
        ]);
        set_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT, $usage, MINUTE_IN_SECONDS);
        delete_option(ImgPro_CDN_Core::UPGRADE_NOTICE_OPTION);

        if ($removing) {
            ImgPro_CDN_Sync::schedule_soon();
        } else {
            $this->sync->start();
        }

        wp_send_json_success(['message' => __('Connected.', 'bandwidth-saver')]);
    }

    /**
     * Explain why a key was rejected
     *
     * @param WP_Error $error API error.
     * @param string   $step  The check that failed: 'read' (usage) or 'write' (upload probe).
     * @return string
     */
    private function describe_key_error($error, $step) {
        $data   = (array) $error->get_error_data();
        $status = (int) ($data['status'] ?? 0);
        $code   = $error->get_error_code();

        if (401 === $status) {
            return __('img.pro did not recognize this key. Check that you copied all of it and that it has not been revoked. A key also stops working when the person who created it is no longer the App\'s owner or an admin. Keys created on test.img.pro only work there.', 'bandwidth-saver');
        }
        if ('forbidden' === $code) {
            return 'read' === $step
                ? __('This key cannot read the App\'s images. Create a key with Read and Write permission.', 'bandwidth-saver')
                : __('This key cannot upload images. Create a key with Read and Write permission.', 'bandwidth-saver');
        }
        if ('app_suspended' === $code) {
            return __('This App is paused or unavailable on img.pro, and this key stops working while it is. Check the App\'s Overview on img.pro, or use a key with App storage only data access.', 'bandwidth-saver');
        }
        if ('app_blocked' === $code) {
            return __('This App is unavailable right now. Write to support@img.pro.', 'bandwidth-saver');
        }
        if ('workspace_unavailable' === $code) {
            return __('This App\'s images are being deleted on img.pro, so it takes no new ones. Use a key from another App.', 'bandwidth-saver');
        }
        if ('connection_error' === $code) {
            /* translators: %s: connection error message */
            return sprintf(__('Could not reach img.pro: %s', 'bandwidth-saver'), $error->get_error_message());
        }
        if (429 === $status || $status >= 500) {
            return __('img.pro is busy right now. Try again in a minute.', 'bandwidth-saver');
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
     * The settings page calls this repeatedly while there is work, so the
     * scan and the copies pages asked for finish quickly even on sites
     * with little traffic.
     *
     * @return void
     */
    public function ajax_sync_step() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_sync_step'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        $result = [];
        if ($this->settings->is_connected()) {
            $result = $this->sync->run(self::STEP_BUDGET);
            $this->settings->clear_cache();
        }

        $payload = $this->status_payload();
        if (!empty($result['locked']) && isset($payload['status'])) {
            // Another request is syncing; ask the page to check back later
            $payload['status']['wait'] = max((int) $payload['status']['wait'], (int) $result['wait']);
        }

        wp_send_json_success($payload);
    }

    /**
     * Queue failed files again
     *
     * @return void
     */
    public function ajax_retry_failed() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action('imgpro_cdn_retry_failed'), 'nonce');
        ImgPro_CDN_Security::check_permission();

        if (ImgPro_CDN_Files::retry_failed((bool) $this->settings->get('remote')) > 0) {
            ImgPro_CDN_Sync::wake_for_new_files();
        }
        ImgPro_CDN_Sync::schedule_soon();

        wp_send_json_success($this->status_payload());
    }

    /**
     * Try again now: resume after a quota or App pause, or cut a back-off
     * wait short
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

        $usage  = ImgPro_CDN_Admin::get_usage($this->settings);
        $status = $this->sync->get_status();

        return [
            'connected' => true,
            'status'    => $status,
            'labels'    => ImgPro_CDN_Admin::status_labels($status),
            'images'    => is_array($usage) ? ImgPro_CDN_Admin::format_image_counts($usage) : null,
        ];
    }
}
