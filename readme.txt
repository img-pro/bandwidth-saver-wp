=== Bandwidth Saver: Image CDN ===
Contributors: imgpro
Tags: image cdn, cdn, images, core web vitals, page speed
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Serve your media library from the img.pro image CDN. Connect your own img.pro App and your images are copied there and delivered fast.

== Description ==

**Image CDN for WordPress, powered by img.pro.**

Heavy images slow down your site. When images lag, visitors leave, Core Web Vitals suffer, and search rankings drop.

Bandwidth Saver copies the images in your media library to your own [img.pro](https://img.pro) App and serves them from img.pro's image CDN. Your server stops sending image bytes to every visitor, and pages load faster.

**Your files, your account.** Images are uploaded straight from your server's disk, so it works on staging sites, password-protected sites and sites that are not public yet. Everything lives in an img.pro App you own, and you can delete it from the plugin at any time.

= How It Works =

1. Create a free App on img.pro and copy an API key with Read and Write permission.
2. Paste the key in **Settings > Bandwidth Saver**.
3. The plugin copies your existing media library in the background and copies every new upload as it arrives.
4. Images that finished copying load from img.pro. Everything else keeps loading from your server until it is ready.

WordPress stays in charge of your images. Every size WordPress creates (the full image and each thumbnail size) is copied as-is and served at the same dimensions, so your theme, srcset and image editor keep working exactly as before.

= Why Use an Image CDN? =

* **Faster page loads.** Images come from a CDN cache close to the visitor instead of your web host.
* **Better Core Web Vitals.** Faster image delivery helps LCP (Largest Contentful Paint).
* **Less load on your server.** Your host no longer serves image traffic for synced images.
* **Safe by design.** If img.pro cannot deliver an image, the browser loads it from your server instead.

= Supported Image Formats =

JPG, PNG, GIF, WebP, AVIF and HEIC, up to 20 MB per file.

SVG, BMP, ICO, TIFF and files over 20 MB stay on your server and keep working as normal.

= Pricing =

The plugin is free. Storage and delivery are provided by your img.pro App, and img.pro plans are counted by the number of stored images, not bandwidth. The free img.pro plan stores 5,000 images. Paid plans with more images are available on [img.pro](https://img.pro).

Every file WordPress creates counts as one image, so a single photo with its thumbnail sizes usually uses 4 to 8 images, depending on your theme.

= What Gets Served From img.pro =

* Images in your media library, including every thumbnail size
* Images inserted in posts, pages and widgets
* Featured images and anything output through WordPress image functions, including srcset

Images that are not in the media library (theme files, external URLs) are left alone.

= Works With Your Theme and Plugins =

* **Block editor and classic editor**
* **WooCommerce** product images and gallery thumbnails
* **Page builders** that output standard media library image URLs
* **Lazy loading**, native and plugin-based
* **Responsive images** with full srcset support
* **Caching plugins** such as WP Rocket, LiteSpeed Cache, W3 Total Cache and WP Super Cache

= Need Video or Audio? =

This plugin is for images only. For video, audio or HLS streaming, see the [Unlimited CDN plugin](https://wordpress.org/plugins/unlimited-cdn/).

== Installation ==

1. Install and activate the plugin.
2. Sign in at [img.pro/apps](https://img.pro/apps) and create an App. The free plan works.
3. In the App, open **API Keys** and create a key with **Read and Write** permission.
4. In WordPress, go to **Settings > Bandwidth Saver**, paste the key and click **Connect**.

The plugin starts copying your media library right away. You can watch the progress on the settings page, and images switch to img.pro as soon as each one is copied.

On a multisite network, each site connects its own key from its own **Settings > Bandwidth Saver** page.

== Frequently Asked Questions ==

= Do I need an img.pro account? =

Yes. Bandwidth Saver 2.0 serves images from your own img.pro App, so you need an img.pro account and an API key. The free img.pro plan is enough to get started.

= Will this change the files on my server? =

No. Your original files stay on your server and your database keeps its normal URLs. The plugin only uploads copies to img.pro and changes image URLs on your public pages. Deactivate it and every image loads from your server again.

= How many img.pro images will my site use? =

Each file WordPress creates counts as one image. A photo uploaded to WordPress is usually saved in 4 to 8 sizes, so a library of 1,000 photos uses roughly 4,000 to 8,000 images. The settings page shows how many images your App stores and its plan limit.

= What happens when my img.pro App is full? =

Copying pauses. Images that were already copied keep loading from img.pro, and new ones load from your server. Upgrade your img.pro plan or free up space, then click Resume on the settings page.

= Does img.pro resize or convert my images? =

No. WordPress keeps creating your image sizes, and img.pro serves each file at the same size and in the same format. img.pro removes hidden photo metadata, such as camera details and GPS location, from the copies it serves.

= Can several sites use the same img.pro App? =

Yes. Each image is labelled with the site it came from, and the plugin only lists or deletes images that carry its own site's label. All sites share the App's image limit.

= What happens when I delete or edit an image in WordPress? =

Deleting an image deletes its copies from img.pro. Editing an image (crop, rotate, scale) copies the new files and deletes the old copies.

= How do I remove my images from img.pro? =

Go to **Settings > Bandwidth Saver** and click **Delete images from img.pro and disconnect**. This deletes every image this site uploaded and forgets the API key. Uninstalling the plugin does not delete anything on img.pro, so you can reinstall and reconnect without uploading again.

= What happens if img.pro is unavailable? =

Each image keeps a copy of its original URL, and the browser falls back to your server if img.pro does not answer. Visitors still see every image.

= I use a page cache. When do images switch to img.pro? =

Image URLs are chosen when a page is rendered. Pages your cache stored before an image finished copying keep the server URL until the cache refreshes. Clear your page cache after the first sync finishes to switch everything at once.

= Can I use my own domain for image URLs? =

Not at the moment. Images are served from img.pro's own domain.

= I used version 1.x. What changed? =

Version 2.0 replaces the old managed CDN and the self-hosted Cloudflare worker with img.pro. The old service has been shut down, so after updating your images load from your server until you connect an img.pro API key. Old settings, subscriptions and custom domains do not carry over.

== Screenshots ==

1. Connect your img.pro App with an API key
2. Watch your media library sync and see how many images your App stores

== Privacy ==

The plugin does not add cookies, tracking scripts or analytics to your site.

When you connect an img.pro API key, the plugin uploads the image files in your media library to your img.pro App. Each upload includes your site's address, the attachment ID, the image size name, its path in the uploads folder and its file size, so the plugin can find and manage its images later. Your API key is stored encrypted in your WordPress database.

Visitors' browsers load synced images from img.pro, so img.pro receives the usual request data a browser sends to any website, such as IP address and user agent.

== External Services ==

This plugin connects to **img.pro**, an image hosting and CDN service, to store your images and deliver them to visitors. Nothing is sent until you paste an img.pro API key on the settings page.

* **img.pro API** (`api.img.pro`): used to check your API key and plan, upload images, list the images this site uploaded and delete them. Data sent: your API key, image files from your media library, and the site address, attachment ID, size name, file path and file size of each image. Requests are made when you connect, when images are added, edited or deleted, during the background sync, and when you view the settings page.
* **img.pro CDN** (`src.img.pro`): visitors' browsers load synced images from this domain.

Service provider: img.pro. [Terms of Service](https://img.pro/terms), [Privacy Policy](https://img.pro/privacy).

== Changelog ==

= 2.0.0 =
* New: Bandwidth Saver now runs on img.pro. Connect your own img.pro App with an API key.
* New: Images are uploaded from your server's disk, so staging, private and local sites work.
* New: Your existing media library is copied in the background, with progress, retries and error details on the settings page.
* New: Every WordPress image size is served from img.pro, including srcset.
* New: Deleting or editing an image in WordPress updates img.pro.
* New: Several sites can share one img.pro App. Each site only manages its own images.
* New: "Delete images from img.pro and disconnect" removes every image the site uploaded.
* New: Settings page shows your img.pro plan and how many images your App stores.
* Removed: The managed CDN, the $9.99 Unlimited plan, custom CDN domains and the self-hosted Cloudflare worker mode.
* Removed: Bandwidth and request charts.
* Changed: SVG, BMP, ICO, TIFF and files over 20 MB stay on your server.

= 1.1.3 =
* Fixed: Source URLs section showing empty on initial page load
* Fixed: Custom domain removal failing on sites with stale local data
* Fixed: Paid plans blocked from adding source URLs (unlimited domains)
* Fixed: Stale cached data overwriting settings after domain changes
* Improved: Custom domain state now syncs correctly when removed server-side

= 1.1.2 =
* New: Cleaner dashboard — see only what matters for your plan
* Fixed: Subscription not activating after checkout in some cases
* Fixed: Compatibility with accounts created in earlier versions
* Improved: Streamlined settings page with fewer distractions

= 1.1.1 =
* Fixed: Subscription status now updates correctly after checkout
* Improved: Smoother activation experience

= 1.1 =
* Changed: Converted from Media CDN to Image CDN
* Removed: Video, audio, and HLS streaming support (use Unlimited CDN plugin instead)
* Simplified: Image-only focus for better positioning and simpler codebase
* Updated: All UI text and documentation for Image CDN

= 1.0 =
* New: Rebranded as "Bandwidth Saver: Unlimited Media CDN"
* New: Simplified pricing - single Unlimited tier at $9.99/mo
* New: Video and audio CDN support with range requests
* New: HLS streaming support (M3U8 and TS segments)
* New: Request-based analytics (bandwidth tracking deprecated)
* Improved: Media-focused messaging and UI
* Improved: Unlimited bandwidth for paid tier

== Upgrade Notice ==

= 2.0.0 =
Major change: the managed CDN and self-hosted mode are gone. Bandwidth Saver now uses your own img.pro App. After updating, images load from your server until you connect an img.pro API key.

= 1.1 =
Breaking change: Video, audio, and HLS streaming no longer supported. If you use video/audio CDN, install the Unlimited CDN plugin instead before upgrading.

== Support ==

* [Support Forum](https://wordpress.org/support/plugin/bandwidth-saver/)
* [img.pro API documentation](https://img.pro/api)
