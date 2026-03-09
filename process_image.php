<?php
/**
 * Image Processing API for Mushroom Analysis
 * Receives images from devices, processes them to determine:
 * - Size/Diameter of mushroom
 * - Harvesting readiness status
 */

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "mushroom_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode([
        "success" => false,
        "error" => "Database connection failed: " . $conn->connect_error
    ]));
}

// Create image_analysis table if not exists
$createTableSql = "CREATE TABLE IF NOT EXISTS image_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(500) NOT NULL,
    diameter_cm FLOAT,
    estimated_size_cm FLOAT,
    harvest_status ENUM('Not Ready', 'Almost Ready', 'Ready for Harvest', 'Overripe') DEFAULT 'Not Ready',
    confidence_score FLOAT,
    analysis_notes TEXT,
    analyzed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($createTableSql);

// Create uploads directory if not exists
$uploadDir = 'uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Handle image upload via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    
    $file = $_FILES['image'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            "success" => false,
            "error" => "File upload error: " . $file['error']
        ]);
        exit;
    }
    
    // Check file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid file type. Allowed: JPEG, PNG, GIF, WEBP"
        ]);
        exit;
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'mushroom_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode([
            "success" => false,
            "error" => "Failed to save uploaded file"
        ]);
        exit;
    }
    
    // Process the image
    $result = processMushroomImage($targetPath);
    
    // Save analysis to database
    $stmt = $conn->prepare("INSERT INTO image_analysis (image_path, diameter_cm, estimated_size_cm, harvest_status, confidence_score, analysis_notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sddss", 
        $targetPath, 
        $result['diameter_cm'], 
        $result['estimated_size_cm'], 
        $result['harvest_status'], 
        $result['confidence_score'],
        $result['analysis_notes']
    );
    
    if ($stmt->execute()) {
        $result['id'] = $conn->insert_id;
        $result['image_path'] = $targetPath;
        $result['success'] = true;

        // ── Send harvest email notification if Ready for Harvest or Overripe ──
        if (in_array($result['harvest_status'], ['Ready for Harvest', 'Overripe'])) {
            _sendHarvestEmail($conn, $result['harvest_status'], $result['diameter_cm'], $targetPath);
        }

        echo json_encode($result);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Database error: " . $conn->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

// ── Harvest Email Notification ──
function _sendHarvestEmail($conn, $status, $diameter, $imagePath) {
    // Read notification settings
    $ns = []; $ss = [];
    $r = $conn->query("SELECT setting_key,setting_value FROM notification_settings");
    if ($r) while ($row = $r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
    $r2 = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    if ($r2) while ($row = $r2->fetch_assoc()) $ss[$row['setting_key']] = $row['setting_value'];

    // Use separate harvest cooldown — 10 minutes default
    $cooldown_min = 10;
    $throttle_key = 'harvest_notify';

    // Check throttle using harvest_notify key in email_throttle
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
            if ((time() - $last) < ($cooldown_min * 60)) return; // Still in cooldown
        }
        $tq->close();
    }

    // Get recipient — owner email
    $recipient = $ns['smtp_to_email'] ?? '';
    $owner = $conn->query("SELECT email FROM users WHERE role='owner' LIMIT 1");
    if ($owner && $row = $owner->fetch_assoc()) $recipient = $row['email'];
    if (!$recipient) return;

    // Send email
    if (file_exists(__DIR__ . '/send_email.php')) {
        require_once __DIR__ . '/send_email.php';

        $icon      = $status === 'Ready for Harvest' ? '🍄' : '⚠️';
        $colorHex  = $status === 'Ready for Harvest' ? '#1a9e5c' : '#b45309';
        $subject   = "$icon MushroomOS — {$status} Detected";
        $body      = "
            <div style='font-family:sans-serif;max-width:480px;'>
                <h2 style='color:{$colorHex};'>{$icon} {$status}</h2>
                <p>The chamber camera has detected a mushroom that is <strong>{$status}</strong>.</p>
                <table style='margin:12px 0;border-collapse:collapse;'>
                    <tr><td style='padding:4px 12px 4px 0;color:#666;'>Diameter</td><td><strong>{$diameter} cm</strong></td></tr>
                    <tr><td style='padding:4px 12px 4px 0;color:#666;'>Status</td><td><strong style='color:{$colorHex};'>{$status}</strong></td></tr>
                    <tr><td style='padding:4px 12px 4px 0;color:#666;'>Detected at</td><td>" . date('M j, Y h:i:s A') . "</td></tr>
                </table>
                " . ($status === 'Ready for Harvest'
                    ? "<p style='color:#1a9e5c;font-weight:600;'>✅ Please harvest your mushrooms now for best quality.</p>"
                    : "<p style='color:#b45309;font-weight:600;'>⚠️ Mushrooms are overripe — harvest immediately to prevent further deterioration.</p>"
                ) . "
                <hr style='border:none;border-top:1px solid #eee;margin:16px 0;'>
                <small style='color:#999;'>MushroomOS Cultivation System &mdash; Automated Camera Alert</small>
            </div>
        ";

        sendEmail($recipient, $subject, $body);

        // Update throttle
        $now = date('Y-m-d H:i:s');
        $us = $conn->prepare("INSERT INTO email_throttle (email,last_sent) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent=?");
        if ($us) { $us->bind_param("sss", $throttle_key, $now, $now); $us->execute(); $us->close(); }
    }
}

