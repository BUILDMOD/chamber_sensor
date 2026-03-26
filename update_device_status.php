<?php
/**
 * update_device_status.php
 * Handles system mode switching (auto/manual) and manual device control
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

date_default_timezone_set('Asia/Manila');

// Bootstrap tables if needed
$conn->query("CREATE TABLE IF NOT EXISTS system_mode (
    id INT PRIMARY KEY DEFAULT 1,
    mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO system_mode (id,mode) VALUES (1,'auto')");

$conn->query("CREATE TABLE IF NOT EXISTS device_state (
    device VARCHAR(20) NOT NULL PRIMARY KEY,
    status ENUM('on','off') NOT NULL DEFAULT 'off',
    controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
    locked_until INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Initialize devices if they don't exist
$devices = ['mist','fan','heater','sprayer','exhaust'];
foreach ($devices as $dev) {
    $conn->query("INSERT IGNORE INTO device_state (device,status,controlled_by,locked_until)
                  VALUES ('{$dev}','off','auto',0)");
}

$response = ['success' => false, 'message' => ''];

// Handle system mode switch (auto/manual)
if (isset($_GET['mode'])) {
    $mode = intval($_GET['mode']);
    $newMode = $mode === 1 ? 'manual' : 'auto';
    
    $stmt = $conn->prepare("UPDATE system_mode SET mode=? WHERE id=1");
    if ($stmt) {
        $stmt->bind_param("s", $newMode);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "System mode changed to {$newMode}";
            
            // Log the mode change
            $conn->query("CREATE TABLE IF NOT EXISTS device_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device VARCHAR(30) NOT NULL,
                action ENUM('ON','OFF') NOT NULL,
                trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
                trigger_detail TEXT DEFAULT NULL,
                duration_seconds INT DEFAULT NULL,
                logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            $logStmt = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES ('system','ON','manual',?)");
            if ($logStmt) {
                $detail = "System mode switched to {$newMode}";
                $logStmt->bind_param("s", $detail);
                $logStmt->execute();
                $logStmt->close();
            }
        } else {
            $response['message'] = 'Database error: ' . $conn->error;
        }
        $stmt->close();
    } else {
        $response['message'] = 'Prepare error: ' . $conn->error;
    }
    
    echo json_encode($response);
    exit;
}

// Handle manual device control - support both formats
$device = null;
$action = null;

// Format 1: update_device_status.php?device=mist&action=on
if (isset($_GET['device']) && isset($_GET['action'])) {
    $device = $_GET['device'];
    $action = $_GET['action'];
}
// Format 2: update_device_status.php?mist=1 (dashboard format)
else {
    foreach ($devices as $dev) {
        if (isset($_GET[$dev])) {
            $device = $dev;
            $action = $_GET[$dev] == '1' ? 'on' : 'off';
            break;
        }
    }
}

if ($device && $action) {
    
    // Validate device
    if (!in_array($device, $devices)) {
        $response['message'] = 'Invalid device';
        echo json_encode($response);
        exit;
    }
    
    // Validate action
    if (!in_array($action, ['on', 'off'])) {
        $response['message'] = 'Invalid action';
        echo json_encode($response);
        exit;
    }
    
    // Manual device control allowed anytime (emergency override)
    // But warn if not in manual mode
    $modeRow = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
    $manualMode = ($modeRow['mode'] ?? 'auto') === 'manual';
    
    if (!$manualMode) {
        // Allow control but warn user
        $response['warning'] = 'Device controlled while system is in auto mode';
    }
    
    // Update device state
    $stmt = $conn->prepare("UPDATE device_state SET status=?, controlled_by='manual', locked_until=0 WHERE device=?");
    if ($stmt) {
        $stmt->bind_param("ss", $action, $device);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Device {$device} turned {$action}";
            
            // Log the action
            $conn->query("CREATE TABLE IF NOT EXISTS device_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device VARCHAR(30) NOT NULL,
                action ENUM('ON','OFF') NOT NULL,
                trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
                trigger_detail TEXT DEFAULT NULL,
                duration_seconds INT DEFAULT NULL,
                logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            $logStmt = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES (?,?,?,'Manual control from dashboard')");
            if ($logStmt) {
                $actionUpper = strtoupper($action);
                $logStmt->bind_param("sss", $device, $actionUpper, $actionUpper);
                $logStmt->execute();
                $logStmt->close();
            }
        } else {
            $response['message'] = 'Database error: ' . $conn->error;
        }
        $stmt->close();
    } else {
        $response['message'] = 'Prepare error: ' . $conn->error;
    }
    
    echo json_encode($response);
    exit;
}

// If no valid parameters provided
$response['message'] = 'Invalid request';
echo json_encode($response);

$conn->close();
?>
