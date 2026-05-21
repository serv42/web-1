<?php
/**
 * Solide.IT Contact Form Handler
 * Receives form data and sends it via PHPMailer (SMTP).
 */

// --- CORS & Security Headers ---
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Disable displaying errors in response for production
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- Configuration ---
$config = [
    'smtp_host'     => getenv('SMTP_HOST') ?: 'mail.solide.it.com',
    'smtp_port'     => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_user'     => getenv('SMTP_USER') ?: 'hello@solide.it.com',
    'smtp_pass'     => getenv('SMTP_PASS') ?: '',
    'smtp_secure'   => getenv('SMTP_SECURE') ?: 'tls',  // 'tls' or 'ssl'
    'from_email'    => getenv('MAIL_FROM') ?: 'noreply@solide.it.com',
    'from_name'     => getenv('MAIL_FROM_NAME') ?: 'Solide.IT Webseite',
    'to_email'      => getenv('MAIL_TO') ?: 'hello@solide.it.com',
    'to_name'       => getenv('MAIL_TO_NAME') ?: 'Solide.IT Team',
    'rate_limit'    => 5,            // max submissions per IP per hour
    'rate_dir'      => '/tmp/solide_ratelimit',
];

// --- Rate Limiting (file-based, simple) ---
function checkRateLimit(string $ip, int $maxPerHour, string $dir): bool {
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . md5($ip) . '.json';
    $now = time();
    $data = ['timestamps' => []];

    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $data = json_decode($raw, true) ?: ['timestamps' => []];
    }

    // Remove entries older than 1 hour
    $data['timestamps'] = array_values(array_filter(
        $data['timestamps'],
        fn($ts) => ($now - $ts) < 3600
    ));

    if (count($data['timestamps']) >= $maxPerHour) {
        return false;
    }

    $data['timestamps'][] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

$clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!checkRateLimit($clientIP, $config['rate_limit'], $config['rate_dir'])) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
}

// --- Parse Input ---
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body']);
    exit;
}

// --- Validate Required Fields ---
$name     = trim($input['name'] ?? '');
$email    = trim($input['email'] ?? '');
$subject  = trim($input['subject'] ?? '');
$message  = trim($input['message'] ?? '');
$services = $input['services'] ?? [];

$errors = [];
if ($name === '') $errors[] = 'Name is required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if ($subject === '') $errors[] = 'Subject is required';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Sanitize
$name    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
$services = array_map(fn($s) => htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8'), (array)$services);

// --- Build Email Body ---
$servicesHtml = '';
if (!empty($services)) {
    $servicesHtml = '<tr>
        <td style="padding:12px 16px;font-weight:600;color:#475569;vertical-align:top;border-bottom:1px solid #e2e8f0;width:140px;">Services</td>
        <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . implode(', ', $services) . '</td>
    </tr>';
}

$messageHtml = '';
if ($message !== '') {
    $messageHtml = '<tr>
        <td style="padding:12px 16px;font-weight:600;color:#475569;vertical-align:top;border-bottom:1px solid #e2e8f0;width:140px;">Nachricht</td>
        <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;white-space:pre-wrap;">' . nl2br($message) . '</td>
    </tr>';
}

$htmlBody = '<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
<div style="max-width:600px;margin:40px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.07);">
    <div style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px 24px;text-align:center;">
        <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Neue Kontaktanfrage</h1>
        <p style="margin:8px 0 0;color:#bfdbfe;font-size:14px;">über solide.it.com</p>
    </div>
    <div style="padding:24px;">
        <table style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:140px;">Name</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . $name . '</td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:140px;">E-Mail</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;"><a href="mailto:' . $email . '" style="color:#2563eb;">' . $email . '</a></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:140px;">Betreff</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . $subject . '</td>
            </tr>
            ' . $servicesHtml . '
            ' . $messageHtml . '
        </table>
    </div>
    <div style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="margin:0;color:#94a3b8;font-size:12px;">Gesendet am ' . date('d.m.Y \u\m H:i') . ' Uhr · IP: ' . $clientIP . '</p>
    </div>
</div>
</body>
</html>';

$textBody = "Neue Kontaktanfrage über solide.it.com\n\n"
    . "Name: $name\n"
    . "E-Mail: $email\n"
    . "Betreff: $subject\n"
    . (!empty($services) ? "Services: " . implode(', ', $services) . "\n" : "")
    . ($message !== '' ? "Nachricht:\n$message\n" : "")
    . "\n---\nGesendet am " . date('d.m.Y H:i') . " Uhr · IP: $clientIP";

// --- Send via PHPMailer ---
require '/var/www/html/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

try {
    $mail = new PHPMailer(true);

    // SMTP settings
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_user'];
    $mail->Password   = $config['smtp_pass'];
    $mail->SMTPSecure = $config['smtp_secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['smtp_port'];
    $mail->CharSet    = 'UTF-8';

    // Sender & recipient
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addReplyTo($email, $name);
    $mail->addAddress($config['to_email'], $config['to_name']);

    // Content
    $mail->isHTML(true);
    $mail->Subject = "Kontaktanfrage: $subject";
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("PHPMailer Error: " . $mail->ErrorInfo);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Mail could not be sent. Please try again later.']);
}
