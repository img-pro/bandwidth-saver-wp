<?php
/**
 * ImgPro CDN File Map
 *
 * @package ImgPro_CDN
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Map of local upload files to their copies on img.pro
 *
 * One row per file WordPress generated (the full-size file and every
 * intermediate size), keyed by the path relative to the uploads folder.
 * Rows move through these statuses:
 *
 * - pending: waiting to be uploaded
 * - synced:  uploaded; `url` is served instead of the local file
 * - failed:  img.pro rejected the file or retries ran out
 * - skipped: format or size img.pro does not accept; stays on origin
 * - delete:  the local file is gone; its img.pro copy is queued for deletion
 *
 * Deletion rows have no file hash, so a new file can reuse the path
 * while the old copy is still waiting to be deleted.
 *
 * @since 2.0.0
 */
class ImgPro_CDN_Files {

    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED  = 'synced';
    const STATUS_FAILED  = 'failed';
    const STATUS_SKIPPED = 'skipped';
    const STATUS_DELETE  = 'delete';

    /**
     * Schema version, bumped when the table definition changes
     *
     * @var string
     */
    const DB_VERSION = '1';

    /**
     * Option holding the installed schema version
     *
     * @var string
     */
    const DB_VERSION_OPTION = 'imgpro_cdn_db_version';

    /**
     * Object cache group
     *
     * @var string
     */
    const CACHE_GROUP = 'imgpro_cdn_files';

    /**
     * Per-request cache of path => URL lookups (false = not synced)
     *
     * @var array
     */
    private static $url_cache = [];

