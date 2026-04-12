<?php
include 'db.php';
$stream_id = $_GET['stream_id'];
$last_id = $_GET['last_id'] ?? 0;

$stmt = $conn->prepare("SELECT * FROM chat WHERE stream_id=? AND chat_id > ? ORDER BY chat_id ASC");
$stmt->bind_param("ii",$stream_id,$last_id);
$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while($row = $result->fetch_assoc()){
    $messages[] = $row;
}
echo json_encode($messages);
?>