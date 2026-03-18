<?php
session_start();
include 'includes/db_connect.php';
include 'includes/auth_check.php';

date_default_timezone_set('Asia/Manila');

// ── Date range from GET params (default: current month) ──
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$df = $conn->real_escape_string($date_from);
$dt = $conn->real_escape_string($date_to);

$label_from = date('M j, Y', strtotime($date_from));
$label_to   = date('M j, Y', strtotime($date_to));
$generated  = date('F j, Y \a\t h:i A');

// ── 1. SENSOR SUMMARY ──
$sensor_rows = [];
$r = $conn->query("SELECT DATE(timestamp) as day,
    ROUND(AVG(temperature),1) as avg_temp, ROUND(MIN(temperature),1) as min_temp, ROUND(MAX(temperature),1) as max_temp,
    ROUND(AVG(humidity),1) as avg_hum, ROUND(MIN(humidity),1) as min_hum, ROUND(MAX(humidity),1) as max_hum,
    COUNT(*) as readings
    FROM sensor_data
    WHERE DATE(timestamp) BETWEEN '$df' AND '$dt'
    GROUP BY DATE(timestamp) ORDER BY day ASC");
if ($r) while ($row = $r->fetch_assoc()) $sensor_rows[] = $row;

// ── 2. DEVICE ACTIVITY LOG ──
$device_rows = [];
$r = $conn->query("SELECT device, action, trigger_type, logged_at
    FROM device_logs
    WHERE DATE(logged_at) BETWEEN '$df' AND '$dt'
    ORDER BY logged_at DESC LIMIT 200");
if ($r) while ($row = $r->fetch_assoc()) $device_rows[] = $row;

// ── 3. ALERT HISTORY ──
$alert_rows = [];
$r = $conn->query("SELECT alert_type, severity, message, resolved, logged_at
    FROM alert_logs
    WHERE DATE(logged_at) BETWEEN '$df' AND '$dt'
    ORDER BY logged_at DESC LIMIT 200");
if ($r) while ($row = $r->fetch_assoc()) $alert_rows[] = $row;

// ── 4. MUSHROOM HARVEST RECORDS ──
$harvest_rows = [];
$r = $conn->query("SELECT record_date, mushroom_count, growth_stage, notes
    FROM mushroom_records
    WHERE record_date BETWEEN '$df' AND '$dt'
    ORDER BY record_date ASC");
if ($r) while ($row = $r->fetch_assoc()) $harvest_rows[] = $row;
$total_harvest = array_sum(array_column(
    array_filter($harvest_rows, fn($r) => $r['growth_stage'] === 'Harvest'),
    'mushroom_count'
));

// ── 5. CAMERA CAPTURES SUMMARY ──
$camera_rows = [];
$r = $conn->query("SELECT DATE(captured_at) as day,
    COUNT(*) as total_captures,
    SUM(CASE WHEN harvest_status='Ready for Harvest' THEN 1 ELSE 0 END) as ready,
    SUM(CASE WHEN harvest_status='Overripe' THEN 1 ELSE 0 END) as overripe,
    ROUND(AVG(diameter_cm),1) as avg_diameter
    FROM camera_captures
    WHERE DATE(captured_at) BETWEEN '$df' AND '$dt'
    GROUP BY DATE(captured_at) ORDER BY day ASC");
if ($r) while ($row = $r->fetch_assoc()) $camera_rows[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MushroomOS — Printable Report</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Arial', sans-serif; font-size: 12px; color: #111; background: #fff; }

  /* ── Screen controls (hidden on print) ── */
  .screen-only {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: #1a2e1a; padding: 12px 24px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
  }
  .screen-only .ctrl-title { color: #fff; font-size: 14px; font-weight: 700; }
  .screen-only .ctrl-right { display: flex; gap: 10px; align-items: center; }
  .ctrl-form { display: flex; gap: 8px; align-items: center; }
  .ctrl-form label { color: #adc9a0; font-size: 11px; font-weight: 600; }
  .ctrl-form input[type=date] {
    padding: 5px 8px; border-radius: 6px; border: 1px solid #3a5a3a;
    background: #243424; color: #fff; font-size: 11px;
  }
  .btn-print {
    padding: 8px 20px; background: #2d7a3a; color: #fff;
    border: none; border-radius: 7px; font-size: 12px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 6px;
  }
  .btn-print:hover { background: #3a9a4a; }
  .btn-back {
    padding: 8px 16px; background: transparent; color: #adc9a0;
    border: 1px solid #3a5a3a; border-radius: 7px; font-size: 12px;
    cursor: pointer; text-decoration: none;
  }

  /* ── Report body ── */
  .report-wrap { padding: 80px 48px 48px; max-width: 960px; margin: 0 auto; }

  /* Header */
  .report-header { border-bottom: 3px solid #2d7a3a; padding-bottom: 14px; margin-bottom: 24px; }
  .report-logo-row { display: flex; align-items: center; justify-content: space-between; }
  .report-system-name { font-size: 18px; font-weight: 800; color: #1a2e1a; }
  .report-system-sub { font-size: 11px; color: #555; margin-top: 2px; }
  .report-meta { text-align: right; font-size: 11px; color: #555; line-height: 1.7; }
  .report-meta strong { color: #1a2e1a; }
  .report-period {
    margin-top: 10px; padding: 8px 14px; background: #f0f7f0;
    border-radius: 6px; display: inline-block; font-size: 12px; color: #2d7a3a; font-weight: 700;
  }

  /* Sections */
  .section { margin-bottom: 32px; page-break-inside: avoid; }
  .section-title {
    font-size: 13px; font-weight: 800; color: #1a2e1a;
    padding: 7px 12px; background: #e8f5e8;
    border-left: 4px solid #2d7a3a; margin-bottom: 10px;
    display: flex; align-items: center; gap: 8px;
  }
  .section-title .ico { font-size: 14px; }

  /* Tables */
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th {
    background: #2d7a3a; color: #fff; padding: 7px 10px;
    text-align: left; font-size: 11px; font-weight: 700;
  }
  td { padding: 6px 10px; border-bottom: 1px solid #e8e8e8; vertical-align: middle; }
  tr:nth-child(even) td { background: #f9fdf9; }
  tr:last-child td { border-bottom: none; }
  .no-data { text-align: center; color: #999; padding: 18px; font-style: italic; }

  /* Badges */
  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 100px;
    font-size: 10px; font-weight: 700;
  }
  .badge-auto     { background: #e8f5e8; color: #2d7a3a; }
  .badge-manual   { background: #e8f0ff; color: #5b3dd4; }
  .badge-schedule { background: #f3e8ff; color: #7c3aed; }
  .badge-emergency{ background: #fff3e0; color: #d97706; }
  .badge-fault    { background: #fde8e8; color: #dc2626; }
  .badge-on       { background: #e8f5e8; color: #2d7a3a; }
  .badge-off      { background: #f3f3f3; color: #666; }
  .badge-critical { background: #fde8e8; color: #dc2626; }
  .badge-warning  { background: #fff3e0; color: #d97706; }
  .badge-info     { background: #e8f0ff; color: #3b6fd4; }
  .badge-resolved { background: #f3f3f3; color: #999; }
  .badge-spawn    { background: #fff8e1; color: #b45309; }
  .badge-pin      { background: #e8f0ff; color: #3b6fd4; }
  .badge-fruit    { background: #e8f5e8; color: #2d7a3a; }
  .badge-harvest  { background: #1a2e1a; color: #fff; }
  .badge-ready    { background: #e8f5e8; color: #2d7a3a; }
  .badge-overripe { background: #fff3e0; color: #d97706; }

  /* Summary stats row */
  .stats-row { display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
  .stat-box {
    flex: 1; min-width: 120px; padding: 10px 14px;
    background: #f0f7f0; border-radius: 8px; border: 1px solid #d0e8d0;
  }
  .stat-box .stat-val { font-size: 22px; font-weight: 800; color: #2d7a3a; }
  .stat-box .stat-lbl { font-size: 10px; color: #666; margin-top: 2px; }

  /* Footer */
  .report-footer {
    margin-top: 40px; padding-top: 12px; border-top: 1px solid #ddd;
    text-align: center; font-size: 10px; color: #999;
  }

  /* ── Print styles ── */
  @media print {
    .screen-only { display: none !important; }
    .report-wrap { padding: 24px; }
    .section { page-break-inside: avoid; }
    body { font-size: 11px; }
    @page { margin: 1.5cm; size: A4; }
  }
</style>
</head>
<body>

<!-- ── Screen Controls ── -->
<div class="screen-only">
  <span class="ctrl-title">🍄 MushroomOS — Print Report</span>
  <div class="ctrl-right">
    <form class="ctrl-form" method="GET">
      <label>From</label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
      <label>To</label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
      <button type="submit" class="btn-print" style="background:#3a5a3a;">
        <i class="fas fa-filter"></i> Filter
      </button>
    </form>
    <button class="btn-print" onclick="window.print()">
      🖨️ Print / Save PDF
    </button>
    <button class="btn-back" onclick="window.close(); if(!window.closed) history.back();">← Back</button>
  </div>
</div>

<!-- ── Report ── -->
<div class="report-wrap">

  <!-- Header -->
  <div class="report-header">
    <div class="report-logo-row">
      <div>
        <div class="report-system-name">🍄 MushroomOS</div>
        <div class="report-system-sub">J WHO? Mushroom Incubation — Cultivation Monitoring & Control System</div>
        <div class="report-period">📅 Report Period: <?= $label_from ?> — <?= $label_to ?></div>
      </div>
      <div class="report-meta">
        <div>Generated by: <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong></div>
        <div>Role: <strong><?= ucfirst($_SESSION['role']) ?></strong></div>
        <div>Date: <strong><?= $generated ?></strong></div>
      </div>
    </div>
  </div>

  <!-- ══════════ 1. SENSOR DATA ══════════ -->
  <div class="section">
    <div class="section-title"><span class="ico">🌡️</span> Sensor Data Summary — Temperature &amp; Humidity</div>

    <?php if (!empty($sensor_rows)): ?>
    <div class="stats-row">
      <?php
        $all_temps = array_column($sensor_rows, 'avg_temp');
        $all_hums  = array_column($sensor_rows, 'avg_hum');
        $overall_temp = round(array_sum($all_temps) / count($all_temps), 1);
        $overall_hum  = round(array_sum($all_hums)  / count($all_hums),  1);
        $total_reads  = array_sum(array_column($sensor_rows, 'readings'));
      ?>
      <div class="stat-box"><div class="stat-val"><?= $overall_temp ?>°C</div><div class="stat-lbl">Avg Temperature</div></div>
      <div class="stat-box"><div class="stat-val"><?= $overall_hum ?>%</div><div class="stat-lbl">Avg Humidity</div></div>
      <div class="stat-box"><div class="stat-val"><?= count($sensor_rows) ?></div><div class="stat-lbl">Days with Data</div></div>
      <div class="stat-box"><div class="stat-val"><?= number_format($total_reads) ?></div><div class="stat-lbl">Total Readings</div></div>
    </div>
    <table>
      <tr>
        <th>Date</th>
        <th>Avg Temp</th><th>Min Temp</th><th>Max Temp</th>
        <th>Avg Humidity</th><th>Min Hum</th><th>Max Hum</th>
        <th>Readings</th>
      </tr>
      <?php foreach ($sensor_rows as $row): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($row['day'])) ?></td>
        <td><?= $row['avg_temp'] ?>°C</td>
        <td><?= $row['min_temp'] ?>°C</td>
        <td><?= $row['max_temp'] ?>°C</td>
        <td><?= $row['avg_hum'] ?>%</td>
        <td><?= $row['min_hum'] ?>%</td>
        <td><?= $row['max_hum'] ?>%</td>
        <td><?= number_format($row['readings']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="no-data">No sensor data found for the selected period.</p>
    <?php endif; ?>
  </div>

  <!-- ══════════ 2. DEVICE ACTIVITY LOG ══════════ -->
  <div class="section">
    <div class="section-title"><span class="ico">⚙️</span> Device Activity Log</div>
    <?php if (!empty($device_rows)): ?>
    <table>
      <tr>
        <th>Date &amp; Time</th><th>Device</th><th>Action</th><th>Trigger</th>
      </tr>
      <?php foreach ($device_rows as $row):
        $trig = $row['trigger_type'];
        $trigClass = "badge-$trig";
        $actClass  = $row['action'] === 'ON' ? 'badge-on' : 'badge-off';
      ?>
      <tr>
        <td><?= date('M j, Y h:i A', strtotime($row['logged_at'])) ?></td>
        <td><strong><?= ucfirst($row['device']) ?></strong></td>
        <td><span class="badge <?= $actClass ?>"><?= $row['action'] ?></span></td>
        <td><span class="badge <?= $trigClass ?>"><?= ucfirst($trig) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="no-data">No device activity found for the selected period.</p>
    <?php endif; ?>
  </div>

  <!-- ══════════ 3. ALERT HISTORY ══════════ -->
  <div class="section">
    <div class="section-title"><span class="ico">🔔</span> Alert History</div>
    <?php if (!empty($alert_rows)):
      $critCount = count(array_filter($alert_rows, fn($r) => $r['severity'] === 'critical'));
      $warnCount = count(array_filter($alert_rows, fn($r) => $r['severity'] === 'warning'));
    ?>
    <div class="stats-row">
      <div class="stat-box"><div class="stat-val"><?= count($alert_rows) ?></div><div class="stat-lbl">Total Alerts</div></div>
      <div class="stat-box"><div class="stat-val" style="color:#dc2626"><?= $critCount ?></div><div class="stat-lbl">Critical</div></div>
      <div class="stat-box"><div class="stat-val" style="color:#d97706"><?= $warnCount ?></div><div class="stat-lbl">Warnings</div></div>
    </div>
    <table>
      <tr><th>Date &amp; Time</th><th>Type</th><th>Severity</th><th>Message</th><th>Status</th></tr>
      <?php foreach ($alert_rows as $row): ?>
      <tr>
        <td><?= date('M j, Y h:i A', strtotime($row['logged_at'])) ?></td>
        <td><?= htmlspecialchars(str_replace('_',' ', $row['alert_type'])) ?></td>
        <td><span class="badge badge-<?= $row['severity'] ?>"><?= ucfirst($row['severity']) ?></span></td>
        <td><?= htmlspecialchars($row['message']) ?></td>
        <td><?= $row['resolved'] ? '<span class="badge badge-resolved">Resolved</span>' : '<span class="badge badge-critical">Active</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="no-data">No alerts found for the selected period.</p>
    <?php endif; ?>
  </div>

  <!-- ══════════ 4. MUSHROOM HARVEST RECORDS ══════════ -->
  <div class="section">
    <div class="section-title"><span class="ico">🍄</span> Mushroom Growth Records</div>
    <?php if (!empty($harvest_rows)): ?>
    <div class="stats-row">
      <div class="stat-box"><div class="stat-val"><?= count($harvest_rows) ?></div><div class="stat-lbl">Total Records</div></div>
      <div class="stat-box"><div class="stat-val"><?= $total_harvest ?></div><div class="stat-lbl">Total Harvested (pcs)</div></div>
    </div>
    <table>
      <tr><th>Date</th><th>Count</th><th>Growth Stage</th><th>Notes</th></tr>
      <?php foreach ($harvest_rows as $row):
        $stage = $row['growth_stage'];
        $stageClass = match($stage) {
          'Spawn Run' => 'badge-spawn',
          'Pinning'   => 'badge-pin',
          'Fruiting'  => 'badge-fruit',
          'Harvest'   => 'badge-harvest',
          default     => ''
        };
      ?>
      <tr>
        <td><?= date('M j, Y', strtotime($row['record_date'])) ?></td>
        <td><strong><?= $row['mushroom_count'] ?> pcs</strong></td>
        <td><span class="badge <?= $stageClass ?>"><?= $stage ?></span></td>
        <td><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="no-data">No mushroom records found for the selected period.</p>
    <?php endif; ?>
  </div>

  <!-- ══════════ 5. CAMERA CAPTURES SUMMARY ══════════ -->
  <div class="section">
    <div class="section-title"><span class="ico">📷</span> Camera Captures Summary</div>
    <?php if (!empty($camera_rows)):
      $total_captures = array_sum(array_column($camera_rows, 'total_captures'));
      $total_ready    = array_sum(array_column($camera_rows, 'ready'));
      $total_overripe = array_sum(array_column($camera_rows, 'overripe'));
    ?>
    <div class="stats-row">
      <div class="stat-box"><div class="stat-val"><?= $total_captures ?></div><div class="stat-lbl">Total Captures</div></div>
      <div class="stat-box"><div class="stat-val" style="color:#2d7a3a"><?= $total_ready ?></div><div class="stat-lbl">Ready for Harvest</div></div>
      <div class="stat-box"><div class="stat-val" style="color:#d97706"><?= $total_overripe ?></div><div class="stat-lbl">Overripe Detected</div></div>
    </div>
    <table>
      <tr><th>Date</th><th>Captures</th><th>Ready for Harvest</th><th>Overripe</th><th>Avg Diameter</th></tr>
      <?php foreach ($camera_rows as $row): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($row['day'])) ?></td>
        <td><?= $row['total_captures'] ?></td>
        <td><?= $row['ready'] > 0 ? '<span class="badge badge-ready">'.$row['ready'].'</span>' : '0' ?></td>
        <td><?= $row['overripe'] > 0 ? '<span class="badge badge-overripe">'.$row['overripe'].'</span>' : '0' ?></td>
        <td><?= $row['avg_diameter'] ? $row['avg_diameter'].' cm' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p class="no-data">No camera captures found for the selected period.</p>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div class="report-footer">
    MushroomOS — J WHO? Mushroom Incubation System &nbsp;|&nbsp;
    Report generated on <?= $generated ?> &nbsp;|&nbsp;
    <?= $label_from ?> to <?= $label_to ?>
  </div>

</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</body>
</html>