// Handle GET request - return recent analyses for dashboard display
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $limit = min($limit, 50);

    $sql = "SELECT id, image_path, diameter_cm, estimated_size_cm, harvest_status, confidence_score, analysis_notes, analyzed_at FROM image_analysis ORDER BY analyzed_at DESC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $analyses = [];
    while ($row = $result->fetch_assoc()) {
        // Make image_path web-accessible (strip leading path if needed)
        $row['image_path'] = ltrim($row['image_path'], './');
        $analyses[] = $row;
    }

    echo json_encode([
        "success" => true,
        "count"   => count($analyses),
        "data"    => $analyses
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

/**
 * Process mushroom image to estimate size and harvest readiness
 * Uses basic image processing with GD library
 */
function processMushroomImage($imagePath) {
    // Get image info
    $imageInfo = getimagesize($imagePath);
    if (!$imageInfo) {
        return [
            "diameter_cm" => 0,
            "estimated_size_cm" => 0,
            "harvest_status" => "Not Ready",
            "confidence_score" => 0,
            "analysis_notes" => "Could not read image"
        ];
    }
    
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $mimeType = $imageInfo['mime'];
    
    // Load image based on type
    switch ($mimeType) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($imagePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($imagePath);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($imagePath);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($imagePath);
            break;
        default:
            return [
                "diameter_cm" => 0,
                "estimated_size_cm" => 0,
                "harvest_status" => "Not Ready",
                "confidence_score" => 0,
                "analysis_notes" => "Unsupported image format"
            ];
    }
    
    if (!$image) {
        return [
            "diameter_cm" => 0,
            "estimated_size_cm" => 0,
            "harvest_status" => "Not Ready",
            "confidence_score" => 0,
            "analysis_notes" => "Could not create image resource"
        ];
    }
    
    // Resize for faster processing
    $maxDim = 300;
    $ratio = min($maxDim / $width, $maxDim / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Convert to grayscale
    $gray = imagecreatetruecolor($newWidth, $newHeight);
    imagefilter($resized, IMG_FILTER_GRAYSCALE);
    
    // Apply threshold to detect mushroom (assuming mushroom is darker than background)
    // This is a simple approach - in production, you'd use more sophisticated methods
    imagefilter($resized, IMG_FILTER_CONTRAST, 40);
    
    // Find bounding box of detected object
    $bounds = findMushroomBounds($resized, $newWidth, $newHeight);
    
    // Calculate diameter in pixels
    $pixelDiameter = max($bounds['width'], $bounds['height']);
    
    // Estimate real-world size (assuming reference object or calibration)
    // For demo, we'll estimate based on typical mushroom sizes
    // In production, you'd use a reference object (like a coin) in the image
    $cmPerPixel = estimateCmPerPixel($pixelDiameter, $newWidth, $newHeight);
    $diameterCm = $pixelDiameter * $cmPerPixel;
    $sizeCm = ($bounds['width'] * $bounds['height']) * pow($cmPerPixel, 2);
    
    // Determine harvest status based on diameter
    $harvestStatus = determineHarvestStatus($diameterCm);
    
    // Calculate confidence (based on how well-defined the detection is)
    $confidenceScore = calculateConfidence($bounds, $newWidth, $newHeight);
    
    // Clean up
    imagedestroy($image);
    imagedestroy($resized);
    
    return [
        "diameter_cm" => round($diameterCm, 2),
        "estimated_size_cm" => round($sizeCm, 2),
        "harvest_status" => $harvestStatus,
        "confidence_score" => round($confidenceScore * 100, 1),
        "analysis_notes" => "Image processed successfully. Detected object size: {$pixelDiameter}px. Reference scale: " . round($cmPerPixel, 4) . " cm/px"
    ];
}

/**
 * Find the bounding box of the mushroom in the image
 */
function findMushroomBounds($image, $width, $height) {
    $minX = $width;
    $minY = $height;
    $maxX = 0;
    $maxY = 0;
    $pixelCount = 0;
    
    // Scan for non-white pixels (assuming dark mushroom on light background)
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            // If pixel is darker than threshold (not white/light background)
            $brightness = ($r + $g + $b) / 3;
            if ($brightness < 200) {
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
                $pixelCount++;
            }
        }
    }
    
    // If no significant object found, return default
    if ($pixelCount < ($width * $height * 0.01)) {
        return [
            'x' => 0,
            'y' => 0,
            'width' => $width * 0.5,
            'height' => $height * 0.5,
            'pixelCount' => $pixelCount
        ];
    }
    
    return [
        'x' => $minX,
        'y' => $minY,
        'width' => $maxX - $minX,
        'height' => $maxY - $minY,
        'pixelCount' => $pixelCount
    ];
}

/**
 * Estimate centimeters per pixel based on typical mushroom sizes
 * In production, this would use a reference object
 */
function estimateCmPerPixel($pixelDiameter, $width, $height) {
    // Default estimation: assume mushroom fills about 50% of image width
    // This is a placeholder - in production, use calibration or reference object
    $typicalMushroomCm = 5.0; // Typical max diameter in cm
    $typicalPixelSize = $width * 0.5; // Assume mushroom is 50% of image width
    
    return $typicalMushroomCm / $typicalPixelSize;
}

/**
 * Determine harvest status based on diameter
 */
function determineHarvestStatus($diameterCm) {
    // Thresholds for different mushroom types (can be adjusted)
    // Typical oyster mushroom harvesting size: 4-8cm diameter
    if ($diameterCm < 3) {
        return "Not Ready";
    } elseif ($diameterCm < 5) {
        return "Almost Ready";
    } elseif ($diameterCm <= 8) {
        return "Ready for Harvest";
    } else {
        return "Overripe";
    }
}

/**
 * Calculate confidence score based on detection quality
 */
function calculateConfidence($bounds, $width, $height) {
    $imageArea = $width * $height;
    $objectArea = $bounds['width'] * $bounds['height'];
    
    // Higher confidence if object is reasonable size (not too small or too large)
    $sizeRatio = $objectArea / $imageArea;
    
    if ($sizeRatio < 0.01) {
        return 0.2; // Too small - likely noise
    } elseif ($sizeRatio > 0.9) {
        return 0.3; // Too large - likely edge case
    } elseif ($sizeRatio > 0.1 && $sizeRatio < 0.7) {
        return 0.85; // Good size range
    } else {
        return 0.6; // Acceptable
    }
}
?>