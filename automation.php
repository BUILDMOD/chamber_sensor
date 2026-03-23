<?php
include('includes/auth_check.php');
include('includes/db_connect.php');
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');
$isOwner = isset($_SESSION['role']) && $_SESSION['role'] === 'owner';

// ── Create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS automation_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device ENUM('mist','fan','heater','exhaust') NOT NULL,
    sensor ENUM('temperature','humidity') NOT NULL,
    operator ENUM('below','above') NOT NULL,
    threshold FLOAT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// Add exhaust column if upgrading from old schema
$conn->query("ALTER TABLE automation_rules MODIFY COLUMN device ENUM('mist','fan','heater','exhaust') NOT NULL");
// Remove duration_minutes if exists (no longer needed — devices turn OFF based on sensor, not timer)
$conn->query("ALTER TABLE automation_rules DROP COLUMN IF EXISTS duration_minutes");

$conn->query("CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(20) NOT NULL DEFAULT 'sprayer',
    run_time TIME NOT NULL,
    duration_minutes INT NOT NULL,
    duration_seconds INT NOT NULL,
    days ENUM('daily','weekdays','weekends') NOT NULL DEFAULT 'daily',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE scheduled_tasks ADD COLUMN IF NOT EXISTS duration_minutes INT NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE scheduled_tasks ADD COLUMN IF NOT EXISTS duration_seconds INT NOT NULL DEFAULT 30");
$conn->query("ALTER TABLE scheduled_tasks MODIFY COLUMN duration_minutes INT NOT NULL");
$conn->query("ALTER TABLE scheduled_tasks MODIFY COLUMN duration_seconds INT NOT NULL");

$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
    trigger_detail TEXT DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Load thresholds from DB ──
$thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
        'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
$tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
if ($tr) while ($r2 = $tr->fetch_assoc()) {
    if ($r2['metric']==='temperature')    { $thr['temp_min']=$r2['min_value']; $thr['temp_max']=$r2['max_value']; }
    if ($r2['metric']==='humidity')       { $thr['hum_min']=$r2['min_value'];  $thr['hum_max']=$r2['max_value']; }
    if ($r2['metric']==='emergency_temp') { $thr['emerg_temp_low']=$r2['min_value']; $thr['emerg_temp_high']=$r2['max_value']; }
    if ($r2['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$r2['max_value']; }
}

$errors = []; $success = '';

// ── Resolve Fault ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['resolve_fault'])) {
    $fid = intval($_POST['resolve_fault']);
    $s=$conn->prepare("UPDATE device_faults SET resolved=1 WHERE id=?");
    if($s){$s->bind_param("i",$fid);if($s->execute())$success='Fault marked as resolved.';$s->close();}
}

// ── Add Rule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_rule'])) {
    if (!$isOwner) { $errors[]='Access denied.'; } else {
        $device   = $_POST['device'] ?? '';
        $sensor   = $_POST['sensor'] ?? '';
        $operator = $_POST['operator'] ?? '';
        $threshold= floatval($_POST['threshold'] ?? 0);
        if (!$device||!$sensor||!$operator) $errors[]='All fields required.';
        if (empty($errors)) {
            $s=$conn->prepare("INSERT INTO automation_rules (device,sensor,operator,threshold) VALUES (?,?,?,?)");
            if ($s){$s->bind_param("sssd",$device,$sensor,$operator,$threshold);if($s->execute())$success='Rule added.';else $errors[]='DB error: '.$conn->error;$s->close();}
        }
    }
}

// ── Toggle Rule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_rule'])) {
    $rid = intval($_POST['rule_id']??0); $enabled = intval($_POST['enabled']??0);
    $s=$conn->prepare("UPDATE automation_rules SET enabled=? WHERE id=?");
    if($s){$s->bind_param("ii",$enabled,$rid);$s->execute();$success='Rule updated.';$s->close();}
}

// ── Delete Rule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_rule'])) {
    $rid = intval($_POST['rule_id']??0);
    $s=$conn->prepare("DELETE FROM automation_rules WHERE id=?");
    if($s){$s->bind_param("i",$rid);if($s->execute())$success='Rule deleted.';$s->close();}
}

