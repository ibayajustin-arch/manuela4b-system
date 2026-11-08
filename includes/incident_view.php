<?php
/**
 * includes/incident_view.php
 * Compact incident details + photo thumbnails for the ADMIN feedback modal.
 *
 * Usage (admin/feedback.php):
 *   require_once __DIR__ . '/../includes/incident_view.php';
 *   $photosById = get_feedback_photos($conn, array_column($rows, 'id'));
 *   ...inside the modal body, above "Resident message":
 *   echo render_incident_admin_details($row, $photosById[(int)$row['id']] ?? []);
 *   ...once, before </body>:
 *   echo render_incident_assets();
 */

require_once __DIR__ . '/incident_helpers.php';

function incident_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function incident_photo_url(string $fileName): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    return $base . '/uploads/incidents/' . rawurlencode($fileName);
}

/**
 * Returns the compact incident block (empty string for non-incident feedback).
 * $fb must contain the incident columns, so the admin query needs
 * reference_no, incident_category, incident_date, incident_location, urgency
 * (SELECT * already includes them).
 */
function render_incident_admin_details(array $fb, array $photos): string
{
    if (($fb['category'] ?? '') !== 'incident') return '';

    $cats     = incident_categories();
    $catKey   = (string) ($fb['incident_category'] ?? '');
    $catLabel = $cats[$catKey] ?? ($catKey !== '' ? ucfirst($catKey) : 'Uncategorized');

    $urgency  = strtolower((string) ($fb['urgency'] ?? ''));
    $urgClass = ['low' => 'inc-urg-low', 'medium' => 'inc-urg-medium', 'high' => 'inc-urg-high'][$urgency] ?? '';

    $when = '';
    if (!empty($fb['incident_date'])) {
        $ts = strtotime($fb['incident_date']);
        $when = $ts ? date('M j, Y g:i A', $ts) : $fb['incident_date'];
    }

    ob_start(); ?>
    <div class="inc-compact">
        <div class="inc-chips">
            <?php if (!empty($fb['reference_no'])): ?>
                <span class="inc-chip inc-chip-ref"><?= incident_h($fb['reference_no']) ?></span>
            <?php endif; ?>
            <span class="inc-chip"><?= incident_h($catLabel) ?></span>
            <?php if ($urgency !== ''): ?>
                <span class="inc-chip <?= $urgClass ?>"><?= incident_h(ucfirst($urgency)) ?> urgency</span>
            <?php endif; ?>
        </div>

        <div class="inc-grid">
            <div>
                <span class="inc-label">When</span>
                <span class="inc-value"><?= $when !== '' ? incident_h($when) : '—' ?></span>
            </div>
            <div>
                <span class="inc-label">Where</span>
                <span class="inc-value"><?= incident_h($fb['incident_location'] ?? '—') ?></span>
            </div>
        </div>

        <?php if ($photos): ?>
            <div class="inc-label">Photos (<?= count($photos) ?>) · tap to enlarge</div>
            <div class="inc-photos">
                <?php foreach ($photos as $i => $p):
                    $url = incident_photo_url($p['file_name']); ?>
                    <button type="button" class="inc-thumb" data-full="<?= incident_h($url) ?>"
                            aria-label="View photo <?= $i + 1 ?>">
                        <img src="<?= incident_h($url) ?>" alt="Incident photo <?= $i + 1 ?>" loading="lazy">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="inc-label">No photos attached</div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * CSS + tap-to-enlarge viewer. Echo once per page.
 * Add class "modal-compact" to the admin feedback modal (<div class="modal fade modal-compact" ...>)
 * so the whole report + reply form fits on a phone screen without scrolling.
 */
function render_incident_assets(): string
{
    ob_start(); ?>
    <style>
        .inc-compact { margin-bottom: .75rem; }
        .inc-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .5rem; }
        .inc-chip {
            font-size: .78rem; font-weight: 600; line-height: 1;
            padding: .35rem .6rem; border-radius: 999px;
            background: #e8f1ee; color: #0f5c4c;
        }
        .inc-chip-ref { background: #f1f3f5; color: #343a40; }
        .inc-urg-low    { background: #d1f2e0; color: #146c43; }
        .inc-urg-medium { background: #fff3cd; color: #8a6100; }
        .inc-urg-high   { background: #f8d7da; color: #b02a37; }

        .inc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .25rem .75rem; margin-bottom: .5rem; }
        .inc-label { display: block; font-size: .72rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .03em; color: #6c757d; margin-bottom: .15rem; }
        .inc-value { display: block; font-size: .9rem; line-height: 1.3; word-break: break-word; }

        .inc-photos { display: flex; gap: .4rem; overflow-x: auto; padding-bottom: .15rem; }
        .inc-thumb { flex: 0 0 auto; width: 64px; height: 64px; padding: 0; border: 1px solid #cfe0da;
                     border-radius: 8px; overflow: hidden; background: #f1f3f5; cursor: zoom-in; }
        .inc-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* Fit the whole admin modal on one phone screen */
        .modal-compact .modal-header { padding: .75rem 1rem; }
        .modal-compact .modal-header .modal-title { font-size: 1.1rem; }
        .modal-compact .modal-header small,
        .modal-compact .modal-header .text-muted { font-size: .8rem; }
        .modal-compact .modal-body { padding: .75rem 1rem; }
        .modal-compact .modal-body h6,
        .modal-compact .modal-body .form-label { font-size: .8rem; margin-bottom: .25rem; }
        .modal-compact .modal-body .mb-3 { margin-bottom: .6rem !important; }
        .modal-compact .modal-body textarea { min-height: 0; }
        .modal-compact .modal-footer { padding: .5rem 1rem; }
        /* Long resident messages scroll inside their own box instead of pushing the form down */
        .modal-compact .feedback-message-box { max-height: 7.5em; overflow-y: auto; padding: .5rem .75rem; font-size: .92rem; }
        @media (max-width: 575.98px) {
            .modal-compact .modal-dialog { margin: .5rem; }
        }

        /* Full-size photo viewer */
        .inc-viewer { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.88);
                      display: none; align-items: center; justify-content: center; padding: 1rem; }
        .inc-viewer.open { display: flex; }
        .inc-viewer img { max-width: 100%; max-height: 100%; border-radius: 6px; }
        .inc-viewer-close { position: absolute; top: .75rem; right: .75rem; width: 40px; height: 40px;
                            border: 0; border-radius: 50%; background: rgba(255,255,255,.15);
                            color: #fff; font-size: 1.4rem; line-height: 1; }
    </style>
    <div class="inc-viewer" id="incViewer" role="dialog" aria-modal="true" aria-label="Incident photo">
        <button type="button" class="inc-viewer-close" aria-label="Close photo">&times;</button>
        <img alt="Incident photo">
    </div>
    <script>
    (function () {
        var viewer = document.getElementById('incViewer');
        if (!viewer) return;
        var img = viewer.querySelector('img');
        document.addEventListener('click', function (e) {
            var thumb = e.target.closest('.inc-thumb');
            if (thumb) {
                e.preventDefault();
                img.src = thumb.getAttribute('data-full');
                viewer.classList.add('open');
                return;
            }
            if (viewer.classList.contains('open') && viewer.contains(e.target)) {
                viewer.classList.remove('open');
                img.removeAttribute('src');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && viewer.classList.contains('open')) {
                e.stopPropagation(); // keep the Bootstrap modal open
                viewer.classList.remove('open');
                img.removeAttribute('src');
            }
        }, true);
    })();
    </script>
    <?php
    return ob_get_clean();
}