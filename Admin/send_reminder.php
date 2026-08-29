<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';

require_once '../config/init.php';
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_POST['payment_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No payment ID provided.']);
    exit;
}

$payment_id = intval($_POST['payment_id']);

$query = "SELECT payments.amount_paid, payments.period_covered, homeowners.full_name, homeowners.email 
          FROM payments 
          LEFT JOIN homeowners ON payments.homeowner_id = homeowners.id 
          WHERE payments.id = $payment_id";

$result = mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    $data = mysqli_fetch_assoc($result);
    
    $email_address = $data['email'];
    $homeowner_name = $data['full_name'];
    $amount = number_format($data['amount_paid'], 2);
    $period = $data['period_covered'];

    if (empty($email_address)) {
        echo json_encode(['status' => 'error', 'message' => 'No email address saved for this homeowner.']);
        exit;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        $mail->Username   = 'ibayajustin@gmail.com'; 
        $mail->Password   = 'lrqg zfpd julp mrho';   
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Updated the sender email to match your authenticated Gmail account
        $mail->setFrom('ibayajustin@gmail.com', 'Manuela 4B MIS');
        $mail->addAddress($email_address, $homeowner_name);

        $mail->isHTML(true);
        $mail->Subject = "Overdue Payment Reminder: $period";
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                <h2>Payment Reminder</h2>
                <p>Hello <b>$homeowner_name</b>,</p>
                <p>This is a friendly reminder that your payment for <b>$period</b> amounting to <b style='color: #dc2626;'>P$amount</b> is currently overdue.</p>
                <p>Please settle this as soon as possible.</p>
                <br>
                <p>Thank you,<br><b>Manuela 4B Admin</b></p>
            </div>
        ";
        $mail->AltBody = "Hello $homeowner_name, your payment for $period amounting to P$amount is overdue. Please settle as soon as possible. Thank you, Manuela 4B Admin.";

        $mail->send();
        echo json_encode(['status' => 'success', 'message' => 'Email reminder sent successfully to ' . $email_address]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => "Message could not be sent. Mailer Error: {$mail->ErrorInfo}"]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Payment record not found.']);
}
?>