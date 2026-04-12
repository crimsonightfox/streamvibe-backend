<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: https://streamvibe.free.nf");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
include 'db.php';
$title = $_POST['title'];
$category = $_POST['category'];

// Create a new stream
$stmt = $conn->prepare("INSERT INTO streams (title, category, status) VALUES (?, ?, 'live')");
$stmt->bind_param("ss", $title, $category);
$stmt->execute();
echo json_encode(['success'=>true, 'stream_id'=>$conn->insert_id]);
?>
