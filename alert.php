<?php
include('includes/db_connect.php');
$result = $conn->query("SELECT * FROM alerts ORDER BY id DESC LIMIT 5");
while($row = $result->fetch_assoc()) {
  echo "<p><b>".$row['type']."</b>: ".$row['message']." (".$row['timestamp'].")</p>";
}
?>
