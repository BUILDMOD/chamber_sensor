<?php
/**
 * save_cam_ip.php
 * Called by ESP32-CAM on boot to register its IP address.
 * Saves to system_settings table so dashboard can auto-load stream URL.
 */
include('includes/db_connect.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$ip = $_GET['ip'] ?? '';

if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['success' => false, 'error' => 'Invalid IP']);
    exit;
}

// Ensure system_settings table exists
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Save or update the cam IP
$stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
    VALUES ('esp32cam_ip', ?)
    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");

if ($stmt) {
    $stmt->bind_param("ss", $ip, $ip);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'ip' => $ip]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();