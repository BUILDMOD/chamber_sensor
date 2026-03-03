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

        // GMAIL ACCOUNT
        $mail->Username   = 'angelodominguiano12345@gmail.com';
        $mail->Password   = 'qasx fnct kunm hwas'; // App Password

        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // SENDER
        $mail->setFrom('angelodominguiano12345@gmail.com', 'MushroomOS');

        // RECEIVER
        $mail->addAddress($to);

        // CONTENT
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return "SUCCESS";
    } catch (Exception $e) {
        return "ERROR: " . $mail->ErrorInfo;
    }
}
?>
