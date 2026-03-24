<?php
include('includes/auth_check.php');
include('includes/db_connect.php');
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');
$isOwner = isset($_SESSION['role']) && $_SESSION['role'] === 'owner';

// ── Create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS alert_thresholds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric VARCHAR(30) NOT NULL UNIQUE,
    min_value FLOAT NOT NULL,
    max_value FLOAT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(60) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(60) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Seed system_settings defaults
$defaults = [
    'fault_timeout_min'   => '5',
    'stuck_timeout_min'   => '60',
    'camera_interval_sec' => '1800',
    'data_retention_days' => '90',
    'notify_temp'         => '1',
    'notify_hum'          => '1',
    'notify_offline'      => '1',
    'notify_emergency'    => '1',
    'notify_cooldown_min' => '30',
    'cam_resolution'      => 'VGA',
    'cam_quality'         => '12',
    'cam_brightness'      => '1',
    'cam_contrast'        => '1',
    'cam_saturation'      => '0',
    'cam_sharpness'       => '0',
    'cam_wb_mode'         => '0',
    'cam_flash'           => '1',
];
foreach ($defaults as $k => $v) {
    $conn->query("INSERT IGNORE INTO system_settings (setting_key,setting_value) VALUES ('$k','$v')");
}

// ── Seed defaults if empty ──
$r = $conn->query("SELECT COUNT(*) as c FROM alert_thresholds");
if ($r && $r->fetch_assoc()['c'] == 0) {
    $conn->query("INSERT INTO alert_thresholds (metric,min_value,max_value) VALUES ('temperature',22,28),('humidity',85,95)");
}

$errors = []; $success = '';

// ── Save Thresholds ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_thresholds'])) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {
        $metrics = ['temperature', 'humidity'];
        foreach ($metrics as $m) {
            $min     = floatval($_POST[$m . '_min'] ?? 0);
            $max     = floatval($_POST[$m . '_max'] ?? 0);
            $enabled = isset($_POST[$m . '_enabled']) ? 1 : 0;
            if ($min >= $max) { $errors[] = ucfirst($m) . ': min must be less than max.'; }
            else {
                $s = $conn->prepare("INSERT INTO alert_thresholds (metric,min_value,max_value,enabled) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value),max_value=VALUES(max_value),enabled=VALUES(enabled)");
                if ($s) { $s->bind_param("sddi", $m, $min, $max, $enabled); $s->execute(); $s->close(); }
            }
        }
        if (empty($errors)) $success = 'Thresholds saved.';
    }
}

