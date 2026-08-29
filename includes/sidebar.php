<?php 
$basePath = '/MANUELA'; 
$current_page = basename($_SERVER['PHP_SELF']);
?>

<aside class="sidebar d-flex flex-column shadow-sm">

    <!-- Brand / Logo -->
    <div class="sidebar-brand d-flex align-items-center gap-3">
        <img src="<?= $basePath ?>/Assets/css/images/logo2.png" alt="Manuela 4B" style="width: 36px; height: 36px; object-fit: contain; flex-shrink: 0;">
        <span class="text-white" style="font-size: 1.05rem; font-weight: 700; letter-spacing: -0.5px;">Manuela 4B MIS</span>
    </div>

    <!-- Navigation Links -->
    <div class="sidebar-nav">
        <p class="sidebar-section-label">Main Menu</p>
        <ul class="nav flex-column gap-1">

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <!-- Admin Links -->
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/dashboard.php" class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="bi bi-grid-1x2-fill"></i> Dashboard & Analytics
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/reservations.php" class="nav-link <?= $current_page == 'reservations.php' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-check-fill"></i> Reservations
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/homeowners.php" class="nav-link <?= ($current_page == 'homeowners.php' || $current_page == 'homeowner_create.php' || $current_page == 'homeowner_edit.php') ? 'active' : '' ?>">
                        <i class="bi bi-people-fill"></i> Homeowners
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/payments.php" class="nav-link <?= $current_page == 'payments.php' ? 'active' : '' ?>">
                        <i class="bi bi-credit-card-fill"></i> Payments
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/admin/announcements.php" class="nav-link <?= $current_page == 'announcements.php' ? 'active' : '' ?>">
                        <i class="bi bi-megaphone-fill"></i> Announcements
                    </a>
                </li>
            <?php else: ?>
                <!-- Resident Links -->
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/profile.php" class="nav-link <?= $current_page == 'profile.php' ? 'active' : '' ?>">
                        <i class="bi bi-person-circle"></i> Profile Account
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/payments.php" class="nav-link <?= $current_page == 'payments.php' ? 'active' : '' ?>">
                        <i class="bi bi-wallet2"></i> Online Payment
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= $basePath ?>/resident/reservations.php" class="nav-link <?= $current_page == 'reservations.php' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-plus"></i> Facility Reservation
                    </a>
                </li>
            <?php endif; ?>

        </ul>
    </div>

    <!-- Footer / User Profile -->
    <div class="sidebar-footer">
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="d-flex align-items-center gap-3 p-2 mb-2 rounded-3" style="background-color: rgba(255,255,255,0.04);">
                <div class="avatar-circle sm">
                    <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="text-truncate">
                    <p class="mb-0 text-white fw-semibold" style="font-size: 0.75rem;"><?= htmlspecialchars($_SESSION['full_name']) ?></p>
                    <p class="mb-0 text-capitalize" style="font-size: 0.65rem; color: var(--text-muted);"><?= htmlspecialchars($_SESSION['role']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <a href="<?= $basePath ?>/logout.php" class="nav-link d-flex align-items-center gap-3 w-100 mt-2" style="color: #f87171;">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>