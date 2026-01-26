=== Bandwidth Saver: Unlimited Media CDN ===
Contributors: imgpro
Tags: media cdn, cdn, video cdn, image cdn, hls streaming
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Unlimited media CDN for WordPress. Serve images, video, audio, and HLS streams from 300+ edge servers. $19.99/mo for unlimited bandwidth.

== Description ==

**Unlimited media CDN that makes your WordPress site faster.**

Heavy media files slow down your site. When videos buffer and images lag, visitors leave, Core Web Vitals fail, and Google ranks you lower.

This media CDN plugin fixes that by serving your images, videos, audio, and HLS streams from 300+ global edge servers. A visitor in Tokyo loads media from Asia. A visitor in London loads from Europe. Everyone gets faster pages.

**60-second setup.** No DNS changes. No external accounts. No settings to configure. Just activate and start delivering.

= Why Use a Media CDN? =

* **Faster page load times** — Media loads from the nearest server instead of traveling across the world from your host
* **Better Core Web Vitals** — Improve LCP (Largest Contentful Paint) by delivering images faster
* **Smooth video playback** — HLS streaming and video files buffer less with edge delivery
* **Higher PageSpeed scores** — Google PageSpeed Insights will show improved performance
* **Lower bounce rates** — Visitors don't wait for slow sites

= All Media Types Supported =

* **Images** — JPG, PNG, GIF, WebP, AVIF, SVG
* **Video** — MP4, WebM, MOV with range request support
* **Audio** — MP3, WAV, OGG, FLAC
* **HLS Streaming** — M3U8 playlists and TS segments

= How This Media CDN Works =

1. Install the media CDN plugin from WordPress
2. Flip the switch to activate
3. Media instantly loads from 300+ global CDN servers

That's it. Your original files stay exactly where they are on your server. The plugin only changes URLs on your public pages. Deactivate it and everything returns to normal instantly.

= Unlimited Media CDN Pricing =

**Unlimited** ($19.99/mo)
* Unlimited bandwidth
* Custom CDN domain (cdn.yoursite.com)
* Priority support
* Images, video, audio, and HLS streaming

All plans include a 7-day money-back guarantee.

= Self-Hosted Option =

For developers who want full control, you can deploy the open-source worker on your own Cloudflare account. Your media, your infrastructure, zero external dependencies.

