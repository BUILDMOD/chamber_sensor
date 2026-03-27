<?php
/**
 * check_email_settings.php
 * Check email notification settings
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

// Get notification settings
$notif_settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $notif_settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Get system settings for email preferences
$sys_settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sys_settings[$row['setting_key']] = $row['setting_value'];
    }
}

echo json_encode([
    'success' => true,
    'notification_settings' => $notif_settings,
    'system_settings' => $sys_settings,
    'email_enabled' => ($notif_settings['smtp_enabled'] ?? '0') === '1',
    'smtp_configured' => !empty($notif_settings['smtp_host'] ?? '') && !empty($notif_settings['smtp_username'] ?? ''),
    'alerts_enabled' => [
        'temperature' => ($sys_settings['notify_temp'] ?? '1') === '1',
        'humidity' => ($sys_settings['notify_hum'] ?? '1') === '1',
        'emergency' => ($sys_settings['notify_emergency'] ?? '1') === '1',
        'offline' => ($sys_settings['notify_offline'] ?? '1') === '1'
    ]
]);

$conn->close();
?>
