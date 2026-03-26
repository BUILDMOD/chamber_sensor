<?php
/**
 * Test script to verify schedule_runner.php works correctly
 */

echo "Testing MushroomOS Schedule Runner...\n\n";

// Include the schedule runner
include('schedule_runner.php');

echo "Schedule runner executed successfully!\n";

// Check current system state
include('includes/db_connect.php');

echo "\n=== Current System Status ===\n";

// Check system mode
$modeRow = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
echo "System Mode: " . ($modeRow['mode'] ?? 'unknown') . "\n";

// Check sprayer schedules
$schedules = [];
$r = $conn->query("SELECT * FROM scheduled_tasks WHERE device='sprayer' AND enabled=1 ORDER BY run_time");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $schedules[] = $row;
    }
}

echo "\n=== Sprayer Schedules ===\n";
if (empty($schedules)) {
    echo "No active sprayer schedules found.\n";
} else {
    foreach ($schedules as $schedule) {
        echo "Time: {$schedule['run_time']}, Duration: {$schedule['duration_minutes']}m {$schedule['duration_seconds']}s, Days: {$schedule['days']}\n";
    }
}

// Check recent logs
echo "\n=== Recent Device Logs (Last 10) ===\n";
$logs = $conn->query("SELECT device, action, trigger_type, trigger_detail, logged_at FROM device_logs ORDER BY logged_at DESC LIMIT 10");
if ($logs) {
    while ($log = $logs->fetch_assoc()) {
        echo "{$log['logged_at']} - {$log['device']} {$log['action']} ({$log['trigger_type']}) - {$log['trigger_detail']}\n";
    }
}

// Check current time
echo "\n=== Current Time ===\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";
echo "Current day: " . date('l') . "\n";

$conn->close();
?>
