<?php
header('Content-Type: text/plain');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// ---------------- DB CONNECTION ----------------
$conn = new mysqli(
"sql200.infinityfree.com",
"if0_40980290",
"md7vQo22wr",
"if0_40980290_StreamVibe_db"
);
if ($conn->connect_error) {
    echo "fail: db error";
    exit;
}

// ---------------- INPUTS ----------------
$email  = $_POST['email'] ?? '';
$action = $_POST['action'] ?? '';

if (!$email || !$action) {
    echo "fail: missing data";
    exit;
}

// ---------------- HELPER: FIND USER TABLE ----------------
function findUser($conn, $email) {
    $tables = ['viewer_db', 'streamer_db'];

    foreach ($tables as $table) {
        $stmt = $conn->prepare("SELECT Email FROM $table WHERE Email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            return $table;
        }
    }
    return false;
}

$table = findUser($conn, $email);
if (!$table) {
    echo "fail: email not found";
    exit;
}

// ---------------- HELPER: SEND OTP EMAIL ----------------
function sendOtpEmail($toEmail, $otp) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Gmail SMTP
        $mail->SMTPAuth = true;
        $mail->Username = 'otpsender.311@gmail.com'; // your email
        $mail->Password = 'urhd gqva nxeh ocjs';  // Gmail App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('otpsender.311@gmail.com', 'StreamVibe');
        $mail->addAddress($toEmail);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = 'StreamVibe OTP Verification';
        $mail->Body    = "Your verification code is <b>$otp</b>. It expires in 10 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return 'Mailer Error: ' . $mail->ErrorInfo;
    }
}

// ---------------- ACTION: SEND OTP ----------------
if ($action === 'send_otp') {

    $otp = random_int(100000, 999999); // 6-digit OTP
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 min expiry

    // Save OTP to DB
    $stmt = $conn->prepare("UPDATE $table SET otp = ?, otp_expires = ? WHERE Email = ?");
    $stmt->bind_param("sss", $otp, $expires, $email);
    $stmt->execute();

    // Send OTP email
    $result = sendOtpEmail($email, $otp);
    if ($result === true) {
        echo "success";
    } else {
        echo "fail: $result";
    }
    exit;
}

// ---------------- ACTION: VERIFY OTP ----------------
if ($action === 'verify_otp') {

   $otp = trim($_POST['otp'] ?? ''); // remove extra spaces

// get otp and otp_expires from DB
$stmt = $conn->prepare(
    "SELECT otp, otp_expires FROM $table WHERE Email = ?"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

// get current PHP time
$current_time = date('Y-m-d H:i:s');

var_dump($row, $current_time); // TEMP: see what DB has and current time

if ($row && $row['otp'] === $otp && strtotime($row['otp_expires']) > strtotime($current_time)) {
    echo "success";
} else {
    echo "fail: otp mismatch or expired";
}
exit;

}

// ---------------- ACTION: RESET PASSWORD ----------------
if ($action === 'reset_password') {

    $newPass = $_POST['new_password'] ?? '';

    if ($newPass === '') {
        echo "fail: missing new password";
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE $table
         SET Password = ?, otp = NULL, otp_expires = NULL
         WHERE Email = ?"
    );
    $stmt->bind_param("ss", $newPass, $email);
    $stmt->execute();

    echo "success";
    exit;
}
echo "fail: invalid action";
