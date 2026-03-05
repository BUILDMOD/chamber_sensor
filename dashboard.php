<?php
// dashboard_redesign.php
include('includes/auth_check.php');
include('includes/db_connect.php');

$createMushroomTableSql = "CREATE TABLE IF NOT EXISTS mushroom_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_date DATE NOT NULL,
    mushroom_count INT UNSIGNED NOT NULL DEFAULT 0,
    growth_stage ENUM('Spawn Run','Pinning','Fruiting','Harvest') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if (!$conn->query($createMushroomTableSql)) { /* silent */ }

if (!ini_get('date.timezone')) date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

$displayName = 'Menu';
if (session_status() === PHP_SESSION_NONE) @session_start();
if (!empty($_SESSION['fullname']))     $displayName = $_SESSION['fullname'];
elseif (!empty($_SESSION['user']))     $displayName = $_SESSION['user'];
$sessionRole = $_SESSION['role'] ?? 'staff';
$isOwner = $sessionRole === 'owner';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mushroom Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg:#f0f2f5; --surface:#ffffff; --surface2:#f7f8fa;
      --border:rgba(0,0,0,0.07); --text:#0d1117; --muted:#6e7681;
      --green:#1a9e5c; --green-lt:#e6f7ef;
      --red:#d93025;   --red-lt:#fdecea;
      --amber:#b45309; --amber-lt:#fef3c7;
      --blue:#1a6bba;  --blue-lt:#e8f1fb;
      --accent:#1a9e5c; --r:12px;
      --shadow:0 1px 3px rgba(0,0,0,0.06),0 4px 16px rgba(0,0,0,0.04);
      --shadow-lg:0 2px 8px rgba(0,0,0,0.08),0 12px 40px rgba(0,0,0,0.06);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}

    /* SIDEBAR */
    .sidebar{position:fixed;inset:0 auto 0 0;width:220px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:50;}
    .sidebar-logo{padding:22px 20px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);}
    .sidebar-logo img{width:36px;height:36px;border-radius:8px;}
    .sidebar-logo-text{font-size:14px;font-weight:700;color:var(--text);line-height:1.2;}
    .sidebar-logo-sub{font-size:11px;color:var(--muted);}
    .sidebar-nav{flex:1;padding:12px 10px;display:flex;flex-direction:column;gap:1px;overflow-y:auto;}
    .sidebar-nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .15s;}
    .sidebar-nav a i{width:16px;text-align:center;font-size:13px;}
    .sidebar-nav a:hover{background:var(--surface2);color:var(--text);}
    .sidebar-nav a.active{background:var(--green-lt);color:var(--green);font-weight:600;}
    .sidebar-nav .nav-bottom{margin-top:auto;padding-top:8px;border-top:1px solid var(--border);}

    /* MAIN */
    .main{margin-left:220px;min-height:100vh;}

    /* TOPBAR */
    .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40;}
    .topbar-title{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.2px;}
    .topbar-time{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--surface2);padding:5px 12px;border-radius:20px;border:1px solid var(--border);}
    .topbar-right{display:flex;align-items:center;gap:10px;}
    .user-badge{display:flex;align-items:center;gap:8px;padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:var(--surface2);font-size:12px;font-weight:600;color:var(--text);}
    .role-pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;}
    .role-owner{background:var(--amber-lt);color:var(--amber);}
    .role-staff{background:var(--blue-lt);color:var(--blue);}

    /* GRID */
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px;padding:24px 28px;max-width:1280px;}
    .col-3{grid-column:span 3;} .col-4{grid-column:span 4;} .col-5{grid-column:span 5;}
    .col-6{grid-column:span 6;} .col-7{grid-column:span 7;} .col-12{grid-column:span 12;}

    /* CARD */
    .card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 12px;border-bottom:1px solid var(--border);}
    .card-title{font-size:13px;font-weight:700;color:var(--text);letter-spacing:-.1px;display:flex;align-items:center;gap:7px;}
    .card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
    .icon-green{background:var(--green-lt);color:var(--green);}
    .icon-orange{background:var(--amber-lt);color:var(--amber);}
    .icon-red{background:var(--red-lt);color:var(--red);}
    .icon-blue{background:var(--blue-lt);color:var(--blue);}
    .card-body{padding:16px 20px;}
    .info-btn{width:24px;height:24px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);cursor:pointer;font-size:11px;transition:all .15s;flex-shrink:0;}
    .info-btn:hover{background:var(--border);color:var(--text);}

    /* GAUGES */
    .gauges-row{display:flex;gap:12px;}
    .gauge-item{flex:1;text-align:center;background:var(--surface2);border-radius:10px;padding:14px 10px;}
    .gauge-wrap{position:relative;width:130px;height:80px;margin:0 auto;}
    .gauge-wrap canvas{display:block;width:100%!important;height:100%!important;}
    .gauge-val{position:absolute;left:50%;top:60%;transform:translate(-50%,-50%);font-size:17px;font-weight:700;color:var(--text);font-family:'DM Mono',monospace;}
    .gauge-label{font-size:11px;font-weight:600;color:var(--muted);margin-top:6px;text-transform:uppercase;letter-spacing:.5px;}
    .gauge-status{display:inline-block;margin-top:5px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;}
    .gs-ideal{background:var(--green-lt);color:var(--green);}
    .gs-low{background:var(--blue-lt);color:var(--blue);}
    .gs-high{background:var(--red-lt);color:var(--red);}
    .gs-offline{background:var(--surface2);color:var(--muted);}
    .last-update{font-size:11px;color:var(--muted);margin-top:10px;text-align:center;}

    /* DEVICE CONTROL */
    .mode-row{display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border-radius:8px;padding:10px 14px;margin-bottom:14px;}
    .mode-row span{font-size:13px;font-weight:600;color:var(--text);}
    .switch{position:relative;width:44px;height:24px;display:inline-block;}
    .switch input{display:none;}
    .slider{position:absolute;inset:0;background:#d1d5db;border-radius:999px;transition:.25s;}
    .slider::before{content:"";position:absolute;left:3px;top:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 4px rgba(0,0,0,0.15);}
    .switch input:checked+.slider{background:var(--accent);}
    .switch input:checked+.slider::before{transform:translateX(20px);}
    .devices{display:flex;flex-direction:column;gap:8px;}
    .device-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--surface2);border-radius:8px;}
    .device-name{font-size:13px;font-weight:600;flex:1;}
    .device-time{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;}
    .toggle-btn{border:1px solid var(--border);background:var(--surface);border-radius:7px;padding:5px 14px;font-size:12px;font-weight:600;color:var(--text);cursor:pointer;transition:all .15s;}
    .toggle-btn:hover{background:var(--text);color:var(--surface);border-color:var(--text);}
    .toggle-btn.active{background:var(--green);color:#fff;border-color:var(--green);}
    .status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
    .dot{width:6px;height:6px;border-radius:50%;}
    .pill-on{background:var(--green-lt);color:var(--green);}
    .pill-on .dot{background:var(--green);}
    .pill-off{background:var(--red-lt);color:var(--red);}
    .pill-off .dot{background:var(--red);}
    .pill-unk{background:var(--surface2);color:var(--muted);}
    .pill-unk .dot{background:#ccc;}
    .control-note{font-size:11px;color:var(--muted);margin-top:12px;line-height:1.5;}

    /* ALERTS */
    .alert-list{display:flex;flex-direction:column;gap:8px;}
    .alert-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:8px;font-size:12.5px;font-weight:600;word-break:break-word;line-height:1.5;}
    .alert-item i{font-size:13px;flex-shrink:0;margin-top:2px;}
    .alert-ok{background:var(--green-lt);color:var(--green);}
    .alert-err{background:var(--red-lt);color:var(--red);}
    .alert-warn{background:var(--amber-lt);color:var(--amber);}

    /* MUSHROOM TABLE */
    .rec-table{width:100%;border-collapse:collapse;font-size:13px;}
    .rec-table thead th{text-align:left;padding:8px 12px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);background:var(--surface2);}
    .rec-table tbody td{padding:10px 12px;border-bottom:1px solid var(--border);}
    .rec-table tbody tr:last-child td{border-bottom:none;}
    .rec-table tbody tr:hover{background:var(--surface2);}
    .count-badge{display:inline-block;background:var(--green);color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;font-family:'DM Mono',monospace;}
    .stage-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--blue-lt);color:var(--blue);}
    .empty-state{text-align:center;padding:32px;color:var(--muted);}
    .empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.4;}
    .empty-state span{font-size:13px;}

    /* DATE PICKER INPUT — matches reference screenshot */
    .date-picker-wrap {
      display: flex; align-items: center; gap: 8px;
    }
    input[type="date"].dash-datepicker {
      font-family: 'DM Sans', sans-serif;
      font-size: 13px; font-weight: 500;
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 7px;
      padding: 5px 10px;
      cursor: pointer;
      outline: none;
      transition: border-color .15s, box-shadow .15s;
      min-width: 130px;
    }
    input[type="date"].dash-datepicker:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(26,107,186,0.1);
    }
    input[type="date"].dash-datepicker::-webkit-calendar-picker-indicator {
      opacity: 0.5; cursor: pointer;
    }

    /* DETAIL PANEL */
    .rec-detail-table{width:100%;border-collapse:collapse;font-size:12px;}
    .rec-detail-table th{text-align:left;padding:6px 8px;font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;background:var(--surface2);border-bottom:1px solid var(--border);}
    .rec-detail-table td{padding:7px 8px;border-bottom:1px solid var(--border);}
    .rec-detail-table tr:last-child td{border-bottom:none;}
    .detail-clear-btn{background:none;border:none;cursor:pointer;font-size:11px;font-weight:600;color:var(--blue);padding:2px 6px;border-radius:4px;}
    .detail-clear-btn:hover{background:var(--blue-lt);}

    /* IMAGE GRID */
    .img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
    .img-card{background:var(--surface);border-radius:10px;overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow);transition:transform .15s,box-shadow .15s;}
    .img-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
    .img-card img{width:100%;height:130px;object-fit:cover;display:block;}
    .img-info{padding:10px 12px;}
    .img-size{font-size:14px;font-weight:700;margin-bottom:4px;}
    .img-ts{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;}
    .img-conf{font-size:11px;color:var(--muted);margin-top:3px;}
    .harvest-ready{background:var(--green-lt);color:var(--green);}
    .harvest-almost{background:var(--amber-lt);color:var(--amber);}
    .harvest-not{background:var(--blue-lt);color:var(--blue);}
    .harvest-over{background:var(--red-lt);color:var(--red);}

    /* MODAL */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:200;opacity:0;visibility:hidden;transition:opacity .2s,visibility .2s;}
    .modal-backdrop.show{opacity:1;visibility:visible;}
    .modal{background:var(--surface);border-radius:var(--r);padding:24px;max-width:380px;width:90%;box-shadow:var(--shadow-lg);position:relative;transform:translateY(8px);transition:transform .2s;}
    .modal-backdrop.show .modal{transform:translateY(0);}
    .modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;color:var(--muted);cursor:pointer;}
    .modal-close:hover{color:var(--text);}
    .modal h3{font-size:16px;font-weight:700;margin-bottom:16px;}
    .modal h4{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px;}
    .modal p,.modal li{font-size:13px;color:var(--muted);line-height:1.6;}
    .modal ul{padding-left:16px;}
    .legend-row{display:flex;align-items:center;gap:8px;margin-bottom:5px;}
    .leg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
    .legend-row span{font-size:12px;color:var(--muted);}




    /* RECORD FORM */
    .rec-form{display:flex;flex-direction:column;gap:8px;padding:12px;background:var(--surface2);border-radius:8px;margin-bottom:12px;border:1px solid var(--border);}
    .rec-form-row{display:flex;gap:8px;align-items:center;}
    .rec-form label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
    .rec-form input[type=number],.rec-form select,.rec-form textarea{flex:1;padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--surface);font-size:12px;color:var(--text);font-family:'DM Sans',sans-serif;outline:none;transition:border-color .15s;}
    .rec-form input:focus,.rec-form select:focus,.rec-form textarea:focus{border-color:var(--green);}
    .rec-form textarea{resize:none;height:54px;line-height:1.4;}
    .btn-save-rec{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;background:var(--green);color:#fff;font-size:12px;font-weight:700;border:none;cursor:pointer;transition:opacity .15s;font-family:'DM Sans',sans-serif;}
    .btn-save-rec:hover{opacity:.88;}
    .btn-save-rec:disabled{opacity:.5;cursor:not-allowed;}
    .rec-form-msg{font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;display:none;}
    .rec-form-msg.ok{background:var(--green-lt);color:var(--green);display:block;}
    .rec-form-msg.err{background:var(--red-lt);color:var(--red);display:block;}

    @media(max-width:1024px){.col-3,.col-4,.col-5,.col-6,.col-7{grid-column:span 12;}}
  </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="logo">
    <div>
      <div class="sidebar-logo-text">MushroomOS</div>
      <div class="sidebar-logo-sub">Cultivation System</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php" class="active"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<!-- MAIN -->
<main class="main">
  <header class="topbar">
    <span class="topbar-title">Dashboard</span>
    <div class="topbar-right">
      <div class="user-badge">
        <i class="fas fa-circle-user" style="color:var(--muted);font-size:14px;"></i>
        <?= htmlspecialchars($displayName) ?>
        <span class="role-pill <?= $isOwner ? 'role-owner' : 'role-staff' ?>">
          <?= $isOwner ? '👑 Owner' : '🧑 Staff' ?>
        </span>
      </div>
      <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
    </div>
  </header>

  <div class="grid">

    <!-- Live Environment -->
    <div class="card col-4">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green"><i class="fas fa-leaf"></i></span> Live Environment</div>
        <button class="info-btn" id="statusInfoIcon"><i class="fas fa-info"></i></button>
      </div>
      <div class="card-body">
        <div class="gauges-row">
          <div class="gauge-item">
            <div class="gauge-wrap"><canvas id="tempGauge"></canvas><div class="gauge-val" id="tempValue">—</div></div>
            <div class="gauge-label">Temperature</div>
            <div class="gauge-status gs-offline" id="tempNote">Offline</div>
          </div>
          <div class="gauge-item">
            <div class="gauge-wrap"><canvas id="humGauge"></canvas><div class="gauge-val" id="humValue">—</div></div>
            <div class="gauge-label">Humidity</div>
            <div class="gauge-status gs-offline" id="humNote">Offline</div>
          </div>
        </div>
        <div class="last-update">Last update: <span id="time">—</span></div>
      </div>
    </div>

    <!-- Device Control -->
    <div class="card col-4">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-blue"><i class="fas fa-sliders"></i></span> Device Control</div>
        <button class="info-btn" id="deviceInfoIcon"><i class="fas fa-info"></i></button>
      </div>
      <div class="card-body" aria-live="polite">
        <div class="mode-row">
          <span id="modeLabel">Auto Mode</span>
          <label class="switch" title="Toggle Manual/Auto">
            <input type="checkbox" id="modeSwitch"><span class="slider"></span>
          </label>
        </div>
        <div id="manualControls" style="display:none;">
          <div class="devices">
            <?php foreach(['mist'=>'Mist','fan'=>'Fan','heater'=>'Heater','sprayer'=>'Sprayer'] as $id=>$name): ?>
            <div class="device-row">
              <span class="device-name"><?= $name ?></span>
              <span class="device-time" id="last_<?= $id ?>">—</span>
              <div id="status_<?= $id ?>" class="status-pill pill-unk"><span class="dot"></span> —</div>
              <button class="toggle-btn" data-device="<?= $id ?>">Toggle</button>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="control-note">System runs automatically. Manual toggles are for emergency override only.</p>
      </div>
    </div>

    <!-- Alerts -->
    <div class="card col-4">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-red"><i class="fas fa-bell"></i></span> Alerts</div>
        <button class="info-btn" id="alertInfoIcon"><i class="fas fa-info"></i></button>
      </div>
      <div class="card-body">
        <div class="alert-list" id="alertList">
          <div class="alert-item alert-ok"><i class="fas fa-check-circle"></i> No alerts</div>
        </div>
      </div>
    </div>

    <!-- Monthly Records -->
    <div class="card col-6">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green">🍄</span> Monthly Records</div>
        <div class="date-picker-wrap">
          <input type="date" id="recDatePicker" class="dash-datepicker" title="Pick a date to view records">
        </div>
      </div>
      <div class="card-body" style="padding:14px 16px;">

        <!-- Log Form -->
        <div class="rec-form" id="recForm" style="display:none;">
          <div class="rec-form-row">
            <label>Date</label>
            <span id="recFormDateLabel" style="font-size:12px;font-weight:600;color:var(--text);flex:1;"></span>
          </div>
          <div class="rec-form-row">
            <label>Count</label>
            <input type="number" id="recCount" min="0" placeholder="e.g. 12">
            <label style="margin-left:8px;">Stage</label>
            <select id="recStage">
              <option value="Spawn Run">Spawn Run</option>
              <option value="Pinning">Pinning</option>
              <option value="Fruiting">Fruiting</option>
              <option value="Harvest">Harvest</option>
            </select>
          </div>
          <div class="rec-form-row">
            <label>Notes</label>
            <textarea id="recNotes" placeholder="Optional notes…"></textarea>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <button class="btn-save-rec" id="recSaveBtn"><i class="fas fa-plus"></i> Save Record</button>
            <span class="rec-form-msg" id="recFormMsg"></span>
          </div>
        </div>

        <!-- Records View -->
        <div id="recDetail">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <span style="font-size:12px;font-weight:700;color:var(--text);" id="recDetailTitle">
              Select a date to view or log records
            </span>
            <button class="detail-clear-btn" id="recClearBtn" style="display:none;">✕ Clear</button>
          </div>
          <div id="recDetailBody">
            <div class="empty-state"><i class="fas fa-seedling"></i><span>Pick a date above to see or add records.</span></div>
          </div>
        </div>

      </div>
    </div>

    <!-- Chamber Camera Analysis -->
    <div class="card col-6">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-blue"><i class="fas fa-camera"></i></span> Chamber Camera Analysis</div>
        <div class="date-picker-wrap">
          <input type="date" id="camDatePicker" class="dash-datepicker" title="Pick a date to view captures">
          <span style="font-size:11px;color:var(--muted);" id="imgLastUpdate">Auto-refreshing…</span>
        </div>
      </div>
      <div class="card-body" style="padding:14px 16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <span style="font-size:12px;font-weight:700;color:var(--text);" id="camDetailTitle">Latest captures</span>
          <button class="detail-clear-btn" id="camClearBtn" style="display:none;">✕ Back to live</button>
        </div>
        <div class="img-grid" id="mushroomImageGrid">
          <div class="empty-state" id="noImages" style="grid-column:1/-1;">
            <i class="fas fa-camera"></i><span>Waiting for camera feed…</span>
          </div>
        </div>
      </div>
    </div>

  </div><!-- end grid -->
</main>

<!-- MODALS -->
<div class="modal-backdrop" id="statusInfoModal">
  <div class="modal">
    <button class="modal-close" data-close="statusInfoModal">&times;</button>
    <h3>Environment Status</h3>
    <h4>Temperature</h4>
    <div class="legend-row"><span class="leg-dot" style="background:#60a5fa"></span><span>Too Low — below 22°C</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#fb7185"></span><span>Ideal — 22–28°C</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#fbbf24"></span><span>Too High — above 28°C</span></div>
    <h4>Humidity</h4>
    <div class="legend-row"><span class="leg-dot" style="background:#60a5fa"></span><span>Too Low — below 85%</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#34d399"></span><span>Ideal — 85–95%</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#fb7185"></span><span>Too High — above 95%</span></div>
  </div>
</div>
<div class="modal-backdrop" id="deviceInfoModal">
  <div class="modal">
    <button class="modal-close" data-close="deviceInfoModal">&times;</button>
    <h3>Device Control</h3>
    <h4>Auto Mode</h4><p>System automatically controls devices based on sensor readings.</p>
    <h4>Manual Mode</h4><p>Override device states for emergencies or adjustments.</p>
    <h4>Devices</h4>
    <ul>
      <li><strong>Mist</strong> – Maintains ideal humidity.</li>
      <li><strong>Fan</strong> – Regulates temperature and airflow.</li>
      <li><strong>Heater</strong> – Adds heat when temp is too low.</li>
      <li><strong>Sprayer</strong> – Moistens mushrooms directly.</li>
    </ul>
  </div>
</div>
<div class="modal-backdrop" id="alertInfoModal">
  <div class="modal">
    <button class="modal-close" data-close="alertInfoModal">&times;</button>
    <h3>Alerts</h3>
    <p>Alerts fire when conditions deviate from ideal ranges.</p>
    <h4>Ideal Ranges</h4>
    <ul><li>Temperature: 22–28°C</li><li>Humidity: 85–95%</li></ul>
    <h4>Color Codes</h4>
    <div class="legend-row"><span class="leg-dot" style="background:var(--red)"></span><span>Critical — out of range</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:var(--green)"></span><span>All clear — conditions ideal</span></div>
  </div>
</div>

<script>
const $$ = id => document.getElementById(id);
const toNum = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };

