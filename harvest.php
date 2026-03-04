<?php
include('includes/auth_check.php');
include('includes/db_connect.php');
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Manila');
$server_ts_ms = round(microtime(true) * 1000);
$server_time_formatted = date('M j, Y — h:i:s A');

// ── Create tables ──
$conn->query("CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(100) NOT NULL,
    species VARCHAR(100) NOT NULL,
    substrate VARCHAR(100),
    start_date DATE NOT NULL,
    stage ENUM('Spawn Run','Pinning','Fruiting','Harvest','Completed') NOT NULL DEFAULT 'Spawn Run',
    notes TEXT,
    status ENUM('active','completed','failed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS harvest_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    harvest_date DATE NOT NULL,
    weight_grams FLOAT NOT NULL DEFAULT 0,
    mushroom_count INT DEFAULT 0,
    quality ENUM('Excellent','Good','Fair','Poor') DEFAULT 'Good',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
)");

$errors = []; $success = '';
$isOwner = isset($_SESSION['role']) && $_SESSION['role'] === 'owner';

// ── Add Batch ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_batch'])) {
    $name      = trim($_POST['batch_name'] ?? '');
    $species   = trim($_POST['species'] ?? '');
    $substrate = trim($_POST['substrate'] ?? '');
    $start     = trim($_POST['start_date'] ?? '');
    $stage     = $_POST['stage'] ?? 'Spawn Run';
    $notes     = trim($_POST['notes'] ?? '');
    if (!$name)  $errors[] = 'Batch name required.';
    if (!$species) $errors[] = 'Species required.';
    if (!$start)  $errors[] = 'Start date required.';
    if (empty($errors)) {
        $s = $conn->prepare("INSERT INTO batches (batch_name,species,substrate,start_date,stage,notes) VALUES (?,?,?,?,?,?)");
        if ($s) { $s->bind_param("ssssss",$name,$species,$substrate,$start,$stage,$notes); if ($s->execute()) $success='Batch added successfully.'; else $errors[]='DB error.'; $s->close(); }
    }
}

// ── Update Batch Stage ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stage'])) {
    $bid   = intval($_POST['batch_id'] ?? 0);
    $stage = $_POST['new_stage'] ?? '';
    $status = ($stage === 'Completed') ? 'completed' : 'active';
    $s = $conn->prepare("UPDATE batches SET stage=?, status=? WHERE id=?");
    if ($s) { $s->bind_param("ssi",$stage,$status,$bid); if ($s->execute()) $success='Stage updated.'; $s->close(); }
}

// ── Delete Batch ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch'])) {
    $bid = intval($_POST['batch_id'] ?? 0);
    $s = $conn->prepare("DELETE FROM batches WHERE id=?");
    if ($s) { $s->bind_param("i",$bid); if ($s->execute()) $success='Batch deleted.'; $s->close(); }
}

// ── Add Harvest Log ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_harvest'])) {
    $bid     = intval($_POST['batch_id'] ?? 0);
    $date    = trim($_POST['harvest_date'] ?? '');
    $weight  = floatval($_POST['weight_grams'] ?? 0);
    $count   = intval($_POST['mushroom_count'] ?? 0);
    $quality = $_POST['quality'] ?? 'Good';
    $notes   = trim($_POST['notes'] ?? '');
    if (!$bid)  $errors[] = 'Batch required.';
    if (!$date) $errors[] = 'Date required.';
    if ($weight <= 0) $errors[] = 'Weight must be > 0.';
    if (empty($errors)) {
        $s = $conn->prepare("INSERT INTO harvest_logs (batch_id,harvest_date,weight_grams,mushroom_count,quality,notes) VALUES (?,?,?,?,?,?)");
        if ($s) { $s->bind_param("isdiis",$bid,$date,$weight,$count,$quality,$notes); if ($s->execute()) { $success='Harvest logged.'; $conn->query("UPDATE batches SET stage='Harvest' WHERE id=$bid AND stage='Fruiting'"); } else $errors[]='DB error.'; $s->close(); }
    }
}

