<?php
require_once 'db.php';
header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');

$email  = $_POST['email']  ?? '';
$action = $_POST['action'] ?? '';

if ($action === 'check_email') {
    $output = "email received: $email | ";
    foreach (['viewer_db', 'streamer_db'] as $table) {
        $stmt = $conn->prepare("SELECT Email FROM $table WHERE Email = ? LIMIT 1");
        if (!$stmt) { echo $output . "prepare failed on $table: " . $conn->error; exit; }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $rows = $stmt->get_result()->num_rows;
        $output .= "$table: $rows rows | ";
        if ($rows === 1) { echo $output . "success"; exit; }
    }
    echo $output . "fail: email not found";
    exit;
}

if ($action === 'reset_password') {
    $newPass = $_POST['new_password'] ?? '';
    if (!$newPass) { echo "fail: missing new password"; exit; }
    foreach (['viewer_db', 'streamer_db'] as $table) {
        $stmt = $conn->prepare("SELECT Email FROM $table WHERE Email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 1) {
            $stmt2 = $conn->prepare("UPDATE $table SET Password = ?, otp = NULL, otp_expires = NULL WHERE Email = ?");
            $stmt2->bind_param("ss", $newPass, $email);
            $stmt2->execute();
            echo "success";
            exit;
        }
    }
    echo "fail: email not found";
    exit;
}

echo "fail: invalid action";
