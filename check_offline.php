<?php
/**
 * check_offline.php
 * Called by dashboard.php via fetch() every polling cycle.
 * Checks if sensor data is stale → sends offline email alert.
 */
session_start();
include 'includes/db_connect.php';

header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// ── 1. Get latest sensor timestamp ──
$row = null;
$r = $conn->query("SELECT timestamp FROM sensor_data ORDER BY timestamp DESC LIMIT 1");
if ($r) $row = $r->fetch_assoc();

$now        = time();
$offline    = true;
$age_sec    = 9999;

if ($row && !empty($row['timestamp'])) {
    $last_ts = strtotime($row['timestamp']);
    $age_sec = $now - $last_ts;
    $offline = ($age_sec > 120); // offline if no data for 2+ minutes
}

if (!$offline) {
    echo json_encode(['offline' => false, 'age_sec' => $age_sec]);
    exit;
}

// ── 2. Read notification settings ──
$ns = [];
$conn->query("CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(60) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$nsr = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
if ($nsr) while ($r2 = $nsr->fetch_assoc()) $ns[$r2['setting_key']] = $r2['setting_value'];

// ── 3. Check if offline notification is enabled ──
if (($ns['notify_offline'] ?? '1') !== '1') {
    echo json_encode(['offline' => true, 'email_sent' => false, 'reason' => 'notify_offline disabled']);
    exit;
}

// ── 4. Get recipient — use registered email of logged-in user ──
$recipient = '';
if (!empty($_SESSION['user'])) {
    $uq = $conn->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
    $uq->bind_param("s", $_SESSION['user']);
    $uq->execute();
    $ur = $uq->get_result();
    if ($ur->num_rows > 0) $recipient = $ur->fetch_assoc()['email'];
    $uq->close();
}
// fallback — owner's registered email
if (empty($recipient)) {
    $oq = $conn->prepare("SELECT email FROM users WHERE role='owner' LIMIT 1");
    $oq->execute();
    $or = $oq->get_result();
    if ($or->num_rows > 0) $recipient = $or->fetch_assoc()['email'];
    $oq->close();
}
if (empty($recipient)) {
    echo json_encode(['offline' => true, 'email_sent' => false, 'reason' => 'no recipient']);
    exit;
}
if (empty($recipient)) {
    echo json_encode(['offline' => true, 'email_sent' => false, 'reason' => 'no recipient']);
    exit;
}

// ── 5. Check throttle (cooldown) ──
$cooldown_min = intval($ns['notify_cooldown_min'] ?? 60);
$throttle_key = 'offline_' . $recipient; // separate throttle key for offline alerts

$conn->query("CREATE TABLE IF NOT EXISTS email_throttle (
    email VARCHAR(120) PRIMARY KEY,
    last_sent TIMESTAMP NOT NULL
)");

$tq = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email = ?");
$tq->bind_param("s", $throttle_key);
$tq->execute();
$tr = $tq->get_result();
$should_send = true;

if ($tr->num_rows > 0) {
    $last_sent = strtotime($tr->fetch_assoc()['last_sent']);
    if (($now - $last_sent) < ($cooldown_min * 60)) {
        $should_send = false;
    }
}
$tq->close();

if (!$should_send) {
    echo json_encode(['offline' => true, 'email_sent' => false, 'reason' => 'throttled']);
    exit;
}

// ── 6. Log alert to alert_logs ──
$conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('temperature','humidity','device','system') NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    message TEXT NOT NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$age_min = round($age_sec / 60);
$log_msg = "Sensor offline — no data received for {$age_min} minute(s).";
$conn->query("INSERT INTO alert_logs (alert_type, severity, message, resolved)
              VALUES ('system', 'critical', '" . $conn->real_escape_string($log_msg) . "', 0)");

// ── 7. Send email ──
include 'send_email.php';
$subject = "🔴 MushroomOS — Sensor Offline Alert";
$body = "
<div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;'>
  <div style='background:#1a2e1a;padding:18px 24px;border-radius:8px 8px 0 0;'>
    <h2 style='color:#fff;margin:0;font-size:18px;'>🍄 MushroomOS — Sensor Offline</h2>
  </div>
  <div style='background:#fff8f8;border:1px solid #fdd;padding:20px 24px;border-radius:0 0 8px 8px;'>
    <p style='color:#c00;font-weight:bold;font-size:15px;'>⚠️ The ESP32 sensor has gone offline.</p>
    <p style='color:#333;'>No sensor data has been received for <b>{$age_min} minute(s)</b>.</p>
    <table style='width:100%;border-collapse:collapse;margin-top:12px;font-size:13px;'>
      <tr style='background:#f5f5f5;'>
        <td style='padding:8px 12px;font-weight:bold;color:#555;'>Last Data Received</td>
        <td style='padding:8px 12px;color:#333;'>" . ($row ? date('M j, Y h:i A', strtotime($row['timestamp'])) : 'No data on record') . "</td>
      </tr>
      <tr>
        <td style='padding:8px 12px;font-weight:bold;color:#555;'>Time Offline</td>
        <td style='padding:8px 12px;color:#c00;'><b>{$age_min} minute(s)</b></td>
      </tr>
      <tr style='background:#f5f5f5;'>
        <td style='padding:8px 12px;font-weight:bold;color:#555;'>Detected At</td>
        <td style='padding:8px 12px;color:#333;'>" . date('M j, Y h:i A') . "</td>
      </tr>
    </table>
    <p style='color:#777;font-size:12px;margin-top:16px;'>Please check the ESP32 device and WiFi connection.<br>This alert was sent by MushroomOS.</p>
  </div>
</div>";

$sent = sendEmail($recipient, $subject, $body);

// ── 8. Update throttle ──
if ($sent) {
    $ts_now = date('Y-m-d H:i:s');
    $uq = $conn->prepare("INSERT INTO email_throttle (email, last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
    $uq->bind_param("sss", $throttle_key, $ts_now, $ts_now);
    $uq->execute();
    $uq->close();
}

echo json_encode([
    'offline'    => true,
    'age_sec'    => $age_sec,
    'age_min'    => $age_min,
    'email_sent' => $sent,
    'recipient'  => $recipient
]);