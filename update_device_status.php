<?php
/**
 * update_device_status.php
 * Writes to device_state table (new architecture).
 * Accepts same params as old version — no changes needed in dashboard JS or ESP32 code.
 *
 * Supported params (GET, POST, or JSON body):
 *   ?mode=0|1                        — switch auto/manual
 *   ?device=fan                      — toggle a single device (reads current, flips)
 *   ?fan=1&mist=0                    — set explicit values
 *   ?sprayer=1&lock_seconds=60       — set + lock (used by ESP32 schedule)
 *   ?active_spray_until=1234567890   — legacy ESP32 compat (converted to lock_seconds internally)
 */
header('Content-Type: application/json');
include 'includes/db_connect.php';

// ── Read input from GET, POST, or JSON body ──
$input = [];
$raw = file_get_contents('php://input');
if ($raw) $input = json_decode($raw, true) ?? [];
if (empty($input)) $input = array_merge($_GET, $_POST);

// ── Bootstrap tables ──
$conn->query("CREATE TABLE IF NOT EXISTS device_state (
    device        VARCHAR(20)  NOT NULL PRIMARY KEY,
    status        ENUM('on','off') NOT NULL DEFAULT 'off',
    controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
    locked_until  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
foreach (['mist','fan','heater','sprayer','exhaust'] as $dev) {
    $conn->query("INSERT IGNORE INTO device_state (device,status,controlled_by,locked_until)
                  VALUES ('{$dev}','off','auto',0)");
}
$conn->query("CREATE TABLE IF NOT EXISTS system_mode (
    id INT PRIMARY KEY DEFAULT 1,
    mode ENUM('auto','manual') NOT NULL DEFAULT 'auto'
)");
$conn->query("INSERT IGNORE INTO system_mode (id,mode) VALUES (1,'auto')");

$conn->query("CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device VARCHAR(30) NOT NULL,
    action ENUM('ON','OFF') NOT NULL,
    trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual',
    trigger_detail VARCHAR(200),
    duration_seconds INT DEFAULT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE device_logs MODIFY COLUMN trigger_type
              ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'manual'");

function _logDev($conn, $device, $action, $by, $detail, $durationSeconds = null) {
    $s = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail,duration_seconds) VALUES (?,?,?,?,?)");
    if ($s) { $s->bind_param("ssssi",$device,$action,$by,$detail,$durationSeconds); $s->execute(); $s->close(); }
}

function buildResponse($conn) {
    $deviceMap = [];
    $r = $conn->query("SELECT device,status,controlled_by,locked_until FROM device_state");
    if ($r) while ($row = $r->fetch_assoc()) $deviceMap[$row['device']] = $row;
    $modeRow    = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
    $manualMode = ($modeRow['mode'] ?? 'auto') === 'manual' ? 1 : 0;
    // Build legacy-compatible 'data' block
    $data = ['manual_mode' => $manualMode];
    foreach (['mist','fan','heater','sprayer','exhaust'] as $dev) {
        $data[$dev] = (($deviceMap[$dev]['status'] ?? 'off') === 'on') ? 1 : 0;
    }
    $data['controlled_by'] = array_map(fn($s) => $s['controlled_by'], $deviceMap);
    $data['locked_until']  = array_map(fn($s) => (int)$s['locked_until'], $deviceMap);
    return $data;
}

$allowed = ['mist','fan','heater','sprayer','exhaust'];

// ════════════════════════════════════════════════════
//  MODE SWITCH  ?mode=0|1
// ════════════════════════════════════════════════════
if (isset($input['mode'])) {
    $wantManual = intval($input['mode']) === 1;
    $conn->query("UPDATE system_mode SET mode='" . ($wantManual ? 'manual' : 'auto') . "' WHERE id=1");

    if (!$wantManual) {
        // Switching TO auto: release all manual locks and reset manual-controlled devices to off
        // Schedule locks (sprayer) are preserved
        foreach ($allowed as $dev) {
            $row = $conn->query("SELECT controlled_by FROM device_state WHERE device='{$dev}' LIMIT 1")->fetch_assoc();
            if (($row['controlled_by'] ?? '') === 'manual') {
                $conn->query("UPDATE device_state SET status='off', controlled_by='auto', locked_until=0 WHERE device='{$dev}'");
                _logDev($conn, $dev, 'OFF', 'auto', 'Switched to Auto Mode — manual devices reset to OFF', null);
            }
        }
    }

    echo json_encode(['success' => true, 'data' => buildResponse($conn)]);
    exit;
}

// ════════════════════════════════════════════════════
//  LEGACY: active_spray_until (ESP32 schedule lock)
//  Converts to lock_seconds internally
// ════════════════════════════════════════════════════
if (isset($input['active_spray_until'])) {
    $asu = (int)$input['active_spray_until'];
    if ($asu === 0) {
        // Clear lock
        $conn->query("UPDATE device_state SET locked_until=0, controlled_by='auto' WHERE device='sprayer'");
    } else {
        $conn->query("UPDATE device_state SET locked_until={$asu} WHERE device='sprayer'");
    }
    // Don't exit — may also have sprayer=0|1 in same request, fall through
}

// ════════════════════════════════════════════════════
//  DEVICE TOGGLE  ?device=fan  (reads current, flips)
// ════════════════════════════════════════════════════
if (isset($input['device'])) {
    $dev = $input['device'];
    if (!in_array($dev, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid device']);
        exit;
    }
    $cur = $conn->query("SELECT status FROM device_state WHERE device='{$dev}' LIMIT 1")->fetch_assoc();
    $newStatus = ($cur['status'] === 'on') ? 'off' : 'on';
    $conn->query("UPDATE device_state
                  SET status='{$newStatus}', controlled_by='manual', locked_until=0
                  WHERE device='{$dev}'");
    $action = $newStatus === 'on' ? 'ON' : 'OFF';
                // Calculate duration for manual devices being turned off
                $durationSeconds = null;
                if ($newStatus === 'off') {
                    $lastOn = $conn->query("SELECT logged_at FROM device_logs
                                            WHERE device='{$dev}' AND action='ON'
                                            ORDER BY logged_at DESC LIMIT 1");
                    if ($lastOn && $lastOn->num_rows > 0) {
                        $onSince = new DateTime($lastOn->fetch_assoc()['logged_at']);
                        $now = new DateTime();
                        $durationSeconds = $now->getTimestamp() - $onSince->getTimestamp();
                    }
                }
                _logDev($conn, $dev, $action, 'manual', 'Manual toggle via dashboard', $durationSeconds);
    echo json_encode(['success'=>true,'data'=>buildResponse($conn)]);
    exit;
}

// ════════════════════════════════════════════════════
//  EXPLICIT VALUES  ?fan=1&mist=0
//  With optional ?lock_seconds=N for schedule use
// ════════════════════════════════════════════════════
$lockSeconds = isset($input['lock_seconds']) ? intval($input['lock_seconds']) : 0;
$lockUntil   = $lockSeconds > 0 ? (time() + $lockSeconds) : 0;

// Determine trigger: if lock is set → schedule, else → manual
$triggerBy = ($lockSeconds > 0 || isset($input['active_spray_until'])) ? 'schedule' : 'manual';

$changed = false;
foreach ($allowed as $dev) {
    if (!isset($input[$dev])) continue;
    $val    = intval($input[$dev]) ? 'on' : 'off';
    $action = $val === 'on' ? 'ON' : 'OFF';

    if ($val === 'on' && $lockUntil > 0) {
        $conn->query("UPDATE device_state
                      SET status='on', controlled_by='{$triggerBy}', locked_until={$lockUntil}
                      WHERE device='{$dev}'");
    } else {
        // OFF always clears the lock
        $lockClear = $val === 'off' ? 0 : $lockUntil;
        $conn->query("UPDATE device_state
                      SET status='{$val}', controlled_by='{$triggerBy}', locked_until={$lockClear}
                      WHERE device='{$dev}'");
    }
    // Calculate duration when turning devices off
    $durationSeconds = null;
    if ($val === 'off') {
        $lastOn = $conn->query("SELECT logged_at FROM device_logs
                                WHERE device='{$dev}' AND action='ON'
                                ORDER BY logged_at DESC LIMIT 1");
        if ($lastOn && $lastOn->num_rows > 0) {
            $onSince = new DateTime($lastOn->fetch_assoc()['logged_at']);
            $now = new DateTime();
            $durationSeconds = $now->getTimestamp() - $onSince->getTimestamp();
        }
    }
    _logDev($conn, $dev, $action, $triggerBy, ucfirst($triggerBy) . " set {$dev} {$action}", $durationSeconds);
    $changed = true;
}

if (!$changed && !isset($input['active_spray_until'])) {
    echo json_encode(['success'=>false,'message'=>'No valid fields provided']);
    exit;
}

echo json_encode(['success'=>true,'data'=>buildResponse($conn)]);
$conn->close();
?>