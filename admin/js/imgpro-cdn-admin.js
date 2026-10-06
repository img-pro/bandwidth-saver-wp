/**
 * Bandwidth Saver settings page
 *
 * Connects the img.pro key, drives the media sync while the page is open
 * and keeps the progress card current.
 *
 * @package ImgPro_CDN
 * @since   2.0.0
 */
(function () {
    'use strict';

    var config = window.imgproCdnAdmin;
    if (!config) {
        return;
    }

    var i18n = config.i18n;
    var timer = null;

    function $(id) {
        return document.getElementById(id);
    }

    function post(action, nonceKey, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', config.nonces[nonceKey] || '');
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, data: { message: i18n.genericError } };
            });
        });
    }

    function format(template, values) {
        var index = 0;
        return template.replace(/%(\d+)\$s/g, function (match, position) {
            return values[parseInt(position, 10) - 1];
        }).replace(/%s/g, function () {
            return values[index++];
        });
    }

    function number(value) {
        return Number(value || 0).toLocaleString();
    }

    function setHidden(element, hidden) {
        if (element) {
            element.hidden = hidden;
        }
    }

    function render(payload) {
        if (!payload || !payload.connected) {
            window.location.reload();
            return null;
        }

        var status = payload.status;
        var phase = $('imgpro-phase');
        if (phase) {
            phase.textContent = i18n.phases[status.phase] || '';
        }

        var bar = $('imgpro-progress-bar');
        if (bar) {
            bar.style.width = status.percent + '%';
            bar.parentNode.setAttribute('aria-valuenow', String(status.percent));
        }

        var text = $('imgpro-progress-text');
        if (text) {
            text.textContent = format(i18n.progress, [number(status.synced), number(status.total)]);
        }

        ['pending', 'failed', 'skipped'].forEach(function (key) {
            var element = $('imgpro-count-' + key);
            if (element) {
                element.textContent = number(status[key]);
            }
        });

        var images = $('imgpro-usage-images');
        if (images && payload.images) {
            images.textContent = payload.images;
        }

        setHidden($('imgpro-retry'), !status.failed);
        setHidden($('imgpro-resume'), !(status.phase === 'paused' && status.pause_reason !== 'auth'));

        return status;
    }

    function schedule(status) {
        clearTimeout(timer);
        if (!status) {
            return;
        }

        if (['scanning', 'matching', 'uploading', 'removing'].indexOf(status.phase) !== -1) {
            // Work remains: run another step right away
            timer = setTimeout(step, 500);
        } else if (status.phase === 'waiting') {
            timer = setTimeout(step, Math.max(5, status.wait || 15) * 1000);
        } else {
            timer = setTimeout(refresh, 30000);
        }
    }

    function step() {
        post('imgpro_cdn_sync_step', 'sync').then(function (response) {
            schedule(response.success ? render(response.data) : null);
        }).catch(function () {
            timer = setTimeout(step, 15000);
        });
    }

    function refresh() {
        post('imgpro_cdn_sync_status', 'sync').then(function (response) {
            schedule(response.success ? render(response.data) : null);
        });
    }

    function bindConnect() {
        var form = $('imgpro-connect-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var input = $('imgpro-api-key');
            var button = form.querySelector('button[type="submit"]');
            var error = $('imgpro-connect-error');

            setHidden(error, true);
            button.disabled = true;
            button.textContent = i18n.connecting;

            post('imgpro_cdn_connect', 'connect', { api_key: input.value.trim() }).then(function (response) {
                if (response.success) {
                    window.location.reload();
                    return;
                }
                error.textContent = (response.data && response.data.message) || i18n.genericError;
                setHidden(error, false);
                button.disabled = false;
                button.textContent = i18n.connect;
            }).catch(function () {
                error.textContent = i18n.genericError;
                setHidden(error, false);
                button.disabled = false;
                button.textContent = i18n.connect;
            });
        });
    }

    function bindButton(id, action, nonceKey, confirmText) {
        var button = $(id);
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            button.disabled = true;
            post(action, nonceKey).then(function (response) {
                button.disabled = false;
                if (!response.success) {
                    window.alert((response.data && response.data.message) || i18n.genericError);
                    return;
                }
                if (id === 'imgpro-remove-all') {
                    window.location.reload();
                    return;
                }
                schedule(render(response.data));
            });
        });
    }

    function bindToggle() {
        var toggle = $('imgpro-enabled');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('change', function () {
            toggle.disabled = true;
            post('imgpro_cdn_toggle_enabled', 'toggle_enabled', { enabled: toggle.checked ? '1' : '0' }).then(function (response) {
                toggle.disabled = false;
                if (!response.success) {
                    toggle.checked = !toggle.checked;
                    window.alert((response.data && response.data.message) || i18n.genericError);
                    return;
                }
                var text = $('imgpro-toggle-text');
                if (text) {
                    text.textContent = response.data.enabled ? i18n.toggleOn : i18n.toggleOff;
                }
            });
        });
    }

    bindConnect();
    bindToggle();
    bindButton('imgpro-retry', 'imgpro_cdn_retry_failed', 'sync');
    bindButton('imgpro-resume', 'imgpro_cdn_resume_sync', 'sync');
    bindButton('imgpro-disconnect', 'imgpro_cdn_disconnect', 'connect', i18n.confirmDisconnect);
    bindButton('imgpro-remove-all', 'imgpro_cdn_remove_all', 'remove_all', i18n.confirmRemove);

    if (config.connected && config.status) {
        schedule(render({ connected: true, status: config.status }));
    }
})();
