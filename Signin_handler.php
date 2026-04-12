<?php
session_start();
header('Content-Type: text/plain');

$conn = new mysqli(
    "sql200.infinityfree.com",
    "if0_40980290",
    "md7vQo22wr",
    "if0_40980290_StreamVibe_db"
);

if ($conn->connect_error) {
    echo "server_error";
    exit;
}

$email = $_POST['email'] ?? '';
$pass  = $_POST['password'] ?? '';

if (!$email || !$pass) {
    echo "empty_fields";
    exit;
}

function checkTable($conn, $table, $email, $pass) {
    $sql = "SELECT UserName, Password FROM `$table` WHERE Email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ["status" => "server_error", "username" => ""];
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($username, $hashedPassword);
    if ($stmt->fetch()) {
        if ($pass === $hashedPassword) {
            return ["status" => "success_$table", "username" => $username];
        }
        return ["status" => "wrong_password", "username" => ""];
    }
    return ["status" => "not_found", "username" => ""];
}

/* Check viewer first */
$result = checkTable($conn, "viewer_db", $email, $pass);

if ($result["status"] === "success_viewer_db") {
    session_start();
    $_SESSION['username'] = $result["username"];
    $_SESSION['role']     = 'viewer';
    echo "success_viewer_db";
    exit;
}

if ($result["status"] === "wrong_password") {
    echo "wrong_password";
    exit;
}

/* Check streamer if not found in viewer_db */
if ($result["status"] === "not_found") {
    $result = checkTable($conn, "streamer_db", $email, $pass);

    if ($result["status"] === "success_streamer_db") {
        session_start();
        $_SESSION['username'] = $result["username"];
        $_SESSION['role']     = 'streamer';
        echo "success_streamer_db";
        exit;
    }

    if ($result["status"] === "wrong_password") {
        echo "wrong_password";
        exit;
    }
}

echo "user_not_found";
$conn->close();
?>