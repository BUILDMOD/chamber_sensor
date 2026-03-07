<?php
header('Content-Type: application/json');
include "includes/db_connect.php";

// Load thresholds from DB to send to ESP32
$thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
        'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
$tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
if ($tr) while ($r2 = $tr->fetch_assoc()) {
    if ($r2['metric']==='temperature')    { $thr['temp_min']=$r2['min_value']; $thr['temp_max']=$r2['max_value']; }
    if ($r2['metric']==='humidity')       { $thr['hum_min']=$r2['min_value'];  $thr['hum_max']=$r2['max_value']; }
    if ($r2['metric']==='emergency_temp') { $thr['emerg_temp_low']=$r2['min_value']; $thr['emerg_temp_high']=$r2['max_value']; }
    if ($r2['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$r2['max_value']; }
}

$sql = "SELECT manual_mode, mist, fan, heater, sprayer, exhaust, IFNULL(buzzer,0) as buzzer FROM device_status WHERE id = 1 LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'manual_mode'     => (int)$row['manual_mode'],
        'mist'            => (int)$row['mist'],
        'fan'             => (int)$row['fan'],
        'heater'          => (int)$row['heater'],
        'sprayer'         => (int)$row['sprayer'],
        'exhaust'         => (int)$row['exhaust'],
        'buzzer'          => (int)($row['buzzer'] ?? 0),
        // Thresholds — ESP32 uses these for local fallback when WiFi is lost
        'temp_min'        => (float)$thr['temp_min'],
        'temp_max'        => (float)$thr['temp_max'],
        'hum_min'         => (float)$thr['hum_min'],
        'hum_max'         => (float)$thr['hum_max'],
        'emerg_temp_high' => (float)$thr['emerg_temp_high'],
        'emerg_temp_low'  => (float)$thr['emerg_temp_low'],
        'emerg_hum_high'  => (float)$thr['emerg_hum_high'],
    ]);
} else {
    echo json_encode([
        'manual_mode' => 0,
        'mist'        => 0,
        'fan'         => 0,
        'heater'      => 0,
        'sprayer'     => 0,
        'exhaust'     => 0,
        'buzzer'      => 0,
    ]);
}

$conn->close();
?>