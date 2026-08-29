<?php 
require_once '../config/init.php';
require_once '../config/db.php';

// Security Check: Only Admins allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Fetch dashboard statistics safely using MySQLi
try {
    $stats = [];
    
    $resHomeowners = $conn->query("SELECT COUNT(*) FROM homeowners");
    $stats['homeowners'] = $resHomeowners ? $resHomeowners->fetch_row()[0] : 0;

    $resReservations = $conn->query("SELECT COUNT(*) FROM reservations");
    $stats['reservations'] = $resReservations ? $resReservations->fetch_row()[0] : 0;

    $resPaymentsTotal = $conn->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'");
    $stats['payments_total'] = $resPaymentsTotal ? $resPaymentsTotal->fetch_row()[0] : 0;

    $resPendingPayments = $conn->query("SELECT COUNT(*) FROM payments WHERE status != 'paid'");
    $stats['pending_payments'] = $resPendingPayments ? $resPendingPayments->fetch_row()[0] : 0;

    $resChartLabels = json_encode(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun']);
    $resChartData = json_encode([12, 19, 15, 25, 22, 30]); 

    $resPaidCount = $conn->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'");
    $paidCount = $resPaidCount ? $resPaidCount->fetch_row()[0] : 0;

    $resUnpaidCount = $conn->query("SELECT COUNT(*) FROM payments WHERE status != 'paid'");
    $unpaidCount = $resUnpaidCount ? $resUnpaidCount->fetch_row()[0] : 0;
    
    $payLabels = json_encode(['Paid', 'Unpaid/Overdue']);
    $payData = json_encode([$paidCount, $unpaidCount]); 

} catch (Exception $e) {
    $stats = ['homeowners' => 0, 'reservations' => 0, 'payments_total' => 0, 'pending_payments' => 0];
    $resChartLabels = json_encode(['Jan', 'Feb', 'Mar']); 
    $resChartData = json_encode([0, 0, 0]);
    $payLabels = json_encode(['Paid', 'Pending']); 
    $payData = json_encode([0, 0]);
}

require_once '../includes/header.php'; 
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header mb-5">
            <div>
                <h1>Dashboard & Analytics</h1>
                <p class="mb-0">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>!</p>
            </div>
            <button onclick="window.print()" class="btn btn-primary d-inline-flex align-items-center gap-2 px-4">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>

        <!-- Stat Cards -->
        <div class="row mb-5 g-4">
            <div class="col-xl-3 col-sm-6">
                <a href="homeowners.php" class="text-decoration-none">
                    <div class="card hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div>
                                <p class="stat-label mb-1">Total Homeowners</p>
                                <p class="stat-value mb-0"><?= number_format($stats['homeowners']) ?></p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="reservations.php" class="text-decoration-none">
                    <div class="card hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background-color: var(--info-soft); color: var(--info-color);">
                                <i class="bi bi-calendar-check-fill"></i>
                            </div>
                            <div>
                                <p class="stat-label mb-1">Reservations</p>
                                <p class="stat-value mb-0"><?= number_format($stats['reservations']) ?></p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="payments.php" class="text-decoration-none">
                    <div class="card hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background-color: var(--success-soft); color: var(--success-color);">
                                <i class="bi bi-wallet2"></i>
                            </div>
                            <div>
                                <p class="stat-label mb-1">Revenue</p>
                                <p class="stat-value mb-0" style="color: var(--success-color);">₱ <?= number_format($stats['payments_total']) ?></p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="payments.php" class="text-decoration-none">
                    <div class="card hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background-color: var(--warning-soft); color: var(--warning-color);">
                                <i class="bi bi-exclamation-circle-fill"></i>
                            </div>
                            <div>
                                <p class="stat-label mb-1">Pending Dues</p>
                                <p class="stat-value mb-0" style="color: var(--warning-color);"><?= number_format($stats['pending_payments']) ?></p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card card-body">
                    <h5 class="mb-4 fw-bold" style="font-size: 1rem;">Monthly Dues Collection Trend</h5>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="collectionChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card card-body">
                    <h5 class="mb-4 fw-bold" style="font-size: 1rem;">Collection Rate Overview</h5>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="paymentsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    const ctxCollection = document.getElementById('collectionChart').getContext('2d');
    new Chart(ctxCollection, {
        type: 'bar',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [
                { 
                    label: 'Collected Dues (₱)', 
                    data: [45000, 48000, 42500, 39000, 51000, 55000], 
                    backgroundColor: '#4f46e5', 
                    borderRadius: 4,
                    barPercentage: 0.65
                },
                { 
                    label: 'Unpaid/Overdue (₱)', 
                    data: [2500, 1500, 5000, 4000, 1000, 500], 
                    backgroundColor: '#ef4444', 
                    borderRadius: 4,
                    barPercentage: 0.65
                }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: { grid: { borderDash: [3, 3], color: '#f1f5f9' }, beginAtZero: true }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 20 } }
            }
        }
    });

    const payLabels = <?= $payLabels ?>;
    const payData   = <?= $payData ?>;
    const ctx2 = document.getElementById('paymentsChart').getContext('2d');
    
    new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: payLabels,
            datasets: [{ 
                data: payData, 
                backgroundColor: ['#10b981', '#f59e0b'], 
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            cutout: '75%', 
            plugins: { 
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 20 } } 
            } 
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>