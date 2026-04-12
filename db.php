<?php
$conn = new mysqli(
    "sql200.infinityfree.com",
    "if0_40980290",
    "md7vQo22wr",
    "if0_40980290_StreamVibe_db"
);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'DB failed: ' . $conn->connect_error]));
}
?>