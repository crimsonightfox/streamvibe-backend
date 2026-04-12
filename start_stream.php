<?php
include 'db.php';
$title = $_POST['title'];
$category = $_POST['category'];

// Create a new stream
$stmt = $conn->prepare("INSERT INTO streams (title, category, status) VALUES (?, ?, 'live')");
$stmt->bind_param("ss", $title, $category);
$stmt->execute();
echo json_encode(['success'=>true, 'stream_id'=>$conn->insert_id]);
?>