[Self-hosted CDN setup guide on GitHub](https://github.com/img-pro/bandwidth-saver-worker)

= Works With Any WordPress Theme or Plugin =

This media CDN is compatible with:

* **Page builders** — Elementor, Divi, Beaver Builder, Gutenberg, Bricks, Oxygen
* **WooCommerce** — Product images, galleries, thumbnails
* **Video players** — Plyr, VideoJS, native HTML5 video
* **Lazy loading** — Works with native lazy load and plugins
* **Responsive images** — Full srcset support
* **Caching plugins** — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache

= Who This Media CDN Is For =

* Video course creators and membership sites
* Podcasters and audio content creators
* Bloggers with image-heavy posts
* WooCommerce stores with product photos and videos
* Recipe, travel, and photography sites
* Portfolio and agency sites
* Anyone who wants faster WordPress media loading without complexity

== Installation ==

**60-second setup. No technical knowledge required.**

1. Install and activate the media CDN plugin
2. Go to **Settings > Bandwidth Saver**
3. Toggle the CDN switch on
4. Upgrade to Unlimited for $19.99/mo

Done. Your media is now loading faster from the global CDN.

== Frequently Asked Questions ==

= What media types does this CDN support? =

The media CDN supports all common media formats: images (JPG, PNG, GIF, WebP, AVIF, SVG), video (MP4, WebM, MOV), audio (MP3, WAV, OGG, FLAC), and HLS streaming (M3U8 playlists and TS segments).

= Will this media CDN improve my Core Web Vitals? =

Yes. The media CDN improves LCP (Largest Contentful Paint) by serving media from servers close to your visitors. Faster media delivery means better Core Web Vitals scores.

= How does video streaming work? =

The CDN supports HTTP range requests, which means video files can be seeked and streamed without downloading the entire file. HLS streams work seamlessly with M3U8 playlist and TS segment delivery.

= Does the CDN work with WooCommerce? =

Yes. The media CDN works with WooCommerce product images, galleries, thumbnails, and product videos.

= Will this CDN work with my page builder? =

Yes. This media CDN works with Elementor, Divi, Beaver Builder, Gutenberg blocks, Bricks, Oxygen, and any other WordPress page builder.

= Is there a file size limit? =

Files up to 500 MB are supported. This covers most images and many video files. For very large video files, consider dedicated video hosting.

= Can I use my own domain for CDN URLs? =

Yes. The Unlimited plan supports custom domains (cdn.yoursite.com) with automatic SSL.

= What happens if the media CDN goes down? =

Your site automatically serves media directly from your server. Visitors won't notice anything — media just loads the normal way until the CDN is back.

= Is this media CDN safe? Will it break my site? =

The media CDN cannot break your site. Your original files stay on your server completely untouched. The plugin only changes URLs on your public pages. If the CDN ever has issues, your site automatically falls back to loading media directly. Deactivate the plugin and everything returns to normal instantly.

= Do I need to change my DNS for this CDN? =

No. Unlike other CDN services, this media CDN works immediately without any DNS changes. Everything happens from your WordPress admin.

== Screenshots ==

1. Speed up your media in 60 seconds with the unlimited media CDN
2. Track your CDN requests and performance
3. Multi-site support and custom CDN domains
4. Self-host option for full control

== Privacy ==

= What Data Is Collected? =

The media CDN plugin does not add cookies, tracking pixels, or analytics to your site.

= Managed Mode =

* Your site URL is used to configure CDN routing
* Email is collected when you upgrade to a paid plan
* Custom domain settings are sent if configured

Media is cached and served through a global edge network powered by Cloudflare.

= Self-Hosted Mode =

No data is sent to us. Media is cached in your own Cloudflare account.

== External Services ==

This media CDN plugin connects to external services:

**Cloudflare (R2 Storage and Workers)**

* Purpose: Media caching and global edge CDN delivery
* [Terms of Service](https://www.cloudflare.com/terms/)
* [Privacy Policy](https://www.cloudflare.com/privacypolicy/)

**Bandwidth Saver API** (Managed mode only)

* Purpose: Account management, usage tracking
* Data sent: Site URL, email (if provided), custom domain (if configured)

Self-hosted users connect only to their own Cloudflare account.

== Fair Use ==

This service is provided on a fair use basis. While we don't impose hard limits, we reserve the right to contact users with exceptionally high usage to discuss dedicated plans or custom arrangements.

The 500 MB per-file size limit applies to all media. For larger files or specialized requirements, please contact us to discuss options.

We aim to provide reliable service for legitimate WordPress media delivery. Abuse, excessive automated requests, or use that degrades service for others may result in account review.

== Changelog ==

= 1.0 =
* New: Rebranded as "Bandwidth Saver: Unlimited Media CDN"
* New: Simplified pricing - single Unlimited tier at $19.99/mo
* New: Video and audio CDN support with range requests
* New: HLS streaming support (M3U8 and TS segments)
* New: Request-based analytics (bandwidth tracking deprecated)
* Improved: Media-focused messaging and UI
* Improved: Unlimited bandwidth for paid tier

= 0.2.5 =
* Improved: Simplified Self-Host setup instructions (3 steps instead of 4)
* Improved: Cleaner UI with single time estimate for setup process

= 0.2.4 =
* New: Frictionless activation — no email required for trial tier
* Improved: Toggle on directly from dashboard, account created automatically
* Improved: Cleaner first-run experience with fewer steps
* Fixed: Account card displays correctly when email is not set

= 0.2.3 =
* Improved: Clearer messaging about media CDN speed and Core Web Vitals benefits
* Improved: Simplified onboarding copy
* Improved: Updated screenshot captions
* Fixed: PHPCS warnings for Stripe redirect handler

== Upgrade Notice ==

= 1.0 =
Major update: Now supports video, audio, and HLS streaming. New simplified pricing at $19.99/mo for unlimited bandwidth.

== Support ==

* [Support Forum](https://wordpress.org/support/plugin/bandwidth-saver/)
* [Self-Host Guide](https://github.com/img-pro/bandwidth-saver-worker)
