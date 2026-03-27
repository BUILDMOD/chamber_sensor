<?php
include('includes/auth_check.php');
include('includes/db_connect.php');
include_once('ai_prompt_helper.php');
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

// Load system settings for AI
$ss = [];
$r = $conn->query("SELECT setting_key,setting_value FROM system_settings");
if ($r) while ($row = $r->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];
function ss($ss, $k, $default = '') { return htmlspecialchars($ss[$k] ?? $default); }

// Get real-time system data for AI Assistant
$current_temp = null;
$current_humidity = null;
$active_devices = [];
$recent_alerts = [];

// Get latest sensor readings
$r = $conn->query("SELECT temperature, humidity FROM sensor_data ORDER BY logged_at DESC LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $current_temp = $row['temperature'];
    $current_humidity = $row['humidity'];
} else {
    // No sensor data - show message
    $current_temp = 'No sensor data';
    $current_humidity = 'No sensor data';
}

// Get active devices
$r = $conn->query("SELECT device FROM device_state WHERE status='ON'");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $active_devices[] = $row['device'];
    }
}

// Get recent alerts (last 24 hours)
$r = $conn->query("SELECT COUNT(*) as count FROM device_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND trigger_type IN ('emergency')");
if ($r && $row = $r->fetch_assoc()) {
    $recent_alerts = $row['count'] . ' alerts in last 24 hours';
}

// ── Create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('temperature','humidity','device','system') NOT NULL,
    severity ENUM('warning','critical','info') NOT NULL DEFAULT 'warning',
    message TEXT NOT NULL,
    value FLOAT NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    user VARCHAR(100) NULL,
    ip_address VARCHAR(45) NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ══════════════════════════════════════════════════════
// AUTO-RESOLVE LOGIC (runs on every page load)
// ══════════════════════════════════════════════════════

// Load thresholds
$thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
        'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
