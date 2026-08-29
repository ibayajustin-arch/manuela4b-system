<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
    exit; 
}

$query = "SELECT payments.*, homeowners.full_name AS homeowner_name 
          FROM payments 
          LEFT JOIN homeowners ON payments.homeowner_id = homeowners.id 
          ORDER BY payments.id DESC"; 

$result = mysqli_query($conn, $query);

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        
        <div class="page-header">
            <h1>Payment Records</h1>
            <a href="record_payment.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-2"></i> Record Manual Payment
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive border-0 shadow-none p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Receipt No.</th>
                                <th>Homeowner</th>
                                <th>Date Paid</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($result && mysqli_num_rows($result) > 0) {
                                while ($row = mysqli_fetch_assoc($result)) { 
                                    
                                    $isPaid = !empty($row['payment_date']) && $row['payment_date'] !== '0000-00-00';
                                    
                                    $paymentStatus = $isPaid ? 'Paid' : 'Pending';
                                    $formattedDate = $isPaid ? date("M d, Y", strtotime($row['payment_date'])) : "-";
                                    $displayName = !empty($row['homeowner_name']) ? $row['homeowner_name'] : 'Homeowner #' . $row['homeowner_id'];
                                    $paymentMethod = !empty($row['payment_method']) ? $row['payment_method'] : 'Manual / Cash';
                            ?>
                                <tr>
                                    <td class="fw-bold">
                                        <?php echo !empty($row['receipt_no']) ? htmlspecialchars($row['receipt_no']) : '-'; ?>
                                    </td>
                                    
                                    <td class="fw-medium"><?php echo htmlspecialchars($displayName); ?></td>
                                    
                                    <td class="text-muted"><?php echo $formattedDate; ?></td>
                                    
                                    <td class="text-muted"><?php echo htmlspecialchars($row['period_covered']); ?></td>
                                    <td class="fw-bold">₱ <?php echo number_format($row['amount_paid'], 2); ?></td>
                                    
                                    <td>
                                        <?php if($paymentStatus == 'Paid'): ?>
                                            <span class="badge badge-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <?php if($paymentStatus == 'Paid'): ?>
                                            <button type="button" 
                                                    class="btn btn-sm" 
                                                    style="background-color: var(--primary-soft); color: var(--primary-color);"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#receiptModal"
                                                    onclick="viewReceipt('<?php echo htmlspecialchars($row['receipt_no'] ?? '-'); ?>', '<?php echo htmlspecialchars(addslashes($displayName)); ?>', '<?php echo $formattedDate; ?>', '<?php echo htmlspecialchars(addslashes($row['period_covered'])); ?>', '<?php echo $row['amount_paid']; ?>', '<?php echo htmlspecialchars(addslashes($paymentMethod)); ?>')">
                                                <i class="bi bi-eye me-1"></i> View Receipt
                                            </button>
                                        <?php else: ?>
                                            <button type="button" 
                                                    class="btn btn-sm"
                                                    style="background-color: var(--warning-soft); color: var(--warning-color);"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#reminderModal"
                                                    onclick="openReminderModal('<?php echo $row['id']; ?>', '<?php echo htmlspecialchars(addslashes($displayName)); ?>', '<?php echo htmlspecialchars(addslashes($row['period_covered'])); ?>', '<?php echo $row['amount_paid']; ?>')">
                                                <i class="bi bi-envelope-paper-fill me-1"></i> Send Reminder
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php 
                                } 
                            } else {
                                echo "<tr><td colspan='7' class='table-empty-state'>No records found.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal 1: Payment Receipt (Landscape) -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0" style="background-color: #f8f9fa;">
      <div class="modal-header border-0 pb-0 justify-content-center position-relative">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 pt-2">
        
        <!-- Styled Receipt Card (Landscape Layout) -->
        <div class="bg-white p-4 p-md-5 rounded-3 shadow-sm border-start" style="border-left-width: 6px !important; border-left-color: var(--primary-color) !important;">
            
            <!-- Header Area -->
            <div class="row mb-4 align-items-center border-bottom pb-4">
                <div class="col-sm-7 d-flex align-items-center gap-3">
                    <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                        <i class="bi bi-buildings-fill text-white fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark">Manuela 4B MIS</h4>
                        <p class="text-muted small mb-0 text-uppercase tracking-wide">Official Payment Receipt</p>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                    <span class="text-muted small d-block mb-1">Receipt No.</span>
                    <span id="modalReceiptNo" class="fw-bold text-dark fs-5"></span>
                </div>
            </div>

            <!-- Details Area -->
            <div class="row mb-4">
                <div class="col-sm-7">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Received From</span>
                        <span id="modalHomeownerName" class="fw-bold text-dark d-block fs-5"></span>
                    </div>
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Payment For</span>
                        <span id="modalDescription" class="fw-semibold text-dark d-block fs-6"></span>
                    </div>
                    <div>
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Payment Method</span>
                        <span id="modalPaymentMethod" class="fw-semibold text-dark d-block fs-6"></span>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-4 mt-sm-0">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Date Paid</span>
                        <span id="modalDatePaid" class="fw-semibold text-dark d-block fs-6"></span>
                    </div>
                    <div>
                        <span class="text-muted small d-block mb-2 text-uppercase fw-semibold">Status</span>
                        <span class="badge badge-success px-3 py-2 fs-6">Paid</span>
                    </div>
                </div>
            </div>

            <!-- Total Amount Area -->
            <div class="rounded-3 p-4 d-flex justify-content-between align-items-center mt-2" style="background-color: var(--bg-color);">
                <span class="text-muted fw-bold text-uppercase" style="font-size: 0.9rem; letter-spacing: 0.5px;">Total Amount Paid</span>
                <span class="fs-2 fw-bold text-primary mb-0">₱ <span id="modalAmount"></span></span>
            </div>

        </div>

      </div>
      <div class="modal-footer border-0 pt-0 pb-4 px-4">
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill me-auto" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary px-4 rounded-pill" onclick="window.print()">
            <i class="bi bi-printer me-2"></i> Print Receipt
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal 2: Send Reminder (Matches Receipt Size) -->
<div class="modal fade" id="reminderModal" tabindex="-1" aria-labelledby="reminderModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0" style="background-color: #f8f9fa;">
      <div class="modal-header border-0 pb-0 justify-content-center position-relative">
        <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 pt-2">
        
        <!-- Styled Reminder Card -->
        <div class="bg-white p-4 p-md-5 rounded-3 shadow-sm border-start" style="border-left-width: 6px !important; border-left-color: var(--warning-color) !important;">
            
            <!-- Header Area -->
            <div class="row mb-4 align-items-center border-bottom pb-4">
                <div class="col-sm-7 d-flex align-items-center gap-3">
                    <div class="bg-warning rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 56px;">
                        <i class="bi bi-envelope-paper-fill text-white fs-3"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark">Payment Reminder</h4>
                        <p class="text-muted small mb-0 text-uppercase tracking-wide">Email Notification</p>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-3 mt-sm-0">
                    <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Status</span>
                    <span class="badge badge-warning px-3 py-2 fs-6">Pending / Overdue</span>
                </div>
            </div>

            <!-- Details Area -->
            <div class="row mb-4">
                <div class="col-sm-7">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Send To</span>
                        <span id="remHomeownerName" class="fw-bold text-dark d-block fs-5"></span>
                    </div>
                </div>
                <div class="col-sm-5 text-sm-end mt-4 mt-sm-0">
                    <div class="mb-4">
                        <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Payment For</span>
                        <span id="remDescription" class="fw-semibold text-dark d-block fs-6"></span>
                    </div>
                </div>
            </div>

            <!-- Total Amount Area -->
            <div class="rounded-3 p-4 d-flex justify-content-between align-items-center mt-2" style="background-color: var(--bg-color);">
                <span class="text-muted fw-bold text-uppercase" style="font-size: 0.9rem; letter-spacing: 0.5px;">Amount Due</span>
                <span class="fs-2 fw-bold text-warning mb-0">₱ <span id="remAmount"></span></span>
            </div>

            <!-- Information Note -->
            <div class="mt-4 pt-2 text-center">
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle-fill text-warning me-1"></i> 
                    Magpapadala ang system ng <b>official email reminder</b> sa homeowner na ito.
                </p>
            </div>

        </div>

      </div>
      <div class="modal-footer border-0 pt-0 pb-4 px-4">
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill me-auto" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn px-4 rounded-pill fw-bold shadow-sm" style="background-color: var(--warning-color); color: #fff; border: none;" onclick="executeReminder()">
            <i class="bi bi-send-fill me-2"></i> Send Email
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function viewReceipt(receiptNo, homeownerName, datePaid, description, amount, paymentMethod) {
    document.getElementById('modalReceiptNo').innerText = receiptNo;
    document.getElementById('modalHomeownerName').innerText = homeownerName;
    document.getElementById('modalDatePaid').innerText = datePaid;
    document.getElementById('modalDescription').innerText = description;
    document.getElementById('modalPaymentMethod').innerText = paymentMethod;
    
    let formattedAmount = parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('modalAmount').innerText = formattedAmount;
}

let currentPaymentIdToRemind = null;

function openReminderModal(paymentId, homeownerName, description, amount) {
    currentPaymentIdToRemind = paymentId;
    
    document.getElementById('remHomeownerName').innerText = homeownerName;
    document.getElementById('remDescription').innerText = description;
    
    let formattedAmount = parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('remAmount').innerText = formattedAmount;
}

function executeReminder() {
    if (!currentPaymentIdToRemind) return;

    fetch('send_reminder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'payment_id=' + encodeURIComponent(currentPaymentIdToRemind)
    })
    .then(response => response.json())
    .then(data => {
        let reminderModal = bootstrap.Modal.getInstance(document.getElementById('reminderModal'));
        reminderModal.hide();

        if(data.status === 'success') {
            alert("Success: " + data.message);
        } else {
            alert("Error: " + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("An error occurred while sending the reminder. Please check the network or console.");
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>