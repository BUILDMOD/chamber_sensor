<?php
include('includes/auth_check.php');
include('includes/db_connect.php');

$createTableSql = "CREATE TABLE IF NOT EXISTS sensor_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    summary_date DATE NOT NULL,
    avg_temp FLOAT, min_temp FLOAT, max_temp FLOAT,
    avg_hum FLOAT, min_hum FLOAT, max_hum FLOAT,
    readings INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if (!$conn->query($createTableSql)) die("Error creating table: " . $conn->error);

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

$displayName = 'Menu';
if (isset($_SESSION) && !empty($_SESSION['fullname'])) $displayName = $_SESSION['fullname'];
elseif (isset($_SESSION) && !empty($_SESSION['user'])) $displayName = $_SESSION['user'];

// ── 7-day sensor data ──
$sql = "SELECT
          DATE(timestamp) as summary_date,
          AVG(temperature) as avg_temp, MIN(temperature) as min_temp, MAX(temperature) as max_temp,
          AVG(humidity) as avg_hum, MIN(humidity) as min_hum, MAX(humidity) as max_hum,
          COUNT(*) as readings
        FROM sensor_data
        WHERE STR_TO_DATE(timestamp,'%Y-%m-%d %H:%i:%s') >= UTC_TIMESTAMP() - INTERVAL 7 DAY
        GROUP BY DATE(timestamp) ORDER BY summary_date ASC";
$result = $conn->query($sql);
$data = [];
if ($result && $result->num_rows > 0)
    while ($row = $result->fetch_assoc()) $data[] = $row;

// Save to sensor_summary
foreach ($data as $row) {
    $ins = $conn->prepare("INSERT IGNORE INTO sensor_summary (summary_date,avg_temp,min_temp,max_temp,avg_hum,min_hum,max_hum,readings) VALUES (?,?,?,?,?,?,?,?)");
    if ($ins) { $ins->bind_param("sddddddi",$row['summary_date'],$row['avg_temp'],$row['min_temp'],$row['max_temp'],$row['avg_hum'],$row['min_hum'],$row['max_hum'],$row['readings']); $ins->execute(); $ins->close(); }
}

// ── compute changes ──
$temp_changes = []; $hum_changes = []; $change_dates = [];
for ($i = 1; $i < count($data); $i++) {
    $temp_changes[] = $data[$i]['avg_temp'] - $data[$i-1]['avg_temp'];
    $hum_changes[]  = $data[$i]['avg_hum']  - $data[$i-1]['avg_hum'];
    $change_dates[]  = $data[$i]['summary_date'];
}
$avg_tc = count($temp_changes) ? array_sum($temp_changes)/count($temp_changes) : 0;
$avg_hc = count($hum_changes)  ? array_sum($hum_changes)/count($hum_changes)   : 0;

