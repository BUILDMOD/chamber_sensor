<?php
date_default_timezone_set('Asia/Manila');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "mushroom_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE TABLE IF NOT EXISTS sensor_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT,
    humidity FLOAT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS email_throttle (
    email VARCHAR(255) PRIMARY KEY,
    last_sent DATETIME
)");


if (
    !isset($_POST['temperature']) &&
    !isset($_POST['humidity']) &&
    !isset($_GET['temperature']) &&
    !isset($_GET['humidity'])
) {
    header('Content-Type: application/json');

    $result = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data WHERE temperature IS NOT NULL AND humidity IS NOT NULL ORDER BY id DESC LIMIT 1");

    if ($result && $result->num_rows > 0) {
        echo json_encode($result->fetch_assoc());
    } else {
        echo json_encode([
            "temperature" => null,
            "humidity" => null,
            "timestamp" => "No data"
        ]);
    }
    exit;
}

$temperature = $_POST['temperature'] ?? $_GET['temperature'] ?? null;
$humidity    = $_POST['humidity'] ?? $_GET['humidity'] ?? null;

if ($temperature === null || $humidity === null) {
    echo "Missing parameters";
    exit;
}

$timestamp = date("Y-m-d H:i:s");

$stmt = $conn->prepare("INSERT INTO sensor_data (temperature, humidity, timestamp) VALUES (?, ?, ?)");
$stmt->bind_param("dds", $temperature, $humidity, $timestamp);

