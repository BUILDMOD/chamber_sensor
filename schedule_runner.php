<?php
/**
 * schedule_runner.php — Sprayer Schedule Runner
 * -----------------------------------------------
 * Called via JS polling from automation.php every 30 seconds.
 *
 * FIRING LOGIC:
 *   - Fires a schedule if run_time is within a ±30 second window of NOW()
 *   - Prevents double-fire: skips if last_run is already within the same
 *     minute as run_time today
 *
 * LOGGING:
 *   - ON  logged_at = exact run_time today  (e.g. 09:30:00)
 *   - OFF logged_at = run_time + duration   (e.g. 09:30:20 for 20sec spray)
 */

date_default_timezone_set('Asia/Manila');

if (php_sapi_name() !== 'cli') {
    include_once __DIR__ . '/includes/db_connect.php';
} else {
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'mushroom_system';
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        error_log('[schedule_runner] DB connect failed: ' . $conn->connect_error);
        exit(1);
    }
    $conn->set_charset('utf8mb4');
}

$now         = new DateTime('now', new DateTimeZone('Asia/Manila'));
$today_date  = $now->format('Y-m-d');
$today_dow   = strtolower($now->format('l'));

$is_weekday  = in_array($today_dow, ['monday','tuesday','wednesday','thursday','friday']);
$is_weekend  = in_array($today_dow, ['saturday','sunday']);

// ── Fetch enabled schedules whose run_time falls within NOW() ± 30 seconds
//    AND has not already fired today at that run_time minute
// ─────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, device, run_time, duration_minutes, duration_seconds, days, last_run
    FROM scheduled_tasks
    WHERE enabled = 1
      AND ABS(TIMESTAMPDIFF(SECOND,
            CONCAT(CURDATE(), ' ', run_time),
            NOW()
          )) <= 30
      AND (
          last_run IS NULL
          OR DATE_FORMAT(last_run, '%Y-%m-%d %H:%i')
             <> DATE_FORMAT(CONCAT(CURDATE(), ' ', run_time), '%Y-%m-%d %H:%i')
      )
");
$stmt->execute();
$result = $stmt->get_result();
$due = [];
while ($row = $result->fetch_assoc()) {
    $due[] = $row;
}
$stmt->close();

if (empty($due)) {
    header('Content-Type: application/json');
    echo json_encode(['fired' => 0]);
    exit(0);
}

$fired = 0;
foreach ($due as $task) {
    $tid     = (int)$task['id'];
    $device  = $task['device'];
    $dur_min = (int)($task['duration_minutes'] ?? 0);
    $dur_sec = (int)($task['duration_seconds'] ?? 0);
    $days    = $task['days'];
    $run_time = $task['run_time']; // e.g. "09:30:00"

    // Day-of-week filter
    if ($days === 'weekdays' && !$is_weekday) continue;
    if ($days === 'weekends' && !$is_weekend) continue;

    $total_seconds = ($dur_min * 60) + $dur_sec;
    if ($total_seconds <= 0) $total_seconds = 30; // fallback

    // ── Exact ON timestamp = today + run_time (e.g. 2026-03-27 09:30:00)
    $on_datetime  = $today_date . ' ' . $run_time;

    // ── Exact OFF timestamp = ON + duration seconds (e.g. 2026-03-27 09:30:20)
    $on_dt_obj    = new DateTime($on_datetime, new DateTimeZone('Asia/Manila'));
    $off_dt_obj   = clone $on_dt_obj;
    $off_dt_obj->modify("+{$total_seconds} seconds");
    $off_datetime = $off_dt_obj->format('Y-m-d H:i:s'); // e.g. "2026-03-27 09:30:20"

    // Build human-readable duration label
    $dur_label = '';
    if ($dur_min > 0 && $dur_sec > 0) $dur_label = "{$dur_min}m {$dur_sec}s";
    elseif ($dur_min > 0)              $dur_label = "{$dur_min}m";
    else                               $dur_label = "{$dur_sec}s";

    $detail_on  = "Server schedule: Sprayer ON for {$dur_label}";
    $detail_off = "Server schedule: Duration completed ({$dur_label})";

    // ── Log ON — logged_at = exact run_time
    $s = $conn->prepare("
        INSERT INTO device_logs
            (device, action, trigger_type, trigger_detail, duration_seconds, logged_at)
        VALUES (?, 'ON', 'schedule', ?, NULL, ?)
    ");
    $s->bind_param('sss', $device, $detail_on, $on_datetime);
    $s->execute();
    $s->close();

    // ── Log OFF — logged_at = run_time + duration (correct end time)
    $s = $conn->prepare("
        INSERT INTO device_logs
            (device, action, trigger_type, trigger_detail, duration_seconds, logged_at)
        VALUES (?, 'OFF', 'schedule', ?, ?, ?)
    ");
    $s->bind_param('ssis', $device, $detail_off, $total_seconds, $off_datetime);
    $s->execute();
    $s->close();

    // ── Mark last_run so it won't double-fire this minute
    $s = $conn->prepare("UPDATE scheduled_tasks SET last_run = ? WHERE id = ?");
    $s->bind_param('si', $on_datetime, $tid);
    $s->execute();
    $s->close();

    error_log("[schedule_runner] Fired #{$tid}: {$device} ON={$on_datetime} OFF={$off_datetime} ({$dur_label})");
    $fired++;
}

$conn->close();

header('Content-Type: application/json');
echo json_encode(['fired' => $fired]);
exit(0);