// ── Add Schedule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_schedule'])) {
    if (!$isOwner) { $errors[]='Access denied.'; } else {
        $device   = 'sprayer';
        $run_time = $_POST['run_time'] ?? '';
        $dur_min  = intval($_POST['dur_min'] ?? 0);
        $dur_sec  = intval($_POST['dur_sec'] ?? 0);
        if ($dur_min <= 0 && $dur_sec <= 0) $dur_sec = 30; // fallback
        $days     = $_POST['days'] ?? 'daily';
        if (!$run_time) $errors[]='Time required.';
        if (empty($errors)) {
            $s=$conn->prepare("INSERT INTO scheduled_tasks (device,run_time,duration_minutes,duration_seconds,days) VALUES (?,?,?,?,?)");
            if($s){$s->bind_param("ssiis",$device,$run_time,$dur_min,$dur_sec,$days);if($s->execute())$success='Schedule added.';else $errors[]='DB error: '.$conn->error;$s->close();}
        }
    }
}
}

// ── Toggle Schedule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_schedule'])) {
    $sid=intval($_POST['sched_id']??0);$enabled=intval($_POST['s_enabled']??0);
    $s=$conn->prepare("UPDATE scheduled_tasks SET enabled=? WHERE id=?");
    if($s){$s->bind_param("ii",$enabled,$sid);$s->execute();$success='Schedule updated.';$s->close();}
}

// ── Delete Schedule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_schedule'])) {
    $sid=intval($_POST['sched_id']??0);
    $s=$conn->prepare("DELETE FROM scheduled_tasks WHERE id=?");
    if($s){$s->bind_param("i",$sid);if($s->execute())$success='Schedule deleted.';$s->close();}
}

