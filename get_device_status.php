<?php
header('Content-Type: application/json');
include "includes/db_connect.php";

$sql = "SELECT manual_mode, mist, fan, heater, sprayer, exhaust FROM device_status WHERE id = 1 LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'manual_mode' => (int)$row['manual_mode'],
        'mist'        => (int)$row['mist'],
        'fan'         => (int)$row['fan'],
        'heater'      => (int)$row['heater'],
        'sprayer'     => (int)$row['sprayer'],
        'exhaust'     => (int)$row['exhaust'],
    ]);
} else {
    echo json_encode([
        'manual_mode' => 0,
        'mist'        => 0,
        'fan'         => 0,
        'heater'      => 0,
        'sprayer'     => 0,
        'exhaust'     => 0,
    ]);
}

$conn->close();
?>