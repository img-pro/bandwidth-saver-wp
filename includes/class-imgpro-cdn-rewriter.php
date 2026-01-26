<?php
/**
 * ImgPro URL Rewriter
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL rewriting engine
 *
 * Transforms image URLs to point to CDN, handles context detection,
 * and processes various WordPress image output hooks.
 *
 * Single-domain architecture (v1.1.0+):
 * - CDN URL format: https://cdn-domain/origin-domain/path/to/image.jpg
 * - Worker serves images directly from R2 cache
 * - On CDN failure, onerror falls back to origin URL
 *
 * @since 0.1.0
 */
class ImgPro_CDN_Rewriter {

    /**
     * Settings instance
     *
     * @since 0.1.0
     * @var ImgPro_CDN_Settings
     */
    private $settings;

    /**
     * URL cache
     *
     * @since 0.1.0
     * @var array
     */
    private $url_cache = [];

    /**
     * Processing flag
     *
     * @since 0.1.0
     * @var bool
     */
    private $processing = false;

    /**
     * Context check cache (performance optimization)
     *
     * @since 0.1.0
     * @var bool|null
     */
    private $is_unsafe_context_cache = null;

    /**
     * Constructor
     *
     * @since 0.1.0
     * @param ImgPro_CDN_Settings $settings Settings instance.
     */
    public function __construct(ImgPro_CDN_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Check if current context is unsafe for URL rewriting
     *
     * ARCHITECTURE: This method is called when hooks execute (lazy evaluation),
     * not during init(). By this time, WordPress has parsed the request and
     * all constants are properly defined.
     *
     * PERFORMANCE: Result is cached per request to avoid repeated constant checks.
     *
     * Returns true if we're in a context where rewriting URLs would break:
     * - Plugin communication (REST API, AJAX)
     * - External services (Jetpack, backups, webhooks)
     * - WordPress admin area
     * - CLI/Cron operations
     * - Any non-frontend rendering context
     *
     * @since 0.1.0
     * @return bool True if context is unsafe for rewriting.
     */
    private function is_unsafe_context() {
        // Return cached result if available (performance optimization)
        if ($this->is_unsafe_context_cache !== null) {
            return $this->is_unsafe_context_cache;
        }

        $is_unsafe = false;

        // Admin area - plugins need original URLs for Media Library, etc.
        // BUT: Allow AJAX requests from frontend (infinite scroll, load more, etc.)
        if (is_admin() && !apply_filters('imgpro_admin_allow_rewrite', false)) {
            // Check if this is a frontend AJAX request (e.g., infinite scroll)
            // Frontend AJAX should have CDN URLs for images
            if (defined('DOING_AJAX') && DOING_AJAX && $this->is_frontend_ajax()) {
                // Allow frontend AJAX - don't mark as unsafe
                $is_unsafe = false;
            } else {
                $is_unsafe = true;
            }
        }
        // REST API requests - plugins/services need original URLs
        // This includes Jetpack, backup plugins, mobile apps, etc.
        elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $is_unsafe = true;
        }
        // Cron jobs - background tasks need original URLs
        elseif (defined('DOING_CRON') && DOING_CRON) {
            $is_unsafe = true;
        }
        // WP-CLI - command line operations need original URLs
        elseif (defined('WP_CLI') && WP_CLI) {
            $is_unsafe = true;
        }
        // XML-RPC - remote publishing tools need original URLs
        elseif (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            $is_unsafe = true;
        }
        // Autosave - editor needs original URLs
        elseif (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            $is_unsafe = true;
        }
        // WordPress core is installing/upgrading
        elseif (defined('WP_INSTALLING') && WP_INSTALLING) {
            $is_unsafe = true;
        }
        // Allow plugins to mark their own unsafe contexts
        elseif (apply_filters('imgpro_is_unsafe_context', false)) {
            $is_unsafe = true;
        }

        // Cache the result for this request
        $this->is_unsafe_context_cache = $is_unsafe;

        return $is_unsafe;
    }