// ── Ensure device_faults table exists ──
$conn->query("CREATE TABLE IF NOT EXISTS device_faults (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    fault_type ENUM('no_response','stuck_on') NOT NULL,
    detail VARCHAR(200),
    sensor_val FLOAT,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Log date filter ──
$log_date_from = $_GET['log_from'] ?? date('Y-m-d');
$log_date_to   = $_GET['log_to']   ?? date('Y-m-d');
// Clamp: max 7-day range
$dt_from = new DateTime($log_date_from);
$dt_to   = new DateTime($log_date_to);
if ($dt_to < $dt_from) $dt_to = clone $dt_from;
$diff = $dt_from->diff($dt_to)->days;
if ($diff > 6) $dt_to = (clone $dt_from)->modify('+6 days');
$log_date_from = $dt_from->format('Y-m-d');
$log_date_to   = $dt_to->format('Y-m-d');

// ── Fetch data ──
$rules = [];
$r=$conn->query("SELECT * FROM automation_rules ORDER BY device, id");
if($r) while($row=$r->fetch_assoc()) $rules[]=$row;

// Active (unresolved) faults
$active_faults = [];
$r=$conn->query("SELECT * FROM device_faults WHERE resolved=0 ORDER BY logged_at DESC");
if($r) while($row=$r->fetch_assoc()) $active_faults[]=$row;

$schedules = [];
$r=$conn->query("SELECT * FROM scheduled_tasks ORDER BY run_time");
if($r) while($row=$r->fetch_assoc()) $schedules[]=$row;

// Filtered logs
$device_logs = [];
$log_from_sql = $log_date_from . ' 00:00:00';
$log_to_sql   = $log_date_to   . ' 23:59:59';
$stmt = $conn->prepare("SELECT * FROM device_logs WHERE logged_at BETWEEN ? AND ? ORDER BY logged_at DESC LIMIT 200");
if ($stmt) {
    $stmt->bind_param("ss", $log_from_sql, $log_to_sql);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $device_logs[] = $row;
    $stmt->close();
}

$devices         = ['mist'=>'Mist','fan'=>'Fan','heater'=>'Heater','exhaust'=>'Exhaust'];
$devices_sched   = ['mist'=>'Mist','fan'=>'Fan','heater'=>'Heater','sprayer'=>'Sprayer','exhaust'=>'Exhaust'];
$device_icons    = ['mist'=>'fa-droplet','fan'=>'fa-fan','heater'=>'fa-fire','sprayer'=>'fa-spray-can-sparkles','exhaust'=>'fa-wind'];
$device_colors   = ['mist'=>'blue','fan'=>'green','heater'=>'red','sprayer'=>'amber','exhaust'=>'green'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/img/jwho-favicon.png">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automation</title>
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
.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.flash-ok{background:var(--green-lt);color:var(--green);}
.flash-err{background:var(--red-lt);color:var(--red);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px;}
.card:last-child{margin-bottom:0;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 14px;border-bottom:1px solid var(--border);}
.card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.icon-green{background:var(--green-lt);color:var(--green);}
.icon-blue{background:var(--blue-lt);color:var(--blue);}
.icon-amber{background:var(--amber-lt);color:var(--amber);}
.icon-red{background:var(--red-lt);color:var(--red);}
.card-sub{font-size:11px;color:var(--muted);}

/* Card header right — count + button together */
.card-header-right{display:flex;align-items:center;gap:10px;}

/* Rules list */
.rule-list{display:flex;flex-direction:column;gap:0;}
.rule-item{display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border);}
.rule-item:last-child{border-bottom:none;}
.rule-device-badge{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.rule-text{flex:1;font-size:13px;font-weight:500;color:var(--text);}
.rule-text span{font-weight:700;}
.rule-detail{font-size:11px;color:var(--muted);margin-top:2px;}

/* Toggle switch */
.toggle-switch{position:relative;width:38px;height:22px;display:inline-block;flex-shrink:0;}
.toggle-switch input{display:none;}
.toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:999px;transition:.2s;cursor:pointer;}
.toggle-slider::before{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
.toggle-switch input:checked+.toggle-slider{background:var(--green);}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(16px);}

/* Table */
table.tbl{width:100%;border-collapse:collapse;font-size:13px;}
.tbl thead th{text-align:left;padding:9px 14px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;background:var(--surface2);border-bottom:1px solid var(--border);white-space:nowrap;}
.tbl tbody td{padding:10px 14px;border-bottom:1px solid var(--border);}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr:hover{background:var(--surface2);}
.mono{font-family:'DM Mono',monospace;font-size:12px;}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-on{background:var(--green-lt);color:var(--green);}
.pill-off{background:var(--red-lt);color:var(--red);}
.pill-auto{background:var(--blue-lt);color:var(--blue);}
.pill-manual{background:var(--amber-lt);color:var(--amber);}
.pill-schedule{background:#ede9fe;color:#7c3aed;}
.pill-emergency{background:var(--red-lt);color:var(--red);}
.pill-fault{background:#fff3e0;color:#e65100;}
.fault-banner{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:8px;background:#fff3e0;border:1px solid #ffcc80;margin-bottom:8px;}
.fault-banner i{color:#e65100;font-size:14px;margin-top:1px;flex-shrink:0;}
.fault-banner-text{font-size:12.5px;font-weight:600;color:#bf360c;line-height:1.5;}
.fault-banner-time{font-size:11px;color:#e65100;margin-top:2px;font-family:'DM Mono',monospace;}
.builtin-rule{display:flex;align-items:center;gap:12px;padding:11px 20px;border-bottom:1px solid var(--border);background:var(--surface2);}
.builtin-rule:last-child{border-bottom:none;}
.builtin-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--red-lt);color:var(--red);letter-spacing:.3px;}

/* Forms */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.form-group label{font-size:12px;font-weight:600;color:var(--muted);}
.form-group input,.form-group select{width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color .15s;}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--green);background:var(--surface);}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:'DM Sans',sans-serif;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover{opacity:.88;}
.btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--border);}
.btn-danger{background:var(--red-lt);color:var(--red);border:1px solid rgba(217,48,37,.15);}
.btn-danger:hover{background:var(--red);color:#fff;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn-amber{background:var(--amber);color:#fff;}
.btn-amber:hover{opacity:.88;}
.empty-state{text-align:center;padding:32px 20px;color:var(--muted);}
.empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.35;}
.empty-state span{font-size:13px;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200;}
.modal-backdrop.open{display:flex;}
.modal{background:var(--surface);border-radius:var(--r);padding:24px;width:480px;max-width:94vw;box-shadow:var(--shadow-lg);position:relative;}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;color:var(--muted);cursor:pointer;}
.modal h3{font-size:15px;font-weight:700;margin-bottom:18px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}

/* Date filter bar */
.log-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.log-filter label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.log-filter input[type=date]{padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--surface2);font-size:12px;color:var(--text);font-family:'DM Mono',monospace;cursor:pointer;}
.log-filter input[type=date]:focus{outline:none;border-color:var(--green);}
.log-filter .filter-note{font-size:11px;color:var(--muted);}
.log-count-badge{font-size:11px;color:var(--muted);background:var(--surface2);border:1px solid var(--border);padding:3px 8px;border-radius:20px;font-family:'DM Mono',monospace;}

