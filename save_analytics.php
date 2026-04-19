<?php
// save_analytics.php
// POST: stream_id, streamer_id, peak_viewers, avg_viewers, total_chat_messages,
//       total_new_followers, total_clips_made, duration_sec, avg_bitrate_kbps, avg_fps
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

require_once 'db.php';

$stream_id   = intval($_POST['stream_id']   ?? 0);
$streamer_id = intval($_POST['streamer_id'] ?? 1);

if (!$stream_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'stream_id required']);
    exit;
}

// Pull title/category from the streams table
$meta = $pdo->prepare("SELECT title, category FROM streams WHERE id = ?");
$meta->execute([$stream_id]);
$row = $meta->fetch(PDO::FETCH_ASSOC) ?? [];

try {
    $stmt = $pdo->prepare("
        INSERT INTO stream_analytics
            (stream_id, streamer_id, peak_viewers, avg_viewers, total_chat_messages,
             total_new_followers, total_clips_made, duration_sec, avg_bitrate_kbps,
             avg_fps, category, title)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            peak_viewers        = VALUES(peak_viewers),
            avg_viewers         = VALUES(avg_viewers),
            total_chat_messages = VALUES(total_chat_messages),
            total_new_followers = VALUES(total_new_followers),
            total_clips_made    = VALUES(total_clips_made),
            duration_sec        = VALUES(duration_sec),
            avg_bitrate_kbps    = VALUES(avg_bitrate_kbps),
            avg_fps             = VALUES(avg_fps),
            category            = VALUES(category),
            title               = VALUES(title)
    ");
    $stmt->execute([
        $stream_id, $streamer_id,
        intval($_POST['peak_viewers']          ?? 0),
        floatval($_POST['avg_viewers']         ?? 0),
        intval($_POST['total_chat_messages']   ?? 0),
        intval($_POST['total_new_followers']   ?? 0),
        intval($_POST['total_clips_made']      ?? 0),
        intval($_POST['duration_sec']          ?? 0),
        intval($_POST['avg_bitrate_kbps']      ?? 0),
        floatval($_POST['avg_fps']             ?? 0),
        $row['category'] ?? null,
        $row['title']    ?? null,
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
