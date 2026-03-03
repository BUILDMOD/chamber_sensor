<?php
// dashboard_cleaned.php
// Cleaned and ready-to-copy single-file dashboard

include('includes/auth_check.php');
include('includes/db_connect.php');

// Create mushroom_records table if not exists
$createMushroomTableSql = "CREATE TABLE IF NOT EXISTS mushroom_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_date DATE NOT NULL,
    mushroom_count INT UNSIGNED NOT NULL DEFAULT 0,
    growth_stage ENUM('Spawn Run','Pinning','Fruiting','Harvest') NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if (!$conn->query($createMushroomTableSql)) {
    // Handle error silently for now
}

// Ensure server uses Philippines timezone and provide server timestamp for client sync
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}
$server_ts_ms = round(microtime(true) * 1000); // milliseconds
$server_time_formatted = date('M j, Y — h:i:s A'); // example: Nov 19, 2025 — 11:52:03 AM



// get display name for topbar menu (fallback to 'Menu')
$displayName = 'Menu';
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (!empty($_SESSION['user_name'])) {
    $displayName = $_SESSION['user_name'];
} elseif (!empty($_SESSION['username'])) {
    $displayName = $_SESSION['username'];
}

// Fetch current month's mushroom records
$currentMonth = date('Y-m');
$mushroomSql = "SELECT record_date, mushroom_count, growth_stage, notes FROM mushroom_records WHERE DATE_FORMAT(record_date, '%Y-%m') = ? ORDER BY record_date DESC";
$mushroomStmt = $conn->prepare($mushroomSql);
$mushroomStmt->bind_param("s", $currentMonth);
$mushroomStmt->execute();
$mushroomResult = $mushroomStmt->get_result();
$mushroomRecords = [];
while ($row = $mushroomResult->fetch_assoc()) {
    $mushroomRecords[] = $row;
}
$mushroomStmt->close();


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard</title>

  <!-- Stylesheet (kept inline for single-file portability) -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


  <style>
    /* ---------- Light / White Theme (kept as in original, lightly cleaned) ---------- */
    :root{ --bg:#f5f7fb; --panel:#ffffff; --muted:#6b7280; --text:#0f172a; --accent:#16a34a; --accent-2:#ef4444; --panel-shadow:0 8px 30px rgba(15,23,42,0.06); --muted-ghost:rgba(15,23,42,0.04); }
    *{box-sizing:border-box}
    body{font-family:"Poppins", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color:var(--text); margin:0; padding:0; -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale}

    /* topbar */
    .topbar{background:white; color:black; display:flex; align-items:center; justify-content:space-between; padding:16px 22px; position:sticky; top:0; z-index:60; border-bottom:1px solid rgba(15,23,42,0.04); backdrop-filter: blur(6px)}
    .topbar .left{display:flex; align-items:center; gap:20px}
    .topbar h1{font-size:17px; margin:0; color:black; font-weight:600; letter-spacing:-0.2px}

    /* layout */
    .container{display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px; padding:26px; max-width:1200px; margin:5px auto}
    .card{background:var(--panel); border-radius:14px; padding:18px; box-shadow:var(--panel-shadow); transition:transform .12s ease, box-shadow .12s ease; border:1px solid rgba(15,23,42,0.03)}
    .card:hover{transform:translateY(-4px); box-shadow:0 16px 48px rgba(15,23,42,0.06)}
    .card h3{margin:0 0 12px 0; color:var(--text); font-weight:700; font-size:16px; display:flex; align-items:center; gap:8px; justify-content:space-between}
    .card p{margin:0; color:var(--muted)}

    /* status & gauges */
    .status-grid{display:flex; gap:18px; align-items:center; justify-content:center; flex-wrap:wrap}
    .gauge-card{width:100%; max-width:260px; text-align:center}
    .gauge-wrap{position:relative; width:240px; height:160px; margin:0 auto}
    .gauge-wrap canvas{display:block; width:100% !important; height:100% !important}
    .gauge-value{position:absolute; left:50%; top:55%; transform:translate(-50%,-50%); font-weight:700; color:var(--text); font-size:20px}
    .gauge-title{margin-top:10px; color:var(--muted); font-weight:600}

    /* controls */
    .mode-toggle{display:flex; align-items:center; justify-content:space-between; background:transparent; padding:8px 10px; border-radius:10px}
    .mode-toggle span{color:var(--muted); font-weight:700}
    .switch{position:relative; width:52px; height:28px; display:inline-block}
    .switch input{display:none}
    .slider{position:absolute; inset:0; background:#e6e9ee; border-radius:999px; transition:.28s}
    .slider:before{content:""; position:absolute; left:4px; top:4px; width:20px; height:20px; background:#fff; border-radius:50%; transition:.28s; box-shadow:0 4px 10px rgba(2,6,23,0.06)}
    .switch input:checked + .slider{background:linear-gradient(90deg, rgba(22,163,74,0.12), rgba(22,163,74,0.18))}
    .switch input:checked + .slider:before{transform:translateX(24px)}
    .manual-controls{display:flex; gap:12px; justify-content:center; margin-top:12px; flex-wrap:wrap; align-items:center}
    .device-row{display:flex; align-items:center; gap:10px; background:transparent; padding:6px 8px; border-radius:8px}
    .manual-controls button{background:transparent; border:1px solid rgba(15,23,42,0.06); padding:8px 12px; border-radius:8px; color:var(--text); cursor:pointer; min-width:120px; text-align:center; font-weight:700}
    .manual-controls button:hover{background:var(--muted-ghost)}
    .manual-controls button.active{background:var(--accent); color:#fff; border-color:transparent; box-shadow:0 8px 22px rgba(16,185,129,0.08)}

    /* status badge */
    .status-badge{display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; min-width:90px; justify-content:center; font-weight:700; font-size:13px; color:var(--text); background:#eef7ef; border:1px solid rgba(15,23,42,0.02)}
    .status-dot{width:10px; height:10px; border-radius:50%; display:inline-block; box-shadow:0 2px 6px rgba(2,6,23,0.06)}
    .status-ON{background:linear-gradient(135deg, #dff7e6, #c4f0d1)}
    .status-OFF{background:linear-gradient(135deg, #ffecec, #ffdada)}
    .status-UNKNOWN{background:#f3f4f6; color:var(--muted)}



    /* alerts */
    .alert-list{list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px}
    .alert-list li{display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; border:1px solid rgba(15,23,42,0.1); box-shadow:0 2px 8px rgba(15,23,42,0.08); background:var(--panel); color:var(--accent-2); font-weight:600; font-size:14px; transition:transform 0.1s ease, box-shadow 0.1s ease}
    .alert-list li:hover{transform:translateY(-2px); box-shadow:0 4px 12px rgba(15,23,42,0.12)}
    .alert-list li.no-alert{background:linear-gradient(135deg, #f0fdf4, #dcfce7); color:#16a34a; border-color:#bbf7d0}
    .alert-list li.alert{background:linear-gradient(135deg, #fef2f2, #fee2e2); color:#dc2626; border-color:#fca5a5}

    /* mushroom records */
    .mushroom-records-table{width:100%; border-collapse:collapse; border:1px solid rgba(15,23,42,0.1); display:table}
    .mushroom-records-table thead{display:table-header-group}
    .mushroom-records-table tbody{display:table-row-group}
    .mushroom-records-table th, .mushroom-records-table td{padding:8px 12px; text-align:left; border:1px solid rgba(15,23,42,0.1)}
    .mushroom-records-table th{background:var(--muted-ghost); font-weight:600}
    .mushroom-records-table td{font-size:14px}
    .record-count{background:var(--accent); color:#fff; padding:2px 8px; border-radius:12px; font-size:12px; font-weight:600}

    /* mushroom image analysis */
    .mushroom-image-grid{display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:16px; margin-top:10px}
    .mushroom-image-card{background:var(--panel); border-radius:10px; overflow:hidden; border:1px solid rgba(15,23,42,0.08); box-shadow:0 2px 8px rgba(15,23,42,0.04); transition:transform .2s ease, box-shadow .2s ease}
    .mushroom-image-card:hover{transform:translateY(-4px); box-shadow:0 8px 24px rgba(15,23,42,0.1)}
    .mushroom-image-card img{width:100%; height:150px; object-fit:cover; display:block}
    .mushroom-image-info{padding:12px}
    .mushroom-image-info .size-info{font-weight:700; color:var(--text); font-size:15px; margin-bottom:6px}
    .mushroom-image-info .status-badge{display:inline-block; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; margin-bottom:6px}
    .mushroom-image-info .status-ready{background:#dcfce7; color:#16a34a}
    .mushroom-image-info .status-almost{background:#fef9c3; color:#a16207}
    .mushroom-image-info .status-not-ready{background:#e0e7ff; color:#4338ca}
    .mushroom-image-info .status-overripe{background:#fee2e2; color:#dc2626}
    .mushroom-image-info .timestamp{color:var(--muted); font-size:11px}
    .mushroom-image-info .confidence{color:var(--muted); font-size:11px; margin-top:4px}
    .no-images{text-align:center; padding:30px; color:var(--muted)}
    .no-images i{font-size:40px; margin-bottom:10px; display:block; opacity:0.5}

    /* sidebar */
    .sidebar{position:fixed; left:0; top:0; width:250px; height:100vh; background:#f8f9fa; border-right:1px solid rgba(15,23,42,0.04); box-shadow:var(--panel-shadow); display:flex; flex-direction:column; z-index:50}
    .sidebar-logo{padding:20px; text-align:center; border-bottom:1px solid rgba(15,23,42,0.1)}
    .sidebar-logo img{width:60px; height:60px; border-radius:8px; }
    .sidebar-nav{flex:1; padding:20px 0}
    .sidebar-nav a{display:block; padding:12px 20px; color:#495057; text-decoration:none; font-weight:600; transition:all .2s ease; border-left:3px solid transparent}
    .sidebar-nav a:hover{background:rgba(15,23,42,0.05); color:#0f172a; border-left-color:#1e40af}
    .sidebar-nav a.active{background:rgba(30,64,175,0.1); color:#1e40af; border-left-color:#1e40af}

    /* main content */
    .main-content{margin-left:250px; min-height:100vh; background:var(--bg)}
    .muted-small{color:var(--muted); font-size:13px}

    /* info icon */
    .info-icon{position:absolute; bottom:18px; right:18px; color:var(--muted); font-size:16px; cursor:pointer; transition:color .2s ease}
    .info-icon:hover{color:var(--text)}

    /* status info icon */
    .status-info-icon{color:var(--muted); font-size:16px; cursor:pointer; transition:color .2s ease}
    .status-info-icon:hover{color:var(--text)}

    /* popup modal */
    .popup-modal{position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1000; opacity:0; visibility:hidden; transition:opacity 0.3s ease, visibility 0.3s ease}
    .popup-modal.show{opacity:1; visibility:visible}
    .popup-content{background:var(--panel); border-radius:14px; padding:20px; max-width:400px; width:90%; box-shadow:0 16px 48px rgba(15,23,42,0.1); position:relative}
    .popup-close{position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; color:var(--muted); cursor:pointer; transition:color .2s ease}
    .popup-close:hover{color:var(--text)}
    .popup-title{margin:0 0 15px 0; color:var(--text); font-weight:700; font-size:18px}
    .popup-section{margin-bottom:15px}
    .popup-section h4{margin:0 0 8px 0; color:var(--text); font-weight:600; font-size:14px}
    .color-legend{display:flex; align-items:center; gap:8px; margin-bottom:5px}
    .color-dot{width:12px; height:12px; border-radius:50%; display:inline-block}
    .color-label{color:var(--muted); font-size:13px}

  </style>
</head>
<body>

  <!-- Sidebar -->
  <div class="sidebar">
    <div class="sidebar-logo"> 
      <img src="assets/img/logo.png" alt="logo">
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
       <a href="profile.php"><i class="fas fa-user"></i> System Profile</a>
      <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
  </div>

  <!-- Main Content -->
  <div class="main-content">

    <div class="topbar">
      <div class="left">
        <h2>System Dashboard</h2>
      </div>

      <div style="display:flex; align-items:center; gap:14px;">
        <!-- server-provided initial formatted PH time and server timestamp (ms) for sync -->
        <span id="phTime" data-server-ts="<?= $server_ts_ms ?>" class="muted-small" style="white-space:nowrap;">
          <?= htmlspecialchars($server_time_formatted) ?>
        </span>
      </div>
    </div>

    <div class="container">

      <!-- Live status -->
      <div class="card status-box">
        <h3>🌿 Live Environment Status <i class="fas fa-info-circle status-info-icon" id="statusInfoIcon" title="Click for info"></i></h3>
        <div class="status-grid">
          <div class="gauge-card">
            <div class="gauge-wrap">
              <canvas id="tempGauge"></canvas>
              <div class="gauge-value" id="tempValue"></div>
            </div>
            <div class="gauge-title">Temperature (°C)</div>
            <div class="gauge-note muted-small" id="tempNote">Offline</div>
          </div>

          <div class="gauge-card">
            <div class="gauge-wrap">
              <canvas id="humGauge"></canvas>
              <div class="gauge-value" id="humValue"></div>
            </div>
            <div class="gauge-title">Humidity (%)</div>
            <div class="gauge-note muted-small" id="humNote">Offline</div>
          </div>
        </div>

        <p style="text-align:center; margin-top:12px;" class="muted-small">🕒 Last update: <span id="time"></span></p>
      </div>

      <!-- Device control -->
      <div class="card control-box" aria-live="polite">
        <h3>⚙️ Device Control Mode <i class="fas fa-info-circle status-info-icon" id="deviceInfoIcon" title="Click for info"></i></h3>

        <div class="mode-toggle">
          <span id="modeLabel">Auto Mode</span>
          <label class="switch" title="Toggle Manual/Auto">
            <input type="checkbox" id="modeSwitch">
            <span class="slider" aria-hidden="true"></span>
          </label>
        </div>

        <div class="manual-controls" id="manualControls" aria-hidden="true" style="display:none;">
          <div class="device-row">
            <button data-device="mist">Mist</button>
            <div id="status_mist" class="status-badge status-UNKNOWN"><span class="status-dot" style="background:#cbd5e1"></span> UNKNOWN</div>
            <span class="muted-small last-time" id="last_mist">Last: --</span>
          </div>

          <div class="device-row">
            <button data-device="fan">Fan</button>
            <div id="status_fan" class="status-badge status-UNKNOWN"><span class="status-dot" style="background:#cbd5e1"></span> UNKNOWN</div>
            <span class="muted-small last-time" id="last_fan">Last: --</span>
          </div>

          <div class="device-row">
            <button data-device="heater">Heater</button>
            <div id="status_heater" class="status-badge status-UNKNOWN"><span class="status-dot" style="background:#cbd5e1"></span> UNKNOWN</div>
            <span class="muted-small last-time" id="last_heater">Last: --</span>
          </div>

          <div class="device-row">
            <button data-device="sprayer">Sprayer</button>
            <div id="status_sprayer" class="status-badge status-UNKNOWN"><span class="status-dot" style="background:#cbd5e1"></span> UNKNOWN</div>
            <span class="muted-small last-time" id="last_sprayer">Last: --</span>
          </div>
        </div>

        <p style="margin-top:135px; color:var(--muted); font-size:12px">Note: System runs automatically based on sensor readings. Manual toggles are for emergency/override only.</p>
      </div>

      <!-- Alerts -->
      <div class="card alert-box">
        <h3>🚨 Alerts <i class="fas fa-info-circle status-info-icon" id="alertInfoIcon" title="Click for info"></i></h3>
        <ul class="alert-list" id="alertList"><li class="no-alert">No alerts</li></ul>
      </div>

      <!-- Mushroom Records -->
      <div class="card mushroom-box">
        <h3>🍄 Monthly Mushroom Records</h3>
        <?php if (empty($mushroomRecords)): ?>
          <p class="muted-small">No records for this month yet.</p>
        <?php else: ?>
          <table class="mushroom-records-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Count</th>
                <th>Stage</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($mushroomRecords as $record): ?>
                <tr>
                  <td><?php echo htmlspecialchars(date('M j, Y', strtotime($record['record_date']))); ?></td>
                  <td><span class="record-count"><?php echo htmlspecialchars($record['mushroom_count']); ?></span></td>
                  <td><?php echo htmlspecialchars($record['growth_stage']); ?></td>
                  <td><?php echo htmlspecialchars($record['notes'] ?: 'No notes'); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p style="margin-top:12px; color:var(--muted); font-size:12px">Records are tracked manually for monthly progress monitoring.</p>
      </div>

      <!-- Mushroom Image Analysis -->
      <div class="card mushroom-image-box" style="grid-column: 1 / -1;">
        <h3>📷 Mushroom Image Analysis <i class="fas fa-info-circle status-info-icon" id="imageInfoIcon" title="Click for info"></i></h3>
        
        <!-- Upload Section -->
        <div style="margin-bottom: 20px; padding: 15px; background: var(--muted-ghost); border-radius: 10px;">
          <form id="imageUploadForm" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
            <label style="font-weight: 600; color: var(--text);">Upload Mushroom Image:</label>
            <input type="file" id="imageInput" name="image" accept="image/*" style="flex: 1; min-width: 200px; padding: 8px; border: 1px solid rgba(15,23,42,0.1); border-radius: 8px;">
            <button type="submit" style="background: var(--accent); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.2s ease;">Analyze & Save</button>
          </form>
          <div id="uploadStatus" style="margin-top: 10px; font-size: 13px;"></div>
        </div>

        <!-- Image Grid Display -->
        <div class="mushroom-image-grid" id="mushroomImageGrid">
          <div class="no-images" id="noImages">
            <i class="fas fa-camera"></i>
            <p>No images analyzed yet. Upload an image to start.</p>
          </div>
        </div>
      </div>

    </div> <!-- end container -->

    <!-- Popup Modal for Status Info -->
    <div class="popup-modal" id="statusInfoModal">
      <div class="popup-content">
        <button class="popup-close" id="closeStatusInfoModal">&times;</button>
        <h3 class="popup-title">Live Environment Status Info</h3>
        <div class="popup-section">
          <h4>Temperature Color Legend</h4>
          <div class="color-legend">
            <span class="color-dot" style="background:#60a5fa;"></span>
            <span class="color-label">Sky Blue: Too Low (< 22°C)</span>
          </div>
          <div class="color-legend">
            <span class="color-dot" style="background:#fb7185;"></span>
            <span class="color-label">Red: Ideal (22-28°C)</span>
          </div>
          <div class="color-legend">
            <span class="color-dot" style="background:#fbbf24;"></span>
            <span class="color-label">Orange: Too High (> 28°C)</span>
          </div>
        </div>
        <div class="popup-section">
          <h4>Humidity Color Legend</h4>
          <div class="color-legend">
            <span class="color-dot" style="background:#60a5fa;"></span>
            <span class="color-label">Sky Blue: Too Low (< 85%)</span>
          </div>
          <div class="color-legend">
            <span class="color-dot" style="background:#34d399;"></span>
            <span class="color-label">Green: Ideal (85-95%)</span>
          </div>
          <div class="color-legend">
            <span class="color-dot" style="background:#fb7185;"></span>
            <span class="color-label">Red: Too High (> 95%)</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Popup Modal for Device Control Info -->
    <div class="popup-modal" id="deviceInfoModal">
      <div class="popup-content">
        <button class="popup-close" id="closeDeviceInfoModal">&times;</button>
        <h3 class="popup-title">Device Control Mode Info</h3>
        <div class="popup-section">
          <h4>Auto Mode</h4>
          <p>The system automatically controls devices based on sensor readings to maintain optimal mushroom growing conditions.</p>
        </div>
        <div class="popup-section">
          <h4>Manual Mode</h4>
          <p>Allows manual override of device states. Use for emergency situations or specific adjustments.</p>
        </div>
        <div class="popup-section">
          <h4>Devices</h4>
          <ul>
            <li><strong>Mist:</strong> Maintains ideal humidity inside the chamber.</li>
            <li><strong>Fan:</strong> Regulates temperature and circulates air inside the chamber.</li>
            <li><strong>Heater:</strong> Provides heat when temperature is too low.</li>
            <li><strong>Sprayer:</strong> Moistens mushrooms or applies water/nutrients (not for humidity control).</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Popup Modal for Alert Info -->
    <div class="popup-modal" id="alertInfoModal">
      <div class="popup-content">
        <button class="popup-close" id="closeAlertInfoModal">&times;</button>
        <h3 class="popup-title">Alerts Info</h3>
        <div class="popup-section">
          <h4>What are Alerts?</h4>
          <p>Alerts are notifications that appear when the environmental conditions (temperature or humidity) deviate from the ideal ranges for mushroom cultivation.</p>
        </div>
        <div class="popup-section">
          <h4>Ideal Ranges</h4>
          <ul>
            <li><strong>Temperature:</strong> 22-28°C</li>
            <li><strong>Humidity:</strong> 85-95%</li>
          </ul>
        </div>
        <div class="popup-section">
          <h4>Alert Types</h4>
          <p>Alerts are color-coded:</p>
          <div class="color-legend">
            <span class="color-dot" style="background:#dc2626;"></span>
            <span class="color-label">Red: Critical alert (out of range)</span>
          </div>
          <div class="color-legend">
            <span class="color-dot" style="background:#16a34a;"></span>
            <span class="color-label">Green: No alerts (conditions are ideal)</span>
          </div>
        </div>
      </div>
    </div>

  </div> <!-- end main-content -->

  <script>
    // ---------- Helper: safe parseFloat fallback ----------
    function toNumber(v){ const n = parseFloat(v); return Number.isFinite(n) ? n : 0; }

    // ---------------- PH SERVER-SYNCED TIME ----------------
    (function(){
      const el = document.getElementById('phTime');
      if(!el) return;
      // `data-server-ts` -> dataset.serverTs
      let current = parseInt(el.dataset.serverTs, 10) || Date.now();
      function formatPH(ms){
        const d = new Date(ms);
        return d.toLocaleString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }).replace(',', ' —');
      }
      el.textContent = formatPH(current);
      setInterval(function(){ current += 1000; el.textContent = formatPH(current); }, 1000);
    })();

    // ---------- Device state polling ----------
    const DEVICE_STATES_ENDPOINT = 'get_device_status.php';
    const deviceIds = ['mist','fan','heater','sprayer'];

    function applyStatusBadge(device, status){
      const el = document.getElementById('status_' + device);
      if(!el) return;
      const s = String(status || '').toUpperCase();
      el.classList.remove('status-ON','status-OFF','status-UNKNOWN');
      if(s === 'ON' || s === '1' || s === 'TRUE'){
        el.classList.add('status-ON');
        el.innerHTML = '<span class="status-dot" style="background:#16a34a"></span> ON';
      } else if(s === 'OFF' || s === '0' || s === 'FALSE'){
        el.classList.add('status-OFF');
        el.innerHTML = '<span class="status-dot" style="background:#ef4444"></span> OFF';
      } else {
        el.classList.add('status-UNKNOWN');
        el.innerHTML = '<span class="status-dot" style="background:#cbd5e1"></span> UNKNOWN';
      }
    }

    async function fetchDeviceStates(){
      try{
        const res = await fetch(DEVICE_STATES_ENDPOINT, { cache: 'no-store' });
        if(!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        deviceIds.forEach(d => applyStatusBadge(d, json[d]));
        // Set mode switch
        const sw = document.getElementById('modeSwitch');
        const manualMode = json.manual_mode == 1;
        sw.checked = manualMode;
        setMode(manualMode);
      } catch (err){
        console.warn('Device state fetch failed:', err);
      }
    }
    fetchDeviceStates();
    setInterval(fetchDeviceStates, 1000);









    // ---------- Gauges (Chart.js doughnut) ----------
    const makeGauge = (canvasId, options) => {
      const ctx = document.getElementById(canvasId).getContext('2d');
      return new Chart(ctx, {
        type: 'doughnut',
        data: { datasets: [{ data: [0, 100], backgroundColor: [options.color, '#f3f4f6'], borderWidth: 0 }] },
        options: { cutout: '72%', rotation: -90, circumference: 180, responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false }, tooltip:{ enabled:false } } }
      });
    };

    const tempGauge = makeGauge('tempGauge', { color: 'rgba(239,123,89,1)' });
    const humGauge = makeGauge('humGauge', { color: 'rgba(52,211,153,1)' });

    function tempColorFor(t){ if(t < 22) return '#60a5fa'; if(t > 28) return '#fbbf24'; return '#fb7185'; }
    function humColorFor(h){ if(h < 85) return '#60a5fa'; if(h > 95) return '#fb7185'; return '#34d399'; }

    function tempColorName(t){ if(t < 22) return 'Sky Blue'; if(t > 28) return 'Orange'; return 'Red'; }
    function humColorName(h){ if(h < 85) return 'Sky Blue'; if(h > 95) return 'Red'; return 'Green'; }

    // ---------- Live data loader ----------
    async function loadLiveData(){
      try{
        const res = await fetch('submit_data.php', { cache: 'no-store' });
        if(!res.ok) throw new Error('HTTP ' + res.status);
        const d = await res.json();
        const temp = toNumber(d.temperature);
        const hum = toNumber(d.humidity);
        const ts = d.timestamp || new Date().toLocaleString();

        const tClamped = Math.max(1, Math.min(50, temp));
        const hClamped = Math.max(1, Math.min(100, hum)); 

        document.getElementById('tempValue').textContent = `${tClamped.toFixed(1)} °C`;
        document.getElementById('humValue').textContent = `${hClamped.toFixed(1)} %`;
        document.getElementById('time').textContent = ts;

        // Update status info display
        const statusInfo = document.getElementById('statusInfo');
        const tempStatus = tClamped < 22 ? 'Too Low' : tClamped > 28 ? 'Too High' : 'Ideal';
        const humStatus = hClamped < 85 ? 'Too Low' : hClamped > 95 ? 'Too High' : 'Ideal';
        statusInfo.textContent = `Temperature: ${tempStatus} (${tempColorName(tClamped)}), Humidity: ${humStatus} (${humColorName(hClamped)})`;

        const tPercent = Math.round((tClamped - 1) / (50 - 1) * 100);
        const hPercent = Math.round((hClamped - 1) / (100 - 1) * 100);

        // Update Gauges
        tempGauge.data.datasets[0].data = [tPercent, 100 - tPercent];
        tempGauge.data.datasets[0].backgroundColor = [tempColorFor(tClamped), '#f3f4f6'];
        tempGauge.update();

        humGauge.data.datasets[0].data = [hPercent, 100 - hPercent];
        humGauge.data.datasets[0].backgroundColor = [humColorFor(hClamped), '#f3f4f6'];
        humGauge.update();

        // Notes
        let tempNote = 'Ideal';
        if (tClamped < 22) tempNote = 'Too Low'; else if (tClamped > 28) tempNote = 'Too High';
        document.getElementById('tempNote').textContent = tempNote;

        let humNote = 'Ideal';
        if (hClamped < 85) humNote = 'Too Low'; else if (hClamped > 95) humNote = 'Too High';
        document.getElementById('humNote').textContent = humNote;

        // Alerts
        const alerts = [];
        if (tClamped < 22 || tClamped > 28) alerts.push({ type: 'Temperature', message: `Temperature out of ideal range (22-28°C): ${tClamped.toFixed(1)}°C` });
        if (hClamped < 85 || hClamped > 95) alerts.push({ type: 'Humidity', message: `Humidity out of ideal range (85-95%): ${hClamped.toFixed(1)}%` });

        const alertList = document.getElementById('alertList');
        alertList.innerHTML = '';
        if(alerts.length === 0){
          const li = document.createElement('li'); li.className = 'no-alert'; li.innerHTML = '<i class="fas fa-check-circle"></i> No alerts'; alertList.appendChild(li);
        } else {
          alerts.forEach(alert => { const li = document.createElement('li'); li.className = 'alert'; li.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + alert.message; alertList.appendChild(li); });
        }

        // Send alerts to server if any
        if (alerts.length > 0) {
          fetch('submit_data.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ alerts: JSON.stringify(alerts) })
          }).catch(err => console.warn('Failed to save alerts:', err));
        }

      } catch (err){
        console.error('Live load error', err);
        // Show no readings when offline
        document.getElementById('tempValue').textContent = '';
        document.getElementById('humValue').textContent = '';
        document.getElementById('time').textContent = 'Offline';

        // Update Gauges to empty
        tempGauge.data.datasets[0].data = [0, 100];
        tempGauge.data.datasets[0].backgroundColor = ['#f3f4f6', '#f3f4f6'];
        tempGauge.update();

        humGauge.data.datasets[0].data = [0, 100];
        humGauge.data.datasets[0].backgroundColor = ['#f3f4f6', '#f3f4f6'];
        humGauge.update();

        // Notes
        document.getElementById('tempNote').textContent = 'Offline';
        document.getElementById('humNote').textContent = 'Offline';

        // Alerts
        const alertList = document.getElementById('alertList');
        alertList.innerHTML = '';
        const li = document.createElement('li');
        li.textContent = 'Device offline';
        alertList.appendChild(li);
      }
    }

    // Initial load & refresh
    loadLiveData();
    setInterval(loadLiveData, 1000);

    // ---------- Mode switch & manual toggles ----------
    (function(){
      const sw = document.getElementById('modeSwitch');
      const label = document.getElementById('modeLabel');
      const manual = document.getElementById('manualControls');

      function setMode(manualMode){
        if(manualMode){
          label.textContent = 'Manual Mode';
          manual.style.display = '';
          manual.setAttribute('aria-hidden','false');
        } else {
          label.textContent = 'Auto Mode';
          manual.style.display = 'none';
          manual.setAttribute('aria-hidden','true');
        }
      }

      sw.addEventListener('change', async function(){
        const manualMode = this.checked;
        setMode(manualMode);
        try{
          const url = `update_device_status.php?mode=${manualMode ? 1 : 0}`;
          await fetch(url, { cache: 'no-store' });
        } catch (err){ console.error('Mode update error', err); }
      });

      // Manual control buttons
      document.querySelectorAll('.manual-controls button').forEach(btn => {
        btn.addEventListener('click', function(){
          const device = btn.dataset.device;
          if(!device) return;
          toggleDevice(device, btn);
        });
      });

    })();

    async function toggleDevice(device, button){
      try{
        const url = `update_device_status.php?device=${encodeURIComponent(device)}`;
        const res = await fetch(url, { cache: 'no-store' });
        const txt = await res.text();
        if(button){ button.classList.add('active'); setTimeout(()=>button.classList.remove('active'), 800); }
        // Update last toggle time
        const now = new Date().toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour12: false });
        document.getElementById('last_' + device).textContent = 'Last: ' + now;
        // refresh device states shortly after
        setTimeout(fetchDeviceStates, 800);
        console.log('Control response:', txt);
      } catch (err){ console.error('Control error', err); }
    }

    // ---------- Popup Modal for Status Info ----------
    (function(){
      const modal = document.getElementById('statusInfoModal');
      const icon = document.getElementById('statusInfoIcon');
      const closeBtn = document.getElementById('closeStatusInfoModal');

      function showModal(){
        modal.classList.add('show');
      }

      function hideModal(){
        modal.classList.remove('show');
      }

      icon.addEventListener('click', showModal);
      closeBtn.addEventListener('click', hideModal);

      // Close modal when clicking outside the content
      modal.addEventListener('click', function(e){
        if(e.target === modal){
          hideModal();
        }
      });
    })();

    // ---------- Popup Modal for Device Control Info ----------
    (function(){
      const modal = document.getElementById('deviceInfoModal');
      const icon = document.getElementById('deviceInfoIcon');
      const closeBtn = document.getElementById('closeDeviceInfoModal');

      function showModal(){
        modal.classList.add('show');
      }

      function hideModal(){
        modal.classList.remove('show');
      }

      icon.addEventListener('click', showModal);
      closeBtn.addEventListener('click', hideModal);

      // Close modal when clicking outside the content
      modal.addEventListener('click', function(e){
        if(e.target === modal){
          hideModal();
        }
      });
    })();

    // ---------- Popup Modal for Alert Info ----------
    (function(){
      const modal = document.getElementById('alertInfoModal');
      const icon = document.getElementById('alertInfoIcon');
      const closeBtn = document.getElementById('closeAlertInfoModal');

      function showModal(){
        modal.classList.add('show');
      }

      function hideModal(){
        modal.classList.remove('show');
      }

      icon.addEventListener('click', showModal);
      closeBtn.addEventListener('click', hideModal);

      // Close modal when clicking outside the content
      modal.addEventListener('click', function(e){
        if(e.target === modal){
          hideModal();
        }
      });
    })();
  </script>
</body>
</html>
  