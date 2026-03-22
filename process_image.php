<?php
date_default_timezone_set('Asia/Manila');
/**
 * Image Processing API for Mushroom Analysis
 * Uses Claude Vision API to determine harvest readiness.
 */

// ── DB Connection ──
if (getenv('MYSQLHOST')) {
    $servername = getenv('MYSQLHOST');
    $username   = getenv('MYSQLUSER');
    $password   = getenv('MYSQLPASSWORD');
    $dbname     = getenv('MYSQLDATABASE');
    $port       = getenv('MYSQLPORT') ?: 3306;
} else {
    $servername = "localhost";
    $username   = "root";
    $password   = "";
    $dbname     = "mushroom_system";
    $port       = 3306;
}
$conn = new mysqli($servername, $username, $password, $dbname, $port);
if ($conn->connect_error) {
    die(json_encode(["success" => false, "error" => "Database connection failed: " . $conn->connect_error]));
}

// ── Create image_analysis table if not exists ──
$conn->query("CREATE TABLE IF NOT EXISTS image_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(500) NOT NULL,
    diameter_cm FLOAT,
    estimated_size_cm FLOAT,
    harvest_status ENUM('Not Ready', 'Almost Ready', 'Ready for Harvest', 'Overripe') DEFAULT 'Not Ready',
    confidence_score FLOAT,
    analysis_notes TEXT,
    analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ── Load Anthropic API key from DB ──
function getAnthropicKey($conn) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='anthropic_api_key' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return trim($row['setting_value']);
    return '';
}

// ── Claude Vision Analysis ──
function analyzeWithClaude($imagePath, $apiKey) {
    if (!file_exists($imagePath)) {
        return fallbackAnalysis($imagePath, "Image file not found");
    }

    $imageData   = base64_encode(file_get_contents($imagePath));
    $imageInfo   = getimagesize($imagePath);
    $mimeType    = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';

    $prompt = <<<PROMPT
You are an expert mushroom cultivation assistant. Analyze this image of mushrooms in a cultivation chamber.

Determine the harvest readiness of the mushrooms visible in the image.

Respond ONLY with a valid JSON object — no explanation, no markdown, no extra text:
{
  "harvest_status": "Not Ready" | "Almost Ready" | "Ready for Harvest" | "Overripe",
  "diameter_cm": <estimated diameter of the largest mushroom cap in cm, as a number>,
  "confidence_score": <your confidence from 0 to 100 as a number>,
  "analysis_notes": "<brief 1-2 sentence explanation of what you observed>"
}

Guidelines:
- "Not Ready": Small pins or very young fruiting bodies, caps still tightly closed, diameter < 3 cm
- "Almost Ready": Caps beginning to open, veil still intact, diameter 3–5 cm
- "Ready for Harvest": Caps fully open but veil still intact or just breaking, diameter 5–8 cm — ideal harvest time
- "Overripe": Veil broken, caps fully flattened or edges curling up, spores may be dropping, diameter > 8 cm
- If no mushroom is visible, return harvest_status "Not Ready", diameter_cm 0, confidence_score 10
PROMPT;

    $payload = [
        "model"      => "claude-opus-4-5",
        "max_tokens" => 300,
        "messages"   => [[
            "role"    => "user",
            "content" => [
                [
                    "type"   => "image",
                    "source" => [
                        "type"       => "base64",
                        "media_type" => $mimeType,
                        "data"       => $imageData
                    ]
                ],
                [
                    "type" => "text",
                    "text" => $prompt
                ]
            ]
        ]]
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "x-api-key: $apiKey",
            "anthropic-version: 2023-06-01"
        ],
        CURLOPT_TIMEOUT        => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        return fallbackAnalysis($imagePath, "Claude API error (HTTP $httpCode)");
    }

    $decoded = json_decode($response, true);
    $text    = $decoded['content'][0]['text'] ?? '';

    // Strip markdown fences if present
    $text = preg_replace('/```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/```/', '', $text);
    $text = trim($text);

    $result = json_decode($text, true);

    if (!$result || !isset($result['harvest_status'])) {
        return fallbackAnalysis($imagePath, "Could not parse Claude response");
    }

    // Sanitize values
    $validStatuses = ['Not Ready', 'Almost Ready', 'Ready for Harvest', 'Overripe'];
    if (!in_array($result['harvest_status'], $validStatuses)) {
        $result['harvest_status'] = 'Not Ready';
    }

    return [
        "diameter_cm"       => round(floatval($result['diameter_cm'] ?? 0), 2),
        "estimated_size_cm" => round(floatval($result['diameter_cm'] ?? 0) * floatval($result['diameter_cm'] ?? 0) * 0.785, 2),
        "harvest_status"    => $result['harvest_status'],
        "confidence_score"  => round(floatval($result['confidence_score'] ?? 50), 1),
        "analysis_notes"    => substr($result['analysis_notes'] ?? 'Analyzed by Claude AI.', 0, 500)
    ];
}

