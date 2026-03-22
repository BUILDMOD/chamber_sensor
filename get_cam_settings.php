<?php
date_default_timezone_set('Asia/Manila');
/**
 * get_cam_settings.php
 * Called by ESP32-CAM every 30 seconds to get latest camera settings.
 * Returns JSON with all camera quality and capture settings.
 */
include('includes/db_connect.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$ss = [];
$r = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($r) while ($row = $r->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];

echo json_encode([
    'success'          => true,
    'interval_sec'     => intval($ss['camera_interval_sec'] ?? 1800),
    'resolution'       => $ss['cam_resolution']  ?? 'VGA',
    'quality'          => intval($ss['cam_quality']    ?? 12),
    'brightness'       => intval($ss['cam_brightness'] ?? 1),
    'contrast'         => intval($ss['cam_contrast']   ?? 1),
    'saturation'       => intval($ss['cam_saturation'] ?? 0),
    'sharpness'        => intval($ss['cam_sharpness']  ?? 0),
    'wb_mode'          => intval($ss['cam_wb_mode']    ?? 0),
    'flash'            => intval($ss['cam_flash']      ?? 1),
    'vflip'            => intval($ss['cam_vflip']      ?? 0),
    'hmirror'          => intval($ss['cam_hmirror']    ?? 0),
]);

$conn->close();