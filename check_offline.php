<?php
/**
 * check_offline.php
 * Original file for checking system offline status
 */
date_default_timezone_set('Asia/Manila');
include 'includes/db_connect.php';

// Ensure ENUM is consistent
$conn->query("ALTER TABLE alert_logs MODIFY COLUMN alert_type ENUM('temperature','humidity','system') NOT NULL");

// Get latest sensor reading
$result = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
if ($result && $result->num_rows > 0) {
    $latest = $result->fetch_assoc();
    $age_minutes = round((time() - strtotime($latest['timestamp'])) / 60);
    
    // If offline for more than 2 minutes, check if we need to send alert
    if ($age_minutes > 2) {
        // Check if there's already an unresolved offline alert
        $existing_alert = $conn->query("SELECT id FROM alert_logs 
            WHERE alert_type='system' 
            AND resolved=0 
            AND (message LIKE '%offline%' OR message LIKE '%Sensor offline%')
            ORDER BY id DESC LIMIT 1");
        
        if (!$existing_alert || $existing_alert->num_rows === 0) {
            // Create new offline alert
            $message = "Sensor offline — no data received for {$age_minutes} minute(s)";
            $stmt = $conn->prepare("INSERT INTO alert_logs (alert_type, severity, message) VALUES (?, ?, ?)");
            if ($stmt) {
                $alert_type = 'system';
                $severity = 'critical';
                $stmt->bind_param("sss", $alert_type, $severity, $message);
                $stmt->execute();
                $stmt->close();
            }
            
            // Send alert email to ALL verified users (owner + all staff)
            include_once 'send_email.php';
            $all_recipients = [];
            $all_users_q = $conn->query("SELECT email FROM users WHERE verified=1 AND email != ''");
            if ($all_users_q) while ($eu = $all_users_q->fetch_assoc()) $all_recipients[] = $eu['email'];

            if (!empty($all_recipients)) {
                $subject = "⚠️ MushroomOS — System Offline";
                $body = "
                    <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                        <div style='background:#d32f2f;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                            <h2 style='color:#ffffff;margin:0;font-size:20px;'>&#127812; MushroomOS — System Offline</h2>
                            <p style='color:rgba(255,255,255,0.8);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                        </div>
                        <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                            <p style='background:#ffebee;border-left:4px solid #d32f2f;padding:12px 16px;border-radius:4px;color:#d32f2f;font-weight:600;margin:0 0 16px;'>
                                &#9888; System is offline — no sensor data received for {$age_minutes} minutes.
                            </p>
                            <p style='color:#555;font-size:13px;'>Last known readings:</p>
                            <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'>
                                <tr><td style='padding:8px 12px;color:#6e7681;'>Temperature</td><td style='padding:8px 12px;font-weight:600;'>{$latest['temperature']}°C</td></tr>
                                <tr><td style='padding:8px 12px;color:#6e7681;'>Humidity</td><td style='padding:8px 12px;font-weight:600;'>{$latest['humidity']}%</td></tr>
                                <tr><td style='padding:8px 12px;color:#6e7681;'>Last Data</td><td style='padding:8px 12px;font-weight:600;'>" . date('M j, Y h:i:s A', strtotime($latest['timestamp'])) . "</td></tr>
                            </table>
                            <p style='color:#555;font-size:13px;'>Please check your ESP32 device and connection.</p>
                            <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                            <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>
                        </div>
                    </div>";
                foreach ($all_recipients as $r) { sendEmail($r, $subject, $body); }
            }
        }
    }
}

$conn->close();
?>