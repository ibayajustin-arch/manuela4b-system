<?php 
$basePath = defined('BASE_PATH') ? BASE_PATH : '';
$current_page = strtolower(basename($_SERVER['PHP_SELF']));
$sidebarUserName = (string) ($_SESSION['full_name'] ?? 'User');
$sidebarPhotoUrl = '';

// Keep the admin label current when the profile name is changed while logged in.
if (($_SESSION['role'] ?? '') === 'admin' && isset($conn) && $conn instanceof mysqli && !empty($_SESSION['user_id'])) {
    $nameStmt = $conn->prepare("SELECT full_name, profile_photo FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    if ($nameStmt) {
        $nameStmt->bind_param('i', $_SESSION['user_id']);
        if ($nameStmt->execute()) {
            $nameRow = $nameStmt->get_result()->fetch_assoc();
            if (!empty($nameRow['full_name'])) {
                $sidebarUserName = (string) $nameRow['full_name'];
                $_SESSION['full_name'] = $sidebarUserName;
            }
            $sidebarPhotoPath = (string) ($nameRow['profile_photo'] ?? '');
            $sidebarPhotoFile = __DIR__ . '/../' . $sidebarPhotoPath;
            if ($sidebarPhotoPath !== '' && is_file($sidebarPhotoFile)) {
                $sidebarPhotoUrl = $basePath . '/' . $sidebarPhotoPath . '?v=' . filemtime($sidebarPhotoFile);
            }
        }
        $nameStmt->close();
    }
}

// Include notifications helper
require_once __DIR__ . '/archive_helpers.php';
require_once __DIR__ . '/notifications.php';

// Live badge counters & notifications
$pendingResCount = 0;
$newFeedbackCount = 0;
$adminNotifs = [];
$unreadNotifsCount = 0;

if (isset($conn) && $conn instanceof mysqli) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        try {
            $qRes = $conn->query("SELECT COUNT(*) AS c FROM reservations r INNER JOIN homeowners h ON h.user_id = r.user_id AND h.archived_at IS NULL WHERE r.status = 'pending' AND r.archived_at IS NULL");
            if ($qRes && $r = $qRes->fetch_assoc()) {
                $pendingResCount = (int)$r['c'];
            }

            $qFb = $conn->query("SELECT COUNT(*) AS c FROM resident_feedback WHERE status = 'new' AND archived_at IS NULL");
            if ($qFb && $r = $qFb->fetch_assoc()) {
                $newFeedbackCount = (int)$r['c'];
            }

            // Retrieve unified notifications
            $notifData = get_admin_notifications($conn, 25);
            $adminNotifs = $notifData['items'] ?? [];
            $unreadNotifsCount = $notifData['unread_count'] ?? 0;
        } catch (Exception $e) {
            // Silently ignore if query fails
        }
    } elseif (in_array(($_SESSION['role'] ?? ''), ['homeowner', 'resident'], true)) {
        try {
            foreach (['announcements', 'reservations', 'payments', 'resident_feedback'] as $archiveTable) {
                ensure_archive_columns($conn, $archiveTable);
            }
            $notifData = get_resident_notifications($conn, (int) ($_SESSION['user_id'] ?? 0), 25);
            $adminNotifs = $notifData['items'] ?? [];
            $unreadNotifsCount = $notifData['unread_count'] ?? 0;
        } catch (Throwable $e) {
            // Keep the rest of the resident page available if notifications are unavailable.
        }
    }
}
?>

<!-- Unified mobile app bar keeps navigation and notifications clear of page content. -->
<header class="mobile-topbar" aria-label="Mobile app navigation">
    <button type="button" id="sidebarOpen" class="sidebar-open-btn mobile-topbar-btn" aria-label="Open menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="mobile-topbar-title"><span class="mobile-topbar-brand">Manuela 4B</span></div>
    <div class="mobile-topbar-actions">
        <?php if (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'homeowner', 'resident'], true)): ?>
            <div class="admin-topbar-notif-wrap" id="adminTopNotifWrap">
                <button type="button" class="btn-topbar-bell mobile-topbar-btn" id="adminNotifBell" aria-label="Notifications" data-bs-toggle="offcanvas" data-bs-target="#adminNotifDrawer" aria-controls="adminNotifDrawer" title="Notifications (<?= $unreadNotifsCount ?> new)">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($unreadNotifsCount > 0): ?>
                        <span class="bell-unread-indicator"><?= $unreadNotifsCount > 9 ? '9+' : $unreadNotifsCount ?></span>
                    <?php endif; ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
