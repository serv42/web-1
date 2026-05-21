<?php
/**
 * Solide.IT Help Center – Ticket Mail Handler
 * Receives form data (multipart/form-data) from helpcenter.html
 * and sends it via PHPMailer (SMTP), including optional file attachment.
 */

// --- Security Headers ---
header('X-Content-Type-Options: nosniff');

// Disable displaying errors in response for production
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Methode nicht erlaubt</h1><p>Nur POST-Anfragen sind zulässig.</p>';
    exit;
}

// --- Configuration (same env vars as booking.php) ---
$config = [
    'smtp_host'     => getenv('SMTP_HOST') ?: 'mail.solide.it.com',
    'smtp_port'     => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_user'     => getenv('SMTP_USER') ?: 'hello@solide.it.com',
    'smtp_pass'     => getenv('SMTP_PASS') ?: '',
    'smtp_secure'   => getenv('SMTP_SECURE') ?: 'tls',
    'from_email'    => getenv('MAIL_FROM') ?: 'noreply@solide.it.com',
    'from_name'     => getenv('MAIL_FROM_NAME') ?: 'Solide.IT Helpcenter',
    'to_email'      => getenv('MAIL_TO') ?: 'hello@solide.it.com',
    'to_name'       => getenv('MAIL_TO_NAME') ?: 'Solide.IT Team',
    'rate_limit'    => 10,            // max submissions per IP per hour
    'rate_dir'      => '/tmp/solide_ratelimit',
    'max_file_size' => 10 * 1024 * 1024, // 10 MB
    'allowed_types' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv',
        'application/zip', 'application/x-zip-compressed',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream', // generic fallback for logs etc.
    ],
];

// --- Rate Limiting (file-based, simple) ---
function checkRateLimit(string $ip, int $maxPerHour, string $dir): bool {
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . md5($ip) . '_hc.json';
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
    showErrorPage('Zu viele Anfragen', 'Bitte versuche es in einer Stunde erneut.');
    exit;
}

// --- Parse Input (multipart/form-data via $_POST) ---
$theme       = trim($_POST['theme'] ?? '');
$subTheme    = trim($_POST['subTheme'] ?? '');
$requestName = trim($_POST['requestName'] ?? '');
$summary     = trim($_POST['summary'] ?? '');
$description = trim($_POST['description'] ?? '');

// --- Validate Required Fields ---
$errors = [];
if ($theme === '')       $errors[] = 'Thema ist erforderlich.';
if ($subTheme === '')    $errors[] = 'Unterthema ist erforderlich.';
if (!filter_var($requestName, FILTER_VALIDATE_EMAIL)) $errors[] = 'Eine gültige E-Mail-Adresse ist erforderlich.';
if ($summary === '')     $errors[] = 'Zusammenfassung ist erforderlich.';
if ($description === '') $errors[] = 'Beschreibung ist erforderlich.';

if (!empty($errors)) {
    http_response_code(422);
    showErrorPage('Validierungsfehler', implode('<br>', $errors));
    exit;
}

// Sanitize for HTML output
$themeSafe       = htmlspecialchars($theme, ENT_QUOTES, 'UTF-8');
$subThemeSafe    = htmlspecialchars($subTheme, ENT_QUOTES, 'UTF-8');
$requestNameSafe = htmlspecialchars($requestName, ENT_QUOTES, 'UTF-8');
$summarySafe     = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
$descriptionSafe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

// --- Validate Attachment (optional) ---
$hasAttachment = false;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $fileSize = $_FILES['attachment']['size'];
    $fileType = $_FILES['attachment']['type'];

    if ($fileSize > $config['max_file_size']) {
        http_response_code(422);
        showErrorPage('Datei zu groß', 'Die maximale Dateigröße beträgt 10 MB.');
        exit;
    }
    $hasAttachment = true;
}

