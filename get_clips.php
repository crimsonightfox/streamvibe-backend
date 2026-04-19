<?php
// get_clips.php
// GET /get_clips.php?streamer_id=1&limit=20&offset=0
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'db.php'; // your existing DB connection file

$streamer_id = intval($_GET['streamer_id'] ?? 1);
$limit       = min(intval($_GET['limit']  ?? 20), 50);
$offset      = intval($_GET['offset'] ?? 0);
$sort        = in_array($_GET['sort'] ?? '', ['views','likes','created_at']) ? $_GET['sort'] : 'created_at';

try {
    $stmt = $pdo->prepare("
        SELECT c.*, s.title AS stream_title, s.category
        FROM clips c
        LEFT JOIN streams s ON s.id = c.stream_id
        WHERE c.streamer_id = ?
          AND c.is_public   = 1
        ORDER BY c.{$sort} DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$streamer_id, $limit, $offset]);
    $clips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE streamer_id = ? AND is_public = 1");
    $count->execute([$streamer_id]);
    $total = (int)$count->fetchColumn();

    echo json_encode(['success' => true, 'clips' => $clips, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
