<?php
/**
 * check_automation_rules.php
 * Check current automation rules in database
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

// Get current automation rules
$result = $conn->query("SELECT * FROM automation_rules ORDER BY id");
$rules = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
}

// Get current device states
$deviceResult = $conn->query("SELECT device, status, controlled_by FROM device_state ORDER BY device");
$devices = [];
if ($deviceResult) {
    while ($row = $deviceResult->fetch_assoc()) {
        $devices[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'rules' => $rules,
    'devices' => $devices,
    'rules_count' => count($rules)
]);

$conn->close();
?>
