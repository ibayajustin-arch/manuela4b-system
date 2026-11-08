<?php
// Shared receipt preview modal.
// Include this once near the bottom of any page (before footer.php) that has receipt buttons.
// Trigger it with:  onclick="openReceipt('payment', 12)"  or  onclick="openReceipt('reservation', 7)"
// Assumes the page lives one folder deep (admin/ or resident/), so receipt.php is at ../receipt.php
?>
<div class="modal fade receipt-preview-modal" id="receiptPreviewModal" tabindex="-1" aria-labelledby="receiptPreviewLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content border-0" style="background-color: var(--bg-color);">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold" id="receiptPreviewLabel">Receipt</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-2">
        <div class="receipt-frame-wrap">
          <div class="receipt-loading" id="receiptLoading" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>Loading receipt…</span>
          </div>
          <iframe id="receiptFrame" title="Receipt preview" style="width: 100%; min-height: 320px; border: 0;"></iframe>
        </div>
      </div>

      <div class="modal-footer border-0 pt-0 pb-3 px-4">
        <div class="btn-group me-auto" role="group" aria-label="Print layout">
          <button type="button" class="btn btn-outline-secondary btn-sm active" id="rcptLayoutHalf">1 copy (half-sheet)</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="rcptLayoutDup">2 copies / bond paper</button>
        </div>
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary px-4 rounded-pill" id="rcptPrintBtn">
            <i class="bi bi-printer me-2"></i> Print
        </button>
      </div>
    </div>
  </div>
</div>

<style>
    #receiptPreviewModal .receipt-frame-wrap { position: relative; }
    #receiptPreviewModal .receipt-loading {
        position: absolute;
        inset: 0;
        z-index: 2;
        display: none;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        border-radius: 12px;
        background: rgba(255, 255, 255, .92);
        color: var(--text-muted);
        font-weight: 600;
    }
    #receiptPreviewModal .receipt-loading.is-visible { display: flex; }

    @media (max-width: 575.98px) {
        #receiptPreviewModal .modal-dialog {
            width: calc(100% - 1rem);
            max-width: none;
            height: auto;
            max-height: calc(100vh - 1rem);
            max-height: calc(100dvh - 1rem);
            margin: .5rem auto;
        }
        #receiptPreviewModal .modal-content {
            display: flex;
            height: auto;
            max-height: calc(100vh - 1rem);
            max-height: calc(100dvh - 1rem);
            overflow: hidden;
            border-radius: 18px;
        }
        #receiptPreviewModal .modal-header { flex: 0 0 auto; padding: .8rem 1rem .5rem; }
        #receiptPreviewModal .modal-body {
            flex: 0 1 auto;
            min-height: 0;
            max-height: calc(100vh - 14rem);
            max-height: calc(100dvh - 14rem);
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            padding: .35rem .5rem;
        }
        #receiptPreviewModal #receiptFrame {
            display: block;
            height: clamp(230px, 43vh, 270px) !important;
            min-height: 230px !important;
        }
        #receiptPreviewModal .modal-footer {
            flex: 0 0 auto;
            display: grid !important;
            grid-template-columns: 1fr 1fr;
            gap: .4rem;
            padding: .4rem .75rem max(.65rem, env(safe-area-inset-bottom));
        }
        #receiptPreviewModal .modal-footer .btn-group {
            grid-column: 1 / -1;
            display: flex;
            width: 100%;
            margin: 0;
        }
        #receiptPreviewModal .modal-footer .btn-group .btn {
            flex: 1 1 50%;
            min-width: 0;
            white-space: normal;
            line-height: 1.15;
            padding: .4rem .3rem;
            font-size: .72rem;
        }
        #receiptPreviewModal .modal-footer > .btn {
            width: 100% !important;
            min-height: 40px;
            margin: 0 !important;
            padding: .4rem !important;
            font-size: .82rem;
        }
    }
</style>

