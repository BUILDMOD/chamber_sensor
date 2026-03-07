<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // ← aliased to avoid conflict with base \Exception

require __DIR__ . "/PHPMailer-master/src/PHPMailer.php";
require __DIR__ . "/PHPMailer-master/src/SMTP.php";
require __DIR__ . "/PHPMailer-master/src/Exception.php";

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    // ── Defaults (used if DB is unavailable) ──
    $smtpUser = 'angelodominguiano12345@gmail.com';
    $smtpPass = 'dxeikfmcjazygwcv'; // App Password without spaces — Gmail accepts both
    $fromName = 'MushroomOS · J.WHO Mushroom Farm';

    // ── Try to load credentials from DB ──
    // Uses base \Exception so it never conflicts with PHPMailerException
    try {
        include_once __DIR__ . '/includes/db_connect.php';
        if (isset($conn) && $conn) {
            $ns = [];
            $r = $conn->query("SELECT setting_key, setting_value FROM notification_settings");
            if ($r) while ($row = $r->fetch_assoc()) $ns[$row['setting_key']] = $row['setting_value'];
            if (!empty($ns['smtp_user']))      $smtpUser = $ns['smtp_user'];
            if (!empty($ns['smtp_pass']))      $smtpPass = preg_replace('/\s+/', '', $ns['smtp_pass']); // strip spaces — Gmail App Passwords work with or without
            if (!empty($ns['smtp_from_name'])) $fromName = $ns['smtp_from_name'];
        }
    } catch (\Exception $e) {
        error_log("send_email DB load failed: " . $e->getMessage());
        // Continue with defaults
    }

    // ── Send via Gmail SMTP ──
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0; // Set to 2 temporarily if emails still don't arrive

        $mail->setFrom($smtpUser, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return "SUCCESS";

    } catch (PHPMailerException $e) {
        error_log("PHPMailer Error sending to {$to}: " . $mail->ErrorInfo);
        return "ERROR: " . $mail->ErrorInfo;
    }
}
?>