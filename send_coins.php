<?php
// send_coins.php — viewer sends coins to streamer during a stream
session_start();
include 'db.php';
header('Content-Type: application/json');

$sender    = trim($_POST['sender']    ?? $_SESSION['username'] ?? '');
$receiver  = trim($_POST['receiver']  ?? '');   // streamer username
$stream_id = trim($_POST['stream_id'] ?? '');
$coins     = (int)($_POST['coins']    ?? 0);
$message   = trim($_POST['message']   ?? '');
$color     = trim($_POST['color']     ?? '#f59e0b');

if (!$sender || !$receiver || !$stream_id || $coins <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if (strlen($message) > 200) $message = substr($message, 0, 200);

// ── 1. Check sender balance ───────────────────────────────────────────────
$stmt = $conn->prepare("SELECT balance FROM coin_balance WHERE username = ? AND user_type = 'viewer'");
$stmt->bind_param("s", $sender);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

$currentBalance = $row ? (int)$row['balance'] : 0;

if ($currentBalance < $coins) {
    echo json_encode(['success' => false, 'error' => 'Insufficient coins', 'balance' => $currentBalance]);
    exit;
}

// ── 2. Deduct from sender ─────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO coin_balance (username, user_type, balance, total_spent)
    VALUES (?, 'viewer', ?, ?)
    ON DUPLICATE KEY UPDATE
        balance     = balance - ?,
        total_spent = total_spent + ?
");
$stmt->bind_param("siiii", $sender, $coins, $coins, $coins, $coins);
$stmt->execute();
$stmt->close();

// ── 3. Credit receiver (streamer) ─────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO coin_balance (username, user_type, balance, total_earned)
    VALUES (?, 'streamer', ?, ?)
    ON DUPLICATE KEY UPDATE
        balance      = balance + ?,
        total_earned = total_earned + ?
");
$stmt->bind_param("siiii", $receiver, $coins, $coins, $coins, $coins);
$stmt->execute();
$stmt->close();

// ── 4. Log the transaction ────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO coin_transactions (stream_id, sender, receiver, coins, message)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("sssss", $stream_id, $sender, $receiver, $coins, $message);
$stmt->execute();
$stmt->close();

// ── 5. Post a coin message into stream_chat so everyone sees it ───────────
$coinMsg   = "🪙 sent {$coins} coins" . ($message ? " — \"{$message}\"" : '');
$from_user = 'coin';
$chatStmt  = $conn->prepare("
    INSERT INTO stream_chat (stream_id, user_name, message, from_user, color, sent_at)
    VALUES (?, ?, ?, 'coin', ?, NOW())
");
$chatStmt->bind_param("ssss", $stream_id, $sender, $coinMsg, $color);
$chatStmt->execute();
$chatStmt->close();

// ── 6. Return new balance ─────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT balance FROM coin_balance WHERE username = ? AND user_type = 'viewer'");
$stmt->bind_param("s", $sender);
$stmt->execute();
$result = $stmt->get_result();
$newRow = $result->fetch_assoc();
$stmt->close();

$conn->close();

echo json_encode([
    'success'     => true,
    'new_balance' => $newRow ? (int)$newRow['balance'] : 0,
    'coins_sent'  => $coins
]);
?>