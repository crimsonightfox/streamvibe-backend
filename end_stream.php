<?php
include 'db.php';
header('Content-Type: application/json');
$stream_id = $_POST['stream_id'] ?? '';
if (!$stream_id) { echo json_encode(['success'=>false,'error'=>'No stream_id']); exit; }
$stmt = $conn->prepare("UPDATE streams SET status='ended' WHERE stream_id=?");
$stmt->bind_param("i", $stream_id);
$stmt->execute();
echo json_encode(['success'=>true]);
$conn->close();
?>