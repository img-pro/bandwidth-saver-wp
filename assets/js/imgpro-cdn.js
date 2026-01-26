/**
 * ImgPro CDN Frontend JavaScript
 *
 * Handles image error fallback, lazy loading cache miss detection,
 * and graceful degradation when CDN fails.
 *
 * Single-domain architecture (v1.1.0+):
 * - CDN URL format: https://px.img.pro/origin.com/path/image.jpg
 * - On failure, falls back to origin: https://origin.com/path/image.jpg
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

var ImgProCDN = (function() {
    'use strict';

    var debugMode = (typeof imgproCdnConfig !== 'undefined' && imgproCdnConfig.debug) || false;

    /**
     * Extract origin URL from CDN URL
     *
     * Uses string manipulation for maximum compatibility.
     *
     * @param {string} cdnUrl - CDN URL (e.g., https://px.img.pro/origin.com/path/image.jpg?v=123#section)
     * @return {string} Origin URL (e.g., https://origin.com/path/image.jpg?v=123#section)
     */
    function extractOriginFromCdnUrl(cdnUrl) {
        try {
            // Split URL by '/'
            // https://px.img.pro/origin.com/path/image.jpg?v=123#section
            // ['https:', '', 'px.img.pro', 'origin.com', 'path', 'image.jpg?v=123#section']
            var parts = cdnUrl.split('/');

            // Need at least: ['https:', '', 'cdn-domain', 'origin-domain', 'path']
            if (parts.length < 5) {
                return cdnUrl;
            }

            // Extract origin parts (everything after index 2 which is the CDN domain)
            var originParts = parts.slice(3);

            // First part is origin domain, rest is path
            var originDomain = originParts[0];
            var originPath = originParts.slice(1).join('/');

            // Reconstruct origin URL (query string and fragment are preserved in the path)
            return 'https://' + originDomain + '/' + originPath;
        } catch (e) {
            // Parsing failed - return as-is
            return cdnUrl;
        }
    }

    /**
     * Handle image load error
     * Called from JavaScript event listeners (NOT inline handlers)
     *
     * This function handles errors for images that:
     * 1. Don't have inline onerror (CSP restrictions)
     * 2. Were added dynamically without handlers
     *
     * Note: For images with inline handlers, the inline onerror handles
     * both CDN failure (sets fallback='1') and origin failure (sets fallback='2').
     * This function is only needed when inline handlers are blocked.
     *
     * State machine:
     * - no fallback = initial state, CDN URL
     * - fallback='1' = CDN failed, now trying origin
     * - fallback='2' = both CDN and origin failed
     *
     * @param {HTMLImageElement} img - The image element that failed
     */
    function handleError(img) {
        // Already fully failed or being handled - skip
        if (img.dataset.fallback === '2') {
            return;
        }

        // If fallback='1', inline handler already switched to origin.
        // The inline handler sets its own onerror for origin failure,
        // so we don't need to do anything here.
        if (img.dataset.fallback === '1') {
            if (debugMode) {
                console.log('ImgPro: Inline handler already processing fallback');
            }
            return;
        }

        // First failure - fallback from CDN to origin
        var t0 = debugMode ? Date.now() : 0;

        // Capture the failed CDN URL BEFORE changing src
        var failedCdnUrl = img.currentSrc || img.src;

        // Extract origin URL directly from failed CDN URL
        // CDN format: https://px.img.pro/origin.domain.com/path/to/image.jpg
        // Extract: https://origin.domain.com/path/to/image.jpg
        var originUrl = extractOriginFromCdnUrl(failedCdnUrl);

        if (debugMode) {
            console.log('ImgPro: CDN failed for', failedCdnUrl, '→ Loading from', originUrl);
        }

        // Update image to load from origin
        img.dataset.fallback = '1';
        img.classList.remove('imgpro-loaded');
        img.removeAttribute('srcset');
        img.removeAttribute('sizes');
        img.onerror = null; // Clear to prevent loop
        img.src = originUrl;

        // Add success handler for origin load
        img.onload = function() {
            if (debugMode) {
                console.log('ImgPro: Origin loaded in ' + (Date.now() - t0) + 'ms');
            }
            img.classList.add('imgpro-loaded');
            img.onload = null;
        };

        // Add error handler for origin failure
        img.onerror = function() {
            if (debugMode) {
                console.error('ImgPro: Origin ALSO failed:', originUrl);
            }
            img.dataset.fallback = '2';
            img.classList.remove('imgpro-loaded');
            img.onerror = null;
        };
    }

    /**
     * Lazy loading handler
     * Fixes browser caching issue with lazy-loaded images
     *
     * Problem: When an image fails (404), browsers cache the failure.
     * With loading="lazy", the error event may fire before our JS loads,
     * causing the cached failure to persist.
     *
     * Solution: Periodically check lazy images for cached failures
     * (complete=true but naturalWidth=0) and trigger fallback.
     */
    var LazyHandler = (function() {
        var intervalId = null;
        var checkCount = 0;
        var maxChecks = 10; // Stop after 20 seconds (10 * 2s)

        /**
         * Check and fix failed lazy-loaded images
         */
        function checkLazyImages() {
            var lazyImages = document.querySelectorAll('img[loading="lazy"][data-imgpro-cdn]');
            var needsChecking = false;

            if (debugMode) {
                console.log('ImgPro: Checking ' + lazyImages.length + ' lazy-loaded images (check #' + (checkCount + 1) + ')');
            }

            lazyImages.forEach(function(img) {
                // Already fell back - skip
                if (img.dataset.fallback) {
                    return;
                }

                // Check if image failed to load (complete but 0 dimensions = cached 404)
                if (img.complete && img.naturalWidth === 0) {
                    // Trigger the error handler
                    handleError(img);
                } else if (!img.complete || img.naturalWidth === 0) {
                    // Still loading or waiting - keep checking
                    needsChecking = true;
                }
            });

            // Stop interval if all images resolved or max checks reached
            checkCount++;
            if (!needsChecking || checkCount >= maxChecks) {
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                    if (debugMode) {
                        console.log('ImgPro: Stopped checking lazy images' +
                            (checkCount >= maxChecks ? ' (max checks reached)' : ' (all resolved)'));
                    }
                }
            }
        }

        /**
         * Start checking interval if not already running
         */
        function startChecking() {
            if (!intervalId) {
                checkCount = 0; // Reset counter
                checkLazyImages();
                intervalId = setInterval(checkLazyImages, 2000);
                if (debugMode) {
                    console.log('ImgPro: Started checking lazy images');
                }
            }
        }

        /**
         * Initialize lazy loading handler
         */
        function init() {
            // Wait for DOM to be ready before setting up observers
            function setupObservers() {
                // Start checking lazy images
                setTimeout(startChecking, 100);

                // Watch for new images added via infinite scroll/AJAX
                if ('MutationObserver' in window && document.body) {
                    var observer = new MutationObserver(function(mutations) {
                        var hasNewLazyImages = false;
                        mutations.forEach(function(mutation) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) {
                                    // Check if node itself is a lazy image
                                    if (node.tagName === 'IMG' && node.loading === 'lazy' && node.dataset.imgproCdn) {
                                        hasNewLazyImages = true;
                                    }
                                    // Check children
                                    if (node.querySelectorAll) {
                                        var lazyImgs = node.querySelectorAll('img[loading="lazy"][data-imgpro-cdn]');
                                        if (lazyImgs.length > 0) {
                                            hasNewLazyImages = true;
                                        }
                                    }
                                }
                            });
                        });

                        // Restart checking if new lazy images detected
                        if (hasNewLazyImages) {
                            if (debugMode) {
                                console.log('ImgPro: New lazy images detected, restarting checks');
                            }
                            startChecking();
                        }
                    });

                    observer.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                }
            }

            // Setup observers when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setupObservers);
            } else {
                setupObservers();
            }
        }

        return {
            init: init
        };
    })();

    /**
     * Initialize and attach handlers to images
     *
     * Handles images that:
     * 1. Were processed before JS loaded (inline onerror already handled)
     * 2. Don't have inline onerror (CSP restrictions or dynamic content)
     * 3. Are added dynamically after page load
     */
    function init() {
        if (debugMode) {
            console.log('ImgPro: Initializing frontend handler');
        }

        // Attach load/error handlers to existing images with data-imgpro-cdn attribute
        // This handles CSP-compliant setups where inline handlers aren't used
        function attachImageHandlers(img) {
            if (img.dataset.imgproCdn === '1' && !img.dataset.handlersAttached) {
                img.dataset.handlersAttached = '1';

                if (debugMode) {
                    console.log('ImgPro: Attaching handlers to', img.src, 'complete:', img.complete, 'naturalWidth:', img.naturalWidth);
                }

                // Check if image already loaded (sync from cache)
                if (img.complete) {
                    if (img.naturalWidth > 0) {
                        // Image loaded successfully
                        img.classList.add('imgpro-loaded');
                        if (debugMode) {
                            console.log('ImgPro: Image already loaded successfully', img.src);
                        }
                    } else {
                        // Image failed to load
                        if (debugMode) {
                            console.log('ImgPro: Image already failed, triggering error handler', img.src);
                        }
                        handleError(img);
                    }
                } else {
                    // Add load handler for images still loading
                    img.addEventListener('load', function() {
                        if (debugMode) {
                            console.log('ImgPro: Load event fired for', this.src);
                        }
                        this.classList.add('imgpro-loaded');
                    });

                    // Add error handler
                    img.addEventListener('error', function() {
                        if (debugMode) {
                            console.log('ImgPro: Error event fired for', this.src);
                        }
                        handleError(this);
                    });
                }
            }
        }

        // Attach to all existing images
        var imagesWithAttr = document.querySelectorAll('img[data-imgpro-cdn]');
        if (debugMode) {
            console.log('ImgPro: Found', imagesWithAttr.length, 'images with data-imgpro-cdn attribute');
        }
        imagesWithAttr.forEach(attachImageHandlers);

        // Watch for new images added via AJAX/dynamic content
        if ('MutationObserver' in window) {
            var imageObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            // Check if node itself is an image
                            if (node.tagName === 'IMG' && node.dataset.imgproCdn === '1') {
                                attachImageHandlers(node);
                            }
                            // Check children
                            if (node.querySelectorAll) {
                                node.querySelectorAll('img[data-imgpro-cdn]').forEach(attachImageHandlers);
                            }
                        }
                    });
                });
            });

            if (document.body) {
                imageObserver.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        }
    }

    // Initialize on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Initialize lazy handler
    LazyHandler.init();

    // Public API
    return {
        handleError: handleError
    };
})();
