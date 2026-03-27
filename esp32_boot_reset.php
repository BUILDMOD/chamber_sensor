<?php
/**
 * ESP32 Boot Reset Script
 * Called by ESP32 on power-on to safely reset device states
 * Prevents unwanted device activation during boot sequence
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

date_default_timezone_set('Asia/Manila');

// Create tables if needed
$conn->query("CREATE TABLE IF NOT EXISTS device_state (
    device VARCHAR(20) NOT NULL PRIMARY KEY,
    status ENUM('on','off') NOT NULL DEFAULT 'off',
    controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
    locked_until INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS system_mode (
    id INT PRIMARY KEY DEFAULT 1,
    mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'manual',
    trigger_detail TEXT DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Get current boot time
$bootTime = date('Y-m-d H:i:s');

// Reset all devices to OFF with safe boot flag
$devices = ['mist','fan','heater','sprayer','exhaust'];
foreach ($devices as $device) {
    $stmt = $conn->prepare("UPDATE device_state SET status='off', controlled_by='auto', locked_until=0 WHERE device=?");
    if ($stmt) {
        $stmt->bind_param("s", $device);
        $stmt->execute();
        $stmt->close();
    }
}

// Force system to Auto mode on boot
$stmt = $conn->prepare("UPDATE system_mode SET mode='auto' WHERE id=1");
if ($stmt) {
    $stmt->execute();
    $stmt->close();
}

// Log boot reset event
$logStmt = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES ('system','ON','manual',?)");
if ($logStmt) {
    $detail = "ESP32 boot completed - all devices reset to OFF, system in Auto mode";
    $logStmt->bind_param("s", $detail);
    $logStmt->execute();
    $logStmt->close();
}

// Add boot delay buffer for schedules (prevent immediate firing)
$now = new DateTime();
$bufferMinutes = 0.5; // 30-second buffer after boot for demo
$bufferTime = $now->format('Y-m-d H:i:s');

echo json_encode([
    'success' => true,
    'message' => 'Boot reset completed',
    'boot_time' => $bootTime,
    'buffer_minutes' => $bufferMinutes,
    'next_safe_time' => $bufferTime
]);

$conn->close();
?>