// PH Time
(function(){
  const el = $$('phTime'); if (!el) return;
  let t = parseInt(el.dataset.serverTs,10)||Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).replace(',','—');
  el.textContent = fmt(t);
  setInterval(()=>{t+=1000;el.textContent=fmt(t);},1000);
})();

// Gauges
function makeGauge(id,color){
  return new Chart(document.getElementById(id).getContext('2d'),{
    type:'doughnut',
    data:{datasets:[{data:[0,100],backgroundColor:[color,'#f0f2f5'],borderWidth:0}]},
    options:{cutout:'75%',rotation:-90,circumference:180,responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}}}
  });
}
const tempGauge = makeGauge('tempGauge','#fb7185');
const humGauge  = makeGauge('humGauge','#34d399');
const tempColor = t => t<22?'#60a5fa':t>28?'#fbbf24':'#fb7185';
const humColor  = h => h<85?'#60a5fa':h>95?'#fb7185':'#34d399';
function gaugeStatusClass(val,low,high){
  if(val<low) return['gs-low','Too Low'];
  if(val>high)return['gs-high','Too High'];
  return['gs-ideal','Ideal'];
}
function setGaugeStatus(el,cls,text){el.className='gauge-status '+cls;el.textContent=text;}

// Live data
async function loadLive(){
  try{
    const r=await fetch('submit_data.php',{cache:'no-store'});
    if(!r.ok)throw 0;
    const d=await r.json();
    const t=Math.max(1,Math.min(50,toNum(d.temperature)));
    const h=Math.max(1,Math.min(100,toNum(d.humidity)));
    $$('tempValue').textContent=t.toFixed(1)+'°';
    $$('humValue').textContent=h.toFixed(1)+'%';
    $$('time').textContent=d.timestamp||'—';
    const[tcls,ttxt]=gaugeStatusClass(t,22,28);
    const[hcls,htxt]=gaugeStatusClass(h,85,95);
    setGaugeStatus($$('tempNote'),tcls,ttxt);
    setGaugeStatus($$('humNote'),hcls,htxt);
    const tPct=Math.round((t-1)/49*100);
    const hPct=Math.round((h-1)/99*100);
    tempGauge.data.datasets[0].data=[tPct,100-tPct];
    tempGauge.data.datasets[0].backgroundColor=[tempColor(t),'#f0f2f5'];
    tempGauge.update();
    humGauge.data.datasets[0].data=[hPct,100-hPct];
    humGauge.data.datasets[0].backgroundColor=[humColor(h),'#f0f2f5'];
    humGauge.update();
    const alerts=[];
    if(t<22||t>28)alerts.push(`Temperature out of range: ${t.toFixed(1)}°C (ideal 22–28°C)`);
    if(h<85||h>95)alerts.push(`Humidity out of range: ${h.toFixed(1)}% (ideal 85–95%)`);
    renderAlerts(alerts);
    if(alerts.length>0){
      fetch('submit_data.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({alerts:JSON.stringify(alerts)})}).catch(()=>{});
    }
  }catch(_){
    ['tempValue','humValue'].forEach(id=>{const e=$$(''+id);if(e)e.textContent='—';});
    $$('time').textContent='Offline';
    setGaugeStatus($$('tempNote'),'gs-offline','Offline');
    setGaugeStatus($$('humNote'),'gs-offline','Offline');
    [tempGauge,humGauge].forEach(g=>{g.data.datasets[0].data=[0,100];g.data.datasets[0].backgroundColor=['#e5e7eb','#f0f2f5'];g.update();});
    renderAlerts(['Device offline']);
  }
}
function renderAlerts(msgs){
  const list=$$('alertList'); list.innerHTML='';
  if(!msgs.length||(msgs.length===1&&msgs[0]==='')){
    list.innerHTML='<div class="alert-item alert-ok"><i class="fas fa-check-circle"></i> No alerts</div>';return;
  }
  if(msgs[0]==='Device offline'){
    list.innerHTML='<div class="alert-item alert-warn"><i class="fas fa-wifi"></i> Device offline</div>';return;
  }
  msgs.forEach(m=>{const d=document.createElement('div');d.className='alert-item alert-err';d.innerHTML=`<i class="fas fa-triangle-exclamation"></i> ${m}`;list.appendChild(d);});
}
loadLive();
setInterval(loadLive,1000);

// Device States
async function fetchDeviceStates(){
  try{
    const r=await fetch('get_device_status.php',{cache:'no-store'});
    if(!r.ok)throw 0;
    const j=await r.json();
    ['mist','fan','heater','sprayer'].forEach(d=>applyPill(d,j[d]));
    const manual=j.manual_mode==1;
    $$('modeSwitch').checked=manual;
    setMode(manual);
  }catch(_){}
}
function applyPill(dev,status){
  const el=$$('status_'+dev); if(!el)return;
  const s=String(status||'').toUpperCase();
  if(['ON','1','TRUE'].includes(s)){el.className='status-pill pill-on';el.innerHTML='<span class="dot"></span> ON';}
  else if(['OFF','0','FALSE'].includes(s)){el.className='status-pill pill-off';el.innerHTML='<span class="dot"></span> OFF';}
  else{el.className='status-pill pill-unk';el.innerHTML='<span class="dot"></span> —';}
}
fetchDeviceStates();
setInterval(fetchDeviceStates,1000);
function setMode(manual){
  $$('modeLabel').textContent=manual?'Manual Mode':'Auto Mode';
  $$('manualControls').style.display=manual?'':'none';
}
$$('modeSwitch').addEventListener('change',async function(){
  setMode(this.checked);
  try{await fetch(`update_device_status.php?mode=${this.checked?1:0}`,{cache:'no-store'});}catch(_){}
});
document.querySelectorAll('.toggle-btn[data-device]').forEach(btn=>{
  btn.addEventListener('click',async function(){
    const dev=this.dataset.device;
    this.classList.add('active');
    setTimeout(()=>this.classList.remove('active'),700);
    try{
      await fetch(`update_device_status.php?device=${encodeURIComponent(dev)}`,{cache:'no-store'});
      const now=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour12:false});
      $$('last_'+dev).textContent=now;
      setTimeout(fetchDeviceStates,700);
    }catch(_){}
  });
});

