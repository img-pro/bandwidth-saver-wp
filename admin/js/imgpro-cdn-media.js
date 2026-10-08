/**
 * Bandwidth Saver: Copy to img.pro in the attachment details and on the
 * attachment edit screen
 *
 * Copies in place, like core's own buttons there. "Copy img.pro URL" needs
 * no code here: core's clipboard handlers pick it up.
 *
 * @package ImgPro_CDN
 * @since   2.0.0
 */
(function ($) {
    'use strict';

    var config = window.imgproCdnMedia;
    if (!config) {
        return;
    }

    $(document).on('click', '.imgpro-copy', function () {
        var $button = $(this);
        var $block = $button.closest('.imgpro-media');
        var id = parseInt($block.data('id'), 10);
        var label = $button.text();

        if ($button.attr('data-doing-ajax') || !id) {
            return;
        }

        $block.find('.imgpro-media-error').remove();
        $button
            .attr({ 'data-doing-ajax': 'true', 'aria-disabled': 'true' })
            .addClass('updating-message')
            .text(config.i18n.copying);

        wp.ajax.post(config.action, {
            nonce: config.nonce,
            attachment_id: id,
            context: $block.data('context')
        }).done(function (data) {
            var $updated = $(data.html);
            $block.replaceWith($updated);

            // Keep focus where it was, or on the status when the button is gone
            var $focus = $updated.find('.imgpro-copy');
            if (!$focus.length) {
                $focus = $updated.find('.imgpro-media-status').attr('tabindex', '-1');
            }
            $focus.trigger('focus');
            wp.a11y.speak(data.message);

            // The details are drawn from the attachment's model the next time
            // they open, so it needs the new status too
            if (wp.media && wp.media.attachment) {
                wp.media.attachment(id).fetch();
            }
        }).fail(function (data) {
            // wp.ajax hands over the JSON error's data, or the raw request
            // when the answer was not a 200 (a rate limit is a 429), or a
            // bare -1 or 0 when the nonce or the login expired
            var json = data && data.responseJSON;
            var raw = data && typeof data.responseText === 'string' ? data.responseText : data;
            var message = (data && data.message) ||
                (json && json.data && json.data.message) ||
                (raw === '-1' || raw === '0' || raw === -1 || raw === 0 ? config.i18n.expired : config.i18n.error);

            $button
                .removeAttr('data-doing-ajax aria-disabled')
                .removeClass('updating-message')
                .text(label);
            $('<div class="notice notice-error notice-alt inline imgpro-media-error"><p></p></div>')
                .find('p').text(message).end()
                .appendTo($block);
            wp.a11y.speak(message, 'assertive');
        });
    });
})(jQuery);
