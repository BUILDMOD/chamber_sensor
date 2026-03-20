<?php
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: homepage.php");
    exit;
}

// ── Check if user still exists and is still verified in the database ──
include_once __DIR__ . '/db_connect.php';

$username = $_SESSION['user'];
$stmt = $conn->prepare("SELECT id, verified, role FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // User was deleted — destroy session and redirect
    session_unset();
    session_destroy();
    header("Location: homepage.php");
    exit;
}

$userRow = $result->fetch_assoc();

// Also kick out staff whose verification was revoked
if ($userRow['role'] !== 'owner' && $userRow['verified'] != 1) {
    session_unset();
    session_destroy();
    header("Location: homepage.php");
    exit;
}
?>