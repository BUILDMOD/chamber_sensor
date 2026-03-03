<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "mushroom_system";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create sensor_data table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS sensor_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT,
    humidity FLOAT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Create email_throttle table if not exists
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

// -------------------------------
// 2. ESP32 SENT DATA → INSERT INTO DATABASE
// -------------------------------

// Support both GET and POST
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

    // ── BUG 1 FIX: Read thresholds from alert_thresholds table (set in settings.php) ──
    // Ensure table exists with defaults
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

    // ── BUG 2 FIX: Ensure alert_logs table exists (read by logs.php) ──
    $conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_type ENUM('temperature','humidity','device','system') NOT NULL,
        severity ENUM('warning','critical','info') NOT NULL DEFAULT 'warning',
        message TEXT NOT NULL,
        value FLOAT NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Check for alerts using DB thresholds
    $alerts_triggered = [];

    if ($temp_enabled && ($temperature < $temp_min || $temperature > $temp_max)) {
        $severity = (abs($temperature - $temp_min) > 5 || abs($temperature - $temp_max) > 5) ? 'critical' : 'warning';
        $msg = "Temperature {$temperature}°C is out of range ({$temp_min}–{$temp_max}°C).";
        $alerts_triggered[] = ['type' => 'temperature', 'severity' => $severity, 'message' => $msg, 'value' => $temperature];
    }

    if ($hum_enabled && ($humidity < $hum_min || $humidity > $hum_max)) {
        $severity = (abs($humidity - $hum_min) > 10 || abs($humidity - $hum_max) > 10) ? 'critical' : 'warning';
        $msg = "Humidity {$humidity}% is out of range ({$hum_min}–{$hum_max}%).";
        $alerts_triggered[] = ['type' => 'humidity', 'severity' => $severity, 'message' => $msg, 'value' => $humidity];
    }

    // Write each alert to alert_logs (for logs.php)
    foreach ($alerts_triggered as $al) {
        $als = $conn->prepare("INSERT INTO alert_logs (alert_type, severity, message, value) VALUES (?,?,?,?)");
        if ($als) {
            $als->bind_param("sssd", $al['type'], $al['severity'], $al['message'], $al['value']);
            $als->execute();
            $als->close();
        }
    }

    // Send email if any alerts triggered
    if (!empty($alerts_triggered)) {
        $alert_message = implode(' ', array_column($alerts_triggered, 'message'));

        // Read notification settings from DB
        $ns = [];
        $conn->query("CREATE TABLE IF NOT EXISTS notification_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(60) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        $nsr = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
        if ($nsr) while ($row = $nsr->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];

        // Respect per-type notification toggles from settings.php
        $should_notify = false;
        foreach ($alerts_triggered as $al) {
            if ($al['type'] === 'temperature' && ($ns['notify_temp'] ?? '1') === '1') $should_notify = true;
            if ($al['type'] === 'humidity'    && ($ns['notify_hum']  ?? '1') === '1') $should_notify = true;
        }

        if ($should_notify) {
            // Use cooldown from settings (default 60 min)
            $cooldown_min = intval($ns['notify_cooldown_min'] ?? 60);

            // Fetch owner email
            $owner_query = $conn->prepare("SELECT email FROM users WHERE role = 'owner' LIMIT 1");
            $owner_query->execute();
            $owner_result = $owner_query->get_result();
            $recipient = $ns['smtp_to_email'] ?? 'angelodominguiano12345@gmail.com';
            if ($owner_result->num_rows > 0) $recipient = $owner_result->fetch_assoc()['email'];
            $owner_query->close();

            // Check throttle using cooldown from settings
            $throttle_query = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email = ?");
            $throttle_query->bind_param("s", $recipient);
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
                $update_stmt->bind_param("sss", $recipient, $timestamp, $timestamp);
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