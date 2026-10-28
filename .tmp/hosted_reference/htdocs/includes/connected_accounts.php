<?php
// Include this inside the resident profile page (where $conn and the session are available),
// using: require_once '../includes/connected_accounts.php';

require_once __DIR__ . '/auth_helpers.php';

$__uid        = (int) ($_SESSION['user_id'] ?? 0);
$__csrf       = generateCsrfToken();
$__configured = (bool) oauth_provider('google');

// Flash message from oauth_start / oauth_callback / oauth_disconnect
$__flash = $_SESSION['oauth_flash'] ?? null;
unset($_SESSION['oauth_flash']);

// Is Google already connected?
$__google = null;
$stmt = $conn->prepare("SELECT email, display_name FROM user_identities WHERE user_id = ? AND provider = 'google' LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $__uid);
    $stmt->execute();
    $__google = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$__e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="card card-body mb-4">
    <h2 class="mb-1" style="font-size: 1rem;">Connected Accounts</h2>
    <p class="mb-3" style="font-size: 0.85rem; color: var(--text-muted);">
        Connect your Google account to log in faster next time.
    </p>

    <?php if ($__flash): ?>
        <div class="alert alert-<?= $__e($__flash['type']) ?> border-0 rounded-3 py-2">
            <?= $__e($__flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php
        // Connected -> disconnect form; otherwise -> connect form
        $__action = $__google ? BASE_PATH . '/oauth_disconnect.php' : BASE_PATH . '/oauth_start.php';
    ?>
    <form method="POST" action="<?= $__e($__action) ?>" style="border-bottom: 1px solid var(--border-color-soft);" class="pb-2">
        <input type="hidden" name="csrf_token" value="<?= $__e($__csrf) ?>">
        <input type="hidden" name="provider" value="google">
        <?php if (!$__google): ?><input type="hidden" name="mode" value="connect"><?php endif; ?>

        <div class="d-flex align-items-center justify-content-between py-2">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-google" style="font-size: 1.3rem; color: var(--primary-color);"></i>
                <div>
                    <div class="fw-semibold" style="color: var(--text-heading);">Google</div>
                    <div class="small" style="color: var(--text-main);">
                        <?php if ($__google): ?>
                            Connected<?= !empty($__google['email']) ? ' as ' . $__e($__google['email']) : (!empty($__google['display_name']) ? ' as ' . $__e($__google['display_name']) : '') ?>
                        <?php elseif ($__configured): ?>
                            Not connected
                        <?php else: ?>
                            Not available yet
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($__google): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="caToggle('ca-google-confirm')">Disconnect</button>
            <?php elseif ($__configured): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="caToggle('ca-google-confirm')">Connect</button>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Connect</button>
            <?php endif; ?>
        </div>

        <?php if ($__google || $__configured): ?>
        <div id="ca-google-confirm" class="d-none pb-2">
            <label class="small mb-1" for="ca-google-pw" style="color: var(--text-main);">
                Enter your password to <?= $__google ? 'disconnect' : 'continue' ?>:
            </label>
            <div class="password-visibility-field">
                <input type="text" id="ca-google-pw" name="password" class="form-control form-control-sm"
                       autocomplete="current-password" required>
                <button type="button" class="password-visibility-toggle" data-password-target="ca-google-pw" data-password-label="current password" aria-label="Hide current password" aria-pressed="true"><i class="bi bi-eye-slash-fill" aria-hidden="true"></i><span>Hide</span></button>
                <button type="submit" class="btn btn-sm <?= $__google ? 'btn-danger' : 'btn-success' ?>" style="white-space: nowrap;">
                    <?= $__google ? 'Disconnect' : 'Continue to Google' ?>
                </button>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
function caToggle(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('d-none');
    var input = el.querySelector('#ca-google-pw');
    if (input && !el.classList.contains('d-none')) input.focus();
}
</script>
