/**
 * Microscope control panel behaviour, for Craft 6.
 *
 * Craft 6 loads this on every control panel page, in <head>, before Vue renders the page, and
 * swaps page content with Inertia visits. So nothing binds to elements directly: clicks, changes
 * and submits are delegated from `document`, and the scan-status poll looks for its element after
 * each Inertia navigation. No jQuery and no `Craft.*` helpers, which Craft 6 doesn't provide.
 */
(function () {
    'use strict';

    // Findings expand to reveal the remediation. --------------------------------------------
    document.addEventListener('click', function (event) {
        var header = event.target.closest('[data-microscope-toggle]');

        if (!header) {
            return;
        }

        var finding = header.closest('.microscope-finding');
        var body = finding && finding.querySelector('.microscope-finding-body');

        if (!body) {
            return;
        }

        var isOpen = finding.classList.toggle('is-open');
        body.hidden = !isOpen;
        header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Destructive forms ask first. Capture phase, so a cancel stops the submit before anything
    // else on the page sees it. ---------------------------------------------------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form && form.dataset ? form.dataset.microscopeConfirm : null;

        if (message && !window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    // Check toggles on the catalogue screen save immediately. --------------------------------
    function xsrfToken() {
        var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

        return match ? decodeURIComponent(match[1]) : '';
    }

    function say(toggle, text, isError) {
        var row = toggle.closest('.microscope-check-row');
        var status = row && row.querySelector('[data-microscope-check-status]');

        if (status) {
            status.textContent = text;
            status.classList.toggle('is-error', !!isError);
        }
    }

    document.addEventListener('change', function (event) {
        var toggle = event.target.closest('[data-microscope-check]');

        if (!toggle) {
            return;
        }

        var enabled = toggle.checked;
        toggle.disabled = true;

        fetch(toggle.dataset.microscopeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken()
            },
            body: JSON.stringify({ handle: toggle.dataset.microscopeCheck, enabled: enabled })
        })
            .then(function (response) {
                return response.ok ? response.json() : Promise.reject(response);
            })
            .then(function (data) {
                say(toggle, data.message || '', false);
            })
            .catch(function () {
                // Put the control back, so the page never claims a state that wasn't saved.
                toggle.checked = !enabled;
                say(toggle, toggle.dataset.microscopeError || 'Couldn’t save that.', true);
            })
            .finally(function () {
                toggle.disabled = false;
            });
    });

    // After a scan is queued, watch for it to finish, then open it. ------------------------
    var polling = null;

    function startPolling() {
        var el = document.querySelector('[data-microscope-poll]');

        if (!el || el.dataset.microscopePolling) {
            return;
        }

        el.dataset.microscopePolling = '1';

        var since = parseInt(el.dataset.microscopePoll, 10) || 0;
        var statusUrl = el.dataset.microscopeStatusUrl;
        var attempts = 0;
        var maxAttempts = 150; // 150 × 4s ≈ 10 minutes.

        function poll() {
            // The page moved on (Inertia swapped it out): stop.
            if (!document.body.contains(el)) {
                polling = null;
                return;
            }

            if (++attempts > maxAttempts) {
                var timeout = el.querySelector('[data-microscope-timeout]');

                if (timeout) {
                    timeout.hidden = false;
                }

                return;
            }

            var url = statusUrl + (statusUrl.indexOf('?') === -1 ? '?' : '&') + 'since=' + since;

            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data && data.finished && data.url) {
                        window.location.href = data.url;
                        return;
                    }

                    polling = window.setTimeout(poll, 4000);
                })
                .catch(function () {
                    // A transient failure shouldn't end the watch: back off and try again.
                    polling = window.setTimeout(poll, 8000);
                });
        }

        polling = window.setTimeout(poll, 4000);
    }

    // The page isn't rendered yet when this runs, and Inertia replaces it on navigation, so look
    // for the poll element for a few seconds after load and after every visit.
    function watchForPoll() {
        var tries = 0;
        var timer = window.setInterval(function () {
            startPolling();

            if (++tries > 20) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    document.addEventListener('inertia:navigate', function () {
        if (polling) {
            window.clearTimeout(polling);
            polling = null;
        }

        watchForPoll();
    });

    watchForPoll();
})();
