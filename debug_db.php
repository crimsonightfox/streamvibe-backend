<?php
// TEMPORARY DEBUG FILE - DELETE AFTER USE

require_once 'db.php';

if ($conn->connect_error) {
    echo "<h2 style='color:red'>❌ CONNECTION FAILED</h2>";
    echo "<p>" . $conn->connect_error . "</p>";
} else {
    echo "<h2 style='color:green'>✅ CONNECTED TO AIVEN SUCCESSFULLY</h2>";
    echo "<p>Host info: " . $conn->host_info . "</p>";

    // Test if tables exist
    $tables = ['viewer_db', 'streamer_db', 'streams', 'chat', 'stream_chat', 'stream_viewers', 'viewers', 'coin_balance', 'coin_purchases', 'coin_transactions'];
    echo "<h3>Tables:</h3><ul>";
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result && $result->num_rows > 0) {
            echo "<li style='color:green'>✅ $table exists</li>";
        } else {
            echo "<li style='color:red'>❌ $table MISSING</li>";
        }
    }
    echo "</ul>";
}
?>
