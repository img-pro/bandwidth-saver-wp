# Bandwidth Saver: Image CDN

[![WordPress Plugin Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://wordpress.org/plugins/bandwidth-saver/)
[![Requires WordPress Version](https://img.shields.io/badge/wordpress-6.2%2B-blue.svg)](https://wordpress.org/download/)
[![Requires PHP Version](https://img.shields.io/badge/php-7.4%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-red.svg)](LICENSE)

**Serve your WordPress media library from the [img.pro](https://img.pro) image CDN.**

## Overview

Bandwidth Saver copies the images in your media library to your own img.pro App and rewrites image URLs on your public pages so visitors load them from img.pro.

- **Bring your own img.pro App.** Each site pastes an API key from an App it owns. Storage, limits and billing live in img.pro.
- **Uploads from disk.** Files are read from the uploads folder, so staging, password-protected and local sites work.
- **WordPress stays in charge.** Every size WordPress generates is uploaded as-is and served at the same dimensions and format. No transforms, no format changes.
- **Safe fallback.** Unsynced images keep their server URLs, and every rewritten image falls back to its server URL if img.pro does not answer.

## Requirements

- WordPress 6.2 or higher
- PHP 7.4 or higher
- An img.pro App and an API key with Read and Write permission ([img.pro/apps](https://img.pro/apps))

## Setup

1. Install and activate the plugin.
2. Create an App at [img.pro/apps](https://img.pro/apps). The free plan stores 5,000 images.
3. In the App, open **API Keys** and create a key with **Read and Write** permission.
4. Go to **Settings > Bandwidth Saver**, paste the key and click **Connect**.

The existing media library is copied in the background. Progress, failures and the App's image count are shown on the settings page.

## How It Works

### Sync

Each file in the media library (the full image and every intermediate size, but not the pre-scaling `original_image`) gets a row in the `{prefix}imgpro_files` table, keyed by its path in the uploads folder.

A background worker, run by WP-Cron and by the settings page while it is open, works through four phases:

1. **Deletions**: removes img.pro copies of files that were deleted or replaced.
2. **Scan**: walks the media library in batches and queues every file.
3. **Match**: after a reconnect, adopts images already in the App (same site label, path and byte size) instead of uploading them again.
4. **Upload**: `POST /v1/images` from disk, with an idempotency key per file version.

Uploads are labelled `site`, `wp_id` and `wp_size`, with `wp_file` and `wp_bytes` in metadata. The site label is the home URL without its scheme, so several sites can share one App and each only lists or deletes its own images.

Editing an image re-queues its new files and deletes the old copies. Deleting an attachment deletes its copies.

Sync pauses on `quota_exceeded`, a rejected key or a suspended App, and backs off on `429` and `503` using `Retry-After`.

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

Each rewritten image gets `data-imgpro-origin` and an `onerror` handler that drops `srcset` and reloads the server URL.

`wp_get_attachment_url` and `wp_get_attachment_image_src` are not filtered, because WordPress builds size URLs and srcset by matching paths in the uploads folder.

### Supported files

JPG, PNG, GIF, WebP, AVIF and HEIC up to 20,000,000 bytes. Anything else (SVG, BMP, ICO, TIFF, larger files) is marked as skipped and stays on the server.

## Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `imgpro_cdn_site_label` | filter | Change the label used to scope this site's images in a shared App |
| `imgpro_cdn_api_base_url` | filter | Point the client at another img.pro API base URL |
| `imgpro_cdn_api_error` | action | Fires on every failed API request (`WP_Error`, path) |
| `imgpro_admin_allow_rewrite` | filter | Allow rewriting inside wp-admin |
| `imgpro_is_unsafe_context` | filter | Force rewriting off for the current request |
| `imgpro_is_frontend_ajax` | filter | Treat the current AJAX request as frontend |
| `imgpro_logged_in_ajax_allow_cdn` | filter | Allow rewriting in logged-in AJAX requests |
| `imgpro_cdn_settings_updated` | action | Fires after settings are saved |
| `imgpro_cdn_upgraded` | action | Fires after a version upgrade |

## Upgrading from 1.x

2.0 is a clean break. The managed CDN (`px.img.pro`), the $9.99 Unlimited plan, custom domains and the self-hosted Cloudflare worker are gone. On upgrade the plugin deletes the 1.x settings and transients and shows a notice asking the site to connect an img.pro key. Until then, images load from the server.

## Uninstall

Uninstalling deletes the plugin's options, transients, cron events and the `imgpro_files` table on every site. Images on img.pro are kept, so reinstalling and reconnecting adopts them without uploading again. To delete them, use **Delete images from img.pro and disconnect** on the settings page first.

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
│   ├── class-imgpro-cdn-admin-ajax.php  # AJAX handlers
│   ├── class-imgpro-cdn-crypto.php      # API key encryption
│   └── class-imgpro-cdn-security.php    # Nonces and capabilities
└── admin/
    ├── css/imgpro-cdn-admin.css         # Settings page styles
    └── js/imgpro-cdn-admin.js           # Settings page script
```

## Privacy

The plugin adds no cookies, tracking scripts or analytics. With a key connected it uploads media library files to the site's own img.pro App, with the site address, attachment ID, size name, file path and byte size. The API key is stored encrypted (AES-256-GCM). See img.pro's [Terms](https://img.pro/terms) and [Privacy Policy](https://img.pro/privacy).

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
Bandwidth Saver: Image CDN by ImgPro
Copyright (C) 2025 ImgPro

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

---

**Made by [ImgPro](https://img.pro)**
