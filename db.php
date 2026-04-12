<?php
header("Access-Control-Allow-Origin: https://streamvibe.free.nf");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, __DIR__ . '/ca.pem', NULL, NULL);
mysqli_real_connect($conn, "mysql-13f8e68-streamvibe.b.aivencloud.com", "avnadmin", "AVNS_-TbAYyJb4l9uqLCBW6w", "defaultdb", 26120, NULL, MYSQLI_CLIENT_SSL);
if (mysqli_connect_error()) {
    die(json_encode(['success' => false, 'error' => 'DB failed: ' . mysqli_connect_error()]));
}
?>
