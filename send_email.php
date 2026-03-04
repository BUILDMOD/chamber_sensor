<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . "/PHPMailer-master/src/PHPMailer.php";
require __DIR__ . "/PHPMailer-master/src/SMTP.php";
require __DIR__ . "/PHPMailer-master/src/Exception.php";

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        // ⚠️ IMPORTANT: Replace with your NEW App Password after revoking the exposed one
        $mail->Username   = 'angelodominguiano12345@gmail.com';
        $mail->Password   = 'fwfe vphq cvok hzbh'; // <-- update this

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 'tls'
        $mail->Port       = 587;

        // Remove or set to 0 after confirming emails work
        $mail->SMTPDebug  = 0; // Set to 2 temporarily if still not working

        // SENDER
        $mail->setFrom('angelodominguiano12345@gmail.com', 'J.WHO Mushroom Farm');

        // RECEIVER
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); // Plain text fallback (helps avoid spam)

        $mail->send();
        return "SUCCESS";
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return "ERROR: " . $mail->ErrorInfo;
    }
}
?>