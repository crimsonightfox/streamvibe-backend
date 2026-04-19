<?php
// snap_viewers.php
// POST: stream_id, viewer_count
// Call this every minute while the stream is live (from JS setInterval)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

require_once 'db.php';

$stream_id    = intval($_POST['stream_id']    ?? 0);
$viewer_count = intval($_POST['viewer_count'] ?? 0);

if (!$stream_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'stream_id required']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO viewer_timeline (stream_id, viewer_count) VALUES (?, ?)");
    $stmt->execute([$stream_id, $viewer_count]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
