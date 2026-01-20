<?php
header('Content-Type: text/plain');
include "includes/db_connect.php";

$sql = "SELECT manual_mode, mist, fan, heater, sprayer, exhaust FROM device_status WHERE id = 1 LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo $row['manual_mode'] . ',' . $row['mist'] . ',' . $row['fan'] . ',' . $row['heater'] . ',' . $row['sprayer'] . ',' . $row['exhaust'];
} else {
    echo '0,0,0,0,0,0';
}

$conn->close();
?>
