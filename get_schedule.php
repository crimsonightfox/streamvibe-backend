<?php
// get_schedule.php
// GET /get_schedule.php?streamer_id=1&view=upcoming|past|all
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'db.php';

$streamer_id = intval($_GET['streamer_id'] ?? 1);
$view        = in_array($_GET['view'] ?? '', ['upcoming','past','all']) ? $_GET['view'] : 'all';

$where = "WHERE streamer_id = ?";
if ($view === 'upcoming') $where .= " AND scheduled_at >= NOW() AND status != 'cancelled'";
if ($view === 'past')     $where .= " AND (scheduled_at < NOW() OR status IN ('ended','cancelled'))";

try {
    $stmt = $pdo->prepare("
        SELECT * FROM scheduled_streams
        {$where}
        ORDER BY scheduled_at ASC
        LIMIT 50
    ");
    $stmt->execute([$streamer_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Auto-expire: mark past 'upcoming' streams as 'ended'
    $pdo->prepare("
        UPDATE scheduled_streams
        SET status = 'ended'
        WHERE streamer_id = ?
          AND status = 'upcoming'
          AND scheduled_at < DATE_SUB(NOW(), INTERVAL duration_min MINUTE)
    ")->execute([$streamer_id]);

    echo json_encode(['success' => true, 'schedule' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
