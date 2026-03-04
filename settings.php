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

// ── Save SMTP Settings ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_smtp']) || ($_POST['form_action'] ?? '') === 'save')) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {
        $keys = ['smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_to_email','notify_temp','notify_hum','notify_offline','notify_cooldown_min'];
        foreach ($keys as $k) {
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
        if (empty($errors)) $success = 'Notification settings saved.';
    }
}

// ── Send Test Email ──
$test_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['send_test']) || ($_POST['form_action'] ?? '') === 'test')) {
    if (!$isOwner) { $errors[] = 'Access denied.'; } else {

        // Re-fetch latest saved settings from DB
        $ns_test = [];
        $r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
        if ($r) while ($row = $r->fetch_assoc()) $ns_test[$row['setting_key']] = $row['setting_value'];

        $host      = $ns_test['smtp_host']      ?? '';
        $port      = intval($ns_test['smtp_port'] ?? 587);
        $user      = $ns_test['smtp_user']      ?? '';
        $pass      = $ns_test['smtp_pass']      ?? '';
        $to        = $ns_test['smtp_to_email']  ?? '';
        $from_name = $ns_test['smtp_from_name'] ?? 'MushroomOS';

        if (!$host || !$user || !$pass || !$to) {
            $test_result = 'error:SMTP settings incomplete. Please save your configuration first.';
        } else {
            require_once 'PHPMailer-master/src/PHPMailer.php';
            require_once 'PHPMailer-master/src/SMTP.php';
            require_once 'PHPMailer-master/src/Exception.php';

            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $host;
                $mail->SMTPAuth   = true;
                $mail->Username   = $user;
                $mail->Password   = $pass;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; // ✅ fixed constant
                $mail->Port       = $port;
                $mail->setFrom($user, $from_name);
                $mail->addAddress($to);
                $mail->isHTML(true); // ✅ enable HTML
                $mail->Subject = 'MushroomOS — Test Notification';
                $mail->Body    = "
                    <b>MushroomOS Test Email</b><br><br>
                    If you received this, your email notification settings are working correctly.<br><br>
                    <b>Sent:</b> " . date('M j, Y h:i:s A T') . "
                ";
                $mail->AltBody = "MushroomOS Test Email\n\nIf you received this, your email notification settings are working correctly.\n\nSent: " . date('M j, Y h:i:s A T'); // ✅ plain text fallback
                $mail->send();
                $test_result = 'ok:Test email sent to ' . htmlspecialchars($to) . '. Check your inbox.';
            } catch (Exception $e) {
                error_log("Test email error: " . $mail->ErrorInfo);
                $test_result = 'error:' . $mail->ErrorInfo;
            }
        }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
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
.main{margin-left:220px;min-height:100vh;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:40;}
.topbar-title{font-size:15px;font-weight:700;color:var(--text);}
.topbar-time{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--surface2);padding:5px 12px;border-radius:20px;border:1px solid var(--border);}
.page{padding:24px 28px;max-width:900px;}
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
.btn-blue{background:var(--blue);color:#fff;}
.btn-blue:hover{opacity:.88;}
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
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="logo">
    <div><div class="sidebar-logo-text">MushroomOS</div><div class="sidebar-logo-sub">Cultivation System</div></div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="harvest.php"><i class="fas fa-seedling"></i> Harvest & Batches</a>
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
    <?php if (!empty($test_result)):
      $tr = explode(':', $test_result, 2);
      $trclass = $tr[0] === 'ok' ? 'flash-ok' : 'flash-err';
      $tricon  = $tr[0] === 'ok' ? 'fa-envelope-circle-check' : 'fa-triangle-exclamation';
    ?><div class="flash <?= $trclass ?>"><i class="fas <?= $tricon ?>"></i> <?= htmlspecialchars($tr[1] ?? $test_result) ?></div><?php endif; ?>

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
          <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save Thresholds</button>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- Email Notifications -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-blue"><i class="fas fa-envelope"></i></span> Email Notifications</div>
        <span style="font-size:11px;color:var(--muted);">SMTP configuration</span>
      </div>
      <div class="card-body">
        <div class="info-box"><i class="fas fa-circle-info"></i>Use Gmail with an App Password (not your real password). Enable 2-Step Verification on your Google account first, then generate an App Password at <b>myaccount.google.com/apppasswords</b>.</div>

        <!-- ✅ Single unified form for Save + Test -->
        <form method="POST" id="smtpForm">

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

          <p class="section-title" style="margin-top:4px;">Recipients & Triggers</p>
          <div class="form-group" style="margin-bottom:14px;">
            <label>Send Alerts To (email)</label>
            <input type="email" name="smtp_to_email" value="<?= ns($ns,'smtp_to_email') ?>" placeholder="admin@example.com" <?= !$isOwner ? 'disabled' : '' ?>>
          </div>

          <div style="background:var(--surface2);border-radius:10px;padding:14px 16px;border:1px solid var(--border);margin-bottom:14px;">
            <p style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Send email when…</p>
            <div class="checkbox-row">
              <input type="checkbox" id="nt" name="notify_temp" value="1" <?= ($ns['notify_temp'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$isOwner ? 'disabled' : '' ?>>
              <div><label for="nt">Temperature out of range</label><div class="sub">Alert when temp goes above or below thresholds</div></div>
            </div>
            <div class="checkbox-row">
              <input type="checkbox" id="nh" name="notify_hum" value="1" <?= ($ns['notify_hum'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$isOwner ? 'disabled' : '' ?>>
              <div><label for="nh">Humidity out of range</label><div class="sub">Alert when humidity goes above or below thresholds</div></div>
            </div>
            <div class="checkbox-row">
              <input type="checkbox" id="no" name="notify_offline" value="1" <?= ($ns['notify_offline'] ?? '1') === '1' ? 'checked' : '' ?> <?= !$isOwner ? 'disabled' : '' ?>>
              <div><label for="no">Sensor offline</label><div class="sub">Alert when no reading received for 5+ minutes</div></div>
            </div>
          </div>

          <div class="form-group" style="max-width:200px;margin-bottom:0;">
            <label>Cooldown Between Emails (min)</label>
            <input type="number" name="notify_cooldown_min" min="1" max="1440" value="<?= ns($ns,'notify_cooldown_min','30') ?>" <?= !$isOwner ? 'disabled' : '' ?>>
          </div>

          <!-- ✅ Hidden field — set by JS to distinguish Save vs Test -->
          <input type="hidden" name="form_action" id="formAction" value="">

          <?php if ($isOwner): ?>
          <div class="form-footer">
            <div style="display:flex;gap:10px;">
              <button type="button" class="btn btn-primary" onclick="submitSmtp('save')">
                <i class="fas fa-floppy-disk"></i> Save Settings
              </button>
              <button type="button" class="btn btn-blue" onclick="submitSmtp('test')">
                <i class="fas fa-paper-plane"></i> Send Test Email
              </button>
            </div>
            <span style="font-size:12px;color:var(--muted);">Powered by PHPMailer.</span>
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- PHPMailer Integration Guide -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-green"><i class="fas fa-code"></i></span> Integration Guide</div>
      </div>
      <div class="card-body">
        <p class="section-title">send_email.php template used by this system</p>
        <div style="background:#0d1117;border-radius:8px;padding:14px 18px;font-family:'DM Mono',monospace;font-size:12px;color:#e6edf3;line-height:1.8;overflow-x:auto;">
<pre style="margin:0;white-space:pre-wrap;"><span style="color:#ff7b72;">use</span> <span style="color:#79c0ff;">PHPMailer\PHPMailer\PHPMailer</span>;
<span style="color:#ff7b72;">require</span> <span style="color:#a5d6ff;">'PHPMailer-master/src/PHPMailer.php'</span>;
<span style="color:#ff7b72;">require</span> <span style="color:#a5d6ff;">'PHPMailer-master/src/SMTP.php'</span>;
<span style="color:#ff7b72;">require</span> <span style="color:#a5d6ff;">'PHPMailer-master/src/Exception.php'</span>;

<span style="color:#ff7b72;">function</span> <span style="color:#d2a8ff;">sendEmail</span>($to, $subject, $body) {
  $mail = <span style="color:#ff7b72;">new</span> PHPMailer(<span style="color:#79c0ff;">true</span>);
  $mail->isSMTP();
  $mail->Host       = <span style="color:#a5d6ff;">'smtp.gmail.com'</span>;
  $mail->SMTPAuth   = <span style="color:#79c0ff;">true</span>;
  $mail->Username   = <span style="color:#a5d6ff;">'your@gmail.com'</span>;
  $mail->Password   = <span style="color:#a5d6ff;">'your-app-password'</span>;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = <span style="color:#79c0ff;">587</span>;
  $mail->setFrom(<span style="color:#a5d6ff;">'your@gmail.com'</span>, <span style="color:#a5d6ff;">'MushroomOS'</span>);
  $mail->addAddress($to);
  $mail->isHTML(<span style="color:#79c0ff;">true</span>);
  $mail->Subject = $subject;
  $mail->Body    = $body;
  $mail->AltBody = strip_tags($body);
  <span style="color:#ff7b72;">return</span> $mail->send() ? <span style="color:#a5d6ff;">"SUCCESS"</span> : <span style="color:#a5d6ff;">"FAILED"</span>;
}</pre>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
// Clock
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

// ✅ Fixed: single form, hidden field tells PHP what action to run
function submitSmtp(action) {
  if (action === 'test') {
    if (!confirm('Send a test email to the configured address?')) return;
    document.getElementById('formAction').value = 'test';
  } else {
    document.getElementById('formAction').value = 'save';
  }
  document.getElementById('smtpForm').submit();
}
</script>


</body>
</html>