@media(max-width:900px){.grid-2{grid-template-columns:1fr;}.form-grid-2,.form-grid-3{grid-template-columns:1fr;}}

/* ============================================================
   RESPONSIVE / MOBILE
   ============================================================ */
.hamburger{display:none;position:fixed;top:4px;left:10px;z-index:200;width:38px;height:38px;border-radius:9px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:4px;padding:9px;touch-action:manipulation;pointer-events:auto;}
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
  .page{padding:14px!important;}
  .grid-2{grid-template-columns:1fr!important;gap:10px;}
  .card-header{flex-wrap:wrap;gap:8px;padding:12px 16px 10px;}
  .card-body{padding:12px 16px!important;}
  .card-title{font-size:12px;}
  .log-filter{gap:6px;}
  .log-filter input[type=date]{font-size:11px;padding:5px 8px;}
  div[style*="overflow-x"]{overflow-x:auto!important;-webkit-overflow-scrolling:touch;}
  table.tbl{font-size:12px;min-width:480px;}
  .tbl thead th,.tbl tbody td{padding:8px 10px;}
}

@media(max-width:480px){
  .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
  .topbar-title{font-size:13px;}
  .topbar-time{display:none;}
  .page{padding:10px!important;padding-top:58px!important;}
  .log-filter{flex-direction:column;align-items:flex-start;}
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
    <a href="automation.php" class="active"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<main class="main">
  <header class="topbar">
    <span class="topbar-title">Automation</span>
    <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
  </header>

  <div class="page">
    <?php if($success): ?><div class="flash flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach($errors as $e): ?><div class="flash flash-err"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Active Faults (only shown when faults exist) -->
    <?php if(!empty($active_faults)): ?>
    <div class="card" style="border-color:rgba(230,81,0,.25);margin-bottom:16px;">
      <div class="card-header" style="background:#fff8f0;">
        <div class="card-title" style="color:#e65100;"><span class="icon" style="background:#fff3e0;color:#e65100;"><i class="fas fa-triangle-exclamation"></i></span> Active Device Faults</div>
        <span class="card-sub" style="color:#e65100;"><?=count($active_faults)?> unresolved</span>
      </div>
      <div style="padding:14px 20px;display:flex;flex-direction:column;gap:8px;">
        <?php foreach($active_faults as $fault): $col=$device_colors[$fault['device']]??'blue'; $icon=$device_icons[$fault['device']]??'fa-cog'; ?>
        <div class="fault-banner">
          <i class="fas fa-<?=$icon?>"></i>
          <div>
            <div class="fault-banner-text">
              <strong><?=ucfirst($fault['device'])?></strong> —
              <?=$fault['fault_type']==='stuck_on'?'Stuck ON too long':'Not responding to sensor'?>:
              <?=htmlspecialchars($fault['detail'])?>
            </div>
            <div class="fault-banner-time"><?=$fault['logged_at']?></div>
          </div>
          <?php if($isOwner): ?>
          <form method="POST" style="margin-left:auto;flex-shrink:0;">
            <input type="hidden" name="resolve_fault" value="<?=$fault['id']?>">
            <button type="submit" class="btn btn-ghost btn-sm">Resolve</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Built-in Protections -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-red"><i class="fas fa-shield-halved"></i></span> Built-in Protections</div>
        <span class="card-sub" style="color:var(--green);font-weight:600;"><i class="fas fa-circle" style="font-size:8px;"></i> Always active</span>
      </div>
      <div class="rule-list">
        <div class="builtin-rule">
          <div class="rule-device-badge" style="background:var(--red-lt);color:var(--red);"><i class="fas fa-fire"></i></div>
          <div class="rule-text">Temperature &gt; <?= $thr["emerg_temp_high"] ?>°C → <span>Heater forced OFF</span> <div class="rule-detail">Emergency shutoff — overrides manual mode</div></div>
          <span class="builtin-badge">EMERGENCY</span>
        </div>
        <div class="builtin-rule">
          <div class="rule-device-badge" style="background:var(--blue-lt);color:var(--blue);"><i class="fas fa-droplet"></i></div>
          <div class="rule-text">Humidity &gt; <?= $thr["emerg_hum_high"] ?>% → <span>Mist + Sprayer forced OFF</span> <div class="rule-detail">Emergency shutoff — overrides manual mode</div></div>
          <span class="builtin-badge">EMERGENCY</span>
        </div>
        <div class="builtin-rule">
          <div class="rule-device-badge" style="background:var(--green-lt);color:var(--green);"><i class="fas fa-fan"></i></div>
          <div class="rule-text">Temperature &lt; <?= $thr["emerg_temp_low"] ?>°C → <span>Fan forced OFF</span> <div class="rule-detail">Emergency shutoff — overrides manual mode</div></div>
          <span class="builtin-badge">EMERGENCY</span>
        </div>
        <div class="builtin-rule">
          <div class="rule-device-badge" style="background:#fff3e0;color:#e65100;"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="rule-text">Device ON but sensor not responding after 5 min → <span>Device forced OFF + Buzzer</span> <div class="rule-detail">Fault detection — overrides manual mode</div></div>
          <span class="builtin-badge">FAULT</span>
        </div>
        <div class="builtin-rule" style="border-bottom:none;">
          <div class="rule-device-badge" style="background:#fff3e0;color:#e65100;"><i class="fas fa-clock-rotate-left"></i></div>
          <div class="rule-text">Device ON for 60+ min continuously → <span>Device forced OFF + Buzzer</span> <div class="rule-detail">Stuck-on detection — overrides manual mode</div></div>
          <span class="builtin-badge">FAULT</span>
        </div>
      </div>
    </div>

    <div class="grid-2">
      <!-- Automation Rules -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-blue"><i class="fas fa-bolt"></i></span> Automation Rules</div>
          <div class="card-header-right">
            <span class="card-sub"><?=count($rules)?> rules</span>
            <?php if($isOwner): ?>
            <button class="btn btn-primary btn-sm" id="openAddRule"><i class="fas fa-plus"></i> Add Rule</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if(empty($rules)): ?>
          <div class="empty-state"><i class="fas fa-bolt"></i><span>No rules yet. Add one to automate devices.</span></div>
        <?php else: ?>
        <div class="rule-list">
          <?php foreach($rules as $rule):
            $col = $device_colors[$rule['device']] ?? 'blue';
            $icon= $device_icons[$rule['device']] ?? 'fa-cog';
          ?>
          <div class="rule-item">
            <div class="rule-device-badge" style="background:var(--<?=$col?>-lt);color:var(--<?=$col?>);"><i class="fas <?=$icon?>"></i></div>
            <div class="rule-text">
              Turn <span><?=ucfirst($rule['device'])?> ON</span> when <span><?=$rule['sensor']?></span> is <span><?=$rule['operator']?></span> <span><?=$rule['threshold']?><?=$rule['sensor']==='temperature'?'°C':'%'?></span>
              <div class="rule-detail">Auto OFF when back in range · <?=$rule['enabled']?'<span style="color:var(--green);font-weight:600;">Active</span>':'<span style="color:var(--muted);">Disabled</span>'?></div>
            </div>
            <?php if($isOwner): ?>
            <form method="POST" style="display:flex;align-items:center;gap:8px;">
              <input type="hidden" name="toggle_rule" value="1">
              <input type="hidden" name="rule_id" value="<?=$rule['id']?>">
              <input type="hidden" name="enabled" value="<?=$rule['enabled']?0:1?>">
              <label class="toggle-switch"><input type="checkbox" onchange="this.form.submit()" <?=$rule['enabled']?'checked':''?>><span class="toggle-slider"></span></label>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this rule?')">
              <input type="hidden" name="delete_rule" value="1">
              <input type="hidden" name="rule_id" value="<?=$rule['id']?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Sprayer Schedule -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-amber"><i class="fas fa-spray-can-sparkles"></i></span> Sprayer Schedule</div>
          <div class="card-header-right">
            <span class="card-sub"><?=count($schedules)?> schedules</span>
            <?php if($isOwner): ?>
            <button class="btn btn-amber btn-sm" id="openAddSchedule"><i class="fas fa-plus"></i> Add Spray Schedule</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if(empty($schedules)): ?>
          <div class="empty-state"><i class="fas fa-spray-can-sparkles"></i><span>No spray schedules yet.</span></div>
        <?php else: ?>
        <div class="rule-list">
          <?php foreach($schedules as $sched):
            $col='amber';
            $icon='fa-spray-can-sparkles';
          ?>
          <div class="rule-item">
            <div class="rule-device-badge" style="background:var(--amber-lt);color:var(--amber);"><i class="fas <?=$icon?>"></i></div>
            <div class="rule-text">
              Spray at <span><?=date('g:i A',strtotime($sched['run_time']))?></span> for <span><?php
                $mins = intval($sched['duration_minutes'] ?? 0);
                $secs = intval($sched['duration_seconds'] ?? 30);
                if ($mins > 0 && $secs > 0) echo "{$mins} min {$secs} sec";
                elseif ($mins > 0) echo "{$mins} min";
                else echo "{$secs} sec";
              ?></span>
              <div class="rule-detail"><?=htmlspecialchars($sched['days'])?> · <?=$sched['enabled']?'<span style="color:var(--green);font-weight:600;">Active</span>':'<span style="color:var(--muted);">Disabled</span>'?></div>
            </div>
            <?php if($isOwner): ?>
            <form method="POST" style="display:flex;align-items:center;gap:8px;">
              <input type="hidden" name="toggle_schedule" value="1">
              <input type="hidden" name="sched_id" value="<?=$sched['id']?>">
              <input type="hidden" name="s_enabled" value="<?=$sched['enabled']?0:1?>">
              <label class="toggle-switch"><input type="checkbox" onchange="this.form.submit()" <?=$sched['enabled']?'checked':''?>><span class="toggle-slider"></span></label>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this schedule?')">
              <input type="hidden" name="delete_schedule" value="1">
              <input type="hidden" name="sched_id" value="<?=$sched['id']?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Device Activity Log -->
    <div class="card" id="activityLog">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green"><i class="fas fa-list-check"></i></span> Device Activity Log</div>
        <span class="log-count-badge"><?=count($device_logs)?> events</span>
      </div>
      <!-- Date Filter -->
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);background:var(--surface2);">
        <form method="GET" class="log-filter" id="logFilterForm" action="automation.php#activityLog">
          <label><i class="fas fa-calendar" style="margin-right:4px;"></i> From</label>
          <input type="date" name="log_from" value="<?= htmlspecialchars($log_date_from) ?>"
                 max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
          <label>To</label>
          <input type="date" name="log_to" value="<?= htmlspecialchars($log_date_to) ?>"
                 max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
          <span class="filter-note"><i class="fas fa-info-circle" style="margin-right:3px;"></i>Max 7-day range · up to 200 records</span>
        </form>
      </div>
      <?php if(empty($device_logs)): ?>
        <div class="empty-state"><i class="fas fa-list-check"></i><span>No device activity for this date range.</span></div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="tbl">
          <thead><tr><th>Device</th><th>Action</th><th>Trigger</th><th>Detail</th><th>Duration</th><th>Time</th></tr></thead>
          <tbody>
            <?php foreach($device_logs as $log):
              $col=$device_colors[$log['device']]??'blue';
              $icon=$device_icons[$log['device']]??'fa-cog';
            ?>
            <tr>
              <td>
                <span style="display:inline-flex;align-items:center;gap:6px;">
                  <span style="width:22px;height:22px;border-radius:5px;background:var(--<?=$col?>-lt);color:var(--<?=$col?>);display:inline-flex;align-items:center;justify-content:center;font-size:11px;"><i class="fas <?=$icon?>"></i></span>
                  <span style="font-weight:600;"><?=ucfirst($log['device'])?></span>
                </span>
              </td>
              <td><span class="pill <?=$log['action']==='ON'?'pill-on':'pill-off'?>"><?=$log['action']?></span></td>
              <td><span class="pill pill-<?=$log['trigger_type']?>"><?=ucfirst($log['trigger_type'])?></span></td>
              <td style="color:var(--muted);font-size:12px;"><?=htmlspecialchars($log['trigger_detail']??'—')?></td>
              <td class="mono"><?=$log['duration_seconds']?number_format($log['duration_seconds']).'s':'—'?></td>
              <td class="mono"><?=date('M j, Y — g:i:s A', strtotime($log['logged_at']))?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- Add Rule Modal -->
