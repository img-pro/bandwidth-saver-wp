# Bandwidth Saver: Image CDN

[![WordPress Plugin Version](https://img.shields.io/badge/version-1.1-blue.svg)](https://wordpress.org/plugins/bandwidth-saver/)
[![Requires WordPress Version](https://img.shields.io/badge/wordpress-6.2%2B-blue.svg)](https://wordpress.org/download/)
[![Requires PHP Version](https://img.shields.io/badge/php-7.4%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-red.svg)](LICENSE)

**Image CDN for WordPress. Serve images from 300+ global edge servers. Faster Core Web Vitals, better SEO.**

## Overview

Heavy images slow down your WordPress site. When images lag, visitors leave, Core Web Vitals fail, and Google ranks you lower.

**Bandwidth Saver** delivers all your images through Cloudflare's global edge network. No DNS changes. No external accounts. No configuration. Images load from the nearest server to each visitor.

Safe to try on any site. Does not touch your database or existing files. Disable at any time and your site instantly returns to normal. If Cloudflare ever has an issue, WordPress automatically loads your original images.

## How It Works

1. You upload images to WordPress as usual
2. The plugin rewrites image URLs on your frontend pages
3. When a visitor requests an image, a Cloudflare Worker fetches it from your site and caches it in R2
4. Future requests serve cached images from Cloudflare's edge (300+ locations worldwide)

**Your original files stay on your server.** WordPress keeps full control. The plugin only changes how images are delivered to visitors.

## Supported Image Formats

| Category | Formats |
|----------|---------|
| **Standard** | JPG, JPEG, PNG, GIF |
| **Modern** | WebP, AVIF |
| **Vector** | SVG |
| **Other** | BMP, TIFF, ICO, HEIC, HEIF |

## Two Ways to Use

### Managed (Recommended)

One-click setup. We handle the Cloudflare infrastructure.

| Plan | Price | Features |
|------|-------|----------|
| **Unlimited** | $9.99/mo | Unlimited bandwidth, custom domain (CNAME), priority support |

**7-day money-back guarantee. Cancel anytime.**

### Self-Hosted (Free)

For developers who want full control. Deploy the open-source worker on your own Cloudflare account.

- Your images, your infrastructure
- Zero external dependencies
- Pay Cloudflare directly (usually $0/month on free tier)

**Requirements:**
- Cloudflare account (free tier works)
- Worker deployment ([see worker repo](https://github.com/img-pro/unlimited-cdn))
- Custom domain pointing to your Worker

## Requirements

- WordPress 6.2 or higher
- PHP 7.4 or higher

## Installation

### Managed Setup (60 Seconds)

1. Install and activate the plugin
2. Go to **Settings > Bandwidth Saver**
3. Toggle the CDN switch on
4. Upgrade to Unlimited for $9.99/mo

Done. Images now load from 300+ global CDN servers.

### Self-Hosted Setup

1. Create a free [Cloudflare account](https://cloudflare.com)
2. Deploy the worker from [unlimited-cdn](https://github.com/img-pro/unlimited-cdn)
3. Add a custom domain to your Worker (e.g., cdn.yoursite.com)
4. Enter your CDN domain in **Settings > Bandwidth Saver > Self-Host**

Detailed guide: [github.com/img-pro/unlimited-cdn](https://github.com/img-pro/unlimited-cdn#setup)

## Technical Details

### URL Rewriting

The plugin intercepts the final HTML output and rewrites image URLs:

```
Before: https://yoursite.com/wp-content/uploads/2024/01/photo.jpg
After:  https://cdn.img.pro/yoursite.com/wp-content/uploads/2024/01/photo.jpg
```

Rewriting happens at render time. Database URLs remain unchanged.

### Cache Behavior

- Images are cached on first request
- Cache persists until explicitly purged
- Stale-while-revalidate for uninterrupted delivery
- Automatic fallback to origin if cache unavailable

### Request Tracking

Usage is tracked by request count (not bandwidth). View analytics in **Settings > Bandwidth Saver**.

## Compatibility

**WordPress:**
- WordPress 6.2+
- PHP 7.4, 8.0, 8.1, 8.2, 8.3
- Multisite installations

**Page Builders:**
- Gutenberg
- Elementor
- Beaver Builder
- Divi
- Bricks
- Oxygen

**Image Plugins:**
- ShortPixel, Imagify, Smush, EWWW (image optimization)
- Any plugin that outputs standard image URLs

**E-commerce:**
- WooCommerce product images, galleries, thumbnails

**Other:**
- Jetpack (REST API compatible)
- Lazy loading (native and plugin-based)
- Responsive images (srcset/sizes)

## Need Video/Audio CDN?

This plugin focuses on images for simplicity and cost-effectiveness. If you need video, audio, or HLS streaming support, check out our [Unlimited CDN plugin](https://github.com/img-pro/unlimited-cdn-wp) which supports all media types.

## Security

- All input sanitized
- All output escaped
- Nonce verification on AJAX
- Capability checks (`manage_options`)
- No SQL injection vulnerabilities
- No XSS vulnerabilities
- No CSRF vulnerabilities

## File Structure

```
bandwidth-saver/
├── imgpro-cdn.php                        # Main plugin file
├── readme.txt                            # WordPress.org readme
├── LICENSE                               # GPL v2 license
├── uninstall.php                         # Clean uninstall
├── includes/
│   ├── class-imgpro-cdn-core.php        # Core functionality
│   ├── class-imgpro-cdn-settings.php    # Settings management
│   ├── class-imgpro-cdn-rewriter.php    # URL rewriting
│   ├── class-imgpro-cdn-admin.php       # Admin interface
│   ├── class-imgpro-cdn-admin-ajax.php  # AJAX handlers
│   ├── class-imgpro-cdn-api.php         # API client
│   ├── class-imgpro-cdn-crypto.php      # Encryption utilities
│   ├── class-imgpro-cdn-security.php    # Security utilities
│   ├── class-imgpro-cdn-plan-selector.php # Plan selection UI
│   └── class-imgpro-cdn-onboarding.php  # Onboarding flow
├── admin/
│   ├── css/
│   │   └── imgpro-cdn-admin.css         # Admin styles
│   └── js/
│       └── imgpro-cdn-admin.js          # Admin JavaScript
└── assets/
    ├── css/
    │   └── imgpro-cdn-frontend.css      # Frontend styles
    └── js/
        └── imgpro-cdn.js                # Frontend JavaScript
```

## Privacy

**What the plugin collects:**
The plugin does not add cookies, tracking scripts, or analytics to your site.

**For Managed users:**
- Site URL is used to configure CDN routing
- Email is collected when you upgrade to a paid plan
- Custom domain settings are sent if configured
- Images are cached on Cloudflare infrastructure

Review [Cloudflare's privacy policy](https://www.cloudflare.com/privacypolicy/).

**For Self-Hosted users:**
No data is sent to us. Images are stored in your own Cloudflare account.

## Fair Use

This service is provided on a fair use basis. While we don't impose hard limits, we reserve the right to contact users with exceptionally high usage to discuss dedicated plans or custom arrangements.

**File size limit:** 500 MB per file. Contact us for larger files or specialized requirements.

**Service integrity:** We aim to provide reliable service for legitimate WordPress image delivery. Abuse, excessive automated requests, or use that degrades service for others may result in account review.

## Support

- **WordPress.org Support Forum:** [wordpress.org/support/plugin/bandwidth-saver](https://wordpress.org/support/plugin/bandwidth-saver/)
- **GitHub Issues:** [github.com/img-pro/bandwidth-saver-wp/issues](https://github.com/img-pro/bandwidth-saver-wp/issues)
- **Self-Host Guide:** [github.com/img-pro/unlimited-cdn](https://github.com/img-pro/unlimited-cdn)

## Contributing

Contributions welcome. Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**Coding Standards:**
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
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

## Related Projects

- **Cloudflare Worker:** [unlimited-cdn](https://github.com/img-pro/unlimited-cdn)
- **Unlimited CDN (Video/Audio):** [unlimited-cdn-wp](https://github.com/img-pro/unlimited-cdn-wp)

## Credits

Built for the WordPress community.

**Powered by:**
- Cloudflare R2 (object storage)
- Cloudflare Workers (edge compute)
- Cloudflare CDN (300+ global locations)

---

**Made by [ImgPro](https://img.pro)**
