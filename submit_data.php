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

    // Check for alerts
    $alert = false;
    $alert_message = "";
    if ($temperature < 22 || $temperature > 28) {
        $alert = true;
        $alert_message .= "Temperature: {$temperature}°C (out of range 22-28°C). ";
    }
    if ($humidity < 85 || $humidity > 95) {
        $alert = true;
        $alert_message .= "Humidity: {$humidity}% (out of range 85-95%). ";
    }

    if ($alert) {
        // Fetch the owner's email from the database
        $owner_query = $conn->prepare("SELECT email FROM users WHERE role = 'owner' LIMIT 1");
        $owner_query->execute();
        $owner_result = $owner_query->get_result();
        $recipient = 'angelodominguiano12345@gmail.com'; // default fallback
        if ($owner_result->num_rows > 0) {
            $owner_row = $owner_result->fetch_assoc();
            $recipient = $owner_row['email'];
        }
        $owner_query->close();

        // Check throttling: send only if last email was more than 1 hour ago
        $throttle_query = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email = ?");
        $throttle_query->bind_param("s", $recipient);
        $throttle_query->execute();
        $throttle_result = $throttle_query->get_result();
        $should_send = true;

        if ($throttle_result->num_rows > 0) {
            $row = $throttle_result->fetch_assoc();
            $last_sent = strtotime($row['last_sent']);
            $now = time();
            if (($now - $last_sent) < 3600) { 
                $should_send = false;
            }
        }

        if ($should_send) {
            include 'send_email.php';
            $subject = "Mushroom System Alert";
            $body = "Alert triggered at {$timestamp}.\n{$alert_message}";
            $result = sendEmail($recipient, $subject, $body);

            // Update last_sent
            $update_stmt = $conn->prepare("INSERT INTO email_throttle (email, last_sent) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_sent = ?");
            $update_stmt->bind_param("sss", $recipient, $timestamp, $timestamp);
            $update_stmt->execute();
            $update_stmt->close();
        }

        $throttle_query->close();
    }
 } else {
    echo "Database Error: " . $conn->error;
}

$stmt->close();
$conn->close();
?>