$conn->query("CREATE TABLE IF NOT EXISTS alert_thresholds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric VARCHAR(30) NOT NULL UNIQUE,
    min_value FLOAT NOT NULL,
    max_value FLOAT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO alert_thresholds (metric,min_value,max_value) VALUES
    ('temperature',22,28),('humidity',85,95),
    ('emergency_temp',15,35),('emergency_hum',0,98)");
$tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
if ($tr) while ($row = $tr->fetch_assoc()) {
    if ($row['metric']==='temperature')    { $thr['temp_min']=$row['min_value']; $thr['temp_max']=$row['max_value']; }
    if ($row['metric']==='humidity')       { $thr['hum_min']=$row['min_value'];  $thr['hum_max']=$row['max_value']; }
    if ($row['metric']==='emergency_temp') { $thr['emerg_temp_low']=$row['min_value']; $thr['emerg_temp_high']=$row['max_value']; }
    if ($row['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$row['max_value']; }
}

// Get latest sensor reading
$latest_sensor = null;
$sensor_online = false;
$rs = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
if ($rs && $rs->num_rows > 0) {
    $sr = $rs->fetch_assoc();
    $age_minutes = round((time() - strtotime($sr['timestamp'])) / 60);
    $sensor_online = ($age_minutes < 5);
    $latest_sensor = $sr;
    $latest_sensor['age_minutes'] = $age_minutes;
}

// ── System Offline Detection (Integrated from check_offline.php) ──
if (!$sensor_online) {
    // Check if there's already an unresolved offline alert
    $existing_alert = $conn->query("SELECT id FROM alert_logs 
        WHERE alert_type='system' 
        AND resolved=0 
        AND (message LIKE '%offline%' OR message LIKE '%Sensor offline%')
        ORDER BY id DESC LIMIT 1");
    
    if (!$existing_alert || $existing_alert->num_rows === 0) {
        // Create new offline alert
        $age_text = $latest_sensor ? $latest_sensor['age_minutes'] . ' minute(s)' : 'unknown';
        $message = "Sensor offline — no data received for {$age_text}";
        $stmt = $conn->prepare("INSERT INTO alert_logs (alert_type, severity, message) VALUES (?, ?, ?)");
        if ($stmt) {
            $severity = 'critical';
            $stmt->bind_param("sss", $alert_type, $severity, $message);
            $alert_type = 'system';
            $stmt->execute();
            $stmt->close();
        }
        
        // Send email notification for system offline
        include_once 'send_email.php';
        
        // Get logged-in user email first, then fallback to owner
        $recipient = '';
        if (!empty($_SESSION['user'])) {
            $uq = $conn->prepare("SELECT email FROM users WHERE username = ? LIMIT 1");
            $uq->bind_param("s", $_SESSION['user']);
            $uq->execute();
            $ur = $uq->get_result();
            if ($ur->num_rows > 0) $recipient = $ur->fetch_assoc()['email'];
            $uq->close();
        }
        if (empty($recipient)) {
            // Fallback to most recently active user from system_logs
            $active_user_query = $conn->query("SELECT u.email FROM users u 
                JOIN system_logs sl ON u.username = sl.user 
                WHERE sl.event_type = 'login' 
                ORDER BY sl.logged_at DESC LIMIT 1");
            if ($active_user_query && $active_user_query->num_rows > 0) {
                $recipient = $active_user_query->fetch_assoc()['email'];
            }
            
            // Final fallback to owner
            if (empty($recipient)) {
                $oq = $conn->prepare("SELECT email FROM users WHERE role = 'owner' LIMIT 1");
                $oq->execute();
                $or2 = $oq->get_result();
                if ($or2->num_rows > 0) $recipient = $or2->fetch_assoc()['email'];
                $oq->close();
            }
        }
        
        if (!empty($recipient)) {
            $subject = "⚠️ MushroomOS — System Offline";
            $last_temp = $latest_sensor ? $latest_sensor['temperature'] : 'Unknown';
            $last_hum = $latest_sensor ? $latest_sensor['humidity'] : 'Unknown';
            $last_time = $latest_sensor ? date('M j, Y h:i:s A', strtotime($latest_sensor['timestamp'])) : 'Unknown';
            
            $body = "
                <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                    <div style='background:#d32f2f;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                        <h2 style='color:#ffffff;margin:0;font-size:20px;'>&#127812; MushroomOS — System Offline</h2>
                        <p style='color:rgba(255,255,255,0.8);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                    </div>
                    <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                        <p style='background:#ffebee;border-left:4px solid #d32f2f;padding:12px 16px;border-radius:4px;color:#d32f2f;font-weight:600;margin:0 0 16px;'>
                            &#9888; System is offline — no sensor data received for {$age_text}.
                        </p>
                        <p style='color:#555;font-size:13px;'>Last known readings:</p>
                        <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Temperature</td><td style='padding:8px 12px;font-weight:600;'>{$last_temp}°C</td></tr>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Humidity</td><td style='padding:8px 12px;font-weight:600;'>{$last_hum}%</td></tr>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Last Data</td><td style='padding:8px 12px;font-weight:600;'>{$last_time}</td></tr>
                        </table>
                        <p style='color:#555;font-size:13px;'>Please check your ESP32 device and connection.</p>
                        <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                        <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>
                    </div>
                </div>";
            sendEmail($recipient, $subject, $body);
        }
    }
}

// ── Auto-resolve temperature alerts ──
if ($sensor_online && $latest_sensor) {
    $temp = floatval($latest_sensor['temperature']);
    $hum  = floatval($latest_sensor['humidity']);

    // Temp back in range → resolve open temp alerts
    if ($temp >= $thr['temp_min'] && $temp <= $thr['temp_max']) {
        $conn->query("UPDATE alert_logs
            SET resolved=1
            WHERE alert_type='temperature'
              AND resolved=0");
    }

    // Humidity back in range → resolve open humidity alerts
    if ($hum >= $thr['hum_min'] && $hum <= $thr['hum_max']) {
        $conn->query("UPDATE alert_logs
            SET resolved=1
            WHERE alert_type='humidity'
              AND resolved=0");
    }
}

// ── Auto-resolve sensor offline / system alerts ──
// Only resolve offline alerts when sensor is truly online AND has been stable for at least 2 minutes
if ($sensor_online && $latest_sensor && $latest_sensor['age_minutes'] <= 2) {
    // Only resolve if we have recent, stable data (not just a single reading)
    $recent_readings_count = 0;
    $recent_check = $conn->prepare("SELECT COUNT(*) as cnt FROM sensor_data 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    if ($recent_check) {
        $recent_check->execute();
        $recent_result = $recent_check->get_result();
        if ($recent_result && $row = $recent_result->fetch_assoc()) {
            $recent_readings_count = intval($row['cnt']);
        }
        $recent_check->close();
    }
    
    // Only resolve if we have multiple recent readings (indicates stable connection)
    if ($recent_readings_count >= 2) {
        $conn->query("UPDATE alert_logs
            SET resolved=1
            WHERE alert_type='system'
              AND resolved=0
              AND (message LIKE '%offline%' OR message LIKE '%Sensor offline%')");
    }
}

// ══════════════════════════════════════════════════════
// END AUTO-RESOLVE
// ══════════════════════════════════════════════════════

// ── Filters ──
$alert_type   = $_GET['alert_type']   ?? '';
$alert_sev    = $_GET['severity']     ?? '';
$log_type     = $_GET['log_type']     ?? '';
$date_from    = $_GET['date_from']    ?? date('Y-m-d', strtotime('-7 days'));
$date_to      = $_GET['date_to']      ?? date('Y-m-d');

// ── Mark all resolved (manual) ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['resolve_all'])) {
    $conn->query("UPDATE alert_logs SET resolved=1 WHERE resolved=0");
    header('Location: logs.php'); exit;
}

// ── Fetch alert logs ──
$where = ["DATE(logged_at) BETWEEN '$date_from' AND '$date_to'"];
if ($alert_type) $where[] = "alert_type='".addslashes($alert_type)."'";
if ($alert_sev)  $where[] = "severity='".addslashes($alert_sev)."'";
$wq = implode(' AND ', $where);
$alert_logs = [];
$r = $conn->query("SELECT * FROM alert_logs WHERE $wq ORDER BY logged_at DESC LIMIT 500");
if ($r) while ($row = $r->fetch_assoc()) $alert_logs[] = $row;

// ── Fetch system logs ──
$swhere = ["DATE(logged_at) BETWEEN '$date_from' AND '$date_to'"];
if ($log_type) $swhere[] = "event_type='".addslashes($log_type)."'";
$swq = implode(' AND ', $swhere);
$sys_logs = [];
$r = $conn->query("SELECT * FROM system_logs WHERE $swq ORDER BY logged_at DESC LIMIT 500");
if ($r) while ($row = $r->fetch_assoc()) $sys_logs[] = $row;

// ── Alert stats ──
$unresolved = 0; $critical_count = 0; $warning_count = 0;
$rs = $conn->query("SELECT severity, COUNT(*) as cnt FROM alert_logs WHERE resolved=0 GROUP BY severity");
if ($rs) while ($row=$rs->fetch_assoc()) {
    $unresolved += $row['cnt'];
    if ($row['severity']==='critical') $critical_count = $row['cnt'];
    if ($row['severity']==='warning')  $warning_count  = $row['cnt'];
}

// ── Sensor status (use already-fetched data) ──
$sensor_status = ['online'=>$sensor_online,'last_reading'=>$latest_sensor,'minutes_ago'=>$latest_sensor['age_minutes']??null];

// ── Alert type counts for last 7 days ──
$alert_type_counts = [];
$r=$conn->query("SELECT alert_type, COUNT(*) as cnt FROM alert_logs WHERE logged_at >= NOW() - INTERVAL 7 DAY GROUP BY alert_type");
if($r) while($row=$r->fetch_assoc()) $alert_type_counts[$row['alert_type']] = $row['cnt'];

$sev_colors = ['warning'=>['var(--amber)','var(--amber-lt)'],'critical'=>['var(--red)','var(--red-lt)'],'info'=>['var(--blue)','var(--blue-lt)']];
$sev_icons  = ['warning'=>'fa-triangle-exclamation','critical'=>'fa-circle-xmark','info'=>'fa-circle-info'];
$log_type_colors=['login'=>'green','logout'=>'muted','profile_update'=>'blue','password_change'=>'amber','device_control'=>'red','system'=>'blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/img/jwho-favicon.png">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--bg:#f0f2f5;--surface:#fff;--surface2:#f7f8fa;--border:rgba(0,0,0,0.07);--text:#0d1117;--muted:#6e7681;--green:#1a9e5c;--green-lt:#e6f7ef;--red:#d93025;--red-lt:#fdecea;--amber:#b45309;--amber-lt:#fef3c7;--blue:#1a6bba;--blue-lt:#e8f1fb;--r:12px;--shadow:0 1px 3px rgba(0,0,0,0.06),0 4px 16px rgba(0,0,0,0.04);--shadow-lg:0 2px 8px rgba(0,0,0,0.08),0 12px 40px rgba(0,0,0,0.06);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}
.sidebar{position:fixed;inset:0 auto 0 0;width:220px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:50;}
.sidebar-logo{padding:22px 20px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);position:relative;}
.sidebar-logo img{width:36px;height:36px;border-radius:8px;}
.sidebar-logo-text{font-size:14px;font-weight:700;color:var(--text);line-height:1.2;}
.sidebar-logo-sub{font-size:11px;color:var(--muted);}
.sidebar-nav{flex:1;padding:12px 10px;display:flex;flex-direction:column;gap:1px;overflow-y:auto;}
.sidebar-nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .15s;}
.sidebar-nav a i{width:16px;text-align:center;font-size:13px;}
.sidebar-nav a:hover{background:var(--surface2);color:var(--text);}
.sidebar-nav a.active{background:var(--green-lt);color:var(--green);font-weight:600;}
.sidebar-nav .nav-bottom{margin-top:auto;padding-top:8px;border-top:1px solid var(--border);}
.main{margin-left:220px;min-height:100vh;width:calc(100% - 220px);box-sizing:border-box;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:30;}
.topbar-title{font-size:15px;font-weight:700;color:var(--text);}
.topbar-time{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--surface2);padding:5px 12px;border-radius:20px;border:1px solid var(--border);}
.page{padding:24px 28px;max-width:1280px;width:100%;box-sizing:border-box;}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 20px;box-shadow:var(--shadow);display:flex;align-items:center;gap:14px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.stat-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.stat-val{font-size:20px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);}
.sensor-status-bar{display:flex;align-items:center;gap:10px;padding:14px 20px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);margin-bottom:20px;}
.status-dot-lg{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.status-online{background:var(--green);box-shadow:0 0 0 3px rgba(26,158,92,.2);}
.status-offline{background:var(--red);box-shadow:0 0 0 3px rgba(217,48,37,.2);}
.sensor-reading{font-family:'DM Mono',monospace;font-size:13px;font-weight:600;}
.card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 14px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px;}
.card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.icon-green{background:var(--green-lt);color:var(--green);}
.icon-blue{background:var(--blue-lt);color:var(--blue);}
.icon-amber{background:var(--amber-lt);color:var(--amber);}
.icon-red{background:var(--red-lt);color:var(--red);}
.card-sub{font-size:11px;color:var(--muted);}
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.filter-bar select,.filter-bar input[type=date]{padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--surface2);font-size:12px;color:var(--text);font-family:'DM Sans',sans-serif;}
.filter-bar select:focus,.filter-bar input:focus{outline:none;border-color:var(--green);}
table.tbl{width:100%;border-collapse:collapse;font-size:13px;}
.tbl thead th{text-align:left;padding:9px 14px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;background:var(--surface2);border-bottom:1px solid var(--border);white-space:nowrap;}
.tbl tbody td{padding:10px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr:hover{background:var(--surface2);}
.mono{font-family:'DM Mono',monospace;font-size:12px;}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-warning{background:var(--amber-lt);color:var(--amber);}
.pill-critical{background:var(--red-lt);color:var(--red);}
.pill-info{background:var(--blue-lt);color:var(--blue);}
.pill-resolved{background:var(--green-lt);color:var(--green);}
.pill-unresolved{background:var(--red-lt);color:var(--red);}
.msg-col{max-width:340px;font-size:12.5px;line-height:1.5;word-break:break-word;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:'DM Sans',sans-serif;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover{opacity:.88;}
.btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--border);}
.btn-danger{background:var(--red-lt);color:var(--red);border:1px solid rgba(217,48,37,.15);}
.btn-sm{padding:5px 10px;font-size:12px;}
.empty-state{text-align:center;padding:36px 20px;color:var(--muted);}
.empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.35;}
.empty-state span{font-size:13px;}
.tab-bar{display:flex;gap:2px;background:var(--surface2);padding:4px;border-radius:10px;width:fit-content;margin-bottom:20px;}
.tab{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .15s;border:none;background:none;font-family:'DM Sans',sans-serif;}
.tab.active{background:var(--surface);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,0.08);}