// Camera auto-feed
function renderCamImages(images){
  const grid=$$('mushroomImageGrid');
  $$('noImages')?.remove();
  if(!images||!images.length){
    grid.innerHTML=`<div class="empty-state" id="noImages" style="grid-column:1/-1;"><i class="fas fa-camera"></i><span>No captures found.</span></div>`;return;
  }
  const statusMap={'Ready for Harvest':['harvest-ready','Ready for Harvest'],'Almost Ready':['harvest-almost','Almost Ready'],'Not Ready':['harvest-not','Not Ready'],'Overripe':['harvest-over','Overripe']};
  grid.innerHTML='';
  images.forEach(img=>{
    const[cls,label]=statusMap[img.harvest_status]||['harvest-not',img.harvest_status||'—'];
    const card=document.createElement('div');
    card.className='img-card';
    card.innerHTML=`<img src="${img.image_path||'#'}" alt="Mushroom" onerror="this.src='assets/img/no-image.png'">
      <div class="img-info">
        <div class="img-size">⌀ ${img.diameter_cm??'—'} cm</div>
        <span class="status-pill ${cls}" style="font-size:11px;padding:2px 8px;">${label}</span>
        <div class="img-ts">${img.analyzed_at||''}</div>
        <div class="img-conf">Confidence: ${img.confidence_score??'—'}%</div>
      </div>`;
    grid.appendChild(card);
  });
}

