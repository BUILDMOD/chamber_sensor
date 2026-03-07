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
    device ENUM('mist','fan','heater','sprayer') NOT NULL,
    sensor ENUM('temperature','humidity') NOT NULL,
    operator ENUM('below','above') NOT NULL,
    threshold FLOAT NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 5,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device ENUM('mist','fan','heater','sprayer') NOT NULL,
    run_time TIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 5,
    days VARCHAR(20) NOT NULL DEFAULT 'daily',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule') NOT NULL DEFAULT 'manual',
    trigger_detail VARCHAR(100),
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$errors = []; $success = '';

// ── Add Rule ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_rule'])) {
    if (!$isOwner) { $errors[]='Access denied.'; } else {
        $device   = $_POST['device'] ?? '';
        $sensor   = $_POST['sensor'] ?? '';
        $operator = $_POST['operator'] ?? '';
        $threshold= floatval($_POST['threshold'] ?? 0);
        $duration = intval($_POST['duration_minutes'] ?? 5);
        if (!$device||!$sensor||!$operator) $errors[]='All fields required.';
        if (empty($errors)) {
            $s=$conn->prepare("INSERT INTO automation_rules (device,sensor,operator,threshold,duration_minutes) VALUES (?,?,?,?,?)");
            if ($s){$s->bind_param("sssdi",$device,$sensor,$operator,$threshold,$duration);if($s->execute())$success='Rule added.';else $errors[]='DB error.';$s->close();}
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
        $device   = $_POST['s_device'] ?? '';
        $run_time = $_POST['run_time'] ?? '';
        $duration = intval($_POST['s_duration'] ?? 5);
        $days     = $_POST['days'] ?? 'daily';
        if (!$device||!$run_time) $errors[]='Device and time required.';
        if (empty($errors)) {
            $s=$conn->prepare("INSERT INTO scheduled_tasks (device,run_time,duration_minutes,days) VALUES (?,?,?,?)");
            if($s){$s->bind_param("ssis",$device,$run_time,$duration,$days);if($s->execute())$success='Schedule added.';else $errors[]='DB error.';$s->close();}
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

// ── Fetch data ──
$rules = [];
$r=$conn->query("SELECT * FROM automation_rules ORDER BY device, id");
if($r) while($row=$r->fetch_assoc()) $rules[]=$row;

$schedules = [];
$r=$conn->query("SELECT * FROM scheduled_tasks ORDER BY run_time");
if($r) while($row=$r->fetch_assoc()) $schedules[]=$row;

$device_logs = [];
$r=$conn->query("SELECT * FROM device_logs ORDER BY logged_at DESC LIMIT 100");
if($r) while($row=$r->fetch_assoc()) $device_logs[]=$row;

// Device stats (last 24h)
$device_stats = [];
foreach(['mist','fan','heater','sprayer'] as $d){
    $rs=$conn->query("SELECT COUNT(*) as toggles FROM device_logs WHERE device='$d' AND logged_at >= NOW() - INTERVAL 24 HOUR");
    $device_stats[$d] = $rs ? $rs->fetch_assoc()['toggles'] : 0;
}

$devices = ['mist'=>'Mist','fan'=>'Fan','heater'=>'Heater','sprayer'=>'Sprayer'];
$device_icons = ['mist'=>'fa-droplet','fan'=>'fa-fan','heater'=>'fa-fire','sprayer'=>'fa-spray-can-sparkles'];
$device_colors= ['mist'=>'blue','fan'=>'green','heater'=>'red','sprayer'=>'amber'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
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
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 20px;box-shadow:var(--shadow);display:flex;align-items:center;gap:14px;}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.stat-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.stat-val{font-size:20px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);}
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
.card-body{padding:20px;}
.card-sub{font-size:11px;color:var(--muted);}

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
.empty-state{text-align:center;padding:32px 20px;color:var(--muted);}
.empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.35;}
.empty-state span{font-size:13px;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200;}
.modal-backdrop.open{display:flex;}
.modal{background:var(--surface);border-radius:var(--r);padding:24px;width:480px;max-width:94vw;box-shadow:var(--shadow-lg);position:relative;}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;color:var(--muted);cursor:pointer;}
.modal h3{font-size:15px;font-weight:700;margin-bottom:18px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}
@media(max-width:900px){.grid-2,.stats-row{grid-template-columns:1fr;}.form-grid-2,.form-grid-3{grid-template-columns:1fr;}}

    /* ============================================================
       RESPONSIVE / MOBILE
       ============================================================ */

    /* Hamburger button */
    .hamburger{
      display:none;position:fixed;top:4px;left:10px;z-index:500;
      width:38px;height:38px;border-radius:9px;
      background:var(--surface);border:1px solid var(--border);
      box-shadow:var(--shadow);
      align-items:center;justify-content:center;
      cursor:pointer;flex-direction:column;gap:4px;padding:9px;
      touch-action:manipulation;
      pointer-events:auto;
    }
    .hamburger span{display:block;width:16px;height:2px;background:var(--text);border-radius:2px;transition:all .25s;}
    .hamburger.open span:nth-child(1){transform:translateY(6px) rotate(45deg);}
    .hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .hamburger.open span:nth-child(3){transform:translateY(-6px) rotate(-45deg);}

    /* Overlay behind sidebar */
    .sidebar-overlay{
      display:none;position:fixed;inset:0;
      background:rgba(0,0,0,.4);z-index:99;
      backdrop-filter:blur(3px);
      -webkit-backdrop-filter:blur(3px);
    }
    .sidebar-overlay.open{display:block;}

    @media(max-width:768px){
      /* Show hamburger */
      .hamburger{display:flex;}

      /* Sidebar slides in */
      .sidebar{
        transform:translateX(-100%);
        transition:transform .28s cubic-bezier(.4,0,.2,1);
        z-index:100;
        box-shadow:4px 0 24px rgba(0,0,0,.12);
      }
      .sidebar.open{transform:translateX(0);}

      /* Main fills full width */
      .main{margin-left:0!important;width:100%!important;overflow-x:hidden;}

      /* Topbar — room for hamburger on left */
      .topbar{padding:0 10px 0 58px;height:52px;gap:6px;position:fixed!important;top:0;left:0;right:0;z-index:40;}
      .topbar-title{font-size:14px;}
      .topbar-right{gap:6px;}
      .topbar-time{font-size:11px;padding:4px 10px;}
      .user-badge{padding:4px 10px;font-size:11px;}
      .user-badge .role-pill{display:none;}
      /* Hide button text labels on mobile, show icon only */
      .btn-label{display:none;}
      .btn{padding:7px 10px;gap:0;}
      .topbar .btn{min-width:34px;justify-content:center;}

      /* Page & grid padding */
      .page{padding:14px!important;}
      .grid{padding:14px!important;gap:10px!important;}

      /* All columns go full width */
      .col-3,.col-4,.col-5,.col-6,.col-7,.col-8,.col-9,.col-12{grid-column:span 12!important;}

      /* Stats row — 2 columns on tablet, handled below for phone */
      .stats-row{grid-template-columns:1fr 1fr!important;gap:10px;}

      /* Gauges — side by side and compact */
      .gauges-row{flex-direction:row;gap:8px;}
      .gauge-item{flex:1;padding:10px 6px;}
      .gauge-wrap{width:100px;height:62px;}
      .gauge-val{font-size:15px;}
      .gauge-label{font-size:10px;}
      .gauge-status{font-size:10px;}

      /* Cards */
      .card-header{flex-wrap:wrap;gap:8px;padding:12px 16px 10px;}
      .card-body{padding:12px 16px!important;}
      .card-title{font-size:12px;}

      /* Filters */
      .filter-bar{flex-direction:column;align-items:stretch!important;gap:8px;}
      .filter-bar select,.filter-bar input[type=date]{width:100%;font-size:12px;}

      /* Profile layout */
      .profile-layout{grid-template-columns:1fr!important;}
      .form-grid-2,.form-grid-3{grid-template-columns:1fr!important;}

      /* Tabs */
      .tab-bar{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;}
      .tab{padding:6px 14px;font-size:12px;}

      /* Tables — horizontal scroll */
      div[style*="overflow-x"]{overflow-x:auto!important;-webkit-overflow-scrolling:touch;}
      table.tbl{font-size:12px;min-width:480px;}
      .tbl thead th,.tbl tbody td{padding:8px 10px;}

      /* Devices */
      .device-row{padding:8px 10px;}
      .device-name{font-size:12px;}
      .mode-row{padding:8px 12px;}

      /* Sensor status bar */
      .sensor-status-bar{flex-wrap:wrap;gap:6px;padding:10px 14px;}
      .sensor-reading{font-size:12px;}

      /* Stat cards */
      .stat-card{padding:12px 14px;gap:10px;}
      .stat-icon{width:36px;height:36px;font-size:14px;}
      .stat-val{font-size:18px;}
      .stat-label{font-size:10px;}
    }

    @media(max-width:480px){
      /* Single column stats on small phones */
      .stats-row{grid-template-columns:1fr!important;}

      /* Topbar compact */
      .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
      .topbar-title{font-size:13px;}
      .topbar-time{display:none;}

      /* Gauges still side by side but smaller */
      .gauge-wrap{width:88px;height:55px;}
      .gauge-val{font-size:13px;}

      /* Page */
      .page{padding:10px!important;padding-top:58px!important;}
      .grid{padding:10px!important;gap:8px!important;}

      /* Buttons */
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


<aside class="sidebar">
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
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if($isOwner): ?>
      <button class="btn btn-primary" id="openAddRule"><i class="fas fa-plus"></i><span class="btn-label"> Add Rule</span></button>
      <button class="btn btn-ghost" id="openAddSchedule"><i class="fas fa-clock"></i><span class="btn-label"> Add Schedule</span></button>
      <?php endif; ?>
      <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
    </div>
  </header>

  <div class="page">
    <?php if($success): ?><div class="flash flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach($errors as $e): ?><div class="flash flash-err"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Device Stats 24h -->
    <div class="stats-row">
      <?php foreach($devices as $key=>$name):
        $col = $device_colors[$key]; $icon = $device_icons[$key];
      ?>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--<?=$col?>-lt);color:var(--<?=$col?>);"><i class="fas <?=$icon?>"></i></div>
        <div>
          <div class="stat-label"><?=$name?> Toggles</div>
          <div class="stat-val"><?=$device_stats[$key]?><span style="font-size:11px;"> /24h</span></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="grid-2">
      <!-- Automation Rules -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-blue"><i class="fas fa-bolt"></i></span> Automation Rules</div>
          <span class="card-sub"><?=count($rules)?> rules</span>
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
              <div class="rule-detail">Run for <?=$rule['duration_minutes']?> min · <?=$rule['enabled']?'<span style="color:var(--green);font-weight:600;">Active</span>':'<span style="color:var(--muted);">Disabled</span>'?></div>
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

      <!-- Scheduled Tasks -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-amber"><i class="fas fa-clock"></i></span> Scheduled Tasks</div>
          <span class="card-sub"><?=count($schedules)?> schedules</span>
        </div>
        <?php if(empty($schedules)): ?>
          <div class="empty-state"><i class="fas fa-clock"></i><span>No schedules yet.</span></div>
        <?php else: ?>
        <div class="rule-list">
          <?php foreach($schedules as $sched):
            $col=$device_colors[$sched['device']]??'blue';
            $icon=$device_icons[$sched['device']]??'fa-cog';
          ?>
          <div class="rule-item">
            <div class="rule-device-badge" style="background:var(--<?=$col?>-lt);color:var(--<?=$col?>);"><i class="fas <?=$icon?>"></i></div>
            <div class="rule-text">
              <span><?=ucfirst($sched['device'])?></span> at <span><?=date('g:i A',strtotime($sched['run_time']))?></span> for <span><?=$sched['duration_minutes']?> min</span>
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
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green"><i class="fas fa-list-check"></i></span> Device Activity Log</div>
        <span class="card-sub">Last 100 events</span>
      </div>
      <?php if(empty($device_logs)): ?>
        <div class="empty-state"><i class="fas fa-list-check"></i><span>No device activity yet.</span></div>
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
              <td class="mono"><?=$log['logged_at']?></td>
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
          <select name="device" required>
            <?php foreach($devices as $k=>$n): ?><option value="<?=$k?>"><?=$n?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Sensor</label>
          <select name="sensor" required><option value="temperature">Temperature</option><option value="humidity">Humidity</option></select>
        </div>
      </div>
      <div class="form-grid-3">
        <div class="form-group"><label>Condition</label>
          <select name="operator" required><option value="below">Below</option><option value="above">Above</option></select>
        </div>
        <div class="form-group"><label>Value</label><input type="number" name="threshold" step="0.1" placeholder="e.g. 85" required></div>
        <div class="form-group"><label>Run (min)</label><input type="number" name="duration_minutes" min="1" value="5" required></div>
      </div>
      <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Example: Turn Mist ON when Humidity is below 85, run for 5 minutes.</p>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addRuleModal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Rule</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Schedule Modal -->
