/**
 * Bandwidth Saver settings page
 *
 * Connects the img.pro key, runs the worker while there is work, and
 * keeps the copying status current.
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
    var failures = 0;
    var generation = 0;
    var phase = config.status ? config.status.phase : null;
    var pauseReason = config.status ? config.status.pause_reason || '' : '';

    function $(id) {
        return document.getElementById(id);
    }

    function speak(message, politeness) {
        if (message && window.wp && window.wp.a11y) {
            window.wp.a11y.speak(message, politeness);
        }
    }

    function setHidden(element, hidden) {
        if (element) {
            element.classList.toggle('hidden', hidden);
        }
    }

    function setText(id, text) {
        var element = $(id);
        if (element && typeof text === 'string') {
            element.textContent = text;
        }
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
                return null;
            }).then(function (body) {
                if (body && typeof body === 'object') {
                    return body;
                }
                // admin-ajax answers a bare -1 or 0 when the nonce or the
                // login is no longer valid; only a reload can fix that
                var expired = body === -1 || body === 0;
                return {
                    success: false,
                    data: {
                        code: expired ? 'session_expired' : '',
                        message: expired ? i18n.sessionExpired : i18n.genericError
                    }
                };
            });
        });
    }

    // Mirrors ImgPro_CDN_Admin::can_resume()
    function canResume(status) {
        return (status.phase === 'paused' && ['auth', 'deleting'].indexOf(status.pause_reason) === -1) || status.phase === 'waiting';
    }

    function render(payload) {
        if (!payload || !payload.connected) {
            window.location.reload();
            return null;
        }

        var status = payload.status;
        var labels = payload.labels || {};

        // A new pause brings its own notice, and maybe a key form, which
        // only the page draws; one that lifted leaves a stale notice
        if ((status.pause_reason || '') !== pauseReason) {
            if (status.pause_reason) {
                window.location.reload();
                return null;
            }
            pauseReason = '';
            setHidden($('imgpro-pause-notice'), true);
        }

        setText('imgpro-status-text', labels.text);
        Object.keys(labels.counts || {}).forEach(function (key) {
            setText('imgpro-count-' + key, labels.counts[key]);
        });
        setText('imgpro-usage-images', payload.images);

        setHidden($('imgpro-row-deleting'), !status.deleting);
        setHidden($('imgpro-row-remote'), !status.remote);
        setHidden($('imgpro-retry'), !status.failed);
        setHidden($('imgpro-resume'), !canResume(status));

        // Announce what the worker does, not every count it passes
        if (status.phase !== phase) {
            phase = status.phase;
            speak(labels.text);
        }

        return status;
    }

    function schedule(status) {
        generation++;
        clearTimeout(timer);
        if (!status) {
            return;
        }

        if (['scanning', 'matching', 'uploading', 'removing'].indexOf(status.phase) !== -1) {
            // Work remains: run another step right away, or later when
            // another request is already syncing
            timer = setTimeout(step, status.wait > 0 ? status.wait * 1000 : 500);
        } else if (status.phase === 'waiting') {
            timer = setTimeout(step, Math.max(5, status.wait || 15) * 1000);
        } else {
            // Pages may queue files at any time
            timer = setTimeout(refresh, 30000);
        }
    }

    // A network blip or server error must not end the loop, or the page
    // stops updating; retry, waiting longer each time (up to 5 minutes).
    // An expired session or a lost permission cannot be retried away, so
    // the loop stops and asks for a reload.
    function poll(action, retry) {
        var current = generation;
        post(action, 'sync').then(function (response) {
            // A button click or a stop() since this request went out made its
            // answer stale; the newer state stands
            if (current !== generation) {
                return;
            }
            if (response.success) {
                failures = 0;
                schedule(render(response.data));
                return;
            }
            var code = response.data && response.data.code;
            if (code === 'session_expired' || code === 'permission_denied') {
                stop((response.data && response.data.message) || i18n.sessionExpired);
                return;
            }
            throw new Error('request failed');
        }).catch(function () {
            // A button click scheduled newer work while this request was
            // out; its failure must not replace that schedule
            if (current !== generation) {
                return;
            }
            failures++;
            clearTimeout(timer);
            timer = setTimeout(retry, Math.min(300000, 15000 * Math.pow(2, failures - 1)));
        });
    }

    function stop(message, silent) {
        generation++;
        clearTimeout(timer);
        setText('imgpro-status-text', message);
        setHidden($('imgpro-resume'), true);
        if (!silent) {
            speak(message, 'assertive');
        }
    }

    function step() {
        poll('imgpro_cdn_sync_step', step);
    }

    function refresh() {
        poll('imgpro_cdn_sync_status', refresh);
    }

    // An inline error notice next to the control that failed, like core's
    function showError(anchor, message) {
        clearErrors();
        var notice = document.createElement('div');
        notice.className = 'notice notice-error inline imgpro-error';
        notice.appendChild(document.createElement('p')).textContent = message;

        var cell = anchor.closest('td');
        if (cell) {
            cell.appendChild(notice);
        } else {
            anchor.parentNode.parentNode.insertBefore(notice, anchor.parentNode.nextSibling);
        }
        speak(message, 'assertive');
    }

    function clearErrors() {
        Array.prototype.forEach.call(document.querySelectorAll('.imgpro-error'), function (notice) {
            notice.parentNode.removeChild(notice);
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

            function fail(message) {
                error.querySelector('p').textContent = message;
                setHidden(error, false);
                button.disabled = false;
                button.textContent = i18n.connect;
                speak(message, 'assertive');
            }

            setHidden(error, true);
            button.disabled = true;
            button.textContent = i18n.connecting;

            post('imgpro_cdn_connect', 'connect', { api_key: input.value.trim() }).then(function (response) {
                if (response.success) {
                    window.location.reload();
                    return;
                }
                fail((response.data && response.data.message) || i18n.genericError);
            }).catch(function () {
                fail(i18n.genericError);
            });
        });
    }

    function bindButton(id, action, nonceKey, confirmText, spoken) {
        var button = $(id);
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            clearErrors();
            button.disabled = true;
            // Answers to polls already out describe the state before this click
            generation++;
            post(action, nonceKey).then(function (response) {
                button.disabled = false;
                if (!response.success) {
                    var code = response.data && response.data.code;
                    var message = (response.data && response.data.message) || i18n.genericError;
                    // Only a reload helps: say so beside the button, and stop
                    // polling, which would only say it again
                    if (code === 'session_expired' || code === 'permission_denied') {
                        showError(button, message);
                        stop(message, true);
                        return;
                    }
                    showError(button, message);
                    refresh();
                    return;
                }
                if (id === 'imgpro-remove-all' || id === 'imgpro-disconnect') {
                    window.location.reload();
                    return;
                }
                if (spoken) {
                    // One announcement: a second speak() would replace this one
                    var payload = response.data || {};
                    phase = payload.status ? payload.status.phase : phase;
                    speak(spoken + ' ' + ((payload.labels && payload.labels.text) || ''));
                }
                // The user asked for work to resume: start retries afresh
                failures = 0;
                schedule(render(response.data));
            }).catch(function () {
                button.disabled = false;
                showError(button, i18n.genericError);
                refresh();
            });
        });
    }

    bindConnect();
    bindButton('imgpro-retry', 'imgpro_cdn_retry_failed', 'sync', null, i18n.retried);
    bindButton('imgpro-resume', 'imgpro_cdn_resume_sync', 'sync');
    bindButton('imgpro-disconnect', 'imgpro_cdn_disconnect', 'connect', i18n.confirmDisconnect);
    bindButton('imgpro-remove-all', 'imgpro_cdn_remove_all', 'remove_all', i18n.confirmRemove);

    if (config.connected && config.status) {
        schedule(config.status);
    }
})();
