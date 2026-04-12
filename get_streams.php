<?php
header('Content-Type: application/json');

$hosts = ['sql200.infinityfree.com', 'localhost', '127.0.0.1'];
$conn  = null;

foreach ($hosts as $host) {
    $test = @new mysqli($host, "if0_40980290", "md7vQo22wr", "if0_40980290_StreamVibe_db");
    if (!$test->connect_error) { $conn = $test; break; }
}

if (!$conn) { echo json_encode([]); exit; }

$result = $conn->query("SELECT * FROM streams WHERE STATUS='live' ORDER BY started_at DESC");

if (!$result) { echo json_encode([]); exit; }

$streams = [];
while ($row = $result->fetch_assoc()) {
    // Normalize to lowercase 'status' so the viewer dashboard JS filter works
    $row['status'] = $row['STATUS'] ?? $row['status'] ?? 'live';
    $streams[] = $row;
}

echo json_encode($streams);
$conn->close();
?>