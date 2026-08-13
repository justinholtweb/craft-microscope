/**
 * Microscope control panel behaviour.
 *
 * Two jobs: expand and collapse findings, and poll for a queued scan to finish. Deliberately
 * dependency-free vanilla JS — Craft's own Garnish and jQuery are available, but nothing here
 * needs them, and not depending on them means the plugin can't break when Craft changes its
 * front-end stack.
 */
(function () {
    'use strict';

    /**
     * Findings expand to reveal the remediation.
     *
     * Uses a button and `aria-expanded` rather than `<details>` so the header can carry a badge
     * and measurements without the summary element's layout quirks, and so it stays keyboard-
     * and screen-reader-navigable.
     */
    function initFindings(root) {
        var headers = root.querySelectorAll('[data-microscope-toggle]');

        Array.prototype.forEach.call(headers, function (header) {
            if (header.dataset.microscopeBound) {
                return;
            }

            header.dataset.microscopeBound = '1';

            header.addEventListener('click', function () {
                var finding = header.closest('.microscope-finding');
                var body = finding.querySelector('.microscope-finding-body');

                if (!body) {
                    return;
                }

                var isOpen = finding.classList.toggle('is-open');
                body.hidden = !isOpen;
                header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        });
    }

    /**
     * After a scan is queued, watch for a newer scan to appear and then reload.
     *
     * The scan runs on Craft's queue, so there is no response to wait on — the page has to ask.
     * Polling stops after a generous ceiling rather than running forever: on a site whose queue
     * isn't running, the honest outcome is a message saying so, not a tab that spins all afternoon.
     */
    function initScanPolling(root) {
        var el = root.querySelector('[data-microscope-poll]');

        if (!el) {
            return;
        }

        var since = parseInt(el.dataset.microscopePoll, 10) || 0;
        var statusUrl = el.dataset.microscopeStatusUrl;
        var attempts = 0;
        var maxAttempts = 150; // 150 × 4s ≈ 10 minutes.

        function poll() {
            attempts++;

            if (attempts > maxAttempts) {
                var timeout = el.querySelector('[data-microscope-timeout]');

                if (timeout) {
                    timeout.hidden = false;
                }

                return;
            }

            fetch(statusUrl + '&since=' + since, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data && data.finished && data.url) {
                        window.location.href = data.url;
                        return;
                    }

                    window.setTimeout(poll, 4000);
                })
                .catch(function () {
                    // A transient failure shouldn't end the watch — the queue is probably still
                    // working. Back off and try again.
                    window.setTimeout(poll, 8000);
                });
        }

        window.setTimeout(poll, 4000);
    }

    /**
     * Check toggles on the catalogue screen save immediately.
     */
    function initCheckToggles(root) {
        var toggles = root.querySelectorAll('[data-microscope-check]');

        Array.prototype.forEach.call(toggles, function (toggle) {
            if (toggle.dataset.microscopeBound) {
                return;
            }

            toggle.dataset.microscopeBound = '1';

            toggle.addEventListener('change', function () {
                var handle = toggle.dataset.microscopeCheck;
                var enabled = toggle.checked;

                // Craft.sendActionRequest handles the CSRF token and the action URL, and is
                // available on every control panel page.
                Craft.sendActionRequest('POST', 'microscope/checks/toggle', {
                    data: { handle: handle, enabled: enabled ? 1 : 0 }
                })
                    .then(function () {
                        Craft.cp.displayNotice(
                            enabled ? Craft.t('microscope', 'Check enabled.') : Craft.t('microscope', 'Check disabled.')
                        );
                    })
                    .catch(function () {
                        // Put the control back where it was, so the UI never claims a state that
                        // wasn't saved.
                        toggle.checked = !enabled;
                        Craft.cp.displayError(Craft.t('microscope', 'Couldn’t save that.'));
                    });
            });
        });
    }

    function init() {
        initFindings(document);
        initScanPolling(document);
        initCheckToggles(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
