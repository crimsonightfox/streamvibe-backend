<?php
session_start();
include 'db.php';
header('Content-Type: application/json');

// Accept username from session OR from POST/GET parameter
$username = $_SESSION['username'] ?? $_GET['username'] ?? '';

if (!$username) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$stmt = $conn->prepare("
    SELECT FirstName, LastName, UserName, Email, ProfilePicture 
    FROM viewer_db 
    WHERE UserName = ?
");

if (!$stmt) {
    // Try streamer_db if viewer_db fails
    $stmt = $conn->prepare("
        SELECT FirstName, LastName, UserName, Email, ProfilePicture 
        FROM streamer_db 
        WHERE UserName = ?
    ");
}

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Query failed']);
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success'    => true,
        'first_name' => $row['FirstName']      ?? '',
        'last_name'  => $row['LastName']       ?? '',
        'username'   => $row['UserName']       ?? '',
        'email'      => $row['Email']          ?? '',
        'picture'    => $row['ProfilePicture'] ?? null,
    ]);
} else {
    // Try streamer_db as fallback
    $stmt->close();
    $stmt2 = $conn->prepare("
        SELECT FirstName, LastName, UserName, Email, ProfilePicture 
        FROM streamer_db 
        WHERE UserName = ?
    ");
    if ($stmt2) {
        $stmt2->bind_param("s", $username);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        if ($row2 = $result2->fetch_assoc()) {
            echo json_encode([
                'success'    => true,
                'first_name' => $row2['FirstName']      ?? '',
                'last_name'  => $row2['LastName']       ?? '',
                'username'   => $row2['UserName']       ?? '',
                'email'      => $row2['Email']          ?? '',
                'picture'    => $row2['ProfilePicture'] ?? null,
            ]);
            $stmt2->close();
            $conn->close();
            exit;
        }
        $stmt2->close();
    }
    echo json_encode(['success' => false, 'error' => 'User not found']);
}

$stmt->close();
$conn->close();
?>