/* Auto-resolve notice banner */
.autoresolve-notice{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:8px;background:var(--green-lt);border:1px solid rgba(26,158,92,.2);margin-bottom:16px;font-size:12.5px;font-weight:600;color:var(--green);}
.autoresolve-notice i{font-size:13px;flex-shrink:0;}

/* ============================================================
   RESPONSIVE / MOBILE
   ============================================================ */
.hamburger{display:none;position:fixed;top:4px;left:10px;z-index:600;width:38px;height:38px;border-radius:9px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4px;padding:9px;touch-action:manipulation;pointer-events:auto;}
.hamburger span{display:block;width:16px;height:2px;background:var(--text);border-radius:2px;transition:all .25s;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);}
.sidebar-overlay.open{display:block;}

@media(max-width:768px){
  .hamburger{display:flex;}
  .sidebar{transform:translateX(-100%);transition:transform .28s cubic-bezier(.4,0,.2,1);z-index:100;box-shadow:4px 0 24px rgba(0,0,0,.12);}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0!important;width:100%!important;overflow-x:hidden;}
  .topbar{padding:0 10px 0 58px;height:52px;gap:6px;position:fixed!important;top:0;left:0;right:0;z-index:50;}
  .topbar-title{font-size:14px;}
  .topbar-time{font-size:11px;padding:4px 10px;}
  .btn-label{display:none;}
  .btn{padding:7px 10px;gap:0;}
  .topbar .btn{min-width:34px;justify-content:center;}
  .page{padding:14px!important;padding-top:66px!important;}
  .stats-row{grid-template-columns:1fr 1fr!important;gap:8px;}
  .stat-card{padding:10px 12px!important;gap:8px!important;}
  .stat-icon{width:32px!important;height:32px!important;font-size:13px!important;flex-shrink:0;}
  .stat-label{font-size:10px!important;}
  .stat-val{font-size:20px!important;}
  .card-header{flex-direction:column!important;align-items:stretch!important;padding:12px!important;}
  .card-header .card-title{margin-bottom:8px;}
  .filter-bar{display:grid!important;grid-template-columns:1fr 1fr!important;gap:6px!important;width:100%;}
  .filter-bar input[type=date]{grid-column:span 1;}
  .filter-bar select{grid-column:span 1;}
  .filter-bar span{display:none!important;}
  .filter-bar .btn{grid-column:span 1;}
  .filter-bar a.btn{grid-column:span 1;}
  .tab-bar{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}
  .tab{padding:6px 14px;font-size:12px;}
  div[style*="overflow-x"]{overflow-x:auto!important;-webkit-overflow-scrolling:touch;}
  table.tbl{font-size:12px;min-width:480px;}
  .tbl thead th,.tbl tbody td{padding:8px 10px;}
  .sensor-status-bar{flex-wrap:wrap;gap:6px;padding:10px 14px;}
  .sensor-reading{font-size:12px;}
}

