<?php
/**
 * admin_users.php
 * GET  /admin_users.php?action=list&role=all|viewer|streamer|banned&search=&limit=50&offset=0
 * POST /admin_users.php  body: { action: 'ban'|'unban', username, role, reason?, duration? }
 * Deploy this alongside your existing backend files.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('ADMIN_TOKEN', 'REPLACE_WITH_YOUR_SECRET_ADMIN_TOKEN'); // same token as admin_stats.php

$token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '');
if ($token !== ADMIN_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

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
    echo json_encode(['success' => false, 'error' => 'DB failed']);
    exit;
}

// ── POST: ban / unban actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $action   = $body['action']   ?? '';
    $username = trim($body['username'] ?? '');
    $role     = $body['role']     ?? 'viewer'; // 'viewer' or 'streamer'
    $reason   = $body['reason']   ?? '';
    $duration = $body['duration'] ?? 'permanent'; // e.g. '24h', '7d', '30d', 'permanent'

    if (!$username || !in_array($action, ['ban', 'unban', 'delete'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid action or username']);
        exit;
    }

    $table = $role === 'streamer' ? 'streamer_db' : 'viewer_db';

    if ($action === 'ban') {
        // Compute expiry
        $expiresAt = null;
        if ($duration !== 'permanent') {
            $map = ['24h' => '+24 hours', '7d' => '+7 days', '30d' => '+30 days'];
            $expiresAt = isset($map[$duration]) ? date('Y-m-d H:i:s', strtotime($map[$duration])) : null;
        }

        // Log the ban
        $stmt = $conn->prepare("
            INSERT INTO bans (username, role, reason, duration, expires_at, banned_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE reason=VALUES(reason), duration=VALUES(duration),
                expires_at=VALUES(expires_at), banned_at=NOW(), lifted_at=NULL
        ");
        $stmt->bind_param("sssss", $username, $role, $reason, $duration, $expiresAt);
        $stmt->execute();
        $stmt->close();

        // Mark user as banned in their table (if column exists)
        $conn->query("UPDATE `$table` SET is_banned=1 WHERE UserName='".mysqli_real_escape_string($conn,$username)."'");

        echo json_encode(['success' => true, 'message' => "User $username banned"]);
    } elseif ($action === 'unban') {
        $stmt = $conn->prepare("UPDATE bans SET lifted_at=NOW() WHERE username=? AND lifted_at IS NULL");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();
        $conn->query("UPDATE `$table` SET is_banned=0 WHERE UserName='".mysqli_real_escape_string($conn,$username)."'");
        echo json_encode(['success' => true, 'message' => "User $username unbanned"]);
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE UserName=?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'message' => "User $username deleted"]);
    }

    $conn->close();
    exit;
}

// ── GET: list users ───────────────────────────────────────────────────────
$roleFilter = $_GET['role']   ?? 'all';
$search     = trim($_GET['search'] ?? '');
$limit      = min((int)($_GET['limit']  ?? 50), 100);
$offset     = (int)($_GET['offset'] ?? 0);

$users = [];

// Helper: build WHERE clause
$whereParts = [];
$searchLike = '';
if ($search) {
    $safe       = mysqli_real_escape_string($conn, $search);
    $searchLike = " AND (UserName LIKE '%$safe%' OR Email LIKE '%$safe%')";
}

// Viewers
if (in_array($roleFilter, ['all', 'viewer', 'banned'])) {
    $bannedJoin = $roleFilter === 'banned'
        ? " INNER JOIN bans b ON b.username=v.UserName AND b.role='viewer' AND b.lifted_at IS NULL"
        : "";
    $bannedJoin2 = $roleFilter === 'banned' ? "" : " AND NOT EXISTS (SELECT 1 FROM bans WHERE username=v.UserName AND role='viewer' AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()))";
    if ($roleFilter === 'all') $bannedJoin2 = '';

    $sql = "SELECT v.UserName, v.Email,
                COALESCE(cb.balance,0) AS coins,
                v.created_at,
                'viewer' AS role,
                IF(EXISTS(SELECT 1 FROM bans WHERE username=v.UserName AND role='viewer' AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())),'banned','active') AS status
            FROM viewer_db v
            $bannedJoin
            LEFT JOIN coin_balance cb ON cb.username=v.UserName AND cb.user_type='viewer'
            WHERE 1=1 $searchLike
            $bannedJoin2
            LIMIT $limit OFFSET $offset";
    $r = $conn->query($sql);
    if ($r) { while ($row = $r->fetch_assoc()) $users[] = $row; }
}

// Streamers
if (in_array($roleFilter, ['all', 'streamer'])) {
    $sql = "SELECT s.UserName, s.Email,
                COALESCE(cb.balance,0) AS coins,
                s.created_at,
                'streamer' AS role,
                IF(EXISTS(SELECT 1 FROM bans WHERE username=s.UserName AND role='streamer' AND lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())),'banned','active') AS status
            FROM streamer_db s
            LEFT JOIN coin_balance cb ON cb.username=s.UserName AND cb.user_type='streamer'
            WHERE 1=1 $searchLike
            LIMIT $limit OFFSET $offset";
    $r = $conn->query($sql);
    if ($r) { while ($row = $r->fetch_assoc()) $users[] = $row; }
}

// Active bans list
$bans = [];
$r = $conn->query("
    SELECT username, role, reason, duration, expires_at, banned_at
    FROM bans
    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY banned_at DESC
    LIMIT 50
");
if ($r) { while ($row = $r->fetch_assoc()) $bans[] = $row; }

$conn->close();
echo json_encode(['success' => true, 'users' => $users, 'bans' => $bans]);
