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
        // FIX: correct table = image_analysis, correct column = analyzed_at
        $r = $conn->query("SELECT id, image_path, analyzed_at,
                           diameter_cm, harvest_status, confidence_score
                           FROM image_analysis
                           WHERE DATE(analyzed_at) = '$date'
                           ORDER BY analyzed_at DESC");
        $day_images = [];
        if ($r) while ($row = $r->fetch_assoc()) $day_images[] = $row;
        echo json_encode(['success'=>true,'day_images'=>$day_images,'count'=>count($day_images)]);

    } elseif ($type === 'monthly_total') {
        $month = $_GET['month'] ?? date('Y-m');
        $month_start = $month . '-01';
        $month_end   = date('Y-m-t', strtotime($month_start));
        $r = $conn->query("SELECT COALESCE(SUM(mushroom_count),0) as total
                           FROM mushroom_records
                           WHERE record_date BETWEEN '$month_start' AND '$month_end'
                           AND growth_stage = 'Harvest'");
        $total = 0;
        if ($r) { $row = $r->fetch_assoc(); $total = intval($row['total']); }
        echo json_encode(['success'=>true,'total'=>$total,'month'=>$month]);

    } else {
        echo json_encode(['success'=>false,'error'=>'Unknown type']);
    }

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}