</header>
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<aside class="sidebar d-flex flex-column" id="appSidebar">

    <!-- Brand / Logo + collapse button -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-content">
            <div class="sidebar-logo-box">
                <img src="<?= $basePath ?>/Assets/css/images/logo2.png" alt="Manuela 4B Logo">
            </div>
            <div class="sidebar-brand-text">
                <span class="brand-name">Manuela 4B</span>
                <span class="brand-tag">HOA Management</span>
            </div>
        </div>
        <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Collapse menu" title="Collapse menu">
            <i class="bi bi-chevron-double-left"></i>
        </button>
    </div>

    <!-- Navigation Links -->
    <div class="sidebar-nav">
        <div class="sidebar-section-label">
            <span>Navigation</span>
        </div>
        <ul class="nav flex-column">

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <!-- Admin Links -->
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/dashboard.php" data-sb-label="Dashboard" class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="bi bi-grid" aria-hidden="true"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <!-- Sections (Overview, Collections, ...) are tabs inside the analytics page -->
                    <a href="<?= $basePath ?>/admin/analytics.php" data-sb-label="Visual Data Analytics" class="nav-link <?= $current_page === 'analytics.php' ? 'active' : '' ?>" <?= $current_page === 'analytics.php' ? 'aria-current="page"' : '' ?>>
                        <i class="bi bi-bar-chart" aria-hidden="true"></i>
                        <span class="nav-text">Visual Data Analytics</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/reservations.php" data-sb-label="Reservations" class="nav-link <?= $current_page === 'reservations.php' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-check" aria-hidden="true"></i>
                        <span class="nav-text">Reservations</span>
                        <?php if ($pendingResCount > 0): ?>
                            <span class="sidebar-badge badge-warning" title="<?= $pendingResCount ?> pending reservation(s)">
                                <?= $pendingResCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/homeowners.php" data-sb-label="Homeowners" class="nav-link <?= in_array($current_page, ['homeowners.php', 'homeowner_create.php', 'homeowner_edit.php', 'homeowner_view.php', 'homeowner_activity.php'], true) ? 'active' : '' ?>">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <span class="nav-text">Homeowners</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/payments.php" data-sb-label="Payments" class="nav-link <?= in_array($current_page, ['payments.php', 'edit_payment.php'], true) ? 'active' : '' ?>">
                        <i class="bi bi-wallet2" aria-hidden="true"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/announcements.php" data-sb-label="Announcements" class="nav-link <?= $current_page === 'announcements.php' ? 'active' : '' ?>">
                        <i class="bi bi-megaphone" aria-hidden="true"></i>
                        <span class="nav-text">Announcements</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/feedback.php" data-sb-label="Resident Feedback" class="nav-link <?= $current_page === 'feedback.php' ? 'active' : '' ?>">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <span class="nav-text">Resident Feedback</span>
                        <?php if ($newFeedbackCount > 0): ?>
                            <span class="sidebar-badge badge-danger" title="<?= $newFeedbackCount ?> new feedback message(s)">
                                <?= $newFeedbackCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php else: ?>
                <!-- Resident Links -->
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/dashboard.php" data-sb-label="Dashboard" class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="bi bi-grid" aria-hidden="true"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/profile.php" data-sb-label="Profile Account" class="nav-link <?= $current_page === 'profile.php' ? 'active' : '' ?>">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <span class="nav-text">Profile Account</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/payments.php" data-sb-label="Payments" class="nav-link <?= $current_page === 'payments.php' ? 'active' : '' ?>">
                        <i class="bi bi-wallet2" aria-hidden="true"></i>
                        <span class="nav-text">Payments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/reservations.php" data-sb-label="Facility Reservation" class="nav-link <?= $current_page === 'reservations.php' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-check" aria-hidden="true"></i>
                        <span class="nav-text">Facility Reservation</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/feedback.php" data-sb-label="Suggestions &amp; Concerns" class="nav-link <?= $current_page === 'feedback.php' ? 'active' : '' ?>">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <span class="nav-text">Suggestions &amp; Concerns</span>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="<?= $basePath ?>/settings.php" data-sb-label="Settings" class="nav-link <?= $current_page === 'settings.php' ? 'active' : '' ?>">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                    <span class="nav-text">Settings</span>
                </a>
            </li>

        </ul>
    </div>

    <!-- Footer / User Profile & Logout -->
    <div class="sidebar-footer">
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="sidebar-user" data-sb-label="<?= htmlspecialchars($sidebarUserName) ?>">
                <div class="sidebar-user-avatar-wrap">
                    <?php if ($sidebarPhotoUrl !== ''): ?>
                        <div class="avatar-circle sm avatar-circle-photo">
                            <img src="<?= htmlspecialchars($sidebarPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        </div>
                    <?php else: ?>
                        <div class="avatar-circle sm">
                            <?= strtoupper(substr($sidebarUserName, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <span class="user-status-dot" title="Active"></span>
                </div>
                <div class="sidebar-user-text text-truncate">
                    <p class="sidebar-user-name mb-0"><?= htmlspecialchars($sidebarUserName) ?></p>
                    <span class="sidebar-role-pill"><?= htmlspecialchars(strtoupper($_SESSION['role'] ?? 'USER')) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <a href="<?= $basePath ?>/logout.php" data-sb-label="Logout" class="sidebar-logout-btn">
            <i class="bi bi-box-arrow-right"></i>
            <span class="nav-text">Log Out</span>
        </a>
    </div>

</aside>

<?php if (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'homeowner', 'resident'], true)): ?>
<nav class="mobile-bottom-nav <?= ($_SESSION['role'] ?? '') === 'admin' ? 'mobile-bottom-nav-admin' : 'mobile-bottom-nav-resident' ?>" aria-label="Main navigation">
    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a href="<?= $basePath ?>/admin/dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>" <?= $current_page === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-house-door" aria-hidden="true"></i><span>Home</span>
        </a>
        <a href="<?= $basePath ?>/admin/analytics.php" class="<?= $current_page === 'analytics.php' ? 'active' : '' ?>" <?= $current_page === 'analytics.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Analytics</span>
        </a>
        <a href="<?= $basePath ?>/admin/homeowners.php" class="<?= in_array($current_page, ['homeowners.php', 'homeowner_create.php', 'homeowner_edit.php', 'homeowner_view.php', 'homeowner_activity.php'], true) ? 'active' : '' ?>" <?= in_array($current_page, ['homeowners.php', 'homeowner_create.php', 'homeowner_edit.php', 'homeowner_view.php', 'homeowner_activity.php'], true) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-people" aria-hidden="true"></i><span>Residents</span>
        </a>
        <a href="<?= $basePath ?>/admin/reservations.php" class="<?= $current_page === 'reservations.php' ? 'active' : '' ?>" <?= $current_page === 'reservations.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-calendar2-check" aria-hidden="true"></i><span>Bookings</span>
        </a>
        <a href="<?= $basePath ?>/admin/payments.php" class="<?= in_array($current_page, ['payments.php', 'record_payment.php', 'view_receipt.php'], true) ? 'active' : '' ?>" <?= in_array($current_page, ['payments.php', 'record_payment.php', 'view_receipt.php'], true) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-wallet2" aria-hidden="true"></i><span>Payments</span>
        </a>
        <a href="<?= $basePath ?>/admin/announcements.php" class="<?= $current_page === 'announcements.php' ? 'active' : '' ?>" <?= $current_page === 'announcements.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-megaphone" aria-hidden="true"></i><span>Announcements</span>
        </a>
        <a href="<?= $basePath ?>/admin/feedback.php" class="<?= $current_page === 'feedback.php' ? 'active' : '' ?>" <?= $current_page === 'feedback.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-chat-dots" aria-hidden="true"></i><span>Feedback</span>
        </a>
        <a href="<?= $basePath ?>/settings.php" class="<?= $current_page === 'settings.php' ? 'active' : '' ?>" <?= $current_page === 'settings.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-gear" aria-hidden="true"></i><span>Settings</span>
        </a>
    <?php else: ?>
        <a href="<?= $basePath ?>/resident/dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>" <?= $current_page === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-grid-1x2" aria-hidden="true"></i><span>Dashboard</span>
        </a>
        <a href="<?= $basePath ?>/resident/payments.php" class="<?= in_array($current_page, ['payments.php', 'payment_archive.php'], true) ? 'active' : '' ?>" <?= in_array($current_page, ['payments.php', 'payment_archive.php'], true) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-wallet2" aria-hidden="true"></i><span>Payments</span>
        </a>
        <a href="<?= $basePath ?>/resident/reservations.php" class="<?= in_array($current_page, ['reservations.php', 'pay_reservation.php'], true) ? 'active' : '' ?>" <?= in_array($current_page, ['reservations.php', 'pay_reservation.php'], true) ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-calendar2-check" aria-hidden="true"></i><span class="mbn-label-sm">Reservations</span>
        </a>
        <a href="<?= $basePath ?>/resident/feedback.php" class="<?= $current_page === 'feedback.php' ? 'active' : '' ?>" <?= $current_page === 'feedback.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-chat-dots" aria-hidden="true"></i><span class="mbn-label-2line">Suggestions<br>&amp; Concerns</span>
        </a>
        <a href="<?= $basePath ?>/settings.php" class="<?= $current_page === 'settings.php' ? 'active' : '' ?>" <?= $current_page === 'settings.php' ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-gear" aria-hidden="true"></i><span>Settings</span>
        </a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php if (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'homeowner', 'resident'], true)): ?>