<div id="addRuleModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="addRuleModal">&times;</button>
    <h3><i class="fas fa-bolt" style="color:var(--blue);margin-right:8px;"></i>Add Automation Rule</h3>
    <form method="POST">
      <input type="hidden" name="add_rule" value="1">
      <div class="form-grid-2">
        <div class="form-group"><label>Device</label>
          <select name="device" id="ruleDevice" required>
            <?php foreach($devices as $k=>$n): ?><option value="<?=$k?>"><?=$n?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Sensor</label>
          <select name="sensor" id="ruleSensor" required><option value="temperature">Temperature</option><option value="humidity">Humidity</option></select>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label>Condition</label>
          <select name="operator" id="ruleOperator" required><option value="below">Below</option><option value="above">Above</option></select>
        </div>
        <div class="form-group"><label>Threshold Value</label><input type="number" name="threshold" id="ruleThreshold" step="0.1" placeholder="e.g. 85" required></div>
      </div>
      <p style="font-size:12px;color:var(--muted);margin-bottom:12px;background:var(--surface2);padding:10px 12px;border-radius:7px;line-height:1.6;">
        <i class="fas fa-circle-info" style="color:var(--blue);margin-right:5px;"></i>
        The device turns <strong>ON</strong> when the condition is met, and turns <strong>OFF automatically</strong> once the sensor reading returns to the ideal range. No timer needed.
        <br>Example: <em>Turn Mist ON when Humidity is below 85 → auto OFF when humidity reaches 85%+</em>
      </p>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addRuleModal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Spray Schedule Modal -->
