<?php
// buy_coins.php — Creates a PayPal order and returns the approval URL
session_start();
include 'db.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('PAYPAL_CLIENT_ID',  'AZD_T8Q6SvdsVHhgrUfx4mrvcw-8bqrd0UlEAHySELinucR9Irn9hYOZG6p0dW7moC116OAwtFOnpiJf');
define('PAYPAL_SECRET',     'EC9MQBF5wHg26h7JIIexpHSLA6-I2k2Ck-izviA0-UhBSPMps4lLZ2MjOh5KS8k-5Ub1FE8sRj-GYKMY');
define('PAYPAL_BASE',       'https://api-m.sandbox.paypal.com'); // sandbox
define('EXCHANGE_RATE',     1);       // ₱1 = 1 coin
define('PLATFORM_FEE_PCT',  0.05);    // 5% fee
define('MIN_COINS',         50);
define('MAX_COINS_PER_BUY', 5000);
define('MAX_WALLET',        999999);
define('DAILY_LIMIT',       60000);
define('COOLDOWN_MINUTES',  5);
define('RETURN_URL',        'https://streamvibe.free.nf/Viewer_Dashboard.html?i=1');
define('CANCEL_URL',        'https://streamvibe.free.nf/Viewer_Dashboard.html?i=1&coin_result=cancelled');

// ── INPUT ─────────────────────────────────────────────────────────────────────
$username  = trim($_POST['username'] ?? '');
$coins     = intval($_POST['coins']  ?? 0);

if (!$username) { echo json_encode(['success'=>false,'error'=>'Not logged in.']); exit; }
if ($coins < MIN_COINS) { echo json_encode(['success'=>false,'error'=>'Minimum purchase is '.MIN_COINS.' coins.']); exit; }
if ($coins > MAX_COINS_PER_BUY) { echo json_encode(['success'=>false,'error'=>'Maximum purchase per transaction is '.number_format(MAX_COINS_PER_BUY).' coins.']); exit; }

// ── FIND USER TABLE ───────────────────────────────────────────────────────────
function findUserTable($conn, $username) {
    foreach (['viewer_db','streamer_db'] as $t) {
        $s = $conn->prepare("SELECT UserName FROM `$t` WHERE UserName=? LIMIT 1");
        $s->bind_param('s', $username); $s->execute(); $s->store_result();
        if ($s->num_rows > 0) { $s->close(); return $t; }
        $s->close();
    }
    return null;
}
$userTable = findUserTable($conn, $username);
if (!$userTable) { echo json_encode(['success'=>false,'error'=>'User not found.']); exit; }

// ── CURRENT BALANCE ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT balance FROM coin_balance WHERE username=? AND user_type=? LIMIT 1");
$utype = ($userTable === 'streamer_db') ? 'streamer' : 'viewer';
$stmt->bind_param('ss', $username, $utype);
$stmt->execute();
$res = $stmt->get_result();
$balRow = $res->fetch_assoc();
$stmt->close();
$currentBalance = $balRow ? intval($balRow['balance']) : 0;

// ── WALLET CAP CHECK ──────────────────────────────────────────────────────────
if (($currentBalance + $coins) > MAX_WALLET) {
    $canBuy = MAX_WALLET - $currentBalance;
    if ($canBuy <= 0) {
        echo json_encode(['success'=>false,'error'=>'Your wallet is full! You cannot store more than 999,999 coins. Please spend some coins first.']);
    } else {
        echo json_encode(['success'=>false,'error'=>"This purchase would exceed your 999,999 coin wallet limit. You can only buy up to ".number_format($canBuy)." more coins right now."]);
    }
    exit;
}

// ── WALLET WARNING (900k+) — just a flag, not a block ────────────────────────
$nearLimit = ($currentBalance + $coins) > 900000;

// ── COOLDOWN CHECK ────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT created_at FROM coin_purchases WHERE username=? AND status='completed' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$lastPurchase = $res->fetch_assoc();
$stmt->close();
if ($lastPurchase) {
    $secondsAgo = time() - strtotime($lastPurchase['created_at']);
    $cooldownSecs = COOLDOWN_MINUTES * 60;
    if ($secondsAgo < $cooldownSecs) {
        $remaining = $cooldownSecs - $secondsAgo;
        $mins = floor($remaining / 60);
        $secs = $remaining % 60;
        echo json_encode(['success'=>false,'error'=>"Please wait {$mins}m {$secs}s before buying coins again."]);
        exit;
    }
}

