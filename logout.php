<?php
session_start();
include 'includes/db_connect.php';

date_default_timezone_set('Asia/Manila');

// ── Log the logout event BEFORE destroying the session ──
if (isset($_SESSION['user'])) {
    $conn->query("CREATE TABLE IF NOT EXISTS system_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(50) NOT NULL,
        description TEXT NOT NULL,
        user VARCHAR(100) NULL,
        ip_address VARCHAR(45) NULL,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $usr  = $_SESSION['user'];
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $evt  = 'logout';
    $desc = "User '{$usr}' logged out.";

    $ls = $conn->prepare("INSERT INTO system_logs (event_type, description, user, ip_address) VALUES (?,?,?,?)");
    if ($ls) {
        $ls->bind_param("ssss", $evt, $desc, $usr, $ip);
        $ls->execute();
        $ls->close();
    }
    $conn->close();
}

session_destroy();
header("Location: homepage.php");
exit;
?>