    /**
     * Check if current AJAX request is from frontend
     *
     * Frontend AJAX requests (infinite scroll, load more, etc.) should have
     * CDN URLs, unlike admin AJAX which needs original URLs.
     *
     * Detection strategy uses login state as the differentiator:
     * - Logged-in users in AJAX = admin context (editing, media library, etc.)
     * - Non-logged-in users in AJAX = frontend context (infinite scroll, etc.)
     *
     * This is more reliable than referer checking because:
     * 1. Referers can be missing or spoofed
     * 2. User login state directly correlates with intent:
     *    - Logged-in = likely managing content (needs original URLs)
     *    - Not logged-in = visitor browsing (needs CDN URLs)
     *
     * @since 0.1.0
     * @return bool True if this appears to be a frontend AJAX request.
     */
    private function is_frontend_ajax() {
        // Allow plugins/themes to force frontend AJAX detection
        if (apply_filters('imgpro_is_frontend_ajax', false)) {
            return true;
        }

        // Must be an AJAX request
        if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) {
            return false;
        }

        // Key insight: Logged-in users making AJAX requests are typically
        // in admin context (media library, page builders, etc.)
        // Non-logged-in users are visitors (infinite scroll, load more, etc.)
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            // Logged-in user - treat as admin AJAX (needs original URLs)
            // Unless explicitly overridden by filter
            return apply_filters('imgpro_logged_in_ajax_allow_cdn', false);
        }

        // Non-logged-in AJAX = frontend visitor (infinite scroll, etc.)
        return true;
    }

    /**
     * Initialize hooks
     *
     * ARCHITECTURE: Always register hooks, but check context when they execute.
     * This is necessary because WordPress hasn't parsed the request yet at
     * plugins_loaded time, so we can't reliably determine request type here.
     *
     * @since 0.1.0
     * @since 0.2.0 Added video and audio shortcode filters
     * @return void
     */
    public function init() {
        // Check if CDN is active (current mode is valid AND enabled)
        if (!ImgPro_CDN_Settings::is_cdn_active($this->settings->get_all())) {
            return;
        }

        // ALWAYS register hooks - we'll check context when they execute
        // This is the only reliable way to handle the WordPress request lifecycle

        // Core image hooks
        add_filter('wp_get_attachment_url', [$this, 'rewrite_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_image_src'], 10, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset'], 10, 5);
        // Run late (priority 999) to override any lazy loading plugins that modify src
        add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_attributes'], 999, 3);

        // Content filters (processes img, video, audio, source tags)
        add_filter('the_content', [$this, 'rewrite_content'], 999);
        add_filter('post_thumbnail_html', [$this, 'rewrite_content'], 999);
        add_filter('widget_text', [$this, 'rewrite_content'], 999);

        // Video and audio shortcode filters (WordPress media embeds)
        add_filter('wp_video_shortcode', [$this, 'rewrite_content'], 999);
        add_filter('wp_audio_shortcode', [$this, 'rewrite_content'], 999);
    }

    /**
     * Rewrite URL
     *
     * @since 0.1.0
     * @param string   $url           Image URL.
     * @param int|null $attachment_id Attachment ID.
     * @return string
     */
    public function rewrite_url($url, $attachment_id = null) {
        // Check context NOW (lazy evaluation)
        // By the time this hook executes, WordPress has parsed the request
        // and all constants (REST_REQUEST, DOING_AJAX, etc.) are defined
        if ($this->is_unsafe_context()) {
            return $url;
        }

        // $processing prevents infinite recursion in rewrite_content()
        if ($this->processing || !$this->should_rewrite($url)) {
            return $url;
        }

        return $this->build_cdn_url($url);
    }

    /**
     * Rewrite image src array
     *
     * @since 0.1.0
     * @param array|false $image         Image data array or false.
     * @param int         $attachment_id Attachment ID.
     * @param string      $size          Image size.
     * @param bool        $icon          Whether to use icon.
     * @return array|false
     */
    public function rewrite_image_src($image, $attachment_id, $size, $icon) {
        // Check context (lazy evaluation)
        if ($this->is_unsafe_context()) {
            return $image;
        }

        if (!is_array($image) || $this->processing) {
            return $image;
        }

        if (!empty($image[0]) && $this->should_rewrite($image[0])) {
            $image[0] = $this->build_cdn_url($image[0]);
        }

        return $image;
    }

    /**
     * Rewrite srcset
     *
     * @since 0.1.0
     * @param array  $sources       Srcset sources array.
     * @param array  $size_array    Size array.
     * @param string $image_src     Image source URL.
     * @param array  $image_meta    Image metadata.
     * @param int    $attachment_id Attachment ID.
     * @return array
     */
    public function rewrite_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        // Check context (lazy evaluation)
        if ($this->is_unsafe_context()) {
            return $sources;
        }

        if (!is_array($sources) || $this->processing) {
            return $sources;
        }

        foreach ($sources as &$source) {
            if (!empty($source['url']) && $this->should_rewrite($source['url'])) {
                $source['url'] = $this->build_cdn_url($source['url']);
            }
        }

        return $sources;
    }

    /**
     * Get true origin URL from any URL type (origin/CDN)
     *
     * SINGLE SOURCE OF TRUTH for origin extraction.
     *
     * @since 0.1.0
     * @param string $url Input URL (origin or CDN).
     * @return string Origin URL.
     */
    private function get_true_origin($url) {
        if (empty($url)) {
            return $url;
        }

        // If already origin URL, return as-is
        if (!$this->is_cdn_url($url)) {
            return $url;
        }

        // Extract origin from CDN URL
        // Format: https://cdn-domain/origin-domain/path
        // Result: https://origin-domain/path
        $parsed = wp_parse_url($url);

        // Handle wp_parse_url() failure
        if ($parsed === false || !is_array($parsed) || empty($parsed['path'])) {
            return $url;
        }

        $path = trim($parsed['path'], '/');
        $path_parts = explode('/', $path, 2);

        if (count($path_parts) !== 2) {
            // Malformed CDN URL - return as-is
            return $url;
        }

        // Reconstruct origin URL preserving query string and fragment
        $origin_url = 'https://' . $path_parts[0] . '/' . $path_parts[1];

        // Preserve query string if present
        if (!empty($parsed['query'])) {
            $origin_url .= '?' . $parsed['query'];
        }

        // Preserve fragment if present
        if (!empty($parsed['fragment'])) {
            $origin_url .= '#' . $parsed['fragment'];
        }

        return $origin_url;
    }

    /**
     * Build onload handler
     *
     * Adds the imgpro-loaded class when image loads successfully.
     * This works with CSS to prevent broken image flash.
     *
     * @since 0.1.5
     * @return string JavaScript onload handler.
     */
    private function get_onload_handler() {
        return "this.classList.add('imgpro-loaded')";
    }

    /**
     * Build onerror fallback handler
     *
     * Creates inline JavaScript that falls back to origin URL on CDN failure.
     * The origin URL is extracted directly from the CDN URL path.
     *
     * Single-domain architecture: CDN URL contains origin domain in path.
     * Example: https://px.img.pro/example.com/path/image.jpg
     *          → Falls back to https://example.com/path/image.jpg
     *
     * Note: Uses string manipulation instead of URL constructor for
     * maximum compatibility in inline handler context.
     *
     * @since 0.1.5
     * @return string JavaScript onerror handler.
     */
    private function get_onerror_handler() {
        // Fallback logic using string manipulation (no URL constructor):
        // 1. Check if not already in fallback state
        // 2. Mark as fallback='1' (trying origin)
        // 3. Use currentSrc (the actual URL that failed, could be from srcset)
        // 4. Split URL: ['https:', '', 'cdn-domain', 'origin-domain', 'path', ...]
        // 5. Extract parts after CDN domain (index 3+)
        // 6. Set new onerror to handle origin failure (sets fallback='2')
        // 7. Remove srcset to prevent browser choosing other CDN URLs
        // 8. Reconstruct and set origin URL
        //
        // Example: https://px.img.pro/example.com/wp-content/image.jpg
        //   currentSrc.split('/') = ['https:', '', 'px.img.pro', 'example.com', 'wp-content', 'image.jpg']
        //   p = slice(3) = ['example.com', 'wp-content', 'image.jpg']
        //   result = 'https://' + p[0] + '/' + p.slice(1).join('/') = 'https://example.com/wp-content/image.jpg'
        //
        // Note: currentSrc gives the actual URL the browser tried to load,
        // which may be from srcset rather than src attribute.
        $handler = "if (!this.dataset.fallback) { "
                 . "this.dataset.fallback = '1'; "
                 . "var u = this.currentSrc || this.src; "
                 . "var p = u.split('/').slice(3); "
                 . "this.onerror = function() { this.dataset.fallback = '2'; this.onerror = null; }; "
                 . "this.removeAttribute('srcset'); "
                 . "this.src = 'https://' + p[0] + '/' + p.slice(1).join('/'); "
                 . "}";

        return $handler;
    }

    /**
     * Build onerror fallback handler for video/audio elements
     *
     * Creates inline JavaScript that falls back to origin URLs on CDN failure.
     * Uses the same URL conversion pattern as get_onerror_handler() for images.
     * Unlike images, video/audio may have multiple source elements that all
     * need to be rewritten, plus a poster attribute for videos.
     *
     * IMPORTANT: Only transforms URLs that were marked as CDN URLs:
     * - this.src only if data-imgpro-cdn is set on the element
     * - this.poster only if data-imgpro-poster is set on the element
     * - source children only if they have data-imgpro-cdn
     * This prevents corrupting non-CDN URLs (e.g., YouTube embeds).
     *
     * @since 1.0
     * @return string JavaScript onerror handler for media elements.
     */
    private function get_media_onerror_handler() {
        // Fallback logic for video/audio (same pattern as images):
        // 1. Check if not already in fallback state
        // 2. Mark as fallback='1' (trying origin)
        // 3. Track if any URLs were transformed
        // 4. Rewrite direct src ONLY if data-imgpro-cdn is set
        // 5. Rewrite all child source elements with data-imgpro-cdn
        // 6. Rewrite poster ONLY if data-imgpro-poster is set
        // 7. Only call load() if something was transformed (avoid unnecessary retries)
        $handler = "if (!this.dataset.fallback) { "
                 . "this.dataset.fallback = '1'; "
                 . "var changed = false; "
                 // Rewrite direct src only if marked as CDN
                 . "if (this.src && this.dataset.imgproCdn) { var p = this.src.split('/').slice(3); this.src = 'https://' + p[0] + '/' + p.slice(1).join('/'); changed = true; } "
                 // Rewrite source children (only those with data-imgpro-cdn)
                 . "var sources = this.querySelectorAll('source[data-imgpro-cdn]'); "
                 . "for (var i = 0; i < sources.length; i++) { var sp = sources[i].src.split('/').slice(3); sources[i].src = 'https://' + sp[0] + '/' + sp.slice(1).join('/'); changed = true; } "
                 // Rewrite poster only if marked as CDN
                 . "if (this.poster && this.dataset.imgproPoster) { var pp = this.poster.split('/').slice(3); this.poster = 'https://' + pp[0] + '/' + pp.slice(1).join('/'); changed = true; } "
                 // Only reload if we actually changed something
                 . "if (changed) { this.onerror = function() { this.dataset.fallback = '2'; this.onerror = null; }; this.load(); } "
                 . "}";

        return $handler;
    }

    /**
     * Rewrite image attributes
     *
     * Processes images generated by wp_get_attachment_image()
     *
     * ARCHITECTURE:
     * - ALWAYS processes every image (no early returns except validation)
     * - ALWAYS sets src to CDN URL
     * - Adds onerror handler for fallback to origin
     * - Runs at priority 999 to override other plugins
     *
     * @since 0.1.0
     * @param array        $attributes Image attributes.
     * @param WP_Post      $attachment Attachment post object.
     * @param string|array $size       Image size.
     * @return array
     */
    public function rewrite_attributes($attributes, $attachment, $size) {
        // Check context (lazy evaluation)
        if ($this->is_unsafe_context()) {
            return $attributes;
        }

        if (empty($attributes['src'])) {
            return $attributes;
        }

        // Get true origin URL (extracts if already CDN)
        $origin_url = $this->get_true_origin($attributes['src']);

        // Skip if not a valid image URL
        if (!$this->should_rewrite($origin_url)) {
            return $attributes;
        }

        // Build CDN URL from origin
        $cdn_url = $this->build_cdn_url($origin_url);

        // Set src to CDN
        $attributes['src'] = $cdn_url;

        // Add data attribute for identification
        $attributes['data-imgpro-cdn'] = '1';

        // Add onload handler (adds imgpro-loaded class for CSS visibility)
        $attributes['onload'] = $this->get_onload_handler();

        // Add onerror fallback handler
        $attributes['onerror'] = $this->get_onerror_handler();

        return $attributes;
    }

    /**
     * Rewrite content HTML
     *
     * Processes media elements in HTML content that weren't processed by rewrite_attributes()
     *
     * ARCHITECTURE:
     * - ONLY processes elements WITHOUT data-imgpro-cdn (not yet processed)
     * - NEVER modifies elements already processed by rewrite_attributes()
     * - Uses WP_HTML_Tag_Processor for safe, spec-compliant HTML parsing (requires WP 6.2+)
     *
     * @since 0.1.0
     * @since 0.2.0 Added video, audio, and source tag support
     * @param string $content HTML content.
     * @return string
     */
    public function rewrite_content($content) {
        // Check context (lazy evaluation)
        if ($this->is_unsafe_context()) {
            return $content;
        }

        // $processing flag prevents infinite recursion when content filters call each other
        if ($this->processing || empty($content)) {
            return $content;
        }

        // Early bail-out: Skip processing if no media tags present
        // This is a performance optimization for text-only content
        $has_media_tags = false;
        $tag_patterns = ['<img', '<amp-img', '<amp-anim', '<video', '<audio', '<source'];

        foreach ($tag_patterns as $pattern) {
            if (false !== stripos($content, $pattern)) {
                $has_media_tags = true;
                break;
            }
        }

        if (!$has_media_tags) {
            return $content;
        }

        $this->processing = true;

        // Use WordPress HTML Tag Processor (WP 6.2+) for safe HTML parsing
        // This is more robust than regex and handles malformed HTML gracefully
        $content = $this->rewrite_content_with_tag_processor($content);

        $this->processing = false;

        return $content;
    }

    /**
     * Rewrite content using WP_HTML_Tag_Processor (modern approach)
     *
     * Processes images, videos, audio, and source elements.
     *
     * @since 0.1.0
     * @since 0.2.0 Added video, audio, and source tag support
     * @param string $content HTML content.
     * @return string Modified content.
     */
    private function rewrite_content_with_tag_processor($content) {
        $processor = new WP_HTML_Tag_Processor($content);

        // All tags we process
        $image_tags = ['IMG', 'AMP-IMG', 'AMP-ANIM'];
        $media_tags = ['VIDEO', 'AUDIO', 'SOURCE'];
        $all_tags = array_merge($image_tags, $media_tags);

        while ($processor->next_tag()) {
            $tag = $processor->get_tag();

            // Skip if not a media tag
            if (!in_array($tag, $all_tags, true)) {
                continue;
            }

            // Skip if already processed (has our data-imgpro-cdn attribute)
            if ($processor->get_attribute('data-imgpro-cdn')) {
                continue;
            }

            // Get src attribute
            $src = $processor->get_attribute('src');

            // Process src if present and valid
            if (!empty($src)) {
                $origin_url = $this->get_true_origin($src);

                if ($this->should_rewrite($origin_url)) {
                    $cdn_url = $this->build_cdn_url($origin_url);
                    $processor->set_attribute('src', esc_url($cdn_url));
                    $processor->set_attribute('data-imgpro-cdn', '1');

                    // Images: add onload for CSS class and onerror for fallback
                    if (in_array($tag, $image_tags, true)) {
                        $processor->set_attribute('onload', $this->get_onload_handler());
                        $processor->set_attribute('onerror', $this->get_onerror_handler());
                    }
                }
            }

            // VIDEO/AUDIO: Always add onerror handler for CDN fallback
            // This is needed even without direct src, because:
            // - WordPress videos typically use <source> children, not src attribute
            // - The onerror handler rewrites all child sources with data-imgpro-cdn
            // - If no CDN sources exist, the handler is a harmless no-op
            // Note: SOURCE elements don't get onerror - error fires on parent media element
            if ($tag === 'VIDEO' || $tag === 'AUDIO') {
                $processor->set_attribute('onerror', $this->get_media_onerror_handler());
            }

            // VIDEO tag: also process 'poster' attribute (thumbnail image)
            if ($tag === 'VIDEO') {
                $poster = $processor->get_attribute('poster');
                if (!empty($poster)) {
                    $origin_poster = $this->get_true_origin($poster);
                    if ($this->should_rewrite($origin_poster)) {
                        $processor->set_attribute('poster', esc_url($this->build_cdn_url($origin_poster)));
                        // Mark poster as CDN so onerror handler knows to transform it
                        $processor->set_attribute('data-imgpro-poster', '1');
                    }
                }
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Check if URL is a CDN URL
     *
     * @since 0.1.0
     * @param string $url URL to check.
     * @return bool
     */
    private function is_cdn_url($url) {
        if (empty($url) || !is_string($url)) {
            return false;
        }
        $cdn_url = $this->settings->get('cdn_url');
        return !empty($cdn_url) && strpos($url, $cdn_url) !== false;
    }

    /**
     * Check if URL should be rewritten
     *
     * @since 0.1.0
     * @since 0.2.0 Now supports video, audio, and HLS files
     * @param string $url URL to check.
     * @return bool
     */
    private function should_rewrite($url) {
        if (empty($url) || !is_string($url)) {
            return false;
        }

        // Already CDN URL?
        if ($this->is_cdn_url($url)) {
            return false;
        }

        // Check source URLs (allowed domains) - stored locally in settings
        $source_urls = $this->settings->get('source_urls', []);
        if (!empty($source_urls)) {
            $url_host = wp_parse_url($url, PHP_URL_HOST);
            if (!$url_host || !$this->is_domain_allowed($url_host, $source_urls)) {
                return false;
            }
        }

        // Must be a supported media file (images, video, audio, HLS)
        if (!$this->is_media_url($url)) {
            return false;
        }

        return true;
    }

    /**
     * Check if URL is a supported media file
     *
     * Supports images, video, audio, and HLS streaming files.
     *
     * @since 0.2.0
     * @param string $url URL to check.
     * @return bool True if URL points to a supported media file.
     */
    private function is_media_url($url) {
        /**
         * Filter the list of allowed media extensions
         *
         * @since 1.0
         * @param array $extensions List of file extensions (without dots)
         */
        $extensions = apply_filters('imgpro_media_extensions', [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
            'bmp', 'tiff', 'ico', 'heic', 'heif',
            // Video
            'mp4', 'm4v', 'webm', 'ogv', 'mov', 'mkv',
            // Audio
            'mp3', 'ogg', 'wav', 'm4a', 'flac', 'aac', 'weba',
            // HLS
            'm3u8', 'ts',
        ]);

        $path = wp_parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $extensions, true);
    }

    /**
     * Check if domain matches allowed domains (with subdomain support)
     *
     * @since 0.1.0
     * @param string $host            Host to check.
     * @param array  $allowed_domains Allowed domains list.
     * @return bool
     */
    private function is_domain_allowed($host, $allowed_domains) {
        if (empty($host) || empty($allowed_domains)) {
            return false;
        }

        $host = strtolower($host);

        foreach ($allowed_domains as $domain) {
            $domain = strtolower(trim($domain));

            if (empty($domain)) {
                continue;
            }

            // Exact match
            if ($host === $domain) {
                return true;
            }

            // Subdomain match: www.example.com matches example.com
            if (substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build CDN URL
     *
     * @since 0.1.0
     * @param string $url Original image URL.
     * @return string CDN URL or original URL if conversion fails.
     */
    private function build_cdn_url($url) {
        // Normalize first to ensure consistent cache keys
        $normalized = $this->normalize_url($url);
        $cache_key = 'cdn_' . md5($normalized);

        if (isset($this->url_cache[$cache_key])) {
            return $this->url_cache[$cache_key];
        }

        $parsed = wp_parse_url($normalized);

        // wp_parse_url() can return false on severely malformed URLs
        // Cache the normalized URL to maintain consistency (cache key is based on normalized)
        if ($parsed === false || !is_array($parsed) || empty($parsed['host']) || empty($parsed['path'])) {
            $this->url_cache[$cache_key] = $normalized;
            return $normalized;
        }

        $cdn_domain = $this->settings->get('cdn_url');

        // Guard against empty domain - cache and return normalized URL
        if (empty($cdn_domain)) {
            $this->url_cache[$cache_key] = $normalized;
            return $normalized;
        }

        // Build CDN URL preserving query string and fragment
        $cdn_url = sprintf('https://%s/%s%s', $cdn_domain, $parsed['host'], $parsed['path']);

        // Append query string if present
        if (!empty($parsed['query'])) {
            $cdn_url .= '?' . $parsed['query'];
        }

        // Append fragment if present
        if (!empty($parsed['fragment'])) {
            $cdn_url .= '#' . $parsed['fragment'];
        }

        $this->url_cache[$cache_key] = $cdn_url;
        return $cdn_url;
    }

    /**
     * Normalize URL
     *
     * Converts relative and protocol-relative URLs to absolute URLs.
     *
     * @since 0.1.0
     * @param string $url URL to normalize.
     * @return string Normalized absolute URL.
     */
    private function normalize_url($url) {
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        if (substr($url, 0, 2) === '//') {
            return 'https:' . $url;
        }

        if (substr($url, 0, 1) === '/') {
            $home = wp_parse_url(home_url());
            // Handle wp_parse_url() failure gracefully
            if ($home === false || !is_array($home)) {
                return $url;
            }
            $scheme = $home['scheme'] ?? 'https';
            $host = $home['host'] ?? 'localhost';
            return $scheme . '://' . $host . $url;
        }

        return rtrim(home_url(), '/') . '/' . ltrim($url, '/');
    }

}
