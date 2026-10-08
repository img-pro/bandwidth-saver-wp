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
 * New uploads, edits and regenerated thumbnails are tracked through the
 * attachment metadata filter; deleted attachments queue their copies for
 * deletion. Files are queued for upload when a page shows them (see
 * ImgPro_CDN_Files::get_urls()) or when someone copies them from the
 * Media Library. A worker, run by WP-Cron and by the admin pages while
 * they are open, works through the queue in time-boxed batches:
 *
 * 1. delete copies of files that are gone
 * 2. scan the existing library once after connecting (backfill)
 * 3. match files already on img.pro from an earlier connection (adopt)
 * 4. upload queued files, those copied from the Media Library first
 *
 * A run works for the connection it started with: when the key or the
 * sync generation changes while it is busy (disconnect, connect), it stops
 * without touching the new connection's state.
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
     * A run renews its lock every third of this while it works (see
     * keep_lock()), so only a run that died leaves one this old.
     *
     * @var int
     */
    const LOCK_TTL = 180;

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
     * Server errors in a row, with no upload succeeding in between, after
     * which uploads are taken to be failing in general and the run backs off
     *
     * @var int
     */
    const SERVICE_FAILURE_LIMIT = 3;

    /**
     * Option holding how many images the last removal could not delete
     *
     * @var string
     */
    const LEFTOVER_OPTION = 'imgpro_cdn_removal_leftover';

    /**
     * Option holding how many queued deletions named copies in an App this
     * site no longer uses, which its key cannot delete
     *
     * @var string
     */
    const OTHER_APP_OPTION = 'imgpro_cdn_other_app_leftover';

    /**
     * Copies whose presence on img.pro is checked each hour
     *
     * @var int
     */
    const CONFIRM_PER_HOUR = 200;

    /**
     * Settings instance
     *
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * Site the settings instance belongs to
     *
     * @var int
     */
    private $blog_id;

    /**
     * Attachments whose metadata changed during this request, by site ID
     *
     * @var array
     */
    private $dirty = [];

    /**
     * API key the current run started with
     *
     * @var string
     */
    private $run_key = '';

    /**
     * Sync generation the current run started with
     *
     * @var string
     */
    private $run_generation = '';

    /**
     * Whether the connection changed since the current run started
     *
     * @var bool
     */
    private $stale = false;

    /**
     * Server errors in a row in this upload step since the last successful
     * upload, and the latest of them
     *
     * @var int
     */
    private $service_failures = 0;

    /**
     * @var WP_Error|null
     */
    private $service_error = null;

    /**
     * Whether an upload succeeded in this upload step
     *
     * @var bool
     */
    private $uploaded = false;

    /**
     * Attachments of the files in the current run of server errors
     *
     * @var bool[] attachment ID => true
     */
    private $failing_attachments = [];

    /**
     * Whether a file charged in this upload step had failed before
     *
     * @var bool
     */
    private $retried_failure = false;

    /**
     * Canary state: whether one is wanted next, and whether the file being
     * uploaded is one (see handle_upload_error())
     *
     * @var bool
     */
    private $canary_wanted = false;

    /**
     * @var bool
     */
    private $canary_active = false;

    /**
     * Canaries that failed since the last successful upload, and whether
     * the first of them had failed before it was picked
     *
     * @var int
     */
    private $canaries_failed = 0;

    /**
     * @var bool
     */
    private $first_canary_retried = false;

    /**
     * Whether the upload step ended with failures only and nothing left
     * pending, so it did not back off (see run())
     *
     * @var bool
     */
    private $ended_on_failures = false;

    /**
     * Deletion rows tried in this run, so a refused id is not sent again
     * before the next run
     *
     * @var int[]
     */
    private $deletions_tried = [];

    /**
     * Whether this run uploads only files copied from the Media Library
     *
     * @var bool
     */
    private $priority_only = false;

    /**
     * Copies deleted from img.pro in this run
     *
     * @var int
     */
    private $deleted = 0;

    /**
     * Random part of the lock row's value while this run holds the lock
     *
     * @var string
     */
    private $lock_token = '';

    /**
     * When this run last stamped its lock (microtime)
     *
     * @var float
     */
    private $lock_stamped = 0.0;

    /**
     * Whether the lock is released when the request ends, even by a fatal error
     *
     * @var bool
     */
    private $release_on_shutdown = false;

    /**
     * Constructor
     *
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
        $this->blog_id  = get_current_blog_id();
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
        $settings = $this->settings_for_current_site();
        if ($settings && $settings->is_connected() && !$settings->get('removing')) {
            $this->dirty[get_current_blog_id()][(int) $attachment_id] = true;
        }
        return $data;
    }

    /**
     * Reconcile attachments changed during this request
     *
     * Network plugins can update another site's media inside
     * switch_to_blog(), so each attachment is reconciled on its own site.
     *
     * @return void
     */
    public function reconcile_dirty() {
        $dirty = $this->dirty;
        $this->dirty = [];

        foreach ($dirty as $blog_id => $attachment_ids) {
            $switched = (int) $blog_id !== get_current_blog_id();
            if ($switched) {
                switch_to_blog((int) $blog_id);
            }

            $queued = 0;
            foreach (array_keys($attachment_ids) as $attachment_id) {
                $queued += ImgPro_CDN_Files::reconcile_attachment($attachment_id);
            }
            if ($queued > 0) {
                self::wake_for_new_files();
                self::schedule_soon();
            }

            if ($switched) {
                restore_current_blog();
            }
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
        unset($this->dirty[get_current_blog_id()][(int) $attachment_id]);
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
        if (!is_string($file) || '' === $file) {
            return $file;
        }
        $settings = $this->settings_for_current_site();
        if (!$settings || !$settings->is_connected()) {
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
     * Settings of the site WordPress is working on right now
     *
     * Differs from this instance's own settings inside switch_to_blog(),
     * where the site may not run the plugin at all.
     *
     * @return ImgPro_CDN_Settings|null Null when the plugin is not active there.
     */
    private function settings_for_current_site() {
        if (get_current_blog_id() === $this->blog_id) {
            return $this->settings;
        }
        return ImgPro_CDN_Settings::plugin_active_here() ? new ImgPro_CDN_Settings() : null;
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
     * @param int  $budget        Seconds to spend.
     * @param bool $priority_only Upload only files copied from the Media
     *                            Library, for a copy that waits on the
     *                            result. Deletions, the scan and the
     *                            matching still run first, so a file
     *                            already in the App is not uploaded twice.
     * @return array Summary: `more` when work remains, `wait` seconds to wait
     *               before the next run, `locked` when another run is active.
     */
    public function run($budget, $priority_only = false) {
        if (!$this->settings->is_connected()) {
            return ['more' => false];
        }

        if (!$this->acquire_lock()) {
            return ['more' => true, 'locked' => true, 'wait' => 10];
        }

        try {
            // Uploads hold a whole file in memory; ask for the admin memory limit
            wp_raise_memory_limit('imgpro_cdn');

            $max_execution = (int) ini_get('max_execution_time');
            if ($max_execution > 0) {
                $budget = min($budget, max(5, $max_execution - 10));
            }
            $deadline = microtime(true) + $budget;

            // Remember which connection this run works for; see current_state()
            $this->settings->refresh();
            $state                = self::get_state(true);
            $this->run_key        = $this->settings->get_api_key();
            $this->run_generation  = (string) $state['generation'];
            $this->stale           = false;
            $this->deletions_tried = [];
            $this->priority_only   = (bool) $priority_only;
            $this->deleted         = 0;

            if ('' === $this->run_key) {
                return ['more' => false];
            }
            $api = new ImgPro_CDN_API($this->run_key);

            if ((int) $state['wait_until'] > time()) {
                return ['more' => true, 'wait' => (int) $state['wait_until'] - time()];
            }

            if (self::blocks_work($this->settings->get('pause_reason'))) {
                return ['more' => false];
            }

            if ($this->settings->get('removing')) {
                return $this->remove_step($api, $deadline);
            }

            if (!$this->delete_step($api, $deadline)) {
                return $this->stopped_result();
            }

            // At the image limit deletions still run, and they are what
            // makes room, so the App is checked after them
            if (ImgPro_CDN_Settings::PAUSE_QUOTA === $this->settings->get('pause_reason')) {
                $this->lift_quota_pause($api, $this->deleted > 0);
            }

            if (!$this->settings->can_sync()) {
                return ['more' => false];
            }

            if (empty($state['backfill_done'])) {
                if (!$this->backfill_step($deadline)) {
                    return $this->stopped_result();
                }
                $state = self::get_state(true);
                if (empty($state['backfill_done'])) {
                    return ['more' => true];
                }
            }

            if (empty($state['adopt_done'])) {
                if (!$this->adopt_step($api, $deadline)) {
                    return $this->stopped_result();
                }
                $state = self::get_state(true);
                if (empty($state['adopt_done'])) {
                    return ['more' => true];
                }
            }

            // Copies retired by the scan or the matching in this run go
            // first, so their replacements do not count against the App's
            // image limit twice
            if (!$this->delete_step($api, $deadline)) {
                return $this->stopped_result();
            }

            if (!$this->upload_step($api, $deadline)) {
                return $this->stopped_result();
            }

            // A copy made from the Media Library waits on the result; the
            // check of earlier copies is left to the next background run
            if (!$this->priority_only && !$this->confirm_step($api, $deadline)) {
                return $this->stopped_result();
            }

            // The run went through: clear the error and back-off, unless the
            // upload step only failed (its last files just failed for good).
            // The back-off then carries over, so the next file to fail does
            // not start from the shortest wait.
            if (!$this->ended_on_failures) {
                $this->clear_back_off();
            }
            if ($this->stale) {
                return ['more' => false];
            }

            // Deletions held back for a copy a row still serves are no work
            // for the next run (see ImgPro_CDN_Files::get_deletions())
            $counts = ImgPro_CDN_Files::get_counts();
            return ['more' => $counts[ImgPro_CDN_Files::STATUS_PENDING] > 0 || !empty(ImgPro_CDN_Files::get_deletions(1))];
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Whether a pause stops the worker altogether
     *
     * Every pause does but the quota pause: a rejected key, or a paused,
     * blocked or deleted App, refuses deletions too. At the image limit,
     * deleting still works, and it is what makes room.
     *
     * @param string $pause PAUSE_* constant.
     * @return bool
     */
    private static function blocks_work($pause) {
        return ImgPro_CDN_Settings::PAUSE_NONE !== $pause && ImgPro_CDN_Settings::PAUSE_QUOTA !== $pause;
    }

    /**
     * Lift a quota pause once the App has room again
     *
     * The App's owner may upgrade it in Billing, or deletions free room.
     * Checked at most every 15 minutes, and right after deletions; a resume
     * that finds the App full again just pauses again, since img.pro stores
     * nothing it refuses.
     *
     * @param ImgPro_CDN_API $api   API client.
     * @param bool           $now   Check whatever the last check's time.
     * @return void
     */
    private function lift_quota_pause($api, $now = false) {
        $state = $this->current_state();
        if (null === $state) {
            return;
        }
        if (!$now && (int) $state['quota_checked_at'] > time() - 15 * MINUTE_IN_SECONDS) {
            return;
        }
        if (!$this->update_state(['quota_checked_at' => time()])) {
            return;
        }

        $usage = $api->get_usage();
        if (is_wp_error($usage)) {
            return;
        }
        set_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT, $usage, MINUTE_IN_SECONDS);

        if (self::has_room($usage)) {
            $this->resume();
        }
    }

    /**
     * Whether img.pro takes new images, going by a usage response
     *
     * @param array|null $usage Usage object.
     * @return bool False when unknown.
     */
    public static function has_room($usage) {
        if (!is_array($usage)) {
            return false;
        }
        $used  = $usage['limits']['images']['used'] ?? null;
        $limit = $usage['limits']['images']['limit'] ?? null;
        return empty($usage['limits_enforced']) || null === $limit || (null !== $used && (int) $used < (int) $limit);
    }

    /**
     * Result after a step stopped early
     *
     * @return array
     */
    private function stopped_result() {
        // The connection changed, or another run took the lock over; that
        // connection or that run schedules the next runs
        if ($this->stale) {
            return ['more' => false];
        }

        $state = self::get_state(true);
        $wait  = max(0, (int) $state['wait_until'] - time());

        if ($wait > 0) {
            return ['more' => true, 'wait' => $wait];
        }

        // Paused (quota, key or App problems) needs the user, not a retry
        return ['more' => $this->settings->can_sync(), 'wait' => 60];
    }

    /**
     * Fresh worker state, unless the connection changed since the run started
     *
     * Disconnecting deletes the state and connecting starts a new
     * generation, so a run still busy with the previous connection
     * (holding its old key) sees a different key or generation and stops.
     * A run whose lock another run took over stops too (see keep_lock()).
     * Settings are re-read on the way, so pauses and removal are current.
     *
     * @return array|null State, or null when this run is stale.
     */
    private function current_state() {
        if ($this->stale) {
            return null;
        }
        if (!$this->keep_lock()) {
            $this->stale = true;
            return null;
        }

        $this->settings->refresh();
        $state = self::get_state(true);

        if ($this->settings->get_api_key() !== $this->run_key || (string) $state['generation'] !== $this->run_generation) {
            $this->stale = true;
            return null;
        }

        return $state;
    }

    /**
     * Save some state keys, unless the connection changed
     *
     * Only the given keys are written, so changes the admin made meanwhile
     * (resume, removal) are kept.
     *
     * @param array $changes Keys to update.
     * @return bool False when this run is stale and nothing was saved.
     */
    private function update_state($changes) {
        $state = $this->current_state();
        if (null === $state) {
            return false;
        }
        self::save_state(array_merge($state, $changes));
        return true;
    }

    /**
     * Forget the last error and the back-off after img.pro answered fine
     *
     * The back-off grows only while errors repeat, so it resets on any
     * successful exchange, not just at the end of a full run.
     *
     * @param bool $uploaded Whether an upload succeeded, which also ends a
     *                       series of failing upload steps.
     * @return void
     */
    private function clear_back_off($uploaded = false) {
        $state = $this->current_state();
        if (null === $state) {
            return;
        }

        $changes = [];
        if ('' !== $state['last_error'] || (int) $state['transient_streak'] > 0) {
            $changes['last_error']       = '';
            $changes['transient_streak'] = 0;
        }
        if ($uploaded && (int) $state['failing_files'] > 0) {
            $changes['failing_files'] = 0;
        }
        if ($changes) {
            self::save_state(array_merge($state, $changes));
        }
    }

    /**
     * Delete img.pro copies of files that are gone
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error or a connection change stopped the step.
     */
    private function delete_step($api, $deadline) {
        while (microtime(true) < $deadline) {
            if (null === $this->current_state()) {
                return false;
            }

            // Rows tried earlier in this run (run() deletes twice) wait for
            // the next run: retrying a refused id at once cannot help
            $rows = ImgPro_CDN_Files::get_deletions(100, $this->deletions_tried);
            if (empty($rows)) {
                return true;
            }

            // A deletion kept from a connection to another App names a copy
            // in that App's storage. This key cannot delete it, and img.pro
            // would report it deleted anyway (a batch delete answers every
            // id), so it is let go and the admin is told once. While nothing
            // shows which storage this key uses, such rows wait.
            $storage = $this->current_storage();
            $other   = [];
            $by_imgpro_id = [];
            foreach ($rows as $row) {
                $this->deletions_tried[] = (int) $row->id;
                $row_storage = ImgPro_CDN_Files::storage_of((string) $row->url);
                if ('' !== $row_storage && $row_storage !== $storage) {
                    if ('' !== $storage) {
                        $other[] = (int) $row->id;
                    }
                    continue;
                }
                $by_imgpro_id[(string) $row->imgpro_id][] = $row;
            }
            if (!empty($other)) {
                ImgPro_CDN_Files::delete_rows($other);
                update_option(self::OTHER_APP_OPTION, (int) get_option(self::OTHER_APP_OPTION, 0) + count($other), false);
            }
            if (empty($by_imgpro_id)) {
                continue;
            }

            list($outcome, $error) = $this->delete_ids($api, array_map('strval', array_keys($by_imgpro_id)), $deadline);

            // Record what was settled before any error, so the work is not
            // repeated. Ids img.pro refused stay queued until they run out of
            // attempts; ids not reached stay queued as they are.
            $done = [];
            foreach ($by_imgpro_id as $imgpro_id => $id_rows) {
                if (!array_key_exists((string) $imgpro_id, $outcome)) {
                    continue;
                }
                $result = $outcome[(string) $imgpro_id];
                foreach ($id_rows as $row) {
                    if (true !== $result && (int) $row->attempts + 1 < self::MAX_ATTEMPTS) {
                        ImgPro_CDN_Files::mark_attempt_failed($row, $result, false, self::MAX_ATTEMPTS);
                    } else {
                        $done[] = (int) $row->id;
                    }
                }
            }
            ImgPro_CDN_Files::delete_rows($done);
            $this->deleted += count($done);

            if ($error) {
                $this->handle_error($error);
                return false;
            }

            $this->clear_back_off();
        }

        return true;
    }

    /**
     * App storage the current key's copies are in
     *
     * Learned from the first copy and kept in the state, so it is still
     * known after every copy is gone.
     *
     * @return string See ImgPro_CDN_Files::storage_of(); empty while unknown.
     */
    private function current_storage() {
        $state = self::get_state(true);
        if ('' !== (string) $state['storage']) {
            return (string) $state['storage'];
        }
        $storage = ImgPro_CDN_Files::current_storage();
        if ('' !== $storage) {
            $this->update_state(['storage' => $storage]);
        }
        return $storage;
    }

    /**
     * Check that copies are still on img.pro
     *
     * The App's team can delete images on img.pro, and moderation can
     * block one. Pages would keep loading such a copy, fail, and fall back
     * every time. So once an hour the copies confirmed longest ago are
     * looked up, 50 at a time: a copy that is gone is queued to be copied
     * again, and a blocked one stops being served.
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when the connection changed.
     */
    private function confirm_step($api, $deadline) {
        $state = $this->current_state();
        if (null === $state) {
            return false;
        }
        if ((int) $state['confirmed_at'] > time() - HOUR_IN_SECONDS) {
            return true;
        }
        if (!$this->update_state(['confirmed_at' => time()])) {
            return false;
        }

        $lost    = 0;
        $checked = [];
        while (count($checked) < self::CONFIRM_PER_HOUR && microtime(true) < $deadline) {
            $rows = ImgPro_CDN_Files::get_unconfirmed(50, $checked);
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $checked[] = (int) $row->id;
            }

            $by_id = [];
            foreach ($rows as $row) {
                $by_id[(string) $row->imgpro_id][] = $row;
            }
            $list = $api->get_images(array_keys($by_id));
            if (is_wp_error($list)) {
                // A check can wait for the next hour; no back-off for it
                break;
            }
            $found = [];
            foreach ((array) ($list['data'] ?? []) as $image) {
                if (!empty($image['id'])) {
                    $found[(string) $image['id']] = true;
                }
            }

            $confirmed = [];
            foreach ($by_id as $id => $id_rows) {
                if (!isset($found[$id])) {
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }
                    // The list leaves out what it cannot serve; one image
                    // at a time says why
                    $image = $api->get_image($id);
                    $code  = is_wp_error($image) ? $image->get_error_code() : '';
                    if ('not_found' === $code) {
                        foreach ($id_rows as $row) {
                            $lost += (int) ImgPro_CDN_Files::mark_lost($row);
                        }
                        continue;
                    }
                    if ('media_failed' === $code) {
                        // The failed image is still in the App, though lists
                        // leave it out, so only this check can delete it.
                        // Queued first: if that fails, the rows keep the id
                        // and the next check asks again.
                        $first = reset($id_rows);
                        if (!ImgPro_CDN_Files::is_queued_for_deletion($id) && !ImgPro_CDN_Files::queue_deletion($id, $first, (string) $first->url)) {
                            continue;
                        }
                        foreach ($id_rows as $row) {
                            ImgPro_CDN_Files::mark_processing_failed($row);
                        }
                        continue;
                    }
                    if ('media_blocked' === $code) {
                        foreach ($id_rows as $row) {
                            ImgPro_CDN_Files::mark_blocked($row);
                        }
                        continue;
                    }
                    if (is_wp_error($image)) {
                        // Anything else (an outage, a rate limit): next hour
                        break 2;
                    }
                }
                foreach ($id_rows as $row) {
                    $confirmed[] = (int) $row->id;
                }
            }
            ImgPro_CDN_Files::confirm_copies($confirmed);

            if (null === $this->current_state()) {
                return false;
            }
        }

        if ($lost > 0) {
            self::wake_for_new_files();
            self::schedule_soon();
        }
        return true;
    }

    /**
     * Delete images, isolating ids img.pro refuses
     *
     * When img.pro rejects a batch as a whole, an id in it is at fault, so
     * the batch is split in halves until the bad ids stand alone: one bad id
     * costs a handful of requests and cannot hold up the rest.
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param string[]       $ids      Image ids (up to 100).
     * @param float          $deadline Unix time to stop at.
     * @return array [outcome, error]: outcome maps id => true when deleted
     *               (or already gone) or to the error message; ids not
     *               reached are left out. error is the WP_Error that
     *               stopped the deletion early, or null.
     */
    private function delete_ids($api, $ids, $deadline) {
        $ids    = array_values($ids);
        $result = $api->delete_images($ids);

        if (!is_wp_error($result)) {
            $outcome = array_fill_keys(array_map('strval', $ids), true);
            return [array_replace($outcome, self::batch_failures($result)), null];
        }

        if ('request' !== self::classify_error($result)) {
            return [[], $result];
        }
        if (1 === count($ids)) {
            return [[(string) $ids[0] => self::stored_error($result)], null];
        }

        $outcome = [];
        foreach (array_chunk($ids, (int) ceil(count($ids) / 2)) as $half) {
            if (microtime(true) >= $deadline) {
                break;
            }
            list($part, $error) = $this->delete_ids($api, $half, $deadline);
            $outcome += $part;
            if ($error) {
                return [$outcome, $error];
            }
        }
        return [$outcome, null];
    }

    /**
     * Ids a batch delete could not delete
     *
     * Batch delete is idempotent: ids that are already gone count as deleted.
     * Defensive: img.pro's batch delete answers a tombstone for every id and
     * an empty errors array today; per-id errors are honored if it ever
     * reports them.
     *
     * @param array $result Batch result.
     * @return array Map of id => error message.
     */
    private static function batch_failures($result) {
        $failed = [];
        foreach ((array) ($result['errors'] ?? []) as $error) {
            $code = $error['error']['code'] ?? '';
            if (!empty($error['id']) && 'not_found' !== $code) {
                $failed[(string) $error['id']] = (string) ($error['error']['message'] ?? $code);
            }
        }
        return $failed;
    }

    /**
     * Record the existing media library, a batch of attachments at a time
     *
     * Files are recorded as not copied yet; only changed files that were in
     * use are queued (see ImgPro_CDN_Files::reconcile_attachment()). Then
     * rows whose attachment was deleted while the plugin was inactive are
     * retired, also in batches that resume on the next run.
     *
     * @param float $deadline Unix time to stop at.
     * @return bool False when a database error (the step backed off) or a connection change stopped the step.
     */
    private function backfill_step($deadline) {
        global $wpdb;

        $state = $this->current_state();
        if (null === $state) {
            return false;
        }
        $cursor = (int) $state['backfill_cursor'];

        while (microtime(true) < $deadline) {
            // A direct keyset query: WP_Query cannot ask for IDs after a
            // cursor, and paging by offset would skip attachments deleted
            // mid-scan. The literal wildcard matches every image type.
            $posts = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                    'image/%',
                    $cursor,
                    self::BACKFILL_BATCH
                )
            );
            // A failed query is not the end of the library: wait, then go on from here
            if ('' !== $wpdb->last_error) {
                $this->back_off_for_database();
                return false;
            }
            $ids = array_map('intval', wp_list_pluck($posts, 'ID'));

            // Cache the batch's posts and metadata, as core's own priming
            // does, so reconciling costs no queries per attachment for them
            if (!empty($ids)) {
                update_post_cache($posts);
                update_meta_cache('post', $ids);
            }

            foreach ($ids as $attachment_id) {
                ImgPro_CDN_Files::reconcile_attachment($attachment_id, null, $failed);
                // Saved up to the one before, so this one is scanned again
                // after a wait. One the database refuses every time is passed
                // over after three tries, so it cannot stop the whole scan.
                if ($failed && (int) $state['backfill_retries'] < 2) {
                    $this->update_state(['backfill_cursor' => $cursor, 'backfill_retries' => (int) $state['backfill_retries'] + 1]);
                    $this->back_off_for_database();
                    return false;
                }
                if ((int) $state['backfill_retries'] > 0) {
                    $state['backfill_retries'] = 0;
                    $this->update_state(['backfill_retries' => 0]);
                }
                $cursor = $attachment_id;
            }

            if (count($ids) < self::BACKFILL_BATCH) {
                $orphans = ImgPro_CDN_Files::retire_orphans((int) $state['orphans_cursor'], $deadline);
                if (false === $orphans) {
                    $this->update_state(['backfill_cursor' => $cursor]);
                    $this->back_off_for_database();
                    return false;
                }
                if (null !== $orphans) {
                    return $this->update_state(['backfill_cursor' => $cursor, 'orphans_cursor' => $orphans]);
                }
                return $this->update_state(['backfill_cursor' => $cursor, 'backfill_done' => true]);
            }

            // Save progress, and stop if the connection changed meanwhile
            if (!$this->update_state(['backfill_cursor' => $cursor])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match files already on img.pro to files not copied yet
     *
     * After reconnecting, or reinstalling the plugin, this site's earlier
     * uploads are still in the App. Files not copied yet (idle or queued)
     * whose earlier copy has the same content are marked synced instead of
     * being uploaded again, which costs nothing; extra or outdated copies
     * of a file are queued for deletion.
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error, a database error (the step backed off) or a connection change stopped the step.
     */
    private function adopt_step($api, $deadline) {
        $state = $this->current_state();
        if (null === $state) {
            return false;
        }
        $cursor = (string) $state['adopt_cursor'];
        $site   = ImgPro_CDN_Settings::get_site_label();

        while (microtime(true) < $deadline) {
            $page = $api->list_images(['site' => $site], 100, '' !== $cursor ? $cursor : null);
            if (is_wp_error($page)) {
                if ('' !== $cursor && 'request' === self::classify_error($page)) {
                    // The cursor is no longer accepted; list from the start
                    $cursor = '';
                    continue;
                }
                $this->handle_error($page);
                return false;
            }

            // Images listed with an earlier connection's key must not be
            // matched to this connection's files
            if (null === $this->current_state()) {
                return false;
            }

            // Matching hashes files, so a page can outlast the time budget.
            // Stopping mid-page is safe: the page is listed again and images
            // already handled are skipped (adopted rows are no longer
            // pending, queued copies are left alone). Each listed page gets
            // a few seconds of its own, and a run stops only after settling
            // an image, so a list call as slow as the budget still advances.
            $page_deadline = max($deadline, microtime(true) + 5);
            $worked        = false;
            foreach ((array) ($page['data'] ?? []) as $image) {
                if ($worked && microtime(true) >= $page_deadline) {
                    return $this->update_state(['transient_streak' => 0, 'last_error' => '']);
                }
                $adopted = $this->adopt_image($image);
                if (null === $adopted) {
                    // Recording a match failed: wait, then list this page again
                    $this->back_off_for_database();
                    return false;
                }
                $worked = $adopted || $worked;
            }

            // img.pro answered, so an earlier error no longer applies
            $changes = ['transient_streak' => 0, 'last_error' => ''];

            $next = $page['pagination']['next_cursor'] ?? null;
            if (empty($page['pagination']['has_more']) || empty($next)) {
                return $this->update_state($changes + ['adopt_cursor' => '', 'adopt_done' => true]);
            }

            $cursor = (string) $next;
            if (!$this->update_state($changes + ['adopt_cursor' => $cursor])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adopt one image found on img.pro
     *
     * @param array $image Image object.
     * @return bool|null Whether this changed anything (adopted or queued the
     *                   image); null when the database refused a change.
     */
    private function adopt_image($image) {
        global $wpdb;

        // An upload names its file; an import from another website its address
        $file = isset($image['metadata']['wp_file']) ? (string) $image['metadata']['wp_file'] : '';
        if ('' === $file && isset($image['metadata']['wp_url'])) {
            $file = (string) $image['metadata']['wp_url'];
        }
        if ('' === $file || empty($image['id']) || empty($image['url'])) {
            return false;
        }

        // A copy queued for deletion was retired on purpose: its file was
        // rewritten, maybe at the same size, or it duplicates another copy.
        // Adopting it back would serve the old picture (or an image about
        // to be deleted), so the file uploads afresh and the old copy is
        // deleted as planned.
        if (ImgPro_CDN_Files::is_queued_for_deletion($image['id'])) {
            return false;
        }
        // A failed read is not a "no"
        if ('' !== $wpdb->last_error) {
            return null;
        }

        $row = ImgPro_CDN_Files::get_by_file($file);
        if (!$row && '' !== $wpdb->last_error) {
            return null;
        }
        if (!$row && !isset($image['metadata']['wp_file']) && isset($image['metadata']['wp_url'])) {
            // An earlier import from another website: no row names it after
            // a reconnect, and pages would import its address again
            $added = ImgPro_CDN_Files::adopt_remote($file, $image['id'], $image['url']);
            if (null === $added) {
                return null;
            }
            if ($added) {
                return true;
            }
            // A page queued the address meanwhile
            $row = ImgPro_CDN_Files::get_by_file($file);
            if (!$row && '' !== $wpdb->last_error) {
                return null;
            }
        }
        if (!$row) {
            // Not part of the library any more; leave it for the user to manage
            return false;
        }

        // Not copied yet; for an image from another website also a failed
        // import, whose website may be gone while the earlier copy works
        $not_copied = in_array($row->status, [ImgPro_CDN_Files::STATUS_PENDING, ImgPro_CDN_Files::STATUS_IDLE], true)
            || (!empty($row->remote) && ImgPro_CDN_Files::STATUS_FAILED === $row->status);
        if ($not_copied && false !== self::same_content($row, $image)) {
            return null === ImgPro_CDN_Files::mark_synced($row, $image['id'], $image['url']) ? null : true;
        }

        if ($row->imgpro_id === $image['id']) {
            return false;
        }

        // A stale or duplicate copy of this file
        return ImgPro_CDN_Files::queue_deletion($image['id'], $row, (string) $image['url']) ? true : null;
    }

    /**
     * Whether an image on img.pro is a copy of a row's file as it is now
     *
     * Name and size alone do not tell: a file rewritten at the same size
     * (an edit while disconnected, regenerated thumbnails) would bring its
     * old picture back. The size is compared first, so only likely matches
     * are hashed.
     *
     * @param object $row   Table row.
     * @param array  $image Image object.
     * @return bool|null Null when the file cannot be read right now. It
     *                   could not be uploaded either, so name and size are
     *                   the best match there is; deleting the copy would
     *                   leave the file with none.
     */
    private static function same_content($row, $image) {
        // An import is matched by its address; the image there is not ours to hash
        if (!empty($row->remote)) {
            return isset($image['metadata']['wp_url']) && (string) $image['metadata']['wp_url'] === (string) $row->file;
        }

        $bytes = isset($image['metadata']['wp_bytes']) ? (string) $image['metadata']['wp_bytes'] : '';
        $md5   = isset($image['metadata']['wp_md5']) ? (string) $image['metadata']['wp_md5'] : '';
        if ('' === $md5 || (string) $row->file_bytes !== $bytes) {
            return false;
        }

        $path = ImgPro_CDN_Files::get_basedir() . '/' . $row->file;
        $hash = is_readable($path) ? md5_file($path) : false;
        if (false === $hash) {
            return null;
        }
        return hash_equals($md5, $hash);
    }

    /**
     * Upload pending files
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error, a database error (the step backed off) or a connection change stopped the step.
     */
    private function upload_step($api, $deadline) {
        $this->service_failures    = 0;
        $this->service_error       = null;
        $this->uploaded            = false;
        $this->failing_attachments = [];
        $this->retried_failure     = false;
        $this->canary_wanted       = false;
        $this->canary_active       = false;
        $this->canaries_failed     = 0;
        $this->ended_on_failures   = false;

        $result = $this->upload_files($api, $deadline);

        // Nothing uploaded and the step ended on a server error: wait before
        // the next run rather than charge the remaining files again at once
        if ($result && $this->service_failures > 0 && !$this->uploaded && $this->settings->can_sync()) {
            $backed_off              = $this->upload_back_off();
            $this->ended_on_failures = !$backed_off;
            $result                  = !$backed_off;
        }

        $this->service_failures = 0;
        $this->service_error    = null;
        return $result;
    }

    /**
     * Wait before the next run after an upload step that only failed
     *
     * Files failing while img.pro answers are most likely those files'
     * own problem (too slow to upload, or content img.pro cannot process),
     * so the first such steps wait a flat minute. When failures repeat (a
     * file that failed before fails again, or several steps in a row), the
     * wait grows each time, as after an outage. New files cut either wait
     * short (see wake_for_new_files()).
     *
     * @return bool Whether the run backs off; false when no file is left
     *              pending, so there is nothing to protect.
     */
    private function upload_back_off() {
        $counts = ImgPro_CDN_Files::get_counts();
        if (0 === $counts[ImgPro_CDN_Files::STATUS_PENDING]) {
            return false;
        }

        $state = $this->current_state();
        if (null === $state) {
            return true;
        }

        // The flat minute is for files failing while others could still
        // upload; with only the failing attachments' files left, it would
        // just spend their attempts faster
        $failing = (int) $state['failing_files'] + $this->service_failures;
        if (!$this->retried_failure && $failing < self::SERVICE_FAILURE_LIMIT
            && ImgPro_CDN_Files::get_canary([], array_keys($this->failing_attachments))) {
            self::save_state(array_merge($state, [
                'failing_files' => $failing,
                'wait_until'    => time() + MINUTE_IN_SECONDS,
                'wait_reason'   => 'upload',
                'last_error'    => $this->service_error->get_error_message(),
            ]));
        } else {
            self::save_state(array_merge($state, ['failing_files' => 0]));
            $this->handle_error($this->service_error, 'upload');
        }
        return true;
    }

    /**
     * Upload pending files until the deadline or the queue's end
     *
     * @param ImgPro_CDN_API $api      API client.
     * @param float          $deadline Unix time to stop at.
     * @return bool False when an API error, a database error (the step backed off) or a connection change stopped the step.
     */
    private function upload_files($api, $deadline) {
        $basedir = ImgPro_CDN_Files::get_basedir();
        $site    = ImgPro_CDN_Settings::get_site_label();
        $tried   = [];

        while (microtime(true) < $deadline) {
            // Each file is tried once per run: files that stay pending (a
            // retry for a later run) do not hold up the files behind them.
            // A requeued file keeps its ID, so it waits for the next run too.
            $this->canary_active = false;
            if ($this->canary_wanted) {
                // See handle_upload_error(). Files that failed as a canary
                // before are not picked again.
                $this->canary_wanted = false;
                $skip = (array) self::get_state(true)['canary_skip'];
                $rows = ImgPro_CDN_Files::get_canary(array_merge($tried, $skip), array_keys($this->failing_attachments), $this->canaries_failed > 0);
                if (empty($rows)) {
                    // Only files of the failing attachments are left
                    return !$this->upload_back_off();
                }
                $this->canary_active = true;
            } else {
                $rows = ImgPro_CDN_Files::get_pending(10, $tried, $this->priority_only);
            }
            if (empty($rows)) {
                return true;
            }

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    return true;
                }
                $tried[] = (int) $row->id;

                // The admin may have paused, disconnected, connected another
                // key or started removal while this run was busy
                if (null === $this->current_state()) {
                    return false;
                }
                if (!$this->settings->can_sync()) {
                    return true;
                }

                if (!empty($row->remote)) {
                    // An image from another website: img.pro fetches it from its address.
                    // Queued before the site stopped copying them: forget it.
                    if (!$this->settings->get('remote')) {
                        ImgPro_CDN_Files::delete_rows([(int) $row->id]);
                        continue;
                    }
                    if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                        ImgPro_CDN_Files::mark_attempt_failed($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_IMPORT_STALLED), true);
                        continue;
                    }
                    $labels = [
                        'site'    => $site,
                        'wp_id'   => '0',
                        'wp_size' => 'remote',
                    ];
                    // wp_url lets a later adopt step find the copy of this address
                    $metadata = ['wp_url' => (string) $row->file];
                    if (!ImgPro_CDN_Files::renew_stale_key($row)) {
                        continue;
                    }
                    $key      = 'bs-' . md5($this->run_generation . '|' . $row->id . '|' . (int) $row->retries . '|' . $row->file);

                    ImgPro_CDN_Files::note_attempt($row);
                    $image = $api->import_url($row->file, $labels, $metadata, $key);
                } else {
                    $path = $basedir . '/' . $row->file;
                    if (!file_exists($path)) {
                        ImgPro_CDN_Files::mark_skipped($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_MISSING));
                        continue;
                    }

                    // Every earlier attempt was cut off before it could fail cleanly
                    if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                        ImgPro_CDN_Files::mark_attempt_failed($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_STALLED), true);
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
                        ImgPro_CDN_Files::mark_skipped($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_TOO_LARGE));
                        continue;
                    }
                    if (!self::has_memory_for($bytes)) {
                        ImgPro_CDN_Files::mark_attempt_failed($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_MEMORY), true);
                        continue;
                    }

                    $labels = [
                        'site'    => $site,
                        'wp_id'   => (string) $row->attachment_id,
                        'wp_size' => ImgPro_CDN_Settings::clean_label((string) $row->size_name),
                    ];
                    // wp_md5 lets a later adopt step tell a copy of this file
                    // from one of an older file of the same name and size
                    $metadata = [
                        'wp_file'  => (string) $row->file,
                        'wp_bytes' => (string) $row->file_bytes,
                        'wp_md5'   => is_readable($path) ? (string) md5_file($path) : '',
                    ];
                    // A file sent again from scratch counts a retry, for a new key (see ImgPro_CDN_Files::requeue())
                    if (!ImgPro_CDN_Files::renew_stale_key($row)) {
                        continue;
                    }
                    $key = 'bs-' . md5($this->run_generation . '|' . $row->id . '|' . (int) $row->retries . '|' . $row->file . '|' . $row->file_bytes . '|' . $row->file_mtime);

                    ImgPro_CDN_Files::note_attempt($row);
                    $image = $api->upload_file($path, $labels, $metadata, $key);
                }

                if (is_wp_error($image)) {
                    if (!$this->handle_upload_error($api, $row, $image)) {
                        return false;
                    }
                    if ($this->canary_wanted) {
                        // Fetch the canary next
                        break;
                    }
                    continue;
                }

                if (empty($image['id']) || empty($image['url'])) {
                    ImgPro_CDN_Files::mark_attempt_failed($row, ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_UNEXPECTED), false, self::MAX_ATTEMPTS);
                    continue;
                }

                // Null: the database refused to record the copy. When the
                // row is still queued, the next run's upload sends the same
                // key and gets this image back; when it was retired meanwhile
                // and the deletion could not be queued, the copy stays in the
                // App unnamed. Either way, wait before uploading more.
                if (null === ImgPro_CDN_Files::mark_synced($row, $image['id'], $image['url'])) {
                    $this->back_off_for_database();
                    return false;
                }

                // Uploads work: earlier server errors were those files' own
                $this->service_failures    = 0;
                $this->service_error       = null;
                $this->failing_attachments = [];
                $this->canaries_failed     = 0;
                if (!$this->uploaded) {
                    $this->uploaded = true;
                    $this->clear_back_off(true);
                }
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
     * @param ImgPro_CDN_API $api   API client.
     * @param object         $row   Table row.
     * @param WP_Error       $error API error.
     * @return bool True to continue with the next file, false to stop.
     */
    private function handle_upload_error($api, $row, $error) {
        $code = $error->get_error_code();

        // The body changed since an earlier attempt with this key (the file
        // was rewritten in place): queue it again under a new key
        if (in_array($code, ['idempotency_key_conflict', 'idempotency_recovery_conflict'], true)) {
            // Keeps the row's current priority: a Copy made during the upload stands
            ImgPro_CDN_Files::requeue($row, (int) $row->attempts + 1);
            return true;
        }

        $class = self::classify_error($error);

        // Problems with this one file
        if ('request' === $class) {
            ImgPro_CDN_Files::mark_attempt_failed($row, self::stored_error($error), true);
            return true;
        }

        // The other website did not hand the image over; uploads and other
        // imports carry on. One that answered with an error (502: missing,
        // refused) fails at once rather than being asked again run after
        // run; pages keep showing it from its own address, and Retry failed
        // files asks again. One that was too slow (504) waits, longer each
        // time, until a page shows it again (see ImgPro_CDN_Files::retry_due()),
        // until its attempts run out.
        if ('source' === $class) {
            $data = (array) $error->get_error_data();
            if (504 === (int) ($data['status'] ?? 0)) {
                ImgPro_CDN_Files::mark_attempt_later($row, self::stored_error($error), self::MAX_ATTEMPTS);
            } else {
                ImgPro_CDN_Files::mark_attempt_failed($row, self::stored_error($error), true);
            }
            return true;
        }

        // A server or connection error may be this file's fault (img.pro
        // cannot process it, or it is too slow to upload) or an outage,
        // which must not use up attempts.
        if ('service' === $class && $this->canary_active) {
            // The canary, a file of another attachment, failed too. It may be
            // one more bad file rather than a sign of an outage: it gives its
            // attempt back, is not picked as canary again, and one more
            // canary from the other end of the queue is tried first.
            ImgPro_CDN_Files::set_attempts($row, (int) $row->attempts);
            $this->failing_attachments[(int) $row->attachment_id] = true;
            // The list is long enough that bad files of a big batch do not
            // rotate back in as canaries
            $state = $this->current_state();
            if (null !== $state) {
                $skip = array_slice(array_merge([(int) $row->id], (array) $state['canary_skip']), 0, 200);
                self::save_state(array_merge($state, ['canary_skip' => $skip]));
            }
            if (0 === $this->canaries_failed++) {
                $this->first_canary_retried = (int) $row->attempts > 0;
                $this->canary_wanted        = true;
                return true;
            }

            // Two canaries failed: uploads fail in general. When both had
            // failed before, they may just be more bad files, so new files
            // may still cut the wait short. A canary that had never failed
            // points to an outage, whose wait new files must not cut short
            // (each woken run would spend several failing uploads).
            $reason = ($this->first_canary_retried && (int) $row->attempts > 0) ? 'upload' : '';
            $this->handle_error($error, $reason);
            return false;
        }

        if ('service' === $class) {
            // The first one in a row is checked against the rest of the API:
            // when img.pro is down, nothing is charged
            $probe = 0 === $this->service_failures ? $api->get_usage() : null;

            if (!is_wp_error($probe)) {
                // img.pro answers, so the file is charged and the next file
                // is tried; files tried least go first in later runs, so a
                // file that keeps failing does not lead every run
                ImgPro_CDN_Files::mark_attempt_failed($row, self::stored_error($error), false, self::MAX_ATTEMPTS);
                if ((int) $row->attempts > 0) {
                    $this->retried_failure = true;
                }
                $this->service_failures++;
                $this->service_error = $error;
                $this->failing_attachments[(int) $row->attachment_id] = true;

                // Several failures in a row: the files share a problem (the
                // sizes of one image, a batch of similar images) or uploads
                // fail in general. One file of another attachment, from the
                // far end of the queue, tells which before the run backs off.
                if ($this->service_failures >= self::SERVICE_FAILURE_LIMIT) {
                    $this->canary_wanted = true;
                }
                return true;
            }

            if ('service' !== self::classify_error($probe)) {
                // The check ran into a key, App or rate problem; handle that
                $error = $probe;
            }
        }

        // Pauses, rate limits and outages are not the file's fault: give the
        // attempt back and stop the run
        ImgPro_CDN_Files::set_attempts($row, (int) $row->attempts);
        $this->handle_error($error);
        return false;
    }

    /**
     * How an API error is stored for the admin to read
     *
     * img.pro's own words are kept. Errors the plugin words itself are
     * stored as reason codes and worded when shown, in the reader's
     * language (see ImgPro_CDN_Files::error_text()).
     *
     * @param WP_Error $error API error.
     * @return string
     */
    private static function stored_error($error) {
        $code = (string) $error->get_error_code();
        if ('file_unreadable' === $code) {
            return ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_UNREADABLE);
        }
        // The client names an error after its status when img.pro sent no words
        if (preg_match('/^http_(\d+)$/', $code, $match)) {
            return ImgPro_CDN_Files::reason(ImgPro_CDN_Files::REASON_HTTP . $match[1]);
        }
        return $error->get_error_message();
    }

    /**
     * What an API error means for the sync
     *
     * - auth:     the key was rejected or cannot upload (pause)
     * - quota:    the App reached its plan's image limit (pause)
     * - app:      the App is paused, or blocked, and this key is App-wide (pause)
     * - blocked:  the App is blocked (pause)
     * - deleting: the App's storage is being deleted (pause)
     * - throttle: img.pro asked to slow down (wait)
     * - source:   img.pro could not fetch an image from another website
     * - request:  img.pro rejected what was sent: the file, or an id in a batch
     * - service:  connection problem or server error (back off)
     *
     * Account-level codes come first, so a pause always wins over the
     * status checks below. Codes img.pro adds later fall back on the
     * error's type, which says what kind of problem they are.
     *
     * @param WP_Error $error API error.
     * @return string
     */
    private static function classify_error($error) {
        $data   = (array) $error->get_error_data();
        $status = (int) ($data['status'] ?? 0);
        $code   = $error->get_error_code();

        if (401 === $status || 'forbidden' === $code) {
            return 'auth';
        }
        if ('quota_exceeded' === $code) {
            return 'quota';
        }
        if ('app_suspended' === $code) {
            return 'app';
        }
        if ('app_blocked' === $code) {
            return 'blocked';
        }
        if ('workspace_unavailable' === $code) {
            return 'deleting';
        }
        if (429 === $status || 503 === $status || 'idempotency_key_in_progress' === $code) {
            return 'throttle';
        }
        // A 502 or 504 about the other website, not about img.pro
        if ('fetch_failed' === $code) {
            return 'source';
        }
        // Defensive beyond 422: media_failed and media_blocked come from
        // reading one image, and 400/413/415 from infrastructure in front
        // of the API, not from /v1 creates
        if (in_array($code, ['validation_error', 'media_failed', 'media_blocked', 'file_unreadable'], true) || in_array($status, [400, 413, 415, 422], true)) {
            return 'request';
        }

        switch ((string) ($data['type'] ?? '')) {
            case 'authentication_error':
                return 'auth';
            case 'permission_error':
                return 'app';
            case 'quota_error':
                return 'quota';
            case 'rate_limit_error':
                return 'throttle';
        }
        return 'service';
    }

    /**
     * Pause or back off after an API error that affects every request
     *
     * @param WP_Error $error  API error.
     * @param string   $reason 'upload' when only failing uploads caused the
     *                         wait, which new files may cut short (see
     *                         wake_for_new_files()); 'database' when the
     *                         site's database failed, which the settings
     *                         page says instead of blaming img.pro.
     * @return void
     */
    private function handle_error($error, $reason = '') {
        $state = $this->current_state();
        if (null === $state) {
            // The connection changed; its state is not this run's to touch
            return;
        }

        $class   = self::classify_error($error);
        $data    = (array) $error->get_error_data();
        $message = self::stored_error($error);
        $changes = ['last_error' => $message, 'wait_reason' => $reason];

        $pauses = [
            'auth'     => ImgPro_CDN_Settings::PAUSE_AUTH,
            'quota'    => ImgPro_CDN_Settings::PAUSE_QUOTA,
            'app'      => ImgPro_CDN_Settings::PAUSE_APP,
            'blocked'  => ImgPro_CDN_Settings::PAUSE_BLOCKED,
            'deleting' => ImgPro_CDN_Settings::PAUSE_DELETING,
        ];
        if (isset($pauses[$class])) {
            self::save_state(array_merge($state, $changes));
            $this->pause($pauses[$class], $message);
            return;
        }

        if ('throttle' === $class) {
            $wait = max(30, (int) ($data['retry_after'] ?? 0));
        } else {
            // Connection problems and server errors: back off, for longer
            // each time they repeat (1, 2, 4 … minutes, up to an hour)
            $changes['transient_streak'] = (int) $state['transient_streak'] + 1;
            $wait = 60 * (2 ** min(6, $changes['transient_streak'] - 1));
        }
        $changes['wait_until'] = time() + min($wait, HOUR_IN_SECONDS);

        self::save_state(array_merge($state, $changes));
    }

    /**
     * Back off after the site's database refused a read or a write
     *
     * The step stops where it was, so nothing is skipped, and waits like
     * after a server error (1, 2, 4 … minutes), so a database that keeps
     * failing is not asked again every few seconds. The settings page says
     * why it waits.
     *
     * @return void
     */
    private function back_off_for_database() {
        // A code img.pro's error codes cannot take (they pass sanitize_key())
        $this->handle_error(new WP_Error('imgpro_cdn:database', ''), 'database');
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
        // The notices name the plan and its limit; show them as they are now
        delete_transient(ImgPro_CDN_Admin::USAGE_TRANSIENT);
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
        $state = self::get_state(true);
        $state['wait_until']       = 0;
        $state['last_error']       = '';
        $state['transient_streak'] = 0;
        $state['failing_files']    = 0;
        self::save_state($state);
        self::schedule_soon();
    }

    /**
     * Let new files cut short a wait that only failing uploads caused
     *
     * Files tried least go first, so new files are tried before the ones
     * that kept failing, and a run still backs off if they fail too. A wait
     * after an outage, a rate limit or a pause is left alone.
     *
     * @return void
     */
    public static function wake_for_new_files() {
        $state = self::get_state(true);
        if ('upload' === $state['wait_reason'] && (int) $state['wait_until'] > time()) {
            $state['wait_until'] = 0;
            self::save_state($state);
        }
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
        $state = $this->current_state();
        if (null === $state) {
            return ['more' => false];
        }

        $site   = ImgPro_CDN_Settings::get_site_label();
        $cursor = (string) $state['remove_cursor'];
        $skip   = array_flip(array_map('strval', (array) $state['remove_skip']));

        while (microtime(true) < $deadline) {
            $page = $api->list_images(['site' => $site], 100, '' !== $cursor ? $cursor : null);
            if (is_wp_error($page)) {
                if ('' !== $cursor && 'request' === self::classify_error($page)) {
                    // The cursor is no longer accepted; list from the start
                    $cursor = '';
                    continue;
                }
                $this->handle_error($page);
                return $this->stopped_result();
            }

            $ids = [];
            foreach ((array) ($page['data'] ?? []) as $image) {
                if (!empty($image['id']) && !isset($skip[(string) $image['id']])) {
                    $ids[] = (string) $image['id'];
                }
            }

            // Stop if the connection changed while the page loaded
            if (null === $this->current_state()) {
                return ['more' => false];
            }

            if (empty($ids)) {
                $next = $page['pagination']['next_cursor'] ?? null;
                if (!empty($page['pagination']['has_more']) && !empty($next)) {
                    // Only images that could not be deleted on this page
                    $cursor = (string) $next;
                    continue;
                }
                $this->finish_removal(count($skip));
                return ['more' => false];
            }

            list($outcome, $error) = $this->delete_ids($api, $ids, $deadline);

            foreach ($outcome as $id => $result) {
                if (true !== $result) {
                    $skip[(string) $id] = true;
                }
            }
            $remove_skip = array_map('strval', array_slice(array_keys($skip), 0, 1000));

            if ($error) {
                // Keep the images img.pro refused before the error stopped it
                $this->update_state(['remove_cursor' => '', 'remove_skip' => $remove_skip]);
                $this->handle_error($error);
                return $this->stopped_result();
            }

            // The listing shifted, so start again from the first page
            $cursor = '';
            $saved  = $this->update_state([
                'remove_cursor'    => '',
                'remove_skip'      => $remove_skip,
                'last_error'       => '',
                'transient_streak' => 0,
            ]);
            if (!$saved) {
                return ['more' => false];
            }
        }

        return ['more' => $this->update_state(['remove_cursor' => $cursor])];
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
     * Queued deletions are kept for a key to the same App. A key to
     * another App cannot delete them (img.pro answers every id in a batch
     * delete as deleted), so delete_step() lets those go and tells the
     * admin they stay in the old App.
     *
     * @return void
     */
    public function start() {
        ImgPro_CDN_Files::clear(true);

        // A new generation also tells a run still busy with the previous
        // connection to stop
        $state = self::default_state();
        // Part of every idempotency key (see random_hex())
        $state['generation'] = self::random_hex();
        self::save_state($state);

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
        // Not covered: a run already in its scan or matching step when this
        // resets them saves its own progress over the reset. That needs the
        // plugin deactivated and reactivated within one run (seconds), and
        // the next restart or reconnect scans again.
        $state = self::get_state(true);
        $state['backfill_cursor'] = 0;
        $state['backfill_retries'] = 0;
        $state['orphans_cursor']  = 0;
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
     * Asking to delete is a fresh attempt: a quota or App pause from
     * earlier syncing is cleared, since it may no longer apply and would
     * otherwise hold the deletion until the user guesses to resume. A key
     * problem stays, as the key needs replacing first.
     *
     * @return void
     */
    public function start_removal() {
        $update = [
            'removing' => true,
            'enabled'  => false,
        ];
        if (ImgPro_CDN_Settings::PAUSE_AUTH !== $this->settings->refresh()['pause_reason']) {
            $update['pause_reason'] = ImgPro_CDN_Settings::PAUSE_NONE;
            $update['pause_detail'] = '';
        }
        $this->settings->update($update);
        ImgPro_CDN_Files::flush_cache();
        $state = self::get_state(true);
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

        $waiting  = (int) $state['wait_until'] > time();
        $removing = (bool) $this->settings->get('removing');
        $blocked  = self::blocks_work($pause);

        if ($removing && !$blocked && !$waiting) {
            $phase = 'removing';
        } elseif (ImgPro_CDN_Settings::PAUSE_NONE !== $pause && (!$removing || $blocked)) {
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
            // Nothing queued. Not "idle", which is a file status: files
            // wait idle until a page shows them, so there is no finish line
            $phase = 'ready';
        }

        return [
            'phase'        => $phase,
            'removing'     => $removing,
            'pause_reason' => $pause,
            'pause_detail' => $this->settings->get('pause_detail'),
            'synced'       => $counts[ImgPro_CDN_Files::STATUS_SYNCED],
            'pending'      => $counts[ImgPro_CDN_Files::STATUS_PENDING],
            'idle'         => $counts[ImgPro_CDN_Files::STATUS_IDLE],
            'failed'       => $counts[ImgPro_CDN_Files::STATUS_FAILED],
            'skipped'      => $counts[ImgPro_CDN_Files::STATUS_SKIPPED],
            'deleting'     => $counts[ImgPro_CDN_Files::STATUS_DELETE],
            'remote'       => ImgPro_CDN_Files::count_remote_copies(),
            'wait'         => max(0, (int) $state['wait_until'] - time()),
            'wait_reason'  => (string) $state['wait_reason'],
            'last_error'   => (string) $state['last_error'],
        ];
    }

    /**
     * Default worker state
     *
     * The generation is empty until start() gives the connection one; it
     * is part of every upload's idempotency key, and a run stops when it
     * changes (see current_state()).
     *
     * @return array
     */
    private static function default_state() {
        return [
            'generation'       => '',
            'backfill_cursor'  => 0,
            'backfill_retries' => 0,
            'orphans_cursor'   => 0,
            'backfill_done'    => false,
            'adopt_cursor'     => '',
            'adopt_done'       => false,
            'wait_until'       => 0,
            'wait_reason'      => '',
            'transient_streak' => 0,
            'failing_files'    => 0,
            'canary_skip'      => [],
            'quota_checked_at' => 0,
            'last_error'       => '',
            'remove_cursor'    => '',
            'remove_skip'      => [],
            'confirmed_at'     => 0,
            'storage'          => '',
        ];
    }

    /**
     * Current worker state
     *
     * @param bool $fresh Read from the database, not this request's option
     *                    cache, to see changes other requests made since.
     * @return array
     */
    public static function get_state($fresh = false) {
        if ($fresh) {
            $notoptions = wp_cache_get('notoptions', 'options');
            if (is_array($notoptions) && isset($notoptions[self::STATE_OPTION])) {
                unset($notoptions[self::STATE_OPTION]);
                wp_cache_set('notoptions', $notoptions, 'options');
            }
            wp_cache_delete(self::STATE_OPTION, 'options');
        }

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
        $when = time() + max(0, (int) $delay);
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next && $next <= $when + 60) {
            return;
        }
        // A later run is moved, not joined: core refuses an event that
        // duplicates one less than ten minutes away. A run that comes too
        // early finds the wait in the state and schedules itself again.
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
        wp_schedule_single_event($when, self::CRON_HOOK);
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
     * The lock is a row in the options table, written with direct
     * statements so the database decides who holds it: the options API
     * answers from this request's cache, and its add_option() and
     * update_option() can each report success to two requests at once. Two
     * runs at once would upload the same files twice. The row stays; its
     * value is empty while free, and "time:random" while a run holds it.
     * One UPDATE claims it when free or abandoned, so exactly one run wins,
     * and the time is the database's, one clock for every web server. The
     * row is never deleted while in use, since inserts racing a delete on
     * the same key deadlock in InnoDB.
     *
     * @return bool
     */
    private function acquire_lock() {
        global $wpdb;

        $token = self::random_hex();

        for ($try = 0; $try < 2; $try++) {
            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = CONCAT(UNIX_TIMESTAMP(), ':', %s) WHERE option_name = %s AND (option_value = '' OR CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < UNIX_TIMESTAMP() - %d)",
                    $token,
                    self::LOCK_OPTION,
                    self::LOCK_TTL
                )
            );
            if (1 === $claimed) {
                $this->lock_token   = $token;
                $this->lock_stamped = microtime(true);
                if (!$this->release_on_shutdown) {
                    // A fatal error skips run()'s finally, not shutdown
                    // functions. Not when memory ran out or the process was
                    // killed, since WordPress's own fatal handler runs first:
                    // LOCK_TTL covers those.
                    $this->release_on_shutdown = true;
                    register_shutdown_function(
                        function () {
                            $this->release_lock();
                        }
                    );
                }
                return true;
            }

            // A live run holds it, or the row does not exist yet (the first
            // run): create it free, once, and claim it
            if ($try > 0 || null !== $wpdb->get_row($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION))) {
                return false;
            }
            // Runs starting together may all try; only one row is added, and
            // the losers' lock waits are expected, so they are not logged
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query(
                $wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '', %s)", self::LOCK_OPTION, 'no')
            );
            $wpdb->suppress_errors($suppress);
        }
        return false;
    }

    /**
     * Twelve random hex characters, for lock tokens and generations
     *
     * Not wp_generate_password(), which other plugins can filter: the value
     * must never hold the ':' that separates a lock's time from its token.
     * Unique, not secret, so a host without a random source still gets one.
     *
     * @return string
     */
    private static function random_hex() {
        try {
            return bin2hex(random_bytes(6));
        } catch (Exception $e) {
            return sprintf('%06x%06x', wp_rand(0, 0xffffff), wp_rand(0, 0xffffff));
        }
    }

    /**
     * Renew the lock while the run works, and tell whether it is still held
     *
     * Stamped again every third of LOCK_TTL, so a run that outlasts it
     * (a slow disk, a stalled database) is not taken for a dead one. A run
     * whose lock another run took over stops.
     *
     * @return bool False when another run holds the lock now.
     */
    private function keep_lock() {
        global $wpdb;

        // Ownership is checked only when restamping: another run can take
        // the lock over only once it is LOCK_TTL old, and this run restamps
        // well before that
        if ('' === $this->lock_token || microtime(true) - $this->lock_stamped < self::LOCK_TTL / 3) {
            return true;
        }

        $kept = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = CONCAT(UNIX_TIMESTAMP(), ':', %s) WHERE option_name = %s AND SUBSTRING_INDEX(option_value, ':', -1) = %s",
                $this->lock_token,
                self::LOCK_OPTION,
                $this->lock_token
            )
        );
        if (false === $kept) {
            // The database did not answer; the run's next write will tell
            return true;
        }
        // 0 rows also when the stamp did not change within the same second
        if (0 === $kept && null === $wpdb->get_row($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s AND SUBSTRING_INDEX(option_value, ':', -1) = %s", self::LOCK_OPTION, $this->lock_token))) {
            $this->lock_token = '';
            return false;
        }
        $this->lock_stamped = microtime(true);
        return true;
    }

    /**
     * Release the worker lock, unless another run took it over meanwhile
     *
     * @return void
     */
    private function release_lock() {
        global $wpdb;

        if ('' === $this->lock_token) {
            return;
        }
        $wpdb->query(
            $wpdb->prepare("UPDATE {$wpdb->options} SET option_value = '' WHERE option_name = %s AND SUBSTRING_INDEX(option_value, ':', -1) = %s", self::LOCK_OPTION, $this->lock_token)
        );
        $this->lock_token = '';
    }
}