// ── Fetch data ──
$batches = [];
$r = $conn->query("SELECT * FROM batches ORDER BY FIELD(status,'active','completed','failed'), start_date DESC");
if ($r) while ($row = $r->fetch_assoc()) $batches[] = $row;

$harvests = [];
$r = $conn->query("SELECT h.*, b.batch_name, b.species FROM harvest_logs h JOIN batches b ON h.batch_id=b.id ORDER BY h.harvest_date DESC LIMIT 50");
if ($r) while ($row = $r->fetch_assoc()) $harvests[] = $row;

// Stats
$total_weight = 0; $total_count = 0; $active_batches = 0;
foreach ($batches as $b) { if ($b['status']==='active') $active_batches++; }
foreach ($harvests as $h) { $total_weight += $h['weight_grams']; $total_count += $h['mushroom_count']; }

// Batch yield summary for chart
$batch_yields = [];
$r = $conn->query("SELECT b.batch_name, SUM(h.weight_grams) as total_weight FROM harvest_logs h JOIN batches b ON h.batch_id=b.id GROUP BY h.batch_id ORDER BY total_weight DESC LIMIT 8");
if ($r) while ($row = $r->fetch_assoc()) $batch_yields[] = $row;

$stages = ['Spawn Run','Pinning','Fruiting','Harvest','Completed'];
$stage_colors = ['Spawn Run'=>'var(--blue)','Pinning'=>'var(--amber)','Fruiting'=>'var(--green)','Harvest'=>'#7c3aed','Completed'=>'var(--muted)'];
$stage_bg = ['Spawn Run'=>'var(--blue-lt)','Pinning'=>'var(--amber-lt)','Fruiting'=>'var(--green-lt)','Harvest'=>'#ede9fe','Completed'=>'var(--surface2)'];

$active_batches_list = array_filter($batches, fn($b) => $b['status'] === 'active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harvest & Batches</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg:#f0f2f5; --surface:#fff; --surface2:#f7f8fa; --border:rgba(0,0,0,0.07);
  --text:#0d1117; --muted:#6e7681;
  --green:#1a9e5c; --green-lt:#e6f7ef;
  --red:#d93025;   --red-lt:#fdecea;
  --amber:#b45309; --amber-lt:#fef3c7;
  --blue:#1a6bba;  --blue-lt:#e8f1fb;
  --purple:#7c3aed;--purple-lt:#ede9fe;
  --r:12px;
  --shadow:0 1px 3px rgba(0,0,0,0.06),0 4px 16px rgba(0,0,0,0.04);
  --shadow-lg:0 2px 8px rgba(0,0,0,0.08),0 12px 40px rgba(0,0,0,0.06);
}
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
.page{padding:24px 28px;max-width:1280px;}

.flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.flash-ok{background:var(--green-lt);color:var(--green);}
.flash-err{background:var(--red-lt);color:var(--red);}

/* Stats strip */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 20px;box-shadow:var(--shadow);}
.stat-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-val{font-size:26px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);}
.stat-val span{font-size:13px;font-weight:500;color:var(--muted);}
.stat-icon{float:right;width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;margin-top:-4px;}

.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}

.card{background:var(--surface);border-radius:var(--r);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 14px;border-bottom:1px solid var(--border);}
.card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-title .icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.icon-green{background:var(--green-lt);color:var(--green);}
.icon-blue{background:var(--blue-lt);color:var(--blue);}
.icon-amber{background:var(--amber-lt);color:var(--amber);}
.icon-purple{background:var(--purple-lt);color:var(--purple);}
.card-body{padding:20px;}
.card-sub{font-size:11px;color:var(--muted);}

/* Batch cards */
.batch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:16px 20px;}
.batch-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;position:relative;transition:box-shadow .15s;}
.batch-card:hover{box-shadow:var(--shadow-lg);}
.batch-card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;}
.batch-name{font-size:14px;font-weight:700;color:var(--text);}
.batch-species{font-size:12px;color:var(--muted);margin-top:1px;}
.stage-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.batch-meta{display:flex;gap:14px;margin-top:10px;flex-wrap:wrap;}
.batch-meta-item{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px;}
.batch-meta-item strong{color:var(--text);font-weight:600;}
.batch-actions{display:flex;gap:6px;margin-top:12px;}
.stage-progress{display:flex;gap:4px;margin-top:10px;}
.stage-dot{flex:1;height:4px;border-radius:4px;background:var(--border);}
.stage-dot.done{background:var(--green);}
.stage-dot.active{background:var(--amber);}

