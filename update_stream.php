<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: https://streamvibe.free.nf");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

include 'db.php';

$stream_id   = $_POST['stream_id']   ?? '';
$title       = $_POST['title']       ?? '';
$category    = $_POST['category']    ?? '';
$description = $_POST['description'] ?? '';

if (!$stream_id) {
    echo json_encode(['success' => false, 'error' => 'stream_id is required']);
    exit;
}

if (!$title) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$stmt = $conn->prepare("UPDATE streams SET title = ?, category = ?, description = ? WHERE stream_id = ? AND status = 'live'");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Query prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("sssi", $title, $category, $description, $stream_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($affected >= 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Update failed or stream not live']);
}
?>
