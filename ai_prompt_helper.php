<?php
function getAISystemPrompt($conn, $ss = []) {
    date_default_timezone_set('Asia/Manila');

    // Enhanced real-time sensor data with more detailed analysis
    $current_temp = 'Sensor offline';
    $current_humidity = 'Sensor offline';
    $sensor_age_min = null;
    $temp_trend = 'stable';
    $humidity_trend = 'stable';
    
    // Get current reading
    $r = $conn->query("SELECT temperature, humidity, logged_at FROM sensor_data ORDER BY logged_at DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $current_temp = $row['temperature'];
        $current_humidity = $row['humidity'];
        $sensor_age_min = round((time() - strtotime($row['logged_at'])) / 60);
    }
    
    // Calculate trends from last 5 readings
    $r_trend = $conn->query("SELECT temperature, humidity FROM sensor_data ORDER BY logged_at DESC LIMIT 5");
    if ($r_trend && $r_trend->num_rows >= 3) {
        $temps = [];
        $hums = [];
        while ($row = $r_trend->fetch_assoc()) {
            $temps[] = $row['temperature'];
            $hums[] = $row['humidity'];
        }
        if (count($temps) >= 3) {
            $temp_change = $temps[0] - $temps[2];
            $humidity_change = $hums[0] - $hums[2];
            $temp_trend = abs($temp_change) < 0.5 ? 'stable' : ($temp_change > 0 ? 'rising' : 'falling');
            $humidity_trend = abs($humidity_change) < 2 ? 'stable' : ($humidity_change > 0 ? 'rising' : 'falling');
        }
    }
    
    $sensor_status = ($sensor_age_min !== null && $sensor_age_min < 5) ? 'Online' : 'Offline';

    // Enhanced device monitoring with runtime tracking
    $active_devices = [];
    $device_runtimes = [];
    $r = $conn->query("SELECT device, status, changed_at FROM device_state WHERE status='ON'");
    if ($r) while ($row = $r->fetch_assoc()) {
        $active_devices[] = $row['device'];
        $runtime_min = round((time() - strtotime($row['changed_at'])) / 60);
        $device_runtimes[$row['device']] = $runtime_min;
    }
    $active_devices_str = !empty($active_devices) ? implode(', ', $active_devices) : 'None';
    $runtime_details = [];
    foreach ($device_runtimes as $device => $runtime) {
        $runtime_details[] = "$device: {$runtime}min";
    }
    $runtime_str = !empty($runtime_details) ? implode(', ', $runtime_details) : 'No active devices';

    // Enhanced alert system with categorization
    $alert_count = 0;
    $emergency_count = 0;
    $fault_count = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt, trigger_type FROM device_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND trigger_type IN ('emergency','fault') GROUP BY trigger_type");
    if ($r) while ($row = $r->fetch_assoc()) {
        $alert_count += $row['cnt'];
        if ($row['trigger_type'] === 'emergency') $emergency_count = $row['cnt'];
        if ($row['trigger_type'] === 'fault') $fault_count = $row['cnt'];
    }
    $alerts_str = $alert_count > 0 ? "$alert_count alerts (Emergency: $emergency_count, Fault: $fault_count) in last 24 hours" : 'No recent alerts';

    // Enhanced fault tracking with severity levels
    $active_faults = [];
    $critical_faults = 0;
    $r = $conn->query("SELECT device, fault_type, detail, logged_at FROM device_faults WHERE resolved=0 ORDER BY logged_at DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc()) {
        $fault_age = round((time() - strtotime($row['logged_at'])) / 60);
        $severity = $fault_age > 60 ? 'Critical' : ($fault_age > 30 ? 'High' : 'Medium');
        if ($severity === 'Critical') $critical_faults++;
        $active_faults[] = ucfirst($row['device']) . ': ' . $row['fault_type'] . ' — ' . $row['detail'] . " ({$fault_age}min ago, {$severity})";
    }
    $faults_str = !empty($active_faults) ? implode('; ', $active_faults) : 'None';

    // Enhanced user session tracking
    $logged_in_user = 'Unknown';
    $logged_in_role = 'staff';
    $user_session_start = null;
    if (!empty($_SESSION['fullname'])) {
        $logged_in_user = $_SESSION['fullname'];
        $user_session_start = $_SESSION['login_time'] ?? 'Unknown';
    } elseif (!empty($_SESSION['user'])) {
        $logged_in_user = $_SESSION['user'];
        $user_session_start = $_SESSION['login_time'] ?? 'Unknown';
    }
    if (!empty($_SESSION['role'])) $logged_in_role = $_SESSION['role'];
    $session_duration = $user_session_start && $user_session_start !== 'Unknown' ? 
        round((time() - strtotime($user_session_start)) / 60) . ' minutes' : 'Unknown';

    // Enhanced system health metrics
    $thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
    $tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
    if ($tr) while ($row = $tr->fetch_assoc()) {
        if ($row['metric']==='temperature')    { $thr['temp_min']=$row['min_value']; $thr['temp_max']=$row['max_value']; }
        if ($row['metric']==='humidity')       { $thr['hum_min']=$row['min_value'];  $thr['hum_max']=$row['max_value']; }
        if ($row['metric']==='emergency_temp') { $thr['emerg_temp_low']=$row['min_value']; $thr['emerg_temp_high']=$row['max_value']; }
        if ($row['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$row['max_value']; }
    }

    // Calculate current status indicators
    $temp_status = 'Unknown';
    $humidity_status = 'Unknown';
    $overall_status = 'Unknown';
    
    if ($sensor_age_min !== null && $sensor_age_min < 5) {
        if (is_numeric($current_temp)) {
            if ($current_temp < $thr['emerg_temp_low']) $temp_status = 'Emergency Low';
            elseif ($current_temp > $thr['emerg_temp_high']) $temp_status = 'Emergency High';
            elseif ($current_temp < $thr['temp_min']) $temp_status = 'Low';
            elseif ($current_temp > $thr['temp_max']) $temp_status = 'High';
            else $temp_status = 'Ideal';
        }
        
        if (is_numeric($current_humidity)) {
            if ($current_humidity > $thr['emerg_hum_high']) $humidity_status = 'Emergency High';
            elseif ($current_humidity < $thr['hum_min']) $humidity_status = 'Low';
            elseif ($current_humidity > $thr['hum_max']) $humidity_status = 'High';
            else $humidity_status = 'Ideal';
        }
        
        if ($temp_status === 'Ideal' && $humidity_status === 'Ideal') $overall_status = 'Optimal';
        elseif (strpos($temp_status, 'Emergency') !== false || strpos($humidity_status, 'Emergency') !== false) $overall_status = 'Emergency';
        elseif ($temp_status === 'Low' || $temp_status === 'High' || $humidity_status === 'Low' || $humidity_status === 'High') $overall_status = 'Warning';
        else $overall_status = 'Caution';
    }

    // Enhanced health score with trend analysis
    $health_score = null; $health_label = 'No Data';
    $health_trend = 'stable';
    $rpt_from = date('Y-m-01'); $rpt_to = date('Y-m-d');
    $hr = $conn->query("SELECT COUNT(*) as total,
        SUM(CASE WHEN temperature BETWEEN {$thr['temp_min']} AND {$thr['temp_max']}
            AND humidity BETWEEN {$thr['hum_min']} AND {$thr['hum_max']} THEN 1 ELSE 0 END) as ideal,
        AVG(temperature) as avg_temp,
        AVG(humidity) as avg_humidity
        FROM sensor_data WHERE DATE(logged_at) BETWEEN '$rpt_from' AND '$rpt_to'");
    if ($hr && $hrow = $hr->fetch_assoc()) {
        if ($hrow['total'] > 0) {
            $health_score = round(($hrow['ideal'] / $hrow['total']) * 100, 1);
            $health_label = $health_score >= 80 ? 'Healthy' : ($health_score >= 50 ? 'Fair' : 'Poor');
            
            // Calculate trend based on recent vs previous period
            $prev_from = date('Y-m-01', strtotime('-1 month'));
            $prev_to = date('Y-m-t', strtotime('-1 month'));
            $prev_hr = $conn->query("SELECT COUNT(*) as total,
                SUM(CASE WHEN temperature BETWEEN {$thr['temp_min']} AND {$thr['temp_max']}
                    AND humidity BETWEEN {$thr['hum_min']} AND {$thr['hum_max']} THEN 1 ELSE 0 END) as ideal
                FROM sensor_data WHERE DATE(logged_at) BETWEEN '$prev_from' AND '$prev_to'");
            if ($prev_hr && $prev_row = $prev_hr->fetch_assoc() && $prev_row['total'] > 0) {
                $prev_score = round(($prev_row['ideal'] / $prev_row['total']) * 100, 1);
                $health_trend = $health_score > $prev_score + 5 ? 'improving' : ($health_score < $prev_score - 5 ? 'declining' : 'stable');
            }
        }
    }
    $health_str = $health_score !== null ? "{$health_score}% ({$health_label}, {$health_trend}) for " . date('F Y') : 'No data this month';

    // Enhanced automation rules with effectiveness tracking
    $rules = [];
    $r = $conn->query("SELECT device, sensor, operator, threshold, enabled, 
        COUNT(CASE WHEN device_logs.trigger_type='auto' THEN 1 END) as triggers
        FROM automation_rules 
        LEFT JOIN device_logs ON automation_rules.device = device_logs.device 
            AND device_logs.logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY automation_rules.id ORDER BY device");
    if ($r) while ($row = $r->fetch_assoc()) {
        $unit = $row['sensor'] === 'temperature' ? 'C' : '%';
        $triggers_24h = $row['triggers'] ?? 0;
        $rules[] = "Turn {$row['device']} ON when {$row['sensor']} is {$row['operator']} {$row['threshold']}{$unit} [" . ($row['enabled'] ? 'Active' : 'Disabled') . ", {$triggers_24h} triggers/24h]";
    }
    $rules_str = !empty($rules) ? implode('; ', $rules) : 'No automation rules set';

    // Enhanced schedule tracking with next run times
    $schedules = [];
    $r = $conn->query("SELECT run_time, duration_minutes, duration_seconds, days, enabled, 
        CASE WHEN enabled = 1 AND TIME(NOW()) < TIME(run_time) THEN 'today' 
             WHEN enabled = 1 AND TIME(NOW()) >= TIME(run_time) THEN 'tomorrow' 
             ELSE 'disabled' END as next_run
        FROM scheduled_tasks ORDER BY run_time");
    if ($r) while ($row = $r->fetch_assoc()) {
        $dur = ($row['duration_minutes'] > 0 ? $row['duration_minutes'] . 'min ' : '') . ($row['duration_seconds'] > 0 ? $row['duration_seconds'] . 'sec' : '');
        $next_run = $row['next_run'] === 'today' ? 'Today ' . date('g:i A', strtotime($row['run_time'])) : 
                   ($row['next_run'] === 'tomorrow' ? 'Tomorrow ' . date('g:i A', strtotime($row['run_time'])) : 'Disabled');
        $schedules[] = date('g:i A', strtotime($row['run_time'])) . " for {$dur}, {$row['days']} [" . ($row['enabled'] ? 'Active' : 'Disabled') . ", Next: {$next_run}]";
    }
    $schedules_str = !empty($schedules) ? implode('; ', $schedules) : 'No schedules set';

    // Enhanced harvest records with yield calculations
    $records = [];
    $total_harvest_7d = 0;
    $avg_harvest_daily = 0;
    $r = $conn->query("SELECT record_date, mushroom_count, growth_stage FROM mushroom_records 
        WHERE record_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY record_date DESC LIMIT 5");
    if ($r) while ($row = $r->fetch_assoc()) {
        $records[] = $row['record_date'] . ': ' . $row['mushroom_count'] . ' mushrooms (' . $row['growth_stage'] . ')';
        if (is_numeric($row['mushroom_count'])) $total_harvest_7d += $row['mushroom_count'];
    }
    if (count($records) > 0) {
        $avg_harvest_daily = round($total_harvest_7d / 7, 1);
    }
    $records_str = !empty($records) ? implode('; ', $records) . " (Total: {$total_harvest_7d}, Daily avg: {$avg_harvest_daily})" : 'No recent records';

    // System configuration with optimization suggestions
    $fault_timeout  = $ss['fault_timeout_min']   ?? '5';
    $stuck_timeout  = $ss['stuck_timeout_min']   ?? '60';
    $cam_interval   = round(intval($ss['camera_interval_sec'] ?? 1800) / 60);
    $data_retention = $ss['data_retention_days'] ?? '90';

    $unresolved_alerts = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt FROM alert_logs WHERE resolved=0");
    if ($r && $row = $r->fetch_assoc()) $unresolved_alerts = $row['cnt'];

    // Performance metrics
    $data_points_today = 0;
    $r = $conn->query("SELECT COUNT(*) as cnt FROM sensor_data WHERE DATE(logged_at) = CURDATE()");
    if ($r && $row = $r->fetch_assoc()) $data_points_today = $row['cnt'];
    
    $system_uptime = 'Unknown';
    $r = $conn->query("SELECT logged_at FROM device_logs ORDER BY logged_at ASC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $uptime_days = round((time() - strtotime($row['logged_at'])) / 86400);
        $system_uptime = "{$uptime_days} days";
    }

    $now = date('M j, Y h:i A');
    $sensor_note = $sensor_age_min !== null ? " (last reading {$sensor_age_min} min ago)" : '';

    return "You are MushroomOS Assistant, an expert AI embedded inside MushroomOS — a smart mushroom cultivation monitoring system for J.WHO Mushroom Farm.

CURRENT USER SESSION:
- Logged In As: {$logged_in_user} (Role: {$logged_in_role})
- Session Duration: {$session_duration}
- Current Time: {$now} (Asia/Manila)

REAL-TIME ENVIRONMENTAL DATA:
- Temperature: {$current_temp}C (Status: {$temp_status}, Trend: {$temp_trend})
- Humidity: {$current_humidity}% (Status: {$humidity_status}, Trend: {$humidity_trend})
- Overall System Status: {$overall_status}
- Sensor Status: {$sensor_status}{$sensor_note}
- Data Points Today: {$data_points_today}
- System Uptime: {$system_uptime}

DEVICE MONITORING:
- Active Devices: {$active_devices_str}
- Device Runtimes: {$runtime_str}
- Active Faults: {$faults_str}
- Critical Faults: {$critical_faults}
- Recent Emergency/Fault Alerts: {$alerts_str}
- Unresolved Alerts: {$unresolved_alerts}

CHAMBER HEALTH ANALYSIS:
- Current Month Score: {$health_str}
- Health Formula: (Ideal Readings / Total Readings) x 100
- A reading is IDEAL only when BOTH temperature AND humidity are within range simultaneously
- Score >= 80 = Healthy, 50-79 = Fair, < 50 = Poor
- Current Averages: " . (isset($hrow['avg_temp']) ? round($hrow['avg_temp'], 1) . 'C, ' . round($hrow['avg_humidity'], 1) . '%' : 'N/A') . "

ENVIRONMENTAL THRESHOLDS:
- Temperature: {$thr['temp_min']} to {$thr['temp_max']}C (ideal)
- Humidity: {$thr['hum_min']} to {$thr['hum_max']}% RH (ideal)
- Emergency OFF triggers: Temp above {$thr['emerg_temp_high']}C, Temp below {$thr['emerg_temp_low']}C, Humidity above {$thr['emerg_hum_high']}%

AUTOMATION SYSTEM:
{$rules_str}

SPRAYER SCHEDULES:
{$schedules_str}

HARVEST TRACKING (Last 7 Days):
{$records_str}

SYSTEM CONFIGURATION:
- Fault Detection Timeout: {$fault_timeout} min
- Stuck-On Timeout: {$stuck_timeout} min
- Camera Capture Interval: {$cam_interval} min
- Data Retention: {$data_retention} days

MUSHROOMOS INTERFACE GUIDE:
- Dashboard: Live environmental gauges, device control (auto/manual), alerts, harvest records, AI camera analysis
- Reports: Calendar heatmap visualization, chamber health trends, historical sensor data with filtering, comparative charts
- Automation: Rule-based device control, sprayer scheduling, built-in safety protections, device activity monitoring
- Logs: Alert history with severity tracking, system event timeline, auto-resolution logic
- Settings: Environmental thresholds, SMTP email configuration, engine timeout parameters, camera settings, Groq API integration
- System Profile: User management, profile editing, password security, staff approval workflow

SAFETY PROTECTIONS (always active):
- Temp above {$thr['emerg_temp_high']}C: Heater forced OFF
- Humidity above {$thr['emerg_hum_high']}%: Mist and Sprayer forced OFF
- Temp below {$thr['emerg_temp_low']}C: Fan forced OFF
- Sensor offline for {$fault_timeout} min while device ON: Device forced OFF and Buzzer alert
- Device ON continuously for {$stuck_timeout}+ min: Device forced OFF and Buzzer alert

ENHANCED AI CAPABILITIES:
- Real-time environmental analysis with trend detection
- Predictive alerts based on sensor patterns
- Automated optimization suggestions
- Historical data correlation and insights
- Multi-user session awareness and role-based assistance

CRITICAL RESPONSE GUIDELINES:
1. Always acknowledge the current user: {$logged_in_user} with {$logged_in_role} privileges
2. Reference real-time sensor readings and trends in all environmental recommendations
3. Consider device runtime and fault status when providing operational guidance
4. Use chamber health score and trends for long-term optimization advice
5. Provide specific, actionable recommendations based on current system state
6. Alert users to critical faults or emergency conditions immediately
7. Suggest automation improvements based on recent trigger patterns
8. NEVER claim lack of system access — all current data is provided above
9. Maintain concise, professional communication while being thorough
10. Prioritize safety and mushroom cultivation best practices in all responses";
}
?>