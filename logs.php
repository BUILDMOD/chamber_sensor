<?php
include('includes/auth_check.php');
include('includes/db_connect.php');
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

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

// ── Filters ──
$alert_type   = $_GET['alert_type']   ?? '';
$alert_sev    = $_GET['severity']     ?? '';
$log_type     = $_GET['log_type']     ?? '';
$date_from    = $_GET['date_from']    ?? date('Y-m-d', strtotime('-7 days'));
$date_to      = $_GET['date_to']      ?? date('Y-m-d');

// ── Mark all resolved ──
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
$r = $conn->query("SELECT * FROM alert_logs WHERE $wq ORDER BY logged_at DESC LIMIT 200");
if ($r) while ($row = $r->fetch_assoc()) $alert_logs[] = $row;

// ── Fetch system logs ──
$swhere = ["DATE(logged_at) BETWEEN '$date_from' AND '$date_to'"];
if ($log_type) $swhere[] = "event_type='".addslashes($log_type)."'";
$swq = implode(' AND ', $swhere);
$sys_logs = [];
$r = $conn->query("SELECT * FROM system_logs WHERE $swq ORDER BY logged_at DESC LIMIT 200");
if ($r) while ($row = $r->fetch_assoc()) $sys_logs[] = $row;

// ── Alert stats ──
$unresolved = 0; $critical_count = 0; $warning_count = 0;
$rs = $conn->query("SELECT severity, COUNT(*) as cnt FROM alert_logs WHERE resolved=0 GROUP BY severity");
if ($rs) while ($row=$rs->fetch_assoc()) {
    $unresolved += $row['cnt'];
    if ($row['severity']==='critical') $critical_count = $row['cnt'];
    if ($row['severity']==='warning')  $warning_count  = $row['cnt'];
}

// ── Sensor status ──
$sensor_status = ['online'=>false,'last_reading'=>null,'minutes_ago'=>null];
$rs = $conn->query("SELECT temperature, humidity, timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
if ($rs && $rs->num_rows > 0) {
    $sr = $rs->fetch_assoc();
    $last_ts = strtotime($sr['timestamp']);
    $mins_ago = round((time() - $last_ts) / 60);
    $sensor_status = ['online'=>$mins_ago < 5,'last_reading'=>$sr,'minutes_ago'=>$mins_ago];
}

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
    .sidebar-close{display:none;position:absolute;top:50%;right:14px;transform:translateY(-50%);width:28px;height:28px;border-radius:7px;border:1px solid var(--border);background:var(--surface2);align-items:center;justify-content:center;cursor:pointer;color:var(--muted);font-size:13px;transition:all .15s;}
    .sidebar-close:hover{background:var(--red-lt);color:var(--red);border-color:var(--red);}
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
      .sidebar-close{display:flex;}

      /* Sidebar slides in */
      .sidebar{
        transform:translateX(-100%);
        transition:transform .28s cubic-bezier(.4,0,.2,1);
        z-index:100;
        box-shadow:4px 0 24px rgba(0,0,0,.12);
      }
      .sidebar.open{transform:translateX(0);}
      .hamburger.open{display:none!important;}
      .sidebar.open ~ * .hamburger, .hamburger.open{opacity:0;pointer-events:none;}

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
      /* Logs: always 2x2 grid for 4 stat cards */
      .stats-row{grid-template-columns:1fr 1fr!important;gap:8px;}
      /* Compact stat cards */
      .stat-card{padding:10px 12px!important;gap:8px!important;}
      .stat-icon{width:32px!important;height:32px!important;font-size:13px!important;flex-shrink:0;}
      .stat-label{font-size:10px!important;}
      .stat-val{font-size:20px!important;}
      /* Filter bar inside card-header: stack neatly */
      .card-header{flex-direction:column!important;align-items:stretch!important;padding:12px!important;}
      .card-header .card-title{margin-bottom:8px;}
      .filter-bar{display:grid!important;grid-template-columns:1fr 1fr!important;gap:6px!important;width:100%;}
      .filter-bar input[type=date]{grid-column:span 1;}
      .filter-bar select{grid-column:span 1;}
      .filter-bar span{display:none!important;}
      .filter-bar .btn{grid-column:span 1;}
      .filter-bar a.btn{grid-column:span 1;}

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
    <button class="sidebar-close" id="sidebarClose" aria-label="Close menu"><i class="fas fa-xmark"></i></button>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php" class="active"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
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
                <td><span class="pill <?= $al['resolved'] ? 'pill-resolved' : 'pill-unresolved' ?>"><?= $al['resolved'] ? 'Resolved' : 'Open' ?></span></td>
                <td class="mono"><?= $al['logged_at'] ?></td>
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
                <td class="mono"><?= $sl['logged_at'] ?></td>
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
  const closeBtn = document.getElementById('sidebarClose');
  if(closeBtn) closeBtn.addEventListener('click', closeSidebar);
  overlay.addEventListener('click', closeSidebar);

  // Close sidebar when a nav link is tapped on mobile
  sidebar.querySelectorAll('.sidebar-nav a').forEach(a => {
    a.addEventListener('click', ()=>{ if(window.innerWidth<=768) closeSidebar(); });
  });
})();

</script>
</body>
</html>