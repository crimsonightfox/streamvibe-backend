<?php
include 'db.php';
header('Content-Type: text/plain');

$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name']  ?? '');
$username   = trim($_POST['username']   ?? '');
$email      = trim($_POST['email']      ?? '');
$password   = $_POST['password']        ?? '';
$role       = $_POST['role']            ?? 'viewer'; // 'viewer' or 'streamer'

// Basic validation
if (!$first_name || !$last_name || !$username || !$email || !$password) {
    echo 'missing_fields';
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo 'invalid_email';
    exit;
}

if (strlen($password) < 8) {
    echo 'password_too_short';
    exit;
}

// Determine which table to use
$table = ($role === 'streamer') ? 'streamer_db' : 'viewer_db';

// Check if email already exists (in both tables)
foreach (['viewer_db', 'streamer_db'] as $t) {
    $check = $conn->prepare("SELECT Email FROM `$t` WHERE Email = ? LIMIT 1");
    if ($check) {
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            echo 'email_exists';
            exit;
        }
        $check->close();
    }
}

// Check if username already exists (in both tables)
foreach (['viewer_db', 'streamer_db'] as $t) {
    $check = $conn->prepare("SELECT UserName FROM `$t` WHERE UserName = ? LIMIT 1");
    if ($check) {
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            echo 'username_exists';
            exit;
        }
        $check->close();
    }
}

// Insert into the correct table
$stmt = $conn->prepare("
    INSERT INTO `$table` (FirstName, LastName, UserName, Email, Password)
    VALUES (?, ?, ?, ?, ?)
");

if (!$stmt) {
    echo 'server_error';
    exit;
}

$stmt->bind_param("sssss", $first_name, $last_name, $username, $email, $password);

if ($stmt->execute()) {
    echo 'success';
} else {
    echo 'server_error';
}

$stmt->close();
$conn->close();
?>