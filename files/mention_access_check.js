/**
 * Warn the author of a note, while typing, when an @mentioned user cannot see
 * the issue (or the private note) and therefore will neither be notified nor
 * added as a monitor. Also points out @mentions that match no user (typos).
 *
 * Works with the plain textarea and with the ToastUI editor of
 * ImaticFormatting, which mirrors its content into #bugnote_text and fires
 * 'input' on it.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 500;

    function init() {
        var settingsEl = document.getElementById('imaticAutoMonitoringMentions');
        var textarea = document.getElementById('bugnote_text');
        var row = document.getElementById('imatic-mention-access-row');
        var box = document.getElementById('imatic-mention-access-warning');

        if (!settingsEl || !textarea || !row || !box) {
            return;
        }

        var settings;
        try {
            settings = JSON.parse(settingsEl.getAttribute('data-settings'));
        } catch (e) {
            return;
        }

        var privateCheckbox = document.getElementById('bugnote_add_view_status');
        var timer = null;
        var lastRequest = 0;

        function isPrivate() {
            return !!(privateCheckbox && privateCheckbox.checked);
        }

        function render(result, wasPrivate) {
            var parts = [];

            if (result.no_access && result.no_access.length) {
                var template = wasPrivate ? settings.lang.no_access_private : settings.lang.no_access;
                parts.push(template.replace('%s', '@' + result.no_access.join(', @')));
            }
            if (result.unknown && result.unknown.length) {
                parts.push(settings.lang.unknown.replace('%s', '@' + result.unknown.join(', @')));
            }

            if (!parts.length) {
                row.hidden = true;
                box.textContent = '';
                return;
            }

            box.textContent = '';
            parts.forEach(function (text) {
                var p = document.createElement('div');
                p.textContent = text;
                box.appendChild(p);
            });
            row.hidden = false;
        }

        function check() {
            var text = textarea.value || '';

            if (text.indexOf('@') === -1) {
                render({}, false);
                return;
            }

            var requestId = ++lastRequest;
            var wasPrivate = isPrivate();

            fetch(settings.check_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bug_id: settings.bug_id, text: text, private: wasPrivate })
            })
                .then(function (response) { return response.ok ? response.json() : {}; })
                .then(function (result) {
                    // Ignore responses that arrive out of order.
                    if (requestId === lastRequest) {
                        render(result, wasPrivate);
                    }
                })
                .catch(function () { /* the warning is best effort */ });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(check, DEBOUNCE_MS);
        }

        textarea.addEventListener('input', schedule);
        textarea.addEventListener('change', schedule);
        if (privateCheckbox) {
            privateCheckbox.addEventListener('change', schedule);
        }

        // Text restored by the browser or by ImaticPersistentBugnoteText.
        if ((textarea.value || '').indexOf('@') !== -1) {
            schedule();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
