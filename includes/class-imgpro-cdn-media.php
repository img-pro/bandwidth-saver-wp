<?php
/**
 * ImgPro CDN Media Library Integration
 *
 * @package ImgPro_CDN
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * img.pro status and "Copy to img.pro" in the Media Library
 *
 * Images are copied the first time a page shows them. The Media Library
 * shows where each image is, and copies an image ahead of time when asked:
 * one that should load from img.pro from its first view, or one used
 * where Bandwidth Saver does not change URLs (theme CSS, social sharing
 * images), whose img.pro URL can then be copied.
 *
 * Built from core's own patterns: a list view column, a row action and a
 * bulk action that reload the page with a notice, like Trash, and a line
 * in the attachment details and on the edit screen.
 *
 * @since 2.0.0
 */
class ImgPro_CDN_Media {

    /**
     * Row and bulk action
     *
     * @var string
     */
    const COPY_ACTION = 'imgpro_copy';

    /**
     * AJAX action of the copy button in the attachment details
     *
     * @var string
     */
    const AJAX_ACTION = 'imgpro_cdn_copy_attachment';

    /**
     * Script and style handle
     *
     * @var string
     */
    const HANDLE = 'imgpro-cdn-media';

    /**
     * Transient that carries a row or bulk copy's result to the list it
     * returns to, per user
     *
     * @var string
     */
    const RESULT_TRANSIENT = 'imgpro_cdn_copy_result_';

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
     * Whether the current user gets the Media Library tools (see available())
     *
     * @var bool|null
     */
    private $available = null;

    /**
     * Attachments listed on this request whose status is not looked up yet
     *
     * @var bool[] attachment ID => true
     */
    private $listed = [];

    /**
     * Status summaries looked up on this request
     *
     * @var array attachment ID => summary (see ImgPro_CDN_Files::get_summaries())
     */
    private $summaries = [];

