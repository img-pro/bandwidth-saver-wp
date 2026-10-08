<?php
/**
 * ImgPro CDN img.pro API Client
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for the img.pro REST API (https://img.pro/api)
 *
 * Every method returns the decoded JSON body on success or a WP_Error.
 * Error data carries the HTTP status, img.pro error type and code, and
 * the Retry-After value when img.pro sends one.
 *
 * @since 2.0.0
 */
class ImgPro_CDN_API {

    /**
     * Default API base URL
     *
     * @var string
     */
    const BASE_URL = 'https://api.img.pro/v1';

    /**
     * Largest file img.pro accepts, in bytes
     *
     * @var int
     */
    const MAX_FILE_BYTES = 20000000;

    /**
     * File extensions img.pro accepts for upload
     *
     * @var string[]
     */
    const UPLOADABLE_EXTENSIONS = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'heic', 'heif'];

    /**
     * API key used for requests
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor
     *
     * @param string $api_key Plaintext img.pro API key.
     */
    public function __construct($api_key) {
        $this->api_key = (string) $api_key;
    }

    /**
     * Read usage and plan limits for the key's storage
     *
     * @return array|WP_Error Usage object.
     */
    public function get_usage() {
        return $this->request('GET', '/usage');
    }

    /**
     * Check that the key can upload images
     *
     * img.pro checks permissions before validating the body, so an empty
     * create request answers 422 for a key with write access and 403 for
     * a read-only key. Nothing is created either way.
     *
     * @return true|WP_Error True when the key has write access.
     */
    public function check_write_access() {
        $result = $this->request('POST', '/images', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{}',
        ]);

        if (!is_wp_error($result)) {
            return true;
        }

        $data = $result->get_error_data();
        if (is_array($data) && 422 === ($data['status'] ?? 0)) {
            return true;
        }

        return $result;
    }

    /**
     * Upload a local file
     *
     * img.pro recognizes a retry by its parsed fields and file, not the raw
     * bytes, so any boundary works; deriving it from the idempotency key
     * keeps retries byte-identical anyway. Changing the filename, the
     * file's Content-Type or the labels or metadata JSON (wp_md5 included)
     * makes a different request, which img.pro answers with 409
     * idempotency_key_conflict (the sync requeues the file).
     *
     * @param string $path            Absolute path of the file.
     * @param array  $labels          Filterable labels (string => string).
     * @param array  $metadata        Free-form metadata (string => string).
     * @param string $idempotency_key Stable key for this upload.
     * @return array|WP_Error Image object.
     */
    public function upload_file($path, $labels, $metadata, $idempotency_key) {
        $contents = self::read_local_file($path);
        if (false === $contents) {
            return new WP_Error('file_unreadable', __('The file could not be read from disk.', 'bandwidth-saver'), ['status' => 0]);
        }

        $boundary = 'imgpro' . substr(hash('sha256', $idempotency_key), 0, 32);
        $filename = str_replace(['"', "\r", "\n"], '', wp_basename($path));
        $filetype = wp_check_filetype($filename);
        $mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

        $fields = [
            'labels'   => wp_json_encode((object) $labels),
            'metadata' => wp_json_encode((object) $metadata),
        ];

        $head = '';
        foreach ($fields as $name => $value) {
            $head .= '--' . $boundary . "\r\n";
            $head .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $head .= $value . "\r\n";
        }
        $head .= '--' . $boundary . "\r\n";
        $head .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
        $head .= 'Content-Type: ' . $mime . "\r\n\r\n";

        // Build the body in one step and drop the file copy, so only one
        // copy of the file is held before the HTTP layer makes its own
        $body = $head . $contents . "\r\n--" . $boundary . "--\r\n";
        unset($contents);

        return $this->request('POST', '/images', [
            'headers' => [
                'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
                'Idempotency-Key' => $idempotency_key,
            ],
            'body'    => $body,
            'timeout' => 60,
        ]);
    }

    /**
     * Have img.pro import an image from its address
     *
     * img.pro fetches the URL itself (30 seconds at most, public addresses
     * only), so the file never passes through this server. A source that
     * does not answer, or answers with an error, comes back as
     * fetch_failed.
     *
     * @param string $url             Absolute http(s) URL.
     * @param array  $labels          Filterable labels (string => string).
     * @param array  $metadata        Free-form metadata (string => string).
     * @param string $idempotency_key Stable key for this import.
     * @return array|WP_Error Image object.
     */
    public function import_url($url, $labels, $metadata, $idempotency_key) {
        return $this->request('POST', '/images', [
            'headers' => [
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ],
            'body'    => wp_json_encode([
                'url'      => (string) $url,
                'labels'   => (object) $labels,
                'metadata' => (object) $metadata,
            ]),
            // img.pro's own fetch may take 30 seconds before it answers
            'timeout' => 60,
        ]);
    }

    /**
     * List images carrying the given labels
     *
     * @param array       $labels Label filters (key => value).
     * @param int         $limit  Page size (1-100).
     * @param string|null $cursor Cursor from a previous page.
     * @return array|WP_Error List object.
     */
    public function list_images($labels, $limit = 100, $cursor = null) {
        $query = ['limit' => max(1, min(100, (int) $limit))];
        foreach ($labels as $key => $value) {
            $query['label[' . $key . ']'] = $value;
        }
        if ($cursor) {
            $query['cursor'] = $cursor;
        }

        return $this->request('GET', '/images?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * Images by id, up to 50
     *
     * Only images in the key's storage come back; img.pro leaves out ids it
     * cannot serve (deleted, blocked, failed) without saying why.
     *
     * @param string[] $ids Image ids.
     * @return array|WP_Error List object.
     */
    public function get_images($ids) {
        $ids = array_values(array_slice(array_unique(array_filter(array_map('strval', (array) $ids))), 0, 50));
        if (empty($ids)) {
            return ['object' => 'list', 'data' => []];
        }

        return $this->request('GET', '/images?' . http_build_query(['ids' => implode(',', $ids)], '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * One image by id
     *
     * Says why an image cannot be served: 404 not_found when it is gone
     * (or in another storage), 403 media_blocked, 422 media_failed.
     *
     * @param string $id Image id.
     * @return array|WP_Error Image object.
     */
    public function get_image($id) {
        return $this->request('GET', '/images/' . rawurlencode((string) $id));
    }

    /**
     * Delete up to 100 images
     *
     * Batch delete is idempotent: ids that are already gone still report
     * as deleted.
     *
     * @param string[] $ids Image ids.
     * @return array|WP_Error Batch result.
     */
    public function delete_images($ids) {
        $ids = array_values(array_slice(array_filter(array_map('strval', $ids)), 0, 100));
        if (empty($ids)) {
            return ['object' => 'batch_result', 'data' => [], 'errors' => []];
        }

        return $this->request('DELETE', '/images/batch', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['ids' => $ids]),
        ]);
    }

    /**
     * Send a request to the img.pro API
     *
     * @param string $method HTTP method.
     * @param string $path   Path below the base URL, including any query string.
     * @param array  $args   Extra wp_remote_request() arguments.
     * @return array|WP_Error Decoded JSON body.
     */
    private function request($method, $path, $args = []) {
        if ('' === $this->api_key) {
            return new WP_Error('missing_api_key', __('No img.pro API key is configured.', 'bandwidth-saver'), ['status' => 0]);
        }

        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept'        => 'application/json',
        ], $args['headers'] ?? []);

        $request_args = [
            'method'     => $method,
            'headers'    => $headers,
            'timeout'    => $args['timeout'] ?? 20,
            'user-agent' => $this->get_user_agent(),
        ];
        if (isset($args['body'])) {
            $request_args['body'] = $args['body'];
        }

        $response = wp_remote_request(self::get_base_url() . $path, $request_args);

        if (is_wp_error($response)) {
            $error = new WP_Error('connection_error', $response->get_error_message(), ['status' => 0]);
            do_action('imgpro_cdn_api_error', $error, $path);
            return $error;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 200 && $status < 300) {
            return is_array($body) ? $body : [];
        }

        $error_body  = is_array($body) && isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
        $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
        if (!$retry_after && isset($error_body['action']['retry_after'])) {
            $retry_after = (int) $error_body['action']['retry_after'];
        }

        $code = !empty($error_body['code']) ? sanitize_key($error_body['code']) : 'http_' . $status;
        $message = !empty($error_body['message'])
            ? sanitize_text_field($error_body['message'])
            /* translators: %d: HTTP status code */
            : sprintf(__('img.pro returned HTTP %d.', 'bandwidth-saver'), $status);

        // A validation error's message is only "Validation failed"; the
        // reason (a damaged file, an unsupported variant) is in details
        $reasons = self::detail_messages($error_body['details'] ?? null);
        if (!empty($reasons)) {
            $message = implode(' ', $reasons);
        }

        $error = new WP_Error($code, $message, [
            'status'      => $status,
            'type'        => isset($error_body['type']) ? sanitize_key($error_body['type']) : '',
            'retry_after' => $retry_after,
        ]);

        do_action('imgpro_cdn_api_error', $error, $path);

        return $error;
    }

    /**
     * Messages from an error's details, at most three
     *
     * img.pro lists the reasons per field, as a message or a list of them.
     *
     * @param mixed $details Error details.
     * @return string[]
     */
    private static function detail_messages($details) {
        if (!is_array($details)) {
            return [];
        }

        $messages = [];
        foreach ($details as $value) {
            $first = is_array($value) ? reset($value) : $value;
            if (is_string($first) && '' !== trim($first)) {
                $messages[] = sanitize_text_field($first);
            }
        }
        return array_slice($messages, 0, 3);
    }

    /**
     * Read a local file through the direct filesystem class
     *
     * Reading needs no credentials, so the direct method is safe even on
     * hosts where WordPress writes files over FTP.
     *
     * @param string $path Absolute path.
     * @return string|false File contents, or false on failure.
     */
    private static function read_local_file($path) {
        if (!class_exists('WP_Filesystem_Direct')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        }
        $filesystem = new WP_Filesystem_Direct(null);

        return $filesystem->get_contents($path);
    }

    /**
     * API base URL, filterable for staging environments
     *
     * @return string
     */
    public static function get_base_url() {
        /**
         * Filter the img.pro API base URL.
         *
         * @since 2.0.0
         * @param string $base_url Default base URL.
         */
        return untrailingslashit(apply_filters('imgpro_cdn_api_base_url', self::BASE_URL));
    }

    /**
     * User agent sent with API requests
     *
     * @return string
     */
    private function get_user_agent() {
        return sprintf(
            'BandwidthSaver/%s WordPress/%s; %s',
            IMGPRO_CDN_VERSION,
            get_bloginfo('version'),
            home_url()
        );
    }
}
