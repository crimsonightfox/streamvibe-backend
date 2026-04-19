<?php
// create_clip.php
// POST: stream_id, title, description, clip_start, clip_end, thumbnail_url, streamer_id
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

require_once 'db.php';

$stream_id     = intval($_POST['stream_id']     ?? 0);
$title         = trim($_POST['title']           ?? 'Untitled Clip');
$description   = trim($_POST['description']     ?? '');
$clip_start    = intval($_POST['clip_start']    ?? 0);
$clip_end      = intval($_POST['clip_end']      ?? 0);
$thumbnail_url = trim($_POST['thumbnail_url']   ?? '');
$streamer_id   = intval($_POST['streamer_id']   ?? 1);

if (!$stream_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'stream_id required']);
    exit;
}

$duration = max(0, $clip_end - $clip_start);

try {
    $stmt = $pdo->prepare("
        INSERT INTO clips (stream_id, streamer_id, title, description, thumbnail_url, duration_sec, clip_start, clip_end)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$stream_id, $streamer_id, $title, $description, $thumbnail_url, $duration, $clip_start, $clip_end]);
    $clip_id = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'clip_id' => $clip_id, 'duration_sec' => $duration]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