// --- Build Email Body ---
$htmlBody = '<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
<div style="max-width:600px;margin:40px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,0.07);">
    <div style="background:linear-gradient(135deg,#dc3545,#b02a37);padding:32px 24px;text-align:center;">
        <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">🎫 Neues Support-Ticket</h1>
        <p style="margin:8px 0 0;color:#f8d7da;font-size:14px;">über das Solide.IT Help Center</p>
    </div>
    <div style="padding:24px;">
        <table style="width:100%;border-collapse:collapse;">
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:160px;">Thema</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . $themeSafe . '</td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:160px;">Unterthema</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . $subThemeSafe . '</td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:160px;">Mitarbeiter</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;"><a href="mailto:' . $requestNameSafe . '" style="color:#2563eb;">' . $requestNameSafe . '</a></td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:160px;">Zusammenfassung</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;font-weight:600;">' . $summarySafe . '</td>
            </tr>
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;vertical-align:top;border-bottom:1px solid #e2e8f0;width:160px;">Beschreibung</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;white-space:pre-wrap;">' . nl2br($descriptionSafe) . '</td>
            </tr>' . ($hasAttachment ? '
            <tr>
                <td style="padding:12px 16px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;width:160px;">📎 Anhang</td>
                <td style="padding:12px 16px;color:#1e293b;border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($_FILES['attachment']['name'], ENT_QUOTES, 'UTF-8') . '</td>
            </tr>' : '') . '
        </table>
    </div>
    <div style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
        <p style="margin:0;color:#94a3b8;font-size:12px;">Gesendet am ' . date('d.m.Y \u\m H:i') . ' Uhr · IP: ' . $clientIP . '</p>
    </div>
</div>
</body>
</html>';

$textBody = "Neues Support-Ticket über das Solide.IT Help Center\n\n"
    . "Thema: $theme\n"
    . "Unterthema: $subTheme\n"
    . "Mitarbeiter: $requestName\n"
    . "Zusammenfassung: $summary\n"
    . "Beschreibung:\n$description\n"
    . ($hasAttachment ? "Anhang: " . $_FILES['attachment']['name'] . "\n" : "")
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
    $mail->addReplyTo($requestName, $requestName);
    $mail->addAddress($config['to_email'], $config['to_name']);

    // Attachment
    if ($hasAttachment) {
        $mail->addAttachment(
            $_FILES['attachment']['tmp_name'],
            $_FILES['attachment']['name']
        );
    }

    // Content
    $mail->isHTML(true);
    $mail->Subject = "🎫 Ticket: [$themeSafe] $summarySafe";
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();

    // Show success page
    showSuccessPage($summarySafe);

} catch (Exception $e) {
    error_log("PHPMailer Error (Helpcenter): " . $mail->ErrorInfo);
    http_response_code(500);
    showErrorPage('Senden fehlgeschlagen', 'Die Nachricht konnte leider nicht gesendet werden. Bitte versuche es später erneut oder kontaktiere uns direkt unter hello@solide.it.com.');
}

// --- Helper: Success Page ---
function showSuccessPage(string $summary): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Ticket erstellt</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f1f5f9; font-family: Arial, sans-serif; }
        .success-card { max-width: 600px; margin: 80px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .success-header { background: linear-gradient(135deg, #198754, #157347); padding: 32px 24px; text-align: center; color: #fff; }
        .success-body { padding: 32px 24px; text-align: center; }
    </style>
</head>
<body>
    <div class="success-card">
        <div class="success-header">
            <i class="fas fa-check-circle" style="font-size:3rem;margin-bottom:12px;"></i>
            <h2 style="margin:0;font-size:1.5rem;">Ticket erfolgreich erstellt!</h2>
        </div>
        <div class="success-body">
            <p class="text-muted mb-1">Deine Anfrage wurde versendet:</p>
            <p class="fw-bold fs-5 mb-4">' . $summary . '</p>
            <p class="text-muted">Wir melden uns schnellstmöglich bei dir.</p>
            <a href="helpcenter.html" class="btn btn-primary mt-3">
                <i class="fas fa-arrow-left me-2"></i>Zurück zum Help Center
            </a>
        </div>
    </div>
</body>
</html>';
}

// --- Helper: Error Page ---
function showErrorPage(string $title, string $message): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fehler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f1f5f9; font-family: Arial, sans-serif; }
        .error-card { max-width: 600px; margin: 80px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .error-header { background: linear-gradient(135deg, #dc3545, #b02a37); padding: 32px 24px; text-align: center; color: #fff; }
        .error-body { padding: 32px 24px; text-align: center; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-header">
            <i class="fas fa-exclamation-triangle" style="font-size:3rem;margin-bottom:12px;"></i>
            <h2 style="margin:0;font-size:1.5rem;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>
        </div>
        <div class="error-body">
            <p class="text-muted mb-4">' . $message . '</p>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Zurück
            </a>
        </div>
    </div>
</body>
</html>';
}
