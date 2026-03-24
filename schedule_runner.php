<?php
/**
 * schedule_runner.php
 * Server-side schedule runner for sprayer tasks
 * Runs via cron every minute to execute scheduled sprayer operations
 * Provides backup when ESP32 is offline
 */

include('includes/db_connect.php');
date_default_timezone_set('Asia/Manila');

// Bootstrap device state table
$conn->query("CREATE TABLE IF NOT EXISTS device_state (
    device       VARCHAR(20)  NOT NULL PRIMARY KEY,
    status       ENUM('on','off') NOT NULL DEFAULT 'off',
    controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
    locked_until INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Create device_logs table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'auto',
    trigger_detail VARCHAR(200),
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function logDevice($conn, $device, $action, $triggerType, $detail) {
    $stmt = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES (?,?,?,?)");
    if ($stmt) { 
        $stmt->bind_param("ssss",$device,$action,$triggerType,$detail); 
        $stmt->execute(); 
        $stmt->close(); 
    }
}

function isLocked($conn, $device) {
    $r = $conn->query("SELECT locked_until FROM device_state WHERE device='$device' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        return (int)($row['locked_until'] ?? 0) > time();
    }
    return false;
}

function deviceOn($conn, $device, $by, $lockSeconds = 0) {
    $lockUntil = $lockSeconds > 0 ? (time() + $lockSeconds) : 0;
    $conn->query("UPDATE device_state
                  SET status='on', controlled_by='$by', locked_until=$lockUntil
                  WHERE device='$device'");
    logDevice($conn, $device, 'ON', $by, "Server schedule: Turned ON by $by" . ($lockSeconds > 0 ? " (locked {$lockSeconds}s)" : ""));
}

function deviceOff($conn, $device, $by, $detail = '') {
    $conn->query("UPDATE device_state
                  SET status='off', controlled_by='$by', locked_until=0
                  WHERE device='$device'");
    logDevice($conn, $device, 'OFF', $by, $detail ?: "Server schedule: Turned OFF by $by");
}

// Get current time and day
$now = new DateTime();
$currentDay = strtolower($now->format('l')); // monday, tuesday, etc.
$currentTime = $now->format('H:i:s');
$currentWeekday = $now->format('N'); // 1=Monday, 7=Sunday

// Get system mode
$modeRow = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
$manualMode = ($modeRow['mode'] ?? 'auto') === 'manual';

// Only run schedules if not in manual mode
if ($manualMode) {
    echo "System in manual mode - skipping schedule execution\n";
    exit;
}

// Get active sprayer schedules
$schedules = [];
$r = $conn->query("SELECT * FROM scheduled_tasks WHERE device='sprayer' AND enabled=1 ORDER BY run_time");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $schedules[] = $row;
    }
}

// Check each schedule
foreach ($schedules as $schedule) {
    $runTime = $schedule['run_time']; // HH:MM:SS format
    $days = $schedule['days']; // daily, weekdays, weekends
    $durationMin = intval($schedule['duration_minutes'] ?? 0);
    $durationSec = intval($schedule['duration_seconds'] ?? 30);
    $totalDuration = ($durationMin * 60) + $durationSec;
    
    // Check if this schedule should run today
    $shouldRunToday = false;
    if ($days === 'daily') {
        $shouldRunToday = true;
    } elseif ($days === 'weekdays' && $currentWeekday >= 1 && $currentWeekday <= 5) {
        $shouldRunToday = true;
    } elseif ($days === 'weekends' && ($currentWeekday == 6 || $currentWeekday == 7)) {
        $shouldRunToday = true;
    }
    
    if (!$shouldRunToday) {
        continue; // Skip if not scheduled for today
    }
    
    // Check if it's time to run (within the same minute)
    $scheduleHour = substr($runTime, 0, 2);
    $scheduleMinute = substr($runTime, 3, 2);
    $currentHour = $now->format('H');
    $currentMinute = $now->format('i');
    
    if ($scheduleHour == $currentHour && $scheduleMinute == $currentMinute) {
        // Check if sprayer is already locked (already running)
        if (isLocked($conn, 'sprayer')) {
            echo "Sprayer already running/locked - skipping schedule\n";
            continue;
        }
        
        // Get current sprayer state
        $sprayerState = $conn->query("SELECT status, controlled_by FROM device_state WHERE device='sprayer' LIMIT 1")->fetch_assoc();
        
        // Only turn ON if currently OFF and not manually controlled
        if ($sprayerState && $sprayerState['status'] === 'off' && $sprayerState['controlled_by'] !== 'manual') {
            // Turn ON sprayer with duration lock
            deviceOn($conn, 'sprayer', 'schedule', $totalDuration);
            echo "Sprayer turned ON by schedule at $currentTime for {$totalDuration}s\n";
            
            // Schedule OFF after duration (we'll handle this in the next cycle)
            // The lock ensures no other process interferes
        }
    }
}

// Check for sprayers that need to be turned OFF (duration expired)
$r = $conn->query("SELECT device, locked_until FROM device_state WHERE device='sprayer' AND status='on' AND controlled_by='schedule'");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $lockedUntil = (int)($row['locked_until'] ?? 0);
        if ($lockedUntil > 0 && $lockedUntil <= time()) {
            // Duration expired, turn OFF
            deviceOff($conn, 'sprayer', 'schedule', 'Server schedule: Duration completed');
            echo "Sprayer turned OFF by schedule - duration completed\n";
        }
    }
}

$conn->close();
?>