<!-- Notification Drawer -->
<div class="offcanvas offcanvas-end admin-notif-offcanvas" tabindex="-1" id="adminNotifDrawer" aria-labelledby="adminNotifDrawerLabel" data-bs-backdrop="false" data-bs-scroll="true">
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-2">
            <div class="notif-header-icon">
                <i class="bi bi-bell-fill"></i>
            </div>
            <div>
                <h5 class="offcanvas-title fw-bold" id="adminNotifDrawerLabel" style="font-size: 1.1rem; line-height: 1.2;">
                    Notifications
                </h5>
                <small class="notif-unread-summary" id="notifUnreadSummary">
                    <?= $unreadNotifsCount > 0 ? "{$unreadNotifsCount} new notification" . ($unreadNotifsCount > 1 ? 's' : '') : "All caught up" ?>
                </small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($unreadNotifsCount > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" id="btnMarkNotifsRead" title="Mark all as read">
                    <i class="bi bi-check2-all"></i> Mark All as Read
                </button>
            <?php endif; ?>
            <button type="button" class="btn-close notif-drawer-close" data-bs-dismiss="offcanvas" aria-label="Close notifications" title="Close notifications"></button>
        </div>
    </div>

    <!-- Filter Pills -->
    <?php
    $notifCounts = [
        'all' => count($adminNotifs),
        'payment' => 0,
        'reservation' => 0,
        'feedback' => 0,
        'other' => 0
    ];
    foreach ($adminNotifs as $n) {
        $t = $n['type'] ?? '';
        if (isset($notifCounts[$t])) {
            $notifCounts[$t]++;
        } elseif ($t !== '') {
            $notifCounts['other']++;
        }
    }
    ?>
    <div class="notif-filter-bar p-3 border-bottom d-flex gap-2 flex-wrap">
        <button type="button" class="notif-filter-pill active" data-filter="all">All (<?= $notifCounts['all'] ?>)</button>
        <button type="button" class="notif-filter-pill" data-filter="payment"><i class="bi bi-cash-coin me-1"></i> Payments (<?= $notifCounts['payment'] ?>)</button>
        <button type="button" class="notif-filter-pill" data-filter="reservation"><i class="bi bi-calendar-event me-1"></i> Reservations (<?= $notifCounts['reservation'] ?>)</button>
        <button type="button" class="notif-filter-pill" data-filter="feedback"><i class="bi bi-chat-left-heart me-1"></i> Feedback (<?= $notifCounts['feedback'] ?>)</button>
        <button type="button" class="notif-filter-pill" data-filter="other"><i class="bi bi-activity me-1"></i> Other activity (<?= $notifCounts['other'] ?>)</button>
    </div>

    <div class="offcanvas-body p-0">
        <div class="notif-list-container" id="notifListContainer">
            <?php if (empty($adminNotifs)): ?>
                <div class="text-center py-5 px-3">
                    <div class="notif-empty-icon mb-3">
                        <i class="bi bi-bell-slash notif-empty-icon-glyph" style="font-size: 2.5rem;"></i>
                    </div>
                    <h6 class="fw-bold notif-empty-title mb-1">No notifications yet</h6>
                    <p class="notif-empty-desc small mb-0"><?= ($_SESSION['role'] ?? '') === 'admin' ? 'No recent homeowner registrations or other admin activity to show.' : 'New announcements, payment updates, reservation updates, and feedback replies will appear here.' ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($adminNotifs as $notif): ?>
                    <a href="<?= htmlspecialchars($notif['link']) ?>" class="notif-card-item text-decoration-none <?= $notif['is_unread'] ? 'unread' : '' ?>" data-notif-type="<?= in_array($notif['type'] ?? '', ['payment', 'reservation', 'feedback'], true) ? htmlspecialchars($notif['type']) : 'other' ?>">
                        <div class="notif-avatar-box" style="background: <?= $notif['icon_bg'] ?>; color: <?= $notif['icon_color'] ?>;">
                            <i class="bi <?= $notif['icon'] ?>"></i>
                        </div>
                        <div class="notif-content-wrap flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="notif-category-tag notif-tag-<?= $notif['type'] ?>">
                                    <?= htmlspecialchars($notif['category_label']) ?>
                                </span>
                                <span class="notif-time-text"><?= htmlspecialchars($notif['time_ago']) ?></span>
                            </div>
                            <h6 class="notif-item-title mb-1"><?= htmlspecialchars($notif['title']) ?></h6>
                            <p class="notif-item-desc mb-0"><?= $notif['description'] ?></p>
                        </div>
                        <?php if ($notif['is_unread']): ?>
                            <div class="notif-unread-dot" title="New / Unread"></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="offcanvas-footer p-3 border-top text-center">
        <small class="notif-footer-text"><?= ($_SESSION['role'] ?? '') === 'admin' ? 'Recent resident activity and admin actions appear here automatically.' : 'Updates from your community appear here automatically.' ?></small>
    </div>
</div>

<style>
    #adminNotifDrawer.admin-notif-offcanvas {
        width: min(440px, calc(100vw - 24px)) !important;
        max-width: calc(100vw - 24px) !important;
    }
    #adminNotifDrawer .notif-drawer-close {
        flex: 0 0 auto;
        width: 1.1rem;
        height: 1.1rem;
        padding: .65rem;
        opacity: .8;
    }
    #adminNotifDrawer .notif-drawer-close:hover,
    #adminNotifDrawer .notif-drawer-close:focus-visible {
        opacity: 1;
    }
    @media (max-width: 575.98px) {
        #adminNotifDrawer.admin-notif-offcanvas {
            width: min(340px, calc(100vw - 24px)) !important;
            max-width: calc(100vw - 24px) !important;
        }
    }

    /* Resident bottom navbar: a bit bigger, labels never split mid-word */
    nav.mobile-bottom-nav.mobile-bottom-nav-resident {
        min-height: 66px !important;
        padding: 6px 6px calc(6px + env(safe-area-inset-bottom)) !important;
        gap: 2px !important;
    }
    nav.mobile-bottom-nav.mobile-bottom-nav-resident > a {
        flex: 1 1 0 !important;
        min-width: 0 !important;
        min-height: 54px !important;
        padding: 5px 2px !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 3px !important;
        text-align: center !important;
        line-height: 1.1 !important;
    }
    nav.mobile-bottom-nav.mobile-bottom-nav-resident > a > i {
        font-size: 22px !important;
        line-height: 1 !important;
    }
    nav.mobile-bottom-nav.mobile-bottom-nav-resident > a > span {
        font-size: 11px !important;
        letter-spacing: -.1px !important;
        max-width: 100% !important;
        white-space: nowrap !important;
        word-break: keep-all !important;
        overflow-wrap: normal !important;
        hyphens: none !important;
        overflow: visible !important;
        text-overflow: clip !important;
    }
    nav.mobile-bottom-nav.mobile-bottom-nav-resident > a > span.mbn-label-sm {
        font-size: 9.5px !important;
        letter-spacing: -.2px !important;
    }
    nav.mobile-bottom-nav.mobile-bottom-nav-resident > a > span.mbn-label-2line {
        font-size: 9.5px !important;
        letter-spacing: -.2px !important;
        line-height: 1.05 !important;
    }
    /* Keep page content clear of the navbar */
    @media (max-width: 991.98px) {
        body:has(.mobile-bottom-nav-resident) .main-content {
            padding-bottom: calc(96px + env(safe-area-inset-bottom)) !important;
        }
    }
