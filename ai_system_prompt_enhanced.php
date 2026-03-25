<?php
/**
 * MushroomOS AI Assistant — Enhanced System Prompt Generator
 * Include this file in every page that uses the AI chat bubble.
 */
function getAISystemPrompt($conn, $ss = []) {
    date_default_timezone_set('Asia/Manila');

    // ── Real-time sensor ──
    $current_temp = 'Sensor offline';
    $current_humidity = 'Sensor offline';
    $sensor_age_min = null;
    $r = $conn->query("SELECT temperature, humidity, logged_at FROM sensor_data ORDER BY logged_at DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $current_temp = $row['temperature'];
        $current_humidity = $row['humidity'];
        $sensor_age_min = round((time() - strtotime($row['logged_at'])) / 60);
    }
    $sensor_status = ($sensor_age_min !== null && $sensor_age_min < 5) ? 'Online' : 'Offline';

    // ── Active devices ──
    $active_devices = [];
    $r = $conn->query("SELECT device FROM device_state WHERE status='ON'");
    if ($r) while ($row = $r->fetch_assoc()) $active_devices[] = $row['device'];
    $active_devices_str = !empty($active_devices) ? implode(', ', $active_devices) : 'None';

    // ── Control mode ──
    $mode_str = 'Auto';
    $r = $conn->query("SELECT manual_mode FROM device_state LIMIT 1");
    if ($r && $row = $r->fetch_assoc() && isset($row['manual_mode'])) $mode_str = $row['manual_mode'] == '1' ? 'Manual' : 'Auto';

    // ── Recent alerts ──
    $alert_count = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt FROM device_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND trigger_type IN ('emergency','fault')");
    if ($r && $row = $r->fetch_assoc()) $alert_count = $row['cnt'];
    $alerts_str = $alert_count > 0 ? "$alert_count emergency/fault alerts in last 24 hours" : 'No recent alerts';

    // ── Active faults ──
    $active_faults = [];
    $r = $conn->query("SELECT device, fault_type, detail FROM device_faults WHERE resolved=0 ORDER BY logged_at DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc())
        $active_faults[] = ucfirst($row['device']) . ': ' . $row['fault_type'] . ' — ' . $row['detail'];
    $faults_str = !empty($active_faults) ? implode('; ', $active_faults) : 'None';

    // ── Logged-in user ──
    $logged_in_user = 'Unknown';
    $logged_in_role = 'staff';
    if (!empty($_SESSION['fullname'])) $logged_in_user = $_SESSION['fullname'];
    elseif (!empty($_SESSION['user'])) $logged_in_user = $_SESSION['user'];
    if (!empty($_SESSION['role'])) $logged_in_role = $_SESSION['role'];

    // ── All registered users ──
    $all_users = [];
    $r = $conn->query("SELECT fullname, username, role FROM users WHERE verified=1 ORDER BY role, fullname");
    if ($r) while ($row = $r->fetch_assoc())
        $all_users[] = $row['fullname'] . ' (@' . $row['username'] . ', ' . $row['role'] . ')';
    $users_list = !empty($all_users) ? implode('; ', $all_users) : 'No users found';

    $pending_count = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE verified=0");
    if ($r && $row = $r->fetch_assoc()) $pending_count = $row['cnt'];

    // ── Thresholds ──
    $thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
    $tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
    if ($tr) while ($row = $tr->fetch_assoc()) {
        if ($row['metric']==='temperature')    { $thr['temp_min']=$row['min_value']; $thr['temp_max']=$row['max_value']; }
        if ($row['metric']==='humidity')       { $thr['hum_min']=$row['min_value'];  $thr['hum_max']=$row['max_value']; }
        if ($row['metric']==='emergency_temp') { $thr['emerg_temp_low']=$row['min_value']; $thr['emerg_temp_high']=$row['max_value']; }
        if ($row['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$row['max_value']; }
    }

    // ── Chamber Health Score (current month) ──
    $health_score = null; $health_label = 'No Data';
    $rpt_from = date('Y-m-01'); $rpt_to = date('Y-m-d');
    $hr = $conn->query("SELECT COUNT(*) as total,
        SUM(CASE WHEN temperature BETWEEN {$thr['temp_min']} AND {$thr['temp_max']} AND humidity BETWEEN {$thr['hum_min']} AND {$thr['hum_max']} THEN 1 ELSE 0 END) as ideal
        FROM sensor_data WHERE DATE(logged_at) BETWEEN '$rpt_from' AND '$rpt_to'");
    if ($hr && $hrow = $hr->fetch_assoc() && $hrow['total'] > 0) {
        $health_score = round(($hrow['ideal'] / $hrow['total']) * 100, 1);
        $health_label = $health_score >= 80 ? 'Healthy' : ($health_score >= 50 ? 'Fair' : 'Poor');
    }
    $health_str = $health_score !== null ? "{$health_score}% ({$health_label}) for " . date('F Y') : 'No data this month';

    // ── Automation rules ──
    $rules = [];
    $r = $conn->query("SELECT device, sensor, operator, threshold, enabled FROM automation_rules ORDER BY device");
    if ($r) while ($row = $r->fetch_assoc()) {
        $unit = $row['sensor'] === 'temperature' ? '°C' : '%';
        $rules[] = "Turn {$row['device']} ON when {$row['sensor']} is {$row['operator']} {$row['threshold']}{$unit} [" . ($row['enabled'] ? 'Active' : 'Disabled') . "]";
    }
    $rules_str = !empty($rules) ? implode('; ', $rules) : 'No automation rules set';

    // ── Sprayer schedules ──
    $schedules = [];
    $r = $conn->query("SELECT run_time, duration_minutes, duration_seconds, days, enabled FROM scheduled_tasks ORDER BY run_time");
    if ($r) while ($row = $r->fetch_assoc()) {
        $dur = ($row['duration_minutes'] > 0 ? $row['duration_minutes'] . 'min ' : '') . ($row['duration_seconds'] > 0 ? $row['duration_seconds'] . 'sec' : '');
        $schedules[] = date('g:i A', strtotime($row['run_time'])) . " for {$dur}, {$row['days']} [" . ($row['enabled'] ? 'Active' : 'Disabled') . "]";
    }
    $schedules_str = !empty($schedules) ? implode('; ', $schedules) : 'No schedules set';

    // ── Recent harvest records ──
    $records = [];
    $r = $conn->query("SELECT record_date, mushroom_count, growth_stage FROM mushroom_records WHERE record_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY record_date DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc())
        $records[] = $row['record_date'] . ': ' . $row['mushroom_count'] . ' mushrooms (' . $row['growth_stage'] . ')';
    $records_str = !empty($records) ? implode('; ', $records) : 'No recent records';

    // ── System settings ──
    $fault_timeout  = $ss['fault_timeout_min']   ?? '5';
    $stuck_timeout  = $ss['stuck_timeout_min']   ?? '60';
    $cam_interval   = round(intval($ss['camera_interval_sec'] ?? 1800) / 60);
    $data_retention = $ss['data_retention_days'] ?? '90';

    // ── Unresolved alerts ──
    $unresolved_alerts = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt FROM alert_logs WHERE resolved=0");
    if ($r && $row = $r->fetch_assoc()) $unresolved_alerts = $row['cnt'];

    return "You are MushroomOS Assistant, an expert AI embedded inside MushroomOS — a smart mushroom cultivation monitoring system for J.WHO Mushroom Farm.

CURRENT USER SESSION:
- Logged In As: {$logged_in_user} (Role: {$logged_in_role})
- Current Time: " . date('M j, Y h:i A') . " (Asia/Manila)

REAL-TIME SENSOR DATA:
- Temperature: {$current_temp}°C
- Humidity: {$current_humidity}%
- Sensor Status: {$sensor_status}" . ($sensor_age_min !== null ? " (last reading {$sensor_age_min} min ago)" : "") . "
- Control Mode: {$mode_str} Mode
- Active Devices: {$active_devices_str}
- Active Faults: {$faults_str}
- Recent Emergency/Fault Alerts: {$alerts_str}
- Unresolved Alerts: {$unresolved_alerts}

CHAMBER HEALTH SCORE (Reports Page):
- Current Month Score: {$health_str}
- Formula: (Ideal Readings ÷ Total Readings) × 100
- A reading is 'ideal' ONLY when BOTH temperature AND humidity are simultaneously in range
- Score ≥ 80 = Healthy, 50–79 = Fair, < 50 = Poor
- The Temperature % and Humidity % sub-bars use daily AVERAGES separately — so they may show higher than overall score
- Example: 100 readings, 72 had both temp AND humidity in range → Health Score = 72%

IDEAL RANGES & THRESHOLDS:
- Temperature: {$thr['temp_min']}–{$thr['temp_max']}°C (ideal)
- Humidity: {$thr['hum_min']}–{$thr['hum_max']}% RH (ideal)
- Emergency OFF: Temp > {$thr['emerg_temp_high']}°C or < {$thr['emerg_temp_low']}°C; Humidity > {$thr['emerg_hum_high']}%

AUTOMATION RULES:
- {$rules_str}

SPRAYER SCHEDULES:
- {$schedules_str}

RECENT HARVEST RECORDS (Last 7 Days):
- {$records_str}

REGISTERED USERS:
- {$users_list}
- Pending Approval: {$pending_count} user(s)

SYSTEM CONFIGURATION:
- Fault Detection Timeout: {$fault_timeout} min
- Stuck-On Timeout: {$stuck_timeout} min
- Camera Capture Interval: {$cam_interval} min
- Data Retention: {$data_retention} days

MUSHROOMOS PAGES:
- Dashboard: Live gauges, device control, alerts, monthly harvest records, camera analysis
- Reports: Calendar heatmap, Chamber Health Score, sensor data table, day-over-day changes
- Automation: Automation rules, sprayer schedules, built-in protections, device activity log
- Logs: Alert history, system event log, auto-resolve logic
- Settings: Thresholds, email/SMTP, auto engine, camera, AI (Groq) API key
- System Profile: User profile, password change, add/manage staff, pending approvals

BUILT-IN PROTECTIONS (Always Active):
- Temp > {$thr['emerg_temp_high']}°C → Heater forced OFF (emergency)
- Humidity > {$thr['emerg_hum_high']}% → Mist + Sprayer forced OFF (emergency)
- Temp < {$thr['emerg_temp_low']}°C → Fan forced OFF (emergency)
- Sensor offline for {$fault_timeout} min while device ON → Device forced OFF + Buzzer (fault)
- Device ON for {$stuck_timeout}+ min → Device forced OFF + Buzzer (stuck-on)

IMPORTANT:
- When asked who is logged in: answer '{$logged_in_user}' with role '{$logged_in_role}'
- When asked about Chamber Health Score formula: explain using the details above with current score
- When asked about active devices: list '{$active_devices_str}'
- Be concise, practical, and friendly. Use short paragraphs
- Always use the real-time data above when answering current-status questions";
}
?>