// ── Fallback: GD-based analysis (used if no API key or API fails) ──
function fallbackAnalysis($imagePath, $reason = '') {
    $imageInfo = @getimagesize($imagePath);
    if (!$imageInfo) {
        return [
            "diameter_cm" => 0, "estimated_size_cm" => 0,
            "harvest_status" => "Not Ready", "confidence_score" => 0,
            "analysis_notes" => "Could not read image. $reason"
        ];
    }
    $width    = $imageInfo[0];
    $height   = $imageInfo[1];
    $fileSize = filesize($imagePath);
    $shortSide      = min($width, $height);
    $mushroomPixels = $shortSide * 0.50;
    $cmPerPixel     = 20.0 / max($width, 1);
    $diameterCm     = $mushroomPixels * $cmPerPixel;
    if ($fileSize < 6000)      $diameterCm *= 0.7;
    elseif ($fileSize > 20000) $diameterCm *= 1.2;
    $diameterCm    = round(max(1.0, min($diameterCm, 15.0)), 2);
    $sizeCm        = round($diameterCm * $diameterCm * 0.785, 2);
    $harvestStatus = determineHarvestStatus($diameterCm);
    $confidence    = ($fileSize > 5000 && $width > 0) ? 45 : 20;
    $note = "Analyzed via fallback method (GD). " . ($reason ? "Reason: $reason. " : "") . "Resolution: {$width}x{$height}";
    return [
        "diameter_cm"       => $diameterCm,
        "estimated_size_cm" => $sizeCm,
        "harvest_status"    => $harvestStatus,
        "confidence_score"  => $confidence,
        "analysis_notes"    => $note
    ];
}

function determineHarvestStatus($diameterCm) {
    if ($diameterCm < 3)      return "Not Ready";
    elseif ($diameterCm < 5)  return "Almost Ready";
    elseif ($diameterCm <= 8) return "Ready for Harvest";
    else                      return "Overripe";
}

