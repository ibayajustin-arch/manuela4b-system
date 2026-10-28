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
        <iframe id="receiptFrame" title="Receipt preview" style="width: 100%; min-height: 320px; border: 0;"></iframe>
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
    @media (max-width: 575.98px) {
        #receiptPreviewModal .modal-dialog { margin: .5rem; }
        #receiptPreviewModal .modal-header { padding: 1rem 1rem .5rem; }
        #receiptPreviewModal .modal-body { padding: .5rem; }
        #receiptPreviewModal .modal-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .5rem;
            padding: .5rem 1rem 1rem;
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
            line-height: 1.25;
            padding: .55rem .4rem;
        }
        #receiptPreviewModal .modal-footer > .btn {
            width: 100%;
            margin: 0;
            padding: .6rem .4rem !important;
        }
    }
</style>

<script>
(function () {
    const modalEl  = document.getElementById('receiptPreviewModal');
    const frame    = document.getElementById('receiptFrame');
    const btnHalf  = document.getElementById('rcptLayoutHalf');
    const btnDup   = document.getElementById('rcptLayoutDup');
    const btnPrint = document.getElementById('rcptPrintBtn');
    let current = { type: 'payment', id: 0, layout: 'half' };

    function loadReceipt() {
        frame.style.height = '320px';
        frame.src = '../receipt.php?type=' + encodeURIComponent(current.type) +
                    '&id=' + encodeURIComponent(current.id) +
                    '&layout=' + current.layout + '&embed=1';
        btnHalf.classList.toggle('active', current.layout === 'half');
        btnDup.classList.toggle('active', current.layout === 'duplicate');
    }

    // Fit the iframe to the receipt's height once it finishes loading
    frame.addEventListener('load', function () {
        try {
            const doc = frame.contentDocument;
            if (doc && doc.documentElement && frame.getAttribute('src') !== 'about:blank') {
                frame.style.height = doc.documentElement.scrollHeight + 'px';
            }
        } catch (e) { /* ignore */ }
    });

    window.openReceipt = function (type, id) {
        current = { type: type, id: id, layout: 'half' };
        loadReceipt();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    btnHalf.addEventListener('click', function () { current.layout = 'half'; loadReceipt(); });
    btnDup.addEventListener('click',  function () { current.layout = 'duplicate'; loadReceipt(); });

    // Print only the receipt (inside the iframe), not the whole page
    btnPrint.addEventListener('click', function () {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    });

    // Clear the frame when closed so the next receipt doesn't flash the old one
    modalEl.addEventListener('hidden.bs.modal', function () { frame.src = 'about:blank'; });
})();
</script>
