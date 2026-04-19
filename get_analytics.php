<?php
// get_analytics.php
// GET /get_analytics.php?streamer_id=1&period=7|30|90|all
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once 'db.php';

$streamer_id = intval($_GET['streamer_id'] ?? 1);
$period      = intval($_GET['period']      ?? 30); // days

$date_filter = $period > 0
    ? "AND sa.recorded_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)"
    : '';

try {
    // ── Per-stream analytics rows ──────────────────────────────
    $stmt = $pdo->prepare("
        SELECT sa.*,
               s.started_at, s.ended_at
        FROM stream_analytics sa
        LEFT JOIN streams s ON s.id = sa.stream_id
        WHERE sa.streamer_id = ? {$date_filter}
        ORDER BY sa.recorded_at DESC
        LIMIT 30
    ");
    $stmt->execute([$streamer_id]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Aggregate summary ─────────────────────────────────────
    $sumStmt = $pdo->prepare("
        SELECT
            COUNT(*)                      AS total_streams,
            SUM(duration_sec)             AS total_duration_sec,
            SUM(total_chat_messages)      AS total_chat,
            SUM(total_new_followers)      AS total_followers,
            SUM(total_clips_made)         AS total_clips,
            SUM(revenue_usd)              AS total_revenue,
            MAX(peak_viewers)             AS all_time_peak,
            AVG(avg_viewers)              AS overall_avg_viewers,
            AVG(avg_fps)                  AS avg_fps
        FROM stream_analytics
        WHERE streamer_id = ? {$date_filter}
    ");
    $sumStmt->execute([$streamer_id]);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

    // ── Category breakdown ────────────────────────────────────
    $catStmt = $pdo->prepare("
        SELECT category,
               COUNT(*)          AS stream_count,
               SUM(duration_sec) AS total_sec,
               AVG(avg_viewers)  AS avg_viewers,
               SUM(total_new_followers) AS followers
        FROM stream_analytics
        WHERE streamer_id = ? {$date_filter}
          AND category IS NOT NULL
        GROUP BY category
        ORDER BY stream_count DESC
        LIMIT 10
    ");
    $catStmt->execute([$streamer_id]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Viewer timeline (last 7 streams merged) ───────────────
    $timelineStmt = $pdo->prepare("
        SELECT DATE(snapped_at) AS day, AVG(viewer_count) AS avg_viewers
        FROM viewer_timeline vt
        JOIN stream_analytics sa ON sa.stream_id = vt.stream_id
        WHERE sa.streamer_id = ? {$date_filter}
        GROUP BY DATE(snapped_at)
        ORDER BY day ASC
        LIMIT 30
    ");
    $timelineStmt->execute([$streamer_id]);
    $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Clips summary ─────────────────────────────────────────
    $clipStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_clips, SUM(views) AS total_views, SUM(likes) AS total_likes
        FROM clips
        WHERE streamer_id = ?
    ");
    $clipStmt->execute([$streamer_id]);
    $clipSummary = $clipStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'summary'      => $summary,
        'streams'      => $streams,
        'categories'   => $categories,
        'timeline'     => $timeline,
        'clip_summary' => $clipSummary,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
