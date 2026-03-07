<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . "/PHPMailer-master/src/PHPMailer.php";
require __DIR__ . "/PHPMailer-master/src/SMTP.php";
require __DIR__ . "/PHPMailer-master/src/Exception.php";

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    // ── Load SMTP credentials from DB notification_settings ──
    // Falls back to config values if DB is unavailable
    $smtpUser = 'angelodominguiano12345@gmail.com';
    $smtpPass = 'zmxf wghz zmwe eoqb'; // ⚠️ Replace with new App Password
    $fromName = 'MushroomOS · J.WHO Mushroom Farm';

    try {
        // Try to load from DB so owner can update credentials in Settings
        include_once __DIR__ . '/includes/db_connect.php';
        if (isset($conn) && $conn) {
            $ns = [];
            $r = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
            if ($r) while ($row = $r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
            if (!empty($ns['smtp_username'])) $smtpUser = $ns['smtp_username'];
            if (!empty($ns['smtp_password'])) $smtpPass = $ns['smtp_password'];
            if (!empty($ns['smtp_from_name'])) $fromName = $ns['smtp_from_name'];
        }
    } catch (Exception $e) { /* use defaults above */ }

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0;

        // SENDER — uses logged-in owner's Gmail and MushroomOS display name
        $mail->setFrom($smtpUser, $fromName);

        // RECEIVER
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return "SUCCESS";
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return "ERROR: " . $mail->ErrorInfo;
    }
}
?>