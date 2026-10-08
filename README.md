# Bandwidth Saver: Image CDN

[![WordPress Plugin Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://wordpress.org/plugins/bandwidth-saver/)
[![Requires WordPress Version](https://img.shields.io/badge/wordpress-6.2%2B-blue.svg)](https://wordpress.org/download/)
[![Requires PHP Version](https://img.shields.io/badge/php-7.4%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-red.svg)](LICENSE)

**Serve your WordPress media library from the [img.pro](https://img.pro) image CDN.**

## Overview

Bandwidth Saver serves the images on your public pages from your own img.pro App. Each file is copied the first time a page shows it, and image URLs are rewritten at render time so visitors load the copies from img.pro.

- **Bring your own img.pro App.** Each site pastes an API key from an App it owns. Storage, limits and billing live in img.pro.
- **Copied on demand.** Only files pages show are uploaded, so files nothing uses never count against the App's image limit. The Media Library can copy any image ahead of time, and images posts load from other websites are imported by their address.
- **Uploads from disk.** Files are read from the uploads folder, so staging, password-protected and local sites work.
- **WordPress stays in charge.** Every size WordPress generates is uploaded unchanged and served at the same dimensions, as img.pro's re-encoded copy in the same format (HEIC as JPEG), without metadata. Animated PNGs stay on the server, because img.pro serves PNGs as still images.
- **Safe fallback.** Images not copied yet keep their server URLs, and every rewritten image falls back to its server URL if img.pro does not answer.

## Requirements

- WordPress 6.2 or higher
- PHP 7.4 or higher
- An img.pro App and an API key with Read and Write permission ([img.pro/apps](https://img.pro/apps))

## Setup

1. Install and activate the plugin.
2. Create an App at [img.pro/apps](https://img.pro/apps). The Free plan stores 5,000 images.
3. In the App, open **API Keys** and choose **New key**. Keep **Read** and **Write** ticked and, if it asks for **Data access**, keep **App storage only** (an App-wide key stops working while the App is paused).
4. Go to **Settings > Bandwidth Saver**, paste the key and click **Connect**.

From then on, each file is copied the first time a page shows it. The settings page shows how many files are copied, queued, not copied yet and failed, and the App's image count.

## How It Works

### Sync

Each file in the media library (the full image and every intermediate size, but not the pre-scaling `original_image`) gets a row in the `{prefix}imgpro_files` table, keyed by its path in the uploads folder. A row is `idle` (not copied), `pending` (queued), `synced`, `failed`, `skipped` (format or size img.pro does not accept) or `delete` (a copy waiting to be deleted).

Files are copied on demand. The rewriter looks up every media library URL a page shows; files among them that are still `idle` are queued, and the page keeps their server URLs until the copies exist. **Copy to img.pro** in the Media Library queues all of an image's files with priority.

A background worker, run by WP-Cron and by the settings page while it is open, works through four phases:

1. **Deletions**: removes img.pro copies of files that were deleted or replaced. It runs again right before uploads, so copies retired earlier in the same run are gone before their replacements count against the App's image limit.
2. **Scan**: walks the media library in batches and records every file as `idle`, then retires, also in batches, the rows of attachments deleted while the plugin was inactive whose files are gone.
3. **Match**: after a reconnect, adopts images already in the App (same site label, path, byte size and MD5 hash) for files not copied yet, instead of uploading them again. Copies already queued for deletion are never adopted.
4. **Upload**: `POST /v1/images` from disk, with an idempotency key per file version. For 24 hours img.pro answers a key that made an image with that image again, so a file sent again from scratch (Retry failed files, Copy to img.pro on a failed file, a copy lost from the App, a file rewritten in place at the same size and time, or an import that timed out at its website) counts a retry on its row, and the count is part of the key. An attempt that failed is retried with the same key, unless that key was first sent 20 hours ago or more: img.pro keeps a key's state for 24 hours, so an older key is replaced (a retry) before it is sent again. Files copied from the Media Library go first; files that failed before go after files not tried yet, and a file started over takes its old place among them again.

Uploads are labelled `site`, `wp_id` and `wp_size`, with `wp_file`, `wp_bytes` and `wp_md5` in metadata. The site label is the home URL without its scheme, so several sites can share one App and each only lists or deletes its own images.

Core's image editor writes new file names, so an edited image's files wait for a page (or **Copy to img.pro**) like any upload. A file rewritten at the same path (regenerated thumbnails, or `IMAGE_EDIT_OVERWRITE`) is queued at once when its old version was copied or queued, and its old copy is deleted. WordPress keeps the previous files of an edit (to restore them, and posts may still embed them), so their copies stay until WordPress deletes those files. Deleting an attachment deletes its copies.

Sync pauses on `quota_exceeded` (and resumes on its own once `GET /v1/usage` shows room), a rejected key, a paused or blocked App, or one being deleted, and waits on `429` and `503` using `Retry-After`. Connection and other server errors back off for a minute, doubling up to an hour while they repeat. A failed read or write in the site's own database makes the worker wait the same way when it happens in the scan, the matching step or while recording an upload; the step then resumes where it stopped. One run works at a time: the worker lock is a row in the options table, claimed with a single `UPDATE` that only succeeds while the row is free or its time (the database's clock) is over 3 minutes old. It is renewed every minute while the run works and freed when the run ends, even by most fatal errors; when PHP cannot finish its shutdown work (memory ran out, or the process was killed), it frees itself once it is 3 minutes old. A queued deletion of a copy that a row still serves waits until that row is settled. A failing file is only charged an attempt (5 before it is marked failed) while img.pro otherwise answers: the first server error in a row is checked against `GET /v1/usage`, and after 3 in a row the worker tries files of other attachments (up to two) before backing off. Newly queued files cut short a wait that only failing files caused. Deletions img.pro refuses are retried on later runs, and a batch rejected as a whole is split in halves to isolate the bad ids.

Once an hour the worker also checks that copies are still on img.pro, the ones confirmed longest ago first (up to 200, with `GET /v1/images?ids=` and, for ids it leaves out, `GET /v1/images/{id}`). A copy deleted in the App is queued to be copied again, under a new idempotency key. One whose processing failed is deleted and its file listed as failed, for Retry failed files to try again. A blocked one stops being served and is listed as staying on the server. A queued deletion from an earlier key whose copy is in another App's storage (the first path segment of its `src.img.pro` URL) is not sent, since that key's batch delete would report it deleted anyway: it is counted, and the settings page says once that those images stay in the old App.

A run stops without touching anything when the site disconnects or connects another key while it is busy.

### URL rewriting

Rewriting happens at render time on the frontend only. Database URLs are never changed.

```
Before: https://yoursite.com/wp-content/uploads/2026/01/photo-1024x683.jpg
After:  https://src.img.pro/4j2/abc12345.jpg
```

- `wp_get_attachment_image_attributes` rewrites `src` and `srcset` on images rendered by WordPress.
- `the_content`, `post_thumbnail_html`, `widget_text` and `widget_block_content` are rewritten with `WP_HTML_Tag_Processor` (`img`, `amp-img`, `amp-anim`, and `a href` links to images), with one batched lookup per block of HTML.
- `post_thumbnail_url` is rewritten for themes that print the URL directly.
- `<picture><source>` is left alone, since a failed `source` has no fallback.

Each rewritten image gets `data-imgpro-origin` and an `onerror` handler that drops `srcset` and reloads the server URL. That URL is the original of the image the browser shows:

- `src`, when it points into the media library.
- The lazy-load source (`data-lazy-src` or `data-src`), when `src` is a placeholder.
- The same file at its current address, for content saved before a move (another host, a subfolder, a network subsite).

An image with no usable fallback keeps its server URLs. The handler ignores the error an empty `src` fires before a lazy loader fills it in.

### Images from other websites

Post content can point anywhere: WordPress renders any `<img src>` as saved, and only treats an `<img>` as a media library image when its `wp-image-ID` and file name match an attachment (it then adds that attachment's `srcset`). With **Images from other websites** on (the default), the rewriter also handles those:

- An `img` whose `src` is on another host but matches one of the `srcset` candidates WordPress added (content saved under an earlier domain) uses that local file's copy.
- The site's own uploads at its `www` or bare host are treated as local files.
- Any other image address on a public host that is not this site's gets a `remote` row keyed by its URL the first time a published page shows it, and img.pro imports it with `POST /v1/images` and a JSON `url` (labels `site`, `wp_id` `0`, `wp_size` `remote`; metadata `wp_url`). Links (`a href`) count only when they name an image file, and not when they are pages named after one (wiki `File:` pages, Dropbox previews). Left alone: private hosts (`localhost`, `.test`, `.local`, private IPs), img.pro itself, formats img.pro rejects (SVG, BMP, ICO, TIFF), tracking pixels (declared or styled at most 1 pixel wide or high, or hidden), ad, affiliate and analytics hosts, addresses with signature, expiry, timestamp or cache-busting parameters, images with no usable `src` (they are never swapped), and anything the `imgpro_cdn_copy_remote_image` filter returns false for. Previews and unpublished content only use copies that exist, and one request queues at most 50 new addresses.
- The page keeps the original address until the copy exists, and falls back to it if img.pro does not answer.
- A source img.pro cannot fetch (`fetch_failed`) is that image's failure, not an outage: other errors fail at once, and a timeout waits 5, 15, 45 and then 135 minutes before a page may queue the image again, up to 5 attempts. An animated PNG is imported as a still image, as img.pro shows every PNG. Turning the setting off forgets imports not made yet, and the worker skips any it meets.
- After a reconnect the matching step adopts earlier imports by `wp_url`, also for an address whose import has failed since, so nothing is imported twice. Imported copies are never deleted on their own, since nothing tells the plugin when a post stops using them; **Delete images from img.pro and disconnect** removes them with the rest.

On a multisite network, content rendered inside `switch_to_blog()` uses that site's own settings and uploads folder, and is only rewritten when the plugin is active on that site.

`wp_get_attachment_url` and `wp_get_attachment_image_src` are not filtered, because WordPress builds size URLs and srcset by matching paths in the uploads folder.

### Media Library

Copying is gated on the plugin's capability (`manage_imgpro_cdn` or `manage_options`), since copies spend the App's image allowance, plus `edit_post` for each item. Nothing shows while no key is connected or while the site's images are being deleted.

- **List view**: an `img.pro` column (Copied, Queued, Not copied yet, Copy failed with its reason, Stays on your server for files img.pro does not accept, missing files and blocked images, Not an image, or Files not found for an image with no file recorded in the uploads folder), a **Copy to img.pro** row action and a bulk action. Both go through `handle_bulk_actions-upload` and reload the list with a counted notice, like Trash. Statuses for a page of attachments are looked up in one batch.
- **Attachment details** (media modal) and the **edit screen** (Save box): the same status, a **Copy to img.pro** button that copies in place over admin-ajax, and **Copy img.pro URL**, which uses core's clipboard handlers.
- A copy queues the image's files with priority and runs the worker for up to 8 seconds on those files only; whatever is left finishes in the background.

There is no "copy everything" control: copying files no page shows would spend the App's limit on images nobody sees.

### Supported files

JPG, PNG, GIF, WebP, AVIF and HEIC up to 20,000,000 bytes. Anything else (SVG, BMP, ICO, TIFF, larger files) is marked as skipped and stays on the server.

## Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `imgpro_cdn_site_label` | filter | Change the label used to scope this site's images in a shared App |
| `imgpro_cdn_copy_remote_image` | filter | Return false to leave an image from another website alone (`bool`, URL) |
| `imgpro_cdn_api_base_url` | filter | Point the client at another img.pro API base URL |
| `imgpro_cdn_api_error` | action | Fires on every failed API request (`WP_Error`, path) |
| `imgpro_admin_allow_rewrite` | filter | Allow rewriting inside wp-admin |
| `imgpro_is_unsafe_context` | filter | Force rewriting off for the current request |
| `imgpro_is_frontend_ajax` | filter | Treat the current AJAX request as frontend |
| `imgpro_logged_in_ajax_allow_cdn` | filter | Allow rewriting in logged-in AJAX requests |
| `imgpro_cdn_settings_updated` | action | Fires after settings are saved |
| `imgpro_cdn_upgraded` | action | Fires after a version upgrade |

## Upgrading from 1.x

2.0 is a clean break. 2.0 no longer uses the managed CDN (`px.img.pro`), its old $9.99 a month plan, custom domains or the self-hosted Cloudflare Worker. On upgrade the plugin deletes the 1.x settings and transients and shows a notice asking the site to connect an img.pro key. Until then, images load from the server.

## Uninstall

Uninstalling deletes the plugin's options, transients, cron events and the `imgpro_files` table on every site. When Unlimited CDN, which shares some option and capability names, is installed, those shared names are kept. Images on img.pro are kept, so reinstalling and reconnecting to the same App adopts them without uploading again. Connecting a site afresh while its stored label names another address (a copy of a site, such as a staging copy, or a site that moved) gives it its own label and drops the rows it was copied with, so a copy never manages the original site's images. Adoption matches images by the site label stored at connect time, which uninstall deletes: a site whose address changed since it connected can set the old label with the `imgpro_cdn_site_label` filter before reconnecting. To delete them, use **Delete images from img.pro and disconnect** on the settings page first.

## File Structure

```
bandwidth-saver/
├── imgpro-cdn.php                        # Main plugin file
├── readme.txt                            # WordPress.org readme
├── uninstall.php                         # Uninstall cleanup
├── includes/
│   ├── class-imgpro-cdn-core.php        # Bootstrap, activation, upgrades
│   ├── class-imgpro-cdn-settings.php    # Settings and API key storage
│   ├── class-imgpro-cdn-api.php         # img.pro API client
│   ├── class-imgpro-cdn-files.php       # File map table
│   ├── class-imgpro-cdn-sync.php        # Background sync worker
│   ├── class-imgpro-cdn-rewriter.php    # Frontend URL rewriting
│   ├── class-imgpro-cdn-admin.php       # Settings page
│   ├── class-imgpro-cdn-admin-ajax.php  # Settings page AJAX handlers
│   ├── class-imgpro-cdn-media.php       # Media Library column, actions and details
│   ├── class-imgpro-cdn-crypto.php      # API key encryption
│   └── class-imgpro-cdn-security.php    # Nonces and capabilities
└── admin/
    └── js/
        ├── imgpro-cdn-admin.js          # Settings page script
        └── imgpro-cdn-media.js          # Copy to img.pro in the media modal and edit screen
```

## Privacy

The plugin adds no cookies, tracking scripts or analytics. With a key connected it uploads the media library files pages show, and those copied from the Media Library, to the site's own img.pro App, with the site address, attachment ID, size name, file path, byte size and MD5 hash. For an image from another website that a published page shows, it sends img.pro the image's address and the site address, and img.pro fetches the image from that website. The API key is stored encrypted (AES-256-GCM). Images on img.pro are public at their address, without a key. See img.pro's [Terms](https://img.pro/terms) and [Privacy Policy](https://img.pro/privacy).

## Support

- **WordPress.org Support Forum:** [wordpress.org/support/plugin/bandwidth-saver](https://wordpress.org/support/plugin/bandwidth-saver/)
- **GitHub Issues:** [github.com/img-pro/bandwidth-saver-wp/issues](https://github.com/img-pro/bandwidth-saver-wp/issues)
- **img.pro API:** [img.pro/api](https://img.pro/api)

## Contributing

Contributions welcome. Please:

1. Fork the repository
2. Create a feature branch from `development`
3. Commit your changes
4. Open a pull request into `development`

**Coding standards:**
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) for escaping, sanitizing and nonces
- All strings must be translatable
- All input sanitized, all output escaped
- Document all functions with PHPDoc

## License

GPL v2 or later.

```
Bandwidth Saver: Image CDN by img.pro
Copyright (C) 2026 img.pro

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

---

**Made by [img.pro](https://img.pro)**