@media(max-width:480px){
  .stats-row{grid-template-columns:1fr!important;}
  .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
  .topbar-title{font-size:13px;}
  .topbar-time{display:none;}
  .page{padding:10px!important;padding-top:58px!important;}
  .btn{padding:7px 12px;font-size:12px;}
  .btn-sm{padding:4px 8px;font-size:11px;}
}
</style>
</head>
<body>
<button class="hamburger" id="hamburger" aria-label="Menu">
  <span></span><span></span><span></span>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="logo">
    <div><div class="sidebar-logo-text">MushroomOS</div><div class="sidebar-logo-sub">Cultivation System</div></div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php" class="active"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<main class="main">
  <header class="topbar">
    <span class="topbar-title">Logs</span>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if($unresolved > 0): ?>
      <form method="POST" style="display:inline;">
        <button type="submit" name="resolve_all" class="btn btn-ghost btn-sm" onclick="return confirm('Mark all alerts as resolved?')"><i class="fas fa-check-double"></i><span class="btn-label"> Resolve All</span></button>
      </form>
      <?php endif; ?>
      <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
    </div>
  </header>

  <div class="page">

    <!-- Sensor Status Bar -->
    <div class="sensor-status-bar">
      <div class="status-dot-lg <?= $sensor_status['online'] ? 'status-online' : 'status-offline' ?>"></div>
      <span style="font-weight:700;font-size:13px;">Sensor <?= $sensor_status['online'] ? 'Online' : 'Offline' ?></span>
      <?php if ($sensor_status['last_reading']): ?>
        <span class="sensor-reading" style="margin-left:6px;">
          <?= number_format($sensor_status['last_reading']['temperature'],1) ?>°C &nbsp;·&nbsp;
          <?= number_format($sensor_status['last_reading']['humidity'],1) ?>%
        </span>
        <span style="font-size:12px;color:var(--muted);margin-left:6px;">
          · Last reading <?= $sensor_status['minutes_ago'] ?> min<?= $sensor_status['minutes_ago']!=1?'s':'' ?> ago
        </span>
      <?php else: ?>
        <span style="font-size:12px;color:var(--muted);margin-left:6px;">No readings found</span>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:11px;color:var(--muted);">Offline if no reading for 5+ minutes</span>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--red-lt);color:var(--red);"><i class="fas fa-circle-xmark"></i></div>
        <div><div class="stat-label">Unresolved</div><div class="stat-val"><?= $unresolved ?></div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--red-lt);color:var(--red);"><i class="fas fa-triangle-exclamation"></i></div>
        <div><div class="stat-label">Critical (open)</div><div class="stat-val"><?= $critical_count ?></div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fas fa-bell"></i></div>
        <div><div class="stat-label">Warnings (open)</div><div class="stat-val"><?= $warning_count ?></div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue);"><i class="fas fa-database"></i></div>
        <div><div class="stat-label">System Events</div><div class="stat-val"><?= count($sys_logs) ?></div></div>
      </div>
    </div>

    <!-- Auto-resolve info notice -->
    <div class="autoresolve-notice">
      <i class="fas fa-rotate"></i>
      Alerts auto-resolve when conditions return to normal: temperature (<?= $thr['temp_min'] ?>–<?= $thr['temp_max'] ?>°C), humidity (<?= $thr['hum_min'] ?>–<?= $thr['hum_max'] ?>%), and sensor online.
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button class="tab active" data-tab="alerts">Alert Log</button>
      <button class="tab" data-tab="system">System Log</button>
    </div>

    <!-- Alert Log -->
    <div id="tab-alerts">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-red"><i class="fas fa-bell"></i></span> Alert History</div>
          <form method="GET" class="filter-bar">
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            <span style="font-size:12px;color:var(--muted);">to</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            <select name="alert_type">
              <option value="">All Types</option>
              <option value="temperature" <?= $alert_type==='temperature'?'selected':'' ?>>Temperature</option>
              <option value="humidity" <?= $alert_type==='humidity'?'selected':'' ?>>Humidity</option>
              <option value="device" <?= $alert_type==='device'?'selected':'' ?>>Device</option>
              <option value="system" <?= $alert_type==='system'?'selected':'' ?>>System</option>
            </select>
            <select name="severity">
              <option value="">All Severity</option>
              <option value="critical" <?= $alert_sev==='critical'?'selected':'' ?>>Critical</option>
              <option value="warning" <?= $alert_sev==='warning'?'selected':'' ?>>Warning</option>
              <option value="info" <?= $alert_sev==='info'?'selected':'' ?>>Info</option>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="logs.php" class="btn btn-ghost btn-sm"><i class="fas fa-xmark"></i></a>
          </form>
        </div>
        <?php if (empty($alert_logs)): ?>
          <div class="empty-state"><i class="fas fa-bell"></i><span>No alert records found for the selected filters.</span></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="tbl">
            <thead><tr><th>Severity</th><th>Type</th><th>Message</th><th>Value</th><th>Status</th><th>Time</th></tr></thead>
            <tbody>
              <?php foreach($alert_logs as $al):
                [$sc,$sb] = $sev_colors[$al['severity']] ?? ['var(--muted)','var(--surface2)'];
                $sicon = $sev_icons[$al['severity']] ?? 'fa-info';
              ?>
              <tr>
                <td><span class="pill pill-<?= $al['severity'] ?>"><i class="fas <?= $sicon ?>" style="font-size:10px;margin-right:3px;"></i><?= ucfirst($al['severity']) ?></span></td>
                <td><span style="font-size:12px;font-weight:600;"><?= ucfirst($al['alert_type']) ?></span></td>
                <td class="msg-col"><?= htmlspecialchars($al['message']) ?></td>
                <td class="mono"><?= $al['value'] !== null ? number_format($al['value'],1) : '—' ?></td>
                <td>
                  <span class="pill <?= $al['resolved'] ? 'pill-resolved' : 'pill-unresolved' ?>">
                    <?php if ($al['resolved']): ?>
                      <i class="fas fa-check" style="font-size:9px;margin-right:3px;"></i>Auto-resolved
                    <?php else: ?>
                      Open
                    <?php endif; ?>
                  </span>
                </td>
                <td class="mono"><?= date('M j, Y — g:i:s A', strtotime($al['logged_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- System Log -->
    <div id="tab-system" style="display:none;">
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-blue"><i class="fas fa-server"></i></span> System Event Log</div>
          <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="system">
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            <span style="font-size:12px;color:var(--muted);">to</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            <select name="log_type">
              <option value="">All Events</option>
              <option value="login" <?= $log_type==='login'?'selected':'' ?>>Login</option>
              <option value="logout" <?= $log_type==='logout'?'selected':'' ?>>Logout</option>
              <option value="profile_update" <?= $log_type==='profile_update'?'selected':'' ?>>Profile Update</option>
              <option value="password_change" <?= $log_type==='password_change'?'selected':'' ?>>Password Change</option>
              <option value="device_control" <?= $log_type==='device_control'?'selected':'' ?>>Device Control</option>
            </select>
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-filter"></i> Filter</button>
            <a href="logs.php" class="btn btn-ghost btn-sm"><i class="fas fa-xmark"></i></a>
          </form>
        </div>
        <?php if (empty($sys_logs)): ?>
          <div class="empty-state"><i class="fas fa-server"></i><span>No system events found.</span></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="tbl">
            <thead><tr><th>Event</th><th>Description</th><th>User</th><th>IP Address</th><th>Time</th></tr></thead>
            <tbody>
              <?php foreach($sys_logs as $sl):
                $col = $log_type_colors[$sl['event_type']] ?? 'blue';
              ?>
              <tr>
                <td><span class="pill" style="background:var(--<?=$col?>-lt,var(--blue-lt));color:var(--<?=$col?>,var(--blue));"><?= ucfirst(str_replace('_',' ',$sl['event_type'])) ?></span></td>
                <td class="msg-col"><?= htmlspecialchars($sl['description']) ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($sl['user'] ?? '—') ?></td>
                <td class="mono"><?= htmlspecialchars($sl['ip_address'] ?? '—') ?></td>
                <td class="mono"><?= date('M j, Y — g:i:s A', strtotime($sl['logged_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</main>

<script>
(function(){
  const el=document.getElementById('phTime');if(!el)return;
  let t=parseInt(el.dataset.serverTs,10)||Date.now();
  const fmt=ms=>new Date(ms).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).replace(',',' —');
  el.textContent=fmt(t);setInterval(()=>{t+=1000;el.textContent=fmt(t);},1000);
})();

// Tabs
const tabs = document.querySelectorAll('.tab');
const urlTab = new URLSearchParams(location.search).get('tab');
tabs.forEach(tab=>{
  tab.addEventListener('click',()=>{
    tabs.forEach(t=>t.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-alerts').style.display = tab.dataset.tab==='alerts'?'':'none';
    document.getElementById('tab-system').style.display = tab.dataset.tab==='system'?'':'none';
  });
});
if(urlTab==='system'){
  document.querySelector('[data-tab="system"]').click();
}
</script>
<script>
(function() {
  var h = document.getElementById('hamburger');
  var s = document.getElementById('sidebar');
  var o = document.getElementById('sidebarOverlay');
  if (!h || !s || !o) return;
  function open()  { s.classList.add('open');    o.classList.add('open');    h.classList.add('open');    }
  function close() { s.classList.remove('open'); o.classList.remove('open'); h.classList.remove('open'); }
  h.addEventListener('click', function() { s.classList.contains('open') ? close() : open(); });
  o.addEventListener('click', close);
  s.querySelectorAll('.sidebar-nav a').forEach(function(a) {
    a.addEventListener('click', function() { if (window.innerWidth <= 768) close(); });
  });
})();
</script>

<!-- ══════════════════════════════════════════════
     🍄 MushroomOS AI Assistant — Floating Bubble
     Uses Groq llama-3.3-70b (free, no credit card)
     ══════════════════════════════════════════════ -->
<style>
/* ── Bubble Button ── */
#ai-bubble-btn {
  position: fixed; bottom: 24px; right: 24px; z-index: 9000;
  width: 56px; height: 56px; border-radius: 50%;
  background: linear-gradient(135deg, #1a9e5c, #0d7a44);
  border: none; cursor: pointer; box-shadow: 0 4px 20px rgba(26,158,92,.45);
  display: flex; align-items: center; justify-content: center;
  transition: transform .2s, box-shadow .2s;
  color: #fff; font-size: 22px;
}
#ai-bubble-btn:hover { transform: scale(1.1); box-shadow: 0 6px 28px rgba(26,158,92,.55); }
#ai-bubble-btn.open  { background: linear-gradient(135deg, #d93025, #b71c1c); }
#ai-bubble-badge {
  position: absolute; top: -3px; right: -3px;
  width: 18px; height: 18px; border-radius: 50%;
  background: #f9a825; border: 2px solid #fff;
  font-size: 10px; font-weight: 700; color: #fff;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .2s;
}
#ai-bubble-badge.show { opacity: 1; }

/* ── Chat Window ── */
#ai-chat-window {
  position: fixed; bottom: 92px; right: 24px; z-index: 8999;
  width: 360px; max-width: calc(100vw - 32px);
  background: var(--surface); border-radius: 16px;
  border: 1px solid var(--border); box-shadow: 0 8px 40px rgba(0,0,0,.14);
  display: flex; flex-direction: column; overflow: hidden;
  transform: scale(.92) translateY(16px); opacity: 0;
  pointer-events: none;
  transition: transform .22s cubic-bezier(.4,0,.2,1), opacity .22s;
  max-height: 520px;
}
#ai-chat-window.open {
  transform: scale(1) translateY(0); opacity: 1; pointer-events: all;
}

/* ── Header ── */
.ai-chat-header {
  background: linear-gradient(135deg, #1a9e5c, #0d7a44);
  padding: 14px 16px; display: flex; align-items: center; gap: 10px;
  flex-shrink: 0;
}
.ai-chat-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: rgba(255,255,255,.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 17px; flex-shrink: 0;
}
.ai-chat-header-info { flex: 1; }
.ai-chat-header-name { font-size: 13px; font-weight: 700; color: #fff; }
.ai-chat-header-sub  { font-size: 11px; color: rgba(255,255,255,.7); margin-top: 1px; }
.ai-chat-close-btn {
  background: rgba(255,255,255,.15); border: none; color: #fff;
  width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
  font-size: 14px; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: background .15s;
}
.ai-chat-close-btn:hover { background: rgba(255,255,255,.28); }

/* ── Messages ── */
.ai-chat-messages {
  flex: 1; overflow-y: auto; padding: 14px 14px 8px;
  display: flex; flex-direction: column; gap: 10px;
  scroll-behavior: smooth;
}
.ai-chat-messages::-webkit-scrollbar { width: 4px; }
.ai-chat-messages::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

.ai-msg { display: flex; gap: 8px; align-items: flex-end; }
.ai-msg.user { flex-direction: row-reverse; }
.ai-msg-avatar {
  width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center; font-size: 13px;
  background: var(--green-lt); color: var(--green);
}
.ai-msg.user .ai-msg-avatar { background: var(--blue-lt); color: var(--blue); }
.ai-msg-bubble {
  max-width: 78%; padding: 9px 12px; border-radius: 12px;
  font-size: 13px; line-height: 1.55; color: var(--text);
  background: var(--surface2); border: 1px solid var(--border);
  word-break: break-word;
}
.ai-msg.user .ai-msg-bubble {
  background: var(--green); color: #fff; border-color: var(--green);
}
.ai-msg-time {
  font-size: 10px; color: var(--muted); margin-top: 3px;
  text-align: right;
}
.ai-msg.user .ai-msg-time { text-align: left; }

/* Typing indicator */
.ai-typing-dots { display: flex; gap: 4px; align-items: center; padding: 4px 2px; }
.ai-typing-dots span {
  width: 7px; height: 7px; border-radius: 50%; background: var(--muted);
  animation: aiDot .9s infinite ease-in-out;
}
.ai-typing-dots span:nth-child(2) { animation-delay: .15s; }
.ai-typing-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes aiDot { 0%,80%,100%{transform:scale(.7);opacity:.5} 40%{transform:scale(1);opacity:1} }

/* ── Suggestions ── */
.ai-suggestions {
  display: flex; flex-wrap: wrap; gap: 6px;
  padding: 0 14px 10px; flex-shrink: 0;
}
.ai-suggestion-btn {
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 20px; padding: 5px 11px; font-size: 11px;
  font-weight: 600; color: var(--text); cursor: pointer;
  transition: all .15s; font-family: 'DM Sans', sans-serif;
  white-space: nowrap;
}
.ai-suggestion-btn:hover { background: var(--green-lt); color: var(--green); border-color: var(--green); }

/* ── Input ── */
.ai-chat-input-row {
  display: flex; gap: 8px; padding: 10px 14px 14px;
  border-top: 1px solid var(--border); flex-shrink: 0;
}
#ai-chat-input {
  flex: 1; padding: 9px 12px; border-radius: 22px;
  border: 1px solid var(--border); background: var(--surface2);
  font-size: 13px; color: var(--text); font-family: 'DM Sans', sans-serif;
  outline: none; resize: none; transition: border-color .15s;
  line-height: 1.4; max-height: 80px;
}
#ai-chat-input:focus { border-color: var(--green); background: var(--surface); }
#ai-chat-input::placeholder { color: var(--muted); }
#ai-send-btn {
  width: 38px; height: 38px; border-radius: 50%;
  background: var(--green); border: none; color: #fff;
  cursor: pointer; font-size: 14px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: opacity .15s; align-self: flex-end;
}
#ai-send-btn:hover { opacity: .85; }
#ai-send-btn:disabled { opacity: .4; cursor: not-allowed; }

/* ── Mobile ── */
@media(max-width: 480px) {
  #ai-chat-window { bottom: 84px; right: 12px; width: calc(100vw - 24px); }
  #ai-bubble-btn  { bottom: 16px; right: 16px; width: 50px; height: 50px; font-size: 20px; }
}
</style>

<!-- Bubble Toggle Button -->
<button id="ai-bubble-btn" title="Ask AI Assistant" aria-label="Open AI Assistant">
  <i class="fas fa-seedling"></i>
  <span id="ai-bubble-badge">1</span>
</button>

<!-- Chat Window -->
<div id="ai-chat-window" role="dialog" aria-label="MushroomOS AI Assistant">
  <!-- Header -->
  <div class="ai-chat-header">
    <div class="ai-chat-avatar"><i class="fas fa-seedling"></i></div>
    <div class="ai-chat-header-info">
      <div class="ai-chat-header-name">MushroomOS Assistant</div>
      <div class="ai-chat-header-sub">Powered by Groq AI</div>
    </div>
    <button class="ai-chat-close-btn" id="ai-close-btn" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>

  <!-- Messages -->
  <div class="ai-chat-messages" id="ai-chat-messages">
    <!-- Welcome message injected by JS -->
  </div>

  <!-- Quick Suggestions -->
  <div class="ai-suggestions" id="ai-suggestions">
    <button class="ai-suggestion-btn" data-msg="What are the ideal temperature and humidity for oyster mushrooms?">🌡️ Ideal conditions</button>
    <button class="ai-suggestion-btn" data-msg="What does it mean if humidity is too low in mushroom cultivation?">💧 Low humidity</button>
    <button class="ai-suggestion-btn" data-msg="How do I know when mushrooms are ready to harvest?">🍄 Harvest tips</button>
    <button class="ai-suggestion-btn" data-msg="What causes contamination in mushroom farms and how to prevent it?">⚠️ Contamination</button>
  </div>

  <!-- Input -->
  <div class="ai-chat-input-row">
    <textarea id="ai-chat-input" placeholder="Ask about mushroom farming…" rows="1"></textarea>
    <button id="ai-send-btn" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
  </div>
</div>

<script>
(function () {
  // ── CONFIG ──
  const GROQ_API_KEY = '<?= htmlspecialchars(ss($ss, 'groq_api_key', '')) ?>';
  const GROQ_MODEL   = 'llama-3.3-70b-versatile';

  // DEBUG: Check API key loading
  console.log('=== AI Assistant Debug ===');
  console.log('API Key loaded:', GROQ_API_KEY);
  console.log('API Key length:', GROQ_API_KEY.length);
  console.log('API Key format:', GROQ_API_KEY.startsWith('gsk_') ? 'VALID' : 'INVALID');
  console.log('========================');

  const SYSTEM_PROMPT = `<?= getAISystemPrompt($conn, $ss) ?>`;

  // ── State ──
  const messages = []; // { role, content }
  let isTyping = false;

  // ── Elements ──
  const bubbleBtn   = document.getElementById('ai-bubble-btn');
  const chatWindow  = document.getElementById('ai-chat-window');
  const closeBtn    = document.getElementById('ai-close-btn');
  const messagesEl  = document.getElementById('ai-chat-messages');
  const inputEl     = document.getElementById('ai-chat-input');
  const sendBtn     = document.getElementById('ai-send-btn');
  const badge       = document.getElementById('ai-bubble-badge');
  const suggestionsEl = document.getElementById('ai-suggestions');

  // ── Toggle ──
  function openChat() {
    chatWindow.classList.add('open');
    bubbleBtn.classList.add('open');
    bubbleBtn.innerHTML = '<i class="fas fa-times"></i>';
    badge.classList.remove('show');
    setTimeout(() => inputEl.focus(), 220);
  }
  function closeChat() {
    chatWindow.classList.remove('open');
    bubbleBtn.classList.remove('open');
    bubbleBtn.innerHTML = '<i class="fas fa-seedling"></i><span id="ai-bubble-badge" class="' + (badge.classList.contains('show') ? 'show' : '') + '">1</span>';
  }
  bubbleBtn.addEventListener('click', () => chatWindow.classList.contains('open') ? closeChat() : openChat());
  closeBtn.addEventListener('click', closeChat);

  // ── Welcome message ──
  function addMessage(role, text, skipHistory) {
    if (!skipHistory) messages.push({ role, content: text });
    const now = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Manila' });
    const isUser = role === 'user';
    const div = document.createElement('div');
    div.className = 'ai-msg' + (isUser ? ' user' : '');
    div.innerHTML = `
      <div class="ai-msg-avatar">${isUser ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>'}</div>
      <div>
        <div class="ai-msg-bubble">${escapeHtml(text).replace(/\n/g, '<br>')}</div>
        <div class="ai-msg-time">${now}</div>
      </div>`;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return div;
  }

  function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function showTyping() {
    const div = document.createElement('div');
    div.className = 'ai-msg';
    div.id = 'ai-typing';
    div.innerHTML = `
      <div class="ai-msg-avatar"><i class="fas fa-robot"></i></div>
      <div><div class="ai-msg-bubble"><div class="ai-typing-dots"><span></span><span></span><span></span></div></div></div>`;
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }
  function hideTyping() {
    const el = document.getElementById('ai-typing');
    if (el) el.remove();
  }

  // ── API Call ──
  async function sendToGroq(userText) {
    if (isTyping) return;
    isTyping = true;
    sendBtn.disabled = true;
    suggestionsEl.style.display = 'none';

    addMessage('user', userText);
    showTyping();

    try {
      const res = await fetch('https://api.groq.com/openai/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + GROQ_API_KEY,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          model: GROQ_MODEL,
          max_tokens: 512,
          temperature: 0.7,
          messages: [
            { role: 'system', content: SYSTEM_PROMPT },
            ...messages
          ]
        })
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err?.error?.message || 'Groq API error ' + res.status);
      }

      const data = await res.json();
      const reply = data.choices?.[0]?.message?.content?.trim() || 'Sorry, I could not generate a response.';
      hideTyping();
      addMessage('assistant', reply);

    } catch (e) {
      hideTyping();
      addMessage('assistant', '⚠️ ' + (e.message || 'Could not connect. Check your API key or internet connection.'), true);
    }

    isTyping = false;
    sendBtn.disabled = false;
    inputEl.focus();
  }

  // ── Send handlers ──
  function handleSend() {
    const text = inputEl.value.trim();
    if (!text || isTyping) return;
    inputEl.value = '';
    inputEl.style.height = 'auto';
    sendToGroq(text);
  }

  sendBtn.addEventListener('click', handleSend);
  inputEl.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
  });
  // Auto-resize textarea
  inputEl.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 80) + 'px';
  });

  // ── Suggestion buttons ──
  suggestionsEl.querySelectorAll('.ai-suggestion-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const msg = btn.dataset.msg;
      if (msg) sendToGroq(msg);
    });
  });

  // ── Init welcome message ──
  addMessage('assistant', 'Hi! 👋 I\'m your MushroomOS Assistant. Ask me anything about mushroom cultivation, sensor readings, or farm management!', true);
  // Show badge after 2s to draw attention
  setTimeout(() => badge.classList.add('show'), 2000);

})();
</script>