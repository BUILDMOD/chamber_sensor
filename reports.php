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

// ── Calendar view mode ──
// view: 'day' | 'month' | 'year'
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'month';
$valid_views = ['day', 'month', 'year'];
if (!in_array($view_mode, $valid_views)) $view_mode = 'month';

$sel_year  = isset($_GET['year'])  ? intval($_GET['year'])  : intval(date('Y'));
$sel_month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
$sel_day   = isset($_GET['day'])   ? intval($_GET['day'])   : intval(date('j'));

// Clamp values
$sel_year  = max(2020, min(2099, $sel_year));
$sel_month = max(1, min(12, $sel_month));
$sel_day   = max(1, min(31, $sel_day));

// ── Build SQL date range based on view mode ──
if ($view_mode === 'day') {
    $date_from = sprintf('%04d-%02d-%02d', $sel_year, $sel_month, $sel_day);
    $date_to   = $date_from;
    $label     = date('F j, Y', mktime(0,0,0,$sel_month,$sel_day,$sel_year));
} elseif ($view_mode === 'month') {
    $date_from = sprintf('%04d-%02d-01', $sel_year, $sel_month);
    $last_day  = date('t', mktime(0,0,0,$sel_month,1,$sel_year));
    $date_to   = sprintf('%04d-%02d-%02d', $sel_year, $sel_month, $last_day);
    $label     = date('F Y', mktime(0,0,0,$sel_month,1,$sel_year));
} else { // year
    $date_from = sprintf('%04d-01-01', $sel_year);
    $date_to   = sprintf('%04d-12-31', $sel_year);
    $label     = (string)$sel_year;
}

// ── Sensor data for selected period ──
$sql = "SELECT
          DATE(timestamp) as summary_date,
          AVG(temperature) as avg_temp, MIN(temperature) as min_temp, MAX(temperature) as max_temp,
          AVG(humidity) as avg_hum, MIN(humidity) as min_hum, MAX(humidity) as max_hum,
          COUNT(*) as readings,
          SUM(CASE WHEN temperature BETWEEN 22 AND 28 AND humidity BETWEEN 85 AND 95 THEN 1 ELSE 0 END) as ideal_readings
        FROM sensor_data
        WHERE DATE(timestamp) BETWEEN '$date_from' AND '$date_to'
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

// ── Compute changes ──
$temp_changes = []; $hum_changes = []; $change_dates = [];
for ($i = 1; $i < count($data); $i++) {
    $temp_changes[] = $data[$i]['avg_temp'] - $data[$i-1]['avg_temp'];
    $hum_changes[]  = $data[$i]['avg_hum']  - $data[$i-1]['avg_hum'];
    $change_dates[]  = $data[$i]['summary_date'];
}
$avg_tc = count($temp_changes) ? array_sum($temp_changes)/count($temp_changes) : 0;
$avg_hc = count($hum_changes)  ? array_sum($hum_changes)/count($hum_changes)   : 0;

// ── Sensor Health Score ──
$total_readings_all = array_sum(array_column($data, 'readings'));
$ideal_readings_all = array_sum(array_column($data, 'ideal_readings'));
$health_score = $total_readings_all > 0 ? round(($ideal_readings_all / $total_readings_all) * 100, 1) : null;
$health_class = $health_score === null ? 'neu' : ($health_score >= 80 ? 'pos' : ($health_score >= 50 ? 'warn' : 'neg'));
$health_label = $health_score === null ? 'No Data' : ($health_score >= 80 ? 'Healthy' : ($health_score >= 50 ? 'Fair' : 'Poor'));

// ── Calendar heatmap data (for month view): daily avg temps ──
$heatmap_data = [];
foreach ($data as $row) {
    $d = intval(date('j', strtotime($row['summary_date'])));
    $heatmap_data[$d] = [
        'avg_temp' => $row['avg_temp'],
        'avg_hum'  => $row['avg_hum'],
        'readings' => $row['readings'],
        'ideal'    => $row['ideal_readings'],
    ];
}

// ── Month grid for year view: monthly averages ──
$monthly_data = [];
if ($view_mode === 'year') {
    $mSql = "SELECT MONTH(timestamp) as m,
               AVG(temperature) as avg_temp, AVG(humidity) as avg_hum, COUNT(*) as readings,
               SUM(CASE WHEN temperature BETWEEN 22 AND 28 AND humidity BETWEEN 85 AND 95 THEN 1 ELSE 0 END) as ideal_readings
             FROM sensor_data
             WHERE YEAR(timestamp)=$sel_year
             GROUP BY MONTH(timestamp) ORDER BY m ASC";
    $mResult = $conn->query($mSql);
    if ($mResult && $mResult->num_rows > 0)
        while ($r = $mResult->fetch_assoc()) $monthly_data[intval($r['m'])] = $r;
}

function trendWord($v) { return $v > 0 ? 'Increasing' : ($v < 0 ? 'Decreasing' : 'Stable'); }
function trendClass($v){ return $v > 0 ? 'pos' : ($v < 0 ? 'neg' : 'neu'); }
function trendIcon($v) { return $v > 0 ? 'arrow-up' : ($v < 0 ? 'arrow-down' : 'minus'); }

