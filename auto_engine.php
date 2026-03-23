<?php
// auto_engine.php
// Architecture: device_state table (one row per device) replaces device_status 0/1 columns.
// controlled_by + locked_until prevent any engine from overwriting another's active device.

date_default_timezone_set('Asia/Manila');

define('FAULT_NO_RESPONSE_MINUTES_DEFAULT', 5);
define('FAULT_MAX_ON_MINUTES_DEFAULT', 60);

// Devices that have sensor expectations for fault detection
$DEVICE_SENSOR_EXPECTATION = [
    'mist'   => ['sensor' => 'humidity',    'direction' => 'rising'],
    'heater' => ['sensor' => 'temperature', 'direction' => 'rising'],
    'fan'    => ['sensor' => 'temperature', 'direction' => 'falling'],
];

// Conflict pairs — turning one ON forces the other OFF
$DEVICE_CONFLICTS = [
    'heater' => 'fan',
    'fan'    => 'heater',
    'mist'   => 'exhaust',
    'exhaust'=> 'mist',
];

// ============================================================
//  DB BOOTSTRAP — creates device_state and system_mode tables
//  Safe to call on every request (IF NOT EXISTS / INSERT IGNORE)
// ============================================================
function bootstrapDeviceState($conn) {
    // One row per device. controlled_by tells us who is responsible.
    // locked_until (UNIX ts): nobody else may change this device until the lock expires.
    $conn->query("CREATE TABLE IF NOT EXISTS device_state (
        device       VARCHAR(20)  NOT NULL PRIMARY KEY,
        status       ENUM('on','off') NOT NULL DEFAULT 'off',
        controlled_by ENUM('auto','manual','schedule','emergency') NOT NULL DEFAULT 'auto',
        locked_until INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Seed all devices if they don't exist yet
    $devices = ['mist','fan','heater','sprayer','exhaust'];
    foreach ($devices as $dev) {
        $conn->query("INSERT IGNORE INTO device_state (device,status,controlled_by,locked_until)
                      VALUES ('{$dev}','off','auto',0)");
    }

    // system_mode: global auto/manual switch (separate from per-device state)
    $conn->query("CREATE TABLE IF NOT EXISTS system_mode (
        id   INT PRIMARY KEY DEFAULT 1,
        mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->query("INSERT IGNORE INTO system_mode (id,mode) VALUES (1,'auto')");

    // Buzzer flag (server → ESP32 signal)
    $conn->query("CREATE TABLE IF NOT EXISTS system_flags (
        flag_key   VARCHAR(30) PRIMARY KEY,
        flag_value TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->query("INSERT IGNORE INTO system_flags (flag_key,flag_value) VALUES ('buzzer',0)");
}

// ============================================================
//  HELPERS
// ============================================================
function getDeviceStates($conn) {
    $states = [];
    $r = $conn->query("SELECT device,status,controlled_by,locked_until FROM device_state");
    if ($r) while ($row = $r->fetch_assoc()) $states[$row['device']] = $row;
    return $states;
}

function isLocked($state) {
    // A device is locked if locked_until is in the future
    return (int)($state['locked_until'] ?? 0) > time();
}

function deviceOn($conn, $device, $by, $lockSeconds = 0) {
    $lockUntil = $lockSeconds > 0 ? (time() + $lockSeconds) : 0;
    $conn->query("UPDATE device_state
                  SET status='on', controlled_by='{$by}', locked_until={$lockUntil}
                  WHERE device='{$device}'");
    _logDevice($conn, $device, 'ON', $by, "Turned ON by {$by}" . ($lockSeconds > 0 ? " (locked {$lockSeconds}s)" : ""));
}

function deviceOff($conn, $device, $by, $detail = '') {
    $conn->query("UPDATE device_state
                  SET status='off', controlled_by='{$by}', locked_until=0
                  WHERE device='{$device}'");
    _logDevice($conn, $device, 'OFF', $by, $detail ?: "Turned OFF by {$by}");
}

function _logDevice($conn, $device, $action, $triggerType, $detail) {
    $conn->query("CREATE TABLE IF NOT EXISTS device_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device VARCHAR(30) NOT NULL,
        action ENUM('ON','OFF') NOT NULL,
        trigger_type ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'auto',
        trigger_detail VARCHAR(200),
        duration_seconds INT DEFAULT NULL,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->query("ALTER TABLE device_logs MODIFY COLUMN trigger_type
                  ENUM('auto','manual','schedule','emergency','fault') NOT NULL DEFAULT 'auto'");
    $stmt = $conn->prepare("INSERT INTO device_logs (device,action,trigger_type,trigger_detail) VALUES (?,?,?,?)");
    if ($stmt) { $stmt->bind_param("ssss",$device,$action,$triggerType,$detail); $stmt->execute(); $stmt->close(); }
}

function _logAlert($conn, $type, $severity, $message, $value) {
    $conn->query("CREATE TABLE IF NOT EXISTS alert_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        alert_type ENUM('temperature','humidity','device','system') NOT NULL,
        severity ENUM('warning','critical','info') NOT NULL DEFAULT 'warning',
        message TEXT NOT NULL, value FLOAT NULL,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $conn->prepare("INSERT INTO alert_logs (alert_type,severity,message,value) VALUES (?,?,?,?)");
    if ($stmt) { $stmt->bind_param("sssd",$type,$severity,$message,$value); $stmt->execute(); $stmt->close(); }

    $ns = []; $ss = [];
    $r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
    if ($r) while ($row=$r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
    $r2 = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    if ($r2) while ($row=$r2->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];

    $should_email = false;
    if ($type === 'device'      && ($ss['notify_emergency'] ?? '1') === '1') $should_email = true;
    if ($type === 'system'      && ($ss['notify_offline']   ?? '1') === '1') $should_email = true;
    if ($type === 'temperature' && ($ss['notify_temp']      ?? '1') === '1') $should_email = true;
    if ($type === 'humidity'    && ($ss['notify_hum']       ?? '1') === '1') $should_email = true;

    if ($should_email) {
        $cooldown_min = intval($ss['notify_cooldown_min'] ?? $ns['notify_cooldown_min'] ?? 30);
        $owner = $conn->query("SELECT email FROM users WHERE role='owner' LIMIT 1");
        $recipient = $ns['smtp_to_email'] ?? '';
        if ($owner && $row = $owner->fetch_assoc()) $recipient = $row['email'];
        if (!$recipient) return;

        $throttle_key = $type . '_' . $recipient;
        $conn->query("CREATE TABLE IF NOT EXISTS email_throttle (
            email VARCHAR(120) PRIMARY KEY,
            last_sent TIMESTAMP NOT NULL
        )");
        $tq = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email=?");
        if ($tq) {
            $tq->bind_param("s",$throttle_key); $tq->execute();
            $tr = $tq->get_result();
            if ($tr->num_rows > 0) {
                $last = strtotime($tr->fetch_assoc()['last_sent']);
                if ((time()-$last) < ($cooldown_min*60)) return;
            }
            $tq->close();
        }

        if (file_exists(__DIR__.'/send_email.php')) {
            require_once __DIR__.'/send_email.php';
            $icons    = ['device'=>'🔧','system'=>'📡','temperature'=>'🌡️','humidity'=>'💧'];
            $icon     = $icons[$type] ?? '⚠️';
            $colorHex = ($severity === 'critical') ? '#d93025' : '#b45309';
            $colorLt  = ($severity === 'critical') ? '#fff0f0' : '#fef3c7';
            $borderCol= ($severity === 'critical') ? '#e53935' : '#f9a825';
            $typeLabel= ucfirst($type);
            $sevLabel = ucfirst($severity);
            $detAt    = date('M j, Y h:i:s A');
            $subj     = "$icon MushroomOS — {$typeLabel} Alert";
            $body     = "<div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                <div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                  <h2 style='color:#c8e8b8;margin:0;font-size:20px;'>&#127812; MushroomOS — {$typeLabel} Alert</h2>
                  <p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                </div>
                <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                  <p style='background:{$colorLt};border-left:4px solid {$borderCol};padding:12px 16px;border-radius:4px;color:{$colorHex};font-weight:600;margin:0 0 16px;'>
                    &#9888; {$sevLabel}: {$typeLabel} alert detected.</p>
                  <p style='color:#555;font-size:13px;line-height:1.6;margin:0 0 16px;'>" . nl2br(htmlspecialchars($message)) . "</p>
                  <p style='color:#555;font-size:13px;'>Please check your chamber and devices immediately.</p>
                </div></div>";
            sendEmail($recipient, $subj, $body);
            $now = date('Y-m-d H:i:s');
            $us = $conn->prepare("INSERT INTO email_throttle (email,last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
            if ($us) { $us->bind_param("sss",$throttle_key,$now,$now); $us->execute(); $us->close(); }
        }
    }
}

// ============================================================
//  MAIN ENGINE
// ============================================================
function runAutoEngine($conn, $temperature, $humidity, $timestamp) {
    global $DEVICE_SENSOR_EXPECTATION, $DEVICE_CONFLICTS;

    bootstrapDeviceState($conn);

    $ss_r = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    $ss = [];
    if ($ss_r) while ($row_ss = $ss_r->fetch_assoc()) $ss[$row_ss['setting_key']] = $row_ss['setting_value'];
    $FAULT_NO_RESPONSE_MINUTES = intval($ss['fault_timeout_min'] ?? FAULT_NO_RESPONSE_MINUTES_DEFAULT);
    $FAULT_MAX_ON_MINUTES      = intval($ss['stuck_timeout_min'] ?? FAULT_MAX_ON_MINUTES_DEFAULT);

    // --- System mode ---
    $modeRow    = $conn->query("SELECT mode FROM system_mode WHERE id=1 LIMIT 1")->fetch_assoc();
    $manualMode = ($modeRow['mode'] ?? 'auto') === 'manual';

    // --- Current device states ---
    $states = getDeviceStates($conn);

    // --- Thresholds ---
    $conn->query("INSERT IGNORE INTO alert_thresholds (metric,min_value,max_value) VALUES
        ('temperature',22,28),('humidity',85,95),
        ('emergency_temp',15,35),('emergency_hum',0,98)");
    $thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
            'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
    $tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
    if ($tr) while ($r2 = $tr->fetch_assoc()) {
        if ($r2['metric']==='temperature')    { $thr['temp_min']=$r2['min_value']; $thr['temp_max']=$r2['max_value']; }
        if ($r2['metric']==='humidity')       { $thr['hum_min']=$r2['min_value'];  $thr['hum_max']=$r2['max_value']; }
        if ($r2['metric']==='emergency_temp') { $thr['emerg_temp_low']=$r2['min_value']; $thr['emerg_temp_high']=$r2['max_value']; }
        if ($r2['metric']==='emergency_hum')  { $thr['emerg_hum_high']=$r2['max_value']; }
    }

    // --- Sensor freshness check ---
    $lastTs = $conn->query("SELECT timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
    $sensorOnline = false;
    if ($lastTs && $row_ts = $lastTs->fetch_assoc()) {
        $sensorOnline = ((time() - strtotime($row_ts['timestamp'])) / 60) < 5;
    }
    if (!$sensorOnline) {
        // Sensor offline — turn OFF auto-controlled devices only (not locked/manual/schedule)
        if (!$manualMode) {
            foreach ($states as $dev => $state) {
                if ($dev === 'sprayer') continue; // sprayer is schedule-controlled
                if ($state['status'] === 'on' && !isLocked($state) && $state['controlled_by'] === 'auto') {
                    deviceOff($conn, $dev, 'auto', 'Sensor offline — auto devices turned OFF');
                }
            }
        }
        $conn->query("UPDATE system_flags SET flag_value=0 WHERE flag_key='buzzer'");
        return;
    }

    $buzzerOn = false;

    // ============================================================
    //  STEP 1 — EMERGENCY SHUTOFF
    //  Overrides ALL locks and modes. Safety first.
    // ============================================================
    $emergencies = [];
    if ($temperature > $thr['emerg_temp_high']) {
        $emergencies['heater'] = "Emergency: Temp {$temperature}°C critically high — Heater forced OFF";
        // Exhaust ON during high temp (if not already on)
        if (($states['exhaust']['status'] ?? 'off') === 'off') {
            deviceOn($conn, 'exhaust', 'emergency');
            $states['exhaust']['status'] = 'on';
            $conn->query("UPDATE device_state SET controlled_by='emergency', locked_until=0 WHERE device='exhaust'");
        }
    }
    if ($temperature < $thr['emerg_temp_low']) {
        $emergencies['fan'] = "Emergency: Temp {$temperature}°C critically low — Fan forced OFF";
    }
    if ($humidity > $thr['emerg_hum_high']) {
        $emergencies['mist']    = "Emergency: Humidity {$humidity}% critically high — Mist forced OFF";
        $emergencies['sprayer'] = "Emergency: Humidity {$humidity}% critically high — Sprayer forced OFF";
    }

    foreach ($emergencies as $device => $reason) {
        if (($states[$device]['status'] ?? 'off') === 'on') {
            // Emergency overrides ALL locks — including schedule locks
            $conn->query("UPDATE device_state SET status='off', controlled_by='emergency', locked_until=0 WHERE device='{$device}'");
            _logDevice($conn, $device, 'OFF', 'emergency', $reason);
            _logAlert($conn, 'device', 'critical', $reason,
                in_array($device, ['heater','fan']) ? $temperature : $humidity);
            $states[$device]['status']      = 'off';
            $states[$device]['locked_until'] = 0;
            $buzzerOn = true;
        }
    }

    // ============================================================
    //  STEP 2 — FAULT DETECTION
    //  Runs in both auto and manual. Skips locked devices.
    // ============================================================
    foreach ($DEVICE_SENSOR_EXPECTATION as $device => $expect) {
        if (($states[$device]['status'] ?? 'off') !== 'on') continue;
        // Don't fault-check a schedule-locked device (e.g. sprayer mid-spray)
        if (isLocked($states[$device])) continue;

        $sensorVal = $expect['sensor'] === 'temperature' ? $temperature : $humidity;
        $lastOn = $conn->query("SELECT logged_at FROM device_logs
                                WHERE device='{$device}' AND action='ON'
                                ORDER BY logged_at DESC LIMIT 1");
        if (!$lastOn || $lastOn->num_rows === 0) continue;

        $onSince   = new DateTime($lastOn->fetch_assoc()['logged_at']);
        $now       = new DateTime($timestamp);
        $onMinutes = ($now->getTimestamp() - $onSince->getTimestamp()) / 60;

        $faultType = null; $faultReason = null;

        if ($onMinutes >= $FAULT_MAX_ON_MINUTES) {
            $faultType   = 'stuck_on';
            $faultReason = "Fault: {$device} ON for " . round($onMinutes) . " min — forced OFF";
        }

        if (!$faultType && $onMinutes >= $FAULT_NO_RESPONSE_MINUTES) {
            $valAtOn = $conn->query(
                "SELECT {$expect['sensor']} FROM sensor_data
                 WHERE timestamp <= '{$onSince->format('Y-m-d H:i:s')}'
                 ORDER BY id DESC LIMIT 1"
            );
            if ($valAtOn && $valAtOn->num_rows > 0) {
                $valThen  = floatval($valAtOn->fetch_assoc()[$expect['sensor']]);
                $delta    = $sensorVal - $valThen;
                $notResp  = ($expect['direction'] === 'rising'  && $delta <= 0)
                         || ($expect['direction'] === 'falling' && $delta >= 0);
                if ($notResp) {
                    $faultType   = 'no_response';
                    $dir         = $expect['direction'] === 'rising' ? 'increase' : 'decrease';
                    $faultReason = "Fault: {$device} ON {$onMinutes}min but {$expect['sensor']} did not {$dir} (was {$valThen}, now {$sensorVal})";
                }
            }
        }

        if ($faultType && $faultReason) {
            $conn->query("UPDATE device_state SET status='off', controlled_by='auto', locked_until=0 WHERE device='{$device}'");
            _logDevice($conn, $device, 'OFF', 'fault', $faultReason);
            _logAlert($conn, 'device', 'critical', $faultReason, $sensorVal);
            $conn->query("CREATE TABLE IF NOT EXISTS device_faults (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device VARCHAR(30) NOT NULL,
                fault_type ENUM('no_response','stuck_on') NOT NULL,
                detail VARCHAR(200), sensor_val FLOAT,
                resolved TINYINT(1) NOT NULL DEFAULT 0,
                logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $fs = $conn->prepare("INSERT INTO device_faults (device,fault_type,detail,sensor_val) VALUES (?,?,?,?)");
            if ($fs) { $fs->bind_param("sssd",$device,$faultType,$faultReason,$sensorVal); $fs->execute(); $fs->close(); }
            $states[$device]['status']      = 'off';
            $states[$device]['locked_until'] = 0;
            $buzzerOn = true;
        }
    }

    // ============================================================
    //  STEP 3 — AUTO MODE RULES
    //  Only runs when mode=auto.
    //  Skips any device that is locked (e.g. schedule-running sprayer,
    //  or manually-controlled device).
    // ============================================================
    if (!$manualMode) {
        $rules = [];
        $r = $conn->query("SELECT * FROM automation_rules WHERE enabled=1 ORDER BY id");
        if ($r) while ($rule = $r->fetch_assoc()) $rules[] = $rule;

        // One rule per device — first rule wins
        $seenDevices = [];
        foreach ($rules as $rule) {
            $device = $rule['device'];

            // Sprayer is schedule-only — never touched by sensor rules
            if ($device === 'sprayer') continue;
            // Each device gets at most one rule evaluated per cycle
            if (isset($seenDevices[$device])) continue;
            $seenDevices[$device] = true;

            $state     = $states[$device] ?? ['status'=>'off','controlled_by'=>'auto','locked_until'=>0];
            $sensor    = $rule['sensor'];
            $operator  = $rule['operator'];
            $threshold = floatval($rule['threshold']);
            $sensorVal = $sensor === 'temperature' ? $temperature : $humidity;
            $condMet   = ($operator === 'below' && $sensorVal < $threshold)
                      || ($operator === 'above' && $sensorVal > $threshold);

            // LOCKED: skip — schedule or manual owns this device
            if (isLocked($state)) continue;

            // MANUAL-controlled: auto cannot override it
            if ($state['controlled_by'] === 'manual' && !$condMet) continue;

            if ($condMet && $state['status'] === 'off') {
                // Conflict guard: turn off the opposing device first
                $opp = $DEVICE_CONFLICTS[$device] ?? null;
                if ($opp && ($states[$opp]['status'] ?? 'off') === 'on' && !isLocked($states[$opp])) {
                    deviceOff($conn, $opp, 'auto', "Conflict: {$device} turning ON, {$opp} forced OFF");
                    $states[$opp]['status'] = 'off';
                }
                deviceOn($conn, $device, 'auto');
                $states[$device]['status'] = 'on';
                _logDevice($conn, $device, 'ON', 'auto', "Auto: {$sensor} {$operator} {$threshold} (now {$sensorVal})");

            } elseif (!$condMet && $state['status'] === 'on' && $state['controlled_by'] === 'auto') {
                deviceOff($conn, $device, 'auto', "Auto: {$sensor} back in range (now {$sensorVal}, threshold {$threshold})");
                $states[$device]['status'] = 'off';
            }
        }
    }

    // ============================================================
    //  STEP 4 — BUZZER FLAG
    // ============================================================
    $conn->query("UPDATE system_flags SET flag_value=" . ($buzzerOn ? 1 : 0) . " WHERE flag_key='buzzer'");
}
?>