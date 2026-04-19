<?php
// update_clip.php
// POST: clip_id, title, description, is_public, streamer_id
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

require_once 'db.php';

$clip_id     = intval($_POST['clip_id']     ?? 0);
$streamer_id = intval($_POST['streamer_id'] ?? 1);
$action      = trim($_POST['action']        ?? 'update'); // 'update' | 'delete' | 'like'

if (!$clip_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'clip_id required']);
    exit;
}

try {
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM clips WHERE id = ? AND streamer_id = ?");
        $stmt->execute([$clip_id, $streamer_id]);
        echo json_encode(['success' => true, 'deleted' => $clip_id]);

    } elseif ($action === 'like') {
        $stmt = $pdo->prepare("UPDATE clips SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$clip_id]);
        $row  = $pdo->query("SELECT likes FROM clips WHERE id = $clip_id")->fetch();
        echo json_encode(['success' => true, 'likes' => $row['likes']]);

    } elseif ($action === 'view') {
        $stmt = $pdo->prepare("UPDATE clips SET views = views + 1 WHERE id = ?");
        $stmt->execute([$clip_id]);
        echo json_encode(['success' => true]);

    } else {
        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_public   = isset($_POST['is_public']) ? intval($_POST['is_public']) : 1;

        $stmt = $pdo->prepare("
            UPDATE clips SET title = ?, description = ?, is_public = ?
            WHERE id = ? AND streamer_id = ?
        ");
        $stmt->execute([$title, $description, $is_public, $clip_id, $streamer_id]);
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