<script>
(function () {
    const modalEl  = document.getElementById('receiptPreviewModal');
    const frame    = document.getElementById('receiptFrame');
    const loading  = document.getElementById('receiptLoading');
    const btnHalf  = document.getElementById('rcptLayoutHalf');
    const btnDup   = document.getElementById('rcptLayoutDup');
    const btnPrint = document.getElementById('rcptPrintBtn');
    if (!modalEl || !frame) return;

    let current = { type: 'payment', id: 0, layout: 'half' };
    let opening = false;

    function receiptUrl(embed) {
        return '../receipt.php?type=' + encodeURIComponent(current.type) +
               '&id=' + encodeURIComponent(current.id) +
               '&layout=' + encodeURIComponent(current.layout) +
               (embed ? '&embed=1' : '');
    }

    // Remove any leftover dark overlay so the page is never left frozen.
    function cleanupBackdrops() {
        if (document.querySelector('.modal.show')) return;
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    function loadReceipt() {
        if (loading) loading.classList.add('is-visible');
        frame.style.height = '320px';
        frame.src = receiptUrl(true);
        btnHalf.classList.toggle('active', current.layout === 'half');
        btnDup.classList.toggle('active', current.layout === 'duplicate');
    }

    function showModal() {
        cleanupBackdrops();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // Fit the iframe to the receipt's height once it finishes loading
    frame.addEventListener('load', function () {
        if (frame.getAttribute('src') === 'about:blank') return;
        if (loading) loading.classList.remove('is-visible');
        try {
            const doc = frame.contentDocument;
            if (doc && doc.documentElement && !window.matchMedia('(max-width: 575.98px)').matches) {
                frame.style.height = doc.documentElement.scrollHeight + 'px';
            }
        } catch (e) { /* cross-origin or blocked: keep the default height */ }
    });

    window.openReceipt = function (type, id) {
        id = parseInt(id, 10);
        if (!id) return;
        current = { type: type === 'reservation' ? 'reservation' : 'payment', id: id, layout: 'half' };

        // Bootstrap not ready (still loading or blocked): open the receipt page instead
        if (!window.bootstrap || !bootstrap.Modal) {
            window.open(receiptUrl(false), '_blank', 'noopener');
            return;
        }

        // Ignore a second tap while the first one is still opening
        if (opening || modalEl.classList.contains('show')) {
            loadReceipt();
            return;
        }
        opening = true;
        loadReceipt();

        // Close any other open modal first, then show the receipt once it is fully gone
        const others = Array.from(document.querySelectorAll('.modal.show')).filter(function (m) { return m !== modalEl; });
        if (!others.length) {
            showModal();
            return;
        }
        let remaining = others.length;
        others.forEach(function (m) {
            m.addEventListener('hidden.bs.modal', function once() {
                m.removeEventListener('hidden.bs.modal', once);
                remaining -= 1;
                if (remaining === 0) showModal();
            });
            bootstrap.Modal.getOrCreateInstance(m).hide();
        });
    };

    modalEl.addEventListener('shown.bs.modal', function () { opening = false; });

    // Clear the frame when closed so the next receipt doesn't flash the old one
    modalEl.addEventListener('hidden.bs.modal', function () {
        opening = false;
        frame.src = 'about:blank';
        if (loading) loading.classList.remove('is-visible');
        window.setTimeout(cleanupBackdrops, 50);
    });

    window.addEventListener('pageshow', cleanupBackdrops);

    btnHalf.addEventListener('click', function () { current.layout = 'half'; loadReceipt(); });
    btnDup.addEventListener('click',  function () { current.layout = 'duplicate'; loadReceipt(); });

    // Print only the receipt (inside the iframe). If the phone browser blocks that,
    // open the receipt in a new tab so it can be printed or saved from there.
    btnPrint.addEventListener('click', function () {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (e) {
            window.open(receiptUrl(false), '_blank', 'noopener');
        }
    });
})();
</script>