// ── Handle POST: new image uploaded ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    header('Content-Type: application/json');

    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "error" => "File upload error: " . $file['error']]);
        exit;
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode(["success" => false, "error" => "Invalid file type."]);
        exit;
    }

    $uploadDir = 'uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $extension  = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename   = 'mushroom_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode(["success" => false, "error" => "Failed to save uploaded file"]);
        exit;
    }

    // ── Analyze: Claude if key exists, else fallback ──
    $apiKey = getAnthropicKey($conn);
    if ($apiKey) {
        $result = analyzeWithClaude($targetPath, $apiKey);
    } else {
        $result = fallbackAnalysis($targetPath, "No Anthropic API key configured. Go to Settings to add one.");
    }

    // ── Save to DB ──
    $stmt = $conn->prepare("INSERT INTO image_analysis (image_path, diameter_cm, estimated_size_cm, harvest_status, confidence_score, analysis_notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sddsds",
        $targetPath,
        $result['diameter_cm'],
        $result['estimated_size_cm'],
        $result['harvest_status'],
        $result['confidence_score'],
        $result['analysis_notes']
    );

    if ($stmt->execute()) {
        $result['id']         = $conn->insert_id;
        $result['image_path'] = $targetPath;
        $result['success']    = true;

        // ── Email notification ──
        if (in_array($result['harvest_status'], ['Ready for Harvest', 'Overripe'])) {
            _sendHarvestEmail($conn, $result['harvest_status'], $result['diameter_cm'], $targetPath);
        }

        echo json_encode($result);
    } else {
        echo json_encode(["success" => false, "error" => "Database error: " . $conn->error]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// ── Harvest Email Notification (unchanged) ──
function _sendHarvestEmail($conn, $status, $diameter, $imagePath) {
    $ns = []; $ss = [];
    $r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
    if ($r) while ($row = $r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
    $r2 = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    if ($r2) while ($row = $r2->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];

    $cooldown_min = 10;
    $throttle_key = 'harvest_notify';

    $conn->query("CREATE TABLE IF NOT EXISTS email_throttle (
        email VARCHAR(255) PRIMARY KEY,
        last_sent DATETIME NOT NULL
    )");
    $tq = $conn->prepare("SELECT last_sent FROM email_throttle WHERE email=?");
    if ($tq) {
        $tq->bind_param("s", $throttle_key);
        $tq->execute();
        $tr = $tq->get_result();
        if ($tr->num_rows > 0) {
            $last = strtotime($tr->fetch_assoc()['last_sent']);
            if ((time() - $last) < ($cooldown_min * 60)) return;
        }
        $tq->close();
    }

    $recipient = $ns['smtp_to_email'] ?? '';
    $owner = $conn->query("SELECT email FROM users WHERE role='owner' LIMIT 1");
    if ($owner && $row = $owner->fetch_assoc()) $recipient = $row['email'];
    if (!$recipient) return;

    if (file_exists(__DIR__ . '/send_email.php')) {
        require_once __DIR__ . '/send_email.php';

        $icon      = $status === 'Ready for Harvest' ? '🍄' : '⚠️';
        $colorHex  = $status === 'Ready for Harvest' ? '#1a9e5c' : '#b45309';
        $colorLt   = $status === 'Ready for Harvest' ? '#e6f7ef' : '#fef3c7';
        $borderCol = $status === 'Ready for Harvest' ? '#1a9e5c' : '#f9a825';
        $detectedAt = date('M j, Y h:i:s A');
        $subject   = "$icon MushroomOS — {$status} Detected";
        $actionMsg = $status === 'Ready for Harvest'
            ? "&#10003; Please harvest your mushrooms now for best quality."
            : "&#9888; Mushrooms are overripe — harvest immediately to prevent further deterioration.";
        $body = "
            <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
                <div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'>
                    <h2 style='color:#c8e8b8;margin:0;font-size:20px;'>&#127812; MushroomOS — {$status}</h2>
                    <p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p>
                </div>
                <div style='background:#ffffff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'>
                    <p style='background:{$colorLt};border-left:4px solid {$borderCol};padding:12px 16px;border-radius:4px;color:{$colorHex};font-weight:600;margin:0 0 16px;'>
                        {$icon} The chamber camera has detected a mushroom that is <strong>{$status}</strong>.
                    </p>
                    <table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'>
                        <tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;width:40%;'>Diameter</td><td style='padding:8px 12px;font-weight:600;'>{$diameter} cm</td></tr>
                        <tr><td style='padding:8px 12px;color:#6e7681;'>Status</td><td style='padding:8px 12px;font-weight:600;color:{$colorHex};'>{$status}</td></tr>
                        <tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Detected At</td><td style='padding:8px 12px;font-weight:600;'>{$detectedAt}</td></tr>
                    </table>
                    <p style='color:{$colorHex};font-size:13px;font-weight:600;'>{$actionMsg}</p>
                    <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                    <p style='font-size:12px;color:#aaa;text-align:center;margin:0;'>MushroomOS &middot; J.WHO Mushroom Farm &mdash; Automated Camera Alert</p>
                </div>
            </div>";

        sendEmail($recipient, $subject, $body);

        $now = date('Y-m-d H:i:s');
        $us = $conn->prepare("INSERT INTO email_throttle (email,last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
        if ($us) { $us->bind_param("sss", $throttle_key, $now, $now); $us->execute(); $us->close(); }
    }
}

// ── Handle GET: return recent analyses ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $limit = min(isset($_GET['limit']) ? intval($_GET['limit']) : 10, 50);

    $stmt = $conn->prepare("SELECT id, image_path, diameter_cm, estimated_size_cm, harvest_status, confidence_score, analysis_notes, analyzed_at FROM image_analysis ORDER BY analyzed_at DESC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $analyses = [];
    while ($row = $result->fetch_assoc()) {
        $row['image_path'] = ltrim($row['image_path'], './');
        $analyses[] = $row;
    }

    echo json_encode(["success" => true, "count" => count($analyses), "data" => $analyses]);

    $stmt->close();
    $conn->close();
    exit;
}
?>