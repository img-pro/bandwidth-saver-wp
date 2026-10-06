<?php
/**
 * ImgPro CDN Media Sync
 *
 * @package ImgPro_CDN
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copies media library files to img.pro and keeps them in step
 *
 * New uploads, edits and regenerated thumbnails are queued through the
 * attachment metadata filter; deleted attachments queue their copies for
 * deletion. A worker, run by WP-Cron and by the admin page while it is
 * open, works through the queue in time-boxed batches:
 *
 * 1. delete copies of files that are gone
 * 2. scan the existing library once after connecting (backfill)
 * 3. match files already on img.pro from an earlier connection (adopt)
 * 4. upload pending files
 *
 * @since 2.0.0
 */
class ImgPro_CDN_Sync {

    /**
     * Cron hook that runs the worker
     *
     * @var string
     */
    const CRON_HOOK = 'imgpro_cdn_sync';

    /**
     * Option holding worker state
     *
     * @var string
     */
    const STATE_OPTION = 'imgpro_cdn_sync_state';

    /**
     * Option used as a worker lock
     *
     * @var string
     */
    const LOCK_OPTION = 'imgpro_cdn_sync_lock';

    /**
     * Seconds after which a lock is considered abandoned
     *
     * @var int
     */
    const LOCK_TTL = 120;

    /**
     * Attachments scanned per backfill query
     *
     * @var int
     */
    const BACKFILL_BATCH = 100;

    /**
     * Upload attempts before a file is marked failed
     *
     * @var int
     */
    const MAX_ATTEMPTS = 5;

    /**
     * Option holding how many images the last removal could not delete
     *
     * @var string
     */
    const LEFTOVER_OPTION = 'imgpro_cdn_removal_leftover';

    /**
     * Settings instance
     *
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Attachments whose metadata changed during this request
     *
     * @var array
     */
    private $dirty = [];

    /**
     * Whether this run added or deleted images on img.pro
     *
     * @var bool
     */
    private $changed_remote = false;

