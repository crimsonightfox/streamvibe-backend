<?php
// buy_coins.php — initiates a coin purchase
// For GCash: integrate PayMongo (https://developers.paymongo.com)
// For PayPal: integrate PayPal Orders API v2 (https://developer.paypal.com/docs/api/orders/v2/)
//
// This file handles the INITIATION. Payment callbacks go to:
//   payment_callback.php?method=gcash   (PayMongo webhook)
//   payment_callback.php?method=paypal  (PayPal webhook / return URL)

session_start();
include 'db.php';
header('Content-Type: application/json');

// ── Config ────────────────────────────────────────────────────────────────
define('COINS_PER_PESO', 10);          // ₱1 = 10 coins
define('MIN_COINS', 50);               // minimum purchase
define('MAX_COINS', 100000);           // maximum per transaction

// ── PayMongo (GCash) ──────────────────────────────────────────────────────
define('PAYMONGO_SECRET_KEY', 'sk_test_YOUR_PAYMONGO_SECRET_KEY');  // replace
define('PAYMONGO_PUBLIC_KEY', 'pk_test_YOUR_PAYMONGO_PUBLIC_KEY');  // replace

// ── PayPal ────────────────────────────────────────────────────────────────
define('PAYPAL_CLIENT_ID',     'YOUR_PAYPAL_CLIENT_ID');      // replace
define('PAYPAL_CLIENT_SECRET', 'YOUR_PAYPAL_CLIENT_SECRET');  // replace
define('PAYPAL_MODE', 'sandbox');  // change to 'live' for production

define('APP_URL', 'https://yourdomain.com');  // replace with your actual domain

// ─────────────────────────────────────────────────────────────────────────

$username = trim($_POST['username'] ?? $_SESSION['username'] ?? '');
$coins    = (int)($_POST['coins']   ?? 0);
$method   = trim($_POST['method']  ?? '');  // 'gcash' or 'paypal'

if (!$username || $coins < MIN_COINS || $coins > MAX_COINS) {
    echo json_encode(['success' => false, 'error' => 'Invalid purchase amount (min ' . MIN_COINS . ' coins)']);
    exit;
}

if (!in_array($method, ['gcash', 'paypal'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

$amountPHP = $coins / COINS_PER_PESO;  // peso amount

// ── Save pending purchase ─────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO coin_purchases (username, coins_bought, amount_php, payment_method, status)
    VALUES (?, ?, ?, ?, 'pending')
");
$stmt->bind_param("sids", $username, $coins, $amountPHP, $method);
$stmt->execute();
$purchaseId = $conn->insert_id;
$stmt->close();

// ── Route to payment gateway ──────────────────────────────────────────────
if ($method === 'gcash') {
    $result = initiateGCash($purchaseId, $username, $coins, $amountPHP);
} else {
    $result = initiatePayPal($purchaseId, $username, $coins, $amountPHP);
}

echo json_encode($result);
$conn->close();


// ═══════════════════════════════════════════════════════════════════════════
// GCash via PayMongo
// ═══════════════════════════════════════════════════════════════════════════
function initiateGCash($purchaseId, $username, $coins, $amountPHP) {
    $amountCentavos = (int)($amountPHP * 100);

    $payload = [
        'data' => [
            'attributes' => [
                'amount'       => $amountCentavos,
                'currency'     => 'PHP',
                'description'  => "StreamVibe {$coins} Coins for {$username}",
                'redirect'     => [
                    'success' => APP_URL . "/payment_callback.php?method=gcash&purchase_id={$purchaseId}&status=success",
                    'failed'  => APP_URL . "/payment_callback.php?method=gcash&purchase_id={$purchaseId}&status=failed"
                ],
                'type'         => 'gcash',
                'billing'      => ['name' => $username]
            ]
        ]
    ];

    $ch = curl_init('https://api.paymongo.com/v1/links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 200 && isset($data['data']['attributes']['checkout_url'])) {
        return [
            'success'      => true,
            'redirect_url' => $data['data']['attributes']['checkout_url'],
            'purchase_id'  => $purchaseId,
            'method'       => 'gcash'
        ];
    }

    // ── MOCK fallback (remove when you have real keys) ──────────────────
    return [
        'success'      => true,
        'redirect_url' => APP_URL . "/payment_callback.php?method=gcash&purchase_id={$purchaseId}&status=success&mock=1",
        'purchase_id'  => $purchaseId,
        'method'       => 'gcash',
        'mock'         => true
    ];
}


// ═══════════════════════════════════════════════════════════════════════════
// PayPal Orders API v2
// ═══════════════════════════════════════════════════════════════════════════
function initiatePayPal($purchaseId, $username, $coins, $amountPHP) {
    $baseUrl = PAYPAL_MODE === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';

    // 1. Get access token
    $ch = curl_init("{$baseUrl}/v1/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);
    $tokenResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($tokenResponse['access_token'])) {
        // MOCK fallback (remove when you have real keys)
        return [
            'success'      => true,
            'redirect_url' => APP_URL . "/payment_callback.php?method=paypal&purchase_id={$purchaseId}&status=success&mock=1",
            'purchase_id'  => $purchaseId,
            'method'       => 'paypal',
            'mock'         => true
        ];
    }

    $accessToken = $tokenResponse['access_token'];

    // Convert PHP pesos to USD (approximate — use a live rate in production)
    // ₱1 ≈ $0.017 USD  (update this rate or use an API)
    $amountUSD = number_format($amountPHP * 0.017, 2, '.', '');

    // 2. Create order
    $orderPayload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'amount'      => ['currency_code' => 'USD', 'value' => $amountUSD],
            'description' => "StreamVibe {$coins} Coins"
        ]],
        'application_context' => [
            'return_url' => APP_URL . "/payment_callback.php?method=paypal&purchase_id={$purchaseId}&status=success",
            'cancel_url' => APP_URL . "/payment_callback.php?method=paypal&purchase_id={$purchaseId}&status=failed"
        ]
    ];

    $ch = curl_init("{$baseUrl}/v2/checkout/orders");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($orderPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}"
        ]
    ]);
    $orderResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $approveLink = null;
    foreach (($orderResponse['links'] ?? []) as $link) {
        if ($link['rel'] === 'approve') { $approveLink = $link['href']; break; }
    }

    if ($approveLink) {
        return ['success' => true, 'redirect_url' => $approveLink, 'purchase_id' => $purchaseId, 'method' => 'paypal'];
    }

    return ['success' => false, 'error' => 'PayPal order creation failed'];
}
?>