<?php
/**
 * ImgPro CDN URL Rewriter
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Swaps media library URLs for their img.pro copies on the frontend
 *
 * Only files that finished syncing are swapped; everything else keeps its
 * original URL. Every swapped image carries its original URL so the
 * browser can fall back to it if img.pro cannot serve the file.
 *
 * Rewriting happens at render time. Database content is never changed.
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
     * Prevents re-entrant content processing
     *
     * @since 0.1.0
     * @var bool
     */
    private $processing = false;

    /**
     * Cached result of is_unsafe_context()
     *
     * @since 0.1.0
     * @var bool|null
     */
    private $is_unsafe_context_cache = null;

    /**
     * Uploads base URL without scheme, e.g. //example.com/wp-content/uploads/
     *
     * @since 2.0.0
     * @var string|null
     */
    private $upload_base = null;

    /**
     * Attributes that can hold an image URL or srcset
     *
     * @since 2.0.0
     * @var array attribute => true when it holds a srcset
     */
    const URL_ATTRIBUTES = [
        'src'              => false,
        'srcset'           => true,
        'data-src'         => false,
        'data-srcset'      => true,
        'data-lazy-src'    => false,
        'data-lazy-srcset' => true,
    ];

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
     * Register rewriting hooks when serving from img.pro is on
     *
     * @since 0.1.0
     * @return void
     */
    public function init() {
        if (!$this->settings->is_serving()) {
            return;
        }

        // Images are swapped where markup is produced, not in
        // wp_get_attachment_url() or wp_get_attachment_image_src():
        // WordPress builds size URLs and srcset from those by matching
        // upload paths, which an img.pro URL does not contain.
        // Run late (priority 999) to see the final src from lazy loading plugins
        add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_attributes'], 999, 3);
        add_filter('post_thumbnail_url', [$this, 'rewrite_url'], 10, 1);

        // Rendered HTML
        add_filter('the_content', [$this, 'rewrite_content'], 999);
        add_filter('post_thumbnail_html', [$this, 'rewrite_content'], 999);
        add_filter('widget_text', [$this, 'rewrite_content'], 999);
        add_filter('widget_block_content', [$this, 'rewrite_content'], 999);
    }

    /**
     * Rewrite a single image URL
     *
     * @since 2.0.0
     * @param string|false $url Image URL.
     * @return string|false
     */
    public function rewrite_url($url) {
        if ($this->is_unsafe_context() || $this->processing || !is_string($url) || '' === $url) {
            return $url;
        }

        $map = $this->lookup([$url]);
        return isset($map[$url]) ? $map[$url] : $url;
    }

    /**
     * Rewrite attributes of images rendered by wp_get_attachment_image()
     *
     * @since 0.1.0
     * @param array        $attributes Image attributes.
     * @param WP_Post      $attachment Attachment post.
     * @param string|int[] $size       Requested size.
     * @return array
     */
    public function rewrite_attributes($attributes, $attachment, $size) {
        if ($this->is_unsafe_context() || $this->processing || empty($attributes['src'])) {
            return $attributes;
        }

        $values = [];
        foreach (self::URL_ATTRIBUTES as $name => $is_srcset) {
            if (!empty($attributes[$name]) && is_string($attributes[$name])) {
                $values[$name] = $attributes[$name];
            }
        }

        $map = $this->lookup($this->collect_urls($values));
        $changed = false;
        foreach ($values as $name => $value) {
            $attributes[$name] = $this->replace_value($value, self::URL_ATTRIBUTES[$name], $map);
            $changed = $changed || ($attributes[$name] !== $value);
        }

        if ($changed) {
            $attributes['data-imgpro-cdn']    = '1';
            $attributes['data-imgpro-origin'] = $this->absolute_url($values['src']);
            $attributes['onerror']            = $this->get_onerror_handler();
        }

        return $attributes;
    }

    /**
     * Rewrite image URLs in rendered HTML
     *
     * Handles img and AMP images, including common lazy loading
     * attributes, plus links that point straight at an image file
     * (lightboxes).
     *
     * @since 0.1.0
     * @param string $content HTML.
     * @return string
     */
    public function rewrite_content($content) {
        if ($this->is_unsafe_context() || $this->processing || empty($content) || !is_string($content)) {
            return $content;
        }

        // Skip the HTML parser when nothing points at the uploads folder
        if (false === strpos($content, $this->get_upload_path())) {
            return $content;
        }

        $this->processing = true;

        // First pass: collect every candidate URL for one batched lookup
        $urls = [];
        $processor = new WP_HTML_Tag_Processor($content);
        while ($processor->next_tag()) {
            $values = $this->get_tag_values($processor);
            $urls = array_merge($urls, $this->collect_urls($values));
        }

        $map = $this->lookup($urls);
        if (empty($map)) {
            $this->processing = false;
            return $content;
        }

        // Second pass: swap URLs
        $processor = new WP_HTML_Tag_Processor($content);
        while ($processor->next_tag()) {
            $tag    = $processor->get_tag();
            $values = $this->get_tag_values($processor);

            $changed = false;
            foreach ($values as $name => $value) {
                $is_srcset = 'href' === $name ? false : self::URL_ATTRIBUTES[$name];
                $new_value = $this->replace_value($value, $is_srcset, $map);
                if ($new_value !== $value) {
                    $processor->set_attribute($name, $new_value);
                    $changed = true;
                }
            }

            // Fallback to the original file when img.pro cannot serve it
            $src = $values['src'] ?? '';
            if ($changed && '' !== $src && in_array($tag, ['IMG', 'AMP-IMG', 'AMP-ANIM'], true) && !$processor->get_attribute('data-imgpro-origin')) {
                $processor->set_attribute('data-imgpro-cdn', '1');
                $processor->set_attribute('data-imgpro-origin', $this->absolute_url($src));
                if ('IMG' === $tag) {
                    $processor->set_attribute('onerror', $this->get_onerror_handler());
                }
            }
        }

        $this->processing = false;

        return $processor->get_updated_html();
    }

    /**
     * URL-bearing attributes of the current tag
     *
     * @since 2.0.0
     * @param WP_HTML_Tag_Processor $processor Processor positioned on a tag.
     * @return array attribute => value
     */
    private function get_tag_values($processor) {
        $tag = $processor->get_tag();

        if ('A' === $tag) {
            $href = $processor->get_attribute('href');
            return (is_string($href) && '' !== $href) ? ['href' => $href] : [];
        }

        // <picture> sources are left alone: the img fallback cannot undo them
        if (!in_array($tag, ['IMG', 'AMP-IMG', 'AMP-ANIM'], true)) {
            return [];
        }

        $values = [];
        foreach (array_keys(self::URL_ATTRIBUTES) as $name) {
            $value = $processor->get_attribute($name);
            if (is_string($value) && '' !== $value) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Collect URLs from attribute values
     *
     * @since 2.0.0
     * @param array $values attribute => value.
     * @return string[]
     */
    private function collect_urls($values) {
        $urls = [];
        foreach ($values as $name => $value) {
            $is_srcset = isset(self::URL_ATTRIBUTES[$name]) ? self::URL_ATTRIBUTES[$name] : false;
            if ($is_srcset) {
                foreach ($this->parse_srcset($value) as $candidate) {
                    $urls[] = $candidate[0];
                }
            } else {
                $urls[] = trim($value);
            }
        }
        return $urls;
    }

    /**
     * Swap URLs inside an attribute value
     *
     * @since 2.0.0
     * @param string $value     Attribute value.
     * @param bool   $is_srcset Whether the value is a srcset list.
     * @param array  $map       original URL => img.pro URL.
     * @return string
     */
    private function replace_value($value, $is_srcset, $map) {
        if (!$is_srcset) {
            $url = trim($value);
            return isset($map[$url]) ? $map[$url] : $value;
        }

        $changed = false;
        $parts = [];
        foreach ($this->parse_srcset($value) as $candidate) {
            if (isset($map[$candidate[0]])) {
                $candidate[0] = $map[$candidate[0]];
                $changed = true;
            }
            $parts[] = trim($candidate[0] . ' ' . $candidate[1]);
        }

        return $changed ? implode(', ', $parts) : $value;
    }

    /**
     * Split a srcset value into [url, descriptor] pairs
     *
     * @since 2.0.0
     * @param string $srcset Attribute value.
     * @return array
     */
    private function parse_srcset($srcset) {
        $candidates = [];
        foreach (preg_split('/,\s+/', trim($srcset)) as $candidate) {
            $candidate = trim($candidate, " \t\n\r\0\x0B,");
            if ('' === $candidate) {
                continue;
            }
            $pieces = preg_split('/\s+/', $candidate, 2);
            $candidates[] = [$pieces[0], $pieces[1] ?? ''];
        }
        return $candidates;
    }

    /**
     * Map original URLs to img.pro URLs for files that finished syncing
     *
     * @since 2.0.0
     * @param string[] $urls URLs as they appear in the page.
     * @return array original URL => img.pro URL
     */
    private function lookup($urls) {
        $paths = [];
        foreach (array_unique($urls) as $url) {
            $path = $this->url_to_path($url);
            if (null !== $path) {
                $paths[$url] = $path;
            }
        }

        if (empty($paths)) {
            return [];
        }

        $found = ImgPro_CDN_Files::get_urls(array_values($paths));
        $map = [];
        foreach ($paths as $url => $path) {
            if (isset($found[$path])) {
                $map[$url] = $found[$path];
            }
        }

        return $map;
    }

    /**
     * Path relative to the uploads folder for a URL, if it points there
     *
     * Accepts absolute, protocol-relative and root-relative URLs on the
     * site's own host. Query strings and fragments are ignored.
     *
     * @since 2.0.0
     * @param string $url URL.
     * @return string|null
     */
    private function url_to_path($url) {
        if (!is_string($url) || '' === $url) {
            return null;
        }

        $url = preg_replace('/[?#].*$/', '', trim($url));
        $base = $this->get_upload_base();

        if ('/' === substr($url, 0, 1) && '//' !== substr($url, 0, 2)) {
            $prefix = $this->get_upload_path();
            $rest = (0 === strpos($url, $prefix)) ? substr($url, strlen($prefix)) : null;
        } else {
            $url = preg_replace('#^https?:#i', '', $url);
            $rest = (0 === stripos($url, $base)) ? substr($url, strlen($base)) : null;
        }

        if (null === $rest || '' === $rest || false !== strpos($rest, '..')) {
            return null;
        }

        return rawurldecode($rest);
    }

    /**
     * Make a page URL absolute for use as the fallback
     *
     * @since 2.0.0
     * @param string $url URL as it appears in the page.
     * @return string
     */
    private function absolute_url($url) {
        $url = trim($url);
        if ('//' === substr($url, 0, 2)) {
            return set_url_scheme('http:' . $url);
        }
        if ('/' === substr($url, 0, 1)) {
            return home_url($url);
        }
        return $url;
    }

    /**
     * Uploads base URL without scheme, with trailing slash
     *
     * @since 2.0.0
     * @return string
     */
    private function get_upload_base() {
        if (null === $this->upload_base) {
            $uploads = wp_get_upload_dir();
            $this->upload_base = trailingslashit(preg_replace('#^https?:#i', '', $uploads['baseurl']));
        }
        return $this->upload_base;
    }

    /**
     * Path part of the uploads base URL, e.g. /wp-content/uploads/
     *
     * @since 2.0.0
     * @return string
     */
    private function get_upload_path() {
        $path = wp_parse_url('http:' . $this->get_upload_base(), PHP_URL_PATH);
        return trailingslashit($path ? $path : '/');
    }

    /**
     * Inline handler that swaps a failed img.pro image for the original
     *
     * Removing srcset stops the browser from picking another img.pro
     * candidate; clearing the handler prevents loops if the original
     * fails too.
     *
     * @since 0.1.0
     * @return string
     */
    private function get_onerror_handler() {
        return "this.onerror=null;this.removeAttribute('srcset');this.src=this.getAttribute('data-imgpro-origin');";
    }
}
