<?php
include('includes/auth_check.php');
include('includes/db_connect.php');

// Create sensor_summary table if it doesn't exist
$createTableSql = "CREATE TABLE IF NOT EXISTS sensor_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    summary_date DATE NOT NULL,

    avg_temp FLOAT,
    min_temp FLOAT,
    max_temp FLOAT,

    avg_hum FLOAT,
    min_hum FLOAT,
    max_hum FLOAT,

    readings INT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if (!$conn->query($createTableSql)) {
    die("Error creating table: " . $conn->error);
}

// ensure server uses Philippines timezone and provide server timestamp for client sync
date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000); // milliseconds
$server_time_formatted = date('M j, Y — h:i:s A'); // example: Nov 19, 2025 — 11:52:03 AM

// get display name for topbar menu (fallback to 'Menu')
$displayName = 'Menu';
if (isset($_SESSION) && !empty($_SESSION['user_name'])) {
    $displayName = $_SESSION['user_name'];
} elseif (isset($_SESSION) && !empty($_SESSION['username'])) {
    $displayName = $_SESSION['username'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reports</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    /* ---------- Light / White Theme ---------- */
    :root{
      --bg: #f5f7fb;
      --panel: #ffffff;
      --muted: #6b7280;
      --text: #0f172a;
      --accent: #16a34a; /* green */
      --accent-2: #ef4444; /* red */
      --panel-shadow: 0 8px 30px rgba(15,23,42,0.06);
      --muted-ghost: rgba(15,23,42,0.04);
    }

    *{box-sizing:border-box}
    body {
      font-family: "Poppins", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      color: var(--text);
      margin: 0;
      padding: 0;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    /* topbar */
    .topbar {
      background: white;
      color: black;
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:16px 22px;
      position: sticky;
      top: 0;
      z-index: 60;
      border-bottom: 1px solid rgba(15,23,42,0.04);
      backdrop-filter: blur(6px);
    }
    .topbar .left {
      display:flex;
      align-items:center;
      gap:14px;
    }
    .topbar img { width: 44px; height: 40px; border-radius: 1px; background:transparent; box-shadow: 0 6px 18px rgba(2,6,23,0.04);}
    .topbar h1 { font-size:17px; margin:0; color:black; font-weight:600; letter-spacing: -0.2px; }

    /* layout */
    .container {
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap:20px;
      padding:26px;
      max-width:1200px;
      margin: 5px auto;
    }

    .card {
      background: var(--panel);
      border-radius:14px;
      padding:18px;
      box-shadow: var(--panel-shadow);
      transition: transform .12s ease, box-shadow .12s ease;
      border: 1px solid rgba(15,23,42,0.03);
      overflow-x: auto;
    }
    .card:hover { transform: translateY(-4px); box-shadow: 0 16px 48px rgba(15,23,42,0.06); }
    .card h3 { margin:0 0 12px 0; color:var(--text); font-weight:700; font-size:16px; display:flex; align-items:center; gap:8px; }
    .card p { margin:0; color:var(--muted); }

    /* report table */
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
    }
    .report-table th, .report-table td {
      padding: 8px 12px;
      text-align: left;
      border-bottom: 1px solid rgba(15,23,42,0.04);
    }
    .report-table th {
      background: var(--muted-ghost);
      font-weight: 700;
      color: var(--text);
    }
    .report-table td {
      color: var(--muted);
    }

    /* sidebar */
    .sidebar {
      position: fixed;
      left: 0;
      top: 0;
      width: 250px;
      height: 100vh;
      background: #f8f9fa;
      border-right: 1px solid rgba(15,23,42,0.04);
      box-shadow: var(--panel-shadow);
      display: flex;
      flex-direction: column;
      z-index: 50;
    }
    .sidebar-logo {
      padding: 20px;
      text-align: center;
      border-bottom: 1px solid rgba(15,23,42,0.1);
    }
    .sidebar-logo img {
      width: 60px;
      height: 60px;
      border-radius: 8px;
    }
    .sidebar-nav {
      flex: 1;
      padding: 20px 0;
    }
    .sidebar-nav a {
      display: block;
      padding: 12px 20px;
      color: #495057;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.2s ease;
      border-left: 3px solid transparent;
    }
    .sidebar-nav a:hover {
      background: rgba(15,23,42,0.05);
      color: #0f172a;
      border-left-color: #1e40af;
    }
    .sidebar-nav a.active {
      background: rgba(30,64,175,0.1);
      color: #1e40af;
      border-left-color: #1e40af;
    }

    /* main content */
    .main-content {
      margin-left: 250px;
      min-height: 100vh;
      background: var(--bg);
    }

    /* small helper */
    .muted-small { color:var(--muted); font-size:13px; }

    /* change indicators */
    .change-positive { color: var(--accent); font-weight: 600; }
    .change-negative { color: var(--accent-2); font-weight: 600; }
    .change-neutral { color: var(--muted); font-weight: 600; }

  </style>
</head>
<body>
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="sidebar-logo">
      <img src="assets/img/logo.png" alt="logo">
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
      <a href="reports.php"  class="active"><i class="fas fa-chart-bar"></i> Reports</a>
      <a href="profile.php"><i class="fas fa-user"></i> System Profile</a>
      <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <div class="topbar">
      <div class="left">
        <h2>System Reports</h2>
      </div>


      <div style="display:flex; align-items:center; gap:14px;">
        <span id="phTime" data-server-ts="<?= $server_ts_ms ?>" class="muted-small" style="white-space:nowrap;">
          <?= htmlspecialchars($server_time_formatted) ?>
        </span>
      </div>
    </div>

    <div class="container">
      <!-- Sensor Data Report -->
      <div class="card" style="grid-column: 1 / -1;">
        <h3>📊 Sensor Data Report</h3>
        <p>Summary of temperature and humidity readings over the last 7 days.</p>
        <?php
        $sql = "SELECT
                  DATE(timestamp) as summary_date,
                  AVG(temperature) as avg_temp,
                  MIN(temperature) as min_temp,
                  MAX(temperature) as max_temp,
                  AVG(humidity) as avg_hum,
                  MIN(humidity) as min_hum,
                  MAX(humidity) as max_hum,
                  COUNT(*) as readings
                FROM sensor_data
                WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= UTC_TIMESTAMP() - INTERVAL 7 DAY
                GROUP BY DATE(timestamp)
                ORDER BY summary_date ASC";
        $result = $conn->query($sql);

        $data = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }

        // Save the report data to sensor_summary table
        foreach ($data as $row) {
            $insertSql = "INSERT IGNORE INTO sensor_summary (summary_date, avg_temp, min_temp, max_temp, avg_hum, min_hum, max_hum, readings) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertSql);
            if ($stmt === false) {
                die("Error preparing statement: " . $conn->error);
            }
            $stmt->bind_param("sddddddi", $row['summary_date'], $row['avg_temp'], $row['min_temp'], $row['max_temp'], $row['avg_hum'], $row['min_hum'], $row['max_hum'], $row['readings']);
            $stmt->execute();
            $stmt->close();
        }

        // Get historical data for averages (last 30 days)
        $hist_sql = "SELECT DATE(timestamp) as date, AVG(temperature) as avg_temp, AVG(humidity) as avg_hum FROM sensor_data WHERE STR_TO_DATE(timestamp, '%Y-%m-%d %H:%i:%s') >= UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY DATE(timestamp) ORDER BY date ASC";
        $hist_result = $conn->query($hist_sql);
        $hist_data = [];
        if ($hist_result && $hist_result->num_rows > 0) {
            while ($row = $hist_result->fetch_assoc()) {
                $hist_data[] = $row;
            }
        }

        // Calculate historical average changes
        $temp_changes = [];
        $hum_changes = [];
        for ($i = 1; $i < count($hist_data); $i++) {
            $temp_changes[] = $hist_data[$i]['avg_temp'] - $hist_data[$i-1]['avg_temp'];
            $hum_changes[] = $hist_data[$i]['avg_hum'] - $hist_data[$i-1]['avg_hum'];
        }
        $avg_hist_temp_change = count($temp_changes) > 0 ? array_sum($temp_changes) / count($temp_changes) : 0;
        $avg_hist_hum_change = count($hum_changes) > 0 ? array_sum($hum_changes) / count($hum_changes) : 0;
        ?>
        <table class="report-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Avg Temp (°C)</th>
              <th>Min Temp (°C)</th>
              <th>Max Temp (°C)</th>
              <th>Avg Hum (%)</th>
              <th>Min Hum (%)</th>
              <th>Max Hum (%)</th>
              <th>Readings</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['summary_date']) ?></td>
              <td><?= number_format($row['avg_temp'], 1) ?></td>
              <td><?= number_format($row['min_temp'], 1) ?></td>
              <td><?= number_format($row['max_temp'], 1) ?></td>
              <td><?= number_format($row['avg_hum'], 1) ?></td>
              <td><?= number_format($row['min_hum'], 1) ?></td>
              <td><?= number_format($row['max_hum'], 1) ?></td>
              <td><?= htmlspecialchars($row['readings']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Sensor Data Changes Report -->
      <div class="card" style="grid-column: 1 / -1;">
        <h3>📈 Sensor Data Changes</h3>
        <p>Daily changes in average temperature and humidity from the previous day.</p>
        <table class="report-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Temp Change (°C)</th>
              <th>Hum Change (%)</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $temp_changes = [];
            $hum_changes = [];
            for ($i = 1; $i < count($data); $i++):
              $temp_change = $data[$i]['avg_temp'] - $data[$i-1]['avg_temp'];
              $hum_change = $data[$i]['avg_hum'] - $data[$i-1]['avg_hum'];
              $temp_changes[] = $temp_change;
              $hum_changes[] = $hum_change;
            ?>
            <tr>
              <td><?= htmlspecialchars($data[$i]['summary_date']) ?></td>
              <td class="<?= $temp_change > 0 ? 'change-positive' : ($temp_change < 0 ? 'change-negative' : 'change-neutral') ?>">
                <i class="fas fa-<?= $temp_change > 0 ? 'arrow-up' : ($temp_change < 0 ? 'arrow-down' : 'minus') ?>"></i>
                <?= number_format($temp_change, 1) ?>
              </td>
              <td class="<?= $hum_change > 0 ? 'change-positive' : ($hum_change < 0 ? 'change-negative' : 'change-neutral') ?>">
                <i class="fas fa-<?= $hum_change > 0 ? 'arrow-up' : ($hum_change < 0 ? 'arrow-down' : 'minus') ?>"></i>
                <?= number_format($hum_change, 1) ?>
              </td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>

        <!-- Chart for Changes -->
        <div style="margin-top: 20px;">
          <canvas id="changesChart" width="400" height="200"></canvas>
        </div>

        <!-- Summary Section -->
        <div style="margin-top: 20px; padding: 15px; background: var(--muted-ghost); border-radius: 8px;">
          <h4 style="margin: 0 0 10px 0; color: var(--text);">Summary of Changes</h4>
          <p style="margin: 5px 0;">
            <strong>Average Temperature Change:</strong>
            <span class="<?= array_sum($temp_changes)/count($temp_changes) > 0 ? 'change-positive' : (array_sum($temp_changes)/count($temp_changes) < 0 ? 'change-negative' : 'change-neutral') ?>">
              <?= number_format(array_sum($temp_changes)/count($temp_changes), 2) ?> °C
            </span>
          </p>
          <p style="margin: 5px 0;">
            <strong>Average Humidity Change:</strong>
            <span class="<?= array_sum($hum_changes)/count($hum_changes) > 0 ? 'change-positive' : (array_sum($hum_changes)/count($hum_changes) < 0 ? 'change-negative' : 'change-neutral') ?>">
              <?= number_format(array_sum($hum_changes)/count($hum_changes), 2) ?> %
            </span>
          </p>
          <p style="margin: 5px 0; font-size: 14px; color: var(--muted);">
            Trends: Temperature has been <?= array_sum($temp_changes)/count($temp_changes) > 0 ? 'increasing' : (array_sum($temp_changes)/count($temp_changes) < 0 ? 'decreasing' : 'stable') ?> on average,
            while humidity has been <?= array_sum($hum_changes)/count($hum_changes) > 0 ? 'increasing' : (array_sum($hum_changes)/count($hum_changes) < 0 ? 'decreasing' : 'stable') ?>.
          </p>
        </div>
      </div>

    <script>
      // Pass PHP data to JavaScript
      var tempChanges = <?php echo json_encode($temp_changes); ?>;
      var humChanges = <?php echo json_encode($hum_changes); ?>;
      var dates = <?php echo json_encode(array_slice(array_column($data, 'summary_date'), 1)); ?>;
      // ---------------- PH SERVER-SYNCED TIME ----------------
      (function(){
        const el = document.getElementById('phTime');
        if(!el) return;

        let current = parseInt(el.dataset.serverTs, 10) || Date.now();

        function formatPH(ms){
          const d = new Date(ms);
          return d.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
          }).replace(',', ' —');
        }

        el.textContent = formatPH(current);

        setInterval(function(){
          current += 1000;
          el.textContent = formatPH(current);
        }, 1000);
      })();

    </script>
  </div>
</body>
</html>
