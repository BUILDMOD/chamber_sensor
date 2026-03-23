<?php
/**
 * get_device_status.php
 * Reads from device_state table (new architecture).
 * Output JSON is identical to the old format — ESP32 and dashboard need no changes.
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

// Bootstrap tables if not yet migrated
$conn->query("CREATE TABLE IF NOT EXISTS device_state (
    device        VARCHAR(20)  NOT NULL PRIMARY KEY,
    status        ENUM('on','off') NOT NULL DEFAULT 'off',
    controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
    locked_until  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
foreach (['mist','fan','heater','sprayer','exhaust'] as $dev) {
    $conn->query("INSERT IGNORE INTO device_state (device,status,controlled_by,locked_until)
                  VALUES ('{$dev}','off','auto',0)");
}
$conn->query("CREATE TABLE IF NOT EXISTS system_mode (
    id INT PRIMARY KEY DEFAULT 1,
    mode ENUM('auto','manual') NOT NULL DEFAULT 'auto'
)");
$conn->query("INSERT IGNORE INTO system_mode (id,mode) VALUES (1,'auto')");
$conn->query("CREATE TABLE IF NOT EXISTS system_flags (
    flag_key   VARCHAR(30) PRIMARY KEY,
    flag_value TINYINT(1) NOT NULL DEFAULT 0
)");
$conn->query("INSERT IGNORE INTO system_flags (flag_key,flag_value) VALUES ('buzzer',0)");

// Load thresholds
$thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
        'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
$tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
if ($tr) while ($r2 = $tr->fetch_assoc()) {
    if ($r2['metric']==='temperature')    { $thr['temp_min']=$r2['min_value']; $thr['temp_max']=$r2['max_value']; }
    if ($r2['metric']==='humidity')       { $thr['hum_min']=$r2['min_value'];  $thr['hum_max']=$r2['max_value']; }
    if ($r2['metric']==='emergency_temp') { $thr['emerg_temp_low']=$r2['min_value']; $thr['emerg_temp_high']=$r2['max_value']; }
    if ($r2['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$r2['max_value']; }
}

// Load device states
$deviceMap = [];
$r = $conn->query("SELECT device, status, controlled_by, locked_until FROM device_state");
if ($r) while ($row = $r->fetch_assoc()) $deviceMap[$row['device']] = $row;

$statusInt = fn($dev) => (($deviceMap[$dev]['status'] ?? 'off') === 'on') ? 1 : 0;

// Mode
$modeRow    = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
$manualMode = ($modeRow['mode'] ?? 'auto') === 'manual' ? 1 : 0;

// Buzzer
$buzzerRow = $conn->query("SELECT flag_value FROM system_flags WHERE flag_key='buzzer' LIMIT 1")->fetch_assoc();
$buzzer    = (int)($buzzerRow['flag_value'] ?? 0);

// Build response — same shape as old get_device_status.php
// ESP32 reads: manual_mode, mist, fan, heater, sprayer, exhaust, buzzer, thresholds
// Extra fields (controlled_by, locked_until) are ignored by ESP32 but available for dashboard
$response = [
    'manual_mode'     => $manualMode,
    'mist'            => $statusInt('mist'),
    'fan'             => $statusInt('fan'),
    'heater'          => $statusInt('heater'),
    'sprayer'         => $statusInt('sprayer'),
    'exhaust'         => $statusInt('exhaust'),
    'buzzer'          => $buzzer,
    // Thresholds for ESP32 local fallback
    'temp_min'        => (float)$thr['temp_min'],
    'temp_max'        => (float)$thr['temp_max'],
    'hum_min'         => (float)$thr['hum_min'],
    'hum_max'         => (float)$thr['hum_max'],
    'emerg_temp_high' => (float)$thr['emerg_temp_high'],
    'emerg_temp_low'  => (float)$thr['emerg_temp_low'],
    'emerg_hum_high'  => (float)$thr['emerg_hum_high'],
    // Extra: controlled_by per device (used by dashboard for display)
    'controlled_by'   => array_map(fn($s) => $s['controlled_by'], $deviceMap),
    'locked_until'    => array_map(fn($s) => (int)$s['locked_until'], $deviceMap),
];

echo json_encode($response);
$conn->close();
?>