<?php
// This file lives on RENDER - handles email check & password reset only
require_once 'db.php';
header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$email  = $_POST['email']  ?? '';
$action = $_POST['action'] ?? '';

if (!$email || !$action) { echo "fail: missing data"; exit; }

function findTable($conn, $email) {
    foreach (['viewer_db', 'streamer_db'] as $table) {
        $stmt = $conn->prepare("SELECT Email FROM $table WHERE Email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 1) return $table;
    }
    return false;
}

// ---------------- CHECK EMAIL EXISTS ----------------
if ($action === 'check_email') {
    $table = findTable($conn, $email);
    echo $table ? "success" : "fail: email not found";
    exit;
}

// ---------------- RESET PASSWORD ----------------
if ($action === 'reset_password') {
    $newPass = $_POST['new_password'] ?? '';
    if (!$newPass) { echo "fail: missing new password"; exit; }

    $table = findTable($conn, $email);
    if (!$table) { echo "fail: email not found"; exit; }

    $stmt = $conn->prepare("UPDATE $table SET Password = ?, otp = NULL, otp_expires = NULL WHERE Email = ?");
    $stmt->bind_param("ss", $newPass, $email);
    $stmt->execute();
    echo "success";
    exit;
}

echo "fail: invalid action";