/* Timeline */
.timeline{display:flex;flex-direction:column;gap:0;}
.tl-item{display:flex;gap:14px;padding:12px 20px;border-bottom:1px solid var(--border);align-items:flex-start;}
.tl-item:last-child{border-bottom:none;}
.tl-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.tl-body{flex:1;}
.tl-title{font-size:13px;font-weight:600;color:var(--text);}
.tl-sub{font-size:11px;color:var(--muted);margin-top:2px;font-family:'DM Mono',monospace;}
.tl-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;margin-left:8px;}
.tl-weight{font-size:15px;font-weight:700;font-family:'DM Mono',monospace;color:var(--text);margin-left:auto;flex-shrink:0;}

/* Quality pills */
.q-excellent{background:var(--green-lt);color:var(--green);}
.q-good{background:var(--blue-lt);color:var(--blue);}
.q-fair{background:var(--amber-lt);color:var(--amber);}
.q-poor{background:var(--red-lt);color:var(--red);}

/* Table */
table.tbl{width:100%;border-collapse:collapse;font-size:13px;}
.tbl thead th{text-align:left;padding:9px 14px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;background:var(--surface2);border-bottom:1px solid var(--border);white-space:nowrap;}
.tbl tbody td{padding:10px 14px;border-bottom:1px solid var(--border);}
.tbl tbody tr:last-child td{border-bottom:none;}
.tbl tbody tr:hover{background:var(--surface2);}
.mono{font-family:'DM Mono',monospace;font-size:12px;}

/* Forms */
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.form-group label{font-size:12px;font-weight:600;color:var(--muted);}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color .15s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--green);background:var(--surface);}
.form-group textarea{resize:vertical;min-height:70px;}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;font-family:'DM Sans',sans-serif;}
.btn-primary{background:var(--green);color:#fff;}
.btn-primary:hover{opacity:.88;}
.btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--border);}
.btn-danger{background:var(--red-lt);color:var(--red);border:1px solid rgba(217,48,37,.15);}
.btn-danger:hover{background:var(--red);color:#fff;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn-purple{background:var(--purple-lt);color:var(--purple);border:1px solid rgba(124,58,237,.15);}
.btn-purple:hover{background:var(--purple);color:#fff;}

.empty-state{text-align:center;padding:36px 20px;color:var(--muted);}
.empty-state i{font-size:28px;display:block;margin-bottom:8px;opacity:.35;}
.empty-state span{font-size:13px;}

/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200;}
.modal-backdrop.open{display:flex;}
.modal{background:var(--surface);border-radius:var(--r);padding:24px;width:520px;max-width:94vw;box-shadow:var(--shadow-lg);position:relative;max-height:90vh;overflow-y:auto;}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:18px;color:var(--muted);cursor:pointer;}
.modal-close:hover{color:var(--text);}
.modal h3{font-size:15px;font-weight:700;margin-bottom:18px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}

@media(max-width:900px){.grid-2,.grid-3,.stats-row{grid-template-columns:1fr;}.form-grid-2,.form-grid-3{grid-template-columns:1fr;}}
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
    <a href="harvest.php" class="active"><i class="fas fa-seedling"></i> Harvest & Batches</a>
    <a href="automation.php"><i class="fas fa-robot"></i> Automation</a>
    <a href="logs.php"><i class="fas fa-list-check"></i> Logs</a>
    <a href="settings.php"><i class="fas fa-gear"></i> Settings</a>
    <a href="profile.php"><i class="fas fa-sliders"></i> System Profile</a>
    <div class="nav-bottom"><a href="logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></div>
  </nav>
</aside>