    /**
     * Table name for the current site
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'imgpro_files';
    }

    /**
     * Create or upgrade the table when the schema version changed
     *
     * @return void
     */
    public static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        self::install();
    }

    /**
     * Create or upgrade the table
     *
     * @return void
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file varchar(1000) NOT NULL DEFAULT '',
  file_hash char(32) DEFAULT NULL,
  size_name varchar(100) NOT NULL DEFAULT '',
  file_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  imgpro_id varchar(64) DEFAULT NULL,
  url varchar(500) DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  error varchar(255) DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY file_hash (file_hash),
  KEY attachment_id (attachment_id),
  KEY status (status)
) {$charset_collate};");

        // Read on every request by maybe_install(), so autoload it
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
    }

    /**
     * Drop the table for the current site
     *
     * @return void
     */
    public static function drop() {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * Remove every row
     *
     * @param bool $keep_deletions Keep rows still waiting to delete an
     *                             img.pro copy, so they are not orphaned.
     * @return void
     */
    public static function clear($keep_deletions = false) {
        global $wpdb;
        $table = self::table();
        if ($keep_deletions) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE status <> %s', $table, self::STATUS_DELETE));
        } else {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $table));
        }
        self::flush_cache();
    }

    /**
     * Add this plugin's table to the ones dropped with a network site
     *
     * @param string[] $tables  Tables to drop.
     * @param int      $site_id Site being deleted.
     * @return string[]
     */
    public static function drop_site_table($tables, $site_id) {
        global $wpdb;
        $tables[] = $wpdb->get_blog_prefix($site_id) . 'imgpro_files';
        return $tables;
    }

    /**
     * Hash used to index a relative path
     *
     * @param string $file Path relative to the uploads folder.
     * @return string
     */
    public static function hash($file) {
        return md5($file);
    }

    /**
     * Look up img.pro URLs for a set of upload paths
     *
     * @param string[] $files Paths relative to the uploads folder.
     * @return array Map of path => img.pro URL, for synced files only.
     */
    public static function get_urls($files) {
        global $wpdb;

        $result  = [];
        $missing = [];

        foreach (array_unique(array_filter($files)) as $file) {
            if (array_key_exists($file, self::$url_cache)) {
                if (self::$url_cache[$file]) {
                    $result[$file] = self::$url_cache[$file];
                }
                continue;
            }
            $cached = wp_cache_get(self::hash($file), self::CACHE_GROUP);
            if (false !== $cached) {
                self::$url_cache[$file] = $cached;
                if ($cached) {
                    $result[$file] = $cached;
                }
                continue;
            }
            $missing[self::hash($file)] = $file;
        }

        if (empty($missing)) {
            return $result;
        }

        $table = self::table();
        foreach (array_chunk(array_keys($missing), 200) as $hashes) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT file, file_hash, url FROM %i WHERE status = %s AND file_hash IN (' . implode(',', array_fill(0, count($hashes), '%s')) . ')',
                    array_merge([$table, self::STATUS_SYNCED], $hashes)
                )
            );

            foreach ((array) $rows as $row) {
                if (isset($missing[$row->file_hash]) && $missing[$row->file_hash] === $row->file && $row->url) {
                    $result[$row->file] = $row->url;
                    self::$url_cache[$row->file] = $row->url;
                    wp_cache_set($row->file_hash, $row->url, self::CACHE_GROUP, HOUR_IN_SECONDS);
                    unset($missing[$row->file_hash]);
                }
            }
        }

        // Remember misses too, as an empty string
        foreach ($missing as $hash => $file) {
            self::$url_cache[$file] = '';
            wp_cache_set($hash, '', self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);
        }

        return $result;
    }

    /**
     * Forget cached lookups
     *
     * @param string[] $files Paths to forget, or empty for everything.
     * @return void
     */
    public static function flush_cache($files = []) {
        if (empty($files)) {
            self::$url_cache = [];
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group(self::CACHE_GROUP);
            }
            return;
        }
        foreach ($files as $file) {
            unset(self::$url_cache[$file]);
            wp_cache_delete(self::hash($file), self::CACHE_GROUP);
        }
    }

    /**
     * Files WordPress generated for an attachment
     *
     * Covers the full-size file (the "-scaled" copy when WordPress made
     * one) and every intermediate size. The untouched original kept next
     * to a "-scaled" file is not served by WordPress and is left out.
     *
     * @param int        $attachment_id Attachment ID.
     * @param array|null $metadata      Attachment metadata, or null to load it.
     * @return array Map of relative path => size name.
     */
    public static function get_attachment_files($attachment_id, $metadata = null) {
        if (null === $metadata) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }
        if (!is_array($metadata) || empty($metadata['file']) || !is_string($metadata['file'])) {
            return [];
        }

        $main = ltrim(str_replace('\\', '/', $metadata['file']), '/');
        // Absolute paths mean the file lives outside the uploads folder
        if (path_is_absolute($metadata['file'])) {
            return [];
        }

        $files = [$main => 'full'];
        $dir   = dirname($main);
        $dir   = ('.' === $dir) ? '' : $dir . '/';

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size) {
                if (empty($size['file']) || !is_string($size['file'])) {
                    continue;
                }
                $path = $dir . wp_basename($size['file']);
                if (!isset($files[$path])) {
                    $files[$path] = (string) $size_name;
                }
            }
        }

        return $files;
    }

    /**
     * Bring an attachment's rows in line with the files on disk
     *
     * New files are queued for upload, files that disappeared are queued
     * for deletion, and synced files whose size or modification time
     * changed (for example after regenerating thumbnails) are re-uploaded.
     *
     * @param int        $attachment_id Attachment ID.
     * @param array|null $metadata      Attachment metadata, or null to load it.
     * @return int Number of rows queued for upload.
     */
    public static function reconcile_attachment($attachment_id, $metadata = null) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return 0;
        }

        $desired  = self::get_attachment_files($attachment_id, $metadata);
        $basedir  = self::get_basedir();
        $table    = self::table();
        $queued   = 0;
        $existing = [];
        $now      = current_time('mysql', true);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE attachment_id = %d AND status <> %s',
                $table,
                $attachment_id,
                self::STATUS_DELETE
            )
        );
        foreach ((array) $rows as $row) {
            $existing[$row->file] = $row;
        }

        // Files that are no longer part of the attachment. WordPress keeps
        // the old files when an image is edited and posts may still embed
        // them, so their copies stay until the file itself is deleted.
        foreach ($existing as $file => $row) {
            if (!isset($desired[$file]) && !file_exists($basedir . '/' . $file)) {
                self::retire_row($row);
            }
        }

        foreach ($desired as $file => $size_name) {
            $path  = $basedir . '/' . $file;
            $bytes = file_exists($path) ? (int) filesize($path) : 0;
            $mtime = file_exists($path) ? (int) filemtime($path) : 0;

            if (isset($existing[$file])) {
                $row = $existing[$file];
                $unchanged = ((int) $row->file_bytes === $bytes && (int) $row->file_mtime === $mtime);
                if ($unchanged || !$bytes) {
                    continue;
                }
                // Same path, new contents: replace the img.pro copy
                self::retire_row($row);
            }

            // Another attachment may share this file (media translations
            // do). Its copy serves both, unless the file has changed.
            $owner = self::get_by_file($file);
            if ($owner) {
                if ((int) $owner->file_bytes === $bytes && (int) $owner->file_mtime === $mtime) {
                    continue;
                }
                self::retire_row($owner);
            }

            list($status, $error) = self::initial_status($file, $bytes);

            $wpdb->insert($table, [
                'attachment_id' => $attachment_id,
                'file'          => $file,
                'file_hash'     => self::hash($file),
                'size_name'     => substr($size_name, 0, 100),
                'file_bytes'    => $bytes,
                'file_mtime'    => $mtime,
                'status'        => $status,
                'error'         => $error,
                'updated_at'    => $now,
            ]);

            if (self::STATUS_PENDING === $status) {
                $queued++;
            }
        }

        self::flush_cache(array_merge(array_keys($desired), array_keys($existing)));

        return $queued;
    }

    /**
     * Status for a newly seen file
     *
     * @param string $file  Relative path.
     * @param int    $bytes File size in bytes (0 when missing).
     * @return array [status, error message or null]
     */
    private static function initial_status($file, $bytes) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($ext, ImgPro_CDN_API::UPLOADABLE_EXTENSIONS, true)) {
            return [self::STATUS_SKIPPED, __('img.pro does not accept this file format.', 'bandwidth-saver')];
        }
        if (!$bytes) {
            return [self::STATUS_SKIPPED, __('The file is missing from the uploads folder.', 'bandwidth-saver')];
        }
        if ($bytes > ImgPro_CDN_API::MAX_FILE_BYTES) {
            return [self::STATUS_SKIPPED, __('The file is larger than the 20 MB img.pro accepts.', 'bandwidth-saver')];
        }

        return [self::STATUS_PENDING, null];
    }

    /**
     * Take a row out of service
     *
     * Rows with an img.pro copy become deletion rows; the rest are removed.
     *
     * @param object $row Table row.
     * @return void
     */
    private static function retire_row($row) {
        global $wpdb;
        $table = self::table();

        if (!empty($row->imgpro_id)) {
            $wpdb->update(
                $table,
                [
                    'file_hash'  => null,
                    'status'     => self::STATUS_DELETE,
                    'attempts'   => 0,
                    'error'      => null,
                    'updated_at' => current_time('mysql', true),
                ],
                ['id' => (int) $row->id]
            );
        } else {
            $wpdb->delete($table, ['id' => (int) $row->id]);
        }

        self::flush_cache([$row->file]);
    }

    /**
     * Queue the img.pro copy of a file WordPress deleted
     *
     * @param string $file Path relative to the uploads folder.
     * @return bool Whether a row was retired.
     */
    public static function retire_file($file) {
        $row = self::get_by_file($file);
        if (!$row) {
            return false;
        }
        self::retire_row($row);
        return true;
    }

    /**
     * Retire rows whose attachment no longer exists and whose file is gone
     *
     * Catches attachments deleted while the plugin was inactive.
     *
     * @return int Rows retired.
     */
    public static function retire_orphans() {
        global $wpdb;
        $table   = self::table();
        $basedir = self::get_basedir();
        $retired = 0;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT f.* FROM %i f LEFT JOIN %i p ON p.ID = f.attachment_id WHERE p.ID IS NULL AND f.status <> %s',
                $table,
                $wpdb->posts,
                self::STATUS_DELETE
            )
        );
        foreach ((array) $rows as $row) {
            if (!file_exists($basedir . '/' . $row->file)) {
                self::retire_row($row);
                $retired++;
            }
        }

        return $retired;
    }

    /**
     * Rows waiting for upload, oldest first
     *
     * @param int $limit Maximum rows.
     * @return object[]
     */
    public static function get_pending($limit) {
        global $wpdb;
        $table = self::table();

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $table,
                self::STATUS_PENDING,
                (int) $limit
            )
        );
    }

    /**
     * Rows whose img.pro copy should be deleted
     *
     * @param int $limit Maximum rows.
     * @return object[]
     */
    public static function get_deletions($limit) {
        global $wpdb;
        $table = self::table();

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d',
                $table,
                self::STATUS_DELETE,
                (int) $limit
            )
        );
    }

    /**
     * Find a live row by relative path
     *
     * @param string $file Relative path.
     * @return object|null
     */
    public static function get_by_file($file) {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE file_hash = %s', $table, self::hash($file))
        );

        return ($row && $row->file === $file) ? $row : null;
    }

    /**
     * Record a successful upload
     *
     * The row may have been retired by another request (the attachment was
     * deleted or regenerated) while the file uploaded. The new copy is then
     * queued for deletion instead of being left untracked in the App.
     *
     * @param object $row Table row.
     * @param string $imgpro_id img.pro image id.
     * @param string $url       img.pro image URL.
     * @return bool Whether the row was still pending and is now synced.
     */
    public static function mark_synced($row, $imgpro_id, $url) {
        global $wpdb;

        $updated = $wpdb->update(
            self::table(),
            [
                'status'     => self::STATUS_SYNCED,
                'imgpro_id'  => substr((string) $imgpro_id, 0, 64),
                'url'        => esc_url_raw($url),
                'attempts'   => 0,
                'error'      => null,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => (int) $row->id, 'status' => self::STATUS_PENDING]
        );
        self::flush_cache([$row->file]);

        if (!$updated) {
            self::queue_deletion($imgpro_id, $row);
            return false;
        }
        return true;
    }

    /**
     * Queue an img.pro image that no row tracks for deletion
     *
     * @param string $imgpro_id img.pro image id.
     * @param object $row       Row the image was uploaded for.
     * @return void
     */
    public static function queue_deletion($imgpro_id, $row) {
        global $wpdb;

        $wpdb->insert(self::table(), [
            'attachment_id' => (int) $row->attachment_id,
            'file'          => (string) $row->file,
            'file_hash'     => null,
            'status'        => self::STATUS_DELETE,
            'imgpro_id'     => substr((string) $imgpro_id, 0, 64),
            'updated_at'    => current_time('mysql', true),
        ]);
    }

    /**
     * Count an upload attempt before it starts
     *
     * A request that dies mid-upload (memory or time limit) never reaches
     * the error handling, so the attempt is recorded first. Otherwise one
     * such file would be retried first on every run, forever.
     *
     * @param object $row Table row.
     * @return void
     */
    public static function note_attempt($row) {
        self::set_attempts($row, (int) $row->attempts + 1);
    }

    /**
     * Set a row's attempt count
     *
     * @param object $row      Table row.
     * @param int    $attempts Attempts.
     * @return void
     */
    public static function set_attempts($row, $attempts) {
        global $wpdb;
        $wpdb->update(self::table(), ['attempts' => max(0, (int) $attempts)], ['id' => (int) $row->id]);
    }

    /**
     * Record a file's current size and modification time
     *
     * @param object $row   Table row, updated in place.
     * @param int    $bytes File size.
     * @param int    $mtime Modification time.
     * @return void
     */
    public static function update_file_stats($row, $bytes, $mtime) {
        global $wpdb;
        $wpdb->update(self::table(), ['file_bytes' => (int) $bytes, 'file_mtime' => (int) $mtime], ['id' => (int) $row->id]);
        $row->file_bytes = (int) $bytes;
        $row->file_mtime = (int) $mtime;
    }

    /**
     * Record a failed attempt
     *
     * @param object $row       Table row.
     * @param string $error     Error message.
     * @param bool   $permanent Whether retrying cannot help.
     * @param int    $max_attempts Attempts before giving up.
     * @return void
     */
    public static function mark_attempt_failed($row, $error, $permanent, $max_attempts = 5) {
        global $wpdb;

        $attempts = (int) $row->attempts + 1;
        $status   = ($permanent || $attempts >= $max_attempts) ? self::STATUS_FAILED : $row->status;

        $wpdb->update(
            self::table(),
            [
                'status'     => $status,
                'attempts'   => $attempts,
                'error'      => substr(sanitize_text_field($error), 0, 255),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => (int) $row->id]
        );
    }

    /**
     * Record a file that cannot be uploaded
     *
     * @param object $row   Table row.
     * @param string $error Reason shown in the admin.
     * @return void
     */
    public static function mark_skipped($row, $error) {
        global $wpdb;

        $wpdb->update(
            self::table(),
            [
                'status'     => self::STATUS_SKIPPED,
                'error'      => substr(sanitize_text_field($error), 0, 255),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => (int) $row->id]
        );
    }

    /**
     * Delete rows by ID
     *
     * @param int[] $ids Row IDs.
     * @return void
     */
    public static function delete_rows($ids) {
        global $wpdb;
        $ids = array_filter(array_map('absint', (array) $ids));
        if (empty($ids)) {
            return;
        }

        $table = self::table();
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
                array_merge([$table], array_values($ids))
            )
        );
    }

    /**
     * Queue failed files for another try
     *
     * Rows are re-created so each retry gets a fresh idempotency key.
     *
     * @return int Number of rows requeued.
     */
    public static function retry_failed() {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE status = %s', $table, self::STATUS_FAILED),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            self::requeue((object) $row, 0);
        }

        return count((array) $rows);
    }

    /**
     * Queue a row again under a new ID, which gives it a new idempotency key
     *
     * @param object $row      Table row.
     * @param int    $attempts Attempt count to carry over.
     * @return void
     */
    public static function requeue($row, $attempts) {
        global $wpdb;
        $table = self::table();

        $data = (array) $row;
        $wpdb->delete($table, ['id' => (int) $data['id']]);
        unset($data['id']);
        $data['status']     = self::STATUS_PENDING;
        $data['attempts']   = (int) $attempts;
        $data['error']      = null;
        $data['updated_at'] = current_time('mysql', true);
        $wpdb->insert($table, $data);
    }

    /**
     * Row counts per status
     *
     * @return array status => count
     */
    public static function get_counts() {
        global $wpdb;
        $table = self::table();

        $counts = array_fill_keys(
            [self::STATUS_PENDING, self::STATUS_SYNCED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_DELETE],
            0
        );

        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table));
        foreach ((array) $rows as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Most recent failures, for the admin page
     *
     * @param int $limit Maximum rows.
     * @return object[]
     */
    public static function get_recent_failures($limit = 5) {
        global $wpdb;
        $table = self::table();

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT file, error FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d',
                $table,
                self::STATUS_FAILED,
                (int) $limit
            )
        );
    }

    /**
     * Absolute path of the uploads folder, without trailing slash
     *
     * @return string
     */
    public static function get_basedir() {
        $uploads = wp_get_upload_dir();
        return untrailingslashit(str_replace('\\', '/', $uploads['basedir']));
    }
}