// Helper: nav URLs
function navUrl($view, $year, $month, $day) {
    return "?view=$view&year=$year&month=$month&day=$day";
}
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
      --bg:#f0f2f5; --surface:#ffffff; --surface2:#f7f8fa;
      --border:rgba(0,0,0,0.07); --text:#0d1117; --muted:#6e7681;
      --green:#1a9e5c; --green-lt:#e6f7ef;
      --red:#d93025;   --red-lt:#fdecea;
      --amber:#b45309; --amber-lt:#fef3c7;
      --blue:#1a6bba;  --blue-lt:#e8f1fb;
      --r:12px;
      --shadow:0 1px 3px rgba(0,0,0,0.06),0 4px 16px rgba(0,0,0,0.04);
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

    .main{margin-left:220px;min-height:100vh;width:calc(100% - 220px);box-sizing:border-box;}

    .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:30;}
    .topbar-title{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.2px;}
    .topbar-right{display:flex;align-items:center;gap:10px;}
    .topbar-time{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--surface2);padding:5px 12px;border-radius:20px;border:1px solid var(--border);}

    .page{padding:24px 28px;max-width:1280px;width:100%;box-sizing:border-box;display:flex;flex-direction:column;gap:16px;}

    /* ── CALENDAR NAV ── */
    .cal-nav{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;}
    .cal-nav-top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);}
    .cal-nav-label{font-size:15px;font-weight:700;color:var(--text);}
    .cal-nav-controls{display:flex;align-items:center;gap:6px;}
    .cal-view-tabs{display:flex;gap:3px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:3px;}
    .cal-view-tab{padding:5px 13px;border-radius:6px;font-size:12px;font-weight:600;color:var(--muted);text-decoration:none;transition:all .15s;white-space:nowrap;}
    .cal-view-tab:hover{color:var(--text);}
    .cal-view-tab.active{background:var(--green);color:#fff;}
    .cal-arrow{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);text-decoration:none;font-size:12px;transition:all .15s;}
    .cal-arrow:hover{background:var(--surface2);color:var(--text);}

    /* Year View: 12 month tiles */
    .cal-year-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-top:1px solid var(--border);}
    .cal-month-tile{padding:14px 16px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);cursor:pointer;text-decoration:none;transition:background .15s;display:block;}
    .cal-month-tile:nth-child(4n){border-right:none;}
    .cal-month-tile:nth-last-child(-n+4){border-bottom:none;}
    .cal-month-tile:hover{background:var(--surface2);}
    .cal-month-tile.has-data{background:var(--green-lt);}
    .cal-month-tile.has-data:hover{background:#d2f2e4;}
    .cal-month-tile.active-month{background:var(--green)!important;}
    .cal-month-tile.active-month .cmt-name,.cal-month-tile.active-month .cmt-stat{color:#fff!important;}
    .cmt-name{font-size:12px;font-weight:700;color:var(--text);margin-bottom:4px;}
    .cmt-stat{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;}

    /* Month View: calendar grid */
    .cal-month-grid-wrap{padding:16px 18px 20px;}
    .cal-dow-row{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:6px;}
    .cal-dow{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);text-align:center;padding:2px 0;}
    .cal-days-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
    .cal-day{min-height:64px;border-radius:8px;padding:6px 8px;border:1px solid transparent;transition:all .15s;position:relative;text-decoration:none;display:block;}
    .cal-day.empty{background:transparent;border-color:transparent;pointer-events:none;}
    .cal-day.no-data{background:var(--surface2);border-color:var(--border);}
    .cal-day.has-data{background:var(--green-lt);border-color:rgba(26,158,92,0.2);cursor:pointer;}
    .cal-day.has-data:hover{background:#d2f2e4;border-color:var(--green);}
    .cal-day.today{border-color:var(--green)!important;box-shadow:0 0 0 2px rgba(26,158,92,0.15);}
    .cal-day.selected{background:var(--green)!important;border-color:var(--green)!important;}
    .cal-day.selected .cd-num,.cal-day.selected .cd-temp,.cal-day.selected .cd-hum{color:#fff!important;}
    .cd-num{font-size:11px;font-weight:700;color:var(--muted);margin-bottom:4px;}
    .cd-temp{font-size:12px;font-weight:700;font-family:'DM Mono',monospace;color:var(--green);line-height:1;}
    .cd-hum{font-size:10px;color:var(--muted);font-family:'DM Mono',monospace;margin-top:2px;}

    /* Day view: single day summary */
    .day-view-wrap{padding:20px 24px;}
    .day-stat-row{display:flex;gap:12px;flex-wrap:wrap;}
    .day-stat{flex:1;min-width:120px;background:var(--surface2);border-radius:10px;padding:14px 16px;border:1px solid var(--border);}
    .day-stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:4px;}
    .day-stat-val{font-size:24px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);}
    .day-stat-val span{font-size:13px;font-weight:500;color:var(--muted);}

    /* CARD */
    .card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 14px;border-bottom:1px solid var(--border);}
    .card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
    .card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
    .icon-blue{background:var(--blue-lt);color:var(--blue);}
    .icon-green{background:var(--green-lt);color:var(--green);}
    .card-sub{font-size:12px;color:var(--muted);}
    .card-body{padding:0;}

    .stat-strip{display:flex;gap:0;border-bottom:1px solid var(--border);}
    .stat-item{flex:1;padding:14px 18px;border-right:1px solid var(--border);}
    .stat-item:last-child{border-right:none;}
    .stat-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
    .stat-val{font-size:22px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);}
    .stat-val span{font-size:12px;font-weight:500;color:var(--muted);}

    /* HEALTH */
    .health-wrap{display:flex;align-items:center;gap:24px;padding:20px 24px;}
    .health-circle{position:relative;width:110px;height:110px;flex-shrink:0;}
    .health-circle canvas{position:absolute;inset:0;width:100%!important;height:100%!important;}
    .health-score-val{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
    .health-score-num{font-size:22px;font-weight:700;font-family:'DM Mono',monospace;line-height:1;}
    .health-score-pct{font-size:11px;color:var(--muted);font-weight:500;}
    .health-details{flex:1;display:flex;flex-direction:column;gap:10px;}
    .health-detail-row{display:flex;align-items:center;justify-content:space-between;}
    .health-detail-label{font-size:12px;color:var(--muted);}
    .health-detail-val{font-size:13px;font-weight:700;font-family:'DM Mono',monospace;}
    .health-bar-wrap{width:100%;height:6px;background:var(--surface2);border-radius:99px;margin-top:3px;overflow:hidden;}
    .health-bar{height:6px;border-radius:99px;}

    /* TABLE */
    .tbl-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;font-size:13px;}
    thead th{text-align:left;padding:9px 14px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;background:var(--surface2);border-bottom:1px solid var(--border);white-space:nowrap;}
    tbody td{padding:10px 14px;border-bottom:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:12.5px;}
    tbody tr:last-child td{border-bottom:none;}
    tbody tr:hover{background:var(--surface2);}
    td.date-col{font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:var(--text);}
    td.readings-col{font-weight:700;}

    .badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:700;font-family:'DM Sans',sans-serif;}
    .badge-green{background:var(--green-lt);color:var(--green);}
    .badge-red{background:var(--red-lt);color:var(--red);}
    .badge-amber{background:var(--amber-lt);color:var(--amber);}
    .badge-blue{background:var(--blue-lt);color:var(--blue);}

    .pos{color:var(--green);font-weight:700;}
    .neg{color:var(--red);font-weight:700;}
    .neu{color:var(--muted);font-weight:600;}
    .warn{color:var(--amber);font-weight:700;}

    .chart-wrap{padding:20px;}
    .chart-wrap canvas{max-height:220px;}

    .summary-box{background:var(--surface2);border-top:1px solid var(--border);padding:20px;}
    .summary-box h4{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;}
    .summary-rows{display:flex;gap:24px;flex-wrap:wrap;}
    .summary-row{display:flex;flex-direction:column;gap:2px;}
    .summary-row .s-label{font-size:11px;color:var(--muted);}
    .summary-row .s-val{font-size:16px;font-weight:700;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:5px;}
    .summary-row .s-trend{font-size:11px;font-weight:600;margin-top:2px;}

    .csv-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:7px;border:1px solid var(--border);background:var(--surface);font-size:12px;font-weight:600;color:var(--text);cursor:pointer;text-decoration:none;transition:all .15s;}
    .csv-btn:hover{background:var(--green);color:#fff;border-color:var(--green);}
    .csv-btn i{font-size:11px;}

    .empty-state{text-align:center;padding:40px;color:var(--muted);}
    .empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.35;}
    .empty-state span{font-size:13px;}

    /* Legend */
    .cal-legend{display:flex;align-items:center;gap:16px;padding:10px 18px;border-top:1px solid var(--border);background:var(--surface2);}
    .cal-legend-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);}
    .cal-legend-dot{width:10px;height:10px;border-radius:3px;}
  
    /* ============================================================
       RESPONSIVE / MOBILE
       ============================================================ */

    /* Hamburger button */
    .hamburger{
      display:none;position:fixed;top:4px;left:10px;z-index:600;pointer-events:auto;
      width:38px;height:38px;border-radius:9px;
      background:var(--surface);border:1px solid var(--border);
      box-shadow:var(--shadow);
      align-items:center;justify-content:center;
      cursor:pointer;flex-direction:column;gap:4px;padding:9px;
      touch-action:manipulation;
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
      /* Hamburger visible */
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
      .main{margin-left:0!important;width:100%!important;}

      /* Topbar */
      .topbar{padding:0 10px 0 58px;height:52px;position:fixed!important;top:0;left:0;right:0;z-index:40;}
      .topbar-title{font-size:14px;}
      .topbar-time{font-size:11px;padding:4px 10px;}

      /* Page — push down so hamburger isn't covered by cal-nav */
      .page{padding:8px!important;padding-top:64px!important;gap:8px!important;overflow-x:hidden;}

      /* ── Calendar Nav ── */
      .cal-nav-top{padding:8px 12px;flex-wrap:wrap;gap:6px;}
      .cal-nav-label{font-size:13px;}
      .cal-nav-controls{gap:4px;}
      .cal-view-tab{padding:4px 10px;font-size:11px;}
      .cal-arrow{width:28px;height:28px;font-size:11px;}

      /* Year view: 3 columns on tablet */
      .cal-year-grid{grid-template-columns:repeat(3,1fr);}
      .cal-month-tile{padding:10px 12px;}
      .cmt-name{font-size:11px;}
      .cmt-stat{font-size:10px;}

      /* Month view: days compact */
      .cal-month-grid-wrap{padding:6px 10px 10px;}
      .cal-day{min-height:40px;padding:3px 4px;}
      .cd-num{font-size:10px;}
      .cd-temp{font-size:11px;}
      .cd-hum{font-size:9px;}
      .cal-dow{font-size:9px;}

      /* Day view */
      .day-view-wrap{padding:10px 12px;}
      .day-stat-row{gap:8px;}
      .day-stat{padding:10px 12px;min-width:0;}
      .day-stat-label{font-size:10px;}
      .day-stat-val{font-size:18px;}

      /* Stat strip — stack vertically */
      .stat-strip{flex-direction:column;}
      .stat-item{padding:10px 14px;border-right:none;border-bottom:1px solid var(--border);}
      .stat-item:last-child{border-bottom:none;}
      .stat-val{font-size:18px;}
      .stat-label{font-size:10px;}

      /* Health section */
      .health-wrap{flex-direction:column;align-items:flex-start;padding:10px 12px;gap:10px;}
      .health-circle{width:90px;height:90px;}
      .health-score-num{font-size:18px;}
      .health-details{width:100%;}

      /* Chart */
      .chart-wrap{padding:10px;}
      .chart-wrap canvas{max-height:180px;}

      /* Summary box */
      .summary-box{padding:10px 12px;}
      .summary-rows{gap:14px;}
      .summary-row .s-val{font-size:14px;}

      /* Card headers */
      .card-header{flex-wrap:wrap;gap:6px;padding:10px 12px 8px;}
      .card-title{font-size:12px;}
      .card-sub{font-size:11px;}

      /* CSV button */
      .csv-btn{padding:5px 10px;font-size:11px;}

      /* Tables */
      .tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
      table{min-width:460px;font-size:12px;}
      thead th{padding:8px 10px;font-size:10px;}
      tbody td{padding:8px 10px;font-size:11px;}

      /* Legend */
      .cal-legend{flex-wrap:wrap;gap:8px;padding:8px 12px;}
      .cal-legend-item{font-size:10px;}
    }

    @media(max-width:480px){
      .topbar{height:48px;position:fixed!important;top:0;left:0;right:0;}
      .topbar-title{font-size:13px;}
      .topbar-time{display:none;}

      .page{padding:6px!important;padding-top:58px!important;gap:6px!important;overflow-x:hidden;}

      /* Year view: 2 columns on small phone */
      .cal-year-grid{grid-template-columns:repeat(2,1fr);}
      .cal-month-tile{padding:6px 8px;}

      /* Month view: ultra compact */
      .cal-day{min-height:38px;padding:3px 4px;}
      .cd-temp{font-size:10px;}
      .cd-hum{display:none;}

      /* Day stats: 2 col grid */
      .day-stat-row{display:grid;grid-template-columns:1fr 1fr;}
      .day-stat-val{font-size:16px;}

      /* Health */
      .health-circle{width:80px;height:80px;}
      .health-score-num{font-size:16px;}

      /* Chart smaller */
      .chart-wrap canvas{max-height:150px;}

      /* Summary rows: 2 col grid */
      .summary-rows{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    }

</style>
</head>
<body>
<button class="hamburger" id="hamburger" aria-label="Menu">
  <span></span><span></span><span></span>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>


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
    <a href="dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
    <a href="reports.php" class="active"><i class="fas fa-chart-line"></i> Reports</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<main class="main">
  <header class="topbar">
    <span class="topbar-title">Reports</span>
    <div class="topbar-right">
      <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
    </div>
  </header>

  <div class="page">

    <?php
    // ── Prev / Next navigation ──
    if ($view_mode === 'year') {
        $prev_url = navUrl('year', $sel_year-1, $sel_month, $sel_day);
        $next_url = navUrl('year', $sel_year+1, $sel_month, $sel_day);
    } elseif ($view_mode === 'month') {
        $pm = $sel_month - 1; $py = $sel_year;
        if ($pm < 1) { $pm = 12; $py--; }
        $nm = $sel_month + 1; $ny = $sel_year;
        if ($nm > 12) { $nm = 1; $ny++; }
        $prev_url = navUrl('month', $py, $pm, $sel_day);
        $next_url = navUrl('month', $ny, $nm, $sel_day);
    } else { // day
        $prev_ts  = mktime(0,0,0,$sel_month,$sel_day-1,$sel_year);
        $next_ts  = mktime(0,0,0,$sel_month,$sel_day+1,$sel_year);
        $prev_url = navUrl('day', date('Y',$prev_ts), date('n',$prev_ts), date('j',$prev_ts));
        $next_url = navUrl('day', date('Y',$next_ts), date('n',$next_ts), date('j',$next_ts));
    }
    ?>

    <!-- ── CALENDAR NAVIGATOR ── -->
    <div class="cal-nav">
      <div class="cal-nav-top">
        <span class="cal-nav-label">
          <i class="fas fa-calendar-days" style="color:var(--green);margin-right:7px;"></i>
          <?= htmlspecialchars($label) ?>
        </span>
        <div class="cal-nav-controls">
          <a href="<?= $prev_url ?>" class="cal-arrow"><i class="fas fa-chevron-left"></i></a>
          <a href="<?= $next_url ?>" class="cal-arrow"><i class="fas fa-chevron-right"></i></a>
          <div class="cal-view-tabs" style="margin-left:6px;">
            <a href="<?= navUrl('day',  $sel_year, $sel_month, $sel_day) ?>" class="cal-view-tab <?= $view_mode==='day'   ? 'active' : '' ?>">Day</a>
            <a href="<?= navUrl('month',$sel_year, $sel_month, $sel_day) ?>" class="cal-view-tab <?= $view_mode==='month' ? 'active' : '' ?>">Month</a>
            <a href="<?= navUrl('year', $sel_year, $sel_month, $sel_day) ?>" class="cal-view-tab <?= $view_mode==='year'  ? 'active' : '' ?>">Year</a>
          </div>
        </div>
      </div>

      <?php if ($view_mode === 'year'): ?>
      <!-- YEAR VIEW: 12 month tiles -->
      <div class="cal-year-grid">
        <?php
        $month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        for ($m = 1; $m <= 12; $m++):
          $has = isset($monthly_data[$m]);
          $is_active = ($m === $sel_month);
          $tile_url = navUrl('month', $sel_year, $m, $sel_day);
          $tile_class = 'cal-month-tile' . ($has ? ' has-data' : '') . ($is_active ? ' active-month' : '');
        ?>
        <a href="<?= $tile_url ?>" class="<?= $tile_class ?>">
          <div class="cmt-name"><?= $month_names[$m-1] ?></div>
          <?php if ($has): ?>
            <div class="cmt-stat"><?= number_format($monthly_data[$m]['avg_temp'],1) ?>°C · <?= number_format($monthly_data[$m]['avg_hum'],1) ?>%</div>
          <?php else: ?>
            <div class="cmt-stat" style="opacity:.4;">No data</div>
          <?php endif; ?>
        </a>
        <?php endfor; ?>
      </div>

      <?php elseif ($view_mode === 'month'): ?>
      <!-- MONTH VIEW: day calendar grid -->
      <?php
        $first_dow = date('w', mktime(0,0,0,$sel_month,1,$sel_year)); // 0=Sun
        $days_in_month = date('t', mktime(0,0,0,$sel_month,1,$sel_year));
        $today_d = (intval(date('Y')) === $sel_year && intval(date('n')) === $sel_month) ? intval(date('j')) : -1;
      ?>
      <div class="cal-month-grid-wrap">
        <div class="cal-dow-row">
          <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
          <div class="cal-dow"><?= $d ?></div>
          <?php endforeach; ?>
        </div>
        <div class="cal-days-grid">
          <?php
          // Empty cells before first day
          for ($e = 0; $e < $first_dow; $e++) echo '<div class="cal-day empty"></div>';
          // Day cells
          for ($d = 1; $d <= $days_in_month; $d++):
            $has  = isset($heatmap_data[$d]);
            $is_today = ($d === $today_d);
            $is_sel   = ($d === $sel_day && $view_mode === 'month');
            $dc = 'cal-day' . ($has ? ' has-data' : ' no-data') . ($is_today ? ' today' : '') . ($is_sel ? ' selected' : '');
            $day_url = navUrl('day', $sel_year, $sel_month, $d);
          ?>
          <a href="<?= $day_url ?>" class="<?= $dc ?>">
            <div class="cd-num"><?= $d ?></div>
            <?php if ($has): ?>
              <div class="cd-temp"><?= number_format($heatmap_data[$d]['avg_temp'],1) ?>°</div>
              <div class="cd-hum"><?= number_format($heatmap_data[$d]['avg_hum'],1) ?>%</div>
            <?php endif; ?>
          </a>
          <?php endfor; ?>
        </div>
      </div>
      <div class="cal-legend">
        <div class="cal-legend-item"><div class="cal-legend-dot" style="background:var(--green-lt);border:1px solid rgba(26,158,92,.3);"></div> Has data</div>
        <div class="cal-legend-item"><div class="cal-legend-dot" style="background:var(--surface2);border:1px solid var(--border);"></div> No data</div>
        <div class="cal-legend-item"><div class="cal-legend-dot" style="background:var(--green);"></div> Selected</div>
        <span style="font-size:11px;color:var(--muted);margin-left:auto;">Click a day for detailed view</span>
      </div>

      <?php else: ?>
      <!-- DAY VIEW: single day highlight -->
      <?php
        $day_row = null;
        foreach ($data as $row) {
            if (intval(date('j',strtotime($row['summary_date']))) === $sel_day) { $day_row = $row; break; }
        }
      ?>
      <div class="day-view-wrap">
        <?php if ($day_row): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <span style="font-size:13px;font-weight:600;color:var(--text);"><?= date('l, F j, Y', mktime(0,0,0,$sel_month,$sel_day,$sel_year)) ?></span>
          <span class="badge badge-green"><?= number_format($day_row['readings']) ?> readings</span>
        </div>
        <div class="day-stat-row">
          <div class="day-stat">
            <div class="day-stat-label">Avg Temp</div>
            <div class="day-stat-val"><?= number_format($day_row['avg_temp'],1) ?><span>°C</span></div>
          </div>
          <div class="day-stat">
            <div class="day-stat-label">Min / Max Temp</div>
            <div class="day-stat-val"><?= number_format($day_row['min_temp'],1) ?><span>° – </span><?= number_format($day_row['max_temp'],1) ?><span>°C</span></div>
          </div>
          <div class="day-stat">
            <div class="day-stat-label">Avg Humidity</div>
            <div class="day-stat-val"><?= number_format($day_row['avg_hum'],1) ?><span>%</span></div>
          </div>
          <div class="day-stat">
            <div class="day-stat-label">Min / Max Hum</div>
            <div class="day-stat-val"><?= number_format($day_row['min_hum'],1) ?><span>% – </span><?= number_format($day_row['max_hum'],1) ?><span>%</span></div>
          </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-calendar-xmark"></i><span>No sensor data for <?= date('F j, Y', mktime(0,0,0,$sel_month,$sel_day,$sel_year)) ?>.</span></div>
        <?php endif; ?>
      </div>
      <div class="cal-legend">
        <a href="<?= navUrl('month',$sel_year,$sel_month,$sel_day) ?>" style="font-size:11px;color:var(--green);text-decoration:none;font-weight:600;"><i class="fas fa-arrow-left" style="font-size:10px;margin-right:4px;"></i> Back to <?= date('F Y',mktime(0,0,0,$sel_month,1,$sel_year)) ?></a>
      </div>
      <?php endif; ?>
    </div>

    <?php
    $all_temps = array_column($data,'avg_temp');
    $all_hums  = array_column($data,'avg_hum');
    $all_reads = array_column($data,'readings');
    $overall_avg_t = count($all_temps) ? array_sum($all_temps)/count($all_temps) : null;
    $overall_avg_h = count($all_hums)  ? array_sum($all_hums)/count($all_hums)   : null;
    $total_reads   = array_sum($all_reads);
    $overall_min_t = count($data) ? min(array_column($data,'min_temp')) : null;
    $overall_max_t = count($data) ? max(array_column($data,'max_temp')) : null;
    ?>

    <!-- ── SENSOR HEALTH SCORE ── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span class="icon icon-green"><i class="fas fa-heart-pulse"></i></span>
          Chamber Health Score
        </div>
        <span class="card-sub">% of readings within ideal ranges (22–28°C · 85–95% RH) — <?= htmlspecialchars($label) ?></span>
      </div>
      <?php if ($health_score === null): ?>
        <div class="empty-state"><i class="fas fa-database"></i><span>No sensor data for this period.</span></div>
      <?php else:
        $scoreColor = $health_score >= 80 ? '#1a9e5c' : ($health_score >= 50 ? '#b45309' : '#d93025');
        $scoreBg    = $health_score >= 80 ? '#e6f7ef' : ($health_score >= 50 ? '#fef3c7' : '#fdecea');
        $temp_ideal = 0; $hum_ideal = 0;
        foreach ($data as $row) {
            if ($row['avg_temp'] >= 22 && $row['avg_temp'] <= 28) $temp_ideal++;
            if ($row['avg_hum']  >= 85 && $row['avg_hum']  <= 95) $hum_ideal++;
        }
        $n = count($data);
        $temp_pct = $n ? round($temp_ideal/$n*100) : 0;
        $hum_pct  = $n ? round($hum_ideal/$n*100)  : 0;
      ?>
      <div class="health-wrap">
        <div class="health-circle">
          <canvas id="healthDonut"></canvas>
          <div class="health-score-val">
            <span class="health-score-num" style="color:<?= $scoreColor ?>"><?= $health_score ?></span>
            <span class="health-score-pct">/ 100</span>
          </div>
        </div>
        <div class="health-details">
          <div>
            <div class="health-detail-row">
              <span class="health-detail-label">Temperature in range</span>
              <span class="health-detail-val"><?= $temp_pct ?>%</span>
            </div>
            <div class="health-bar-wrap"><div class="health-bar" style="width:<?= $temp_pct ?>%;background:#fb7185;"></div></div>
          </div>
          <div>
            <div class="health-detail-row">
              <span class="health-detail-label">Humidity in range</span>
              <span class="health-detail-val"><?= $hum_pct ?>%</span>
            </div>
            <div class="health-bar-wrap"><div class="health-bar" style="width:<?= $hum_pct ?>%;background:#34d399;"></div></div>
          </div>
          <div>
            <div class="health-detail-row">
              <span class="health-detail-label">Overall ideal readings</span>
              <span class="health-detail-val"><?= number_format($ideal_readings_all) ?> / <?= number_format($total_readings_all) ?></span>
            </div>
            <div class="health-bar-wrap"><div class="health-bar" style="width:<?= $health_score ?>%;background:<?= $scoreColor ?>;"></div></div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
            <span style="background:<?= $scoreBg ?>;color:<?= $scoreColor ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;"><?= $health_label ?></span>
            <span style="font-size:11px;color:var(--muted);">Based on <?= htmlspecialchars($label) ?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── SENSOR DATA REPORT ── -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          <span class="icon icon-blue"><i class="fas fa-table"></i></span>
          Sensor Data Report
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="card-sub"><?= htmlspecialchars($label) ?> · <?= count($data) ?> day<?= count($data)!=1?'s':'' ?> of data</span>
          <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'sensor_csv'])) ?>" class="csv-btn">
            <i class="fas fa-download"></i> Export CSV
          </a>
        </div>
      </div>

      <?php if (count($data)): ?>
      <div class="stat-strip">
        <div class="stat-item">
          <div class="stat-label">Avg Temp</div>
          <div class="stat-val"><?= number_format($overall_avg_t,1) ?><span> °C</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Temp Range</div>
          <div class="stat-val"><?= number_format($overall_min_t,1) ?>–<?= number_format($overall_max_t,1) ?><span> °C</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Avg Humidity</div>
          <div class="stat-val"><?= number_format($overall_avg_h,1) ?><span> %</span></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Total Readings</div>
          <div class="stat-val"><?= number_format($total_reads) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card-body">
        <?php if (empty($data)): ?>
          <div class="empty-state"><i class="fas fa-database"></i><span>No sensor data for this period.</span></div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table id="sensorTable">
            <thead>
              <tr>
                <th>Date</th><th>Avg Temp</th><th>Min Temp</th><th>Max Temp</th>
                <th>Avg Hum</th><th>Min Hum</th><th>Max Hum</th><th>Readings</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                $tc = ($row['avg_temp']>=22&&$row['avg_temp']<=28)?'badge-green':(($row['avg_temp']<22)?'badge-blue':'badge-amber');
                $hc = ($row['avg_hum'] >=85&&$row['avg_hum'] <=95)?'badge-green':(($row['avg_hum'] <85)?'badge-blue':'badge-red');
              ?>
              <tr>
                <td class="date-col"><?= date('M j, Y',strtotime($row['summary_date'])) ?></td>
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

    <!-- ── SENSOR DATA CHANGES ── -->
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
        <div class="chart-wrap"><canvas id="changesChart"></canvas></div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Date</th><th>Temp Change (°C)</th><th>Humidity Change (%)</th></tr></thead>
            <tbody>
              <?php for ($i=1;$i<count($data);$i++):
                $row_tc=$data[$i]['avg_temp']-$data[$i-1]['avg_temp'];
                $row_hc=$data[$i]['avg_hum'] -$data[$i-1]['avg_hum'];
              ?>
              <tr>
                <td class="date-col"><?= date('M j, Y',strtotime($data[$i]['summary_date'])) ?></td>
                <td class="<?= trendClass($row_tc) ?>"><i class="fas fa-<?= trendIcon($row_tc) ?>"></i> <?= ($row_tc>=0?'+':'').number_format($row_tc,2) ?></td>
                <td class="<?= trendClass($row_hc) ?>"><i class="fas fa-<?= trendIcon($row_hc) ?>"></i> <?= ($row_hc>=0?'+':'').number_format($row_hc,2) ?></td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="summary-box">
          <h4>Summary of Changes</h4>
          <div class="summary-rows">
            <div class="summary-row">
              <span class="s-label">Average Temperature Change</span>
              <span class="s-val" style="color:<?= $avg_tc>0?'var(--green)':($avg_tc<0?'var(--red)':'var(--muted)') ?>">
                <i class="fas fa-<?= trendIcon($avg_tc) ?>" style="font-size:13px;"></i>
                <?= ($avg_tc>=0?'+':'').number_format($avg_tc,2) ?>°C
              </span>
              <span class="s-trend" style="color:<?= $avg_tc>0?'var(--green)':($avg_tc<0?'var(--red)':'var(--muted)') ?>"><?= trendWord($avg_tc) ?></span>
            </div>
            <div class="summary-row">
              <span class="s-label">Average Humidity Change</span>
              <span class="s-val" style="color:<?= $avg_hc>0?'var(--green)':($avg_hc<0?'var(--red)':'var(--muted)') ?>">
                <i class="fas fa-<?= trendIcon($avg_hc) ?>" style="font-size:13px;"></i>
                <?= ($avg_hc>=0?'+':'').number_format($avg_hc,2) ?>%
              </span>
              <span class="s-trend" style="color:<?= $avg_hc>0?'var(--green)':($avg_hc<0?'var(--red)':'var(--muted)') ?>"><?= trendWord($avg_hc) ?></span>
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

