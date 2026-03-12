<?php
// update_device_status.php
header('Content-Type: application/json');
include 'includes/db_connect.php';

// Read from GET, POST, or JSON body
$input = [];
$raw = file_get_contents('php://input');
if ($raw) $input = json_decode($raw, true) ?? [];
if (empty($input)) $input = array_merge($_GET, $_POST);

// Ensure device_status row exists
$conn->query("CREATE TABLE IF NOT EXISTS device_status (
    id INT PRIMARY KEY,
    manual_mode TINYINT(1) NOT NULL DEFAULT 0,
    mist        TINYINT(1) NOT NULL DEFAULT 0,
    fan         TINYINT(1) NOT NULL DEFAULT 0,
    heater      TINYINT(1) NOT NULL DEFAULT 0,
    sprayer     TINYINT(1) NOT NULL DEFAULT 0,
    exhaust     TINYINT(1) NOT NULL DEFAULT 0,
    buzzer      TINYINT(1) NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$check = $conn->query("SELECT id FROM device_status WHERE id=1 LIMIT 1");
if ($check->num_rows == 0) {
    $conn->query("INSERT INTO device_status (id,manual_mode,mist,fan,heater,sprayer,exhaust,buzzer) VALUES (1,0,0,0,0,0,0,0)");
}

// ── Handle mode switch ──
if (isset($input['mode'])) {
    $mode = intval($input['mode']);
    if ($mode === 1) {
        // Switching TO manual — keep current device states as-is (don't reset to 0)
        // Just flip the manual_mode flag, relay states stay whatever they currently are
        $conn->query("UPDATE device_status SET manual_mode=1 WHERE id=1");
    } else {
        // Switching TO auto — just flip the flag, ESP32 auto logic takes over
        $conn->query("UPDATE device_status SET manual_mode=0 WHERE id=1");
    }
    $row = $conn->query("SELECT * FROM device_status WHERE id=1 LIMIT 1")->fetch_assoc();
    echo json_encode(['success' => true, 'data' => $row]);
    exit;
}

// ── Handle device toggle via ?device=xxx (reads current, flips it) ──
$allowed = ['mist','fan','heater','sprayer','exhaust'];

if (isset($input['device'])) {
    $dev = $input['device'];
    if (!in_array($dev, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid device']);
        exit;
    }
    $cur = $conn->query("SELECT `$dev` FROM device_status WHERE id=1 LIMIT 1")->fetch_assoc();
    $newVal = $cur[$dev] ? 0 : 1;
    $conn->query("UPDATE device_status SET `$dev`=$newVal WHERE id=1");
    // Log
    $action = $newVal ? 'ON' : 'OFF';
    $ls = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES (?,'$action','manual','Manual toggle via dashboard')");
    if ($ls) { $ls->bind_param("s",$dev); $ls->execute(); $ls->close(); }
    $row = $conn->query("SELECT * FROM device_status WHERE id=1 LIMIT 1")->fetch_assoc();
    echo json_encode(['success'=>true,'data'=>$row]);
    exit;
}

// ── Handle explicit device value e.g. ?fan=1&mist=0 ──
$fields = []; $logs = [];
foreach ($allowed as $dev) {
    if (isset($input[$dev])) {
        $val = intval($input[$dev]) ? 1 : 0;
        $fields[] = "`$dev`=$val";
        $logs[$dev] = $val;
    }
}

if (empty($fields)) {
    echo json_encode(['success'=>false,'message'=>'No valid fields provided']);
    exit;
}

$conn->query("UPDATE device_status SET ".implode(',',$fields)." WHERE id=1");

// Log each changed device
$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
    trigger_detail VARCHAR(100),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
foreach ($logs as $dev => $val) {
    $action = $val ? 'ON' : 'OFF';
    $ls = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES (?,'$action','manual','Manual toggle via dashboard')");
    if ($ls) { $ls->bind_param("s",$dev); $ls->execute(); $ls->close(); }
}

$row = $conn->query("SELECT * FROM device_status WHERE id=1 LIMIT 1")->fetch_assoc();
echo json_encode(['success'=>true,'data'=>$row]);
$conn->close();
?>