<div id="addScheduleModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="addScheduleModal">&times;</button>
    <h3><i class="fas fa-clock" style="color:var(--amber);margin-right:8px;"></i>Add Schedule</h3>
    <form method="POST">
      <input type="hidden" name="add_schedule" value="1">
      <div class="form-grid-2">
        <div class="form-group"><label>Device</label>
          <select name="s_device" required>
            <?php foreach($devices as $k=>$n): ?><option value="<?=$k?>"><?=$n?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Time</label><input type="time" name="run_time" required></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label>Duration (min)</label><input type="number" name="s_duration" min="1" value="5" required></div>
        <div class="form-group"><label>Days</label>
          <select name="days">
            <option value="daily">Daily</option>
            <option value="weekdays">Weekdays</option>
            <option value="weekends">Weekends</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addScheduleModal">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:var(--amber);"><i class="fas fa-clock"></i> Add Schedule</button>
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
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.querySelectorAll('[data-close]').forEach(el=>el.addEventListener('click',()=>closeModal(el.dataset.close)));
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open');}));
document.getElementById('openAddRule')?.addEventListener('click',()=>openModal('addRuleModal'));
document.getElementById('openAddSchedule')?.addEventListener('click',()=>openModal('addScheduleModal'));

// ── Mobile sidebar toggle ──
(function(){
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.querySelector('.sidebar');
  const overlay   = document.getElementById('sidebarOverlay');
  if(!hamburger||!sidebar||!overlay) return;

  function openSidebar(){
    sidebar.classList.add('open');
    overlay.classList.add('open');
    hamburger.classList.add('open');
  }
  function closeSidebar(){
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    hamburger.classList.remove('open');
  }

  hamburger.addEventListener('click', ()=> sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
  overlay.addEventListener('click', closeSidebar);

  // Close sidebar when a nav link is tapped on mobile
  sidebar.querySelectorAll('.sidebar-nav a').forEach(a => {
    a.addEventListener('click', ()=>{ if(window.innerWidth<=768) closeSidebar(); });
  });
})();

</script>
</body>
</html>