<div id="addScheduleModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="addScheduleModal">&times;</button>
    <h3><i class="fas fa-spray-can-sparkles" style="color:var(--amber);margin-right:8px;"></i>Add Spray Schedule</h3>
    <form method="POST" id="scheduleForm">
      <input type="hidden" name="add_schedule" value="1">
      <input type="hidden" name="s_device" value="sprayer">
      <input type="hidden" name="run_time" id="run_time_hidden">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Time</label>
          <div style="display:flex;gap:6px;align-items:center;">
            <select id="sched_hour" style="flex:1;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;">
              <?php for($h=1;$h<=12;$h++): ?>
              <option value="<?=$h?>" <?=$h===8?'selected':''?>><?=$h?></option>
              <?php endfor; ?>
            </select>
            <span style="font-weight:700;color:var(--muted);">:</span>
            <select id="sched_min" style="flex:1;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;">
              <option value="00">00</option>
              <option value="15">15</option>
              <option value="30">30</option>
              <option value="45">45</option>
            </select>
            <select id="sched_ampm" style="flex:1;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;">
              <option value="AM">AM</option>
              <option value="PM">PM</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Duration</label>
          <div style="display:flex;gap:6px;align-items:center;">
            <input type="number" name="dur_min" id="sched_dur_min" min="0" value="0" style="flex:1;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;">
            <span style="font-size:12px;color:var(--muted);font-weight:600;white-space:nowrap;">min</span>
            <input type="number" name="dur_sec" id="sched_dur_sec" min="0" max="59" value="30" style="flex:1;padding:9px 10px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;">
            <span style="font-size:12px;color:var(--muted);font-weight:600;white-space:nowrap;">sec</span>
          </div>
          <input type="hidden" name="s_duration" id="s_duration_hidden" value="0">
        </div>
      </div>
      <div class="form-group">
        <label>Days</label>
        <select name="days">
          <option value="daily">Daily</option>
          <option value="weekdays">Weekdays</option>
          <option value="weekends">Weekends</option>
        </select>
      </div>
      <p style="font-size:12px;color:var(--muted);background:var(--surface2);padding:10px 12px;border-radius:7px;line-height:1.6;margin-bottom:12px;">
        <i class="fas fa-circle-info" style="color:var(--blue);margin-right:5px;"></i>
        Recommended: <strong>3x per day</strong> — 8:00 AM, 4:00 PM, 12:00 AM · <strong>1 min</strong> each spray session.
      </p>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addScheduleModal">Cancel</button>
        <button type="submit" class="btn btn-amber" id="scheduleSubmitBtn"><i class="fas fa-plus"></i> Add Spray Schedule</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const el=document.getElementById('phTime');if(!el)return;
  let t=parseInt(el.dataset.serverTs,10)||Date.now();
  const fmt=ms=>new Date(ms).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).replace(',',' —');
  el.textContent=fmt(t);setInterval(()=>{t+=1000;el.textContent=fmt(t);},1000);
})();

