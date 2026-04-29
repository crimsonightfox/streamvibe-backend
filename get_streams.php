<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$conn = mysqli_init();
mysqli_ssl_set($conn, NULL, NULL, __DIR__ . '/ca.pem', NULL, NULL);
mysqli_real_connect($conn, "mysql-13f8e68-streamvibe.b.aivencloud.com", "avnadmin", "AVNS_-TbAYyJb4l9uqLCBW6w", "defaultdb", 26120, NULL, MYSQLI_CLIENT_SSL);

if (mysqli_connect_error()) {
    echo json_encode([]);
    exit;
}

$result = $conn->query("SELECT stream_id, title, category, description, status, started_at FROM streams WHERE status='live' ORDER BY started_at DESC");

if (!$result) { echo json_encode([]); exit; }

$streams = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['STATUS'] ?? $row['status'] ?? 'live';
    $streams[] = $row;
}

echo json_encode($streams);
$conn->close();
?>
