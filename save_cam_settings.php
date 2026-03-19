<?php
/**
 * save_cam_settings.php
 * Called via AJAX from dashboard live cam settings panel.
 * Saves only the fields that were sent (non-empty).
 */
include('includes/db_connect.php');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$allowed = [
    'camera_interval_sec' => 'int',
    'cam_quality'         => 'int',
    'cam_brightness'      => 'int',
    'cam_contrast'        => 'int',
    'cam_saturation'      => 'int',
    'cam_sharpness'       => 'int',
    'cam_wb_mode'         => 'int',
    'cam_flash'           => 'int',
    'cam_vflip'           => 'int',
    'cam_hmirror'         => 'int',
    'cam_resolution'      => 'str',
];

$saved = 0;
foreach ($allowed as $key => $type) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') continue;
    $val = $type === 'int' ? (string)intval($_POST[$key]) : trim($_POST[$key]);
    $s = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    if ($s) {
        $s->bind_param("sss", $key, $val, $val);
        $s->execute();
        $s->close();
        $saved++;
    }
}

echo json_encode(['success' => $saved > 0, 'saved' => $saved]);
$conn->close();