// ── Save SMTP + Notification Settings (combined) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_smtp'])) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {
        // SMTP keys → notification_settings table
        $smtp_keys = ['smtp_host','smtp_port','smtp_user','smtp_from_name'];
        foreach ($smtp_keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $s = $conn->prepare("INSERT INTO notification_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        // Password only if provided
        if (!empty($_POST['smtp_pass'])) {
            $pass = trim($_POST['smtp_pass']);
            $s = $conn->prepare("INSERT INTO notification_settings (setting_key,setting_value) VALUES ('smtp_pass',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("s", $pass); $s->execute(); $s->close(); }
        }
        // Notification trigger checkboxes → notification_settings table
        $notif_ns_keys = ['notify_temp','notify_hum','notify_offline'];
        foreach ($notif_ns_keys as $k) {
            $val = isset($_POST[$k]) ? '1' : '0';
            $s = $conn->prepare("INSERT INTO notification_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        // Notification trigger toggles → system_settings table
        $notif_ss_keys = ['notify_temp','notify_hum','notify_offline','notify_emergency'];
        foreach ($notif_ss_keys as $k) {
            $val = isset($_POST[$k]) ? '1' : '0';
            $s = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        // Cooldown
        $cooldown = (string)intval($_POST['notify_cooldown_min'] ?? 30);
        foreach (['notification_settings','system_settings'] as $tbl) {
            $s = $conn->prepare("INSERT INTO $tbl (setting_key,setting_value) VALUES ('notify_cooldown_min',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("s", $cooldown); $s->execute(); $s->close(); }
        }
        if (empty($errors)) $success = 'Notification settings saved.';
    }
}

// ── Save Auto Engine Settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_auto_engine'])) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {
        $keys = ['fault_timeout_min','stuck_timeout_min'];
        foreach ($keys as $k) {
            $val = (string)intval($_POST[$k] ?? 5);
            $s = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        if (empty($errors)) $success = 'Auto engine settings saved.';
    }
}

// ── Save Camera Settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_camera'])) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {
        $int_keys = ['camera_interval_sec','cam_quality','cam_brightness','cam_contrast','cam_saturation','cam_sharpness','cam_wb_mode','cam_flash'];
        $str_keys = ['cam_resolution'];
        foreach ($int_keys as $k) {
            $val = (string)intval($_POST[$k] ?? 0);
            $s = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        foreach ($str_keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $s = $conn->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($s) { $s->bind_param("ss", $k, $val); $s->execute(); $s->close(); }
        }
        if (empty($errors)) $success = 'Camera settings saved.';
    }
}

// ── Fetch current settings for display ──
$thresholds = [];
$r = $conn->query("SELECT * FROM alert_thresholds");
if ($r) while ($row = $r->fetch_assoc()) $thresholds[$row['metric']] = $row;

$ns = [];
$r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
if ($r) while ($row = $r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];

function ns($ns, $k, $default = '') { return htmlspecialchars($ns[$k] ?? $default); }

$ss = [];
$r = $conn->query("SELECT setting_key,setting_value FROM system_settings");
if ($r) while ($row = $r->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];
function ss($ss, $k, $default = '') { return htmlspecialchars($ss[$k] ?? $default); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="assets/img/jwho-favicon.png">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings</title>
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
.flash-info{background:var(--blue-lt);color:var(--blue);}
.card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;margin-bottom:16px;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 14px;border-bottom:1px solid var(--border);}
.card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.icon-green{background:var(--green-lt);color:var(--green);}
.icon-blue{background:var(--blue-lt);color:var(--blue);}
.icon-amber{background:var(--amber-lt);color:var(--amber);}
.card-body{padding:24px;}
.section-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border);}
.threshold-row{display:grid;grid-template-columns:140px 1fr 1fr 80px;gap:14px;align-items:end;margin-bottom:16px;padding:16px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);}
.threshold-label{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.threshold-label .dot{width:10px;height:10px;border-radius:50%;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:12px;font-weight:600;color:var(--muted);}
.form-group input,.form-group select{width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--border);background:var(--surface);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color .15s;}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--green);background:var(--surface);}
.form-group input:disabled{opacity:.5;cursor:not-allowed;}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.form-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding-top:4px;}
.form-footer-btns{display:flex;gap:10px;flex-wrap:wrap;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;}
.checkbox-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);}
.checkbox-row:last-child{border-bottom:none;}
.checkbox-row label{font-size:13px;font-weight:500;color:var(--text);cursor:pointer;flex:1;}
.checkbox-row .sub{font-size:11px;color:var(--muted);margin-top:1px;}
input[type=checkbox]{width:16px;height:16px;accent-color:var(--green);cursor:pointer;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:'DM Sans',sans-serif;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover{opacity:.88;}
.btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--border);}
.form-footer{display:flex;align-items:center;justify-content:space-between;margin-top:20px;padding-top:16px;border-top:1px solid var(--border);}
.info-box{background:var(--blue-lt);border:1px solid rgba(26,107,186,.15);border-radius:8px;padding:12px 16px;font-size:12.5px;color:var(--blue);margin-bottom:16px;line-height:1.6;}
.info-box i{margin-right:6px;}
.toggle-switch{position:relative;width:38px;height:22px;display:inline-block;}
.toggle-switch input{display:none;}
.toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:999px;transition:.2s;cursor:pointer;}
.toggle-slider::before{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
.toggle-switch input:checked+.toggle-slider{background:var(--green);}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(16px);}
.access-notice{background:var(--amber-lt);border:1px solid rgba(180,83,9,.15);border-radius:8px;padding:14px 16px;font-size:13px;color:var(--amber);display:flex;align-items:center;gap:10px;}
@media(max-width:700px){.threshold-row{grid-template-columns:1fr;}.form-grid-2,.form-grid-3{grid-template-columns:1fr;}}

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
  .page{padding:14px!important;}
  .form-grid-2,.form-grid-3{grid-template-columns:1fr!important;}
  .form-footer{flex-direction:column!important;align-items:flex-start!important;}
  .form-footer-btns{flex-direction:row!important;width:auto;}
  .threshold-row{grid-template-columns:1fr 1fr!important;gap:8px!important;}
  .threshold-row>*:first-child{grid-column:span 2;}
  .card-header{flex-wrap:wrap;gap:8px;padding:12px 16px 10px;}
  .card-body{padding:12px 16px!important;}
}
@media(max-width:480px){
  .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
  .topbar-title{font-size:13px;}
  .topbar-time{display:none;}
  .page{padding:10px!important;padding-top:58px!important;}
  .btn{padding:7px 12px;font-size:12px;}
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
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php" class="active"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<main class="main">
  <header class="topbar">
    <span class="topbar-title">Settings</span>
    <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
  </header>

  <div class="page">
    <?php if ($success): ?><div class="flash flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="flash flash-err"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <?php if (!$isOwner): ?>
      <div class="access-notice"><i class="fas fa-lock"></i> Only the Owner can modify settings. You can view the current configuration below.</div>
    <?php endif; ?>

    <!-- Alert Thresholds -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-amber"><i class="fas fa-sliders"></i></span> Alert Thresholds</div>
        <span style="font-size:11px;color:var(--muted);">Triggers alerts when values go out of range</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="save_thresholds" value="1">
          <?php foreach (['temperature' => ['Temperature','°C','var(--red)'], 'humidity' => ['Humidity','%','var(--blue)']] as $m => [$label, $unit, $col]):
            $t = $thresholds[$m] ?? ['min_value' => 0, 'max_value' => 100, 'enabled' => 1];
          ?>
          <div class="threshold-row">
            <div class="threshold-label">
              <div class="dot" style="background:<?= $col ?>;"></div>
              <?= $label ?>
            </div>
            <div class="form-group">
              <label>Min (<?= $unit ?>)</label>
              <input type="number" name="<?= $m ?>_min" step="0.1" value="<?= $t['min_value'] ?>" <?= !$isOwner ? 'disabled' : '' ?> required>
            </div>
            <div class="form-group">
              <label>Max (<?= $unit ?>)</label>
              <input type="number" name="<?= $m ?>_max" step="0.1" value="<?= $t['max_value'] ?>" <?= !$isOwner ? 'disabled' : '' ?> required>
            </div>
            <div class="form-group">
              <label>Enabled</label>
              <label class="toggle-switch" style="margin-top:6px;">
                <input type="checkbox" name="<?= $m ?>_enabled" value="1" <?= $t['enabled'] ? 'checked' : '' ?> <?= !$isOwner ? 'disabled' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
          <p style="font-size:12px;color:var(--muted);margin-bottom:16px;">Alerts fire when readings stay outside the range. Defaults: Temperature 22–28°C, Humidity 85–95%.</p>
          <?php if ($isOwner): ?>
          <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i><span class="btn-label"> Save Thresholds</span></button>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Email Notifications (merged with Notification Preferences) -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-blue"><i class="fas fa-envelope"></i></span> Email Notifications</div>
        <span style="font-size:11px;color:var(--muted);">SMTP configuration & alert triggers</span>
      </div>
      <div class="card-body">
        <div class="info-box"><i class="fas fa-circle-info"></i>Use Gmail with an App Password (not your real password). Enable 2-Step Verification on your Google account first, then generate an App Password at <b>myaccount.google.com/apppasswords</b>.</div>

        <form method="POST">
          <input type="hidden" name="save_smtp" value="1">

          <p class="section-title">SMTP Server</p>
          <div class="form-grid-3">
            <div class="form-group"><label>SMTP Host</label><input type="text" name="smtp_host" value="<?= ns($ns,'smtp_host','smtp.gmail.com') ?>" placeholder="smtp.gmail.com" <?= !$isOwner ? 'disabled' : '' ?>></div>
            <div class="form-group"><label>Port</label><input type="number" name="smtp_port" value="<?= ns($ns,'smtp_port','587') ?>" placeholder="587" <?= !$isOwner ? 'disabled' : '' ?>></div>
            <div class="form-group"><label>From Name</label><input type="text" name="smtp_from_name" value="<?= ns($ns,'smtp_from_name','MushroomOS') ?>" placeholder="MushroomOS" <?= !$isOwner ? 'disabled' : '' ?>></div>
          </div>
          <div class="form-grid-2">
            <div class="form-group"><label>SMTP Username (Email)</label><input type="email" name="smtp_user" value="<?= ns($ns,'smtp_user') ?>" placeholder="your@gmail.com" <?= !$isOwner ? 'disabled' : '' ?>></div>
            <div class="form-group">
              <label>SMTP Password <?= ($ns['smtp_pass'] ?? false) ? '<span style="color:var(--green);font-size:11px;">● saved</span>' : '' ?></label>
              <input type="password" name="smtp_pass" placeholder="Leave blank to keep current" <?= !$isOwner ? 'disabled' : '' ?>>
            </div>
          </div>

          <p class="section-title" style="margin-top:4px;">Alert Triggers</p>
          <div style="background:var(--surface2);border-radius:10px;padding:14px 16px;border:1px solid var(--border);margin-bottom:14px;">
            <p style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Send email when…</p>
            <?php
            $trigger_items = [
              ['notify_temp',      'Temperature out of range',   'Alert when temp goes above or below thresholds',   $ns],
              ['notify_hum',       'Humidity out of range',      'Alert when humidity goes above or below thresholds',$ns],
              ['notify_offline',   'Sensor offline',             'Alert when no reading received for 5+ minutes',    $ns],
              ['notify_emergency', 'Emergency / Fault Alerts',   'Alert on device faults and emergency events',       $ss],
            ];
            foreach ($trigger_items as [$key, $label, $sub, $src]):
              $checked = ($src[$key] ?? '1') === '1' ? 'checked' : '';
            ?>
            <div class="checkbox-row">
              <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="1" <?= $checked ?> <?= !$isOwner ? 'disabled' : '' ?>>
              <div><label for="<?= $key ?>"><?= $label ?></label><div class="sub"><?= $sub ?></div></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="form-group" style="max-width:200px;margin-bottom:0;">
            <label>Cooldown Between Emails (min)</label>
            <input type="number" name="notify_cooldown_min" min="1" max="1440" value="<?= ns($ns,'notify_cooldown_min', ss($ss,'notify_cooldown_min','30')) ?>" <?= !$isOwner ? 'disabled' : '' ?>>
            <span style="font-size:11px;color:var(--muted);">Minimum gap between repeated alert emails</span>
          </div>

          <?php if ($isOwner): ?>
          <div class="form-footer">
            <div class="form-footer-btns">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-floppy-disk"></i><span class="btn-label"> Save Settings</span>
              </button>
            </div>
            <span style="font-size:12px;color:var(--muted);">Powered by PHPMailer.</span>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Auto Engine Settings -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green"><i class="fas fa-robot"></i></span> Auto Engine Settings</div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="save_auto_engine" value="1">
          <p class="section-title">Configure fault detection and protection timers</p>
          <div class="form-grid-2">
            <div class="form-group">
              <label>Fault Detection Timeout (minutes)</label>
              <input type="number" name="fault_timeout_min" min="1" max="60" value="<?= ss($ss,'fault_timeout_min','5') ?>" <?= !$isOwner?'disabled':'' ?>>
              <span style="font-size:11px;color:var(--muted);">Device forced OFF if sensor stops responding after this many minutes (default: 5)</span>
            </div>
            <div class="form-group">
              <label>Stuck-On Detection Timeout (minutes)</label>
              <input type="number" name="stuck_timeout_min" min="10" max="480" value="<?= ss($ss,'stuck_timeout_min','60') ?>" <?= !$isOwner?'disabled':'' ?>>
              <span style="font-size:11px;color:var(--muted);">Device forced OFF if continuously ON longer than this (default: 60)</span>
            </div>
          </div>
          <?php if ($isOwner): ?>
          <div class="form-footer" style="margin-top:14px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <span class="btn-label">Save Engine Settings</span></button>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Camera Settings -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-blue"><i class="fas fa-camera"></i></span> Camera Settings</div>
        <span style="font-size:11px;color:var(--muted);">Settings are applied to ESP32-CAM automatically on next poll</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="save_camera" value="1">
          <p class="section-title">Capture Behavior</p>
          <div class="form-grid-2">
            <div class="form-group">
              <label>Capture Interval (seconds)</label>
              <input type="number" name="camera_interval_sec" min="10" max="3600" value="<?= ss($ss,'camera_interval_sec','1800') ?>" <?= !$isOwner?'disabled':'' ?>>
              <span style="font-size:11px;color:var(--muted);">How often the camera uploads a photo (default: 1800 = 30 min)</span>
            </div>
          </div>
          <div class="info-box" style="margin-top:8px;">
            <i class="fas fa-circle-info"></i>
            Image quality settings (brightness, contrast, flip, mirror, etc.) can be adjusted from the <strong>Live Camera</strong> button on the Dashboard.
          </div>
          <?php if ($isOwner): ?>
          <div class="form-footer" style="margin-top:14px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <span class="btn-label">Save Camera Settings</span></button>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>

  </div>
</main>

<script>
(function(){
  const el = document.getElementById('phTime'); if (!el) return;
  let t = parseInt(el.dataset.serverTs, 10) || Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH', {
    timeZone:'Asia/Manila', month:'short', day:'numeric', year:'numeric',
    hour:'numeric', minute:'2-digit', second:'2-digit', hour12:true
  }).replace(',', ' —');
  el.textContent = fmt(t);
  setInterval(() => { t += 1000; el.textContent = fmt(t); }, 1000);
})();
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
    <div class="ai-chat-avatar">🍄</div>
    <div class="ai-chat-header-info">
      <div class="ai-chat-header-name">MushroomOS Assistant</div>
      <div class="ai-chat-header-sub">Powered by Groq · llama-3.3-70b</div>
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
  // Replace with your Groq API key (get one free at console.groq.com)
  const GROQ_API_KEY = 'YOUR_GROQ_API_KEY_HERE';
  const GROQ_MODEL   = 'llama-3.3-70b-versatile';

  const SYSTEM_PROMPT = `You are MushroomOS Assistant, an expert AI embedded inside MushroomOS — a smart mushroom cultivation monitoring system for J.WHO Mushroom Farm. 

You help farm operators with:
- Mushroom cultivation advice (oyster, shiitake, etc.)
- Interpreting sensor data (temperature & humidity readings)
- Diagnosing problems (contamination, poor growth, etc.)
- Automation & device control suggestions (mist, fan, heater, sprayer, exhaust)
- Harvest timing and post-harvest tips
- General mushroom farming best practices

Be concise, practical, and friendly. Use short paragraphs. When giving ranges or numbers, be specific. Always relate answers to mushroom farming context.`;

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
      <div class="ai-msg-avatar">${isUser ? '<i class="fas fa-user"></i>' : '🍄'}</div>
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
      <div class="ai-msg-avatar">🍄</div>
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
</body>
</html>