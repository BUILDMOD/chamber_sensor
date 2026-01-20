<?php
include('includes/db_connect.php');

// Always use PH timezone
date_default_timezone_set('Asia/Manila');

// Set response type
header("Content-Type: application/json");

// Get range from URL (default = live)
$range = $_GET['range'] ?? 'live';

// Build SQL based on selected range
switch ($range) {

    case '1hr':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 1 HOUR
                ORDER BY timestamp ASC";
        break;

    case '1day':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 1 DAY
                ORDER BY timestamp ASC";
        break;

    case '1week':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 1 WEEK
                ORDER BY timestamp ASC";
        break;

    case '1month':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 1 MONTH
                ORDER BY timestamp ASC";
        break;

    case '3months':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 3 MONTH
                ORDER BY timestamp ASC";
        break;

    case '6months':
        $sql = "SELECT * FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= NOW() - INTERVAL 6 MONTH
                ORDER BY timestamp ASC";
        break;

    default: // LIVE MODE (latest 10 entries)
        $sql = "SELECT * FROM sensor_data 
                ORDER BY id DESC 
                LIMIT 10";
        break;
}

// Run query
$result = $conn->query($sql);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            "id"          => (int)$row["id"],
            "temperature" => $row["temperature"] !== null ? (float)$row["temperature"] : null,
            "humidity"    => $row["humidity"] !== null ? (float)$row["humidity"] : null,
            "timestamp"   => $row["timestamp"]
        ];
    }
}

// If LIVE mode, reverse order (DESC → ASC)
if ($range === "live") {
    $data = array_reverse($data);
}

echo json_encode($data);
exit;
?>
