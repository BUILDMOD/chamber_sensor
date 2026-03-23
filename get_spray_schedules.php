<?php
/**
 * get_spray_schedules.php
 * Returns sprayer schedules for ESP32 local schedule execution.
 * ESP32 uses NTP time and fires sprayer exactly at scheduled time.
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

$schedules = [];

$r = $conn->query("SELECT run_time, duration_minutes, duration_seconds FROM scheduled_tasks WHERE device='sprayer' AND enabled=1 ORDER BY run_time ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        // Convert run_time (HH:MM:SS) to seconds since midnight
        $parts = explode(':', $row['run_time']);
        $timeOfDay = (intval($parts[0]) * 3600) + (intval($parts[1]) * 60) + intval($parts[2] ?? 0);

        // Total duration in seconds
        $durMin = intval($row['duration_minutes'] ?? 0);
        $durSec = intval($row['duration_seconds'] ?? 30);
        $totalSec = ($durMin * 60) + $durSec;
        if ($totalSec <= 0) $totalSec = 30; // fallback

        $schedules[] = [
            'time_of_day'  => $timeOfDay,
            'duration_sec' => $totalSec,
            'run_time'     => $row['run_time'],
        ];
    }
}

echo json_encode([
    'success'   => true,
    'count'     => count($schedules),
    'schedules' => $schedules,
]);

$conn->close();
?>