// Date range clamp
(function(){
  const fromEl = document.querySelector('input[name="log_from"]');
  const toEl   = document.querySelector('input[name="log_to"]');
  if (!fromEl || !toEl) return;

  function clamp() {
    if (!fromEl.value || !toEl.value) return;
    const from = new Date(fromEl.value);
    const to   = new Date(toEl.value);
    if (to < from) { toEl.value = fromEl.value; }
    const diff = (new Date(toEl.value) - from) / 86400000;
    if (diff > 6) {
      const maxTo = new Date(from); maxTo.setDate(maxTo.getDate() + 6);
      toEl.value = maxTo.toISOString().slice(0,10);
    }
  }

  fromEl.addEventListener('change', function(){ clamp(); this.form.submit(); });
  toEl.addEventListener('change', function(){ clamp(); this.form.submit(); });
})();

function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.querySelectorAll('[data-close]').forEach(el=>el.addEventListener('click',()=>closeModal(el.dataset.close)));
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open');}));
// ── Spray Schedule time + duration picker ──
(function(){
  const form = document.getElementById('scheduleForm');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    // Convert 12hr → 24hr for run_time
    const hour = parseInt(document.getElementById('sched_hour').value);
    const min  = document.getElementById('sched_min').value;
    const ampm = document.getElementById('sched_ampm').value;
    let h24 = hour;
    if (ampm === 'AM' && hour === 12) h24 = 0;
    if (ampm === 'PM' && hour !== 12) h24 = hour + 12;
    document.getElementById('run_time_hidden').value = String(h24).padStart(2,'0') + ':' + min + ':00';

    // Validate duration
    const durMin = parseInt(document.getElementById('sched_dur_min').value) || 0;
    const durSec = parseInt(document.getElementById('sched_dur_sec').value) || 0;
    const totalSec = (durMin * 60) + durSec;
    if (totalSec <= 0) {
      e.preventDefault();
      alert('Duration must be greater than 0 seconds.');
      return;
    }
    // dur_min and dur_sec already have name attributes — PHP reads them directly
  });
})();

