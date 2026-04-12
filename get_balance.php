<?php
// get_balance.php — returns coin balance for a user
session_start();
include 'db.php';
header('Content-Type: application/json');

$username  = $_GET['username'] ?? $_SESSION['username'] ?? '';
$user_type = $_GET['user_type'] ?? 'viewer';

if (!$username) {
    echo json_encode(['success' => false, 'error' => 'No username']);
    exit;
}

$stmt = $conn->prepare("SELECT balance, total_earned, total_spent FROM coin_balance WHERE username = ? AND user_type = ?");
$stmt->bind_param("ss", $username, $user_type);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success'       => true,
        'balance'       => (int)$row['balance'],
        'total_earned'  => (int)$row['total_earned'],
        'total_spent'   => (int)$row['total_spent']
    ]);
} else {
    // No row yet — balance is 0
    echo json_encode(['success' => true, 'balance' => 0, 'total_earned' => 0, 'total_spent' => 0]);
}

$stmt->close();
$conn->close();
?>