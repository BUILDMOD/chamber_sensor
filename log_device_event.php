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
 *   duration = seconds (for OFF — used to compute correct logged_at end time)
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

date_default_timezone_set('Asia/Manila');

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

// ── Compute logged_at ──
// ON  → use NOW() (exact moment ESP32 fires the relay)
// OFF → use the ON logged_at + duration so the end time is correct
//       e.g. ON at 09:30:00 + 20s = OFF at 09:30:20
$logged_at = date('Y-m-d H:i:s'); // default = now

if ($action === 'OFF' && $duration !== null && $duration > 0) {
    // Find the most recent ON log for this device today
    $r = $conn->prepare("
        SELECT logged_at FROM device_logs
        WHERE device = ? AND action = 'ON' AND trigger_type = 'schedule'
          AND DATE(logged_at) = CURDATE()
        ORDER BY logged_at DESC LIMIT 1
    ");
    if ($r) {
        $r->bind_param('s', $device);
        $r->execute();
        $res = $r->get_result();
        if ($row = $res->fetch_assoc()) {
            $on_dt = new DateTime($row['logged_at'], new DateTimeZone('Asia/Manila'));
            $on_dt->modify("+{$duration} seconds");
            $logged_at = $on_dt->format('Y-m-d H:i:s');
        }
        $r->close();
    }
}

// ── Update device_state ──
$status       = ($action === 'ON') ? 'on' : 'off';
$locked_until = 0;
if ($action === 'ON' && $duration) {
    $locked_until = time() + $duration;
}
$conn->query("INSERT INTO device_state (device, status, controlled_by, locked_until)
    VALUES ('{$device}', '{$status}', '{$trigger}', {$locked_until})
    ON DUPLICATE KEY UPDATE status='{$status}', controlled_by='{$trigger}', locked_until={$locked_until}");

// ── Insert into device_logs with correct logged_at ──
$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'manual',
    trigger_detail VARCHAR(200),
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$dur_for_log = ($action === 'OFF') ? $duration : null;

$stmt = $conn->prepare(
    "INSERT INTO device_logs (device, action, trigger_type, trigger_detail, duration_seconds, logged_at)
     VALUES (?, ?, ?, ?, ?, ?)"
);
if ($stmt) {
    $stmt->bind_param("ssssss", $device, $action, $trigger, $detail, $dur_for_log, $logged_at);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'logged' => "$device $action at $logged_at"]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
?>