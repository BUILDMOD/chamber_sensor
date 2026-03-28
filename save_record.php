<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();
header('Content-Type: application/json');

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/includes/db_connect.php';
    ob_clean();
    if (!isset($conn) || $conn->connect_error) throw new Exception('DB connection failed');

    if (empty($_SESSION['user'])) {
        echo json_encode(['success'=>false,'error'=>'Not logged in']);
        exit;
    }

    // ── POST: Save a harvest record ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $record_date   = $_POST['record_date']   ?? '';
        $mushroom_count = intval($_POST['mushroom_count'] ?? 0);
        $growth_stage  = $_POST['growth_stage']  ?? 'Harvest';
        $notes         = trim($_POST['notes']    ?? '');
        $created_by    = $_SESSION['user'] ?? '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $record_date)) {
            echo json_encode(['success'=>false,'error'=>'Invalid date']);
            exit;
        }
        if ($mushroom_count < 0) {
            echo json_encode(['success'=>false,'error'=>'Invalid weight']);
            exit;
        }

        $conn->query("CREATE TABLE IF NOT EXISTS mushroom_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_date DATE NOT NULL,
            mushroom_count INT NOT NULL DEFAULT 0,
            growth_stage VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $conn->prepare("INSERT INTO mushroom_records (record_date, mushroom_count, growth_stage, notes) VALUES (?, ?, ?, ?)");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        $stmt->bind_param('siis', $record_date, $mushroom_count, $growth_stage, $notes);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'id'=>$stmt->insert_id]);
        } else {
            throw new Exception('Insert failed: ' . $stmt->error);
        }
        $stmt->close();
        exit;
    }

    // ── GET: Fetch records ──
    $type  = $_GET['type']  ?? '';
    $month = $_GET['month'] ?? date('Y-m');
    $day   = $_GET['day']   ?? '';

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        echo json_encode(['success'=>false,'error'=>'Invalid month']);
        exit;
    }

    $month_start = $month . '-01';
    $month_end   = date('Y-m-t', strtotime($month_start));

    if ($type === 'records') {
        $conn->query("CREATE TABLE IF NOT EXISTS mushroom_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            record_date DATE NOT NULL,
            mushroom_count INT NOT NULL DEFAULT 0,
            growth_stage VARCHAR(50) DEFAULT '',
            notes TEXT DEFAULT '',
            created_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $r = $conn->query("SELECT record_date, mushroom_count, growth_stage, notes
                           FROM mushroom_records
                           WHERE record_date BETWEEN '$month_start' AND '$month_end'
                           ORDER BY record_date ASC, id ASC");
        $data = [];
        if ($r) while ($row = $r->fetch_assoc()) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);

    } elseif ($type === 'camera') {
        $date = $day ?: date('Y-m-d');
        $r = $conn->query("SELECT id, image_path, captured_at, analysis_result
                           FROM camera_images
                           WHERE DATE(captured_at) = '$date'
                           ORDER BY captured_at DESC LIMIT 20");
        $data = [];
        if ($r) while ($row = $r->fetch_assoc()) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);

    } else {
        echo json_encode(['success'=>false,'error'=>'Unknown type']);
    }

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}