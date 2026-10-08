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
 * Images pages load from other websites get rows too (`remote`), keyed by
 * their URL; img.pro imports those from their address. Rows move through
 * these statuses:
 *
 * - idle:    not copied, and nothing asked for it yet
 * - pending: waiting to be uploaded
 * - synced:  uploaded; `url` is served instead of the local file
 * - failed:  img.pro rejected the file or retries ran out
 * - skipped: format or size img.pro does not accept; stays on origin
 * - delete:  the local file is gone; its img.pro copy is queued for deletion
 *
 * Files are copied on demand: a file stays idle until a page shows it
 * (see get_urls()) or someone copies it from the Media Library, so files
 * no page uses never count against the App's image limit.
 *
 * Deletion rows have no file hash, so a new file can reuse the path
 * while the old copy is still waiting to be deleted.
 *
 * The table is the plugin's own, so it is read and written with $wpdb.
 * Only lookups are cached (see lookup()): the rest serve a queue that the
 * worker, AJAX steps and page views change at the same time, where a stale
 * read would upload a file twice or bring back a retired row. Writes that
 * change what a lookup returns call flush_cache(); the others leave a file
 * not copied, which a cached lookup already says.
 *
 * @since 2.0.0
 */
class ImgPro_CDN_Files {

    const STATUS_IDLE    = 'idle';
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED  = 'synced';
    const STATUS_FAILED  = 'failed';
    const STATUS_SKIPPED = 'skipped';
    const STATUS_DELETE  = 'delete';

    /**
     * Reasons the plugin itself gives for a file that is skipped or failed
     *
     * Stored as codes (see reason()) and worded when shown (see
     * error_text()), so each admin reads them in their own language.
     * Reasons img.pro gives are stored as img.pro worded them.
     */
    const REASON_FORMAT       = 'format';
    const REASON_MISSING      = 'missing';
    const REASON_TOO_LARGE    = 'too_large';
    const REASON_ANIMATED_PNG = 'animated_png';
    const REASON_STALLED      = 'stalled';
    const REASON_MEMORY       = 'memory';
    const REASON_UNEXPECTED   = 'unexpected';
    const REASON_UNREADABLE   = 'unreadable';
    const REASON_IMPORT_STALLED = 'import_stalled';
    const REASON_HTTP         = 'http_';
    const REASON_BLOCKED      = 'blocked';
    const REASON_PROCESSING   = 'processing';

    /**
     * Prefix marking a stored reason code
     *
     * @var string
     */
    const REASON_PREFIX = 'bs:';

    /**
     * Schema version, bumped when the table definition changes
     *
     * @var string
     */
    const DB_VERSION = '8';

    /**
     * Option holding the installed schema version
     *
     * @var string
     */
    const DB_VERSION_OPTION = 'imgpro_cdn_db_version';

    /**
     * Set for an hour when the table could not be brought up to this schema
     *
     * @var string
     */
    const INSTALL_RETRY_TRANSIENT = 'imgpro_cdn_install_retry';

    /**
     * Columns the code reads, all of which must exist to record the version
     *
     * @var string[]
     */
    const COLUMNS = ['id', 'attachment_id', 'file', 'file_hash', 'size_name', 'file_bytes', 'file_mtime', 'status', 'priority', 'remote', 'imgpro_id', 'url', 'attempts', 'retries', 'sent_at', 'error', 'updated_at'];

    /**
     * Age at which a row's idempotency key is replaced before it is sent again
     *
     * img.pro keeps a key's state for 24 hours; this leaves a margin.
     *
     * @var int
     */
    const KEY_RENEW_SECONDS = 20 * HOUR_IN_SECONDS;

    /**
     * Object cache group
     *
     * @var string
     */
    const CACHE_GROUP = 'imgpro_cdn_files';

    /**
     * Per-request cache of URL lookups, by site ID then path ('' = not synced)
     *
     * @var array
     */
    private static $url_cache = [];

    /**
     * Sites whose table this request already brought up to this schema
     *
     * @var bool[]
     */
    private static $installed = [];