let camViewingDay = null;

async function loadCameraImages(){
  if(camViewingDay)return;
  try{
    const r=await fetch('process_image.php?limit=6',{cache:'no-store'});
    if(!r.ok)throw 0;
    const d=await r.json();
    if(!d.success||!d.data||!d.data.length)return;
    renderCamImages(d.data);
    const el=$$('imgLastUpdate');
    if(el)el.textContent='Updated: '+new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour12:true});
  }catch(_){}
}
loadCameraImages();
setInterval(loadCameraImages,10000);

// ── Records Date Picker ──
// ── Monthly Records ──
let currentRecDate = null;

async function loadRecords(date) {
  const month = date.slice(0,7);
  const res = await fetch(`get_calendar_data.php?type=records&month=${month}`,{cache:'no-store'});
  const json = await res.json();
  const allData = json.data||[];
  return allData.filter(r=>r.record_date===date);
}

function renderRecords(records) {
  $$('recDetailBody').innerHTML = records.length
    ? `<table class="rec-detail-table">
        <thead><tr><th>Count</th><th>Stage</th><th>Notes</th></tr></thead>
        <tbody>${records.map(r=>`<tr>
          <td><span class="count-badge">${r.mushroom_count}</span></td>
          <td><span class="stage-badge">${r.growth_stage}</span></td>
          <td style="color:var(--muted);">${r.notes||'—'}</td>
        </tr>`).join('')}</tbody>
      </table>`
    : `<p style="font-size:12px;color:var(--muted);padding:8px 0;">No records yet for this date.</p>`;
}