<?php
// ── CSV EXPORT ──
if (isset($_GET['export']) && $_GET['export'] === 'sensor_csv' && !empty($data)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sensor_report_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Date','Avg Temp (°C)','Min Temp (°C)','Max Temp (°C)','Avg Humidity (%)','Min Humidity (%)','Max Humidity (%)','Readings']);
    foreach ($data as $row) fputcsv($out,[date('M j, Y',strtotime($row['summary_date'])),number_format($row['avg_temp'],2),number_format($row['min_temp'],2),number_format($row['max_temp'],2),number_format($row['avg_hum'],2),number_format($row['min_hum'],2),number_format($row['max_hum'],2),$row['readings']]);
    fclose($out); exit;
}
?>

<script>
// PH Time
(function(){
  const el = document.getElementById('phTime'); if(!el)return;
  let t = parseInt(el.dataset.serverTs,10)||Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).replace(',','—');
  el.textContent=fmt(t); setInterval(()=>{t+=1000;el.textContent=fmt(t);},1000);
})();

// Health Donut
(function(){
  const c=document.getElementById('healthDonut'); if(!c)return;
  const score=<?= $health_score ?? 0 ?>;
  const color=score>=80?'#1a9e5c':score>=50?'#b45309':'#d93025';
  new Chart(c.getContext('2d'),{
    type:'doughnut',
    data:{datasets:[{data:[score,100-score],backgroundColor:[color,'#f0f2f5'],borderWidth:0}]},
    options:{cutout:'72%',rotation:-90,circumference:180,responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}}}
  });
})();

