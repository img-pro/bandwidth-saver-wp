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
 * Images a page loads from other websites are copied too, when the site
 * allows it: img.pro imports them from their address (see
 * ImgPro_CDN_Files::get_remote_urls()). An img src on another host that
 * WordPress matched to one of this site's own files (it then adds that
 * file's srcset) is that file, and uses its copy.
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
     * Site the settings instance belongs to
     *
     * @since 2.0.0
     * @var int
     */
    private $blog_id;

    /**
     * Uploads base URLs without scheme, by site ID,
     * e.g. //example.com/wp-content/uploads/
     *
     * @since 2.0.0
     * @var string[]
     */
    private $upload_bases = [];

    /**
     * Whether other sites of a network serve from img.pro, by site ID
     *
     * @since 2.0.0
     * @var bool[]
     */
    private $serving = [];

    /**
     * Whether images from other websites are copied, by site ID
     *
     * @since 2.0.0
     * @var bool[]
     */
    private $remote = [];

    /**
     * Hosts that are this site's own, by site ID
     *
     * @since 2.0.0
     * @var array
     */
    private $own_hosts = [];

    /**
     * URLs the last lookup swapped for copies of images from other websites
     *
     * @since 2.0.0
     * @var bool[]
     */
    private $remote_hits = [];

    /**
     * Extensions of files img.pro does not accept, for addresses that name one
     *
     * @since 2.0.0
     * @var string[]
     */
    const UNSUPPORTED_EXTENSIONS = ['svg', 'svgz', 'bmp', 'ico', 'tif', 'tiff', 'eps', 'psd', 'pdf'];

    /**
     * Top-level domains that never resolve on the public internet, where
     * img.pro could not fetch an image
     *
     * @since 2.0.0
     * @var string[]
     */
    const PRIVATE_TLDS = ['test', 'local', 'localhost', 'invalid', 'example', 'internal', 'lan', 'home', 'corp', 'intranet', 'arpa', 'localdomain'];

    /**
     * Query parameters of addresses that change from one view to the next
     * (signed or expiring links, cache-busters); each would become a new copy
     *
     * @since 2.0.0
     * @var string[]
     */
    const VOLATILE_PARAMS = ['signature', 'sig', 'expires', 'expiry', 'exp', 'token', 'access_token', '__token__', 'hdnts', 'verify', 'authorization', 'policy', 'key-pair-id', 'hmac', 'oh', 'oe', 'se', 'st', 'sv', '_', 't', 'ts', 'time', 'timestamp', 'cb', 'cachebust', 'cachebuster', 'nocache', 'rand', 'random', 'rnd'];

    /**
     * Prefixes of such parameters (cloud storage signatures)
     *
     * @since 2.0.0
     * @var string[]
     */
    const VOLATILE_PARAM_PREFIXES = ['x-amz-', 'x-goog-', 'x-ms-'];

    /**
     * Hosts that serve tracking pixels and ads, whose requests have to keep
     * reaching them, matched with their subdomains
     *
     * @since 2.0.0
     * @var string[]
     */
    const TRACKING_HOSTS = [
        // Ads and affiliate networks (Commission Junction serves from its many domains)
        'amazon-adsystem.com', 'doubleclick.net', 'googlesyndication.com', 'googleadservices.com', 'adnxs.com', 'adsrvr.org', 'criteo.com', 'taboola.com', 'outbrain.com',
        'awin1.com', 'shareasale.com', 'impactradius.com', 'pxf.io', 'sjv.io', 'linksynergy.com',
        'tqlkg.com', 'ftjcfx.com', 'kqzyfj.com', 'jdoqocy.com', 'dpbolvw.net', 'anrdoezrs.net', 'tkqlhce.com', 'awltovhc.com', 'lduhtrp.net', 'emjcd.com',
        // Analytics and social pixels
        'google-analytics.com', 'googletagmanager.com', 'facebook.com', 'facebook.net', 'pixel.wp.com', 'stats.wp.com', 'scorecardresearch.com', 'quantserve.com', 'statcounter.com',
        'yandex.ru', 'clarity.ms', 'hubspot.com', 'bing.com', 'pinterest.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com', 'snapchat.com', 'reddit.com',
    ];

    /**
     * Most images from other websites one request queues for copying
     *
     * @since 2.0.0
     * @var int
     */
    const REMOTE_PER_REQUEST = 50;

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
        $this->blog_id  = get_current_blog_id();
    }

    /**
     * Whether the site WordPress is rendering serves from img.pro
     *
     * Hooks are only registered when this instance's own site serves, but
     * a network page can render another site's content inside
     * switch_to_blog(); that site's own setting decides for its images.
     *
     * @since 2.0.0
     * @return bool
     */
    private function is_serving_here() {
        $blog_id = get_current_blog_id();
        if ($blog_id === $this->blog_id) {
            return true;
        }
        if (!isset($this->serving[$blog_id])) {
            $settings = new ImgPro_CDN_Settings();
            $this->serving[$blog_id] = ImgPro_CDN_Settings::plugin_active_here() && $settings->is_serving();
        }
        return $this->serving[$blog_id];
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
        if ($this->is_unsafe_context() || $this->processing || !is_string($url) || '' === $url || !$this->is_serving_here()) {
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
        if ($this->is_unsafe_context() || $this->processing || !$this->is_serving_here()) {
            return $attributes;
        }

        $values = [];
        foreach (self::URL_ATTRIBUTES as $name => $is_srcset) {
            if (!empty($attributes[$name]) && is_string($attributes[$name])) {
                $values[$name] = $attributes[$name];
            }
        }
        if (empty($values)) {
            return $attributes;
        }

        $map = $this->lookup($this->collect_urls($values));
        $new_values = [];
        foreach ($values as $name => $value) {
            $new_value = $this->replace_value($value, self::URL_ATTRIBUTES[$name], $map);
            if ($new_value !== $value) {
                $new_values[$name] = $new_value;
            }
        }
        if (empty($new_values)) {
            return $attributes;
        }

        // Every swapped image carries its original URL; one without a
        // usable original keeps its local URLs
        $origin = $this->fallback_url($values);
        if ('' === $origin) {
            return $attributes;
        }

        $attributes = array_merge($attributes, $new_values);
        $attributes['data-imgpro-cdn']    = '1';
        $attributes['data-imgpro-origin'] = $origin;
        $attributes['onerror']            = $this->get_onerror_handler();

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
        if ($this->is_unsafe_context() || $this->processing || empty($content) || !is_string($content) || !$this->is_serving_here()) {
            return $content;
        }

        // Skip the HTML parser when nothing points at the uploads folder,
        // and there is no image another website could serve
        if (false === strpos($content, $this->get_upload_path()) && !($this->copies_remote() && (false !== stripos($content, '<img') || false !== stripos($content, '<amp-')))) {
            return $content;
        }

        $this->processing = true;

        // First pass: collect every candidate URL for one batched lookup
        $urls    = [];
        $aliases = [];
        $links   = [];
        $images  = [];
        $pixels  = [];
        $processor = new WP_HTML_Tag_Processor($content);
        while ($processor->next_tag()) {
            $values = $this->get_tag_values($processor);
            // An image with nothing to fall back to is never swapped (see the
            // second pass), so its files are not worth queueing
            if (!isset($values['href']) && !empty($values) && !self::has_image_source($values)) {
                continue;
            }
            $found  = $this->collect_urls($values);
            $urls   = array_merge($urls, $found);
            foreach ($found as $url) {
                if (isset($values['href'])) {
                    $links[$url] = true;
                } else {
                    $images[$url] = true;
                }
            }

            // A tracking pixel (declared 1x1, or hidden at 0) has to keep
            // reaching its own server: never copy it from another website
            if (!isset($values['href']) && self::is_pixel($processor)) {
                foreach ($found as $url) {
                    $pixels[$url] = true;
                }
            }

            // A src on another host that WordPress matched to one of this
            // site's files (that file's sizes are the srcset it added)
            if (isset($values['src'], $values['srcset']) && null === $this->url_to_path($values['src'])) {
                $match = $this->match_srcset_candidate(trim($values['src']), $values['srcset']);
                if (null !== $match) {
                    $aliases[trim($values['src'])] = $match['path'];
                }
            }
        }

        // Only an address some image shows is an image for certain; one that
        // is only a link's target must name an image file to be copied
        $map = $this->lookup($urls, $aliases, array_diff_key($links, $images), $pixels);
        if (empty($map)) {
            $this->processing = false;
            return $content;
        }

        // Second pass: swap URLs
        $processor = new WP_HTML_Tag_Processor($content);
        while ($processor->next_tag()) {
            $tag    = $processor->get_tag();
            $values = $this->get_tag_values($processor);

            $new_values = [];
            foreach ($values as $name => $value) {
                $is_srcset = 'href' === $name ? false : self::URL_ATTRIBUTES[$name];
                $new_value = $this->replace_value($value, $is_srcset, $map);
                if ($new_value !== $value) {
                    $new_values[$name] = $new_value;
                }
            }
            if (empty($new_values)) {
                continue;
            }

            // Fallback to the original file when img.pro cannot serve it
            if ('A' !== $tag && !$processor->get_attribute('data-imgpro-origin')) {
                $origin = $this->fallback_url($values, isset($values['src']) && isset($this->remote_hits[trim($values['src'])]));
                if ('IMG' === $tag && '' === $origin) {
                    // Every swapped image carries its original URL; one
                    // without a usable original keeps its local URLs
                    continue;
                }
                $processor->set_attribute('data-imgpro-cdn', '1');
                if ('' !== $origin) {
                    $processor->set_attribute('data-imgpro-origin', $origin);
                    if ('IMG' === $tag) {
                        $processor->set_attribute('onerror', $this->get_onerror_handler());
                    }
                }
            }

            foreach ($new_values as $name => $new_value) {
                $processor->set_attribute($name, $new_value);
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
     * @param string[] $urls    URLs as they appear in the page.
     * @param array    $aliases URLs on other hosts known to be one of this
     *                          site's files: URL => path in the uploads folder.
     * @param bool[]   $links   URLs that are only links' targets: URL => true.
     * @param bool[]   $skip    URLs never to copy from another website: URL => true.
     * @return array original URL => img.pro URL
     */
    private function lookup($urls, $aliases = [], $links = [], $skip = []) {
        $paths   = [];
        $sources = [];
        foreach (array_unique($urls) as $url) {
            $path = $aliases[$url] ?? $this->url_to_path($url);
            if (null !== $path) {
                $paths[$url] = $path;
                continue;
            }
            $source = isset($skip[$url]) ? null : $this->remote_source($url, isset($links[$url]));
            if (null !== $source) {
                $sources[$url] = $source;
            }
        }

        $map = [];
        if (!empty($paths)) {
            $found = ImgPro_CDN_Files::get_urls(array_values($paths));
            foreach ($paths as $url => $path) {
                if (isset($found[$path])) {
                    $map[$url] = $found[$path];
                }
            }
        }
        $this->remote_hits = [];
        if (!empty($sources)) {
            $found = ImgPro_CDN_Files::get_remote_urls(array_values($sources), $this->may_queue_remote());
            foreach ($sources as $url => $source) {
                if (isset($found[$source])) {
                    $map[$url] = $found[$source];
                    $this->remote_hits[$url] = true;
                }
            }
        }

        return $map;
    }

    /**
     * Address img.pro can import an image on another website from
     *
     * Takes absolute and protocol-relative URLs on a host that is not this
     * site's, with a public name img.pro can reach. Addresses naming a file
     * img.pro does not accept are left alone, and so are links that do not
     * name an image file: a link may lead anywhere, while an img always
     * shows an image.
     *
     * @since 2.0.0
     * @param string $url  URL as it appears in the page.
     * @param bool   $link Whether it is a link's href rather than an image's.
     * @return string|null Absolute URL without its fragment, or null.
     */
    private function remote_source($url, $link = false) {
        if (!$this->copies_remote()) {
            return null;
        }

        $url = trim((string) $url);
        if ('//' === substr($url, 0, 2)) {
            $url = 'https:' . $url;
        }
        $url = preg_replace('/#.*$/', '', $url);
        // Whitespace, quotes, angle brackets and backslashes never belong
        // in an address img.pro could fetch
        if (!preg_match('#^https?://#i', $url) || strlen($url) > 1000 || preg_match('/[\s<>"\x5C]/', $url)) {
            return null;
        }

        $parts = wp_parse_url($url);
        $host  = isset($parts['host']) ? strtolower(rtrim($parts['host'], '.')) : '';
        if ('' === $host || isset($parts['user']) || isset($parts['pass']) || isset($this->get_own_hosts()[$host])) {
            return null;
        }
        // Copies already on img.pro
        if ('img.pro' === $host || '.img.pro' === substr($host, -8)) {
            return null;
        }
        if (!self::is_public_host($host) || self::is_tracking_host($host)) {
            return null;
        }
        if (isset($parts['query']) && self::is_volatile_query($parts['query'])) {
            return null;
        }

        $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($extension, self::UNSUPPORTED_EXTENSIONS, true)) {
            return null;
        }
        if ($link && !in_array($extension, ImgPro_CDN_API::UPLOADABLE_EXTENSIONS, true)) {
            return null;
        }
        // Pages named after an image: wiki file pages (File:Example.jpg) and
        // file-sharing previews show the image inside a web page
        if ($link && (false !== strpos(wp_basename((string) ($parts['path'] ?? '')), ':') || 'dropbox.com' === $host || '.dropbox.com' === substr($host, -12))) {
            return null;
        }

        /**
         * Filter whether an image from another website is copied to img.pro
         *
         * @since 2.0.0
         * @param bool   $copy Whether to copy it.
         * @param string $url  The image's address.
         */
        return apply_filters('imgpro_cdn_copy_remote_image', true, $url) ? $url : null;
    }

    /**
     * Whether a host name is one img.pro can reach on the public internet
     *
     * img.pro refuses private addresses itself; leaving them out here
     * saves the import that would fail.
     *
     * @since 2.0.0
     * @param string $host Lowercase host name.
     * @return bool
     */
    private static function is_public_host($host) {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (false === strpos($host, '.')) {
            return false;
        }
        $tld = substr($host, strrpos($host, '.') + 1);
        return !in_array($tld, self::PRIVATE_TLDS, true);
    }

    /**
     * Whether a host serves tracking pixels or ads
     *
     * @since 2.0.0
     * @param string $host Lowercase host name.
     * @return bool
     */
    private static function is_tracking_host($host) {
        foreach (self::TRACKING_HOSTS as $tracker) {
            if ($host === $tracker || '.' . $tracker === substr($host, -strlen($tracker) - 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a query string makes an address change between views
     *
     * Signed or expiring links and cache-busters give the same picture a
     * new address on each view, and each address would become a new copy
     * in the App. The picture they show may also expire on purpose.
     *
     * @since 2.0.0
     * @param string $query Query string.
     * @return bool
     */
    private static function is_volatile_query($query) {
        foreach (explode('&', $query) as $pair) {
            $name  = strtolower(rawurldecode((string) strstr($pair . '=', '=', true)));
            $value = rawurldecode((string) substr((string) strstr($pair, '='), 1));
            if (in_array($name, self::VOLATILE_PARAMS, true)) {
                return true;
            }
            // A Unix time in seconds or milliseconds, as a value or as the
            // whole parameter (image.jpg?1791358318)
            if (preg_match('/^\d{10}(\d{3})?(\.\d+)?$/', '' !== $value ? $value : $name)) {
                return true;
            }
            foreach (self::VOLATILE_PARAM_PREFIXES as $prefix) {
                if (0 === strpos($name, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether an image's attributes name a picture other than a placeholder
     *
     * @since 2.0.0
     * @param array $values URL-bearing attribute values.
     * @return bool
     */
    private static function has_image_source($values) {
        foreach (['src', 'data-lazy-src', 'data-src'] as $name) {
            if (isset($values[$name]) && '' !== trim($values[$name]) && 0 !== stripos(trim($values[$name]), 'data:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the tag is a tracking pixel: declared at most 1 pixel wide or
     * high, or hidden (a hidden image still loads)
     *
     * @since 2.0.0
     * @param WP_HTML_Tag_Processor $processor Processor positioned on a tag.
     * @return bool
     */
    private static function is_pixel($processor) {
        foreach (['width', 'height'] as $name) {
            // Read as browsers do: the leading number, with or without px
            $value = $processor->get_attribute($name);
            if (is_string($value) && preg_match('/^\s*\+?(\d+(?:\.\d+)?)(?:e0)?\s*(px)?\s*$/i', $value, $match) && (float) $match[1] <= 1) {
                return true;
            }
        }
        if (null !== $processor->get_attribute('hidden')) {
            return true;
        }
        $style = $processor->get_attribute('style');
        if (is_string($style)) {
            $style = strtolower(preg_replace('/\s+/', '', $style));
            if (preg_match('/display:none|visibility:hidden|(?:^|;)(?:width|height):[01](?:\.0+)?(?:px)?(?:;|!|$)/', $style)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this request may queue new images from other websites
     *
     * Copies spend the App's image allowance, which the site's admins
     * manage. A preview, or a post that is not public yet, shows content no
     * admin has approved (a contributor's draft), so it only uses copies
     * that already exist.
     *
     * @since 2.0.0
     * @return bool
     */
    private function may_queue_remote() {
        if (is_preview()) {
            return false;
        }
        // Published content of any post type, testimonials and slides included
        $post = get_post();
        return !$post || is_post_status_viewable(get_post_status($post));
    }

    /**
     * Hosts that serve this site, which are never another website
     *
     * @since 2.0.0
     * @return bool[] host => true
     */
    private function get_own_hosts() {
        $blog_id = get_current_blog_id();
        if (!isset($this->own_hosts[$blog_id])) {
            $hosts = [];
            foreach ([home_url(), site_url(), 'http:' . $this->get_upload_base()] as $url) {
                $host = wp_parse_url($url, PHP_URL_HOST);
                if (is_string($host) && '' !== $host) {
                    // The same site with and without www
                    $host = strtolower($host);
                    $bare = preg_replace('/^www\./', '', $host);
                    $hosts[$bare]          = true;
                    $hosts['www.' . $bare] = true;
                }
            }
            $this->own_hosts[$blog_id] = $hosts;
        }
        return $this->own_hosts[$blog_id];
    }

    /**
     * Whether the site WordPress is rendering copies other websites' images
     *
     * @since 2.0.0
     * @return bool
     */
    private function copies_remote() {
        $blog_id = get_current_blog_id();
        if (!isset($this->remote[$blog_id])) {
            $settings = $blog_id === $this->blog_id ? $this->settings : new ImgPro_CDN_Settings();
            $this->remote[$blog_id] = (bool) $settings->get('remote');
        }
        return $this->remote[$blog_id];
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

            // The same uploads folder at another of the site's own hosts (www or not)
            if (null === $rest && '//' === substr($url, 0, 2)) {
                $host   = wp_parse_url('http:' . $url, PHP_URL_HOST);
                $path   = wp_parse_url('http:' . $url, PHP_URL_PATH);
                $prefix = $this->get_upload_path();
                if (is_string($host) && isset($this->get_own_hosts()[strtolower(rtrim($host, '.'))]) && is_string($path) && 0 === strpos($path, $prefix)) {
                    $rest = substr($path, strlen($prefix));
                }
            }
        }

        if (null === $rest || '' === $rest || false !== strpos($rest, '..')) {
            return null;
        }

        // Normalized like the file map's paths (backslashes, duplicate slashes)
        return ltrim(wp_normalize_path(rawurldecode($rest)), '/');
    }

    /**
     * Original URL an image falls back to when img.pro cannot serve it
     *
     * The fallback is the original of the image the browser shows. When
     * src points into the media library, that is src: a data-src beside it
     * is not necessarily for lazy loading (WooCommerce keeps the zoom image
     * there). Otherwise src is a lazy loader's placeholder, and the image is
     * the one it copies in from data-lazy-src or data-src.
     *
     * Without any lazy attributes, src is the image even when it is stale:
     * content from before a move (another host, a subfolder, a network
     * subsite) keeps its old address in src, while WordPress builds srcset
     * from the current uploads folder and the browser uses that. The old
     * address may no longer answer, so the fallback is the same file at
     * its current address.
     *
     * A copy of an image from another website falls back to that image's
     * own address.
     *
     * @since 2.0.0
     * @param array $values     Attribute values before swapping.
     * @param bool  $remote_src Whether src is swapped for a copy of an image
     *                          from another website.
     * @return string Fallback URL, or empty when there is none to offer.
     */
    private function fallback_url($values, $remote_src = false) {
        $src = isset($values['src']) ? trim($values['src']) : '';
        $own = '' !== $src ? $this->url_to_path($src) : null;

        $lazy_loaded = isset($values['data-src']) || isset($values['data-lazy-src'])
            || isset($values['data-srcset']) || isset($values['data-lazy-srcset']);

        // Beside lazy attributes, src is a placeholder; they decide below
        if ($remote_src && '' !== $src && !$lazy_loaded) {
            return $this->absolute_url($src);
        }
        $plain = '' !== $src && !$lazy_loaded && preg_match('#^(https?:)?//|^/#i', $src);

        // A stale src that WordPress matched to a srcset candidate (it
        // looks for the candidate's file at the end of src's path): the
        // candidate is the file at its current address
        if ($plain && isset($values['srcset'])) {
            $match = $this->match_srcset_candidate($src, $values['srcset']);
            if (null !== $match && $match['path'] !== $own) {
                return $this->absolute_url($match['url']);
            }
        }

        if (null !== $own) {
            return $this->absolute_url($src);
        }

        foreach (['data-lazy-src', 'data-src'] as $name) {
            $lazy = isset($values[$name]) ? trim($values[$name]) : '';
            if ('' !== $lazy && 0 !== stripos($lazy, 'data:')) {
                return $this->absolute_url($lazy);
            }
        }

        if ($plain) {
            // Same uploads path on another host: the same file on this one,
            // when this site has it (every WordPress site uses this path)
            $absolute = ('//' === substr($src, 0, 2)) ? 'http:' . $src : $src;
            $path     = wp_parse_url($absolute, PHP_URL_PATH);
            $prefix   = $this->get_upload_path();
            $relative = is_string($path) && 0 === strpos($path, $prefix) ? ltrim(wp_normalize_path(rawurldecode(substr($path, strlen($prefix)))), '/') : '';
            if ('' !== $relative && false === strpos($relative, '..') && file_exists(ImgPro_CDN_Files::get_basedir() . '/' . $relative)) {
                $query = (string) wp_parse_url($absolute, PHP_URL_QUERY);
                return $this->get_upload_base() . substr($path, strlen($prefix)) . ('' !== $query ? '?' . $query : '');
            }

            // Elsewhere, and its own file is not in srcset (a size another
            // size of the same width replaced): any current candidate is
            // a better fallback than an address that may be dead
            if (isset($values['srcset'])) {
                foreach ($this->parse_srcset($values['srcset']) as $candidate) {
                    if (null !== $this->url_to_path($candidate[0])) {
                        return $this->absolute_url($candidate[0]);
                    }
                }
            }

            return $this->absolute_url($src);
        }

        return '';
    }

    /**
     * Srcset candidate whose uploads-relative path a src ends with
     *
     * Mirrors how WordPress matches src to the attachment's sizes. When
     * several candidates match, the longest path wins.
     *
     * @since 2.0.0
     * @param string $src    Image src.
     * @param string $srcset Srcset value.
     * @return array|null ['url' => candidate URL, 'path' => its uploads-relative path], or null.
     */
    private function match_srcset_candidate($src, $srcset) {
        $absolute = ('//' === substr($src, 0, 2)) ? 'http:' . $src : $src;
        $path     = wp_parse_url($absolute, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $decoded = '/' . ltrim(wp_normalize_path(rawurldecode($path)), '/');

        $best = null;
        foreach ($this->parse_srcset($srcset) as $candidate) {
            $relative = $this->url_to_path($candidate[0]);
            if (null === $relative || '/' . $relative !== substr($decoded, -strlen('/' . $relative))) {
                continue;
            }
            if (null === $best || strlen($relative) > strlen($best['path'])) {
                $best = ['url' => $candidate[0], 'path' => $relative];
            }
        }
        return $best;
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
            // Protocol-relative: the browser keeps the page's scheme
            return $url;
        }
        if ('/' === substr($url, 0, 1)) {
            // Root-relative: prefix the site's host only, so a home URL in a
            // subfolder (example.com/blog) doesn't add its path twice
            $home = wp_parse_url(home_url());
            if (empty($home['host'])) {
                return $url;
            }
            return '//' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $url;
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
        // Per site: a network page can render other sites' content
        $blog_id = get_current_blog_id();
        if (!isset($this->upload_bases[$blog_id])) {
            $uploads = wp_get_upload_dir();
            $this->upload_bases[$blog_id] = trailingslashit(preg_replace('#^https?:#i', '', $uploads['baseurl']));
        }
        return $this->upload_bases[$blog_id];
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
     * fails too. An empty src (a lazy loader has not filled it in yet)
     * fires an error with no image requested; the handler ignores it and
     * waits for a real failure, which may come from src or srcset. Safari
     * before 26.4 reports the page URL as currentSrc for an empty src, so
     * the guard also requires a src or srcset to be set.
     *
     * @since 0.1.0
     * @return string
     */
    private function get_onerror_handler() {
        return "if(!this.currentSrc||!((this.getAttribute('src')||'').trim()||this.getAttribute('srcset')))return;"
            . "this.onerror=null;this.removeAttribute('srcset');this.src=this.getAttribute('data-imgpro-origin');";
    }
}
