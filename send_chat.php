<?php
include 'db.php';
header('Content-Type: application/json');

$stream_id = $_POST['stream_id'] ?? '';
$user_name = trim($_POST['user_name'] ?? 'Viewer');
$message   = trim($_POST['message']   ?? '');
$from_user = $_POST['from_user']      ?? 'viewer';
$color     = $_POST['color']          ?? '#667eea';

if (!$stream_id || !$message) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO stream_chat (stream_id, user_name, message, from_user, color, sent_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Query prepare failed']);
    exit;
}

$stmt->bind_param("sssss", $stream_id, $user_name, $message, $from_user, $color);
$stmt->execute();
echo json_encode(['success' => true]);

$stmt->close();
$conn->close();
?>