</style>

<script>
(function () {
    var endpoint = '<?= $basePath ?>/includes/notifications_api.php';
    var busy = false;
    var filter = 'all';
    var labels = { all: 'All', payment: 'Payments', reservation: 'Reservations', feedback: 'Feedback', other: 'Other activity' };
    var icons = { payment: 'bi-cash-coin', reservation: 'bi-calendar-event', feedback: 'bi-chat-left-heart', other: 'bi-activity' };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function updateBell(count) {
        var bell = document.getElementById('adminNotifBell');
        if (!bell) return;
        bell.setAttribute('aria-label', count ? count + ' unread notifications' : 'Notifications');
        bell.title = count ? 'Notifications (' + count + ' new)' : 'Notifications (all caught up)';
        var badge = bell.querySelector('.bell-unread-indicator');
        if (count > 0) {
            if (!badge) {
                badge = el('span', 'bell-unread-indicator');
                bell.appendChild(badge);
            }
            badge.textContent = count > 9 ? '9+' : String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    function updateMarkButton(count) {
        var headerActions = document.querySelector('#adminNotifDrawer .offcanvas-header > .d-flex:last-child');
        if (!headerActions) return;
        var button = document.getElementById('btnMarkNotifsRead');
        if (!button && count > 0) {
            button = el('button', 'btn btn-sm btn-outline-secondary py-1 px-2');
            button.type = 'button';
            button.id = 'btnMarkNotifsRead';
            button.title = 'Mark all as read';
            button.appendChild(el('i', 'bi bi-check2-all'));
            button.appendChild(document.createTextNode(' Mark All as Read'));
            headerActions.insertBefore(button, headerActions.firstChild);
        }
        if (button) button.hidden = count < 1;
    }

    function updateFilters(items) {
        var bar = document.querySelector('.notif-filter-bar');
        if (!bar) return;
        var counts = { all: items.length, payment: 0, reservation: 0, feedback: 0, other: 0 };
        items.forEach(function (item) {
            var type = counts[item.type] !== undefined ? item.type : 'other';
            counts[type]++;
        });
        bar.querySelectorAll('.notif-filter-pill').forEach(function (button) {
            var type = button.getAttribute('data-filter') || 'all';
            var icon = icons[type] ? '<i class="bi ' + icons[type] + ' me-1"></i> ' : '';
            button.innerHTML = icon + labels[type] + ' (' + (counts[type] || 0) + ')';
            button.classList.toggle('active', type === filter);
        });
    }

    function renderItems(items) {
        var container = document.getElementById('notifListContainer');
        if (!container) return;
        container.replaceChildren();
        if (!items.length) {
            var empty = el('div', 'text-center py-5 px-3');
            empty.appendChild(el('i', 'bi bi-bell-slash notif-empty-icon-glyph mb-3'));
            empty.appendChild(el('h6', 'fw-bold notif-empty-title mb-1', 'No notifications yet'));
            empty.appendChild(el('p', 'notif-empty-desc small mb-0', 'Your recent system updates will appear here.'));
            container.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var type = labels[item.type] ? item.type : 'other';
            var card = el('a', 'notif-card-item text-decoration-none' + (item.is_unread ? ' unread' : ''));
            card.href = item.link || '#';
            card.setAttribute('data-notif-type', type);
            card.setAttribute('data-notif-id', item.id || '');
            var avatar = el('div', 'notif-avatar-box');
            avatar.style.backgroundColor = item.icon_bg;
            avatar.style.color = item.icon_color;
            avatar.appendChild(el('i', 'bi ' + String(item.icon || 'bi-bell').replace(/[^a-z0-9 -]/gi, '')));
            var body = el('div', 'notif-content-wrap flex-grow-1 min-w-0');
            var meta = el('div', 'd-flex justify-content-between align-items-center mb-1');
            meta.appendChild(el('span', 'notif-category-tag notif-tag-' + type, item.category_label || labels[type]));
            meta.appendChild(el('span', 'notif-time-text', item.time_ago || ''));
            body.appendChild(meta);
            body.appendChild(el('h6', 'notif-item-title mb-1', item.title || 'Notification'));
            body.appendChild(el('p', 'notif-item-desc mb-0', item.description || ''));
            card.appendChild(avatar);
            card.appendChild(body);
            if (item.is_unread) card.appendChild(el('div', 'notif-unread-dot'));
            if (filter !== 'all' && filter !== type) card.classList.add('d-none');
            container.appendChild(card);
        });
    }

    function applyFilter(nextFilter) {
        filter = nextFilter;
        document.querySelectorAll('.notif-filter-pill').forEach(function (button) {
            button.classList.toggle('active', (button.getAttribute('data-filter') || 'all') === filter);
        });
        document.querySelectorAll('.notif-card-item').forEach(function (card) {
            card.classList.toggle('d-none', filter !== 'all' && card.getAttribute('data-notif-type') !== filter);
        });
    }

    function poll() {
        if (busy || document.hidden || !document.getElementById('adminNotifBell')) return;
        busy = true;
        fetch(endpoint + '?action=poll&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('Notification update failed'); return response.json(); })
            .then(function (data) {
                if (!data.success) return;
                var count = Number(data.unread_count) || 0;
                var items = Array.isArray(data.items) ? data.items : [];
                updateBell(count);
                var summary = document.getElementById('notifUnreadSummary');
                if (summary) summary.textContent = count ? count + ' new notification' + (count === 1 ? '' : 's') : 'All caught up';
                updateMarkButton(count);
                updateFilters(items);
                renderItems(items);
                applyFilter(filter);
            })
            .catch(function () {})
            .finally(function () { busy = false; });
    }

    function markNotificationsRead() {
        updateBell(0);
        var summary = document.getElementById('notifUnreadSummary');
        if (summary) summary.textContent = 'All caught up';
        updateMarkButton(0);
        document.querySelectorAll('.notif-card-item.unread').forEach(function (card) {
            card.classList.remove('unread');
            var dot = card.querySelector('.notif-unread-dot');
            if (dot) dot.remove();
        });
        fetch(endpoint + '?action=mark_read', { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('Could not mark notifications as read'); return response.json(); })
            .then(function (data) { if (data.success) poll(); })
            .catch(function () { poll(); });
    }

    document.addEventListener('shown.bs.offcanvas', function (event) {
        if (event.target && event.target.id === 'adminNotifDrawer') markNotificationsRead();
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('#adminNotifBell')) {
            markNotificationsRead();
            return;
        }
        var filterButton = event.target.closest('.notif-filter-pill');
        if (filterButton) {
            event.preventDefault();
            applyFilter(filterButton.getAttribute('data-filter') || 'all');
            return;
        }
        var markButton = event.target.closest('#btnMarkNotifsRead');
        if (markButton) {
            markButton.disabled = true;
            markNotificationsRead();
        }
    });

    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    poll();
    window.setInterval(poll, 30000); // every 30s: keeps InfinityFree under its connection limits
})();
</script>
<?php endif; ?>