<?php
if (getenv('MYSQLHOST')) {
    // ── RAILWAY (online) ──
    $servername = getenv('MYSQLHOST');
    $username   = getenv('MYSQLUSER');
    $password   = getenv('MYSQLPASSWORD');
    $dbname     = getenv('MYSQLDATABASE');
    $port       = getenv('MYSQLPORT') ?: 3306;
} else {
    // ── LOCALHOST (local testing) ──
    $servername = "localhost";
    $username   = "root";
    $password   = "";
    $dbname     = "mushroom_system";
    $port       = 3306;
}

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>