/*
 * Two admin settings: the live API key check. While the merchant types a key
 * or changes the environment, the admin asks Two about the pair (the typed
 * key, or the saved one when the field is empty) and shows the verdict under
 * the key field. Nothing is saved or cached by the check; the save re-checks.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 600;

    function init() {
        var key = document.getElementById('input-api-key');
        var environment = document.getElementById('input-environment');
        var status = document.getElementById('two-key-status');
        var panel = document.getElementById('two-merchant-panel');
        if (!key || !environment || !status || !panel) {
            return;
        }
        var timer = null;
        var sequence = 0;

        function show(text, tone) {
            status.textContent = text;
            status.className = 'small mt-1 text-' + tone;
        }

        function check() {
            var mine = ++sequence;
            show(key.getAttribute('data-checking') || '', 'muted');
            var body = new URLSearchParams();
            body.append('api_key', key.value);
            body.append('environment', environment.value);
            fetch(key.getAttribute('data-verify-url'), {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (mine !== sequence) {
                        return;
                    }
                    panel.classList.toggle('d-none', !result.ok);
                    if (result.ok) {
                        document.getElementById('two-merchant-id').textContent = result.merchant_id || '';
                        document.getElementById('two-merchant-short-name').textContent = result.merchant_short_name || '';
                        document.getElementById('two-merchant-short-name-row').classList.toggle('d-none', !result.merchant_short_name);
                    }
                    show(result.message || '', result.ok ? 'success' : (result.definitive ? 'danger' : 'warning'));
                })
                .catch(function () {
                    if (mine === sequence) {
                        show('', 'muted');
                    }
                });
        }

        key.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(check, DEBOUNCE_MS);
        });
        environment.addEventListener('change', function () {
            clearTimeout(timer);
            check();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
