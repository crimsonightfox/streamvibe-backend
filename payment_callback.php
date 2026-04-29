<?php
// payment_callback.php — Captures the PayPal payment after user approves
// PayPal redirects to return_url with ?token=ORDER_ID&PayerID=xxx
// We then capture it server-side and credit the coins
session_start();
include 'db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('PAYPAL_CLIENT_ID', 'AZD_T8Q6SvdsVHhgrUfx4mrvcw-8bqrd0UlEAHySELinucR9Irn9hYOZG6p0dW7moC116OAwtFOnpiJf');
define('PAYPAL_SECRET',    'EC9MQBF5wHg26h7JIIexpHSLA6-I2k2Ck-izviA0-UhBSPMps4lLZ2MjOh5KS8k-5Ub1FE8sRj-GYKMY');
define('PAYPAL_BASE',      'https://api-m.sandbox.paypal.com');
define('MAX_WALLET',       999999);
define('FRONTEND_URL', (getenv('FRONTEND_URL') ?: 'https://streamvibe.free.nf') . '/Viewer_Dashboard.html?i=1');

// ── GET PARAMS ────────────────────────────────────────────────────────────────
// PayPal sends: token (=order ID), PayerID, and our custom params
$orderId   = $_GET['token']    ?? '';
$payerId   = $_GET['PayerID']  ?? '';
$username  = $_GET['username'] ?? '';
$coins     = intval($_GET['coins'] ?? 0);
$nearLimit = $_GET['near_limit'] ?? '0';

if (!$orderId || !$payerId || !$username || $coins <= 0) {
    redirect('failed', 'Invalid payment parameters.');
}

// ── CHECK THIS ORDER IN DB ────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, status, coins_bought FROM coin_purchases WHERE paypal_order_id=? AND username=? LIMIT 1");
$stmt->bind_param('ss', $orderId, $username);
$stmt->execute();
$res = $stmt->get_result();
$purchase = $res->fetch_assoc();
$stmt->close();

if (!$purchase) { redirect('failed', 'Order not found.'); }
if ($purchase['status'] === 'completed') {
    // Already processed — idempotent redirect back to success
    redirect('success', $purchase['coins_bought'], $nearLimit);
}
if ($purchase['status'] === 'failed' || $purchase['status'] === 'cancelled') {
    redirect('failed', 'This order was already cancelled or failed.');
}

// ── GET ACCESS TOKEN ──────────────────────────────────────────────────────────
function getPayPalToken() {
    $ch = curl_init(PAYPAL_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['access_token'] ?? null;
}

$token = getPayPalToken();
if (!$token) {
    markFailed($conn, $orderId);
    redirect('failed', 'Could not connect to PayPal.');
}

// ── CAPTURE THE ORDER ─────────────────────────────────────────────────────────
$ch = curl_init(PAYPAL_BASE . '/v2/checkout/orders/' . $orderId . '/capture');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_POSTFIELDS     => '{}',
    CURLOPT_SSL_VERIFYPEER => true,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$capture = json_decode($res, true);
$captureStatus = $capture['status'] ?? '';

if ($captureStatus !== 'COMPLETED') {
    markFailed($conn, $orderId);
    redirect('failed', 'Payment capture failed: ' . ($capture['message'] ?? 'Unknown error'));
}

// ── CREDIT COINS ─────────────────────────────────────────────────────────────
// Find user type
function findUserType($conn, $username) {
    $s = $conn->prepare("SELECT UserName FROM viewer_db WHERE UserName=? LIMIT 1");
    $s->bind_param('s', $username); $s->execute(); $s->store_result();
    if ($s->num_rows > 0) { $s->close(); return 'viewer'; }
    $s->close();
    return 'streamer';
}
$userType = findUserType($conn, $username);

// Upsert coin_balance — add coins
$stmt = $conn->prepare("
    INSERT INTO coin_balance (username, user_type, balance, total_earned, total_spent)
    VALUES (?, ?, ?, ?, 0)
    ON DUPLICATE KEY UPDATE
        balance = LEAST(balance + VALUES(balance), ?),
        total_earned = total_earned + VALUES(total_earned)
");
$stmt->bind_param('ssiii', $username, $userType, $coins, $coins, MAX_WALLET);
$stmt->execute();
$stmt->close();

// Fetch new balance
$stmt = $conn->prepare("SELECT balance FROM coin_balance WHERE username=? AND user_type=? LIMIT 1");
$stmt->bind_param('ss', $username, $userType);
$stmt->execute();
$res = $stmt->get_result();
$balRow = $res->fetch_assoc();
$stmt->close();
$newBalance = $balRow ? intval($balRow['balance']) : $coins;

// Mark purchase completed
$stmt = $conn->prepare("UPDATE coin_purchases SET status='completed', completed_at=NOW() WHERE paypal_order_id=?");
$stmt->bind_param('s', $orderId);
$stmt->execute();
$stmt->close();

$conn->close();

// ── REDIRECT BACK ─────────────────────────────────────────────────────────────
redirect('success', $coins, $nearLimit, $newBalance);

// ── HELPERS ───────────────────────────────────────────────────────────────────
function markFailed($conn, $orderId) {
    $s = $conn->prepare("UPDATE coin_purchases SET status='failed' WHERE paypal_order_id=?");
    $s->bind_param('s', $orderId);
    $s->execute();
    $s->close();
}

function redirect($result, $coinsOrMsg = 0, $nearLimit = '0', $balance = 0) {
    $base = FRONTEND_URL;
    if ($result === 'success') {
        $url = $base . '&coin_result=success&coins=' . intval($coinsOrMsg) . '&near_limit=' . $nearLimit . '&new_balance=' . intval($balance);
    } else {
        $url = $base . '&coin_result=failed&reason=' . urlencode(is_string($coinsOrMsg) ? $coinsOrMsg : 'Payment failed');
    }
    header('Location: ' . $url);
    exit;
}
?>