<main class="main">
  <header class="topbar">
    <span class="topbar-title">Harvest & Batches</span>
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="btn btn-primary" id="openAddBatch"><i class="fas fa-plus"></i> New Batch</button>
      <button class="btn btn-ghost" id="openLogHarvest"><i class="fas fa-scale-balanced"></i> Log Harvest</button>
      <span class="topbar-time" id="phTime" data-server-ts="<?= $server_ts_ms ?>"><?= htmlspecialchars($server_time_formatted) ?></span>
    </div>
  </header>

  <div class="page">

    <?php if ($success): ?><div class="flash flash-ok"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="flash flash-err"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon icon-green" style="background:var(--green-lt);color:var(--green);"><i class="fas fa-layer-group"></i></div>
        <div class="stat-label">Active Batches</div>
        <div class="stat-val"><?= $active_batches ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--purple-lt);color:var(--purple);"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-label">Total Yield</div>
        <div class="stat-val"><?= number_format($total_weight/1000,2) ?><span> kg</span></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--amber-lt);color:var(--amber);"><i class="fas fa-mushroom"></i></div>
        <div class="stat-label">Total Mushrooms</div>
        <div class="stat-val"><?= number_format($total_count) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue);"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-label">Harvest Records</div>
        <div class="stat-val"><?= count($harvests) ?></div>
      </div>
    </div>

    <!-- Batch Cards + Yield Chart -->
    <div class="grid-2" style="margin-bottom:16px;align-items:start;">

      <!-- Active Batches -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-green"><i class="fas fa-layer-group"></i></span> Active Batches</div>
          <span class="card-sub"><?= count(array_filter($batches,fn($b)=>$b['status']==='active')) ?> running</span>
        </div>
        <?php
        $active = array_filter($batches, fn($b) => $b['status']==='active');
        if (empty($active)):
        ?>
          <div class="empty-state"><i class="fas fa-seedling"></i><span>No active batches. Create one to get started.</span></div>
        <?php else: ?>
        <div class="batch-grid">
          <?php foreach ($active as $b):
            $sc = $stage_colors[$b['stage']] ?? 'var(--muted)';
            $sb = $stage_bg[$b['stage']] ?? 'var(--surface2)';
            $stage_idx = array_search($b['stage'], $stages);
            $days_running = (new DateTime($b['start_date']))->diff(new DateTime())->days;
          ?>
          <div class="batch-card">
            <div class="batch-card-top">
              <div>
                <div class="batch-name"><?= htmlspecialchars($b['batch_name']) ?></div>
                <div class="batch-species"><?= htmlspecialchars($b['species']) ?></div>
              </div>
              <span class="stage-pill" style="background:<?= $sb ?>;color:<?= $sc ?>;"><?= htmlspecialchars($b['stage']) ?></span>
            </div>
            <div class="stage-progress">
              <?php foreach ($stages as $i => $st): $done = $i < $stage_idx; $act = $i === $stage_idx; ?>
              <div class="stage-dot <?= $done?'done':($act?'active':'') ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="batch-meta">
              <div class="batch-meta-item"><i class="fas fa-calendar"></i> <strong><?= date('M j, Y', strtotime($b['start_date'])) ?></strong></div>
              <div class="batch-meta-item"><i class="fas fa-clock"></i> <strong><?= $days_running ?></strong> days</div>
              <?php if ($b['substrate']): ?><div class="batch-meta-item"><i class="fas fa-cubes"></i> <?= htmlspecialchars($b['substrate']) ?></div><?php endif; ?>
            </div>
            <div class="batch-actions">
              <button class="btn btn-ghost btn-sm update-stage-btn" data-id="<?= $b['id'] ?>" data-stage="<?= htmlspecialchars($b['stage']) ?>"><i class="fas fa-arrow-right"></i> Advance Stage</button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this batch and all its harvests?')">
                <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                <button type="submit" name="delete_batch" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Yield Chart -->
      <div class="card">
        <div class="card-header">
          <div class="card-title"><span class="icon icon-purple"><i class="fas fa-chart-bar"></i></span> Yield by Batch</div>
          <span class="card-sub">Top 8 batches</span>
        </div>
        <div class="card-body">
          <?php if (empty($batch_yields)): ?>
            <div class="empty-state"><i class="fas fa-chart-bar"></i><span>No harvest data yet.</span></div>
          <?php else: ?>
            <canvas id="yieldChart" height="220"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Harvest Log -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <div class="card-title"><span class="icon icon-amber"><i class="fas fa-scale-balanced"></i></span> Harvest Log</div>
        <span class="card-sub">Last 50 records</span>
      </div>
      <?php if (empty($harvests)): ?>
        <div class="empty-state"><i class="fas fa-scale-balanced"></i><span>No harvests recorded yet.</span></div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="tbl">
          <thead><tr><th>Date</th><th>Batch</th><th>Species</th><th>Weight</th><th>Count</th><th>Quality</th><th>Notes</th></tr></thead>
          <tbody>
            <?php foreach ($harvests as $h):
              $qc = 'q-'.strtolower($h['quality']);
            ?>
            <tr>
              <td class="mono"><?= date('M j, Y', strtotime($h['harvest_date'])) ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars($h['batch_name']) ?></td>
              <td style="color:var(--muted);font-size:12px;"><?= htmlspecialchars($h['species']) ?></td>
              <td class="mono" style="font-weight:700;"><?= number_format($h['weight_grams'],1) ?> g</td>
              <td class="mono"><?= number_format($h['mushroom_count']) ?></td>
              <td><span class="tl-badge <?= $qc ?>"><?= htmlspecialchars($h['quality']) ?></span></td>
              <td style="color:var(--muted);font-size:12px;"><?= htmlspecialchars($h['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Completed Batches -->
    <?php $completed = array_filter($batches, fn($b) => $b['status'] !== 'active'); ?>
    <?php if (!empty($completed)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title"><span class="icon" style="background:var(--surface2);color:var(--muted);width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;"><i class="fas fa-check"></i></span> Completed / Failed Batches</div>
        <span class="card-sub"><?= count($completed) ?> batches</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="tbl">
          <thead><tr><th>Batch</th><th>Species</th><th>Substrate</th><th>Started</th><th>Final Stage</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($completed as $b): ?>
            <tr>
              <td style="font-weight:600;"><?= htmlspecialchars($b['batch_name']) ?></td>
              <td style="color:var(--muted);"><?= htmlspecialchars($b['species']) ?></td>
              <td style="color:var(--muted);"><?= htmlspecialchars($b['substrate'] ?: '—') ?></td>
              <td class="mono"><?= date('M j, Y', strtotime($b['start_date'])) ?></td>
              <td><?= htmlspecialchars($b['stage']) ?></td>
              <td><span class="tl-badge" style="background:var(--surface2);color:var(--muted);"><?= ucfirst($b['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>

<!-- Add Batch Modal -->
<div id="addBatchModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="addBatchModal">&times;</button>
    <h3><i class="fas fa-seedling" style="color:var(--green);margin-right:8px;"></i>New Batch</h3>
    <form method="POST">
      <input type="hidden" name="add_batch" value="1">
      <div class="form-grid-2">
        <div class="form-group"><label>Batch Name</label><input type="text" name="batch_name" placeholder="e.g. Batch #12" required></div>
        <div class="form-group"><label>Species</label><input type="text" name="species" placeholder="e.g. Oyster, Shiitake" required></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label>Substrate</label><input type="text" name="substrate" placeholder="e.g. Rice straw, Sawdust"></div>
        <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
      </div>
      <div class="form-group">
        <label>Initial Stage</label>
        <select name="stage">
          <?php foreach ($stages as $st): ?><option value="<?= $st ?>"><?= $st ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" placeholder="Optional notes about this batch..."></textarea></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="addBatchModal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create Batch</button>
      </div>
    </form>
  </div>
</div>

<!-- Log Harvest Modal -->
<div id="logHarvestModal" class="modal-backdrop">
  <div class="modal">
    <button class="modal-close" data-close="logHarvestModal">&times;</button>
    <h3><i class="fas fa-scale-balanced" style="color:var(--amber);margin-right:8px;"></i>Log Harvest</h3>
    <form method="POST">
      <input type="hidden" name="add_harvest" value="1">
      <div class="form-group">
        <label>Batch</label>
        <select name="batch_id" required>
          <option value="">Select batch...</option>
          <?php foreach ($batches as $b): ?>
          <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_name']) ?> — <?= htmlspecialchars($b['species']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label>Harvest Date</label><input type="date" name="harvest_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>Weight (grams)</label><input type="number" name="weight_grams" step="0.1" min="0.1" placeholder="e.g. 250.5" required></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group"><label>Mushroom Count</label><input type="number" name="mushroom_count" min="0" placeholder="0"></div>
        <div class="form-group">
          <label>Quality</label>
          <select name="quality">
            <option>Excellent</option><option selected>Good</option><option>Fair</option><option>Poor</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label>Notes</label><textarea name="notes" placeholder="Optional observations..."></textarea></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="logHarvestModal">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:var(--amber);"><i class="fas fa-scale-balanced"></i> Log Harvest</button>
      </div>
    </form>
  </div>
</div>

<!-- Advance Stage Modal -->
<div id="stageModal" class="modal-backdrop">
  <div class="modal" style="max-width:380px;">
    <button class="modal-close" data-close="stageModal">&times;</button>
    <h3><i class="fas fa-arrow-right" style="color:var(--blue);margin-right:8px;"></i>Advance Stage</h3>
    <form method="POST">
      <input type="hidden" name="update_stage" value="1">
      <input type="hidden" name="batch_id" id="stage_batch_id">
      <div class="form-group">
        <label>New Stage</label>
        <select name="new_stage" id="stage_select">
          <?php foreach ($stages as $st): ?><option value="<?= $st ?>"><?= $st ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-close="stageModal">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Stage</button>
      </div>
    </form>
  </div>
</div>

<script>
// PH Time
(function(){
  const el = document.getElementById('phTime'); if (!el) return;
  let t = parseInt(el.dataset.serverTs,10)||Date.now();
  const fmt = ms => new Date(ms).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true}).replace(',',' —');
  el.textContent = fmt(t); setInterval(()=>{t+=1000;el.textContent=fmt(t);},1000);
})();

// Modals
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.querySelectorAll('[data-close]').forEach(el=>el.addEventListener('click',()=>closeModal(el.dataset.close)));
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open');}));
document.getElementById('openAddBatch')?.addEventListener('click',()=>openModal('addBatchModal'));
document.getElementById('openLogHarvest')?.addEventListener('click',()=>openModal('logHarvestModal'));

// Advance stage buttons
document.querySelectorAll('.update-stage-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const stages=['Spawn Run','Pinning','Fruiting','Harvest','Completed'];
    const cur = btn.dataset.stage; const idx = stages.indexOf(cur);
    document.getElementById('stage_batch_id').value = btn.dataset.id;
    const sel = document.getElementById('stage_select');
    sel.value = stages[Math.min(idx+1,stages.length-1)];
    openModal('stageModal');
  });
});

// Yield chart
<?php if (!empty($batch_yields)): ?>
new Chart(document.getElementById('yieldChart').getContext('2d'),{
  type:'bar',
  data:{
    labels:<?= json_encode(array_column($batch_yields,'batch_name')) ?>,
    datasets:[{
      label:'Yield (g)',
      data:<?= json_encode(array_column($batch_yields,'total_weight')) ?>,
      backgroundColor:'rgba(26,158,92,0.75)',
      borderRadius:6,borderSkipped:false
    }]
  },
  options:{responsive:true,maintainAspectRatio:true,
    plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.parsed.y.toFixed(1)+'g'}}},
    scales:{
      x:{grid:{display:false},ticks:{font:{family:'DM Sans',size:11},color:'#6e7681',maxRotation:30}},
      y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{font:{family:'DM Mono',size:11},color:'#6e7681',callback:v=>v+'g'}}
    }
  }
});
<?php endif; ?>
</script>
</body>
</html>