document.getElementById('openAddRule')?.addEventListener('click',()=>{ openModal('addRuleModal'); updateRuleDefaults(); });
document.getElementById('openAddSchedule')?.addEventListener('click',()=>openModal('addScheduleModal'));

// ── Smart threshold auto-fill ──
const THR = {
  temp_min: <?= $thr['temp_min'] ?>,
  temp_max: <?= $thr['temp_max'] ?>,
  hum_min:  <?= $thr['hum_min'] ?>,
  hum_max:  <?= $thr['hum_max'] ?>
};

// device → which sensor it uses + which condition + which threshold value
const DEVICE_DEFAULTS = {
  mist:    { sensor: 'humidity',    operator: 'below', value: () => THR.hum_min  },
  fan:     { sensor: 'temperature', operator: 'above', value: () => THR.temp_max },
  heater:  { sensor: 'temperature', operator: 'below', value: () => THR.temp_min },
  exhaust: { sensor: 'temperature', operator: 'above', value: () => THR.temp_max },
};

function updateRuleDefaults() {
  const device   = document.getElementById('ruleDevice');
  const sensor   = document.getElementById('ruleSensor');
  const operator = document.getElementById('ruleOperator');
  const threshold= document.getElementById('ruleThreshold');
  if (!device || !sensor || !operator || !threshold) return;

  const def = DEVICE_DEFAULTS[device.value];
  if (!def) return;

  // Set sensor
  sensor.value = def.sensor;
  // Set operator
  operator.value = def.operator;
  // Set threshold value
  threshold.value = def.value();

  // Update placeholder with unit hint
  const unit = def.sensor === 'temperature' ? '°C' : '%';
  threshold.placeholder = `e.g. ${def.value()}${unit}`;
}

// Auto-update when device changes
document.getElementById('ruleDevice')?.addEventListener('change', updateRuleDefaults);
// Also update when sensor/operator changes manually
document.getElementById('ruleSensor')?.addEventListener('change', function() {
  const threshold = document.getElementById('ruleThreshold');
  const operator  = document.getElementById('ruleOperator');
  if (this.value === 'temperature') {
    threshold.value = operator.value === 'below' ? THR.temp_min : THR.temp_max;
  } else {
    threshold.value = operator.value === 'below' ? THR.hum_min : THR.hum_max;
  }
});
document.getElementById('ruleOperator')?.addEventListener('change', function() {
  const threshold = document.getElementById('ruleThreshold');
  const sensor    = document.getElementById('ruleSensor');
  if (sensor.value === 'temperature') {
    threshold.value = this.value === 'below' ? THR.temp_min : THR.temp_max;
  } else {
    threshold.value = this.value === 'below' ? THR.hum_min : THR.hum_max;
  }
});
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
</body>
</html>