if ($stmt->execute()) {
    echo "Success";

    require_once 'auto_engine.php';
    runAutoEngine($conn, floatval($temperature), floatval($humidity), $timestamp);

    $conn->query("CREATE TABLE IF NOT EXISTS alert_thresholds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        metric VARCHAR(30) NOT NULL UNIQUE,
        min_value FLOAT NOT NULL,
        max_value FLOAT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->query("INSERT IGNORE INTO alert_thresholds (metric,min_value,max_value) VALUES ('temperature',22,28),('humidity',85,95)");

    $thr = [];
    $tr = $conn->query("SELECT metric, min_value, max_value, enabled FROM alert_thresholds");
    if ($tr) while ($row = $tr->fetch_assoc()) $thr[$row['metric']] = $row;

    $temp_min = $thr['temperature']['min_value'] ?? 22;
    $temp_max = $thr['temperature']['max_value'] ?? 28;
    $hum_min  = $thr['humidity']['min_value']    ?? 85;
    $hum_max  = $thr['humidity']['max_value']    ?? 95;
    $temp_enabled = $thr['temperature']['enabled'] ?? 1;
    $hum_enabled  = $thr['humidity']['enabled']    ?? 1;

    $conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_type ENUM('temperature','humidity','device','system') NOT NULL,
        severity ENUM('warning','critical','info') NOT NULL DEFAULT 'warning',
        message TEXT NOT NULL,
        value FLOAT NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── ALERT DEDUPLICATION ──
    $alerts_triggered = [];

    // Auto-resolve when back in range
    if ($temp_enabled && $temperature >= $temp_min && $temperature <= $temp_max) {
        $conn->query("UPDATE alert_logs SET resolved=1 WHERE alert_type='temperature' AND resolved=0");
    }
    if ($hum_enabled && $humidity >= $hum_min && $humidity <= $hum_max) {
        $conn->query("UPDATE alert_logs SET resolved=1 WHERE alert_type='humidity' AND resolved=0");
    }

    // Temperature — only log if no existing open alert OR value changed >=2°C
    if ($temp_enabled && ($temperature < $temp_min || $temperature > $temp_max)) {
        $severity = (abs($temperature - $temp_min) > 5 || abs($temperature - $temp_max) > 5) ? 'critical' : 'warning';
        $msg = "Temperature {$temperature}°C is out of range ({$temp_min}–{$temp_max}°C).";
        $chk = $conn->prepare("SELECT id, value FROM alert_logs WHERE alert_type='temperature' AND resolved=0 ORDER BY id DESC LIMIT 1");
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing || abs(floatval($existing['value']) - floatval($temperature)) >= 2) {
            $alerts_triggered[] = ['type' => 'temperature', 'severity' => $severity, 'message' => $msg, 'value' => $temperature];
        }
    }

    // Humidity — only log if no existing open alert OR value changed >=5%
    if ($hum_enabled && ($humidity < $hum_min || $humidity > $hum_max)) {
        $severity = (abs($humidity - $hum_min) > 10 || abs($humidity - $hum_max) > 10) ? 'critical' : 'warning';
        $msg = "Humidity {$humidity}% is out of range ({$hum_min}–{$hum_max}%).";
        $chk = $conn->prepare("SELECT id, value FROM alert_logs WHERE alert_type='humidity' AND resolved=0 ORDER BY id DESC LIMIT 1");
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing || abs(floatval($existing['value']) - floatval($humidity)) >= 5) {
            $alerts_triggered[] = ['type' => 'humidity', 'severity' => $severity, 'message' => $msg, 'value' => $humidity];
        }
    }

    foreach ($alerts_triggered as $al) {
        $als = $conn->prepare("INSERT INTO alert_logs (alert_type, severity, message, value) VALUES (?,?,?,?)");
        if ($als) {
            $als->bind_param("sssd", $al['type'], $al['severity'], $al['message'], $al['value']);
            $als->execute();
            $als->close();
        }
    }

    if (!empty($alerts_triggered)) {
        $alert_message = implode(' ', array_column($alerts_triggered, 'message'));

        // Read from BOTH tables — system_settings has notify prefs, notification_settings has SMTP
        $ns = []; $ss_notif = [];
        $conn->query("CREATE TABLE IF NOT EXISTS notification_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(60) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $nsr = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
        if ($nsr) while ($row = $nsr->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
        $ssr = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($ssr) while ($row = $ssr->fetch_assoc()) $ss_notif[$row['setting_key']] = $row['setting_value'];

        $should_notify = false;
        foreach ($alerts_triggered as $al) {
            if ($al['type'] === 'temperature' && ($ss_notif['notify_temp']      ?? '1') === '1') $should_notify = true;
            if ($al['type'] === 'humidity'    && ($ss_notif['notify_hum']       ?? '1') === '1') $should_notify = true;
            if ($al['type'] === 'device'      && ($ss_notif['notify_emergency'] ?? '1') === '1') $should_notify = true;
            if ($al['type'] === 'system'      && ($ss_notif['notify_offline']   ?? '1') === '1') $should_notify = true;
        }

        if ($should_notify) {
            $cooldown_min = intval($ss_notif['notify_cooldown_min'] ?? $ns['notify_cooldown_min'] ?? 60);
            $recipient = '';
            @session_start();
            if (!empty($_SESSION['user'])) {
                $uq = $conn->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
                $uq->bind_param("s", $_SESSION['user']);
                $uq->execute();
                $ur = $uq->get_result();
                if ($ur->num_rows > 0) $recipient = $ur->fetch_assoc()['email'];
                $uq->close();
            }
            if (empty($recipient)) {
                $oq = $conn->prepare("SELECT email FROM users WHERE role = 'owner' LIMIT 1");
                $oq->execute();
                $or2 = $oq->get_result();
                if ($or2->num_rows > 0) $recipient = $or2->fetch_assoc()['email'];
                $oq->close();
            }
            if (empty($recipient)) $recipient = $ns['smtp_to_email'] ?? '';

            // Per-type throttle key — prevents one alert type from blocking another
            $alert_types_in = array_unique(array_column($alerts_triggered, 'type'));
            $throttle_key = implode('_', $alert_types_in) . '_' . $recipient;

            $throttle_query = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email = ?");
            $throttle_query->bind_param("s", $throttle_key);
            $throttle_query->execute();
            $throttle_result = $throttle_query->get_result();
            $should_send = true;
            if ($throttle_result->num_rows > 0) {
                $last_sent = strtotime($throttle_result->fetch_assoc()['last_sent']);
                if ((time() - $last_sent) < ($cooldown_min * 60)) $should_send = false;
            }
            $throttle_query->close();

            if ($should_send) {
                include 'send_email.php';
                $subject = "⚠️ MushroomOS Alert";
                $body = "<b>Alert triggered at {$timestamp}</b><br><br>" . nl2br(htmlspecialchars($alert_message)) .
                        "<br><br><small>Thresholds: Temperature {$temp_min}–{$temp_max}°C · Humidity {$hum_min}–{$hum_max}%</small>";
                sendEmail($recipient, $subject, $body);
                $update_stmt = $conn->prepare("INSERT INTO email_throttle (email, last_sent) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_sent = ?");
                $update_stmt->bind_param("sss", $throttle_key, $timestamp, $timestamp);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
    }
} else {
    echo "Database Error: " . $conn->error;
}

$stmt->close();
$conn->close();
?>