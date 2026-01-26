/**
 * ImgPro CDN Admin JavaScript
 *
 * Handles admin interface interactions including onboarding wizard,
 * toggle switches, subscription management, and AJAX operations.
 *
 * @package ImgPro_CDN
 * @since   0.1.0
 */

(function($) {
    'use strict';

    // ===== Constants =====

    var AJAX_TIMEOUT = 30000; // 30 seconds for API-dependent operations

    // ===== Utility Functions =====

    /**
     * Escape HTML special characters to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show admin notice
     */
    function showNotice(type, message) {
        $('.imgpro-notice-dynamic').remove();

        const $notice = $('<div>', {
            'class': 'imgpro-notice imgpro-notice-' + type + ' imgpro-notice-dynamic'
        }).append(
            $('<p>').text(message)
        );

        // Insert at top of admin wrapper
        $('.imgpro-admin').prepend($notice);

        // Auto dismiss after 4 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 4000);
    }

    /**
     * Update a progress bar element with percentage and warning/critical states
     * @param {string} selector - jQuery selector for the progress bar element
     * @param {number} percentage - The percentage to display (0-100)
     */
    function updateProgressBar(selector, percentage) {
        const $bar = $(selector);
        if (!$bar.length) return;

        const pct = Math.min(100, percentage);
        $bar.css('width', pct + '%');
        $bar.removeClass('is-warning is-critical');

        if (pct >= 90) {
            $bar.addClass('is-critical');
        } else if (pct >= 70) {
            $bar.addClass('is-warning');
        }
    }

    // ===== Onboarding Wizard =====

    /**
     * Initialize onboarding wizard
     */
    function initOnboarding() {
        const $wizard = $('.imgpro-onboarding');
        if (!$wizard.length) return;

        // Step 1: Get Started
        $('#imgpro-onboarding-start').off('click').on('click', function() {
            updateOnboardingStep(2);
        });

        // Step 2: Connect Form
        $('#imgpro-onboarding-connect-form').off('submit').on('submit', function(e) {
            e.preventDefault();
            handleFreeRegistration($(this));
        });

        // Step 3: Activate Toggle
        $('#imgpro-activate-toggle').off('change').on('change', function() {
            if ($(this).is(':checked')) {
                handleActivateCDN();
            }
        });

        // Step 4: Complete
        $('#imgpro-onboarding-complete').off('click').on('click', function() {
            completeOnboarding();
        });

        // Note: Upgrade link in onboarding now uses .imgpro-open-plan-selector class
        // which is handled by initPlanSelector()
    }

    /**
     * Update onboarding step (client-side transition)
     */
    function updateOnboardingStep(step) {
        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_update_onboarding_step',
                step: step,
                nonce: imgproCdnAdmin.nonces.onboarding
            },
            success: function(response) {
                if (response.success) {
                    // Reload to show new step
                    window.location.reload();
                }
            },
            error: function(xhr, status) {
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle free registration form submission
     */
    function handleFreeRegistration($form) {
        const $button = $form.find('button[type="submit"]');
        const email = $('#imgpro-email').val();
        const marketingOptIn = $form.find('input[name="marketing_opt_in"]').is(':checked') ? '1' : '0';

        // Prevent duplicate submissions
        if ($button.hasClass('is-loading')) {
            return false;
        }

        // Add loading state
        $button.addClass('is-loading').prop('disabled', true);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_free_register',
                email: email,
                marketing_opt_in: marketingOptIn,
                nonce: imgproCdnAdmin.nonces.free_register
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.next_step) {
                        updateOnboardingStep(response.data.next_step);
                    } else {
                        window.location.reload();
                    }
                } else {
                    $button.removeClass('is-loading').prop('disabled', false);
                    // Account exists - show verification modal (email already sent)
                    if (response.data.show_recovery) {
                        showRecoveryVerificationModal(response.data.email_hint);
                    } else {
                        showNotice('error', response.data.message || imgproCdnAdmin.i18n.registrationError);
                    }
                }
            },
            error: function(xhr, status) {
                $button.removeClass('is-loading').prop('disabled', false);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message || imgproCdnAdmin.i18n.genericError);
            }
        });
    }

    /**
     * Handle CDN activation from onboarding
     */
    function handleActivateCDN() {
        const $card = $('#imgpro-activate-card');
        $card.addClass('is-loading');

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_toggle_enabled',
                enabled: 1,
                mode: 'cloud',
                nonce: imgproCdnAdmin.nonces.toggle_enabled
            },
            success: function(response) {
                if (response.success) {
                    $card.removeClass('is-loading').addClass('is-active');
                    // Move to success step
                    setTimeout(function() {
                        updateOnboardingStep(4);
                    }, 500);
                } else {
                    $card.removeClass('is-loading');
                    $('#imgpro-activate-toggle').prop('checked', false);
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.settingsError);
                }
            },
            error: function(xhr, status) {
                $card.removeClass('is-loading');
                $('#imgpro-activate-toggle').prop('checked', false);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Complete onboarding
     */
    function completeOnboarding() {
        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_complete_onboarding',
                nonce: imgproCdnAdmin.nonces.onboarding
            },
            success: function(response) {
                if (response.success && response.data.redirect) {
                    window.location.href = response.data.redirect;
                } else {
                    window.location.reload();
                }
            },
            error: function() {
                window.location.reload();
            }
        });
    }

    // ===== Dashboard & Settings =====

    /**
     * Initialize dashboard functionality
     */
    function initDashboard() {
        // Main toggle handler
        $('#imgpro-cdn-enabled').off('change').on('change', function() {
            handleToggle($(this));
        });

        // Free signup button
        $('#imgpro-free-signup').off('click').on('click', function() {
            handleFreeSignup($(this));
        });

        // Recover account
        $('#imgpro-recover-account').off('click').on('click', function() {
            handleRecoverAccount($(this));
        });

        // Manage subscription
        $('#imgpro-manage-subscription').off('click').on('click', function() {
            handleManageSubscription($(this));
        });

        // Subscription alert buttons (cancelled/past_due states)
        $('#imgpro-resubscribe').off('click').on('click', function() {
            // Resubscribe - redirect to checkout
            handleCheckout($(this));
        });

        $('#imgpro-update-payment, #imgpro-manage-subscription-alert').off('click').on('click', function() {
            // Update payment / manage - open Stripe portal
            handleManageSubscription($(this));
        });

        // Legacy: Direct upgrade handler (kept for backwards compatibility, no longer used in new UI)

        // Advanced settings accordion
        initDetailsAccordion();

        // Developer options (auto-save)
        initDevOptions();

        // Source URLs handlers
        initSourceUrls();

        // Custom domain handlers
        initCustomDomain();

        // Self-hosted CDN domain handlers
        initCdnDomain();

        // Note: Stats sync is now handled by PHP sync_site_data() on page load
        // No need for JS syncStats() call - analytics section handles live data
    }

    // Track if stats sync is in progress to prevent race conditions
    var statsSyncInProgress = false;

    /**
     * Handle main toggle
     *
     * Each mode (cloud/cloudflare) has its own independent enabled state.
     */
    function handleToggle($toggle) {
        const $card = $('#imgpro-toggle-card');
        const isEnabled = $toggle.is(':checked');

        // Get mode from the toggle card's data attribute
        const mode = $card.data('mode') || '';
        if (!mode) {
            showNotice('error', 'Unable to determine mode');
            $toggle.prop('checked', !isEnabled);
            return;
        }

        $card.addClass('is-loading');

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_toggle_enabled',
                enabled: isEnabled ? 1 : 0,
                mode: mode,
                nonce: imgproCdnAdmin.nonces.toggle_enabled
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.redirect) {
                        showNotice('success', response.data.message);
                        setTimeout(function() {
                            window.location.href = response.data.redirect;
                        }, 500);
                        return;
                    }

                    updateToggleUI(isEnabled);
                    showNotice('success', response.data.message);
                } else {
                    // Revert toggle
                    $toggle.prop('checked', !isEnabled);
                    // Account exists with email - show recovery modal
                    if (response.data.show_recovery) {
                        showRecoveryVerificationModal(response.data.email_hint);
                    } else {
                        showNotice('error', response.data.message || imgproCdnAdmin.i18n.settingsError);
                    }
                }
            },
            error: function(xhr, status) {
                $toggle.prop('checked', !isEnabled);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            },
            complete: function() {
                $card.removeClass('is-loading');
            }
        });
    }

    /**
     * Update toggle UI
     */
    function updateToggleUI(isEnabled) {
        const $card = $('#imgpro-toggle-card');
        const $heading = $('#imgpro-toggle-heading');
        const $description = $('#imgpro-toggle-description');
        const $statusBadge = $('#imgpro-status-badge');
        const $statusText = $statusBadge.find('.imgpro-status-text');

        if (isEnabled) {
            $card.removeClass('is-inactive').addClass('is-active');
            $heading.text(imgproCdnAdmin.i18n.cdnActiveHeading);
            $description.text(imgproCdnAdmin.i18n.cdnActiveDesc);
            $statusBadge.removeClass('imgpro-status-inactive').addClass('imgpro-status-active');
            $statusText.text(imgproCdnAdmin.i18n.activeLabel);
        } else {
            $card.removeClass('is-active').addClass('is-inactive');
            $heading.text(imgproCdnAdmin.i18n.cdnInactiveHeading);
            $description.text(imgproCdnAdmin.i18n.cdnInactiveDesc);
            $statusBadge.removeClass('imgpro-status-active').addClass('imgpro-status-inactive');
            $statusText.text(imgproCdnAdmin.i18n.inactiveLabel);
        }
    }

    /**
     * Handle free signup
     */
    function handleFreeSignup($button) {
        const originalText = $button.text();
        $button.prop('disabled', true).text(imgproCdnAdmin.i18n.creatingAccount);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_free_register',
                nonce: imgproCdnAdmin.nonces.free_register
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', imgproCdnAdmin.i18n.accountCreated);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $button.prop('disabled', false).text(originalText);
                    // Account exists - show verification modal (email already sent)
                    if (response.data.show_recovery) {
                        showRecoveryVerificationModal(response.data.email_hint);
                    } else {
                        showNotice('error', response.data.message || imgproCdnAdmin.i18n.registrationError);
                    }
                }
            },
            error: function(xhr, status) {
                $button.prop('disabled', false).text(originalText);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle checkout (Pro upgrade)
     */
    function handleCheckout($button, tierId) {
        // Prevent duplicate submissions
        if ($button.prop('disabled') || $button.hasClass('is-loading')) {
            return;
        }

        const originalText = $button.text();
        // Get tier from parameter, button data attribute, or default to 'unlimited'
        const tier = tierId || $button.data('tier') || 'unlimited';
        $button.addClass('is-loading').prop('disabled', true).text(imgproCdnAdmin.i18n.creatingCheckout);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_checkout',
                tier_id: tier,
                nonce: imgproCdnAdmin.nonces.checkout
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                    } else if (response.data.recovered || response.data.upgraded) {
                        // Show success message if provided, then reload to reflect changes
                        if (response.data.message) {
                            showNotice('success', response.data.message);
                        }
                        // Brief delay to show the message before reload
                        setTimeout(function() {
                            window.location.reload();
                        }, response.data.message ? 1500 : 0);
                    }
                } else {
                    $button.removeClass('is-loading').prop('disabled', false).text(originalText);
                    // Account exists - show verification modal (email already sent)
                    if (response.data.show_recovery) {
                        showRecoveryVerificationModal(response.data.email_hint, tier);
                    } else {
                        showNotice('error', response.data.message || imgproCdnAdmin.i18n.checkoutError);
                    }
                }
            },
            error: function(xhr, status) {
                $button.removeClass('is-loading').prop('disabled', false).text(originalText);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle recover account - Step 1: Request verification code
     * @param {jQuery} $button - The button element
     * @param {boolean} skipConfirm - Skip confirmation dialog (for auto-triggered recovery)
     */
    function handleRecoverAccount($button, skipConfirm) {
        // Prevent duplicate submissions
        if ($button.prop('disabled') || $button.hasClass('is-loading')) {
            return;
        }

        const originalText = $button.text();

        if (!skipConfirm && !confirm(imgproCdnAdmin.i18n.recoverConfirm)) {
            return;
        }

        $button.addClass('is-loading').prop('disabled', true).text(imgproCdnAdmin.i18n.recovering);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_request_recovery',
                nonce: imgproCdnAdmin.nonces.recovery
            },
            success: function(response) {
                $button.removeClass('is-loading').prop('disabled', false).text(originalText);

                if (response.success && response.data.step === 'verify') {
                    // Show verification code modal
                    showRecoveryVerificationModal(response.data.email_hint);
                } else if (response.success) {
                    showNotice('success', response.data.message);
                } else {
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.recoverError);
                }
            },
            error: function(xhr, status) {
                $button.removeClass('is-loading').prop('disabled', false).text(originalText);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Show recovery verification code modal
     * @param {string} emailHint - Masked email hint (e.g., "i•••••@domain.com")
     * @param {string|null} pendingTierId - Tier ID to checkout after verification (null = just recover)
     */
    function showRecoveryVerificationModal(emailHint, pendingTierId) {
        // Remove existing modal if any
        $('#imgpro-recovery-modal').remove();

        const descText = imgproCdnAdmin.i18n.accountFoundDesc || 'We found an existing account for this site. To restore access, enter the verification code sent to:';
        const emailDisplay = escapeHtml(emailHint || imgproCdnAdmin.i18n.yourEmail || 'your registered email');

        const modalHtml = `
            <div id="imgpro-recovery-modal" class="imgpro-modal-overlay">
                <div class="imgpro-modal">
                    <button type="button" class="imgpro-modal-close">&times;</button>
                    <div class="imgpro-modal-header">
                        <h2>${imgproCdnAdmin.i18n.accountFound || 'Welcome Back'}</h2>
                    </div>
                    <div class="imgpro-modal-body">
                        <p>${descText} <strong>${emailDisplay}</strong></p>
                        <div class="imgpro-verification-input">
                            <input type="text"
                                   id="imgpro-recovery-code"
                                   maxlength="6"
                                   pattern="[0-9]*"
                                   inputmode="numeric"
                                   placeholder="000000"
                                   autocomplete="off"
                                   data-1p-ignore="true"
                                   data-lpignore="true">
                        </div>
                        <p class="imgpro-hint">${imgproCdnAdmin.i18n.codeExpires}</p>
                    </div>
                    <div class="imgpro-modal-footer">
                        <button type="button" class="imgpro-btn imgpro-btn-secondary" id="imgpro-recovery-cancel">
                            ${imgproCdnAdmin.i18n.cancel}
                        </button>
                        <button type="button" class="imgpro-btn imgpro-btn-primary" id="imgpro-recovery-verify">
                            ${imgproCdnAdmin.i18n.verify}
                        </button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        const $modal = $('#imgpro-recovery-modal');
        const $input = $('#imgpro-recovery-code');

        // Focus input
        setTimeout(function() {
            $input.focus();
        }, 100);

        // Handle close
        $modal.find('.imgpro-modal-close, #imgpro-recovery-cancel').on('click', function() {
            $modal.remove();
        });

        // Handle verify
        $('#imgpro-recovery-verify').off('click').on('click', function() {
            handleRecoveryVerification($input.val(), pendingTierId);
        });

        // Handle enter key
        $input.off('keypress').on('keypress', function(e) {
            if (e.which === 13) {
                handleRecoveryVerification($input.val(), pendingTierId);
            }
        });

        // Only allow numbers
        $input.off('input').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    }

    /**
     * Handle recovery verification - Step 2: Verify code
     * @param {string} code - 6-digit verification code
     * @param {string|null} pendingTierId - Tier to checkout after verification
     */
    function handleRecoveryVerification(code, pendingTierId) {
        const $modal = $('#imgpro-recovery-modal');
        const $button = $('#imgpro-recovery-verify');
        const $input = $('#imgpro-recovery-code');

        // Prevent duplicate submissions
        if ($button.prop('disabled') || $button.hasClass('is-loading')) {
            return;
        }

        if (!code || code.length !== 6) {
            $input.addClass('error').focus();
            showNotice('error', imgproCdnAdmin.i18n.invalidCode);
            return;
        }

        $button.addClass('is-loading').prop('disabled', true).text(imgproCdnAdmin.i18n.verifying);
        $input.prop('disabled', true);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_verify_recovery',
                code: code,
                pending_tier_id: pendingTierId || '',
                nonce: imgproCdnAdmin.nonces.recovery
            },
            success: function(response) {
                if (response.success) {
                    $modal.remove();

                    // If upgrade confirmation needed, reload page and show modal
                    if (response.data.show_upgrade && response.data.pending_tier_id) {
                        // Reload page with parameter to trigger upgrade modal
                        const url = new URL(window.location.href);
                        url.searchParams.set('show_upgrade', response.data.pending_tier_id);
                        url.searchParams.delete('payment_status'); // Clean up any old params
                        window.location.href = url.toString();
                        return;
                    }

                    // If there's a checkout URL, go there (upgrade needed)
                    if (response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                        return;
                    }

                    // Otherwise just reload (already on same/higher plan)
                    showNotice('success', response.data.message);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $button.prop('disabled', false).text(imgproCdnAdmin.i18n.verify);
                    $input.prop('disabled', false).addClass('error').val('').focus();
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.verificationFailed);
                }
            },
            error: function(xhr, status) {
                $button.prop('disabled', false).text(imgproCdnAdmin.i18n.verify);
                $input.prop('disabled', false);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle manage subscription
     */
    function handleManageSubscription($button) {
        // Prevent duplicate submissions
        if ($button.prop('disabled') || $button.hasClass('is-loading')) {
            return;
        }

        const originalText = $button.text();
        $button.addClass('is-loading').prop('disabled', true).text(imgproCdnAdmin.i18n.openingPortal);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_manage_subscription',
                nonce: imgproCdnAdmin.nonces.manage_subscription
            },
            success: function(response) {
                if (response.success && response.data.portal_url) {
                    window.location.href = response.data.portal_url;
                } else {
                    $button.removeClass('is-loading').prop('disabled', false).text(originalText);
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.portalError);
                }
            },
            error: function(xhr, status) {
                $button.removeClass('is-loading').prop('disabled', false).text(originalText);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Sync stats from API
     * Includes debouncing to prevent race conditions from concurrent calls
     */
    function syncStats() {
        // Prevent concurrent requests
        if (statsSyncInProgress) {
            return;
        }
        statsSyncInProgress = true;

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_sync_stats',
                nonce: imgproCdnAdmin.nonces.sync_stats
            },
            success: function(response) {
                if (response.success && response.data.formatted) {
                    // Stats are now handled by insights API
                    // Bandwidth is static in bottom insights, no need to update
                }
            },
            complete: function() {
                statsSyncInProgress = false;
            }
        });
    }

    /**
     * Initialize details/accordion elements
     */
    function initDetailsAccordion() {
        // Details elements handle their own open/close, but we can enhance
        $('.imgpro-details summary').off('click').on('click', function() {
            const $details = $(this).parent();
            const $icon = $(this).find('svg');

            // Rotate icon on toggle
            if ($details.attr('open') !== undefined) {
                $icon.css('transform', 'rotate(0deg)');
            } else {
                $icon.css('transform', 'rotate(90deg)');
            }
        });
    }

    /**
     * Initialize developer options (auto-save on change)
     */
    function initDevOptions() {
        $('.imgpro-dev-checkbox input[type="checkbox"]').off('change').on('change', function() {
            var $checkbox = $(this);
            var $form = $checkbox.closest('form');

            if ($form.length) {
                // When unchecked, ensure the value is explicitly set to 0
                if (!$checkbox.is(':checked')) {
                    // Add hidden field to send 0 value
                    var fieldName = $checkbox.attr('name');
                    if (fieldName) {
                        // Remove any existing hidden field with this name
                        $form.find('input[type="hidden"][name="' + fieldName + '"]').remove();
                        // Add hidden field with 0 value before checkbox
                        $checkbox.before('<input type="hidden" name="' + fieldName + '" value="0">');
                    }
                } else {
                    // When checked, remove any hidden field (checkbox will send its value)
                    var fieldName = $checkbox.attr('name');
                    if (fieldName) {
                        $form.find('input[type="hidden"][name="' + fieldName + '"]').remove();
                    }
                }

                $form.submit();
            }
        });
    }

    // ===== Custom Domain =====

    /**
     * Initialize custom domain handlers
     */
    function initCustomDomain() {
        // Add domain
        $('#imgpro-add-domain').off('click').on('click', function() {
            handleAddDomain($(this));
        });

        // Enter key on domain input
        $('#imgpro-custom-domain-input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#imgpro-add-domain').click();
            }
        });

        // Check domain status
        $('#imgpro-check-domain-pending').off('click').on('click', function() {
            handleCheckDomainStatus($(this));
        });

        // Remove domain
        $('#imgpro-remove-domain').off('click').on('click', function() {
            handleRemoveDomain($(this));
        });
    }

    /**
     * Handle add custom domain
     */
    function handleAddDomain($button) {
        const $input = $('#imgpro-custom-domain-input');
        const $section = $('#imgpro-custom-domain-section');
        const domain = $input.val().trim();

        if (!domain) {
            $input.focus();
            return;
        }

        const originalText = $button.text();
        $button.prop('disabled', true).text(imgproCdnAdmin.i18n.addingDomain);
        $input.prop('disabled', true);
        $section.addClass('is-loading');

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_add_custom_domain',
                domain: domain,
                nonce: imgproCdnAdmin.nonces.custom_domain
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', imgproCdnAdmin.i18n.domainAdded);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $button.prop('disabled', false).text(originalText);
                    $input.prop('disabled', false);
                    $section.removeClass('is-loading');
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.genericError);
                }
            },
            error: function(xhr, status) {
                $button.prop('disabled', false).text(originalText);
                $input.prop('disabled', false);
                $section.removeClass('is-loading');
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle check domain status
     */
    function handleCheckDomainStatus($button) {
        const originalText = $button.text();
        $button.prop('disabled', true).text(imgproCdnAdmin.i18n.checkingStatus);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_check_custom_domain',
                nonce: imgproCdnAdmin.nonces.custom_domain
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.status === 'active') {
                        showNotice('success', imgproCdnAdmin.i18n.domainActive);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        $button.prop('disabled', false).text(originalText);
                        // Status unchanged
                        const $statusDiv = $('#imgpro-custom-domain-status');
                        if ($statusDiv.length && $statusDiv.data('status') !== response.data.status) {
                            window.location.reload();
                        }
                    }
                } else {
                    $button.prop('disabled', false).text(originalText);
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.genericError);
                }
            },
            error: function(xhr, status) {
                $button.prop('disabled', false).text(originalText);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    /**
     * Handle remove custom domain
     */
    function handleRemoveDomain($button) {
        if (!confirm(imgproCdnAdmin.i18n.confirmRemoveDomain)) {
            return;
        }

        const $section = $('#imgpro-custom-domain-section');
        const originalText = $button.text();

        $button.prop('disabled', true).text(imgproCdnAdmin.i18n.removingDomain);
        $section.addClass('is-loading');

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_remove_custom_domain',
                nonce: imgproCdnAdmin.nonces.custom_domain
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', imgproCdnAdmin.i18n.domainRemoved);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $button.prop('disabled', false).text(originalText);
                    $section.removeClass('is-loading');
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.genericError);
                }
            },
            error: function(xhr, status) {
                $button.prop('disabled', false).text(originalText);
                $section.removeClass('is-loading');
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    // ===== Self-Hosted CDN Domain =====

    /**
     * Initialize self-hosted CDN domain handlers
     */
    function initCdnDomain() {
        // Remove CDN domain
        $('#imgpro-remove-cdn-domain').off('click').on('click', function() {
            handleRemoveCdnDomain($(this));
        });
    }

    /**
     * Handle remove CDN domain (self-hosted)
     */
    function handleRemoveCdnDomain($button) {
        if (!confirm(imgproCdnAdmin.i18n.confirmRemoveCdnDomain)) {
            return;
        }

        const $section = $('#imgpro-cdn-domain-section');
        $section.addClass('is-loading');
        $button.prop('disabled', true);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_remove_cdn_domain',
                nonce: imgproCdnAdmin.nonces.remove_cdn_domain
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', response.data.message || imgproCdnAdmin.i18n.cdnDomainRemoved);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $section.removeClass('is-loading');
                    $button.prop('disabled', false);
                    showNotice('error', response.data.message || imgproCdnAdmin.i18n.genericError);
                }
            },
            error: function(xhr, status) {
                $section.removeClass('is-loading');
                $button.prop('disabled', false);
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    // ===== Source URLs =====

    /**
     * Initialize source URLs handlers
     */
    function initSourceUrls() {
        var $section = $('#imgpro-source-urls-section');
        if (!$section.length) return;

        // Load source URLs on init
        loadSourceUrls();

        // Add source URL button
        $('#imgpro-add-source-url').off('click').on('click', function() {
            handleAddSourceUrl();
        });

        // Enter key on input
        $('#imgpro-source-url-input').off('keypress').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                handleAddSourceUrl();
            }
        });

        // Subscription link - open plan modal or manage subscription
        $(document).off('click', '#imgpro-source-urls-upgrade').on('click', '#imgpro-source-urls-upgrade', function(e) {
            e.preventDefault();
            var action = $(this).data('action');

            if (action === 'manage') {
                // Paid: open manage subscription
                window.open(imgproCdnAdmin.manageUrl, '_blank');
            } else {
                // Not paid: open plan selector modal
                openPlanModal();
            }
        });
    }

    /**
     * Load source URLs - uses pre-loaded data if available, otherwise fetches via AJAX
     */
    function loadSourceUrls() {
        var $list = $('#imgpro-source-urls-list');

        // Use pre-loaded data from PHP if available (avoids extra AJAX call)
        if (imgproCdnAdmin.sourceDomains) {
            renderSourceUrls(
                imgproCdnAdmin.sourceDomains.domains || [],
                imgproCdnAdmin.sourceDomains.count || 0,
                imgproCdnAdmin.sourceDomains.max_domains || 1
            );
            // Clear pre-loaded data so subsequent calls fetch fresh
            imgproCdnAdmin.sourceDomains = null;
            return;
        }

        // Fallback: fetch via AJAX (for refreshes after add/remove)
        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_get_source_urls',
                nonce: imgproCdnAdmin.nonces.source_urls
            },
            success: function(response) {
                if (response.success && response.data.source_urls) {
                    renderSourceUrls(
                        response.data.source_urls,
                        response.data.count || 0,
                        response.data.max_domains || 1
                    );
                } else {
                    $list.html('<p class="imgpro-source-urls-error">' +
                        escapeHtml(response.data?.message || 'Failed to load source URLs') + '</p>');
                }
            },
            error: function() {
                $list.html('<p class="imgpro-source-urls-error">Network error loading source URLs</p>');
            }
        });
    }

    /**
     * Render source URLs list
     */
    function renderSourceUrls(urls, count, maxDomains) {
        var $list = $('#imgpro-source-urls-list');
        var $inputWrapper = $('#imgpro-source-urls-input-wrapper');
        var $upgradeLink = $('#imgpro-source-urls-upgrade');

        count = count || (urls ? urls.length : 0);
        maxDomains = maxDomains || 1;

        if (!urls || !urls.length) {
            $list.html('<p class="imgpro-source-urls-empty">No source URLs configured yet.</p>');
        } else {
            var html = '<div class="imgpro-source-urls-items">';
            urls.forEach(function(item) {
                var isPrimary = item.is_primary === 1 || item.is_primary === true;
                html += '<div class="imgpro-source-url-item' + (isPrimary ? ' is-primary' : '') + '">';
                html += '<code>' + escapeHtml(item.domain) + '</code>';
                if (isPrimary) {
                    html += '<span class="imgpro-source-url-badge">Primary</span>';
                } else {
                    html += '<button type="button" class="imgpro-source-url-remove" data-domain="' +
                        escapeHtml(item.domain) + '" title="Remove domain">&times;</button>';
                }
                html += '</div>';
            });
            html += '</div>';

            $list.html(html);

            // Attach remove handlers
            $('.imgpro-source-url-remove').off('click').on('click', function() {
                handleRemoveSourceUrl($(this).data('domain'));
            });
        }

        // Check if at limit (single-tier model: unlimited for all, but check just in case)
        var atLimit = maxDomains > 0 && count >= maxDomains;

        // Show/hide input based on limit
        if (atLimit) {
            $inputWrapper.hide();

            // Get paid status from section
            var $section = $('#imgpro-source-urls-section');
            var isPaid = $section.data('is-paid');

            // Set link text and action based on payment status
            var linkText = isPaid ? 'Manage Subscription' : 'Activate Subscription';
            var action = isPaid ? 'manage' : 'activate';

            $upgradeLink.attr('data-action', action).find('strong').text(linkText);
            $upgradeLink.show();
        } else {
            $inputWrapper.show();
            $upgradeLink.hide();
        }
    }

    /**
     * Handle add source URL
     */
    function handleAddSourceUrl() {
        var $input = $('#imgpro-source-url-input');
        var $button = $('#imgpro-add-source-url');
        var domain = $input.val().trim();

        if (!domain) {
            $input.focus();
            return;
        }

        // Prevent duplicate submissions
        if ($button.prop('disabled') || $button.hasClass('is-loading')) {
            return;
        }

        $button.prop('disabled', true).addClass('is-loading');

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_add_source_url',
                domain: domain,
                nonce: imgproCdnAdmin.nonces.source_urls
            },
            success: function(response) {
                if (response.success) {
                    $input.val('');
                    loadSourceUrls();
                    showNotice('success', response.data.message || 'Domain added successfully');
                } else {
                    showNotice('error', response.data.message || 'Failed to add domain');
                }
            },
            error: function() {
                showNotice('error', 'Network error adding domain');
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('is-loading');
            }
        });
    }

    /**
     * Handle remove source URL
     */
    function handleRemoveSourceUrl(domain) {
        if (!confirm('Remove ' + domain + ' from your source URLs?')) {
            return;
        }

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_remove_source_url',
                domain: domain,
                nonce: imgproCdnAdmin.nonces.source_urls
            },
            success: function(response) {
                if (response.success) {
                    loadSourceUrls();
                    showNotice('success', response.data.message || 'Domain removed');
                } else {
                    showNotice('error', response.data.message || 'Failed to remove domain');
                }
            },
            error: function() {
                showNotice('error', 'Network error removing domain');
            }
        });
    }

    // ===== URL Handling =====

    /**
     * Handle payment success/cancel from URL
     */
    function handlePaymentStatus() {
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.get('payment') === 'success') {
            showNotice('success', imgproCdnAdmin.i18n.subscriptionActivated);
            // Clean up URL
            const cleanUrl = imgproCdnAdmin.settingsUrl + '&tab=cloud';
            window.history.replaceState({}, document.title, cleanUrl);
        } else if (urlParams.get('payment') === 'cancelled') {
            showNotice('info', imgproCdnAdmin.i18n.checkoutCancelled);
            const cleanUrl = imgproCdnAdmin.settingsUrl + '&tab=cloud';
            window.history.replaceState({}, document.title, cleanUrl);
        }

        if (urlParams.get('activated')) {
            // URL already shows activated, no additional notice needed
            const cleanUrl = imgproCdnAdmin.settingsUrl + '&tab=cloud';
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }

    // ===== Plan Selector =====

    /**
     * Selected tier for plan selector
     */
    let selectedTierId = null;

    /**
     * Initialize plan selector modal and interactions
     */
    function initPlanSelector() {
        const $modal = $('#imgpro-plan-modal');
        const $selector = $('.imgpro-plan-selector');

        if (!$selector.length) return;

        // Open plan selector modal
        $(document).on('click', '.imgpro-open-plan-selector', function(e) {
            e.preventDefault();
            openPlanModal();
        });

        // Close modal on X button
        $(document).on('click', '.imgpro-plan-selector__close', function(e) {
            e.preventDefault();
            closePlanModal();
        });

        // Close modal on backdrop click
        $(document).on('click', '.imgpro-plan-modal__backdrop', function() {
            closePlanModal();
        });

        // Select plan card
        $(document).on('click', '.imgpro-plan-card__select', function(e) {
            e.preventDefault();
            const $card = $(this).closest('.imgpro-plan-card');
            selectPlanCard($card);
        });

        // Also allow clicking the entire card (except current plan)
        $(document).on('click', '.imgpro-plan-card:not(.imgpro-plan-card--current)', function(e) {
            // Ignore if clicking the button (handled above)
            if ($(e.target).closest('.imgpro-plan-card__select').length) return;
            selectPlanCard($(this));
        });

        // Checkout button
        $(document).on('click', '#imgpro-plan-checkout', function(e) {
            e.preventDefault();
            // Use selectedTierId if set, otherwise fall back to button's data-tier-id
            var tierId = selectedTierId || $(this).data('tier-id');
            if (tierId) {
                handlePlanCheckout($(this), tierId);
            }
        });
    }

    /**
     * Initialize default plan selection
     * Single-tier model: only one plan card exists
     */
    function initDefaultSelection() {
        // Only target the modal selector to avoid conflicts
        const $modal = $('#imgpro-plan-modal');
        const $selector = $modal.find('.imgpro-plan-selector');
        if (!$selector.length) return;

        // Select the single plan card if it exists
        const $card = $selector.find('.imgpro-plan-card').first();
        if ($card.length) {
            selectPlanCard($card, true);
        }
    }

    /**
     * Open the plan modal
     */
    function openPlanModal() {
        const $modal = $('#imgpro-plan-modal');
        if (!$modal.length) return;

        $modal.fadeIn(200);
        $('body').addClass('imgpro-modal-open');
    }

    /**
     * Close the plan modal
     */
    function closePlanModal() {
        const $modal = $('#imgpro-plan-modal');
        $modal.fadeOut(200);
        $('body').removeClass('imgpro-modal-open');
    }

    /**
     * Select a plan card
     */
    function selectPlanCard($card, enableCheckout = true) {
        const $modal = $('#imgpro-plan-modal');

        // Reset all cards in the modal
        $modal.find('.imgpro-plan-card').each(function() {
            $(this).removeClass('is-selected');
            const $btn = $(this).find('.imgpro-plan-card__select');
            if ($btn.length && !$(this).hasClass('imgpro-plan-card--current') && !$(this).hasClass('imgpro-plan-card--downgrade')) {
                $btn.removeClass('imgpro-btn-primary').addClass('imgpro-btn-secondary');
                $btn.text('Select');
            }
        });

        // Select this card
        $card.addClass('is-selected');

        // Update this card's button
        const $btn = $card.find('.imgpro-plan-card__select');
        if ($btn.length && !$card.hasClass('imgpro-plan-card--downgrade')) {
            $btn.removeClass('imgpro-btn-secondary').addClass('imgpro-btn-primary');
            $btn.text('Selected');
        }

        // Update state
        selectedTierId = $card.data('tier-id');

        // Update footer
        $modal.find('#imgpro-selected-plan-name').text($card.data('tier-name'));
        $modal.find('#imgpro-selected-plan-price').text($card.data('tier-price'));

        // Enable checkout button
        if (enableCheckout && selectedTierId) {
            $modal.find('#imgpro-plan-checkout').prop('disabled', false);
        }
    }

    /**
     * Handle checkout for selected plan
     */
    function handlePlanCheckout($button, tierId) {
        // Add loading state
        $button.addClass('is-loading').prop('disabled', true);

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_checkout',
                tier_id: tierId,
                nonce: imgproCdnAdmin.nonces.checkout
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                    } else if (response.data.upgraded) {
                        // Subscription upgraded directly - show success and reload
                        closePlanModal();
                        showNotice('success', response.data.message || imgproCdnAdmin.i18n.subscriptionUpgraded);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else if (response.data.recovered) {
                        window.location.reload();
                    }
                } else {
                    $button.removeClass('is-loading').prop('disabled', false);
                    closePlanModal();
                    // Account exists - show verification modal with pending tier
                    if (response.data.show_recovery) {
                        showRecoveryVerificationModal(response.data.email_hint, tierId);
                    } else {
                        showNotice('error', response.data.message || imgproCdnAdmin.i18n.checkoutError);
                    }
                }
            },
            error: function(xhr, status) {
                $button.removeClass('is-loading').prop('disabled', false);
                closePlanModal();
                var message = status === 'timeout' ? imgproCdnAdmin.i18n.timeoutError : imgproCdnAdmin.i18n.genericError;
                showNotice('error', message);
            }
        });
    }

    // ===== Initialize =====
    // NOTE: Initialization moved to single $(document).ready() block at end of file
    // to prevent duplicate event bindings

    /**
     * Handle show_upgrade URL parameter (after account recovery)
     */
    function handleShowUpgrade() {
        const urlParams = new URLSearchParams(window.location.search);
        const upgradeTier = urlParams.get('show_upgrade');

        if (upgradeTier) {
            // Clean up URL
            const url = new URL(window.location.href);
            url.searchParams.delete('show_upgrade');
            window.history.replaceState({}, '', url.toString());

            // Show success notice and open plan selector modal
            showNotice('success', imgproCdnAdmin.i18n.accountRecovered || 'Account recovered!');
            setTimeout(function() {
                openPlanModal();
            }, 300);
        }
    }

    // ===== Analytics =====

    /**
     * Chart.js instance for bandwidth usage
     */
    let usageChart = null;

    /**
     * Current chart period (days)
     */
    let chartPeriod = 30;

    /**
     * Auto-refresh interval for analytics (5 minutes)
     */
    let analyticsRefreshInterval = null;

    /**
     * Initialize analytics section
     */
    function initAnalytics() {
        const $section = $('#imgpro-analytics-section');
        if (!$section.length) return;

        // Only initialize if user has a subscription
        if (imgproCdnAdmin.tier === 'none') return;

        // Initialize stat card refresh button
        $('#imgpro-refresh-stats').off('click').on('click', function(e) {
            e.preventDefault();
            handleStatsRefresh($(this));
        });

        // Initialize chart period selector
        $('#imgpro-chart-period').off('change').on('change', function() {
            chartPeriod = parseInt($(this).val(), 10);
            loadAnalytics(true); // Force AJAX for different period
        });

        // Load initial data (single batched call for insights + daily)
        loadAnalytics();

        // Auto-refresh every 5 minutes (force AJAX for fresh data)
        analyticsRefreshInterval = setInterval(function() {
            loadAnalytics(true);
        }, 5 * 60 * 1000);
    }

    /**
     * Handle manual stats refresh button
     */
    function handleStatsRefresh($button) {
        if ($button.hasClass('is-loading')) return;

        $button.addClass('is-loading');

        // Force sync stats from API, then reload analytics
        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_sync_stats',
                nonce: imgproCdnAdmin.nonces.sync_stats
            },
            success: function(response) {
                if (response.success) {
                    // Reload with fresh data (force AJAX)
                    loadAnalytics(true);
                }
            },
            complete: function() {
                $button.removeClass('is-loading');
            }
        });
    }

    /**
     * Load analytics data (insights + daily) in a single request
     * Uses pre-loaded data from PHP on initial load, AJAX for refreshes
     *
     * @since 0.2.2
     * @param {boolean} forceRefresh - If true, always fetch via AJAX
     */
    function loadAnalytics(forceRefresh) {
        const $chartLoading = $('#imgpro-chart-loading');
        const $chartEmpty = $('#imgpro-chart-empty');
        const $chartCanvas = $('#imgpro-usage-chart');

        // Use pre-loaded data from PHP on initial load (avoids AJAX call)
        if (!forceRefresh && imgproCdnAdmin.usage) {
            var data = imgproCdnAdmin.usage;

            // Update insights cards
            if (data.insights) {
                updateInsights(data.insights);
            } else {
                showInsightsEmptyState();
            }

            // Render chart
            if (data.daily && data.daily.length > 0) {
                var chartRendered = renderChart(data.daily);
                $chartLoading.hide();
                if (chartRendered) {
                    $chartCanvas.show();
                }
            } else {
                $chartLoading.hide();
                $chartEmpty.show();
            }

            // Clear pre-loaded data so subsequent calls use AJAX
            imgproCdnAdmin.usage = null;
            return;
        }

        // Show chart loading state
        $chartLoading.show();
        $chartEmpty.hide();
        $chartCanvas.hide();

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_get_usage',
                days: chartPeriod,
                nonce: imgproCdnAdmin.nonces.analytics
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Update insights cards
                    if (response.data.insights) {
                        updateInsights(response.data.insights);
                    } else {
                        showInsightsEmptyState();
                    }

                    // Render chart
                    if (response.data.daily && response.data.daily.length > 0) {
                        var chartRendered = renderChart(response.data.daily);
                        $chartLoading.hide();
                        if (chartRendered) {
                            $chartCanvas.show();
                        }
                    } else {
                        $chartLoading.hide();
                        $chartEmpty.show();
                    }
                } else {
                    showInsightsEmptyState();
                    $chartLoading.hide();
                    $chartEmpty.show();
                }
            },
            error: function() {
                showInsightsEmptyState();
                $chartLoading.hide();
                $chartEmpty.show();
            }
        });
    }

    /**
     * Load usage insights (cache hit rate, avg daily, projected, total requests)
     * @deprecated Use loadAnalytics() instead
     */
    function loadInsights() {
        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_get_insights',
                nonce: imgproCdnAdmin.nonces.analytics
            },
            success: function(response) {
                if (response.success && response.data) {
                    updateInsights(response.data);
                } else {
                    // Show empty state (no data yet)
                    showInsightsEmptyState();
                }
            },
            error: function() {
                // On error, show empty state
                showInsightsEmptyState();
            }
        });
    }

    /**
     * Update insights cards with data from API
     */
    function updateInsights(data) {
        // === Top Row: Quick Stats (last 7 days) ===

        // Total Requests (last 7 days)
        var totalRequests = data.total_requests;
        if (totalRequests != null) {
            $('#imgpro-stat-total-requests').text(totalRequests.toLocaleString());
        }

        // Cached (cache hits)
        var cached = data.cache_hits;
        if (cached != null) {
            $('#imgpro-stat-cached').text(cached.toLocaleString());
        }

        // CDN Hit Rate
        var cacheHitRate = data.cache_hit_rate;
        if (cacheHitRate != null) {
            $('#imgpro-stat-cache-hit-rate').text(Math.round(cacheHitRate * 100) + '%');
        }

        // === Bottom Row: Period Insights ===

        // Total Requests This Period
        var requestsData = data.requests || {};
        if (requestsData.formatted) {
            $('#imgpro-requests-total').text(requestsData.formatted);
        } else if (requestsData.this_period != null) {
            $('#imgpro-requests-total').text(requestsData.this_period.toLocaleString());
        }

        // Avg. Daily Requests
        if (requestsData.avg_daily_formatted) {
            $('#imgpro-requests-avg-daily').text(requestsData.avg_daily_formatted);
        } else if (requestsData.avg_daily != null) {
            $('#imgpro-requests-avg-daily').text(requestsData.avg_daily.toLocaleString());
        }

        // Days Until Reset
        var periodData = data.period || {};
        if (periodData.days_remaining != null) {
            $('#imgpro-insight-days').text(periodData.days_remaining);
        }
    }

    /**
     * Show empty state for insights (when no data available)
     */
    function showInsightsEmptyState() {
        $('#imgpro-stat-total-requests, #imgpro-stat-cached, #imgpro-stat-cache-hit-rate').text('—');
        $('#imgpro-requests-total, #imgpro-requests-avg-daily').text('—');
    }

    /**
     * Load chart data for selected period
     */
    function loadChartData() {
        const $chartLoading = $('#imgpro-chart-loading');
        const $chartEmpty = $('#imgpro-chart-empty');
        const $chartCanvas = $('#imgpro-usage-chart');

        // Show loading state
        $chartLoading.show();
        $chartEmpty.hide();
        $chartCanvas.hide();

        $.ajax({
            url: imgproCdnAdmin.ajaxUrl,
            type: 'POST',
            timeout: AJAX_TIMEOUT,
            data: {
                action: 'imgpro_cdn_get_daily_usage',
                days: chartPeriod,
                nonce: imgproCdnAdmin.nonces.analytics
            },
            success: function(response) {
                if (response.success && response.data && response.data.length > 0) {
                    // We have data - render chart
                    var chartRendered = renderChart(response.data);
                    $chartLoading.hide();
                    if (chartRendered) {
                        $chartCanvas.show();
                    }
                    // If chart failed to render, renderChart already shows empty state
                } else {
                    // No data yet - show empty state
                    $chartLoading.hide();
                    $chartEmpty.show();
                }
            },
            error: function() {
                // On error, show empty state
                $chartLoading.hide();
                $chartEmpty.show();
            }
        });
    }

    /**
     * Render Chart.js requests usage chart
     * @returns {boolean} True if chart rendered successfully, false otherwise
     */
    function renderChart(dailyData) {
        const $canvas = $('#imgpro-usage-chart');
        if (!$canvas.length) return false;

        // Check if Chart.js is loaded first (before any other work)
        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            $('#imgpro-chart-empty').show();
            return false;
        }

        const ctx = $canvas[0].getContext('2d');

        // Destroy existing chart if exists
        if (usageChart) {
            usageChart.destroy();
        }

        // Prepare data - primary metric is now requests
        const labels = [];
        const requestsData = [];

        dailyData.forEach(function(day) {
            // Format date: "Jan 15"
            const date = new Date(day.date);
            const formatted = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            labels.push(formatted);

            requestsData.push(day.requests || 0);
        });

        // Create chart with requests as primary metric
        usageChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Requests',
                        data: requestsData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                const requests = context.parsed.y;
                                return 'Requests: ' + requests.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                // Format large numbers with K/M suffix
                                if (value >= 1000000) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                } else if (value >= 1000) {
                                    return (value / 1000).toFixed(1) + 'K';
                                }
                                return value;
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        return true;
    }

    /**
     * Cleanup analytics on page unload
     */
    function cleanupAnalytics() {
        if (analyticsRefreshInterval) {
            clearInterval(analyticsRefreshInterval);
        }
        if (usageChart) {
            usageChart.destroy();
        }
    }

    // Add analytics to initialization
    $(document).ready(function() {
        // Check for onboarding wizard
        if ($('.imgpro-onboarding').length) {
            initOnboarding();
        } else {
            // Dashboard & settings
            initDashboard();
            handlePaymentStatus();
            handleShowUpgrade();

            // Initialize analytics if section exists
            initAnalytics();
        }

        // Plan selector (available on both onboarding and dashboard)
        initPlanSelector();

        // Global Escape key handler for all modals
        $(document).on('keydown.imgpro-modals', function(e) {
            if (e.key === 'Escape') {
                // Check which modal is visible and close it
                if ($('#imgpro-plan-modal').is(':visible')) {
                    closePlanModal();
                } else if ($('#imgpro-recovery-modal').is(':visible')) {
                    $('#imgpro-recovery-modal').remove();
                }
            }
        });

        // Cleanup on page unload
        $(window).on('beforeunload', function() {
            cleanupAnalytics();
        });
    });

})(jQuery);
