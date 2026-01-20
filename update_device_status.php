<?php
// update_device_status.php
header('Content-Type: application/json');
include 'includes/db_connect.php';

// Read JSON body or form-encoded POST
$input = json_decode(file_get_contents('php://input'), true);

// Fallback to POST form if not JSON
if (!$input) {
    $input = $_POST;
}

// Also handle GET parameters
if (!$input) {
    $input = $_GET;
}

// Ensure row id = 1 exists
$check = $conn->query("SELECT id FROM device_status WHERE id = 1 LIMIT 1");
if ($check->num_rows == 0) {
    // Create initial row if missing
    $conn->query("INSERT INTO device_status (id, manual_mode, mist, fan, heater, sprayer, exhaust) VALUES (1,0,0,0,0,0,0)");
}

// Handle special GET parameters
if (isset($input['device'])) {
    $device = $input['device'];
    // Get current status
    $current = $conn->query("SELECT $device FROM device_status WHERE id = 1 LIMIT 1")->fetch_assoc();
    $new_value = $current[$device] ? 0 : 1; // toggle
    $input[$device] = $new_value;
}

if (isset($input['mode'])) {
    $input['manual_mode'] = intval($input['mode']);
}

// Pull values (default to 0 if not provided)
$manual = isset($input['manual_mode']) ? intval($input['manual_mode']) : null;
$mist   = isset($input['mist'])        ? intval($input['mist']) : null;
$fan    = isset($input['fan'])         ? intval($input['fan']) : null;
$heater = isset($input['heater'])      ? intval($input['heater']) : null;
$sprayer= isset($input['sprayer'])     ? intval($input['sprayer']) : null;
$exhaust= isset($input['exhaust'])     ? intval($input['exhaust']) : null;

// Build dynamic update (only update provided fields)
$fields = [];
$params = [];
$types  = '';

if ($manual !== null) { $fields[] = 'manual_mode = ?'; $params[] = $manual; $types .= 'i'; }
if ($mist   !== null) { $fields[] = 'mist = ?';        $params[] = $mist;   $types .= 'i'; }
if ($fan    !== null) { $fields[] = 'fan = ?';         $params[] = $fan;    $types .= 'i'; }
if ($heater !== null) { $fields[] = 'heater = ?';      $params[] = $heater; $types .= 'i'; }
if ($sprayer!== null) { $fields[] = 'sprayer = ?';     $params[] = $sprayer;$types .= 'i'; }
if ($exhaust!== null) { $fields[] = 'exhaust = ?';     $params[] = $exhaust;$types .= 'i'; }

if (count($fields) == 0) {
    echo json_encode(['success' => false, 'message' => 'No values provided']);
    exit;
}

$sql = "UPDATE device_status SET " . implode(", ", $fields) . " WHERE id = 1 LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed', 'error' => $conn->error]);
    exit;
}

// bind params dynamically
$bind_names[] = $types;
for ($i=0; $i<count($params); $i++) {
    $bind_name = 'bind' . $i;
    $$bind_name = $params[$i];
    $bind_names[] = &$$bind_name;
}
call_user_func_array(array($stmt,'bind_param'), $bind_names);

$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Execute failed', 'error' => $conn->error]);
    exit;
}

// Return updated row
$row = $conn->query("SELECT manual_mode, mist, fan, heater, sprayer, exhaust, updated_at FROM device_status WHERE id = 1 LIMIT 1")->fetch_assoc();

echo json_encode(['success' => true, 'data' => $row]);

$conn->close();
?>
