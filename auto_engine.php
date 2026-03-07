<?php
// auto_engine.php
// ══════════════════════════════════════════════════════════════
// Called every time the ESP32 sends sensor data.
// Handles:
//   1. Emergency shutoff   — overrides everything, even manual mode
//   2. Fault detection     — device ON but not doing its job → force OFF + buzzer
//   3. Auto mode rules     — sensor-based device control
//   4. Scheduled tasks     — time-based device control
// ══════════════════════════════════════════════════════════════

date_default_timezone_set('Asia/Manila');

// Fault timing — read from DB if available, else safe defaults
// These can be added to alert_thresholds table as 'fault_no_response_min' and 'fault_max_on_min'
define('FAULT_NO_RESPONSE_MINUTES', 5);
define('FAULT_MAX_ON_MINUTES', 60);

$DEVICE_SENSOR_EXPECTATION = [
    'mist'    => ['sensor' => 'humidity',    'direction' => 'rising'],
    'sprayer' => ['sensor' => 'humidity',    'direction' => 'rising'],
    'heater'  => ['sensor' => 'temperature', 'direction' => 'rising'],
    'fan'     => ['sensor' => 'temperature', 'direction' => 'falling'],
];

function runAutoEngine($conn, $temperature, $humidity, $timestamp) {
    global $DEVICE_SENSOR_EXPECTATION;

    // Ensure buzzer column exists
    $conn->query("ALTER TABLE device_status ADD COLUMN IF NOT EXISTS buzzer TINYINT(1) NOT NULL DEFAULT 0");

    $conn->query("INSERT IGNORE INTO device_status (id,manual_mode,mist,fan,heater,sprayer,exhaust,buzzer) VALUES (1,0,0,0,0,0,0,0)");

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

    $conn->query("CREATE TABLE IF NOT EXISTS device_faults (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device VARCHAR(30) NOT NULL,
        fault_type ENUM('no_response','stuck_on') NOT NULL,
        detail VARCHAR(200),
        sensor_val FLOAT,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $row        = $conn->query("SELECT * FROM device_status WHERE id=1 LIMIT 1")->fetch_assoc();
    $manualMode = (int)($row['manual_mode'] ?? 0);
    $buzzerOn   = false;

    // ── Load thresholds from DB (never hardcoded) ──
    $conn->query("INSERT IGNORE INTO alert_thresholds (metric,min_value,max_value) VALUES
        ('temperature',22,28),('humidity',85,95),
        ('emergency_temp',15,35),('emergency_hum',0,98)");
    $thr = ['temp_min'=>22,'temp_max'=>28,'hum_min'=>85,'hum_max'=>95,
            'emerg_temp_high'=>35,'emerg_temp_low'=>15,'emerg_hum_high'=>98];
    $tr = $conn->query("SELECT metric,min_value,max_value FROM alert_thresholds");
    if ($tr) while ($r2 = $tr->fetch_assoc()) {
        if ($r2['metric']==='temperature')   { $thr['temp_min']=$r2['min_value']; $thr['temp_max']=$r2['max_value']; }
        if ($r2['metric']==='humidity')      { $thr['hum_min']=$r2['min_value'];  $thr['hum_max']=$r2['max_value']; }
        if ($r2['metric']==='emergency_temp'){ $thr['emerg_temp_low']=$r2['min_value']; $thr['emerg_temp_high']=$r2['max_value']; }
        if ($r2['metric']==='emergency_hum') { $thr['emerg_hum_high']=$r2['max_value']; }
    }

    // ══════════════════════════════════════════════════════
    // STEP 1 — EMERGENCY SHUTOFF (overrides manual mode)
    // All values from DB — nothing hardcoded
    // ══════════════════════════════════════════════════════
    $emergencies = [];
    if ($temperature > $thr['emerg_temp_high'])
        $emergencies['heater']  = "Emergency: Temp {$temperature}°C critically high (>{$thr['emerg_temp_high']}°C) — Heater forced OFF";
    if ($humidity > $thr['emerg_hum_high']) {
        $emergencies['mist']    = "Emergency: Humidity {$humidity}% critically high (>{$thr['emerg_hum_high']}%) — Mist forced OFF";
        $emergencies['sprayer'] = "Emergency: Humidity {$humidity}% critically high (>{$thr['emerg_hum_high']}%) — Sprayer forced OFF";
    }
    if ($temperature < $thr['emerg_temp_low'])
        $emergencies['fan']     = "Emergency: Temp {$temperature}°C critically low (<{$thr['emerg_temp_low']}°C) — Fan forced OFF";

    foreach ($emergencies as $device => $reason) {
        if ((int)($row[$device] ?? 0) === 1) {
            $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
            _logDevice($conn, $device, 'OFF', 'emergency', $reason);
            _logAlert($conn, 'device', 'critical', $reason, in_array($device,['heater','fan']) ? $temperature : $humidity);
            $row[$device] = 0;
            $buzzerOn = true;
        }
    }

    // ══════════════════════════════════════════════════════
    // STEP 2 — FAULT DETECTION (overrides manual mode too)
    // A) Device ON too long (stuck_on)
    // B) Device ON but sensor not responding (no_response)
    // ══════════════════════════════════════════════════════
    foreach ($DEVICE_SENSOR_EXPECTATION as $device => $expect) {
        if ((int)($row[$device] ?? 0) !== 1) continue;

        $sensorVal = $expect['sensor'] === 'temperature' ? $temperature : $humidity;

        $lastOn = $conn->query("SELECT logged_at FROM device_logs
                                WHERE device='{$device}' AND action='ON'
                                ORDER BY logged_at DESC LIMIT 1");
        if (!$lastOn || $lastOn->num_rows === 0) continue;

        $onSince   = new DateTime($lastOn->fetch_assoc()['logged_at']);
        $now       = new DateTime($timestamp);
        $onMinutes = ($now->getTimestamp() - $onSince->getTimestamp()) / 60;

        $faultType   = null;
        $faultReason = null;

        // Fault A: stuck ON too long
        if ($onMinutes >= FAULT_MAX_ON_MINUTES) {
            $faultType   = 'stuck_on';
            $faultReason = "Fault: {$device} ON for " . round($onMinutes) . " min (max " . FAULT_MAX_ON_MINUTES . " min) — forced OFF";
        }

        // Fault B: no sensor response after threshold time
        if (!$faultType && $onMinutes >= FAULT_NO_RESPONSE_MINUTES) {
            $valAtOn = $conn->query(
                "SELECT " . $expect['sensor'] . " FROM sensor_data
                 WHERE timestamp <= '{$onSince->format('Y-m-d H:i:s')}'
                 ORDER BY id DESC LIMIT 1"
            );
            if ($valAtOn && $valAtOn->num_rows > 0) {
                $valThen = floatval($valAtOn->fetch_assoc()[$expect['sensor']]);
                $delta   = $sensorVal - $valThen;
                $notResp = ($expect['direction'] === 'rising'  && $delta <= 0)
                        || ($expect['direction'] === 'falling' && $delta >= 0);
                if ($notResp) {
                    $faultType   = 'no_response';
                    $dir         = $expect['direction'] === 'rising' ? 'increase' : 'decrease';
                    $faultReason = "Fault: {$device} ON {$onMinutes} min but {$expect['sensor']} did not {$dir} (was {$valThen}, now {$sensorVal}) — forced OFF";
                }
            }
        }

        if ($faultType && $faultReason) {
            $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
            _logDevice($conn, $device, 'OFF', 'fault', $faultReason);
            _logAlert($conn, 'device', 'critical', $faultReason, $sensorVal);

            $fs = $conn->prepare("INSERT INTO device_faults (device,fault_type,detail,sensor_val) VALUES (?,?,?,?)");
            if ($fs) { $fs->bind_param("sssd",$device,$faultType,$faultReason,$sensorVal); $fs->execute(); $fs->close(); }

            $row[$device] = 0;
            $buzzerOn = true;
        }
    }

    // ══════════════════════════════════════════════════════
    // STEP 3 — AUTO MODE RULES (only when not in manual mode)
    // ══════════════════════════════════════════════════════
    if (!$manualMode) {
        $rules = [];
        $r = $conn->query("SELECT * FROM automation_rules WHERE enabled=1 ORDER BY id");
        if ($r) while ($rule = $r->fetch_assoc()) $rules[] = $rule;

        foreach ($rules as $rule) {
            $device    = $rule['device'];
            $sensor    = $rule['sensor'];
            $operator  = $rule['operator'];
            $threshold = floatval($rule['threshold']);
            $sensorVal = $sensor === 'temperature' ? $temperature : $humidity;
            $current   = (int)($row[$device] ?? 0);
            // Use rule threshold — set by user in Automation page
        $condMet   = ($operator === 'below' && $sensorVal < $threshold)
                      || ($operator === 'above' && $sensorVal > $threshold);

            if ($condMet && $current === 0) {
                $conn->query("UPDATE device_status SET {$device}=1 WHERE id=1");
                _logDevice($conn, $device, 'ON', 'auto', "Auto: {$sensor} {$operator} {$threshold} (now {$sensorVal})");
                $row[$device] = 1;
            } elseif (!$condMet && $current === 1) {
                $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
                _logDevice($conn, $device, 'OFF', 'auto', "Auto: {$sensor} no longer {$operator} {$threshold} (now {$sensorVal})");
                $row[$device] = 0;
            }
        }
    }

    // ══════════════════════════════════════════════════════
    // STEP 4 — SCHEDULED TASKS (only when not in manual mode)
    // ══════════════════════════════════════════════════════
    if (!$manualMode) {
        $now       = new DateTime($timestamp);
        $isWeekend = in_array(strtolower($now->format('l')), ['saturday','sunday']);

        $schedules = [];
        $r = $conn->query("SELECT * FROM scheduled_tasks WHERE enabled=1");
        if ($r) while ($s = $r->fetch_assoc()) $schedules[] = $s;

        foreach ($schedules as $sched) {
            $device   = $sched['device'];
            $days     = $sched['days'];
            $dayMatch = ($days==='daily') || ($days==='weekdays' && !$isWeekend) || ($days==='weekends' && $isWeekend);
            if (!$dayMatch) continue;

            $start    = new DateTime($now->format('Y-m-d') . ' ' . $sched['run_time']);
            $end      = (clone $start)->modify("+{$sched['duration_minutes']} minutes");
            $inWindow = ($now >= $start && $now <= $end);
            $current  = (int)($row[$device] ?? 0);

            if ($inWindow && $current === 0) {
                $conn->query("UPDATE device_status SET {$device}=1 WHERE id=1");
                _logDevice($conn, $device, 'ON', 'schedule', "Schedule: {$sched['run_time']} for {$sched['duration_minutes']}min ({$days})");
                $row[$device] = 1;
            } elseif (!$inWindow && $current === 1) {
                $last = $conn->query("SELECT trigger_type FROM device_logs WHERE device='{$device}' AND action='ON' ORDER BY logged_at DESC LIMIT 1");
                if ($last && ($lr = $last->fetch_assoc()) && $lr['trigger_type'] === 'schedule') {
                    $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
                    _logDevice($conn, $device, 'OFF', 'schedule', "Schedule ended: {$sched['run_time']} + {$sched['duration_minutes']}min");
                    $row[$device] = 0;
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════
    // STEP 5 — SET BUZZER FLAG for ESP32
    // ESP32 reads this on its next poll via get_device_status.php
    // Auto-clears each cycle unless a new fault fires
    // ══════════════════════════════════════════════════════
    $conn->query("UPDATE device_status SET buzzer=" . ($buzzerOn ? 1 : 0) . " WHERE id=1");
}

function _logDevice($conn, $device, $action, $triggerType, $detail) {
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
}
?>