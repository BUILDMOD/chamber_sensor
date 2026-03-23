<?php
date_default_timezone_set('Asia/Manila');
/**
 * Image Processing API for Mushroom Analysis
 * Uses Google Gemini Vision API (free tier) to determine:
 * - Harvest readiness: Not Ready / Almost Ready / Ready for Harvest / Overripe
 * - Contamination: Clean / Contaminated
 * - Estimated diameter
 * Falls back to GD-based analysis if no API key configured.
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

// ── Create / upgrade image_analysis table ──
$conn->query("CREATE TABLE IF NOT EXISTS image_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(500) NOT NULL,
    diameter_cm FLOAT,
    estimated_size_cm FLOAT,
    harvest_status ENUM('No Mushroom','Not Ready','Almost Ready','Ready for Harvest','Overripe') DEFAULT 'No Mushroom',
    contamination_status ENUM('Clean','Contaminated','Unknown') DEFAULT 'Unknown',
    confidence_score FLOAT,
    analysis_notes TEXT,
    analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Upgrade existing table
$conn->query("ALTER TABLE image_analysis MODIFY COLUMN harvest_status ENUM('No Mushroom','Not Ready','Almost Ready','Ready for Harvest','Overripe') DEFAULT 'No Mushroom'");
$conn->query("ALTER TABLE image_analysis ADD COLUMN IF NOT EXISTS contamination_status ENUM('Clean','Contaminated','Unknown') DEFAULT 'Unknown' AFTER harvest_status");
$conn->query("ALTER TABLE image_analysis MODIFY COLUMN contamination_status ENUM('Clean','Contaminated','Unknown') DEFAULT 'Unknown'");

// ── Load API keys from DB ──
function getGroqKey($conn) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='groq_api_key' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return trim($row['setting_value']);
    return '';
}
function getGeminiKey($conn) {
    $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='gemini_api_key' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return trim($row['setting_value']);
    return '';
}

// ── Groq Vision Analysis ──
function analyzeWithGroq($imagePath, $apiKey) {
    if (!file_exists($imagePath)) return fallbackAnalysis($imagePath, "Image file not found");

    $imageData = base64_encode(file_get_contents($imagePath));
    $imageInfo = getimagesize($imagePath);
    $mimeType  = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';

    $prompt = 'You are an expert mushroom cultivation assistant analyzing images from a mushroom growing chamber. Be STRICT and HONEST.

Respond ONLY with a valid JSON object — no explanation, no markdown:
{"harvest_status":"No Mushroom"|"Not Ready"|"Almost Ready"|"Ready for Harvest"|"Overripe","contamination_status":"Clean"|"Contaminated"|"Unknown","diameter_cm":<number>,"confidence_score":<0-100>,"analysis_notes":"<1-2 sentences>"}

HARVEST STATUS:
- "No Mushroom": No mushroom visible, unclear/blurry/dark image, or subject is NOT a mushroom
- "Not Ready": Pins or young fruiting bodies, caps tightly closed, < 3 cm
- "Almost Ready": Caps beginning to open, veil intact, 3-5 cm
- "Ready for Harvest": Caps fully open, veil intact or just breaking, 5-8 cm
- "Overripe": Veil broken, caps flat/curling, spores dropping, > 8 cm

CONTAMINATION:
- "Contaminated": Green, black, pink, or orange mold patches
- "Clean": Normal white/cream mycelium, healthy coloration
- "Unknown": Cannot determine

IMPORTANT: Only give confidence > 70 if mushroom is CLEARLY visible. When in doubt use "No Mushroom".';

    $payload = [
        "model"    => "meta-llama/llama-4-scout-17b-16e-instruct",
        "messages" => [[
            "role"    => "user",
            "content" => [
                [
                    "type"      => "image_url",
                    "image_url" => ["url" => "data:{$mimeType};base64,{$imageData}"]
                ],
                [
                    "type" => "text",
                    "text" => $prompt
                ]
            ]
        ]],
        "max_tokens"  => 300,
        "temperature" => 0.1
    ];

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_TIMEOUT        => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $errBody = $response ? json_decode($response, true) : null;
        $errMsg  = $errBody['error']['message'] ?? "HTTP $httpCode";
        return fallbackAnalysis($imagePath, "Groq API failed: $errMsg");
    }

    $decoded = json_decode($response, true);
    $text    = $decoded['choices'][0]['message']['content'] ?? '';
    $text    = trim(preg_replace('/```(?:json)?\s*|\s*```/', '', $text));
    $result  = json_decode($text, true);

    if (!$result || !isset($result['harvest_status'])) {
        return fallbackAnalysis($imagePath, "Could not parse Groq response");
    }

    $validHarvest = ['No Mushroom','Not Ready','Almost Ready','Ready for Harvest','Overripe'];
    if (!in_array($result['harvest_status'], $validHarvest)) $result['harvest_status'] = 'No Mushroom';

    $validContam = ['Clean','Contaminated','Unknown'];
    if (!in_array($result['contamination_status'] ?? '', $validContam)) $result['contamination_status'] = 'Unknown';

    $confidence = round(floatval($result['confidence_score'] ?? 0), 1);
    if ($confidence < 20) {
        $result['harvest_status']       = 'No Mushroom';
        $result['contamination_status'] = 'Unknown';
        $result['analysis_notes']       = "Low confidence ({$confidence}%) — image unclear or no mushroom visible. " . ($result['analysis_notes'] ?? '');
    }

    return [
        "diameter_cm"          => round(floatval($result['diameter_cm'] ?? 0), 2),
        "estimated_size_cm"    => round(pow(floatval($result['diameter_cm'] ?? 0) / 2, 2) * M_PI, 2),
        "harvest_status"       => $result['harvest_status'],
        "contamination_status" => $result['contamination_status'],
        "confidence_score"     => $confidence,
        "analysis_notes"       => substr(($result['analysis_notes'] ?? 'Analyzed by Groq AI.') . " [Model: llama-4-scout]", 0, 500)
    ];
}

// ── Gemini Vision Analysis ──
function analyzeWithGemini($imagePath, $apiKey) {
    if (!file_exists($imagePath)) return fallbackAnalysis($imagePath, "Image file not found");

    $imageData = base64_encode(file_get_contents($imagePath));
    $imageInfo = getimagesize($imagePath);
    $mimeType  = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';

    $prompt = 'You are an expert mushroom cultivation assistant analyzing images from a mushroom growing chamber. Be STRICT and HONEST in your assessment.

Respond ONLY with a valid JSON object — no explanation, no markdown, no extra text:
{"harvest_status":"No Mushroom"|"Not Ready"|"Almost Ready"|"Ready for Harvest"|"Overripe","contamination_status":"Clean"|"Contaminated"|"Unknown","diameter_cm":<number>,"confidence_score":<0-100>,"analysis_notes":"<1-2 sentences describing exactly what you see>"}

HARVEST STATUS rules — be strict:
- "No Mushroom": No mushroom visible, unclear image, blurry, dark, or the subject is NOT a mushroom (e.g. substrate only, equipment, wall, etc.)
- "Not Ready": Visible mushroom pins or young fruiting bodies, caps tightly closed, diameter < 3 cm
- "Almost Ready": Mushroom caps beginning to open, veil still intact, diameter 3-5 cm
- "Ready for Harvest": Caps fully open, veil intact or just breaking, diameter 5-8 cm
- "Overripe": Veil broken, caps flat or curling, spores dropping, diameter > 8 cm

CONTAMINATION rules:
- "Contaminated": Visible green, black, pink, or orange mold patches unrelated to normal mycelium
- "Clean": Normal white/cream mycelium, healthy coloration, no mold patches
- "Unknown": Cannot determine due to image quality, lighting, or no mushroom/mycelium visible

CONFIDENCE rules:
- If image is blurry, dark, or unclear: confidence_score < 20, harvest_status="No Mushroom"
- If no mushroom detected: confidence_score < 15, harvest_status="No Mushroom"
- Only give confidence > 70 if mushroom is clearly visible and identifiable

IMPORTANT: Do NOT guess "Ready for Harvest" or "Overripe" unless you clearly see a mushroom with open caps. When in doubt, use "No Mushroom" with low confidence.';

    $payload = [
        "contents" => [[
            "parts" => [
                ["inline_data" => ["mime_type" => $mimeType, "data" => $imageData]],
                ["text" => $prompt]
            ]
        ]],
        "generationConfig" => ["temperature" => 0.1, "maxOutputTokens" => 300]
    ];

    // Working endpoints — v1beta only for these models
    $endpoints = [
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . urlencode($apiKey),
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=" . urlencode($apiKey),
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-8b:generateContent?key=" . urlencode($apiKey),
    ];

    $response = null;
    $httpCode = 0;
    $usedModel = '';

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response && $httpCode === 200) {
            preg_match('/models\/([^:]+)/', $url, $m);
            $usedModel = $m[1] ?? 'gemini';
            break;
        }
        if ($httpCode === 429) {
            // Rate limited — wait 5 seconds and try next model
            sleep(5);
        }
        error_log("[Gemini] $url failed: HTTP $httpCode");
    }

    if (!$response || $httpCode !== 200) {
        // Log the actual error response for debugging
        $errBody = $response ? json_decode($response, true) : null;
        $errMsg  = $errBody['error']['message'] ?? "HTTP $httpCode";
        return fallbackAnalysis($imagePath, "Gemini failed: $errMsg");
    }

    $decoded = json_decode($response, true);
    $text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $text    = trim(preg_replace('/```(?:json)?\s*|\s*```/', '', $text));
    $result  = json_decode($text, true);

    if (!$result || !isset($result['harvest_status'])) return fallbackAnalysis($imagePath, "Could not parse Gemini response");

    // Sanitize harvest_status
    $validHarvest = ['No Mushroom','Not Ready','Almost Ready','Ready for Harvest','Overripe'];
    if (!in_array($result['harvest_status'], $validHarvest)) $result['harvest_status'] = 'No Mushroom';

    // Sanitize contamination_status
    $validContam = ['Clean','Contaminated','Unknown'];
    if (!in_array($result['contamination_status'] ?? '', $validContam)) $result['contamination_status'] = 'Unknown';

    // Confidence threshold — if very low confidence, override to No Mushroom
    $confidence = round(floatval($result['confidence_score'] ?? 0), 1);
    if ($confidence < 20 && !in_array($result['harvest_status'], ['No Mushroom'])) {
        $result['harvest_status']       = 'No Mushroom';
        $result['contamination_status'] = 'Unknown';
        $result['analysis_notes']       = 'Low confidence (' . $confidence . '%) — image may be unclear or no mushroom visible. ' . ($result['analysis_notes'] ?? '');
    }

    return [
        "diameter_cm"          => round(floatval($result['diameter_cm'] ?? 0), 2),
        "estimated_size_cm"    => round(pow(floatval($result['diameter_cm'] ?? 0) / 2, 2) * M_PI, 2),
        "harvest_status"       => $result['harvest_status'],
        "contamination_status" => $result['contamination_status'],
        "confidence_score"     => $confidence,
        "analysis_notes"       => substr(($result['analysis_notes'] ?? 'Analyzed by Gemini AI.') . " [Model: $usedModel]", 0, 500)
    ];
}

// ── Fallback: GD-based ──
function fallbackAnalysis($imagePath, $reason = '') {
    $imageInfo = @getimagesize($imagePath);
    if (!$imageInfo) return ["diameter_cm"=>0,"estimated_size_cm"=>0,"harvest_status"=>"No Mushroom","contamination_status"=>"Unknown","confidence_score"=>0,"analysis_notes"=>"Could not read image. $reason"];
    $width=$imageInfo[0]; $height=$imageInfo[1]; $fileSize=filesize($imagePath);
    $diameterCm = round(max(1.0, min((min($width,$height)*0.5)*(20.0/max($width,1))*($fileSize<6000?0.7:($fileSize>20000?1.2:1)),15.0)),2);
    return ["diameter_cm"=>$diameterCm,"estimated_size_cm"=>round(pow($diameterCm/2,2)*M_PI,2),"harvest_status"=>determineHarvestStatus($diameterCm),"contamination_status"=>"Unknown","confidence_score"=>15,"analysis_notes"=>"Fallback method (no API key or API error). $reason. Resolution: {$width}x{$height}"];
}

function determineHarvestStatus($d) {
    if ($d < 3) return "Not Ready";
    if ($d < 5) return "Almost Ready";
    if ($d <= 8) return "Ready for Harvest";
    return "Overripe";
}

// ── Handle POST: new image uploaded ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    header('Content-Type: application/json');

    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(["success"=>false,"error"=>"Upload error: ".$file['error']]); exit; }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mimeType, ['image/jpeg','image/png','image/gif','image/webp'])) { echo json_encode(["success"=>false,"error"=>"Invalid file type."]); exit; }

    $uploadDir = 'uploads/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    $filename   = 'mushroom_'.time().'_'.bin2hex(random_bytes(4)).'.'.pathinfo($file['name'],PATHINFO_EXTENSION ?: 'jpg');
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) { echo json_encode(["success"=>false,"error"=>"Failed to save file"]); exit; }

    // ── Analyze: Groq first, Gemini backup, fallback last ──
    $groqKey   = getGroqKey($conn);
    $geminiKey = getGeminiKey($conn);

    if ($groqKey) {
        $result = analyzeWithGroq($targetPath, $groqKey);
    } elseif ($geminiKey) {
        $result = analyzeWithGemini($targetPath, $geminiKey);
    } else {
        $result = fallbackAnalysis($targetPath, "No API key configured. Add groq_api_key or gemini_api_key in Settings.");
    }

    $stmt = $conn->prepare("INSERT INTO image_analysis (image_path,diameter_cm,estimated_size_cm,harvest_status,contamination_status,confidence_score,analysis_notes) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("sddssds",$targetPath,$result['diameter_cm'],$result['estimated_size_cm'],$result['harvest_status'],$result['contamination_status'],$result['confidence_score'],$result['analysis_notes']);

    if ($stmt->execute()) {
        $result['id'] = $conn->insert_id;
        $result['image_path'] = $targetPath;
        $result['success'] = true;
        if (in_array($result['harvest_status'],['Ready for Harvest','Overripe']) || $result['contamination_status']==='Contaminated') {
            _sendAnalysisEmail($conn, $result);
        }
        echo json_encode($result);
    } else {
        echo json_encode(["success"=>false,"error"=>"DB error: ".$conn->error]);
    }
    $stmt->close(); $conn->close(); exit;
}

// ── Email Notification ──
function _sendAnalysisEmail($conn, $result) {
    $ns=[]; $ss=[];
    $r=$conn->query("SELECT setting_key,setting_value FROM notification_settings"); if($r) while($row=$r->fetch_assoc()) $ns[$row['setting_key']]=$row['setting_value'];
    $r2=$conn->query("SELECT setting_key,setting_value FROM system_settings"); if($r2) while($row=$r2->fetch_assoc()) $ss[$row['setting_key']]=$row['setting_value'];
    $throttle_key='harvest_notify';
    $conn->query("CREATE TABLE IF NOT EXISTS email_throttle (email VARCHAR(255) PRIMARY KEY, last_sent DATETIME NOT NULL)");
    $tq=$conn->prepare("SELECT last_sent FROM email_throttle WHERE email=?");
    if($tq){$tq->bind_param("s",$throttle_key);$tq->execute();$tr=$tq->get_result();if($tr->num_rows>0){$last=strtotime($tr->fetch_assoc()['last_sent']);if((time()-$last)<600)return;}$tq->close();}
    $recipient='';
    $owner=$conn->query("SELECT email FROM users WHERE role='owner' LIMIT 1");
    if($owner&&$row=$owner->fetch_assoc()) $recipient=$row['email'];
    if(!$recipient) $recipient=$ns['smtp_to_email']??'';
    if(!$recipient||!file_exists(__DIR__.'/send_email.php')) return;
    require_once __DIR__.'/send_email.php';

    $isContam  = $result['contamination_status']==='Contaminated';
    $icon      = $isContam?'⚠️':'🍄';
    $title     = $isContam?'Contamination Detected':$result['harvest_status'];
    $colorHex  = $isContam?'#d93025':($result['harvest_status']==='Ready for Harvest'?'#1a9e5c':'#b45309');
    $colorLt   = $isContam?'#fdecea':($result['harvest_status']==='Ready for Harvest'?'#e6f7ef':'#fef3c7');
    $detectedAt= date('M j, Y h:i:s A');
    $subject   = "$icon MushroomOS — {$title}";
    $body="<div style='font-family:sans-serif;max-width:480px;margin:0 auto;'><div style='background:#2b4d30;padding:24px;border-radius:12px 12px 0 0;text-align:center;'><h2 style='color:#c8e8b8;margin:0;font-size:20px;'>🍄 MushroomOS — {$title}</h2><p style='color:rgba(200,232,184,0.6);font-size:12px;margin:6px 0 0;'>J.WHO Mushroom Farm</p></div><div style='background:#fff;padding:24px;border-radius:0 0 12px 12px;border:1px solid #e0e0e0;'><p style='background:{$colorLt};border-left:4px solid {$colorHex};padding:12px 16px;border-radius:4px;color:{$colorHex};font-weight:600;margin:0 0 16px;'>{$icon} <strong>{$title}</strong> detected by chamber camera.</p><table style='width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;'><tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;width:40%;'>Harvest Status</td><td style='padding:8px 12px;font-weight:600;color:{$colorHex};'>{$result['harvest_status']}</td></tr><tr><td style='padding:8px 12px;color:#6e7681;'>Contamination</td><td style='padding:8px 12px;font-weight:600;color:".($isContam?'#d93025':'#1a9e5c').";'>{$result['contamination_status']}</td></tr><tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Diameter</td><td style='padding:8px 12px;font-weight:600;'>{$result['diameter_cm']} cm</td></tr><tr><td style='padding:8px 12px;color:#6e7681;'>Confidence</td><td style='padding:8px 12px;font-weight:600;'>{$result['confidence_score']}%</td></tr><tr><td style='padding:8px 12px;background:#f7f8fa;color:#6e7681;'>Detected At</td><td style='padding:8px 12px;font-weight:600;'>{$detectedAt}</td></tr></table><p style='color:#555;font-size:13px;'>{$result['analysis_notes']}</p><hr style='border:none;border-top:1px solid #eee;margin:16px 0;'><p style='font-size:12px;color:#aaa;text-align:center;'>MushroomOS &middot; J.WHO Mushroom Farm</p></div></div>";
    sendEmail($recipient, $subject, $body);
    $now=date('Y-m-d H:i:s');
    $us=$conn->prepare("INSERT INTO email_throttle (email,last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
    if($us){$us->bind_param("sss",$throttle_key,$now,$now);$us->execute();$us->close();}
}

// ── Handle GET: return recent analyses ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $limit = min(isset($_GET['limit'])?intval($_GET['limit']):10, 50);
    $stmt=$conn->prepare("SELECT id,image_path,diameter_cm,estimated_size_cm,harvest_status,contamination_status,confidence_score,analysis_notes,analyzed_at FROM image_analysis ORDER BY analyzed_at DESC LIMIT ?");
    $stmt->bind_param("i",$limit); $stmt->execute();
    $result=$stmt->get_result();
    $analyses=[];
    while($row=$result->fetch_assoc()){$row['image_path']=ltrim($row['image_path'],'./');;$analyses[]=$row;}
    echo json_encode(["success"=>true,"count"=>count($analyses),"data"=>$analyses]);
    $stmt->close(); $conn->close(); exit;
}
?>