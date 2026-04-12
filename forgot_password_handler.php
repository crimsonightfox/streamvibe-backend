<?php
require_once 'db.php';
require_once __DIR__ . '/vendor/autoload.php';  // PHPMailer via Composer

header('Content-Type: text/plain');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------- INPUTS ----------------
$email  = $_POST['email']  ?? '';
$action = $_POST['action'] ?? '';

if (!$email || !$action) {
    echo "fail: missing data";
    exit;
}

// ---------------- HELPER: FIND USER TABLE ----------------
function findUser($conn, $email) {
    foreach (['viewer_db', 'streamer_db'] as $table) {
        $stmt = $conn->prepare("SELECT Email FROM $table WHERE Email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 1) return $table;
    }
    return false;
}

$table = findUser($conn, $email);
if (!$table) { echo "fail: email not found"; exit; }

// ---------------- HELPER: SEND OTP EMAIL ----------------
function sendOtpEmail($toEmail, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'otpsender.311@gmail.com';
        $mail->Password   = 'urhd gqva nxeh ocjs';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('otpsender.311@gmail.com', 'StreamVibe');
        $mail->addAddress($toEmail);
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
    $otp     = random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 600);

    $stmt = $conn->prepare("UPDATE $table SET otp = ?, otp_expires = ? WHERE Email = ?");
    $stmt->bind_param("sss", $otp, $expires, $email);
    $stmt->execute();

    $result = sendOtpEmail($email, $otp);
    echo ($result === true) ? "success" : "fail: $result";
    exit;
}

// ---------------- ACTION: VERIFY OTP ----------------
if ($action === 'verify_otp') {
    $otp  = trim($_POST['otp'] ?? '');
    $stmt = $conn->prepare("SELECT otp, otp_expires FROM $table WHERE Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row && $row['otp'] === $otp && strtotime($row['otp_expires']) > time()) {
        echo "success";
    } else {
        echo "fail: otp mismatch or expired";
    }
    exit;
}

// ---------------- ACTION: RESET PASSWORD ----------------
if ($action === 'reset_password') {
    $newPass = $_POST['new_password'] ?? '';
    if (!$newPass) { echo "fail: missing new password"; exit; }

    $stmt = $conn->prepare("UPDATE $table SET Password = ?, otp = NULL, otp_expires = NULL WHERE Email = ?");
    $stmt->bind_param("ss", $newPass, $email);
    $stmt->execute();
    echo "success";
    exit;
}

echo "fail: invalid action";