    /**
     * Constructor
     *
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Register hooks
     *
     * @return void
     */
    public function register_hooks() {
        add_filter('wp_update_attachment_metadata', [$this, 'on_metadata_update'], 99, 2);
        add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1);
        add_filter('wp_delete_file', [$this, 'on_delete_file'], PHP_INT_MAX);
        add_action('shutdown', [$this, 'reconcile_dirty']);
        add_action(self::CRON_HOOK, [$this, 'run_cron']);
        add_action(self::CRON_HOOK . '_hourly', [$this, 'run_cron']);
    }

    /**
     * Note an attachment whose files may have changed
     *
     * WordPress saves metadata several times while it creates sizes, so
     * the attachment is reconciled once, at the end of the request.
     *
     * @param array $data          Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array Unchanged metadata.
     */
    public function on_metadata_update($data, $attachment_id) {
        if ($this->settings->is_connected() && !$this->settings->get('removing')) {
            $this->dirty[(int) $attachment_id] = true;
        }
        return $data;
    }

    /**
     * Reconcile attachments changed during this request
     *
     * @return void
     */
    public function reconcile_dirty() {
        if (empty($this->dirty)) {
            return;
        }

        $queued = 0;
        foreach (array_keys($this->dirty) as $attachment_id) {
            $queued += ImgPro_CDN_Files::reconcile_attachment($attachment_id);
        }
        $this->dirty = [];

        if ($queued > 0) {
            self::schedule_soon();
        }
    }

    /**
     * Stop tracking metadata changes of an attachment being deleted
     *
     * Its img.pro copies are queued for deletion file by file, as
     * WordPress deletes each file (see on_delete_file()).
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public function on_delete_attachment($attachment_id) {
        unset($this->dirty[(int) $attachment_id]);
    }

    /**
     * Queue the img.pro copy of a file WordPress is deleting
     *
     * Runs last on the wp_delete_file filter, so files another plugin
     * keeps (media translations share files) keep their copies too.
     *
     * @param string $file Absolute path, or empty when deletion was cancelled.
     * @return string Unchanged path.
     */
    public function on_delete_file($file) {
        if (!is_string($file) || '' === $file || !$this->settings->is_connected()) {
            return $file;
        }

        $basedir = ImgPro_CDN_Files::get_basedir() . '/';
        $path    = wp_normalize_path($file);
        if (0 !== strpos($path, $basedir)) {
            return $file;
        }

        if (ImgPro_CDN_Files::retire_file(substr($path, strlen($basedir)))) {
            self::schedule_soon();
        }

        return $file;
    }

    /**
     * Cron entry point
     *
     * @return void
     */
    public function run_cron() {
        $result = $this->run(20);

        if (!empty($result['more'])) {
            self::schedule_soon(!empty($result['wait']) ? (int) $result['wait'] : 15);
        }
    }

    /**
     * Run the worker for up to a time budget
     *
     * @param int $budget Seconds to spend.
     * @return array Summary: `more` when work remains, `wait` seconds to wait
     *               before the next run, `locked` when another run is active.
     */
    public function run($budget) {
        if (!$this->settings->is_connected()) {
            return ['more' => false];
        }

        if (!$this->acquire_lock()) {
            return ['more' => true, 'locked' => true, 'wait' => 10];
        }

        // Uploads hold a whole file in memory; ask for the admin memory limit
        wp_raise_memory_limit('imgpro_cdn');

        $max_execution = (int) ini_get('max_execution_time');
        if ($max_execution > 0) {
            $budget = min($budget, max(5, $max_execution - 10));
        }
        $deadline = microtime(true) + $budget;

        try {
            $api   = new ImgPro_CDN_API($this->settings->get_api_key());
            $state = self::get_state();

            if ((int) $state['wait_until'] > time()) {
                return ['more' => true, 'wait' => (int) $state['wait_until'] - time()];
            }

            if (ImgPro_CDN_Settings::PAUSE_AUTH === $this->settings->get('pause_reason')) {
                return ['more' => false];
            }

            if ($this->settings->get('removing')) {
                return $this->remove_step($api, $deadline);
            }

            if (!$this->delete_step($api, $deadline)) {
                return $this->stopped_result();
            }

            if (!$this->settings->can_sync()) {
                return ['more' => false];
            }

            if (empty($state['backfill_done'])) {
                $this->backfill_step($deadline);
                $state = self::get_state();
                if (empty($state['backfill_done'])) {
                    return ['more' => true];
                }
            }

            if (empty($state['adopt_done'])) {
                if (!$this->adopt_step($api, $deadline)) {
                    return $this->stopped_result();
                }
                $state = self::get_state();
                if (empty($state['adopt_done'])) {
                    return ['more' => true];
                }
            }

            if (!$this->upload_step($api, $deadline)) {
                return $this->stopped_result();
            }

            $state = self::get_state();
            if ('' !== $state['last_error']) {
                $state['last_error'] = '';
                self::save_state($state);
            }

            $counts = ImgPro_CDN_Files::get_counts();
            return ['more' => ($counts[ImgPro_CDN_Files::STATUS_PENDING] + $counts[ImgPro_CDN_Files::STATUS_DELETE]) > 0];
        } finally {
            $this->release_lock();

            // The App's image count changed, so the cached usage is stale
            if ($this->changed_remote) {
                delete_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT);
                $this->changed_remote = false;
            }
        }
    }

    /**
     * Result after a step stopped early because of an API error
     *
     * @return array
     */
    private function stopped_result() {
        $state = self::get_state();
        $wait  = max(0, (int) $state['wait_until'] - time());

        if ($wait > 0) {
            return ['more' => true, 'wait' => $wait];
        }

        // Paused (quota, key or App problems) needs the user, not a retry
        return ['more' => $this->settings->can_sync(), 'wait' => 60];
    }

    /**
     * Delete img.pro copies of files that are gone
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error stopped the step.
     */
    private function delete_step($api, $deadline) {
        while (microtime(true) < $deadline) {
            $rows = ImgPro_CDN_Files::get_deletions(100);
            if (empty($rows)) {
                return true;
            }

            $by_imgpro_id = [];
            foreach ($rows as $row) {
                $by_imgpro_id[$row->imgpro_id][] = $row;
            }

            $result = $api->delete_images(array_keys($by_imgpro_id));
            if (is_wp_error($result)) {
                $this->handle_error($result);
                return false;
            }

            // Ids that failed for a reason other than "already gone" stay queued
            $retry = [];
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $code = $error['error']['code'] ?? '';
                if (!empty($error['id']) && 'not_found' !== $code) {
                    $retry[(string) $error['id']] = $error['error']['message'] ?? $code;
                }
            }

            $done = [];
            foreach ($by_imgpro_id as $imgpro_id => $id_rows) {
                foreach ($id_rows as $row) {
                    if (isset($retry[(string) $imgpro_id]) && (int) $row->attempts + 1 < self::MAX_ATTEMPTS) {
                        ImgPro_CDN_Files::mark_attempt_failed($row, $retry[(string) $imgpro_id], false, self::MAX_ATTEMPTS);
                    } else {
                        $done[] = (int) $row->id;
                    }
                }
            }
            ImgPro_CDN_Files::delete_rows($done);
            $this->changed_remote = $this->changed_remote || !empty($done);

            if (!empty($retry)) {
                return true;
            }
        }

        return true;
    }

    /**
     * Queue the existing media library, a batch of attachments at a time
     *
     * @param float $deadline Unix time to stop at.
     * @return void
     */
    private function backfill_step($deadline) {
        global $wpdb;

        $state = self::get_state();

        while (microtime(true) < $deadline) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                    'image/%',
                    (int) $state['backfill_cursor'],
                    self::BACKFILL_BATCH
                )
            );

            foreach ($ids as $attachment_id) {
                ImgPro_CDN_Files::reconcile_attachment((int) $attachment_id);
                $state['backfill_cursor'] = (int) $attachment_id;
            }

            if (count($ids) < self::BACKFILL_BATCH) {
                ImgPro_CDN_Files::retire_orphans();
                $state['backfill_done'] = true;
                break;
            }
        }

        self::save_state($state);
    }

    /**
     * Match files already on img.pro to pending rows
     *
     * After reconnecting, or reinstalling the plugin, this site's earlier
     * uploads are still in the App. Pending files whose earlier copy has
     * the same size are marked synced instead of being uploaded again;
     * extra copies of a file are queued for deletion.
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error stopped the step.
     */
    private function adopt_step($api, $deadline) {
        $state = self::get_state();
        $site  = ImgPro_CDN_Settings::get_site_label();

        while (microtime(true) < $deadline) {
            $page = $api->list_images(['site' => $site], 100, $state['adopt_cursor'] ? $state['adopt_cursor'] : null);
            if (is_wp_error($page)) {
                self::save_state($state);
                $this->handle_error($page);
                return false;
            }

            foreach ((array) ($page['data'] ?? []) as $image) {
                $this->adopt_image($image);
            }

            $cursor = $page['pagination']['next_cursor'] ?? null;
            if (empty($page['pagination']['has_more']) || empty($cursor)) {
                $state['adopt_done']   = true;
                $state['adopt_cursor'] = '';
                break;
            }
            $state['adopt_cursor'] = (string) $cursor;
        }

        self::save_state($state);
        return true;
    }

    /**
     * Adopt one image found on img.pro
     *
     * @param array $image Image object.
     * @return void
     */
    private function adopt_image($image) {
        global $wpdb;

        $file = isset($image['metadata']['wp_file']) ? (string) $image['metadata']['wp_file'] : '';
        if ('' === $file || empty($image['id']) || empty($image['url'])) {
            return;
        }

        $row = ImgPro_CDN_Files::get_by_file($file);
        if (!$row) {
            // Not part of the library any more; leave it for the user to manage
            return;
        }

        $same_bytes = isset($image['metadata']['wp_bytes']) && (string) $row->file_bytes === (string) $image['metadata']['wp_bytes'];

        if (ImgPro_CDN_Files::STATUS_PENDING === $row->status && $same_bytes) {
            ImgPro_CDN_Files::mark_synced($row, $image['id'], $image['url']);
            return;
        }

        if ($row->imgpro_id === $image['id']) {
            return;
        }

        // A stale or duplicate copy of this file
        $wpdb->insert(ImgPro_CDN_Files::table(), [
            'attachment_id' => (int) $row->attachment_id,
            'file'          => $file,
            'file_hash'     => null,
            'status'        => ImgPro_CDN_Files::STATUS_DELETE,
            'imgpro_id'     => substr((string) $image['id'], 0, 64),
            'updated_at'    => current_time('mysql', true),
        ]);
    }

    /**
     * Upload pending files
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error stopped the step.
     */
    private function upload_step($api, $deadline) {
        $state   = self::get_state();
        $basedir = ImgPro_CDN_Files::get_basedir();
        $site    = ImgPro_CDN_Settings::get_site_label();

        while (microtime(true) < $deadline) {
            $rows = ImgPro_CDN_Files::get_pending(10);
            if (empty($rows)) {
                return true;
            }

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    return true;
                }

                // The admin may have paused, disconnected or started removal
                // while this run was busy
                $this->settings->refresh();
                if (!$this->settings->can_sync()) {
                    return true;
                }

                $path = $basedir . '/' . $row->file;
                if (!file_exists($path)) {
                    ImgPro_CDN_Files::mark_skipped($row, __('The file is missing from the uploads folder.', 'bandwidth-saver'));
                    continue;
                }

                // Every earlier attempt was cut off before it could fail cleanly
                if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                    ImgPro_CDN_Files::mark_attempt_failed($row, __('The upload kept stopping before it finished. The file may be too large for this server\'s memory or time limits.', 'bandwidth-saver'), true);
                    continue;
                }

                // Keep the idempotency key and metadata in step with the file
                clearstatcache(true, $path);
                $bytes = (int) filesize($path);
                $mtime = (int) filemtime($path);
                if ($bytes !== (int) $row->file_bytes || $mtime !== (int) $row->file_mtime) {
                    ImgPro_CDN_Files::update_file_stats($row, $bytes, $mtime);
                }
                if ($bytes > ImgPro_CDN_API::MAX_FILE_BYTES) {
                    ImgPro_CDN_Files::mark_skipped($row, __('The file is larger than the 20 MB img.pro accepts.', 'bandwidth-saver'));
                    continue;
                }
                if (!self::has_memory_for($bytes)) {
                    ImgPro_CDN_Files::mark_attempt_failed($row, __('This server\'s PHP memory limit is too low to upload this file.', 'bandwidth-saver'), true);
                    continue;
                }

                $labels = [
                    'site'    => $site,
                    'wp_id'   => (string) $row->attachment_id,
                    'wp_size' => (string) $row->size_name,
                ];
                $metadata = [
                    'wp_file'  => (string) $row->file,
                    'wp_bytes' => (string) $row->file_bytes,
                ];
                $key = 'bs-' . md5($state['generation'] . '|' . $row->id . '|' . $row->file . '|' . $row->file_bytes . '|' . $row->file_mtime);

                ImgPro_CDN_Files::note_attempt($row);
                $image = $api->upload_file($path, $labels, $metadata, $key);

                if (is_wp_error($image)) {
                    if (!$this->handle_upload_error($row, $image)) {
                        return false;
                    }
                    continue;
                }

                if (empty($image['id']) || empty($image['url'])) {
                    ImgPro_CDN_Files::mark_attempt_failed($row, __('img.pro returned an unexpected response.', 'bandwidth-saver'), false, self::MAX_ATTEMPTS);
                    continue;
                }

                ImgPro_CDN_Files::mark_synced($row, $image['id'], $image['url']);
                $this->changed_remote = true;
            }
        }

        return true;
    }

    /**
     * Whether this request has enough memory left to upload a file
     *
     * The multipart body holds the whole file, and the HTTP layer copies
     * it again, so allow about three times the file size.
     *
     * @param int $bytes File size.
     * @return bool
     */
    private static function has_memory_for($bytes) {
        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true;
        }
        return ($limit - memory_get_usage(true)) > ($bytes * 3 + 8 * MB_IN_BYTES);
    }

    /**
     * Handle a failed upload
     *
     * The attempt was already counted by note_attempt(); failures that are
     * not the file's fault give it back.
     *
     * @param object   $row   Table row.
     * @param WP_Error $error API error.
     * @return bool True to continue with the next file, false to stop.
     */
    private function handle_upload_error($row, $error) {
        $data   = (array) $error->get_error_data();
        $status = (int) ($data['status'] ?? 0);
        $code   = $error->get_error_code();

        // The body changed since an earlier attempt with this key (the file
        // was rewritten in place): queue it again under a new key
        if (in_array($code, ['idempotency_key_conflict', 'idempotency_recovery_conflict'], true)) {
            ImgPro_CDN_Files::requeue($row, (int) $row->attempts + 1);
            return true;
        }

        // Problems with this one file
        $permanent_codes = ['validation_error', 'media_failed', 'media_blocked', 'file_unreadable'];
        if (in_array($code, $permanent_codes, true) || 422 === $status) {
            ImgPro_CDN_Files::mark_attempt_failed($row, $error->get_error_message(), true);
            return true;
        }

        // Problems with the key, App, quota or service: stop the run
        if ($this->handle_error($error)) {
            // Pauses and rate limits are not the file's fault
            ImgPro_CDN_Files::set_attempts($row, (int) $row->attempts);
        } else {
            // Server and connection errors: count the attempt and back off
            ImgPro_CDN_Files::mark_attempt_failed($row, $error->get_error_message(), false, self::MAX_ATTEMPTS);
        }
        return false;
    }

    /**
     * Pause or back off after an API error that affects every request
     *
     * @param WP_Error $error API error.
     * @return bool True when the error was a pause or back-off condition.
     */
    private function handle_error($error) {
        $data   = (array) $error->get_error_data();
        $status = (int) ($data['status'] ?? 0);
        $code   = $error->get_error_code();
        $state  = self::get_state();

        $state['last_error'] = $error->get_error_message();

        if (401 === $status || 'forbidden' === $code) {
            self::save_state($state);
            $this->pause(ImgPro_CDN_Settings::PAUSE_AUTH, $error->get_error_message());
            return true;
        }

        if ('quota_exceeded' === $code) {
            self::save_state($state);
            $this->pause(ImgPro_CDN_Settings::PAUSE_QUOTA, $error->get_error_message());
            return true;
        }

        if (in_array($code, ['app_suspended', 'app_blocked', 'workspace_unavailable'], true)) {
            self::save_state($state);
            $this->pause(ImgPro_CDN_Settings::PAUSE_APP, $error->get_error_message());
            return true;
        }

        if (429 === $status || 503 === $status || 'idempotency_key_in_progress' === $code) {
            $wait = max(30, (int) ($data['retry_after'] ?? 0));
            $state['wait_until'] = time() + min($wait, HOUR_IN_SECONDS);
            self::save_state($state);
            return true;
        }

        // Connection problems and server errors: back off briefly
        $state['wait_until'] = time() + 60;
        self::save_state($state);
        return false;
    }

    /**
     * Pause syncing until the user acts
     *
     * @param string $reason PAUSE_* constant.
     * @param string $detail Message from img.pro.
     * @return void
     */
    private function pause($reason, $detail) {
        $this->settings->update([
            'pause_reason' => $reason,
            'pause_detail' => $detail,
        ]);
    }

    /**
     * Resume syncing after a pause
     *
     * @return void
     */
    public function resume() {
        $this->settings->update([
            'pause_reason' => ImgPro_CDN_Settings::PAUSE_NONE,
            'pause_detail' => '',
        ]);
        $state = self::get_state();
        $state['wait_until'] = 0;
        $state['last_error'] = '';
        self::save_state($state);
        self::schedule_soon();
    }

    /**
     * Delete every image this site uploaded, then disconnect
     *
     * Lists the first page of this site's images and deletes it, until the
     * list is empty. Images img.pro refuses to delete are remembered and
     * skipped, so one stubborn image cannot keep the removal going forever.
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return array Run summary.
     */
    private function remove_step($api, $deadline) {
        $site  = ImgPro_CDN_Settings::get_site_label();
        $state = self::get_state();
        $skip  = array_flip((array) $state['remove_skip']);

        while (microtime(true) < $deadline) {
            $page = $api->list_images(['site' => $site], 100, $state['remove_cursor'] ? $state['remove_cursor'] : null);
            if (is_wp_error($page)) {
                self::save_state($state);
                $this->handle_error($page);
                return $this->stopped_result();
            }

            $ids = [];
            foreach ((array) ($page['data'] ?? []) as $image) {
                if (!empty($image['id']) && !isset($skip[(string) $image['id']])) {
                    $ids[] = (string) $image['id'];
                }
            }

            if (empty($ids)) {
                $cursor = $page['pagination']['next_cursor'] ?? null;
                if (!empty($page['pagination']['has_more']) && !empty($cursor)) {
                    // Only images that could not be deleted on this page
                    $state['remove_cursor'] = (string) $cursor;
                    continue;
                }
                $this->finish_removal(count($skip));
                return ['more' => false];
            }

            $result = $api->delete_images($ids);
            if (is_wp_error($result)) {
                self::save_state($state);
                $this->handle_error($result);
                return $this->stopped_result();
            }

            foreach ((array) ($result['errors'] ?? []) as $error) {
                $code = $error['error']['code'] ?? '';
                if (!empty($error['id']) && 'not_found' !== $code) {
                    $skip[(string) $error['id']] = true;
                }
            }
            $state['remove_skip'] = array_slice(array_keys($skip), 0, 1000);

            // The listing shifted, so start again from the first page
            $state['remove_cursor'] = '';
        }

        self::save_state($state);
        return ['more' => true];
    }

    /**
     * Forget the connection once every image is removed
     *
     * @param int $leftover Images img.pro would not delete.
     * @return void
     */
    private function finish_removal($leftover) {
        if ($leftover > 0) {
            update_option(self::LEFTOVER_OPTION, (int) $leftover, false);
        }
        $this->disconnect(false);
        if (0 === (int) $leftover) {
            // Nothing of this site is left in the App; label future uploads afresh
            $this->settings->update(['site_label' => '']);
        }
    }

    /**
     * Prepare a fresh sync for a newly saved key
     *
     * The key may belong to a different App, so the local map starts
     * over; files already in the App are found again by the adopt step.
     * Queued deletions are kept: they still name images in the old App.
     *
     * @return void
     */
    public function start() {
        ImgPro_CDN_Files::clear(true);
        self::save_state(self::default_state());
        delete_option(self::LEFTOVER_OPTION);
        if (!$this->settings->get('site_label')) {
            $this->settings->update(['site_label' => ImgPro_CDN_Settings::current_site_label()]);
        }
        self::schedule_recurring();
        self::schedule_soon();
    }

    /**
     * Rescan the library after the plugin was inactive
     *
     * Attachments added or deleted meanwhile were not tracked, and the
     * database may have been restored from a backup, so files are matched
     * against the App again before anything is uploaded.
     *
     * @return void
     */
    public function restart_scan() {
        if (!$this->settings->is_connected()) {
            return;
        }
        $state = self::get_state();
        $state['backfill_cursor'] = 0;
        $state['backfill_done']   = false;
        $state['adopt_cursor']    = '';
        $state['adopt_done']      = false;
        self::save_state($state);
        self::schedule_recurring();
        self::schedule_soon();
    }

    /**
     * Stop syncing and forget the key and the local map
     *
     * Images stay in the img.pro App.
     *
     * @param bool $keep_deletions Keep queued deletions for the next connection.
     * @return void
     */
    public function disconnect($keep_deletions = true) {
        ImgPro_CDN_Files::clear($keep_deletions);
        delete_option(self::STATE_OPTION);
        self::unschedule();
        $this->settings->update([
            'api_key'      => '',
            'enabled'      => false,
            'pause_reason' => ImgPro_CDN_Settings::PAUSE_NONE,
            'pause_detail' => '',
            'removing'     => false,
        ]);
    }

    /**
     * Start removing every image of this site from img.pro
     *
     * Serving stops at once; the worker deletes in batches and then
     * disconnects.
     *
     * @return void
     */
    public function start_removal() {
        $this->settings->update([
            'removing' => true,
            'enabled'  => false,
        ]);
        ImgPro_CDN_Files::flush_cache();
        $state = self::get_state();
        $state['wait_until']    = 0;
        $state['remove_cursor'] = '';
        $state['remove_skip']   = [];
        self::save_state($state);
        self::schedule_soon();
    }

    /**
     * Progress summary for the admin page
     *
     * @return array
     */
    public function get_status() {
        $counts = ImgPro_CDN_Files::get_counts();
        $state  = self::get_state();
        $pause  = $this->settings->get('pause_reason');

        $waiting = (int) $state['wait_until'] > time();

        if ($this->settings->get('removing') && ImgPro_CDN_Settings::PAUSE_AUTH !== $pause && !$waiting) {
            $phase = 'removing';
        } elseif (ImgPro_CDN_Settings::PAUSE_NONE !== $pause && ($this->settings->get('removing') ? ImgPro_CDN_Settings::PAUSE_AUTH === $pause : true)) {
            $phase = 'paused';
        } elseif ($waiting) {
            $phase = 'waiting';
        } elseif (empty($state['backfill_done'])) {
            $phase = 'scanning';
        } elseif (empty($state['adopt_done'])) {
            $phase = 'matching';
        } elseif ($counts[ImgPro_CDN_Files::STATUS_PENDING] > 0) {
            $phase = 'uploading';
        } else {
            $phase = 'idle';
        }

        $total = $counts[ImgPro_CDN_Files::STATUS_PENDING]
            + $counts[ImgPro_CDN_Files::STATUS_SYNCED]
            + $counts[ImgPro_CDN_Files::STATUS_FAILED];

        return [
            'phase'        => $phase,
            'pause_reason' => $pause,
            'pause_detail' => $this->settings->get('pause_detail'),
            'synced'       => $counts[ImgPro_CDN_Files::STATUS_SYNCED],
            'pending'      => $counts[ImgPro_CDN_Files::STATUS_PENDING],
            'failed'       => $counts[ImgPro_CDN_Files::STATUS_FAILED],
            'skipped'      => $counts[ImgPro_CDN_Files::STATUS_SKIPPED],
            'deleting'     => $counts[ImgPro_CDN_Files::STATUS_DELETE],
            'total'        => $total,
            'percent'      => $total > 0 ? (int) floor($counts[ImgPro_CDN_Files::STATUS_SYNCED] * 100 / $total) : 0,
            'wait'         => max(0, (int) $state['wait_until'] - time()),
            'last_error'   => (string) $state['last_error'],
        ];
    }

    /**
     * Default worker state
     *
     * @return array
     */
    private static function default_state() {
        return [
            'generation'      => wp_generate_password(12, false),
            'backfill_cursor' => 0,
            'backfill_done'   => false,
            'adopt_cursor'    => '',
            'adopt_done'      => false,
            'wait_until'      => 0,
            'last_error'      => '',
            'remove_cursor'   => '',
            'remove_skip'     => [],
        ];
    }

    /**
     * Current worker state
     *
     * @return array
     */
    public static function get_state() {
        $state = get_option(self::STATE_OPTION, []);
        return wp_parse_args(is_array($state) ? $state : [], self::default_state());
    }

    /**
     * Save worker state
     *
     * @param array $state State.
     * @return void
     */
    private static function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Run the worker soon
     *
     * @param int $delay Seconds from now.
     * @return void
     */
    public static function schedule_soon($delay = 0) {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next && $next <= time() + $delay + 60) {
            return;
        }
        wp_schedule_single_event(time() + max(0, (int) $delay), self::CRON_HOOK);
    }

    /**
     * Hourly safety net, in case a single run is missed
     *
     * @return void
     */
    public static function schedule_recurring() {
        if (!wp_next_scheduled(self::CRON_HOOK . '_hourly')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK . '_hourly');
        }
    }

    /**
     * Remove scheduled runs
     *
     * @return void
     */
    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_HOOK . '_hourly');
    }

    /**
     * Take the worker lock
     *
     * @return bool
     */
    private function acquire_lock() {
        $now = time();

        if (add_option(self::LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $held = (int) get_option(self::LOCK_OPTION);
        if ($held && ($now - $held) < self::LOCK_TTL) {
            return false;
        }

        update_option(self::LOCK_OPTION, $now, false);
        return true;
    }

    /**
     * Release the worker lock
     *
     * @return void
     */
    private function release_lock() {
        delete_option(self::LOCK_OPTION);
    }
}