    /**
     * Constructor
     *
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @param ImgPro_CDN_Sync     $sync     Sync instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings, ImgPro_CDN_Sync $sync) {
        $this->settings = $settings;
        $this->sync     = $sync;
    }

    /**
     * Register hooks
     *
     * @return void
     */
    public function register_hooks() {
        // The media modal also opens outside wp-admin, in front-end editors
        add_action('wp_enqueue_media', [$this, 'enqueue_media']);

        if (!is_admin()) {
            return;
        }

        add_filter('manage_media_columns', [$this, 'add_column']);
        add_action('manage_media_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('media_row_actions', [$this, 'add_row_action'], 10, 2);
        add_filter('bulk_actions-upload', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'render_notices']);
        add_filter('the_posts', [$this, 'collect_listed'], 10, 2);
        add_filter('wp_prepare_attachment_for_js', [$this, 'add_details'], 10, 2);
        // After core's file details, which run at 10
        add_action('attachment_submitbox_misc_actions', [$this, 'render_submitbox'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('load-upload.php', [$this, 'add_help_tab']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_copy']);
    }

    /**
     * Whether the current user gets the Media Library tools
     *
     * Copying spends the App's image allowance, which the site's admins
     * manage, so it needs the plugin's capability. Nothing shows while no
     * key is connected or while the site's images are being deleted.
     *
     * @return bool
     */
    private function available() {
        if (null === $this->available) {
            $this->available = $this->settings->is_connected()
                && !$this->settings->get('removing')
                && ImgPro_CDN_Security::current_user_can();
        }
        return $this->available;
    }

    /**
     * Add the img.pro column before the Date column
     *
     * @param string[] $columns Columns.
     * @return string[]
     */
    public function add_column($columns) {
        if (!$this->available()) {
            return $columns;
        }

        $column   = ['imgpro' => _x('img.pro', 'column name', 'bandwidth-saver')];
        $position = array_search('date', array_keys($columns), true);
        if (false === $position) {
            return $columns + $column;
        }
        return array_slice($columns, 0, $position, true) + $column + array_slice($columns, $position, null, true);
    }

    /**
     * Print an attachment's img.pro status in the list
     *
     * @param string $column        Column ID.
     * @param int    $attachment_id Attachment ID.
     * @return void
     */
    public function render_column($column, $attachment_id) {
        if ('imgpro' !== $column) {
            return;
        }

        // A bold status over plain details, like the post title over its
        // links in Uploaded to
        $status = $this->describe((int) $attachment_id);
        echo '<strong>' . esc_html($status['label']) . '</strong>';
        foreach ($status['details'] as $line) {
            echo '<br />' . esc_html($line);
        }
    }

    /**
     * Add "Copy to img.pro" to an attachment's row actions
     *
     * A link that reloads the page, like Trash, handled with the bulk
     * action (see handle_bulk_action()).
     *
     * @param string[] $actions Row actions.
     * @param WP_Post  $post    Attachment.
     * @return string[]
     */
    public function add_row_action($actions, $post) {
        if (!$this->available() || !$this->can_copy($post)) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(
                [
                    // Grid mode would show the grid before handling the action
                    'mode'    => 'list',
                    'action'  => self::COPY_ACTION,
                    'media[]' => (int) $post->ID,
                ],
                admin_url('upload.php')
            ),
            'bulk-media'
        );

        $actions[self::COPY_ACTION] = sprintf(
            '<a href="%s" class="aria-button-if-js" aria-label="%s">%s</a>',
            esc_url($url),
            /* translators: %s: Attachment title. */
            esc_attr(sprintf(__('Copy &#8220;%s&#8221; to img.pro', 'bandwidth-saver'), _draft_or_post_title($post))),
            esc_html__('Copy to img.pro', 'bandwidth-saver')
        );
        return $actions;
    }

    /**
     * Add "Copy to img.pro" to the list's bulk actions
     *
     * @param string[] $actions Bulk actions.
     * @return string[]
     */
    public function add_bulk_action($actions) {
        // Core offers "untrash" only in the Trash, where nothing is copied
        if ($this->available() && !isset($actions['untrash'])) {
            $actions[self::COPY_ACTION] = __('Copy to img.pro', 'bandwidth-saver');
        }
        return $actions;
    }

    /**
     * Copy the selected attachments, then return to the list with the result
     *
     * Handles the bulk action and the row action. Core checked the
     * bulk-media nonce before calling this.
     *
     * @param string $location Where core redirects to next.
     * @param string $action   Bulk action.
     * @param int[]  $ids      Selected attachment IDs.
     * @return string
     */
    public function handle_bulk_action($location, $action, $ids) {
        if (self::COPY_ACTION !== $action) {
            return $location;
        }

        if (!ImgPro_CDN_Security::current_user_can()) {
            wp_die(esc_html__('Sorry, you are not allowed to copy media to img.pro.', 'bandwidth-saver'), 403);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        foreach ($ids as $id) {
            if (!current_user_can('edit_post', $id)) {
                wp_die(esc_html__('Sorry, you are not allowed to copy this item to img.pro.', 'bandwidth-saver'), 403);
            }
        }
        if (empty($ids)) {
            return $location;
        }

        $result = $this->copy($ids);
        if (is_wp_error($result)) {
            $notice = ['error' => $result->get_error_message()];
        } else {
            $notice = array_count_values($result['results']) + [
                'images' => $result['images'],
                // Copying may have stopped on a problem only the admin can solve
                'paused' => ImgPro_CDN_Settings::PAUSE_NONE !== (string) $this->settings->get('pause_reason'),
            ];
        }

        // Shown by render_result_notice() on the page core redirects to
        set_transient(self::RESULT_TRANSIENT . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        return $location;
    }

    /**
     * Copy attachments now
     *
     * Their files are queued ahead of the files pages asked for, and
     * uploaded for as long as one admin step may take; whatever is left
     * finishes in the background. Files already on img.pro are not
     * uploaded again, and failed files are tried again.
     *
     * @param int[] $ids Attachment IDs the user may edit.
     * @return array|WP_Error `results` maps each ID to copied, queued,
     *                        failed or unchanged (nothing to copy), and
     *                        `images` counts the App images their copies
     *                        use so far. An error when copying is blocked,
     *                        coded with the pause reason.
     */
    public function copy($ids) {
        $this->settings->refresh();
        if (!$this->settings->is_connected()) {
            return new WP_Error('not_connected', __('Nothing was copied, because img.pro is not connected.', 'bandwidth-saver'));
        }
        if ($this->settings->get('removing')) {
            return new WP_Error('removing', __('Nothing was copied, because this site\'s images are being deleted from img.pro.', 'bandwidth-saver'));
        }
        $pause = (string) $this->settings->get('pause_reason');
        if (ImgPro_CDN_Settings::PAUSE_QUOTA === $pause && ImgPro_CDN_Sync::has_room(ImgPro_CDN_Admin::get_usage($this->settings))) {
            // The App was upgraded, or deletions freed room, since copying
            // paused; the worker checks that only now and then
            $this->sync->resume();
            $this->settings->refresh();
            $pause = (string) $this->settings->get('pause_reason');
        }
        if (ImgPro_CDN_Settings::PAUSE_NONE !== $pause) {
            return new WP_Error($pause, self::blocked_message($pause, $this->settings));
        }

        $queued   = [];
        $unstored = [];
        foreach ($ids as $id) {
            $queued[$id] = 0;
            if (!wp_attachment_is_image($id) || 'trash' === get_post_status($id)) {
                continue;
            }
            // The library may still be being scanned, or the image was
            // uploaded a moment ago: make sure its files have rows. Files
            // that did not change are left as they are.
            ImgPro_CDN_Files::reconcile_attachment($id, null, $failed);
            if ($failed) {
                $unstored[$id] = true;
            }
            $queued[$id] = ImgPro_CDN_Files::prioritize(array_keys(ImgPro_CDN_Files::get_attachment_files($id)));
        }

        if (array_sum($queued) > 0) {
            ImgPro_CDN_Sync::wake_for_new_files();
            ImgPro_CDN_Sync::schedule_soon();
            $this->sync->run(ImgPro_CDN_Admin_Ajax::STEP_BUDGET, true);
            // The run may have paused (the App filled up, say)
            $this->settings->clear_cache();
        }

        $summaries = ImgPro_CDN_Files::get_summaries($ids);
        $results   = [];
        $images    = 0;
        foreach ($ids as $id) {
            $summary              = $summaries[$id];
            $this->summaries[$id] = $summary;

            if (0 === $queued[$id]) {
                // A file the database would not record is not "nothing to
                // copy": copying again records it
                $results[$id] = isset($unstored[$id]) ? 'failed' : 'unchanged';
                continue;
            }
            $images += $summary['copied'];

            if ($summary['failed']) {
                $results[$id] = 'failed';
            } elseif ($summary['queued'] || $summary['idle']) {
                $results[$id] = 'queued';
            } elseif ($summary['copied']) {
                $results[$id] = 'copied';
            } else {
                // Every file turned out to be one img.pro does not accept
                $results[$id] = 'failed';
            }
        }

        return ['results' => $results, 'images' => $images];
    }

    /**
     * Copy one attachment from its details or its edit screen
     *
     * @return void
     */
    public function ajax_copy() {
        check_ajax_referer(ImgPro_CDN_Security::get_nonce_action(self::AJAX_ACTION), 'nonce');
        ImgPro_CDN_Security::check_permission();

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $context       = (isset($_POST['context']) && 'edit' === sanitize_key(wp_unslash($_POST['context']))) ? 'edit' : 'modal';

        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id) || !current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => __('Sorry, you are not allowed to copy this item to img.pro.', 'bandwidth-saver')]);
        }
        ImgPro_CDN_Security::check_rate_limit('copy');

        $result = $this->copy([$attachment_id]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $state = $result['results'][$attachment_id];
        wp_send_json_success([
            'state'   => $state,
            'html'    => $this->get_block($attachment_id, $context),
            'message' => $this->result_message($attachment_id, $state),
        ]);
    }

    /**
     * Sentence announcing the result of copying one attachment
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $state         Result from copy().
     * @return string
     */
    private function result_message($attachment_id, $state) {
        $summary = $this->summary($attachment_id);

        switch ($state) {
            case 'copied':
                return __('Media file copied to img.pro.', 'bandwidth-saver');

            case 'queued':
                $message = __('Media file queued for copying to img.pro.', 'bandwidth-saver');
                // Copying may have stopped on a problem only the admin can solve
                $pause = (string) $this->settings->get('pause_reason');
                if (ImgPro_CDN_Settings::PAUSE_NONE !== $pause) {
                    $message .= ' ' . ImgPro_CDN_Admin::pause_text($pause, $this->settings, true);
                }
                return $message;

            case 'failed':
                $reason = '' !== $summary['failed_error'] ? $summary['failed_error'] : $summary['skipped_error'];
                return trim(__('Media file could not be copied to img.pro.', 'bandwidth-saver') . ' ' . $reason);
        }

        if (self::is_image_type($attachment_id, $summary) && 0 === $summary['total']) {
            return __('WordPress has no file recorded for this image in the uploads folder.', 'bandwidth-saver');
        }
        return __('Nothing to copy for this media file.', 'bandwidth-saver');
    }

    /**
     * Why nothing can be copied right now, as a notice sentence
     *
     * @param string              $pause    PAUSE_* constant.
     * @param ImgPro_CDN_Settings $settings Settings instance.
     * @return string
     */
    private static function blocked_message($pause, $settings) {
        return trim(__('Nothing was copied.', 'bandwidth-saver') . ' ' . ImgPro_CDN_Admin::pause_text($pause, $settings, true));
    }

    /**
     * Notices on the Media Library and the attachment edit screen
     *
     * @return void
     */
    public function render_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['upload', 'attachment'], true) || !ImgPro_CDN_Security::current_user_can()) {
            return;
        }

        $error_shown = 'upload' === $screen->id && $this->render_result_notice();
        if (!$error_shown && $this->available()) {
            $this->render_pause_notice();
        }
    }

    /**
     * Result of a row or bulk copy, after the redirect
     *
     * @return bool Whether the notice said copying is blocked.
     */
    private function render_result_notice() {
        $key    = self::RESULT_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return false;
        }
        delete_transient($key);

        if (isset($notice['error'])) {
            $this->print_notice('error', esc_html((string) $notice['error']) . ' ' . $this->settings_link());
            return true;
        }

        $copied    = (int) ($notice['copied'] ?? 0);
        $queued    = (int) ($notice['queued'] ?? 0);
        $failed    = (int) ($notice['failed'] ?? 0);
        $unchanged = (int) ($notice['unchanged'] ?? 0);
        $images    = (int) ($notice['images'] ?? 0);
        $total     = $copied + $queued + $failed + $unchanged;
        if (0 === $total) {
            return false;
        }

        $why       = __('The img.pro column shows why.', 'bandwidth-saver');
        $sentences = [];
        if (1 === $total) {
            if ($copied) {
                $sentences[] = __('Media file copied to img.pro.', 'bandwidth-saver');
            } elseif ($queued) {
                $sentences[] = __('Media file queued for copying to img.pro.', 'bandwidth-saver');
            } elseif ($failed) {
                $sentences[] = __('Media file could not be copied to img.pro.', 'bandwidth-saver') . ' ' . $why;
            } else {
                $sentences[] = __('Nothing to copy for this media file.', 'bandwidth-saver') . ' ' . $why;
            }
        } else {
            if ($copied) {
                /* translators: %s: Number of media files. */
                $sentences[] = sprintf(_n('%s media file copied to img.pro.', '%s media files copied to img.pro.', $copied, 'bandwidth-saver'), number_format_i18n($copied));
            }
            if ($queued) {
                /* translators: %s: Number of media files. */
                $sentences[] = sprintf(_n('%s media file queued for copying to img.pro.', '%s media files queued for copying to img.pro.', $queued, 'bandwidth-saver'), number_format_i18n($queued));
            }
            if ($failed) {
                /* translators: %s: Number of media files. */
                $sentences[] = sprintf(_n('%s media file could not be copied to img.pro.', '%s media files could not be copied to img.pro.', $failed, 'bandwidth-saver'), number_format_i18n($failed)) . ' ' . $why;
            }
            if ($unchanged) {
                /* translators: %s: Number of media files. */
                $sentences[] = sprintf(_n('%s media file had nothing to copy.', '%s media files had nothing to copy.', $unchanged, 'bandwidth-saver'), number_format_i18n($unchanged)) . ' ' . $why;
            }
        }
        if ($images && 1 === $total) {
            /* translators: %s: Number of images. */
            $sentences[] = sprintf(_n('Its copy uses %s image in your App.', 'Its copies use %s images in your App.', $images, 'bandwidth-saver'), number_format_i18n($images));
        } elseif ($images) {
            /* translators: %s: Number of images. */
            $sentences[] = sprintf(_n('Their copy uses %s image in your App.', 'Their copies use %s images in your App.', $images, 'bandwidth-saver'), number_format_i18n($images));
        }

        if ($failed === $total) {
            $type = 'error';
        } elseif ($failed || ($queued && !empty($notice['paused']))) {
            $type = 'warning';
        } elseif ($unchanged === $total) {
            $type = 'info';
        } else {
            $type = 'success';
        }
        $this->print_notice($type, esc_html(implode(' ', $sentences)));
        return false;
    }

    /**
     * Say why nothing is being copied, when sync is paused
     *
     * @return void
     */
    private function render_pause_notice() {
        $pause = (string) $this->settings->get('pause_reason');
        if (ImgPro_CDN_Settings::PAUSE_NONE === $pause) {
            return;
        }
        // The App was upgraded, or deletions freed room, since copying paused
        if (ImgPro_CDN_Settings::PAUSE_QUOTA === $pause && ImgPro_CDN_Sync::has_room(ImgPro_CDN_Admin::get_usage($this->settings))) {
            $this->sync->resume();
            return;
        }

        $message = ImgPro_CDN_Admin::pause_text($pause, $this->settings, true);
        if ('' !== $message) {
            $this->print_notice('warning', esc_html($message) . ' ' . $this->settings_link());
        }
    }

    /**
     * Print a dismissible notice
     *
     * Written out rather than through wp_admin_notice(), which needs
     * WordPress 6.4.
     *
     * @param string $type    success, info, warning or error.
     * @param string $message Escaped HTML.
     * @return void
     */
    private function print_notice($type, $message) {
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            wp_kses($message, ['a' => ['href' => []]])
        );
    }

    /**
     * Link to the settings page
     *
     * @return string HTML.
     */
    private function settings_link() {
        return sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . ImgPro_CDN_Admin::PAGE_SLUG)),
            esc_html__('Open Bandwidth Saver settings', 'bandwidth-saver')
        );
    }

    /**
     * Note the attachments a Media Library query lists
     *
     * Their status is then looked up together, the first time one is
     * shown, instead of once per attachment. Metadata, which the lookup
     * needs, is primed after this filter.
     *
     * @param WP_Post[] $posts Posts.
     * @param WP_Query  $query Query.
     * @return WP_Post[]
     */
    public function collect_listed($posts, $query) {
        if ($query instanceof WP_Query && in_array('attachment', (array) $query->get('post_type'), true)) {
            foreach ((array) $posts as $post) {
                if ($post instanceof WP_Post) {
                    $this->listed[$post->ID] = true;
                }
            }
        }
        return $posts;
    }

    /**
     * Add the img.pro line to an attachment's details in the media modal
     *
     * Core prints compat meta as is, at the end of the details.
     *
     * @param array   $response   Attachment data for JavaScript.
     * @param WP_Post $attachment Attachment.
     * @return array
     */
    public function add_details($response, $attachment) {
        if (!is_array($response) || !isset($response['compat']) || !is_array($response['compat']) || !$this->available()) {
            return $response;
        }

        $response['compat']['meta'] = ($response['compat']['meta'] ?? '') . $this->get_block((int) $attachment->ID, 'modal');
        return $response;
    }

    /**
     * Add the img.pro line to the Save box of the attachment edit screen
     *
     * @param WP_Post $post Attachment.
     * @return void
     */
    public function render_submitbox($post) {
        if ($post instanceof WP_Post && $this->available()) {
            $this->print_block((int) $post->ID, 'edit');
        }
    }

    /**
     * The img.pro block as HTML
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $context       'modal' or 'edit'.
     * @return string
     */
    private function get_block($attachment_id, $context) {
        ob_start();
        $this->print_block($attachment_id, $context);
        return (string) ob_get_clean();
    }

    /**
     * Print an attachment's img.pro status, with its buttons
     *
     * The URL button uses core's clipboard code: the media modal handles
     * .copy-attachment-url, and the edit screen .copy-attachment-url.edit-media.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $context       'modal' for the attachment details, 'edit'
     *                              for the edit screen's Save box.
     * @return void
     */
    private function print_block($attachment_id, $context) {
        $status  = $this->describe($attachment_id);
        $summary = $this->summary($attachment_id);
        $edit    = 'edit' === $context;
        $copy    = $this->can_copy(get_post($attachment_id));
        // Sized like core's own URL button in each place
        $button  = $edit ? 'button' : 'button button-small';

        printf(
            '<div class="%s" data-id="%d" data-context="%s">',
            $edit ? 'misc-pub-section misc-pub-imgpro imgpro-media' : 'imgpro-media',
            (int) $attachment_id,
            $edit ? 'edit' : 'modal'
        );

        // Each context follows its neighbours: "Label: <strong>value</strong>"
        // in the Save box, "<strong>Label:</strong> value" in the details
        if ($edit) {
            echo '<span class="imgpro-media-status">' . esc_html__('img.pro:', 'bandwidth-saver') . ' <strong>' . esc_html($status['label']) . '</strong></span>';
            foreach ($status['details'] as $line) {
                echo '<br />' . esc_html($line);
            }
        } else {
            echo '<div class="imgpro-media-status"><strong>' . esc_html__('img.pro:', 'bandwidth-saver') . '</strong> ' . esc_html($status['label']) . '</div>';
            foreach ($status['details'] as $line) {
                echo '<div>' . esc_html($line) . '</div>';
            }
        }

        if ($copy || '' !== $summary['full_url']) {
            echo '<div class="imgpro-media-actions">';
            if ($copy) {
                printf(
                    '<button type="button" class="%s imgpro-copy" aria-label="%s">%s</button>',
                    esc_attr($button),
                    /* translators: %s: Attachment title. */
                    esc_attr(sprintf(__('Copy &#8220;%s&#8221; to img.pro', 'bandwidth-saver'), _draft_or_post_title($attachment_id))),
                    esc_html__('Copy to img.pro', 'bandwidth-saver')
                );
            }
            if ('' !== $summary['full_url']) {
                printf(
                    '<span class="copy-to-clipboard-container"><button type="button" class="%s copy-attachment-url%s" data-clipboard-text="%s">%s</button><span class="success hidden" aria-hidden="true">%s</span></span>',
                    esc_attr($button),
                    $edit ? ' edit-media' : '',
                    esc_url($summary['full_url']),
                    esc_html__('Copy img.pro URL', 'bandwidth-saver'),
                    esc_html__('Copied!', 'bandwidth-saver')
                );
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Whether the user can copy an attachment, and it has anything to copy
     *
     * @param WP_Post|null $post Attachment.
     * @return bool
     */
    private function can_copy($post) {
        if (!$post || 'trash' === $post->post_status || !current_user_can('edit_post', $post->ID)) {
            return false;
        }
        return $this->describe((int) $post->ID)['can_copy'];
    }

    /**
     * What to say about an attachment's files
     *
     * The first matching state wins: a failure, then files in the queue,
     * then copies, then files waiting for a page. Copying some sizes and
     * not others is normal, since pages only ask for the sizes they show.
     *
     * @param int $attachment_id Attachment ID.
     * @return array{label: string, details: string[], can_copy: bool}
     */
    private function describe($attachment_id) {
        $summary = $this->summary($attachment_id);
        $stays   = __('Stays on your server', 'bandwidth-saver');

        // "Stays on your server" is kept for files the settings page counts
        // under that name. Documents, video and audio, and images whose
        // files are not in the uploads folder, have no files there to count.
        if (!self::is_image_type($attachment_id, $summary)) {
            return ['label' => __('Not an image', 'bandwidth-saver'), 'details' => [__('Bandwidth Saver copies images only.', 'bandwidth-saver')], 'can_copy' => false];
        }
        if (0 === $summary['total']) {
            return ['label' => __('Files not found', 'bandwidth-saver'), 'details' => [__('WordPress has no file recorded for this image in the uploads folder.', 'bandwidth-saver')], 'can_copy' => false];
        }

        $details = [];
        /* translators: 1: Number of sizes copied to img.pro, 2: Number of sizes of the image. */
        $copied_of = sprintf(_n('%1$s of %2$s size copied', '%1$s of %2$s sizes copied', $summary['total'], 'bandwidth-saver'), number_format_i18n($summary['copied']), number_format_i18n($summary['total']));

        if ($summary['failed']) {
            $label     = __('Copy failed', 'bandwidth-saver');
            $details[] = $summary['failed_error'];
            if ($summary['copied']) {
                $details[] = $copied_of;
            }
        } elseif ($summary['queued']) {
            $label = __('Queued', 'bandwidth-saver');
            if ($summary['copied']) {
                $details[] = $copied_of;
            }
        } elseif ($summary['copied']) {
            $label = __('Copied', 'bandwidth-saver');
            if ($summary['copied'] < $summary['total']) {
                /* translators: 1: Number of sizes copied to img.pro, 2: Number of sizes of the image. */
                $details[] = sprintf(_n('%1$s of %2$s size', '%1$s of %2$s sizes', $summary['total'], 'bandwidth-saver'), number_format_i18n($summary['copied']), number_format_i18n($summary['total']));
            }
        } elseif ($summary['idle']) {
            $label = __('Not copied yet', 'bandwidth-saver');
            // Pages ask for copies only while they are served from img.pro
            if ($this->settings->is_serving()) {
                $details[] = __('Copied when a page shows it.', 'bandwidth-saver');
            }
        } else {
            return ['label' => $stays, 'details' => array_filter([$summary['skipped_error']], 'strlen'), 'can_copy' => false];
        }

        if ($summary['skipped']) {
            $details[] = trim(
                /* translators: %s: Number of sizes. */
                sprintf(_n('%s size stays on your server.', '%s sizes stay on your server.', $summary['skipped'], 'bandwidth-saver'), number_format_i18n($summary['skipped']))
                . ' ' . $summary['skipped_error']
            );
        }

        return [
            'label'    => $label,
            'details'  => array_values(array_filter($details, 'strlen')),
            'can_copy' => ($summary['idle'] + $summary['queued'] + $summary['failed']) > 0,
        ];
    }

    /**
     * Whether an attachment is an image, even one without its files
     *
     * Core does not count an image without a recorded file as an image, so
     * its type decides: it is an image whose files were not found.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $summary       Its summary (see summary()).
     * @return bool
     */
    private static function is_image_type($attachment_id, $summary) {
        return $summary['is_image'] || 0 === strpos((string) get_post_mime_type($attachment_id), 'image/');
    }

    /**
     * Status summary of an attachment
     *
     * The first lookup also covers every attachment listed so far.
     *
     * @param int $attachment_id Attachment ID.
     * @return array See ImgPro_CDN_Files::get_summaries().
     */
    private function summary($attachment_id) {
        if (!isset($this->summaries[$attachment_id])) {
            $ids             = array_keys(array_diff_key($this->listed + [$attachment_id => true], $this->summaries));
            $this->summaries = ImgPro_CDN_Files::get_summaries($ids) + $this->summaries;
            $this->listed    = [];
        }
        return $this->summaries[$attachment_id];
    }

    /**
     * Load the copy button's script with the media modal
     *
     * @return void
     */
    public function enqueue_media() {
        if ($this->available()) {
            $this->enqueue_script();
        }
    }

    /**
     * Load the column style in the Media Library and the script on the
     * attachment edit screen
     *
     * @param string $hook Current admin page.
     * @return void
     */
    public function enqueue_assets($hook) {
        if (!$this->available()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ('upload.php' === $hook) {
            $this->enqueue_style();
        } elseif ($screen && 'attachment' === $screen->id) {
            $this->enqueue_script();
        }
    }

    /**
     * Load the copy button's script and style
     *
     * @return void
     */
    private function enqueue_script() {
        if (wp_script_is(self::HANDLE, 'enqueued')) {
            return;
        }

        $this->enqueue_style();
        wp_enqueue_script(
            self::HANDLE,
            IMGPRO_CDN_PLUGIN_URL . 'admin/js/imgpro-cdn-media.js',
            ['jquery', 'wp-util', 'wp-a11y'],
            IMGPRO_CDN_VERSION,
            true
        );
        wp_localize_script(self::HANDLE, 'imgproCdnMedia', [
            'action' => self::AJAX_ACTION,
            'nonce'  => ImgPro_CDN_Security::create_nonce(self::AJAX_ACTION),
            'i18n'   => [
                'copying' => __('Copying…', 'bandwidth-saver'),
                'error'   => __('Something went wrong. Please try again.', 'bandwidth-saver'),
                'expired' => __('Your session has expired. Reload this page to continue.', 'bandwidth-saver'),
            ],
        ]);
    }

    /**
     * Load the few rules core's styles do not cover
     *
     * @return void
     */
    private function enqueue_style() {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            wp_register_style(self::HANDLE, false, [], IMGPRO_CDN_VERSION);
            wp_add_inline_style(
                self::HANDLE,
                '.fixed .column-imgpro{width:15%}'
                . '.imgpro-media-actions{margin-top:4px}'
                . '.imgpro-media-actions .button{margin:0 4px 4px 0}'
                // Core lines its own URL button up with the fields below it
                . '.imgpro-media .imgpro-media-actions .copy-to-clipboard-container{display:inline-flex;margin:0;padding:0;vertical-align:top}'
            );
        }
        wp_enqueue_style(self::HANDLE);
    }

    /**
     * Explain the img.pro column and Copy to img.pro in the Media Library's Help
     *
     * @return void
     */
    public function add_help_tab() {
        if (!$this->available()) {
            return;
        }

        $content  = '<p>' . esc_html__('Bandwidth Saver copies an image to your img.pro App the first time a page shows it, and from then on pages load it from img.pro. Images no page shows are not copied, and use none of your App\'s images, until you copy them.', 'bandwidth-saver') . '</p>';
        if (!$this->settings->is_serving()) {
            $content .= '<p>' . esc_html__('Serve images is off on the Bandwidth Saver settings page, so pages load every image from its original address and ask for no new copies. Copy to img.pro still works.', 'bandwidth-saver') . '</p>';
        }
        $content .= '<p>' . esc_html__('The img.pro column in list view, and the img.pro line in an image\'s details, show where each image is:', 'bandwidth-saver') . '</p>';
        $content .= '<ul>';
        $content .= '<li>' . wp_kses(__('<strong>Copied</strong> means your App has a copy of the image. While Serve images is on, pages load it from img.pro, and sizes not copied yet are copied when a page shows them.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Queued</strong> means the image is waiting to be copied. It loads from your server until then.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Not copied yet</strong> means your App has no copy of the image yet. While Serve images is on, it is copied the first time a page shows it.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Copy failed</strong> means img.pro did not take the image. The reason is shown under the status.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Stays on your server</strong> means img.pro does not accept the file (for example because of its format or size), the file WordPress recorded is missing from the uploads folder, or img.pro blocked it. The reason is shown under the status.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Not an image</strong> means a document, video, audio or other file. These always load from your server.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '<li>' . wp_kses(__('<strong>Files not found</strong> means WordPress has no file recorded for the image in the uploads folder, so Bandwidth Saver cannot copy it. The image loads from its original address.', 'bandwidth-saver'), ['strong' => []]) . '</li>';
        $content .= '</ul>';
        $content .= '<p>' . wp_kses(__('<strong>Copy to img.pro</strong> copies every size of an image now instead of waiting for a page. Each size counts as one image in your App. To copy several images, select them in list view and choose Copy to img.pro from Bulk actions.', 'bandwidth-saver'), ['strong' => []]) . '</p>';
        $content .= '<p>' . wp_kses(__('Bandwidth Saver changes images in post content, widgets, featured images and images output with wp_get_attachment_image(). For an image whose URL a theme or plugin reads directly, such as theme CSS or a social sharing image, copy it and use <strong>Copy img.pro URL</strong> in its details.', 'bandwidth-saver'), ['strong' => []]) . '</p>';

        get_current_screen()->add_help_tab([
            'id'       => 'imgpro-cdn',
            'title'    => __('img.pro', 'bandwidth-saver'),
            'content'  => $content,
            // After core's own tabs
            'priority' => 20,
        ]);
    }
}
