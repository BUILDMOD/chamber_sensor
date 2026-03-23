<?php
// auto_engine.php
date_default_timezone_set('Asia/Manila');

define('FAULT_NO_RESPONSE_MINUTES_DEFAULT', 5);
define('FAULT_MAX_ON_MINUTES_DEFAULT', 60);

$DEVICE_SENSOR_EXPECTATION = [
    'mist'    => ['sensor' => 'humidity',    'direction' => 'rising'],
    // sprayer is schedule-based only — not sensor-based
    'heater'  => ['sensor' => 'temperature', 'direction' => 'rising'],
    'fan'     => ['sensor' => 'temperature', 'direction' => 'falling'],
];

function runAutoEngine($conn, $temperature, $humidity, $timestamp) {
    $ss_r = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    $ss = [];
    if ($ss_r) while ($row_ss = $ss_r->fetch_assoc()) $ss[$row_ss['setting_key']] = $row_ss['setting_value'];
    $FAULT_NO_RESPONSE_MINUTES = intval($ss['fault_timeout_min'] ?? FAULT_NO_RESPONSE_MINUTES_DEFAULT);
    $FAULT_MAX_ON_MINUTES      = intval($ss['stuck_timeout_min'] ?? FAULT_MAX_ON_MINUTES_DEFAULT);

    global $DEVICE_SENSOR_EXPECTATION;

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

    // ── Skip fault detection if sensor data is stale (offline) ──
    $lastTs = $conn->query("SELECT timestamp FROM sensor_data ORDER BY id DESC LIMIT 1");
    $sensorOnline = false;
    if ($lastTs && $row_ts = $lastTs->fetch_assoc()) {
        $ageMin = (time() - strtotime($row_ts['timestamp'])) / 60;
        $sensorOnline = ($ageMin < 5);
    }
    // If sensor is offline, clear buzzer and skip fault/auto logic
    if (!$sensorOnline) {
        $conn->query("UPDATE device_status SET buzzer=0 WHERE id=1");
        return;
    }

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

    // STEP 1 — EMERGENCY SHUTOFF (runs in both auto and manual mode)
    $emergencies = [];
    if ($temperature > $thr['emerg_temp_high'])
        $emergencies['heater']  = "Emergency: Temp {$temperature}°C critically high (>{$thr['emerg_temp_high']}°C) — Heater forced OFF";
    if ($humidity > $thr['emerg_hum_high']) {
        $emergencies['mist']    = "Emergency: Humidity {$humidity}% critically high (>{$thr['emerg_hum_high']}%) — Mist forced OFF";
        $emergencies['sprayer'] = "Emergency: Humidity {$humidity}% critically high (>{$thr['emerg_hum_high']}%) — Sprayer forced OFF";
    }
    if ($temperature < $thr['emerg_temp_low'])
        $emergencies['fan']     = "Emergency: Temp {$temperature}°C critically low (<{$thr['emerg_temp_low']}°C) — Fan forced OFF";
    // Turn ON exhaust during high temp emergency to help ventilate
    if ($temperature > $thr['emerg_temp_high'] && (int)($row['exhaust'] ?? 0) === 0) {
        $conn->query("UPDATE device_status SET exhaust=1 WHERE id=1");
        _logDevice($conn, 'exhaust', 'ON', 'emergency', "Emergency: Temp {$temperature}°C critically high — Exhaust forced ON");
        $row['exhaust'] = 1;
    }

    foreach ($emergencies as $device => $reason) {
        if ((int)($row[$device] ?? 0) === 1) {
            $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
            _logDevice($conn, $device, 'OFF', 'emergency', $reason);
            _logAlert($conn, 'device', 'critical', $reason, in_array($device,['heater','fan']) ? $temperature : $humidity);
            $row[$device] = 0;
            $buzzerOn = true;
        }
    }

    // ── STEP 2 — FAULT DETECTION (skip in manual mode — user controls devices manually) ──
    if (!$manualMode) {
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

            if ($onMinutes >= $FAULT_MAX_ON_MINUTES) {
                $faultType   = 'stuck_on';
                $faultReason = "Fault: {$device} ON for " . round($onMinutes) . " min (max " . $FAULT_MAX_ON_MINUTES . " min) — forced OFF";
            }

            if (!$faultType && $onMinutes >= $FAULT_NO_RESPONSE_MINUTES) {
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
    } // end !$manualMode STEP 2

    // STEP 3 — AUTO MODE RULES (sensor-based: ON when condition met, OFF when back in range)
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
            $condMet   = ($operator === 'below' && $sensorVal < $threshold)
                      || ($operator === 'above' && $sensorVal > $threshold);

            if ($condMet && $current === 0) {
                $conn->query("UPDATE device_status SET {$device}=1 WHERE id=1");
                _logDevice($conn, $device, 'ON', 'auto', "Auto: {$sensor} {$operator} {$threshold} (now {$sensorVal})");
                $row[$device] = 1;
            } elseif (!$condMet && $current === 1) {
                // Only turn OFF if it was turned ON by auto (not manual or schedule)
                $lastTrig = $conn->query("SELECT trigger_type FROM device_logs WHERE device='{$device}' AND action='ON' ORDER BY logged_at DESC LIMIT 1");
                if ($lastTrig && ($lr = $lastTrig->fetch_assoc()) && $lr['trigger_type'] === 'auto') {
                    $conn->query("UPDATE device_status SET {$device}=0 WHERE id=1");
                    _logDevice($conn, $device, 'OFF', 'auto', "Auto: {$sensor} back in range (now {$sensorVal}, threshold {$threshold})");
                    $row[$device] = 0;
                }
            }
        }
    }

    // STEP 4 — SCHEDULED TASKS
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

            $start      = new DateTime($now->format('Y-m-d') . ' ' . $sched['run_time']);
            $durMinutes = intval($sched['duration_minutes'] ?? 0);
            $durSeconds = intval($sched['duration_seconds'] ?? 30);
            $totalSeconds = ($durMinutes * 60) + $durSeconds;
            if ($totalSeconds <= 0) $totalSeconds = 30;
            $end        = (clone $start)->modify("+{$totalSeconds} seconds");
            $inWindow   = ($now >= $start && $now <= $end);
            $current    = (int)($row[$device] ?? 0);

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

    // STEP 5 — SET BUZZER FLAG
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

    // Read settings from both tables
    $ns = []; $ss = [];
    $r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
    if ($r) while ($row=$r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
    $r2 = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    if ($r2) while ($row=$r2->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];

    // Check notify prefs from system_settings (where settings.php saves them)
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

        // Per-type throttle key — prevents one alert type from blocking another
        $throttle_key = $type . '_' . $recipient;
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

            $icons      = ['device'=>'🔧','system'=>'📡','temperature'=>'🌡️','humidity'=>'💧'];
            $icon       = $icons[$type] ?? '⚠️';
            $colorHex   = ($severity === 'critical') ? '#d93025' : '#b45309';
            $colorLt    = ($severity === 'critical') ? '#fff0f0' : '#fef3c7';
            $borderCol  = ($severity === 'critical') ? '#e53935' : '#f9a825';
            $typeLabel  = ucfirst($type);
            $sevLabel   = ucfirst($severity);
            $detectedAt = date('M j, Y h:i:s A');

            $subj = "$icon MushroomOS — {$typeLabel} Alert";
            $body = "
                <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                    <div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                        <h2 style='color:#c8e8b8;margin:0;font-size:20px;'>&#127812; MushroomOS — {$typeLabel} Alert</h2>
                        <p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                    </div>
                    <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                        <p style='background:{$colorLt};border-left:4px solid {$borderCol};padding:12px 16px;border-radius:4px;color:{$colorHex};font-weight:600;margin:0 0 16px;'>
                            &#9888; {$sevLabel}: {$typeLabel} alert detected.
                        </p>
                        <p style='color:#555;font-size:13px;line-height:1.6;margin:0 0 16px;'>" . nl2br(htmlspecialchars($message)) . "</p>
                        <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'>
                            <tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;width:40%;'>Type</td><td style='padding:8px 12px;font-weight:600;'>{$icon} {$typeLabel}</td></tr>
                            <tr><td style='padding:8px 12px;color:#6e7681;'>Severity</td><td style='padding:8px 12px;font-weight:600;color:{$colorHex};'>{$sevLabel}</td></tr>
                            <tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Detected At</td><td style='padding:8px 12px;font-weight:600;'>{$detectedAt}</td></tr>
                        </table>
                        <p style='color:#555;font-size:13px;'>Please check your chamber and devices immediately.</p>
                        <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                        <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm</p>
                    </div>
                </div>";

            sendEmail($recipient, $subj, $body);

            $now = date('Y-m-d H:i:s');
            $us = $conn->prepare("INSERT INTO email_throttle (email,last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
            if ($us) { $us->bind_param("sss",$throttle_key,$now,$now); $us->execute(); $us->close(); }
        }
    }
}
?>