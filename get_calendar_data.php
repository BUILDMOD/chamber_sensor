<?php
include('includes/db_connect.php');
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$type  = $_GET['type']  ?? 'records'; // 'records' or 'camera'
$month = $_GET['month'] ?? date('Y-m'); // format: 2025-01

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'error' => 'Invalid month']);
    exit;
}

if ($type === 'records') {
    // Mushroom records for given month
    $stmt = $conn->prepare("SELECT record_date, mushroom_count, growth_stage, notes FROM mushroom_records WHERE DATE_FORMAT(record_date,'%Y-%m') = ? ORDER BY record_date ASC");
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    echo json_encode(['success' => true, 'month' => $month, 'data' => $rows]);

} elseif ($type === 'camera') {
    // Camera captures grouped by day for given month
    $stmt = $conn->prepare("SELECT DATE(analyzed_at) as day, COUNT(*) as count, MAX(harvest_status) as latest_status, MAX(confidence_score) as max_confidence, MAX(diameter_cm) as max_diameter FROM image_analysis WHERE DATE_FORMAT(analyzed_at,'%Y-%m') = ? GROUP BY DATE(analyzed_at) ORDER BY day ASC");
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    // Also get images for a specific day if requested
    $day = $_GET['day'] ?? null;
    $dayImages = [];
    if ($day && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $si = $conn->prepare("SELECT image_path, diameter_cm, harvest_status, confidence_score, analyzed_at FROM image_analysis WHERE DATE(analyzed_at) = ? ORDER BY analyzed_at DESC LIMIT 12");
        $si->bind_param("s", $day);
        $si->execute();
        $ri = $si->get_result();
        while ($r = $ri->fetch_assoc()) {
            $r['image_path'] = ltrim($r['image_path'], './');
            $dayImages[] = $r;
        }
        $si->close();
    }

    echo json_encode(['success' => true, 'month' => $month, 'data' => $rows, 'day_images' => $dayImages]);
}
$conn->close();