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
// Load camera interval from system_settings
$camera_interval_ms = 10000; // default 10s
$r_ci = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='camera_interval_sec'");
if ($r_ci && $row_ci = $r_ci->fetch_assoc()) {
    $camera_interval_ms = intval($row_ci['setting_value']) * 1000;
}
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

// ── Load thresholds from DB (never hardcoded) ──
$thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
        'emergency_temp_high'=>35,'emergency_temp_low'=>15,'emergency_hum_high'=>98];
$conn2 = null;
try {
    include_once('includes/db_connect.php');
    // Ensure defaults exist
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
        if ($row['metric']==='temperature') { $thr['temp_min']=$row['min_value']; $thr['temp_max']=$row['max_value']; }
        if ($row['metric']==='humidity')    { $thr['hum_min']=$row['min_value'];  $thr['hum_max']=$row['max_value']; }
        if ($row['metric']==='emergency_temp') { $thr['emergency_temp_low']=$row['min_value']; $thr['emergency_temp_high']=$row['max_value']; }
        if ($row['metric']==='emergency_hum')  { $thr['emergency_hum_high']=$row['max_value']; }
    }
} catch(Exception $e) { /* use defaults */ }

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
  <title>Dashboard</title>
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

    /* MAIN */
    .main{margin-left:220px;min-height:100vh;width:calc(100% - 220px);box-sizing:border-box;}

    /* TOPBAR */
    .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:30;}
    .topbar-title{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.2px;}
    .topbar-time{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--surface2);padding:5px 12px;border-radius:20px;border:1px solid var(--border);}
    .topbar-right{display:flex;align-items:center;gap:10px;}
    .user-badge{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:var(--surface2);font-size:12px;font-weight:600;color:var(--text);max-width:280px;overflow:hidden;}
    .user-badge .user-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;}
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
      .sidebar.open ~ * .hamburger, .hamburger.open{display:none!important;}

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
      .topbar{padding:0 10px 0 58px;height:52px;gap:6px;position:fixed!important;top:0;left:0;right:0;z-index:40;overflow:hidden;}
      .topbar-title{font-size:14px;flex-shrink:0;}
      .topbar-right{gap:6px;min-width:0;flex:1;justify-content:flex-end;overflow:hidden;}
      .topbar-time{font-size:11px;padding:4px 10px;flex-shrink:0;}
      .user-badge{padding:4px 8px;font-size:11px;max-width:calc(100vw - 140px);gap:5px;min-width:0;}
      .user-badge .user-name{max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      .user-badge .role-pill{font-size:9px;padding:2px 6px;flex-shrink:0;white-space:nowrap;}
      /* Hide button text labels on mobile, show icon only */
      .btn-label{display:none;}
      .btn{padding:7px 10px;gap:0;}
      .topbar .btn{min-width:34px;justify-content:center;}

      /* Page & grid padding */
      .page{padding:14px!important;}
      .grid{padding:14px!important;padding-top:66px!important;gap:10px!important;}

      /* All columns go full width */
      .col-3,.col-4,.col-5,.col-6,.col-7,.col-8,.col-9,.col-12{grid-column:span 12!important;}

      /* Stats row — 2 columns on tablet, handled below for phone */
      .stats-row{grid-template-columns:1fr 1fr!important;gap:10px;}

      /* Gauges — side by side and compact */
      .gauges-row{flex-direction:row;gap:8px;width:100%;box-sizing:border-box;}
      .gauge-item{flex:1;padding:10px 4px;min-width:0;}
      .gauge-wrap{width:100%;max-width:110px;height:62px;margin:0 auto;}
      .gauge-val{font-size:14px;}
      .gauge-label{font-size:10px;}
      .gauge-status{font-size:10px;white-space:nowrap;}

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
      .user-badge{max-width:calc(100vw - 120px);padding:3px 7px;}
      .user-badge .user-name{max-width:100px;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
      .user-badge .role-pill{font-size:8px;padding:1px 5px;flex-shrink:0;}

      /* Gauges still side by side but smaller */
      .gauge-wrap{width:88px;height:55px;}
      .gauge-val{font-size:13px;}

      /* Page */
      .page{padding:10px!important;}
      .grid{padding:10px!important;padding-top:62px!important;gap:8px!important;}

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


<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
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
        <i class="fas fa-circle-user" style="color:var(--muted);font-size:14px;flex-shrink:0;"></i>
        <span class="user-name"><?= htmlspecialchars($displayName) ?></span>
        <span class="role-pill <?= $isOwner ? 'role-owner' : 'role-staff' ?>" style="flex-shrink:0;">
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
            <?php foreach(['mist'=>'Mist','fan'=>'Fan','heater'=>'Heater','sprayer'=>'Sprayer','exhaust'=>'Exhaust'] as $id=>$name): ?>
            <div class="device-row">
              <span class="device-name"><?= $name ?></span>
              <span class="device-time" id="last_<?= $id ?>">—</span>
              <div id="status_<?= $id ?>" class="status-pill pill-unk"><span class="dot"></span> —</div>
              <button class="toggle-btn" data-device="<?= $id ?>" id="btn_<?= $id ?>">Toggle</button>
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

        <!-- Monthly Summary Bar Chart -->
        <div id="monthlySummaryWrap" style="margin-bottom:16px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Monthly Harvest Comparison</span>
            <div style="display:flex;align-items:center;gap:6px;">
              <button onclick="shiftMonthRange(-1)" style="background:none;border:1px solid var(--border);border-radius:5px;padding:2px 7px;cursor:pointer;font-size:12px;color:var(--muted);">‹</button>
              <span id="monthRangeLabel" style="font-size:11px;font-weight:600;color:var(--text);min-width:120px;text-align:center;"></span>
              <button onclick="shiftMonthRange(1)" style="background:none;border:1px solid var(--border);border-radius:5px;padding:2px 7px;cursor:pointer;font-size:12px;color:var(--muted);">›</button>
            </div>
          </div>
          <div id="monthlyBars" style="display:flex;align-items:flex-end;gap:6px;height:80px;padding:0 2px;">
            <div style="color:var(--muted);font-size:11px;margin:auto;">Loading…</div>
          </div>
          <div id="monthlyBarLabels" style="display:flex;gap:6px;margin-top:4px;"></div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin-bottom:14px;">

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
        </div>
      </div>
      <div class="card-body" style="padding:14px 16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;" id="camDetailTitle">Latest captures</span>
          <div style="display:flex;align-items:center;gap:6px;">
            <span style="font-size:11px;color:var(--muted);" id="imgLastUpdate">Auto-refreshing…</span>
            <button class="detail-clear-btn" id="camClearBtn" style="display:none;">✕ Back to live</button>
          </div>
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
    <div class="legend-row"><span class="leg-dot" style="background:#fb7185"></span><span>Ideal — <?= $thr["temp_min"] ?>–<?= $thr["temp_max"] ?>°C</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#fbbf24"></span><span>Too High — above 28°C</span></div>
    <h4>Humidity</h4>
    <div class="legend-row"><span class="leg-dot" style="background:#60a5fa"></span><span>Too Low — below 85%</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:#34d399"></span><span>Ideal — <?= $thr["hum_min"] ?>–<?= $thr["hum_max"] ?>%</span></div>
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
    <ul><li>Temperature: <?= $thr["temp_min"] ?>–<?= $thr["temp_max"] ?>°C</li><li>Humidity: <?= $thr["hum_min"] ?>–<?= $thr["hum_max"] ?>%</li></ul>
    <h4>Color Codes</h4>
    <div class="legend-row"><span class="leg-dot" style="background:var(--red)"></span><span>Critical — out of range</span></div>
    <div class="legend-row"><span class="leg-dot" style="background:var(--green)"></span><span>All clear — conditions ideal</span></div>
  </div>
</div>

<script>
// Thresholds from DB — never hardcoded
const THRESH = {
  tempMin: <?= $thr['temp_min'] ?>,
  tempMax: <?= $thr['temp_max'] ?>,
  humMin:  <?= $thr['hum_min'] ?>,
  humMax:  <?= $thr['hum_max'] ?>,
  emergTempHigh: <?= $thr['emergency_temp_high'] ?>,
  emergTempLow:  <?= $thr['emergency_temp_low'] ?>,
  emergHumHigh:  <?= $thr['emergency_hum_high'] ?>,
};
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
const tempColor = t => t<THRESH.tempMin?'#60a5fa':t>THRESH.tempMax?'#fbbf24':'#fb7185';
const humColor  = h => h<THRESH.humMin?'#60a5fa':h>THRESH.humMax?'#fb7185':'#34d399';
function gaugeStatusClass(val,low,high){
  if(val<low) return['gs-low','Too Low'];
  if(val>high)return['gs-high','Too High'];
  return['gs-ideal','Ideal'];
}
function setGaugeStatus(el,cls,text){el.className='gauge-status '+cls;el.textContent=text;}

// Live data
function goOffline(){
  ['tempValue','humValue'].forEach(id=>{const e=$$(id);if(e)e.textContent='—';});
  $$('time').textContent='Offline';
  setGaugeStatus($$('tempNote'),'gs-offline','Offline');
  setGaugeStatus($$('humNote'),'gs-offline','Offline');
  [tempGauge,humGauge].forEach(g=>{g.data.datasets[0].data=[0,100];g.data.datasets[0].backgroundColor=['#e5e7eb','#f0f2f5'];g.update();});
  renderAlerts(['Device offline']);
}

async function loadLive(){
  try{
    const r=await fetch('submit_data.php',{cache:'no-store'});
    if(!r.ok)throw 0;
    const d=await r.json();

    // ── Staleness check: if last reading is older than 2 minutes, treat as offline ──
    // Timestamp from DB is Asia/Manila (UTC+8), so add 8hrs offset when parsing
    if(d.timestamp && d.timestamp !== 'No data'){
      const lastTs = new Date(d.timestamp.replace(' ','T') + '+08:00');
      const ageMs = Date.now() - lastTs.getTime();
      if(ageMs > 2 * 60 * 1000){ goOffline(); return; }
    } else { goOffline(); return; }

    const t=Math.max(1,Math.min(50,toNum(d.temperature)));
    const h=Math.max(1,Math.min(100,toNum(d.humidity)));
    $$('tempValue').textContent=t.toFixed(1)+'°';
    $$('humValue').textContent=h.toFixed(1)+'%';
    $$('time').textContent=d.timestamp||'—';
    const[tcls,ttxt]=gaugeStatusClass(t,THRESH.tempMin,THRESH.tempMax);
    const[hcls,htxt]=gaugeStatusClass(h,THRESH.humMin,THRESH.humMax);
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
    if(t<THRESH.tempMin||t>THRESH.tempMax)alerts.push(`Temperature out of range: ${t.toFixed(1)}°C (ideal ${THRESH.tempMin}–${THRESH.tempMax}°C)`);
    if(h<THRESH.humMin||h>THRESH.humMax)alerts.push(`Humidity out of range: ${h.toFixed(1)}% (ideal ${THRESH.humMin}–${THRESH.humMax}%)`);
    renderAlerts(alerts);
    if(alerts.length>0){
      fetch('submit_data.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({alerts:JSON.stringify(alerts)})}).catch(()=>{});
    }
  }catch(_){ goOffline(); }
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
    ['mist','fan','heater','sprayer','exhaust'].forEach(d=>applyPill(d,j[d]));
    if(!modeSwitching){
      $$('modeSwitch').checked=j.manual_mode==1;
      setMode(j.manual_mode==1);
    }
    const list=$$('alertList');
    if(j.buzzer==1){
      if(!list.querySelector('.alert-fault')){
        const el=document.createElement('div');
        el.className='alert-item alert-err alert-fault';
        el.innerHTML='<i class="fas fa-triangle-exclamation"></i> <strong>Device fault detected!</strong> Check the Automation log.';
        list.prepend(el);
      }
    } else {
      list.querySelector('.alert-fault')?.remove();
    }
  }catch(_){}
}
function applyPill(dev,status){
  const el=$$('status_'+dev); if(!el)return;
  const btn=$$('btn_'+dev);
  const on=['ON','1','TRUE'].includes(String(status||'').toUpperCase());
  const off=['OFF','0','FALSE'].includes(String(status||'').toUpperCase());
  if(on){
    el.className='status-pill pill-on';el.innerHTML='<span class="dot"></span> ON';
    if(btn){btn.textContent='Turn OFF';btn.style.background='var(--red)';btn.style.color='#fff';btn.style.borderColor='var(--red)';}
  } else if(off){
    el.className='status-pill pill-off';el.innerHTML='<span class="dot"></span> OFF';
    if(btn){btn.textContent='Turn ON';btn.style.background='var(--green)';btn.style.color='#fff';btn.style.borderColor='var(--green)';}
  } else {
    el.className='status-pill pill-unk';el.innerHTML='<span class="dot"></span> —';
    if(btn){btn.textContent='Toggle';btn.style.background='';btn.style.color='';btn.style.borderColor='';}
  }
}
fetchDeviceStates();
setInterval(fetchDeviceStates,1000);
function setMode(manual){
  $$('modeLabel').textContent=manual?'Manual Mode':'Auto Mode';
  $$('manualControls').style.display=manual?'':'none';
}
let modeSwitching=false;

$$('modeSwitch').addEventListener('change',async function(){
  modeSwitching=true;
  const wantManual=this.checked;
  setMode(wantManual);
  try{
    await fetch('update_device_status.php?mode='+(wantManual?1:0),{cache:'no-store'});
    // Wait for ESP32 to sync relay states to DB (ESP32 polls every 6s, give it 8s)
    await fetchDeviceStates();
  }catch(_){}
  setTimeout(()=>{modeSwitching=false;fetchDeviceStates();},8000);
});
document.querySelectorAll('.toggle-btn[data-device]').forEach(btn=>{
  btn.addEventListener('click',async function(){
    if(this.disabled)return;
    this.disabled=true;
    const dev=this.dataset.device;
    const pill=$$('status_'+dev);
    const currentlyOn=pill&&pill.classList.contains('pill-on');
    const newVal=currentlyOn?0:1;
    applyPill(dev,newVal?'1':'0');
    try{
      // Use plain key=value, no encodeURIComponent on the key
      const r=await fetch('update_device_status.php?'+dev+'='+newVal,{cache:'no-store'});
      const j=await r.json();
      if(j.success&&j.data)applyPill(dev,j.data[dev]);
      $$('last_'+dev).textContent=new Date().toLocaleTimeString('en-PH',{timeZone:'Asia/Manila',hour12:false});
      setTimeout(fetchDeviceStates,500);
    }catch(_){
      applyPill(dev,currentlyOn?'1':'0');
    }
    this.disabled=false;
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
setInterval(loadCameraImages, <?= $camera_interval_ms ?>);

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

// ── Monthly Summary Bar Chart ──
let monthRangeStart = 0; // offset from current month, 0 = show last 6 months ending now

async function loadMonthlySummary() {
  try {
    const now = new Date();
    // Build 6-month range ending at (current month + monthRangeStart)
    const endDate = new Date(now.getFullYear(), now.getMonth() + monthRangeStart, 1);
    const months = [];
    for (let i = 5; i >= 0; i--) {
      const d = new Date(endDate.getFullYear(), endDate.getMonth() - i, 1);
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      months.push({ key: `${y}-${m}`, label: d.toLocaleDateString('en-PH', { month: 'short', year: '2-digit' }) });
    }

    // Fetch totals for each month in parallel
    const results = await Promise.all(months.map(async mo => {
      const res = await fetch(`get_calendar_data.php?type=monthly_total&month=${mo.key}`, { cache: 'no-store' });
      const json = await res.json();
      return { ...mo, total: json.total || 0 };
    }));

    // Update range label
    $$('monthRangeLabel').textContent = `${results[0].label} — ${results[5].label}`;

    // Render bars
    const maxVal = Math.max(...results.map(r => r.total), 1);
    const barsEl = $$('monthlyBars');
    const labelsEl = $$('monthlyBarLabels');
    barsEl.innerHTML = '';
    labelsEl.innerHTML = '';

    results.forEach(mo => {
      const pct = Math.max((mo.total / maxVal) * 100, mo.total > 0 ? 8 : 2);
      const isCurrentMonth = mo.key === `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
      const barColor = isCurrentMonth ? 'var(--green)' : 'var(--green-lt)';
      const textColor = isCurrentMonth ? 'var(--green)' : 'var(--muted)';

      // Bar
      const barWrap = document.createElement('div');
      barWrap.style.cssText = 'flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:3px;height:100%;cursor:pointer;';
      barWrap.title = `${mo.label}: ${mo.total} harvested`;
      barWrap.innerHTML = `
        <span style="font-size:10px;font-weight:700;color:${textColor};">${mo.total > 0 ? mo.total : ''}</span>
        <div style="width:100%;height:${pct}%;background:${barColor};border-radius:4px 4px 0 0;transition:height .3s;min-height:3px;"></div>
      `;
      barWrap.addEventListener('click', () => {
        // Auto-set date picker to first day of that month
        const picker = $$('recDatePicker');
        picker.value = mo.key + '-01';
        picker.dispatchEvent(new Event('change'));
      });
      barsEl.appendChild(barWrap);

      // Label
      const lbl = document.createElement('div');
      lbl.style.cssText = `flex:1;text-align:center;font-size:10px;font-weight:${isCurrentMonth?'700':'500'};color:${textColor};`;
      lbl.textContent = mo.label;
      labelsEl.appendChild(lbl);
    });

  } catch(e) {
    $$('monthlyBars').innerHTML = '<div style="color:var(--muted);font-size:11px;margin:auto;">No data yet</div>';
  }
}

function shiftMonthRange(dir) {
  monthRangeStart += dir;
  // Don't go past current month
  if (monthRangeStart > 0) monthRangeStart = 0;
  loadMonthlySummary();
}

loadMonthlySummary();

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