function trendWord($v) { return $v > 0 ? 'Increasing' : ($v < 0 ? 'Decreasing' : 'Stable'); }
function trendClass($v){ return $v > 0 ? 'pos' : ($v < 0 ? 'neg' : 'neu'); }
function trendIcon($v) { return $v > 0 ? 'arrow-up' : ($v < 0 ? 'arrow-down' : 'minus'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reports</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg:       #f0f2f5;
      --surface:  #ffffff;
      --surface2: #f7f8fa;
      --border:   rgba(0,0,0,0.07);
      --text:     #0d1117;
      --muted:    #6e7681;
      --green:    #1a9e5c;
      --green-lt: #e6f7ef;
      --red:      #d93025;
      --red-lt:   #fdecea;
      --amber:    #b45309;
      --amber-lt: #fef3c7;
      --blue:     #1a6bba;
      --blue-lt:  #e8f1fb;
      --r:        12px;
      --shadow:   0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
      --shadow-lg:0 2px 8px rgba(0,0,0,0.08), 0 12px 40px rgba(0,0,0,0.06);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', system-ui, sans-serif; background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* ── SIDEBAR ── */
    .sidebar { position: fixed; inset: 0 auto 0 0; width: 220px; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 50; }
    .sidebar-logo { padding: 22px 20px 18px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); }
    .sidebar-logo img { width: 36px; height: 36px; border-radius: 8px; }
    .sidebar-logo-text { font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.2; }
    .sidebar-logo-sub  { font-size: 11px; color: var(--muted); }
    .sidebar-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 1px; overflow-y: auto; }
    .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; color: var(--muted); text-decoration: none; font-size: 13.5px; font-weight: 500; transition: all .15s ease; }
    .sidebar-nav a i { width: 16px; text-align: center; font-size: 13px; }
    .sidebar-nav a:hover  { background: var(--surface2); color: var(--text); }
    .sidebar-nav a.active { background: var(--green-lt); color: var(--green); font-weight: 600; }

    /* ── MAIN ── */
    .main { margin-left: 220px; min-height: 100vh; }

    /* ── TOPBAR ── */
    .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 28px; height: 56px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; }
    .topbar-title { font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -.2px; }
    .topbar-time { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--muted); background: var(--surface2); padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border); }

    /* ── PAGE BODY ── */
    .page { padding: 24px 28px; max-width: 1280px; display: flex; flex-direction: column; gap: 16px; }

    /* ── CARD ── */
    .card { background: var(--surface); border-radius: var(--r); border: 1px solid var(--border); box-shadow: var(--shadow); overflow: hidden; }
    .card-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
    .card-title { font-size: 13px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
    .card-title .icon { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
    .icon-blue  { background: var(--blue-lt);  color: var(--blue);  }
    .icon-green { background: var(--green-lt); color: var(--green); }
    .card-sub   { font-size: 12px; color: var(--muted); }
    .card-body  { padding: 0; }

    /* ── STAT STRIP ── */
    .stat-strip { display: flex; gap: 0; border-bottom: 1px solid var(--border); }
    .stat-item { flex: 1; padding: 14px 18px; border-right: 1px solid var(--border); }
    .stat-item:last-child { border-right: none; }
    .stat-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .stat-val   { font-size: 22px; font-weight: 700; font-family: 'DM Mono', monospace; color: var(--text); }
    .stat-val span { font-size: 12px; font-weight: 500; color: var(--muted); }

    /* ── TABLE ── */
    .tbl-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    thead th { text-align: left; padding: 9px 14px; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap; }
    tbody td { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text); font-family: 'DM Mono', monospace; font-size: 12.5px; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover { background: var(--surface2); }
    td.date-col { font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600; color: var(--text); }
    td.readings-col { font-weight: 700; }

    /* range badges */
    .badge { display: inline-block; padding: 2px 7px; border-radius: 20px; font-size: 11px; font-weight: 700; font-family: 'DM Sans', sans-serif; }
    .badge-green { background: var(--green-lt); color: var(--green); }
    .badge-red   { background: var(--red-lt);   color: var(--red);   }
    .badge-amber { background: var(--amber-lt); color: var(--amber); }
    .badge-blue  { background: var(--blue-lt);  color: var(--blue);  }

    /* change indicators */
    .pos { color: var(--green); font-weight: 700; }
    .neg { color: var(--red);   font-weight: 700; }
    .neu { color: var(--muted); font-weight: 600; }

    /* ── CHART AREA ── */
    .chart-wrap { padding: 20px; }
    .chart-wrap canvas { max-height: 220px; }

    /* ── SUMMARY BOX ── */
    .summary-box { background: var(--surface2); border-top: 1px solid var(--border); padding: 20px; }
    .summary-box h4 { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 12px; }
    .summary-rows { display: flex; gap: 24px; flex-wrap: wrap; }
    .summary-row { display: flex; flex-direction: column; gap: 2px; }
    .summary-row .s-label { font-size: 11px; color: var(--muted); }
    .summary-row .s-val   { font-size: 16px; font-weight: 700; font-family: 'DM Mono', monospace; display: flex; align-items: center; gap: 5px; }
    .summary-row .s-trend { font-size: 11px; font-weight: 600; margin-top: 2px; }

    /* empty state */
    .empty-state { text-align: center; padding: 40px; color: var(--muted); }
    .empty-state i { font-size: 28px; display: block; margin-bottom: 8px; opacity: .35; }
    .empty-state span { font-size: 13px; }
  </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="assets/img/logo.png" alt="logo">
    <div>
      <div class="sidebar-logo-text">MushroomOS</div>
      <div class="sidebar-logo-sub">Cultivation System</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="harvest.php"><i class="fas fa-seedling"></i> Harvest & Batches</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a>
  </nav>
</aside>

<!-- ── MAIN ── -->
<main class="main">
  <header class="topbar">
    <span class="topbar-title">Reports</span>
    <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
  </header>

  <div class="page">

    <?php
    // Quick stat aggregates for the strip
    $all_temps = array_column($data, 'avg_temp');
    $all_hums  = array_column($data, 'avg_hum');
    $all_reads = array_column($data, 'readings');
    $overall_avg_t = count($all_temps) ? array_sum($all_temps)/count($all_temps) : null;
    $overall_avg_h = count($all_hums)  ? array_sum($all_hums)/count($all_hums)   : null;
    $total_reads   = array_sum($all_reads);
    $overall_min_t = count($data) ? min(array_column($data,'min_temp')) : null;
    $overall_max_t = count($data) ? max(array_column($data,'max_temp')) : null;
    ?>

    <!-- ── Sensor Data Report ── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span class="icon icon-blue"><i class="fas fa-table"></i></span>
          Sensor Data Report
        </div>
        <span class="card-sub">Last 7 days · <?= count($data) ?> day<?= count($data) != 1 ? 's' : '' ?> of data</span>
      </div>

      <!-- stat strip -->
      <?php if (count($data)): ?>
      <div class="stat-strip">
        <div class="stat-item">
          <div class="stat-label">Avg Temp</div>
          <div class="stat-val"><?= number_format($overall_avg_t, 1) ?><span> °C</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Temp Range</div>
          <div class="stat-val"><?= number_format($overall_min_t,1) ?>–<?= number_format($overall_max_t,1) ?><span> °C</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Avg Humidity</div>
          <div class="stat-val"><?= number_format($overall_avg_h, 1) ?><span> %</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Total Readings</div>
          <div class="stat-val"><?= number_format($total_reads) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card-body">
        <?php if (empty($data)): ?>
          <div class="empty-state"><i class="fas fa-database"></i><span>No sensor data available for the last 7 days.</span></div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Avg Temp</th>
                <th>Min Temp</th>
                <th>Max Temp</th>
                <th>Avg Hum</th>
                <th>Min Hum</th>
                <th>Max Hum</th>
                <th>Readings</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                $tc = ($row['avg_temp'] >= 22 && $row['avg_temp'] <= 28) ? 'badge-green' : (($row['avg_temp'] < 22) ? 'badge-blue' : 'badge-amber');
                $hc = ($row['avg_hum']  >= 85 && $row['avg_hum']  <= 95) ? 'badge-green' : (($row['avg_hum']  < 85) ? 'badge-blue' : 'badge-red');
              ?>
              <tr>
                <td class="date-col"><?= date('M j, Y', strtotime($row['summary_date'])) ?></td>
                <td><span class="badge <?= $tc ?>"><?= number_format($row['avg_temp'],1) ?>°</span></td>
                <td><?= number_format($row['min_temp'],1) ?>°</td>
                <td><?= number_format($row['max_temp'],1) ?>°</td>
                <td><span class="badge <?= $hc ?>"><?= number_format($row['avg_hum'],1) ?>%</span></td>
                <td><?= number_format($row['min_hum'],1) ?>%</td>
                <td><?= number_format($row['max_hum'],1) ?>%</td>
                <td class="readings-col"><?= number_format($row['readings']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Sensor Data Changes ── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span class="icon icon-green"><i class="fas fa-chart-line"></i></span>
          Sensor Data Changes
        </div>
        <span class="card-sub">Day-over-day deltas</span>
      </div>

      <?php if (count($data) < 2): ?>
        <div class="empty-state" style="padding:40px;"><i class="fas fa-chart-line"></i><span>Not enough data to compute changes.</span></div>
      <?php else: ?>

        <!-- chart -->
        <div class="chart-wrap">
          <canvas id="changesChart"></canvas>
        </div>

        <!-- changes table -->
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Temp Change (°C)</th>
                <th>Humidity Change (%)</th>
              </tr>
            </thead>
            <tbody>
              <?php for ($i = 1; $i < count($data); $i++):
                $row_tc = $data[$i]['avg_temp'] - $data[$i-1]['avg_temp'];
                $row_hc = $data[$i]['avg_hum']  - $data[$i-1]['avg_hum'];
              ?>
              <tr>
                <td class="date-col"><?= date('M j, Y', strtotime($data[$i]['summary_date'])) ?></td>
                <td class="<?= trendClass($row_tc) ?>">
                  <i class="fas fa-<?= trendIcon($row_tc) ?>"></i>
                  <?= ($row_tc >= 0 ? '+' : '') . number_format($row_tc, 2) ?>
                </td>
                <td class="<?= trendClass($row_hc) ?>">
                  <i class="fas fa-<?= trendIcon($row_hc) ?>"></i>
                  <?= ($row_hc >= 0 ? '+' : '') . number_format($row_hc, 2) ?>
                </td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>

        <!-- summary box — rendered after table so loop vars don't interfere -->
        <div class="summary-box">
          <h4>Summary of Changes</h4>
          <div class="summary-rows">
            <div class="summary-row">
              <span class="s-label">Average Temperature Change</span>
              <span class="s-val" style="color:<?= $avg_tc > 0 ? 'var(--green)' : ($avg_tc < 0 ? 'var(--red)' : 'var(--muted)') ?>">
                <i class="fas fa-<?= trendIcon($avg_tc) ?>" style="font-size:13px;"></i>
                <?= ($avg_tc >= 0 ? '+' : '') . number_format($avg_tc, 2) ?>°C
              </span>
              <span class="s-trend" style="color:<?= $avg_tc > 0 ? 'var(--green)' : ($avg_tc < 0 ? 'var(--red)' : 'var(--muted)') ?>"><?= trendWord($avg_tc) ?></span>
            </div>
            <div class="summary-row">
              <span class="s-label">Average Humidity Change</span>
              <span class="s-val" style="color:<?= $avg_hc > 0 ? 'var(--green)' : ($avg_hc < 0 ? 'var(--red)' : 'var(--muted)') ?>">
                <i class="fas fa-<?= trendIcon($avg_hc) ?>" style="font-size:13px;"></i>
                <?= ($avg_hc >= 0 ? '+' : '') . number_format($avg_hc, 2) ?>%
              </span>
              <span class="s-trend" style="color:<?= $avg_hc > 0 ? 'var(--green)' : ($avg_hc < 0 ? 'var(--red)' : 'var(--muted)') ?>"><?= trendWord($avg_hc) ?></span>
            </div>
            <div class="summary-row" style="flex:1;min-width:200px;">
              <span class="s-label">Trend Narrative</span>
              <span style="font-size:13px;color:var(--muted);line-height:1.6;margin-top:4px;">
                Temperature has been <strong style="color:var(--text)"><?= strtolower(trendWord($avg_tc)) ?></strong> on average,
                while humidity has been <strong style="color:var(--text)"><?= strtolower(trendWord($avg_hc)) ?></strong>.
              </span>
            </div>
          </div>
        </div>

      <?php endif; ?>
    </div>

  </div><!-- end page -->
</main>

<script>
// ── PH Time ──
(function(){
  const el = document.getElementById('phTime');
  if (!el) return;
  let t = parseInt(el.dataset.serverTs, 10) || Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH', {
    timeZone:'Asia/Manila', month:'short', day:'numeric', year:'numeric',
    hour:'numeric', minute:'2-digit', second:'2-digit', hour12:true
  }).replace(',', ' —');
  el.textContent = fmt(t);
  setInterval(() => { t += 1000; el.textContent = fmt(t); }, 1000);
})();

// ── Changes Chart ──
(function(){
  const canvas = document.getElementById('changesChart');
  if (!canvas) return;

  const tempChanges = <?= json_encode($temp_changes) ?>;
  const humChanges  = <?= json_encode($hum_changes) ?>;
  const dates       = <?= json_encode($change_dates) ?>;

  if (!dates.length) return;

  const fmtDate = s => {
    const d = new Date(s + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { month:'short', day:'numeric' });
  };

  new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: {
      labels: dates.map(fmtDate),
      datasets: [
        {
          label: 'Temp Change (°C)',
          data: tempChanges,
          backgroundColor: tempChanges.map(v => v >= 0 ? 'rgba(26,158,92,0.7)' : 'rgba(217,48,37,0.7)'),
          borderRadius: 5,
          borderSkipped: false,
          yAxisID: 'y',
        },
        {
          label: 'Humidity Change (%)',
          data: humChanges,
          backgroundColor: humChanges.map(v => v >= 0 ? 'rgba(26,107,186,0.6)' : 'rgba(180,83,9,0.6)'),
          borderRadius: 5,
          borderSkipped: false,
          yAxisID: 'y1',
          type: 'line',
          fill: false,
          borderColor: 'rgba(26,107,186,0.8)',
          pointBackgroundColor: humChanges.map(v => v >= 0 ? '#1a6bba' : '#b45309'),
          pointRadius: 4,
          tension: 0.3,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: {
            font: { family: 'DM Sans', size: 12 },
            color: '#6e7681',
            boxWidth: 12, boxHeight: 12, borderRadius: 4,
          }
        },
        tooltip: {
          backgroundColor: '#fff',
          borderColor: 'rgba(0,0,0,0.08)',
          borderWidth: 1,
          titleColor: '#0d1117',
          bodyColor: '#6e7681',
          padding: 12,
          cornerRadius: 8,
          titleFont: { family: 'DM Sans', weight: '700', size: 12 },
          bodyFont:  { family: 'DM Mono', size: 12 },
          callbacks: {
            label: ctx => {
              const v = ctx.parsed.y;
              const sign = v >= 0 ? '+' : '';
              return ` ${ctx.dataset.label}: ${sign}${v.toFixed(2)}`;
            }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { family: 'DM Sans', size: 11 }, color: '#6e7681' }
        },
        y: {
          position: 'left',
          grid: { color: 'rgba(0,0,0,0.05)' },
          ticks: {
            font: { family: 'DM Mono', size: 11 }, color: '#6e7681',
            callback: v => (v >= 0 ? '+' : '') + v.toFixed(1) + '°'
          }
        },
        y1: {
          position: 'right',
          grid: { drawOnChartArea: false },
          ticks: {
            font: { family: 'DM Mono', size: 11 }, color: '#6e7681',
            callback: v => (v >= 0 ? '+' : '') + v.toFixed(1) + '%'
          }
        }
      }
    }
  });
})();
</script>
</body>
</html>