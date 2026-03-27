<?php
/**
 * monitor_offline.php
 * Dedicated offline monitoring script
 * Called by automation.php every 30 seconds to check system status
 * and create/manage offline alerts properly
 */

date_default_timezone_set('Asia/Manila');
include_once __DIR__ . '/includes/db_connect.php';

// Get latest sensor reading
$latest_sensor = null;
$sensor_online = false;
$rs = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");

if ($rs && $rs->num_rows > 0) {
    $sr = $rs->fetch_assoc();
    $age_minutes = round((time() - strtotime($sr['timestamp'])) / 60);
    $sensor_online = ($age_minutes < 5);
    $latest_sensor = $sr;
    $latest_sensor['age_minutes'] = $age_minutes;
}

// Create tables if not exist
$conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('temperature','humidity','device','system') NOT NULL,
    severity ENUM('warning','critical','info') NOT NULL DEFAULT 'warning',
    message TEXT NOT NULL,
    value FLOAT NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// OFFLINE DETECTION AND ALERT CREATION
if (!$sensor_online) {
    // Check if there's already an unresolved offline alert in the last 5 minutes
    $existing_alert = $conn->query("SELECT id, logged_at FROM alert_logs 
        WHERE alert_type='system' 
        AND resolved=0 
        AND (message LIKE '%offline%' OR message LIKE '%Sensor offline%')
        AND logged_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY id DESC LIMIT 1");
    
    if (!$existing_alert || $existing_alert->num_rows === 0) {
        // Create new offline alert
        $age_text = $latest_sensor ? $latest_sensor['age_minutes'] . ' minute(s)' : 'unknown';
        $message = "Sensor offline — no data received for {$age_text}";
        $stmt = $conn->prepare("INSERT INTO alert_logs (alert_type, severity, message) VALUES (?, ?, ?)");
        if ($stmt) {
            $severity = 'critical';
            $alert_type = 'system';
            $stmt->bind_param("sss", $alert_type, $severity, $message);
            $stmt->execute();
            $stmt->close();
            
            error_log("[monitor_offline] Created offline alert: {$message}");
        }
        
        // Send email notification for system offline
        include_once 'send_email.php';
        
        // Get logged-in user email first, then fallback to owner
        $recipient = '';
        if (!empty($_SESSION['user'])) {
            $uq = $conn->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
            $uq->bind_param("s", $_SESSION['user']);
            $uq->execute();
            $ur = $uq->get_result();
            if ($ur->num_rows > 0) $recipient = $ur->fetch_assoc()['email'];
            $uq->close();
        }
        
        if (empty($recipient)) {
            // Fallback to most recently active user from system_logs
            $active_user_query = $conn->query("SELECT u.email FROM users u 
                JOIN system_logs sl ON u.username = sl.user 
                WHERE sl.event_type = 'login' 
                ORDER BY sl.logged_at DESC LIMIT 1");
            if ($active_user_query && $active_user_query->num_rows > 0) {
                $recipient = $active_user_query->fetch_assoc()['email'];
            }
            
            // Final fallback to owner
            if (empty($recipient)) {
                $oq = $conn->prepare("SELECT email FROM users WHERE role = 'owner' LIMIT 1");
                $oq->execute();
                $or2 = $oq->get_result();
                if ($or2->num_rows > 0) $recipient = $or2->fetch_assoc()['email'];
                $oq->close();
            }
        }
        
        if (!empty($recipient)) {
            $subject = "⚠️ MushroomOS — System Offline";
            $last_temp = $latest_sensor ? $latest_sensor['temperature'] : 'Unknown';
            $last_hum = $latest_sensor ? $latest_sensor['humidity'] : 'Unknown';
            $last_time = $latest_sensor ? date('M j, Y h:i:s A', strtotime($latest_sensor['timestamp'])) : 'Unknown';
            
            $body = "
                <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                    <div style='background:#d32f2f;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                        <h2 style='color:#ffffff;margin:0;font-size:20px;'>&#127812; MushroomOS — System Offline</h2>
                        <p style='color:rgba(255,255,255,0.8);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                    </div>
                    <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                        <p style='background:#ffebee;border-left:4px solid #d32f2f;padding:12px 16px;border-radius:4px;color:#d32f2f;font-weight:600;margin:0 0 16px;'>
                            &#9888; System is offline — no sensor data received for {$age_text}.
                        </p>
                        <p style='color:#555;font-size:13px;'>Last known readings:</p>
                        <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Temperature</td><td style='padding:8px 12px;font-weight:600;'>{$last_temp}°C</td></tr>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Humidity</td><td style='padding:8px 12px;font-weight:600;'>{$last_hum}%</td></tr>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Last Data</td><td style='padding:8px 12px;font-weight:600;'>{$last_time}</td></tr>
                        </table>
                        <p style='color:#555;font-size:13px;'>Please check your ESP32 device and connection.</p>
                        <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                        <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>
                    </div>
                </div>";
            
            $email_result = sendEmail($recipient, $subject, $body);
            error_log("[monitor_offline] Email result: {$email_result}");
        }
    }
} else {
    // Sensor is online - check if we should resolve offline alerts
    // Only resolve if sensor has been stable for at least 2 minutes with multiple readings
    if ($latest_sensor && $latest_sensor['age_minutes'] <= 2) {
        $recent_readings_count = 0;
        $recent_check = $conn->prepare("SELECT COUNT(*) as cnt FROM sensor_data 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
        if ($recent_check) {
            $recent_check->execute();
            $recent_result = $recent_check->get_result();
            if ($recent_result && $row = $recent_result->fetch_assoc()) {
                $recent_readings_count = intval($row['cnt']);
            }
            $recent_check->close();
        }
        
        // Only resolve if we have multiple recent readings (indicates stable connection)
        if ($recent_readings_count >= 2) {
            $resolve_result = $conn->query("UPDATE alert_logs
                SET resolved=1
                WHERE alert_type='system'
                  AND resolved=0
                  AND (message LIKE '%offline%' OR message LIKE '%Sensor offline%')");
            
            if ($resolve_result && $conn->affected_rows > 0) {
                error_log("[monitor_offline] Resolved {$conn->affected_rows} offline alerts - sensor stable with {$recent_readings_count} recent readings");
            }
        }
    }
}

// Return status for debugging
header('Content-Type: application/json');
echo json_encode([
    'sensor_online' => $sensor_online,
    'age_minutes' => $latest_sensor['age_minutes'] ?? null,
    'last_temp' => $latest_sensor['temperature'] ?? null,
    'last_humidity' => $latest_sensor['humidity'] ?? null,
    'timestamp' => $latest_sensor['timestamp'] ?? null
]);

$conn->close();
?>
