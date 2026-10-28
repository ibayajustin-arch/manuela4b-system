    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $basePath ?>/Assets/js/password-visibility.js?v=1"></script>
    <script>
    // Sidebar: collapse, mobile drawer, remembered collapsed state
    (function () {
        var root = document.documentElement;
        var sidebar = document.getElementById('appSidebar');
        if (!sidebar) return;

        var toggleBtn = document.getElementById('sidebarToggle');
        var openBtn   = document.getElementById('sidebarOpen');
        var backdrop  = document.getElementById('sidebarBackdrop');
        var KEY_C = 'sb_collapsed';

        function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
        function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
        function isDesktop() { return window.matchMedia('(min-width: 992px)').matches; }

        // Tooltips: only while collapsed on desktop
        var tips = [];
        function buildTips() {
            if (!window.bootstrap || !bootstrap.Tooltip) return;
            sidebar.querySelectorAll('[data-sb-label]').forEach(function (el) {
                tips.push(new bootstrap.Tooltip(el, {
                    title: el.getAttribute('data-sb-label'),
                    placement: 'right',
                    container: 'body',
                    trigger: 'hover'
                }));
            });
        }
        function refreshTips() {
            var on = isDesktop() && root.classList.contains('sb-collapsed');
            tips.forEach(function (t) { if (on) { t.enable(); } else { t.hide(); t.disable(); } });
        }

        function refreshIcon() {
            var icon = toggleBtn && toggleBtn.querySelector('i');
            if (!icon) return;
            if (!isDesktop()) { icon.className = 'bi bi-x-lg'; toggleBtn.setAttribute('aria-label', 'Close menu'); }
            else if (root.classList.contains('sb-collapsed')) { icon.className = 'bi bi-list'; toggleBtn.setAttribute('aria-label', 'Expand menu'); }
            else { icon.className = 'bi bi-chevron-double-left'; toggleBtn.setAttribute('aria-label', 'Collapse menu'); }
        }

        function setCollapsed(c, save) {
            root.classList.toggle('sb-collapsed', c);
            if (save !== false) store(KEY_C, c ? '1' : '0');
            refreshIcon();
            refreshTips();
        }

        function closeDrawer() { root.classList.remove('sb-open'); }
        function openDrawer()  { root.classList.add('sb-open'); }

        // Initialize from the saved collapsed state.
        buildTips();
        refreshIcon();
        refreshTips();

        if (toggleBtn) toggleBtn.addEventListener('click', function () {
            if (!isDesktop()) { closeDrawer(); return; }
            setCollapsed(!root.classList.contains('sb-collapsed'));
        });
        if (openBtn)  openBtn.addEventListener('click', openDrawer);
        if (backdrop) backdrop.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
        sidebar.querySelectorAll('a.nav-link').forEach(function (a) {
            a.addEventListener('click', function () { if (!isDesktop()) closeDrawer(); });
        });
        window.addEventListener('resize', function () {
            if (isDesktop()) closeDrawer();
            refreshIcon();
            refreshTips();
        });

    })();
    </script>
    <div class="modal fade" id="paymentConfirmModal" tabindex="-1" aria-labelledby="paymentConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered payment-confirm-dialog">
            <div class="modal-content">
                <div class="modal-body p-4 p-sm-5 text-center">
                    <div class="payment-confirm-icon" aria-hidden="true">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h2 class="h4 fw-bold mb-3" id="paymentConfirmTitle">Are you sure?</h2>
                    <p class="payment-confirm-note mb-4">
                        If you proceed, you will be redirected to PayMongo to complete your payment.
                        No money will be sent until you confirm the transaction there.
                    </p>
                    <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="paymentConfirmProceed">
                            Proceed to payment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var overlay = document.createElement('div');
        overlay.className = 'page-loading-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.appendChild(overlay);
        var loadingTimer = null;

        function createDotSpinner(small) {
            var spinner = document.createElement('span');
            spinner.className = 'dot-spinner' + (small ? ' dot-spinner--small' : '');
            spinner.setAttribute('aria-hidden', 'true');
            for (var i = 0; i < 8; i++) {
                var dot = document.createElement('span');
                dot.className = 'dot-spinner__dot';
                spinner.appendChild(dot);
            }
            return spinner;
        }

        function hidePageLoading() {
            if (loadingTimer !== null) {
                window.clearTimeout(loadingTimer);
                loadingTimer = null;
            }
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.removeAttribute('aria-busy');
        }

        function showPageLoading(text, duration) {
            if (loadingTimer !== null) {
                window.clearTimeout(loadingTimer);
                loadingTimer = null;
            }
            overlay.replaceChildren();
            overlay.appendChild(createDotSpinner(false));
            var message = document.createElement('span');
            message.textContent = text || 'Please wait...';
            overlay.appendChild(message);
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.setAttribute('aria-busy', 'true');
            if (duration > 0) {
                loadingTimer = window.setTimeout(hidePageLoading, duration);
            }
        }

        window.showPageLoading = showPageLoading;

        function showLoading(element, text) {
            if (!element.dataset.originalContent) {
                element.dataset.originalContent = element.innerHTML;
            }
            element.replaceChildren(createDotSpinner(true), document.createTextNode(' ' + text));
            element.setAttribute('aria-busy', 'true');
            element.setAttribute('aria-disabled', 'true');
            if ('disabled' in element) element.disabled = true;
        }

        function restore(element) {
            if (!element.dataset.originalContent) return;
            element.innerHTML = element.dataset.originalContent;
            delete element.dataset.originalContent;
            element.removeAttribute('aria-busy');
            element.removeAttribute('aria-disabled');
            if ('disabled' in element) element.disabled = false;
        }

        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            var form = event.target;
            if (form.hasAttribute('data-payment-confirm') && form.dataset.paymentConfirmed !== 'true') {
                event.preventDefault();
                if (!paymentConfirmInstance) {
                    throw new Error('Payment confirmation modal is unavailable.');
                }
                pendingPaymentForm = form;
                pendingPaymentUrl = null;
                paymentConfirmInstance.show();
                return;
            }
            if (form.dataset.loading === 'true') {
                event.preventDefault();
                return;
            }
            if (form.hasAttribute('data-fullscreen-loading')) {
                form.dataset.loading = 'true';
                showPageLoading(
                    form.getAttribute('data-fullscreen-loading'),
                    Number(form.getAttribute('data-loading-duration'))
                );
                return;
            }
            var button = event.submitter || form.querySelector('[data-loading-text]');
            if (!button || !button.hasAttribute('data-loading-text')) return;

            form.dataset.loading = 'true';
            showLoading(button, button.getAttribute('data-loading-text'));

            if (form.target === '_blank') {
                window.setTimeout(function () {
                    restore(button);
                    delete form.dataset.loading;
                }, 1500);
            }
        });

        var paymentConfirmModal = document.getElementById('paymentConfirmModal');
        var paymentConfirmInstance = window.bootstrap
            ? bootstrap.Modal.getOrCreateInstance(paymentConfirmModal)
            : null;
        var pendingPaymentUrl = null;
        var pendingPaymentForm = null;
        var paymentConfirmProceed = document.getElementById('paymentConfirmProceed');

        if (paymentConfirmModal) {
            paymentConfirmModal.addEventListener('hidden.bs.modal', function () {
                pendingPaymentUrl = null;
                pendingPaymentForm = null;
            });
        }

        if (paymentConfirmProceed) {
            paymentConfirmProceed.addEventListener('click', function () {
                if (pendingPaymentForm) {
                    var paymentForm = pendingPaymentForm;
                    pendingPaymentForm = null;
                    paymentForm.dataset.paymentConfirmed = 'true';
                    if (paymentConfirmInstance) paymentConfirmInstance.hide();
                    paymentForm.requestSubmit();
                    return;
                }
                if (!pendingPaymentUrl) return;
                var paymentUrl = pendingPaymentUrl;
                pendingPaymentUrl = null;
                if (paymentConfirmInstance) paymentConfirmInstance.hide();
                showPageLoading('Opening payment...', 900);
                window.setTimeout(function () {
                    window.location.assign(paymentUrl);
                }, 250);
            });
        }

        document.addEventListener('click', function (event) {
            var paymentLink = event.target.closest('a[data-payment-confirm]');
            if (paymentLink && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
                event.preventDefault();
                if (!paymentConfirmInstance) {
                    throw new Error('Payment confirmation modal is unavailable.');
                }
                pendingPaymentUrl = paymentLink.href;
                paymentConfirmInstance.show();
                return;
            }

            var loadingLink = event.target.closest('a[data-fullscreen-loading]');
            if (loadingLink) {
                if (loadingLink.dataset.loading === 'true') {
                    event.preventDefault();
                    return;
                }
                loadingLink.dataset.loading = 'true';
                loadingLink.setAttribute('aria-disabled', 'true');
                showPageLoading(
                    loadingLink.getAttribute('data-fullscreen-loading'),
                    Number(loadingLink.getAttribute('data-loading-duration'))
                );
                return;
            }

            var link = event.target.closest('a[data-loading-text]');
            if (!link) return;
            if (link.dataset.loading === 'true') {
                event.preventDefault();
                return;
            }
            link.dataset.loading = 'true';
            showLoading(link, link.getAttribute('data-loading-text'));
        });
    })();
    </script>
</body>
</html>
