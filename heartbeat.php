<?php
include 'db.php';
header('Content-Type: application/json');

$viewer_id = $_POST['viewer_id'] ?? '';
$stream_id = $_POST['stream_id'] ?? '';

if (!$viewer_id || !$stream_id) {
    echo json_encode(['viewer_count' => 0]);
    exit;
}

// Upsert viewer heartbeat
$stmt = $conn->prepare("
    INSERT INTO stream_viewers (viewer_id, stream_id, last_seen)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE last_seen = NOW()
");

if ($stmt) {
    $stmt->bind_param("ss", $viewer_id, $stream_id);
    $stmt->execute();
    $stmt->close();
}

// Count viewers active in last 15 seconds
$count_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt 
    FROM stream_viewers 
    WHERE stream_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
");

$count = 1;
if ($count_stmt) {
    $count_stmt->bind_param("s", $stream_id);
    $count_stmt->execute();
    $result = $count_stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $count = $row['cnt'];
    }
    $count_stmt->close();
}

echo json_encode(['viewer_count' => $count]);
$conn->close();
?>