const recPicker = $$('recDatePicker');
recPicker.addEventListener('change', async function(){
  const date = this.value;
  if(!date){ clearRec(); return; }
  currentRecDate = date;
  const d = new Date(date+'T00:00');
  const label = d.toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});
  $$('recDetailTitle').textContent = `Records — ${label}`;
  $$('recClearBtn').style.display = '';
  // Show log form
  $$('recForm').style.display = '';
  $$('recFormDateLabel').textContent = label;
  $$('recCount').value = '';
  $$('recNotes').value = '';
  $$('recFormMsg').className = 'rec-form-msg';
  $$('recFormMsg').textContent = '';
  // Load existing records
  const records = await loadRecords(date);
  renderRecords(records);
});

function clearRec(){
  recPicker.value='';
  currentRecDate = null;
  $$('recDetailTitle').textContent='Select a date to view or log records';
  $$('recClearBtn').style.display='none';
  $$('recForm').style.display='none';
  $$('recDetailBody').innerHTML='<div class="empty-state"><i class="fas fa-seedling"></i><span>Pick a date above to see or add records.</span></div>';
}
$$('recClearBtn').addEventListener('click', clearRec);

// Save record
$$('recSaveBtn').addEventListener('click', async function(){
  if (!currentRecDate) return;
  const count = $$('recCount').value.trim();
  const stage = $$('recStage').value;
  const notes = $$('recNotes').value.trim();

  if (!count || isNaN(count) || parseInt(count) < 0) {
    $$('recFormMsg').className = 'rec-form-msg err';
    $$('recFormMsg').textContent = 'Please enter a valid mushroom count.';
    return;
  }

  this.disabled = true;
  this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  try {
    const body = new URLSearchParams({
      record_date: currentRecDate,
      mushroom_count: parseInt(count),
      growth_stage: stage,
      notes: notes
    });
    const r = await fetch('save_record.php', {method:'POST', body});
    const d = await r.json();

    if (d.success) {
      $$('recFormMsg').className = 'rec-form-msg ok';
      $$('recFormMsg').textContent = '✓ Record saved!';
      $$('recCount').value = '';
      $$('recNotes').value = '';
      // Reload records for this date
      const records = await loadRecords(currentRecDate);
      renderRecords(records);
    } else {
      throw new Error(d.error||'Save failed');
    }
  } catch(e) {
    $$('recFormMsg').className = 'rec-form-msg err';
    $$('recFormMsg').textContent = '✗ ' + e.message;
  }

  this.disabled = false;
  this.innerHTML = '<i class="fas fa-plus"></i> Save Record';
});