// ── DAILY LIMIT CHECK ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COALESCE(SUM(coins_bought),0) AS total FROM coin_purchases WHERE username=? AND status='completed' AND DATE(created_at)=CURDATE()");
$stmt->bind_param('s', $username);
$stmt->execute();
$res = $stmt->get_result();
$dailyRow = $res->fetch_assoc();
$stmt->close();
$dailyTotal = intval($dailyRow['total'] ?? 0);
if (($dailyTotal + $coins) > DAILY_LIMIT) {
    $remaining = DAILY_LIMIT - $dailyTotal;
    if ($remaining <= 0) {
        echo json_encode(['success'=>false,'error'=>'You have reached your daily purchase limit of '.number_format(DAILY_LIMIT).' coins. Limit resets at midnight.']);
    } else {
        echo json_encode(['success'=>false,'error'=>"You can only buy ".number_format($remaining)." more coins today (daily limit: ".number_format(DAILY_LIMIT).")."]);
    }
    exit;
}

// ── CALCULATE AMOUNT ──────────────────────────────────────────────────────────
// ₱1 = 1 coin, PHP to USD conversion for PayPal (approx. 1 USD = 58 PHP)
$amountPHP  = $coins / EXCHANGE_RATE;
$feeAmount  = round($amountPHP * PLATFORM_FEE_PCT, 2);
$amountUSD  = round($amountPHP / 58, 2); // PayPal requires USD for sandbox
if ($amountUSD < 0.01) $amountUSD = 0.01;

// ── GET PAYPAL ACCESS TOKEN ───────────────────────────────────────────────────
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
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return null;
    $data = json_decode($res, true);
    return $data['access_token'] ?? null;
}

$token = getPayPalToken();
if (!$token) { echo json_encode(['success'=>false,'error'=>'Could not connect to PayPal. Try again.']); exit; }

// ── CREATE PAYPAL ORDER ───────────────────────────────────────────────────────
$orderPayload = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id'  => $username . '_' . time(),
        'description'   => "StreamVibe: {$coins} coins for @{$username}",
        'amount'        => [
            'currency_code' => 'USD',
            'value'         => number_format($amountUSD, 2, '.', ''),
        ],
    ]],
    'application_context' => [
        'brand_name'          => 'StreamVibe',
        'locale'              => 'en-PH',
        'landing_page'        => 'LOGIN',
        'user_action'         => 'PAY_NOW',
        'return_url'          => RETURN_URL . '&coin_result=success&coins=' . $coins . '&username=' . urlencode($username) . '&near_limit=' . ($nearLimit ? '1' : '0'),
        'cancel_url'          => CANCEL_URL,
    ],
];

$ch = curl_init(PAYPAL_BASE . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'PayPal-Request-Id: ' . uniqid('sv_', true),
    ],
    CURLOPT_POSTFIELDS     => json_encode($orderPayload),
    CURLOPT_SSL_VERIFYPEER => true,
]);
$res  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code >= 400) {
    $errData = json_decode($res, true);
    echo json_encode(['success'=>false,'error'=>'PayPal error: ' . ($errData['message'] ?? $res)]);
    exit;
}

$order = json_decode($res, true);
$orderId = $order['id'] ?? null;
if (!$orderId) { echo json_encode(['success'=>false,'error'=>'Failed to create PayPal order.']); exit; }

// ── SAVE PENDING PURCHASE TO DB ───────────────────────────────────────────────
// Make sure the coin_purchases table exists
$conn->query("
    CREATE TABLE IF NOT EXISTS coin_purchases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        coins_bought INT NOT NULL,
        amount_php DECIMAL(10,2) NOT NULL,
        amount_usd DECIMAL(10,2) NOT NULL,
        platform_fee DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) DEFAULT 'paypal',
        paypal_order_id VARCHAR(100),
        status ENUM('pending','completed','failed','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_username (username),
        INDEX idx_order (paypal_order_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$stmt = $conn->prepare("INSERT INTO coin_purchases (username, coins_bought, amount_php, amount_usd, platform_fee, paypal_order_id, status) VALUES (?,?,?,?,?,'pending')");
// wait — need order id column too
$stmt = $conn->prepare("INSERT INTO coin_purchases (username, coins_bought, amount_php, amount_usd, platform_fee, paypal_order_id, status) VALUES (?,?,?,?,?,?,?)");
$statusPending = 'pending';
$stmt->bind_param('siiddss', $username, $coins, $amountPHP, $amountUSD, $feeAmount, $orderId, $statusPending);
$stmt->execute();
$stmt->close();

// ── RETURN APPROVAL URL ───────────────────────────────────────────────────────
$approvalUrl = null;
foreach ($order['links'] as $link) {
    if ($link['rel'] === 'approve') { $approvalUrl = $link['href']; break; }
}

if (!$approvalUrl) { echo json_encode(['success'=>false,'error'=>'Could not get PayPal approval URL.']); exit; }

echo json_encode([
    'success'      => true,
    'redirect_url' => $approvalUrl,
    'order_id'     => $orderId,
    'coins'        => $coins,
    'amount_php'   => $amountPHP,
    'amount_usd'   => $amountUSD,
    'fee'          => $feeAmount,
    'near_limit'   => $nearLimit,
]);

$conn->close();
?>