    /**
     * Images from other websites this request queued
     *
     * @var int
     */
    private static $remote_added = 0;

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
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION || get_transient(self::INSTALL_RETRY_TRANSIENT)) {
            return;
        }
        self::install(true);
    }

    /**
     * Create or upgrade the table
     *
     * Requests that start together after an update all get here. One
     * changes the table while the others wait for it, then find the work
     * done, rather than repeat each ALTER and log its failure. (If the
     * change fails, requests already waiting try it once each; requests
     * after that skip it for an hour, see maybe_install().) The wait is
     * short, well inside PHP's time limit: a request that waited in vain
     * carries on and changes the table itself, since a logged error is
     * better than running on the old schema. The version is recorded once
     * every column the code reads exists (a missing index only slows
     * queries); otherwise the change is tried again an hour later, not on
     * every request, so a database user without ALTER rights is not logged
     * all the time.
     *
     * @param bool $if_needed Only when the recorded version is older, read
     *                        again once the lock is held (maybe_install()).
     *                        Otherwise the table is checked anyway, as on
     *                        activation, unless this request already did.
     * @return void
     */
    public static function install($if_needed = false) {
        global $wpdb;

        $table = self::table();
        if (isset(self::$installed[$table])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $lock   = 'imgpro_cdn_install_' . md5(DB_NAME . '|' . $table);
        $locked = 1 === (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 10));

        try {
            $current = $if_needed && self::DB_VERSION === $wpdb->get_var(
                $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::DB_VERSION_OPTION)
            );
            if (!$current) {
                self::create_table($table, $wpdb->get_charset_collate());
                $columns = (array) $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
                if (array_diff(self::COLUMNS, $columns)) {
                    set_transient(self::INSTALL_RETRY_TRANSIENT, 1, HOUR_IN_SECONDS);
                } else {
                    // Read on every request by maybe_install(), so autoload it
                    update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
                    delete_transient(self::INSTALL_RETRY_TRANSIENT);
                }
            }
            self::$installed[$table] = true;
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * Create the table, or bring it up to this schema
     *
     * @param string $table           Table name.
     * @param string $charset_collate Table charset and collation.
     * @return void
     */
    private static function create_table($table, $charset_collate) {
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file varchar(1000) NOT NULL DEFAULT '',
  file_hash char(32) DEFAULT NULL,
  size_name varchar(100) NOT NULL DEFAULT '',
  file_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  priority tinyint(1) unsigned NOT NULL DEFAULT 0,
  remote tinyint(1) unsigned NOT NULL DEFAULT 0,
  imgpro_id varchar(64) DEFAULT NULL,
  url varchar(500) DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  retries smallint(5) unsigned NOT NULL DEFAULT 0,
  sent_at datetime DEFAULT NULL,
  error varchar(255) DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY file_hash (file_hash),
  KEY attachment_id (attachment_id),
  KEY queue (status,attempts,priority),
  KEY status_updated (status,updated_at),
  KEY remote (remote,status),
  KEY imgpro_id (imgpro_id)
) {$charset_collate};");
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
        // One statement each, so the map is never left half cleared;
        // flush_cache() below retires every cached lookup
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
     * This is also how files get copied on demand: the rewriter looks up
     * every media URL a page shows, and idle files among them are queued
     * for upload. The page keeps their server URLs until the copies exist.
     *
     * @param string[] $files Paths relative to the uploads folder.
     * @return array Map of path => img.pro URL, for synced files only.
     */
    public static function get_urls($files) {
        return self::lookup($files, false);
    }

    /**
     * Look up img.pro URLs for images on other websites
     *
     * Pages can show images from other addresses: an old domain, a picture
     * inserted from a URL, another site's image. img.pro imports such an
     * image from its address, so the first page that shows one queues the
     * import, and the page keeps the original address until the copy exists.
     *
     * @param string[] $urls      Absolute http(s) URLs, at most 1,000 characters.
     * @param bool     $queue_new Whether images not seen before may be
     *                            queued (not from a preview, say).
     * @return array Map of URL => img.pro URL, for copied images only.
     */
    public static function get_remote_urls($urls, $queue_new = true) {
        return self::lookup($urls, true, $queue_new);
    }

    /**
     * Look up copies, and queue the files a page needs that are not copied
     *
     * @param string[] $keys      Upload paths, or URLs of other websites' images.
     * @param bool     $remote    Whether the keys are URLs.
     * @param bool     $queue_new Whether URLs without a row may get one.
     * @return array Map of key => img.pro URL, for copied files only.
     */
    private static function lookup($keys, $remote, $queue_new = false) {
        global $wpdb;

        $result  = [];
        $missing = [];
        $blog_id = get_current_blog_id();
        $version = self::cache_version();

        if (!isset(self::$url_cache[$blog_id])) {
            self::$url_cache[$blog_id] = [];
        }
        $local = &self::$url_cache[$blog_id];

        foreach (array_unique(array_filter(array_map('strval', (array) $keys))) as $key) {
            if (array_key_exists($key, $local)) {
                if ($local[$key]) {
                    $result[$key] = $local[$key];
                }
                continue;
            }
            $cached = wp_cache_get(self::cache_key($key, $version), self::CACHE_GROUP);
            if (false !== $cached) {
                $local[$key] = $cached;
                if ($cached) {
                    $result[$key] = $cached;
                }
                continue;
            }
            $missing[self::hash($key)] = $key;
        }

        if (empty($missing)) {
            return $result;
        }

        $table  = self::table();
        $wanted = [];
        $known  = [];
        foreach (array_chunk(array_keys($missing), 200) as $hashes) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT file, file_hash, status, url, attempts, updated_at FROM %i WHERE remote = %d AND file_hash IN (' . implode(',', array_fill(0, count($hashes), '%s')) . ')',
                    array_merge([$table, $remote ? 1 : 0], $hashes)
                )
            );

            foreach ((array) $rows as $row) {
                if (!isset($missing[$row->file_hash]) || $missing[$row->file_hash] !== $row->file) {
                    continue;
                }
                $known[$row->file_hash] = true;
                if (self::STATUS_IDLE === $row->status) {
                    if (!$remote || self::retry_due($row)) {
                        $wanted[] = $row->file_hash;
                    }
                    continue;
                }
                if (self::STATUS_SYNCED === $row->status && $row->url) {
                    $result[$row->file] = $row->url;
                    $local[$row->file] = $row->url;
                    wp_cache_set(self::cache_key($row->file, $version), $row->url, self::CACHE_GROUP, HOUR_IN_SECONDS);
                    unset($missing[$row->file_hash]);
                }
            }
        }

        // An image from another website gets its row the first time a page
        // shows it, up to a number per request, so one page cannot fill the App
        $queued = 0;
        $new    = $remote && $queue_new ? array_slice(array_diff_key($missing, $known), 0, max(0, ImgPro_CDN_Rewriter::REMOTE_PER_REQUEST - self::$remote_added), true) : [];
        if (!empty($new)) {
            $added               = self::add_remote($new);
            self::$remote_added += count($new);
            $queued             += $added;
        }

        // Remember misses too, as an empty string. Images left unqueued (a
        // preview, or past the request's share) are not remembered, so the
        // next published view can queue them.
        foreach ($missing as $hash => $key) {
            if ($remote && !isset($known[$hash]) && !isset($new[$hash])) {
                continue;
            }
            $local[$key] = '';
            wp_cache_set(self::cache_key($key, $version), '', self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);
        }

        if (!empty($wanted)) {
            $queued += self::queue_by_hash($wanted);
        }
        if ($queued > 0) {
            ImgPro_CDN_Sync::wake_for_new_files();
            ImgPro_CDN_Sync::schedule_soon();
        }

        return $result;
    }

    /**
     * Whether an image from another website may be tried again
     *
     * An import that timed out waits before a page may queue it again: 5
     * minutes after the first try, then 15, 45 and 135. The wait is kept on
     * the row, so it holds without a persistent object cache too.
     *
     * @param object $row Row with attempts and updated_at.
     * @return bool
     */
    private static function retry_due($row) {
        $attempts = (int) $row->attempts;
        if ($attempts < 1) {
            return true;
        }
        $wait = 5 * MINUTE_IN_SECONDS * (3 ** min(4, $attempts - 1));
        return strtotime((string) $row->updated_at . ' UTC') <= time() - $wait;
    }

    /**
     * Queue images from other websites for import
     *
     * Another request may queue the same image at the same moment; the
     * unique hash keeps one row.
     *
     * @param string[] $urls Map of hash => URL.
     * @return int Rows added.
     */
    private static function add_remote($urls) {
        global $wpdb;

        $added = 0;
        $now   = current_time('mysql', true);
        foreach ($urls as $hash => $url) {
            $added += (int) $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO %i (attachment_id, file, file_hash, status, priority, remote, updated_at) VALUES (%d, %s, %s, %s, %d, %d, %s)',
                    self::table(),
                    0,
                    $url,
                    $hash,
                    self::STATUS_PENDING,
                    0,
                    1,
                    $now
                )
            );
        }
        return $added;
    }

    /**
     * Queue idle files for upload
     *
     * Leaves the lookup cache alone: idle and queued files both look up as
     * not copied, and mark_synced() flushes a file once its copy exists.
     *
     * @param string[] $hashes File hashes.
     * @return int Files queued.
     */
    private static function queue_by_hash($hashes) {
        global $wpdb;

        $queued = 0;
        foreach (array_chunk(array_values(array_unique($hashes)), 200) as $chunk) {
            $queued += (int) $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, updated_at = %s WHERE status = %s AND file_hash IN (' . implode(',', array_fill(0, count($chunk), '%s')) . ')',
                    array_merge([self::table(), self::STATUS_PENDING, current_time('mysql', true), self::STATUS_IDLE], $chunk)
                )
            );
        }
        return $queued;
    }

    /**
     * Forget cached lookups
     *
     * Forgetting everything moves lookups to a new cache version instead of
     * flushing the group, which many persistent object caches cannot do.
     * Entries of the old version expire on their own.
     *
     * @param string[] $files Paths to forget, or empty for everything.
     * @return void
     */
    public static function flush_cache($files = []) {
        $blog_id = get_current_blog_id();

        if (empty($files)) {
            unset(self::$url_cache[$blog_id]);
            wp_cache_set('last_changed', microtime(), self::CACHE_GROUP);
            return;
        }

        $version = self::cache_version();
        foreach ($files as $file) {
            unset(self::$url_cache[$blog_id][$file]);
            wp_cache_delete(self::cache_key($file, $version), self::CACHE_GROUP);
        }
    }

    /**
     * Current version of the lookup cache
     *
     * @return string
     */
    private static function cache_version() {
        return (string) wp_cache_get_last_changed(self::CACHE_GROUP);
    }

    /**
     * Object cache key of a path's lookup
     *
     * @param string $file    Path relative to the uploads folder.
     * @param string $version Result of cache_version().
     * @return string
     */
    private static function cache_key($file, $version) {
        return self::hash($file) . ':' . $version;
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
        $main = (is_array($metadata) && !empty($metadata['file']) && is_string($metadata['file'])) ? $metadata['file'] : '';
        if ('' === $main) {
            // Core makes no metadata for some images (SVG), and making it can
            // fail; the attached file is still the full size
            $main = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        }
        // Absolute paths mean the file lives outside the uploads folder
        if ('' === $main || path_is_absolute($main)) {
            return [];
        }

        // Normalized like the paths of deleted files and of URLs (backslashes,
        // duplicate slashes), so all three name a file the same way
        $main = ltrim(wp_normalize_path($main), '/');

        $files = [$main => 'full'];
        $dir   = dirname($main);
        $dir   = ('.' === $dir) ? '' : $dir . '/';

        if (is_array($metadata) && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
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
     * New files wait, idle, until a page shows them; files that
     * disappeared are queued for deletion; and files in use whose size or
     * modification time changed (for example after regenerating
     * thumbnails) are queued to be uploaded again.
     *
     * @param int        $attachment_id Attachment ID.
     * @param array|null $metadata      Attachment metadata, or null to load it.
     * @param bool|null  $failed        Set to whether the database refused a
     *                                  file's row, even on a second try.
     *                                  Reconciling is safe to repeat, which
     *                                  records that file again.
     * @return int Number of rows queued for upload.
     */
    public static function reconcile_attachment($attachment_id, $metadata = null, &$failed = null) {
        global $wpdb;

        $failed        = false;
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

        // Another attachment may share a file (media translations do); look
        // up the owners of all the attachment's new files at once
        $owners = self::get_by_files(array_diff(array_keys($desired), array_keys($existing)));

        foreach ($desired as $file => $size_name) {
            $path  = $basedir . '/' . $file;
            $bytes = file_exists($path) ? (int) filesize($path) : 0;
            $mtime = file_exists($path) ? (int) filemtime($path) : 0;

            // A row for the file, whether this attachment's or another's
            $row    = isset($existing[$file]) ? $existing[$file] : ($owners[$file] ?? null);
            $in_use = false;
            if ($row) {
                // A missing file may be mid-replace or briefly unreadable,
                // so its copy stays until the file is back or deleted. An
                // unchanged file keeps its copy, which serves every owner.
                $unchanged = ((int) $row->file_bytes === $bytes && (int) $row->file_mtime === $mtime);
                if ($unchanged || !$bytes) {
                    continue;
                }
                // Same path, new contents: replace the img.pro copy. A file
                // that was copied or queued is in use, so its new version
                // is queued at once instead of waiting for a page.
                $in_use = in_array($row->status, [self::STATUS_SYNCED, self::STATUS_PENDING], true);
                self::retire_row($row);
            }

            list($status, $error) = self::initial_status($file, $bytes);
            if ($in_use && self::STATUS_IDLE === $status) {
                $status = self::STATUS_PENDING;
            }

            // Two requests can reconcile the same attachment at once (the
            // worker's scan, an upload's shutdown, a Media Library copy).
            // The unique hash keeps the row the first one inserted; the
            // second inserts nothing and logs nothing. A failed insert (a
            // deadlock, say) is tried once more.
            for ($try = 0; $try < 2; $try++) {
                $inserted = $wpdb->query(
                    $wpdb->prepare(
                        'INSERT INTO %i (attachment_id, file, file_hash, size_name, file_bytes, file_mtime, status, error, updated_at) VALUES (%d, %s, %s, %s, %d, %d, %s, NULLIF(%s, %s), %s) ON DUPLICATE KEY UPDATE id = id',
                        $table,
                        $attachment_id,
                        $file,
                        self::hash($file),
                        self::clip($size_name, 100),
                        $bytes,
                        $mtime,
                        $status,
                        null === $error ? '' : self::clip($error, 255),
                        '',
                        $now
                    )
                );
                if (false !== $inserted) {
                    break;
                }
            }
            if (false === $inserted) {
                $failed = true;
                continue;
            }

            if (self::STATUS_PENDING === $status) {
                // The other request may not have known the file is in use
                // and left it idle: queue it, as this request meant to. Not
                // decided by the row count, which a lost race reports as 1
                // on connections with the found-rows flag.
                $promoted = self::queue_by_hash([self::hash($file)]);
                if (1 === (int) $inserted || $promoted > 0) {
                    $queued++;
                }
            }
        }

        // No cache flush needed: retire_row() flushes the files it retires,
        // and new rows are never synced, so lookups of them are unchanged

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
            return [self::STATUS_SKIPPED, self::reason(self::REASON_FORMAT)];
        }
        if (!$bytes) {
            return [self::STATUS_SKIPPED, self::reason(self::REASON_MISSING)];
        }
        if ($bytes > ImgPro_CDN_API::MAX_FILE_BYTES) {
            return [self::STATUS_SKIPPED, self::reason(self::REASON_TOO_LARGE)];
        }
        if ('png' === $ext && self::is_animated_png(self::get_basedir() . '/' . $file)) {
            return [self::STATUS_SKIPPED, self::reason(self::REASON_ANIMATED_PNG)];
        }

        // Copied on demand: see get_urls()
        return [self::STATUS_IDLE, null];
    }

    /**
     * A reason code as stored in the error column
     *
     * @param string $code REASON_* constant.
     * @return string
     */
    public static function reason($code) {
        return self::REASON_PREFIX . $code;
    }

    /**
     * The error stored for a file, worded for the current user
     *
     * @param string|null $error Stored error: a reason code, or img.pro's own words.
     * @return string
     */
    public static function error_text($error) {
        $error = (string) $error;
        if (0 !== strpos($error, self::REASON_PREFIX)) {
            return $error;
        }

        switch (substr($error, strlen(self::REASON_PREFIX))) {
            case self::REASON_FORMAT:
                return __('img.pro does not accept this file format.', 'bandwidth-saver');
            case self::REASON_MISSING:
                return __('The file is missing from the uploads folder.', 'bandwidth-saver');
            case self::REASON_TOO_LARGE:
                return __('The file is larger than the 20 MB img.pro accepts.', 'bandwidth-saver');
            case self::REASON_ANIMATED_PNG:
                return __('Animated PNGs stay on your server, because img.pro shows PNGs as still images.', 'bandwidth-saver');
            case self::REASON_STALLED:
                return __('The upload kept stopping before it finished. The file may be too large for this server\'s memory or time limits.', 'bandwidth-saver');
            case self::REASON_MEMORY:
                return __('This server\'s PHP memory limit is too low to upload this file.', 'bandwidth-saver');
            case self::REASON_UNEXPECTED:
                return __('img.pro returned an unexpected response.', 'bandwidth-saver');
            case self::REASON_UNREADABLE:
                return __('The file could not be read from disk.', 'bandwidth-saver');
            case self::REASON_IMPORT_STALLED:
                return __('Copying this image from its website kept stopping before it finished.', 'bandwidth-saver');
            case self::REASON_BLOCKED:
                return __('img.pro blocked this image, so it loads from its original address.', 'bandwidth-saver');
            case self::REASON_PROCESSING:
                return __('img.pro could not process this image, so it loads from its original address.', 'bandwidth-saver');
        }
        $code = substr($error, strlen(self::REASON_PREFIX));
        if (0 === strpos($code, self::REASON_HTTP)) {
            /* translators: %d: HTTP status code */
            return sprintf(__('img.pro returned HTTP %d.', 'bandwidth-saver'), (int) substr($code, strlen(self::REASON_HTTP)));
        }
        return '';
    }

    /**
     * Whether img.pro accepts a file, judging by its name
     *
     * @param string $file Path or URL.
     * @return bool
     */
    public static function has_uploadable_extension($file) {
        $path = (string) wp_parse_url((string) $file, PHP_URL_PATH);
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ImgPro_CDN_API::UPLOADABLE_EXTENSIONS, true);
    }

    /**
     * Whether a PNG file is animated (APNG)
     *
     * img.pro serves a re-encoded copy of a PNG that keeps only its first
     * frame, so an animated one would stop moving. An APNG declares its
     * animation in an acTL chunk before the first image data, so only the
     * start of the file is read.
     *
     * @param string $path Absolute path.
     * @return bool
     */
    private static function is_animated_png($path) {
        $head = is_readable($path) ? file_get_contents($path, false, null, 0, 65536) : false;
        if (!is_string($head) || 0 !== strpos($head, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        // Walk the chunks: 4-byte length, 4-byte type, data, 4-byte CRC
        $offset = 8;
        while ($offset + 8 <= strlen($head)) {
            $chunk = unpack('Nlength/a4type', substr($head, $offset, 8));
            if ('acTL' === $chunk['type']) {
                return true;
            }
            if ('IDAT' === $chunk['type']) {
                return false;
            }
            $offset += 12 + $chunk['length'];
        }
        return false;
    }

    /**
     * Take a row out of service
     *
     * Rows with an img.pro copy become deletion rows; the rest are removed.
     * Decided by the row as stored, not as the caller read it: the worker
     * may have copied the file since, and that copy must be queued for
     * deletion rather than forgotten in the App.
     *
     * Only the worker changes a row's copy meanwhile: mark_synced() gives it
     * one, and mark_lost() or mark_processing_failed() takes it away. Removing first means a removed row
     * never becomes synced (the worker then queues its new copy for
     * deletion). When neither statement matched, the copy changed between
     * them, so they run again; a row already gone or retired matches
     * neither every time and is left as it is.
     *
     * Not covered: an upload whose answer never arrived is known only to
     * its idempotency key, so retiring its row forgets that copy. The
     * matching step after a reconnect deletes it while a row still names
     * the file; otherwise it stays in the App.
     *
     * @param object $row Table row (its ID and file are used).
     * @return void
     */
    private static function retire_row($row) {
        global $wpdb;
        $table = self::table();

        for ($pass = 0; $pass < 3; $pass++) {
            if ($wpdb->query($wpdb->prepare('DELETE FROM %i WHERE id = %d AND imgpro_id IS NULL', $table, (int) $row->id))) {
                break;
            }
            $converted = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET file_hash = NULL, status = %s, attempts = 0, error = NULL, updated_at = %s WHERE id = %d AND imgpro_id IS NOT NULL AND status <> %s',
                    $table,
                    self::STATUS_DELETE,
                    current_time('mysql', true),
                    (int) $row->id,
                    self::STATUS_DELETE
                )
            );
            if ($converted) {
                break;
            }
            if (!$wpdb->get_var($wpdb->prepare('SELECT 1 FROM %i WHERE id = %d AND status <> %s', $table, (int) $row->id, self::STATUS_DELETE))) {
                break;
            }
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
     * Catches attachments deleted while the plugin was inactive. Works
     * through the table in ID order, returning at most 500 rows a query so
     * memory stays flat, and stops at the deadline. Rows from other websites
     * and deletion rows between them are skipped by the scan, not returned.
     * Rows are paged before they are matched to attachments: paging only
     * orphans would read the whole rest of the table to fill a page when
     * there are few.
     *
     * @param int   $after    Row ID to resume after (0 to start).
     * @param float $deadline Unix time to stop at.
     * @return int|null|false Row ID to resume after next time, null once
     *                        every row was checked, or false when the
     *                        database refused a query (resume from $after).
     */
    public static function retire_orphans($after, $deadline) {
        global $wpdb;
        $table   = self::table();
        $basedir = self::get_basedir();
        $after   = (int) $after;
        $page    = 500;

        do {
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT f.id, f.file, p.ID AS post_id FROM %i f LEFT JOIN %i p ON p.ID = f.attachment_id WHERE f.id > %d AND f.status <> %s AND f.remote = %d ORDER BY f.id ASC LIMIT %d',
                    $table,
                    $wpdb->posts,
                    $after,
                    self::STATUS_DELETE,
                    0,
                    $page
                )
            );
            if ('' !== $wpdb->last_error) {
                return false;
            }
            foreach ($rows as $row) {
                if (null === $row->post_id && !file_exists($basedir . '/' . $row->file)) {
                    self::retire_row($row);
                }
                $after = (int) $row->id;
            }
            if (count($rows) < $page) {
                return null;
            }
        } while (microtime(true) < $deadline);

        return $after;
    }

    /**
     * Rows waiting for upload
     *
     * Files copied from the Media Library and not tried yet go first. The
     * rest follow least tried first; among files tried equally often, files
     * pages asked for come before Media Library copies, then oldest first,
     * the order the queue index keeps. So a file that
     * failed before waits behind files not tried yet, even one copied from
     * the Media Library, and a file that keeps failing (or keeps timing out)
     * cannot lead every run and hold up the rest of the queue. A file
     * started over (Retry failed files, a lost copy) has no attempts again,
     * so it takes its old place among the files not tried yet; one more
     * failure puts it behind them. The queue index (status, attempts,
     * priority) serves both queries' order.
     *
     * @param int   $limit         Maximum rows.
     * @param int[] $exclude       Row IDs to leave out (files already tried in this run).
     * @param bool  $priority_only Only files copied from the Media Library and not tried yet.
     * @return object[]
     */
    public static function get_pending($limit, $exclude = [], $priority_only = false) {
        global $wpdb;

        $limit   = (int) $limit;
        $exclude = self::id_list($exclude);

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s AND attempts = %d AND priority = %d AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') ORDER BY id ASC LIMIT %d',
                array_merge([self::table(), self::STATUS_PENDING, 0, 1], $exclude, [$limit])
            )
        );
        if ($priority_only || count($rows) >= $limit) {
            return $rows;
        }

        foreach ($rows as $row) {
            $exclude[] = (int) $row->id;
        }
        $rest = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') ORDER BY attempts ASC, priority ASC, id ASC LIMIT %d',
                array_merge([self::table(), self::STATUS_PENDING], $exclude, [$limit - count($rows)])
            )
        );

        return array_merge($rows, $rest);
    }

    /**
     * One pending file from outside the given attachments
     *
     * Used to tell whether uploads fail in general after a run of files
     * failed together. The first pick is the least tried file at the far
     * end of the queue, away from that run. The far end can hold more of
     * the same bad batch, so a second pick takes the oldest file instead,
     * whatever its attempts.
     *
     * @param int[] $exclude             Row IDs to leave out.
     * @param int[] $exclude_attachments Attachment IDs to leave out.
     * @param bool  $oldest              Take the oldest file (the second pick).
     * @return object[] Zero or one row.
     */
    public static function get_canary($exclude, $exclude_attachments, $oldest = false) {
        global $wpdb;

        $exclude             = self::id_list($exclude);
        $exclude_attachments = self::id_list($exclude_attachments);
        $args                = array_merge([self::table(), self::STATUS_PENDING], $exclude, $exclude_attachments);

        if ($oldest) {
            return (array) $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE status = %s AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') AND attachment_id NOT IN (' . implode(',', array_fill(0, count($exclude_attachments), '%d')) . ') ORDER BY id ASC LIMIT 1',
                    $args
                )
            );
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') AND attachment_id NOT IN (' . implode(',', array_fill(0, count($exclude_attachments), '%d')) . ') ORDER BY attempts ASC, id DESC LIMIT 1',
                $args
            )
        );
    }

    /**
     * Rows whose img.pro copy should be deleted
     *
     * @param int   $limit   Maximum rows.
     * @param int[] $exclude Row IDs to leave out (deletions already tried in this run).
     * @return object[]
     */
    public static function get_deletions($limit, $exclude = []) {
        global $wpdb;

        $exclude = self::id_list($exclude);

        // A copy a synced row still serves is never deleted: that only
        // happens after a failed write, and the row is settled first
        $table = self::table();
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT d.* FROM %i d WHERE d.status = %s AND d.id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') AND NOT EXISTS (SELECT 1 FROM %i s WHERE s.status = %s AND s.imgpro_id = d.imgpro_id) ORDER BY d.id ASC LIMIT %d',
                array_merge([$table, self::STATUS_DELETE], $exclude, [$table, self::STATUS_SYNCED, (int) $limit])
            )
        );
    }

    /**
     * IDs for a NOT IN list, never empty
     *
     * IDs and attachment IDs are never negative, so -1 stands in for an
     * empty list and the query keeps the same shape.
     *
     * @param int[] $ids IDs.
     * @return int[]
     */
    private static function id_list($ids) {
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        return empty($ids) ? [-1] : $ids;
    }

    /**
     * Find a live row by relative path
     *
     * @param string $file Relative path.
     * @return object|null
     */
    public static function get_by_file($file) {
        $rows = self::get_by_files([$file]);
        return $rows[$file] ?? null;
    }

    /**
     * Find live rows for several relative paths
     *
     * @param string[] $files Relative paths.
     * @return array Map of path => row, for paths that have one.
     */
    public static function get_by_files($files) {
        global $wpdb;

        $hashes = [];
        foreach ($files as $file) {
            $file = (string) $file;
            if ('' !== $file) {
                $hashes[self::hash($file)] = $file;
            }
        }
        if (empty($hashes)) {
            return [];
        }

        $table = self::table();
        $found = [];
        foreach (array_chunk(array_keys($hashes), 200) as $chunk) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE file_hash IN (' . implode(',', array_fill(0, count($chunk), '%s')) . ')',
                    array_merge([$table], $chunk)
                )
            );
            foreach ((array) $rows as $row) {
                // The hash indexes the path; compare the path itself too
                if (isset($hashes[$row->file_hash]) && $hashes[$row->file_hash] === $row->file) {
                    $found[$row->file] = $row;
                }
            }
        }

        return $found;
    }

    /**
     * Record a successful upload
     *
     * The row may have been retired by another request (the attachment was
     * deleted or regenerated) while the file uploaded. The new copy is then
     * queued for deletion instead of being left untracked in the App.
     *
     * The same image can also arrive twice: two runs that uploaded the file
     * under the same idempotency key get the same image back. The row then
     * already holds it, so nothing is queued for deletion.
     *
     * @param object $row       Table row.
     * @param string $imgpro_id img.pro image id.
     * @param string $url       img.pro image URL.
     * @return bool|null Whether the row was not copied yet and now serves
     *                   this image (true too when it already served it).
     *                   Null when the database refused a write or a read,
     *                   even on a second try (a deadlock, say). If the row is
     *                   still queued, the next upload sends the same key and,
     *                   within KEY_RENEW_SECONDS, gets this image back; if it
     *                   was retired meanwhile and the deletion could not be
     *                   queued, the copy stays in the App unnamed.
     */
    public static function mark_synced($row, $imgpro_id, $url) {
        global $wpdb;

        $imgpro_id = substr((string) $imgpro_id, 0, 64);

        // Only while the file is still not copied: idle or queued, whichever
        // it is now (a page can queue it while the adopt step matches it).
        // A retired row is deleted or becomes a deletion row, so it never
        // matches. An image from another website whose import failed (its
        // website is gone, say) takes a copy made earlier, too.
        // A deadlock or a lock wait is worth one more try
        for ($try = 0; $try < 2; $try++) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, imgpro_id = %s, url = %s, attempts = 0, sent_at = NULL, error = NULL, updated_at = %s WHERE id = %d AND (status IN (%s, %s) OR (remote = %d AND status = %s))',
                    self::table(),
                    self::STATUS_SYNCED,
                    $imgpro_id,
                    esc_url_raw($url),
                    current_time('mysql', true),
                    (int) $row->id,
                    self::STATUS_IDLE,
                    self::STATUS_PENDING,
                    1,
                    self::STATUS_FAILED
                )
            );
            if (false !== $updated) {
                break;
            }
        }
        self::flush_cache([$row->file]);

        if (false === $updated) {
            return null;
        }
        if (!$updated) {
            $holder = $wpdb->get_var(
                $wpdb->prepare('SELECT status FROM %i WHERE id = %d AND imgpro_id = %s', self::table(), (int) $row->id, $imgpro_id)
            );
            if (null === $holder && '' !== $wpdb->last_error) {
                return null;
            }
            if (null !== $holder) {
                return self::STATUS_SYNCED === $holder;
            }
            if (self::queue_deletion($imgpro_id, $row, $url) || self::queue_deletion($imgpro_id, $row, $url)) {
                return false;
            }
            // The copy is in the App and no row names it
            return null;
        }
        return true;
    }

    /**
     * Whether an img.pro image is waiting to be deleted
     *
     * @param string $imgpro_id img.pro image id.
     * @return bool
     */
    public static function is_queued_for_deletion($imgpro_id) {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1 FROM %i WHERE status = %s AND imgpro_id = %s LIMIT 1',
                self::table(),
                self::STATUS_DELETE,
                substr((string) $imgpro_id, 0, 64)
            )
        );
    }

    /**
     * Queue an img.pro image that no row tracks for deletion
     *
     * @param string $imgpro_id img.pro image id.
     * @param object $row       Row the image was uploaded for.
     * @param string $url       The image's img.pro URL, which tells the App
     *                          storage it is in (see delete_step()).
     * @return bool Whether the deletion was queued.
     */
    public static function queue_deletion($imgpro_id, $row, $url = '') {
        global $wpdb;

        return 1 === $wpdb->insert(self::table(), [
            'attachment_id' => (int) $row->attachment_id,
            'file'          => (string) $row->file,
            'file_hash'     => null,
            'status'        => self::STATUS_DELETE,
            'imgpro_id'     => substr((string) $imgpro_id, 0, 64),
            'url'           => '' !== (string) $url ? esc_url_raw($url) : null,
            'remote'        => empty($row->remote) ? 0 : 1,
            'updated_at'    => current_time('mysql', true),
        ]);
    }

    /**
     * App storage an img.pro URL belongs to
     *
     * Copies are served at https://src.img.pro/{storage}/{id}.{ext}.
     *
     * @param string $url img.pro URL.
     * @return string Host and storage, or empty when the URL does not say.
     */
    public static function storage_of($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        $path = trim((string) wp_parse_url((string) $url, PHP_URL_PATH), '/');
        if (!is_string($host) || '' === $host || false === strpos($path, '/')) {
            return '';
        }
        return strtolower($host) . '/' . strtok($path, '/');
    }

    /**
     * App storage of the copies made with the current key, going by the
     * newest one
     *
     * @return string See storage_of(); empty while nothing is copied.
     */
    public static function current_storage() {
        global $wpdb;
        $url = $wpdb->get_var(
            $wpdb->prepare('SELECT url FROM %i WHERE status = %s AND url IS NOT NULL ORDER BY id DESC LIMIT 1', self::table(), self::STATUS_SYNCED)
        );
        return null === $url ? '' : self::storage_of($url);
    }

    /**
     * Copies whose presence on img.pro was confirmed longest ago
     *
     * A synced row's updated_at is when its copy was last confirmed (or
     * made).
     *
     * @param int   $limit   Maximum rows.
     * @param int[] $exclude Row IDs to leave out (checked already in this run).
     * @return object[]
     */
    public static function get_unconfirmed($limit, $exclude = []) {
        global $wpdb;

        $exclude = self::id_list($exclude);

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, file, imgpro_id, url, remote, status FROM %i WHERE status = %s AND imgpro_id IS NOT NULL AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . ') ORDER BY updated_at ASC, id ASC LIMIT %d',
                array_merge([self::table(), self::STATUS_SYNCED], $exclude, [(int) $limit])
            )
        );
    }

    /**
     * Note that copies are still on img.pro
     *
     * @param int[] $ids Row IDs.
     * @return void
     */
    public static function confirm_copies($ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        foreach (array_chunk($ids, 200) as $chunk) {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET updated_at = %s WHERE status = %s AND id IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')',
                    array_merge([self::table(), current_time('mysql', true), self::STATUS_SYNCED], $chunk)
                )
            );
        }
    }

    /**
     * A copy img.pro no longer has (deleted in the App): stop serving it and
     * copy the file again
     *
     * The file was in use, so it is queued at once rather than left for a
     * page to ask for, under a new idempotency key (see requeue()): the old
     * key would get the lost image back.
     *
     * @param object $row Table row as read.
     * @return bool|null Whether the row was still that copy; null when the
     *                   database refused the change, even on a second try.
     */
    public static function mark_lost($row) {
        global $wpdb;
        // A deadlock or a lock wait is worth one more try
        for ($try = 0; $try < 2; $try++) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, imgpro_id = NULL, url = NULL, priority = %d, attempts = %d, retries = retries + 1, sent_at = NULL, error = NULL, updated_at = %s WHERE id = %d AND status = %s AND imgpro_id = %s',
                    self::table(),
                    self::STATUS_PENDING,
                    0,
                    0,
                    current_time('mysql', true),
                    (int) $row->id,
                    self::STATUS_SYNCED,
                    (string) $row->imgpro_id
                )
            );
            if (false !== $updated) {
                break;
            }
        }
        self::flush_cache([(string) $row->file]);
        return false === $updated ? null : $updated > 0;
    }

    /**
     * A copy img.pro blocked: stop serving it, and leave the file where it is
     *
     * The id is kept, so the copy is deleted along with the file.
     *
     * @param object $row Table row as read.
     * @return bool|null Whether the row was still that copy; null when the
     *                   database refused the change, even on a second try.
     */
    public static function mark_blocked($row) {
        global $wpdb;
        // A deadlock or a lock wait is worth one more try
        for ($try = 0; $try < 2; $try++) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, url = NULL, error = %s, updated_at = %s WHERE id = %d AND status = %s AND imgpro_id = %s',
                    self::table(),
                    self::STATUS_SKIPPED,
                    self::reason(self::REASON_BLOCKED),
                    current_time('mysql', true),
                    (int) $row->id,
                    self::STATUS_SYNCED,
                    (string) $row->imgpro_id
                )
            );
            if (false !== $updated) {
                break;
            }
        }
        self::flush_cache([(string) $row->file]);
        return false === $updated ? null : $updated > 0;
    }

    /**
     * A copy img.pro could not process: stop serving it, and list the file
     * as failed
     *
     * Uploading it again would most likely fail the same way, every hour,
     * so the file waits for Retry failed files, which tries it under a new
     * idempotency key. The failed image is deleted separately (lists leave
     * it out, so nothing else would find it).
     *
     * @param object $row Table row as read.
     * @return bool|null Whether the row was still that copy; null when the
     *                   database refused the change, even on a second try.
     */
    public static function mark_processing_failed($row) {
        global $wpdb;
        // A deadlock or a lock wait is worth one more try
        for ($try = 0; $try < 2; $try++) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, imgpro_id = NULL, url = NULL, attempts = %d, retries = retries + 1, sent_at = NULL, error = %s, updated_at = %s WHERE id = %d AND status = %s AND imgpro_id = %s',
                    self::table(),
                    self::STATUS_FAILED,
                    0,
                    self::reason(self::REASON_PROCESSING),
                    current_time('mysql', true),
                    (int) $row->id,
                    self::STATUS_SYNCED,
                    (string) $row->imgpro_id
                )
            );
            if (false !== $updated) {
                break;
            }
        }
        self::flush_cache([(string) $row->file]);
        return false === $updated ? null : $updated > 0;
    }

    /**
     * Record an image from another website found in the App
     *
     * After a reconnect the App still holds the earlier imports, and no
     * row names them. The image becomes the copy of its address, so pages
     * use it rather than importing the address again.
     *
     * @param string $source    The image's address.
     * @param string $imgpro_id img.pro image id.
     * @param string $url       img.pro image URL.
     * @return bool|null Whether a row was added; false when the address has
     *                   one; null when the database refused the insert.
     */
    public static function adopt_remote($source, $imgpro_id, $url) {
        global $wpdb;

        $added = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (attachment_id, file, file_hash, status, priority, remote, imgpro_id, url, updated_at) VALUES (%d, %s, %s, %s, %d, %d, %s, %s, %s)',
                self::table(),
                0,
                (string) $source,
                self::hash((string) $source),
                self::STATUS_SYNCED,
                0,
                1,
                substr((string) $imgpro_id, 0, 64),
                esc_url_raw($url),
                current_time('mysql', true)
            )
        );
        if (false === $added) {
            return null;
        }
        if ($added) {
            // A page may have remembered the address as not copied
            self::flush_cache([(string) $source]);
        }
        return $added > 0;
    }

    /**
     * Forget images from other websites that are not copied yet
     *
     * Used when the site stops copying them: nothing is imported any more,
     * and pages keep showing them from their own address. Copies made
     * already stay, and are used again if copying is turned back on.
     *
     * @return void
     */
    public static function forget_remote_queue() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE remote = %d AND status IN (%s, %s, %s)',
                self::table(),
                1,
                self::STATUS_IDLE,
                self::STATUS_PENDING,
                self::STATUS_FAILED
            )
        );
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
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET attempts = %d, sent_at = COALESCE(sent_at, %s) WHERE id = %d',
                self::table(),
                (int) $row->attempts + 1,
                $now,
                (int) $row->id
            )
        );
        // When the current key was first sent (see renew_stale_key())
        if (empty($row->sent_at)) {
            $row->sent_at = $now;
        }
    }

    /**
     * Give a row a new idempotency key once its current one is
     * KEY_RENEW_SECONDS old (20 hours, inside img.pro's 24)
     *
     * img.pro keeps a key's state for 24 hours and asks clients not to send
     * it after that: a late retry may neither get back the image an earlier
     * attempt made nor be kept from making a second one. So a key first
     * sent KEY_RENEW_SECONDS ago is replaced (a retry, see requeue()) before
     * the next attempt. An image an earlier attempt made without its answer
     * arriving then stays in the App until the matching step after a
     * reconnect finds it.
     *
     * @param object $row Table row, updated in place.
     * @return bool False when the key was due for renewal and could not be
     *              renewed (the database refused, or another run changed the
     *              row): the row then waits for the next run.
     */
    public static function renew_stale_key($row) {
        global $wpdb;

        if (empty($row->sent_at) || strtotime((string) $row->sent_at . ' UTC') > time() - self::KEY_RENEW_SECONDS) {
            return true;
        }
        $renewed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET retries = retries + 1, sent_at = NULL WHERE id = %d AND retries = %d',
                self::table(),
                (int) $row->id,
                (int) $row->retries
            )
        );
        if (1 !== $renewed) {
            return false;
        }
        $row->retries = (int) $row->retries + 1;
        $row->sent_at = null;
        return true;
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
        // Size and time are part of the upload's key, which is therefore new.
        // It counts a retry too, so a file changed back can never bring back
        // a key used before.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET file_bytes = %d, file_mtime = %d, retries = retries + 1, sent_at = NULL WHERE id = %d',
                self::table(),
                (int) $bytes,
                (int) $mtime,
                (int) $row->id
            )
        );
        $row->file_bytes = (int) $bytes;
        $row->file_mtime = (int) $mtime;
        $row->retries    = (int) $row->retries + 1;
        $row->sent_at    = null;
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
                'error'      => self::clip($error, 255),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => (int) $row->id]
        );
    }

    /**
     * Record an import that should be tried again later, not right away
     *
     * The row goes back to waiting for a page, and a page may queue it
     * again once its wait is over (see retry_due()). img.pro answered and
     * made no image, so the next try uses a new idempotency key (a retry,
     * see requeue()): the website is then fetched again even if img.pro
     * would replay the timeout for the old key.
     *
     * @param object $row          Table row.
     * @param string $error        Error message.
     * @param int    $max_attempts Attempts before giving up.
     * @return void
     */
    public static function mark_attempt_later($row, $error, $max_attempts = 5) {
        global $wpdb;

        $attempts = (int) $row->attempts + 1;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, attempts = %d, retries = retries + 1, sent_at = NULL, error = %s, updated_at = %s WHERE id = %d',
                self::table(),
                $attempts >= $max_attempts ? self::STATUS_FAILED : self::STATUS_IDLE,
                $attempts,
                self::clip($error, 255),
                current_time('mysql', true),
                (int) $row->id
            )
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
                'error'      => self::clip($error, 255),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => (int) $row->id]
        );
    }

    /**
     * Sanitize text and cut it to a column's length in characters
     *
     * Cutting by bytes can split a multibyte character, and wpdb rejects
     * the whole insert or update when a value is not valid UTF-8.
     *
     * @param string $text   Text.
     * @param int    $length Maximum characters.
     * @return string
     */
    public static function clip($text, $length) {
        return mb_substr(sanitize_text_field((string) $text), 0, $length);
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
     * Each gets a new idempotency key (see requeue()), in one statement.
     * They start at normal priority: a retry from the settings page is not
     * a Media Library copy, which a priority-only run uploads while someone
     * waits. Copying a file again from the Media Library gives it its
     * priority back (see prioritize()).
     *
     * @param bool $include_remote Also images from other websites (not
     *                             while the site does not copy them).
     * @return int Number of rows requeued.
     */
    public static function retry_failed($include_remote = true) {
        global $wpdb;

        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status = %s, retries = retries + 1, sent_at = NULL, attempts = %d, priority = %d, error = NULL, updated_at = %s WHERE status = %s AND remote <= %d',
                self::table(),
                self::STATUS_PENDING,
                0,
                0,
                current_time('mysql', true),
                self::STATUS_FAILED,
                $include_remote ? 1 : 0
            )
        );
    }

    /**
     * Queue a row again under a new idempotency key
     *
     * For 24 hours img.pro answers a key that made an image with that image
     * again, so a file sent again from scratch needs a new key: the retry
     * count is part of it (see ImgPro_CDN_Sync::upload_files()). An attempt
     * that failed is retried with the same key. One statement, and only
     * while the row is still in the status it was read in, so a row another
     * request retired or requeued meanwhile is left alone rather than
     * brought back or counted twice.
     *
     * If an earlier answer under the old key never arrived, img.pro may
     * already hold a copy no row names. A conflict does not say which, so
     * it stays until the matching step after a reconnect adopts or deletes
     * it.
     *
     * @param object   $row      Table row as read (its ID and status are used).
     * @param int      $attempts Attempt count to carry over.
     * @param int|null $priority 1 for a file copied from the Media Library,
     *                           0 for one pages asked for, null to keep the
     *                           row's current priority.
     * @return bool Whether this request requeued the row.
     */
    public static function requeue($row, $attempts, $priority = null) {
        global $wpdb;
        $table    = self::table();
        $attempts = max(0, (int) $attempts);
        $now      = current_time('mysql', true);

        if (null === $priority) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, retries = retries + 1, sent_at = NULL, attempts = %d, error = NULL, updated_at = %s WHERE id = %d AND status = %s',
                    $table,
                    self::STATUS_PENDING,
                    $attempts,
                    $now,
                    (int) $row->id,
                    (string) $row->status
                )
            );
        } else {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, retries = retries + 1, sent_at = NULL, attempts = %d, priority = %d, error = NULL, updated_at = %s WHERE id = %d AND status = %s',
                    $table,
                    self::STATUS_PENDING,
                    $attempts,
                    $priority ? 1 : 0,
                    $now,
                    (int) $row->id,
                    (string) $row->status
                )
            );
        }
        return 1 === (int) $updated;
    }

    /**
     * Queue files for upload ahead of the files pages asked for
     *
     * Used by Copy to img.pro in the Media Library. Files not copied yet are
     * queued, files already queued move to the front, and failed files are
     * tried again from scratch. Each starts with fresh attempts: a copy is
     * an explicit request, and only an untried copy goes first (see
     * get_pending()).
     *
     * @param string[] $files Paths relative to the uploads folder.
     * @return int Files now queued with priority.
     */
    public static function prioritize($files) {
        global $wpdb;

        $hashes = [];
        foreach ($files as $file) {
            if ('' !== (string) $file) {
                $hashes[] = self::hash((string) $file);
            }
        }
        $hashes = array_values(array_unique($hashes));

        // Failed files start over, under a new idempotency key
        foreach (self::get_by_files($files) as $row) {
            if (self::STATUS_FAILED === $row->status) {
                self::requeue($row, 0, 1);
            }
        }

        $table  = self::table();
        $now    = current_time('mysql', true);
        $queued = 0;
        foreach (array_chunk($hashes, 200) as $chunk) {
            // By path rather than by the rows read above, so a file another
            // request requeued or replaced meanwhile is still found; a file
            // the worker settled meanwhile keeps its new status
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status = %s, priority = %d, attempts = %d, updated_at = %s WHERE status IN (%s, %s) AND file_hash IN (' . implode(',', array_fill(0, count($chunk), '%s')) . ')',
                    array_merge([$table, self::STATUS_PENDING, 1, 0, $now, self::STATUS_IDLE, self::STATUS_PENDING], $chunk)
                )
            );
            $queued += (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE status = %s AND priority = %d AND file_hash IN (' . implode(',', array_fill(0, count($chunk), '%s')) . ')',
                    array_merge([$table, self::STATUS_PENDING, 1], $chunk)
                )
            );
        }

        return $queued;
    }

    /**
     * Where each attachment's files are, for the Media Library
     *
     * Files are matched by path, as the rewriter looks them up, so an
     * attachment that shares files with another (media translations do)
     * shows the same status. Files without a row yet (an upload from this
     * request, or a library still being scanned) count as not copied, or
     * as staying on the server when img.pro does not accept their format.
     * Reasons come worded for the current user.
     *
     * @param int[] $attachment_ids Attachment IDs.
     * @return array Map of attachment ID => summary: counts per status
     *               (copied, queued, idle, failed, skipped, total), the
     *               latest failure and skip reasons, the img.pro URL of the
     *               full size once copied, and whether it is an image.
     */
    public static function get_summaries($attachment_ids) {
        global $wpdb;

        $files_by_id = [];
        $hashes      = [];
        foreach (array_unique(array_map('intval', (array) $attachment_ids)) as $attachment_id) {
            $is_image = $attachment_id > 0 && wp_attachment_is_image($attachment_id);
            $files    = $is_image ? self::get_attachment_files($attachment_id) : [];
            $files_by_id[$attachment_id] = [$is_image, $files];
            foreach (array_keys($files) as $file) {
                $hashes[self::hash($file)] = $file;
            }
        }

        $rows = [];
        foreach (array_chunk(array_keys($hashes), 200) as $chunk) {
            $found = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT file, file_hash, status, url, error, updated_at FROM %i WHERE file_hash IN (' . implode(',', array_fill(0, count($chunk), '%s')) . ')',
                    array_merge([self::table()], $chunk)
                )
            );
            foreach ((array) $found as $row) {
                if (isset($hashes[$row->file_hash]) && $hashes[$row->file_hash] === $row->file) {
                    $rows[$row->file] = $row;
                }
            }
        }

        $statuses = [
            self::STATUS_SYNCED  => 'copied',
            self::STATUS_PENDING => 'queued',
            self::STATUS_IDLE    => 'idle',
            self::STATUS_FAILED  => 'failed',
            self::STATUS_SKIPPED => 'skipped',
        ];

        $summaries = [];
        foreach ($files_by_id as $attachment_id => list($is_image, $files)) {
            $summary = [
                'copied'        => 0,
                'queued'        => 0,
                'idle'          => 0,
                'failed'        => 0,
                'skipped'       => 0,
                'total'         => count($files),
                'failed_error'  => '',
                'skipped_error' => '',
                'full_url'      => '',
                'is_image'      => $is_image,
            ];
            $failed_at = '';

            foreach ($files as $file => $size_name) {
                $row = $rows[$file] ?? null;
                if ($row) {
                    $key   = $statuses[$row->status] ?? 'idle';
                    $error = (string) $row->error;
                } elseif (self::has_uploadable_extension($file)) {
                    // Not recorded yet (an upload from this request, or a
                    // library still being scanned): waiting like any file
                    $key   = 'idle';
                    $error = '';
                } else {
                    $key   = 'skipped';
                    $error = self::reason(self::REASON_FORMAT);
                }
                $summary[$key]++;

                if ('failed' === $key && (string) $row->updated_at >= $failed_at) {
                    $failed_at               = (string) $row->updated_at;
                    $summary['failed_error'] = self::error_text($error);
                } elseif ('skipped' === $key && '' === $summary['skipped_error']) {
                    $summary['skipped_error'] = self::error_text($error);
                } elseif ('copied' === $key && 'full' === $size_name) {
                    $summary['full_url'] = (string) $row->url;
                }
            }

            $summaries[$attachment_id] = $summary;
        }

        return $summaries;
    }

    /**
     * Row counts per status
     *
     * Live counts of a queue that changes under the worker, AJAX steps and
     * page views, so never cached: run() decides from them whether work
     * remains. Reads only an index that status leads, never table rows.
     *
     * @return array status => count
     */
    public static function get_counts() {
        global $wpdb;
        $table = self::table();

        $counts = array_fill_keys(
            [self::STATUS_IDLE, self::STATUS_PENDING, self::STATUS_SYNCED, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_DELETE],
            0
        );

        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table));
        foreach ((array) $rows as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Images from other websites that are copied
     *
     * @return int
     */
    public static function count_remote_copies() {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE remote = %d AND status = %s', self::table(), 1, self::STATUS_SYNCED)
        );
    }

    /**
     * Files most recently found to stay on the server, for the admin page
     *
     * @param int $limit Maximum rows.
     * @return object[]
     */
    public static function get_recent_skipped($limit = 5) {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT file, error FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d',
                self::table(),
                self::STATUS_SKIPPED,
                (int) $limit
            )
        );
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
        // Normalized like the paths WordPress passes to wp_delete_file, so
        // prefixes compare equal (duplicate slashes, Windows drive letters)
        return untrailingslashit(wp_normalize_path($uploads['basedir']));
    }
}
