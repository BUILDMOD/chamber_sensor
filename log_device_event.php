<?php
/**
 * log_device_event.php
 * Called by ESP32 to log sprayer schedule ON/OFF events into device_logs.
 * 
 * GET params:
 *   device   = sprayer
 *   action   = ON | OFF
 *   trigger  = schedule
 *   detail   = text description
 *   duration = seconds (optional, for OFF events)
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

$device   = preg_replace('/[^a-z]/', '', strtolower($_GET['device']   ?? ''));
$action   = strtoupper(preg_replace('/[^a-zA-Z]/', '', $_GET['action'] ?? ''));
$trigger  = preg_replace('/[^a-z]/', '', strtolower($_GET['trigger']  ?? 'schedule'));
$detail   = substr(strip_tags($_GET['detail']   ?? ''), 0, 200);
$duration = isset($_GET['duration']) ? intval($_GET['duration']) : null;

// Validate
if (!in_array($device, ['mist','fan','heater','sprayer','exhaust'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid device']);
    exit;
}
if (!in_array($action, ['ON','OFF'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}
if (!in_array($trigger, ['auto','manual','schedule','emergency','fault'])) {
    $trigger = 'schedule';
}

// Also update device_state table
$status = ($action === 'ON') ? 'on' : 'off';
$locked_until = 0;

// If turning ON via schedule, lock it for the duration so auto_engine won't interfere
if ($action === 'ON' && $duration) {
    $locked_until = time() + $duration;
}

$conn->query("INSERT INTO device_state (device, status, controlled_by, locked_until)
    VALUES ('{$device}', '{$status}', '{$trigger}', {$locked_until})
    ON DUPLICATE KEY UPDATE status='{$status}', controlled_by='{$trigger}', locked_until={$locked_until}");

// Insert into device_logs
$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
    trigger_detail VARCHAR(200),
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$stmt = $conn->prepare(
    "INSERT INTO device_logs (device, action, trigger_type, trigger_detail, duration_seconds)
     VALUES (?, ?, ?, ?, ?)"
);

if ($stmt) {
    $stmt->bind_param("ssssi", $device, $action, $trigger, $detail, $duration);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'logged' => "$device $action"]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
?>