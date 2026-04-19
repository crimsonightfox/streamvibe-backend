<?php
// manage_schedule.php
// POST: action=create|update|delete|cancel, plus fields
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

require_once 'db.php';

$action      = trim($_POST['action']      ?? 'create');
$streamer_id = intval($_POST['streamer_id'] ?? 1);

try {
    if ($action === 'delete' || $action === 'cancel') {
        $id     = intval($_POST['id'] ?? 0);
        $status = $action === 'cancel' ? 'cancelled' : null;
        if ($status) {
            $stmt = $pdo->prepare("UPDATE scheduled_streams SET status = ? WHERE id = ? AND streamer_id = ?");
            $stmt->execute([$status, $id, $streamer_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM scheduled_streams WHERE id = ? AND streamer_id = ?");
            $stmt->execute([$id, $streamer_id]);
        }
        echo json_encode(['success' => true]);

    } elseif ($action === 'update') {
        $id           = intval($_POST['id']           ?? 0);
        $title        = trim($_POST['title']          ?? '');
        $category     = trim($_POST['category']       ?? 'Just Chatting');
        $description  = trim($_POST['description']    ?? '');
        $scheduled_at = trim($_POST['scheduled_at']   ?? '');
        $duration_min = intval($_POST['duration_min'] ?? 60);
        $is_recurring = intval($_POST['is_recurring'] ?? 0);
        $recurrence   = trim($_POST['recurrence_rule'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE scheduled_streams
            SET title = ?, category = ?, description = ?, scheduled_at = ?,
                duration_min = ?, is_recurring = ?, recurrence_rule = ?
            WHERE id = ? AND streamer_id = ?
        ");
        $stmt->execute([$title, $category, $description, $scheduled_at,
                        $duration_min, $is_recurring, $recurrence, $id, $streamer_id]);
        echo json_encode(['success' => true]);

    } else { // create
        $title        = trim($_POST['title']          ?? 'Untitled Stream');
        $category     = trim($_POST['category']       ?? 'Just Chatting');
        $description  = trim($_POST['description']    ?? '');
        $scheduled_at = trim($_POST['scheduled_at']   ?? '');
        $duration_min = intval($_POST['duration_min'] ?? 60);
        $timezone     = trim($_POST['timezone']       ?? 'UTC');
        $is_recurring = intval($_POST['is_recurring'] ?? 0);
        $recurrence   = trim($_POST['recurrence_rule'] ?? '');

        if (!$title || !$scheduled_at) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'title and scheduled_at are required']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO scheduled_streams
                (streamer_id, title, category, description, scheduled_at, duration_min, timezone, is_recurring, recurrence_rule)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$streamer_id, $title, $category, $description, $scheduled_at,
                        $duration_min, $timezone, $is_recurring, $recurrence]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
