<?php
session_start();
include 'db.php';
header('Content-Type: application/json');

// Get username from session OR POST parameter
$username = $_SESSION['username'] ?? $_POST['current_username'] ?? '';

if (!$username) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name']  ?? '');
$new_user   = trim($_POST['username']   ?? '');
$email      = trim($_POST['email']      ?? '');
$picture    = $_POST['picture']         ?? '';

if (!$first_name || !$new_user || !$email) {
    echo json_encode(['success' => false, 'error' => 'Required fields missing']);
    exit;
}

// Figure out which table this user is in
function findUserTable($conn, $username) {
    $stmt = $conn->prepare("SELECT UserName FROM viewer_db WHERE UserName = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); return 'viewer_db'; }
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT UserName FROM streamer_db WHERE UserName = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); return 'streamer_db'; }
        $stmt->close();
    }
    return null;
}

$table = findUserTable($conn, $username);

if (!$table) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Check if new username is taken by someone else
if ($new_user !== $username) {
    foreach (['viewer_db', 'streamer_db'] as $t) {
        $check = $conn->prepare("SELECT UserName FROM `$t` WHERE UserName = ?");
        if ($check) {
            $check->bind_param("s", $new_user);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                echo json_encode(['success' => false, 'error' => 'Username already taken']);
                $check->close();
                $conn->close();
                exit;
            }
            $check->close();
        }
    }
}

// Build update query
if (!empty($picture)) {
    $stmt = $conn->prepare("
        UPDATE `$table`
        SET FirstName=?, LastName=?, UserName=?, Email=?, ProfilePicture=?
        WHERE UserName=?
    ");
    $stmt->bind_param("ssssss", $first_name, $last_name, $new_user, $email, $picture, $username);
} else {
    $stmt = $conn->prepare("
        UPDATE `$table`
        SET FirstName=?, LastName=?, UserName=?, Email=?
        WHERE UserName=?
    ");
    $stmt->bind_param("sssss", $first_name, $last_name, $new_user, $email, $username);
}

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Query prepare failed']);
    exit;
}

$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($affected >= 0) {
    $_SESSION['username'] = $new_user;
    // Update localStorage key via response
    echo json_encode(['success' => true, 'username' => $new_user]);
} else {
    echo json_encode(['success' => false, 'error' => 'Update failed']);
}
?>