<?php
/**
 * admin_stats.php
 * GET /admin_stats.php
 * Returns real platform-wide KPI data for the admin dashboard.
 * Deploy this alongside your existing backend files.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // tighten to your admin origin in production
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Simple token guard (set X-Admin-Token header from the dashboard) ──────
// Generate a strong random string and store it here + in your admin HTML.
define('ADMIN_TOKEN', 'REPLACE_WITH_YOUR_SECRET_ADMIN_TOKEN');

$token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
if ($token !== ADMIN_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── DB connection (same as your existing db.php pattern) ─────────────────
$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, __DIR__ . '/ca.pem', NULL, NULL);
mysqli_real_connect(
    $conn,
    'mysql-13f8e68-streamvibe.b.aivencloud.com',
    'avnadmin',
    'AVNS_-TbAYyJb4l9uqLCBW6w',
    'defaultdb',
    26120,
    NULL,
    MYSQLI_CLIENT_SSL
);

if (mysqli_connect_error()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . mysqli_connect_error()]);
    exit;
}

$data = [];

// ── 1. Total users (viewers + streamers) ─────────────────────────────────
$r = $conn->query("SELECT
    (SELECT COUNT(*) FROM viewer_db)   AS total_viewers,
    (SELECT COUNT(*) FROM streamer_db) AS total_streamers");
if ($r && $row = $r->fetch_assoc()) {
    $data['total_viewers']   = (int)$row['total_viewers'];
    $data['total_streamers'] = (int)$row['total_streamers'];
    $data['total_users']     = $data['total_viewers'] + $data['total_streamers'];
}

// New users this month
$r = $conn->query("SELECT
    (SELECT COUNT(*) FROM viewer_db   WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) +
    (SELECT COUNT(*) FROM streamer_db WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01'))
    AS new_this_month");
if ($r && $row = $r->fetch_assoc()) {
    $data['new_users_this_month'] = (int)$row['new_this_month'];
}

// ── 2. Live streams right now ─────────────────────────────────────────────
$r = $conn->query("
    SELECT stream_id, title, category, status, started_at, streamer_username
    FROM streams
    WHERE status = 'live'
    ORDER BY started_at DESC
    LIMIT 20
");
$liveStreams = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $liveStreams[] = $row;
    }
}
$data['live_streams']       = $liveStreams;
$data['live_streams_count'] = count($liveStreams);

// Total streams this month
$r = $conn->query("SELECT COUNT(*) AS cnt FROM streams WHERE started_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
if ($r && $row = $r->fetch_assoc()) {
    $data['streams_this_month'] = (int)$row['cnt'];
}

// ── 3. Viewer counts per live stream (from heartbeat table) ──────────────
$r = $conn->query("
    SELECT stream_id, COUNT(*) AS viewer_count
    FROM stream_viewers
    WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
    GROUP BY stream_id
");
$viewerCounts = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $viewerCounts[$row['stream_id']] = (int)$row['viewer_count'];
    }
}
// Attach viewer counts to live streams
foreach ($data['live_streams'] as &$s) {
    $s['viewers'] = $viewerCounts[$s['stream_id']] ?? 0;
}
unset($s);
// Total concurrent viewers right now
$data['total_concurrent_viewers'] = array_sum($viewerCounts);

// ── 4. Coin economy ───────────────────────────────────────────────────────
$r = $conn->query("SELECT
    COALESCE(SUM(balance), 0)      AS coins_in_circulation,
    COALESCE(SUM(total_earned), 0) AS coins_ever_earned,
    COALESCE(SUM(total_spent), 0)  AS coins_ever_spent
    FROM coin_balance");
if ($r && $row = $r->fetch_assoc()) {
    $data['coins_in_circulation'] = (int)$row['coins_in_circulation'];
    $data['coins_ever_earned']    = (int)$row['coins_ever_earned'];
    $data['coins_ever_spent']     = (int)$row['coins_ever_spent'];
}

// Coins transacted this month
$r = $conn->query("SELECT COALESCE(SUM(coins), 0) AS total
    FROM coin_transactions
    WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
if ($r && $row = $r->fetch_assoc()) {
    $data['coins_transacted_this_month'] = (int)$row['total'];
}

// Recent coin transactions
$r = $conn->query("
    SELECT sender, receiver, coins, message, created_at
    FROM coin_transactions
    ORDER BY created_at DESC
    LIMIT 10
");
$data['recent_coin_transactions'] = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $data['recent_coin_transactions'][] = $row;
    }
}

// Top coin earners (streamers by total_earned)
$r = $conn->query("
    SELECT username, balance, total_earned
    FROM coin_balance
    WHERE user_type = 'streamer'
    ORDER BY total_earned DESC
    LIMIT 8
");
$data['top_coin_earners'] = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $data['top_coin_earners'][] = $row;
    }
}

// ── 5. Payments / Revenue ─────────────────────────────────────────────────
$r = $conn->query("SELECT
    COALESCE(SUM(amount_php), 0)                                         AS total_revenue,
    COALESCE(SUM(CASE WHEN payment_method='gcash'  THEN amount_php END), 0) AS gcash_revenue,
    COALESCE(SUM(CASE WHEN payment_method='paypal' THEN amount_php END), 0) AS paypal_revenue,
    COUNT(CASE WHEN status='completed' THEN 1 END)                       AS completed_count,
    COUNT(CASE WHEN status='pending'   THEN 1 END)                       AS pending_count,
    COUNT(CASE WHEN status='failed'    THEN 1 END)                       AS failed_count
    FROM coin_purchases");
if ($r && $row = $r->fetch_assoc()) {
    $data['revenue'] = [
        'total_php'       => (float)$row['total_revenue'],
        'gcash_php'       => (float)$row['gcash_revenue'],
        'paypal_php'      => (float)$row['paypal_revenue'],
        'completed_count' => (int)$row['completed_count'],
        'pending_count'   => (int)$row['pending_count'],
        'failed_count'    => (int)$row['failed_count'],
    ];
}

// Recent payment log
$r = $conn->query("
    SELECT purchase_id, username, coins_bought, amount_php, payment_method, status, created_at
    FROM coin_purchases
    ORDER BY created_at DESC
    LIMIT 15
");
$data['recent_payments'] = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $data['recent_payments'][] = $row;
    }
}

// ── 6. Recent activity feed (sign-ups, coin buys, new streams) ────────────
// Last 10 new viewers
$r = $conn->query("SELECT UserName AS username, 'new_viewer' AS event_type, created_at FROM viewer_db ORDER BY created_at DESC LIMIT 5");
$activity = [];
if ($r) { while ($row = $r->fetch_assoc()) $activity[] = $row; }

// Last 5 coin purchases
$r = $conn->query("SELECT username, 'coin_purchase' AS event_type, coins_bought, amount_php, created_at FROM coin_purchases WHERE status='completed' ORDER BY created_at DESC LIMIT 5");
if ($r) { while ($row = $r->fetch_assoc()) $activity[] = $row; }

// Last 5 streams started
$r = $conn->query("SELECT streamer_username AS username, 'stream_started' AS event_type, title, category, started_at AS created_at FROM streams ORDER BY started_at DESC LIMIT 5");
if ($r) { while ($row = $r->fetch_assoc()) $activity[] = $row; }

// Sort all activity by created_at desc
usort($activity, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$data['activity_feed'] = array_slice($activity, 0, 15);

// ── 7. Stream category breakdown ─────────────────────────────────────────
$r = $conn->query("
    SELECT category, COUNT(*) AS stream_count
    FROM streams
    WHERE started_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
      AND category IS NOT NULL AND category != ''
    GROUP BY category
    ORDER BY stream_count DESC
    LIMIT 8
");
$data['category_breakdown'] = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $data['category_breakdown'][] = $row;
    }
}

// ── Done ──────────────────────────────────────────────────────────────────
$conn->close();

echo json_encode(['success' => true, 'data' => $data, 'generated_at' => date('c')]);
