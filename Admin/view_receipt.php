<?php
// view_receipt.php

// 1. SETUP DATABASE CONNECTION (Palitan ng totoong details mo)
$host = 'localhost';
$dbname = 'manuela4b_mis'; // Pangalan ng database mo
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

if (isset($_GET['receipt_no'])) {
    $receipt_no = $_GET['receipt_no'];

    $stmt = $pdo->prepare("SELECT * FROM payments WHERE receipt_no = :receipt_no");
    $stmt->execute(['receipt_no' => $receipt_no]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        die("Resibo hindi nahanap sa database.");
    }
} else {
    die("Walang receipt number na ibinigay.");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Receipt: <?php echo htmlspecialchars($receipt['receipt_no']); ?></title>
</head>
<body>
    <div style="border: 1px solid #000; padding: 20px; width: 400px; margin: 0 auto;">
        <h2 style="text-align: center;">Manuela 4B MIS</h2>
        <h3 style="text-align: center;">OFFICIAL RECEIPT</h3>
        <hr>
        <p><strong>Receipt No:</strong> <?php echo htmlspecialchars($receipt['receipt_no']); ?></p>
        <p><strong>Date Paid:</strong> <?php echo htmlspecialchars($receipt['date_paid']); ?></p>
        <p><strong>Homeowner:</strong> <?php echo htmlspecialchars($receipt['homeowner_name']); ?></p>
        <p><strong>Description:</strong> <?php echo htmlspecialchars($receipt['description']); ?></p>
        <hr>
        <p style="font-size: 20px;"><strong>Total Amount:</strong> P <?php echo number_format($receipt['amount'], 2); ?></p>
        
        <br>
        <!-- Print Button for convenience -->
        <button onclick="window.print()" style="width: 100%; padding: 10px;">Print Receipt</button>
        <br><br>
        <a href="payments.php">Bumalik sa Payments</a>
    </div>
</body>
</html>