// ── Camera Date Picker ──
const camPicker = $$('camDatePicker');
camPicker.addEventListener('change', async function(){
  const date = this.value;
  if(!date){ resetCamToLive(); loadCameraImages(); return; }
  camViewingDay = date;
  const month = date.slice(0,7);
  const d = new Date(date+'T00:00');
  const label = d.toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});
  $$('camDetailTitle').textContent = `Captures — ${label}`;
  $$('camClearBtn').style.display = '';
  $$('imgLastUpdate').textContent = '';
  const res = await fetch(`get_calendar_data.php?type=camera&month=${month}&day=${date}`,{cache:'no-store'});
  const json = await res.json();
  renderCamImages(json.day_images||[]);
});
function resetCamToLive(){
  camViewingDay=null;
  camPicker.value='';
  $$('camDetailTitle').textContent='Latest captures';
  $$('camClearBtn').style.display='none';
  const el=$$('imgLastUpdate');
  if(el)el.textContent='Auto-refreshing…';
}
$$('camClearBtn').addEventListener('click',()=>{ resetCamToLive(); loadCameraImages(); });

// Modals
function bindModal(triggerIds,modalId){
  const modal=$$(modalId); if(!modal)return;
  triggerIds.forEach(tid=>{const el=$$(tid);if(el)el.addEventListener('click',()=>modal.classList.add('show'));});
  modal.querySelectorAll('.modal-close').forEach(b=>b.addEventListener('click',()=>modal.classList.remove('show')));
  modal.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('show');});
}
bindModal(['statusInfoIcon'],'statusInfoModal');
bindModal(['deviceInfoIcon'],'deviceInfoModal');
bindModal(['alertInfoIcon'],'alertInfoModal');


</script>
</body>
</html>