// Changes Chart
(function(){
  const canvas=document.getElementById('changesChart'); if(!canvas)return;
  const tempChanges=<?= json_encode($temp_changes) ?>;
  const humChanges=<?= json_encode($hum_changes) ?>;
  const dates=<?= json_encode($change_dates) ?>;
  if(!dates.length)return;
  const fmtDate=s=>{const d=new Date(s+'T00:00:00');return d.toLocaleDateString('en-PH',{month:'short',day:'numeric'});};
  new Chart(canvas.getContext('2d'),{
    type:'bar',
    data:{labels:dates.map(fmtDate),datasets:[
      {label:'Temp Change (°C)',data:tempChanges,backgroundColor:tempChanges.map(v=>v>=0?'rgba(26,158,92,0.7)':'rgba(217,48,37,0.7)'),borderRadius:5,borderSkipped:false,yAxisID:'y'},
      {label:'Humidity Change (%)',data:humChanges,type:'line',fill:false,borderColor:'rgba(26,107,186,0.8)',backgroundColor:humChanges.map(v=>v>=0?'rgba(26,107,186,0.6)':'rgba(180,83,9,0.6)'),pointBackgroundColor:humChanges.map(v=>v>=0?'#1a6bba':'#b45309'),pointRadius:4,tension:0.3,yAxisID:'y1'}
    ]},
    options:{responsive:true,maintainAspectRatio:true,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{font:{family:'DM Sans',size:12},color:'#6e7681',boxWidth:12,boxHeight:12}},tooltip:{backgroundColor:'#fff',borderColor:'rgba(0,0,0,0.08)',borderWidth:1,titleColor:'#0d1117',bodyColor:'#6e7681',padding:12,cornerRadius:8,callbacks:{label:ctx=>{const v=ctx.parsed.y;return` ${ctx.dataset.label}: ${v>=0?'+':''}${v.toFixed(2)}`;}}}}},scales:{x:{grid:{display:false},ticks:{font:{family:'DM Sans',size:11},color:'#6e7681'}},y:{position:'left',grid:{color:'rgba(0,0,0,0.05)'},ticks:{font:{family:'DM Mono',size:11},color:'#6e7681',callback:v=>(v>=0?'+':'')+v.toFixed(1)+'°'}},y1:{position:'right',grid:{drawOnChartArea:false},ticks:{font:{family:'DM Mono',size:11},color:'#6e7681',callback:v=>(v>=0?'+':'')+v.toFixed(1)+'%'}}}}
  });
})();

// ── Mobile sidebar toggle ──
(function(){
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.querySelector('.sidebar');
  const overlay   = document.getElementById('sidebarOverlay');
  if(!hamburger || !sidebar || !overlay) return;

  function openSidebar(){
    sidebar.classList.add('open');
    overlay.classList.add('open');
    hamburger.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar(){
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    hamburger.classList.remove('open');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', function(e){
    e.stopPropagation();
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  overlay.addEventListener('click', closeSidebar);

  sidebar.querySelectorAll('.sidebar-nav a').forEach(function(a){
    a.addEventListener('click', function(){ if(window.innerWidth<=768) closeSidebar(); });
  });
})();

</script>
</body>
</html>