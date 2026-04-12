<?php
// payment_callback.php — called after GCash / PayPal payment
// This page is the redirect URL the user lands on after paying.
// It also handles PayMongo webhook POSTs for server-side verification.

session_start();
include 'db.php';

$method     = $_GET['method']      ?? '';
$purchaseId = (int)($_GET['purchase_id'] ?? 0);
$status     = $_GET['status']      ?? 'failed';
$isMock     = isset($_GET['mock']);

// ── PayMongo webhook verification (POST) ──────────────────────────────────
// When PayMongo sends a webhook, verify and credit coins automatically.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload   = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

    // TODO: verify $signature using your PayMongo webhook secret
    // For now, parse the event and credit coins if payment succeeded

    $event = json_decode($payload, true);
    if (isset($event['data']['attributes']['status']) && $event['data']['attributes']['status'] === 'paid') {
        $ref = $event['data']['id'] ?? '';
        // Find the matching pending purchase by amount/ref and credit
        // (You'd store the PayMongo link ID in coin_purchases.payment_ref)
        creditCoinsByRef($conn, $ref);
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

// ── GET redirect (user returns from payment page) ─────────────────────────
if (!$purchaseId) { redirect('error', 'Invalid purchase reference.'); }

// Fetch purchase
$stmt = $conn->prepare("SELECT * FROM coin_purchases WHERE purchase_id = ? AND status = 'pending'");
$stmt->bind_param("i", $purchaseId);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    // Already processed or not found
    redirect('already', 'This purchase has already been processed.');
}

if ($status === 'success') {
    // Credit the coins
    $username = $purchase['username'];
    $coins    = (int)$purchase['coins_bought'];

    // Update purchase record
    $stmt = $conn->prepare("UPDATE coin_purchases SET status='completed', completed_at=NOW() WHERE purchase_id=?");
    $stmt->bind_param("i", $purchaseId);
    $stmt->execute();
    $stmt->close();

    // Add to coin_balance
    $stmt = $conn->prepare("
        INSERT INTO coin_balance (username, user_type, balance, total_earned)
        VALUES (?, 'viewer', ?, ?)
        ON DUPLICATE KEY UPDATE
            balance      = balance + ?,
            total_earned = total_earned + ?
    ");
    $stmt->bind_param("siiii", $username, $coins, $coins, $coins, $coins);
    $stmt->execute();
    $stmt->close();

    $conn->close();
    redirect('success', $coins, $username);
} else {
    // Mark as failed
    $stmt = $conn->prepare("UPDATE coin_purchases SET status='failed' WHERE purchase_id=?");
    $stmt->bind_param("i", $purchaseId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    redirect('failed', 0);
}

function creditCoinsByRef($conn, $ref) {
    $stmt = $conn->prepare("SELECT * FROM coin_purchases WHERE payment_ref=? AND status='pending'");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return;
    $u = $row['username']; $c = (int)$row['coins_bought']; $id = (int)$row['purchase_id'];
    $conn->query("UPDATE coin_purchases SET status='completed', completed_at=NOW() WHERE purchase_id=$id");
    $ins = $conn->prepare("INSERT INTO coin_balance (username, user_type, balance, total_earned) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE balance=balance+?, total_earned=total_earned+?");
    $t = 'viewer';
    $ins->bind_param("ssiiii", $u, $t, $c, $c, $c, $c);
    $ins->execute(); $ins->close();
}

function redirect($result, $coins = 0, $username = '') {
    // Redirect back to viewer dashboard with a status param
    $base = 'Viewer_Dashboard.html';
    if ($result === 'success') {
        header("Location: {$base}?coin_result=success&coins={$coins}");
    } elseif ($result === 'failed') {
        header("Location: {$base}?coin_result=failed");
    } elseif ($result === 'already') {
        header("Location: {$base}?coin_result=already");
    } else {
        header("Location: {$base}?coin_result=error");
    }
    exit;
}
?>