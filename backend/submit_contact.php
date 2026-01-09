<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

/* =========================
   Load DB Config
========================= */
$dbFile = __DIR__ . '/config/database.php';
if (!file_exists($dbFile)) {
    error_log('Database config file missing: ' . $dbFile);
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Server misconfiguration. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $dbFile;

/* =========================
   Request Method Handling
========================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($method !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Please submit the form using POST method.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Content-Type Detection
========================= */
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isJson = stripos($contentType, 'application/json') !== false;

error_log('Contact Request - Content-Type: ' . $contentType);
error_log('Contact Request - Is JSON: ' . ($isJson ? 'YES' : 'NO'));

/* =========================
   Parse Input Data
========================= */
$data = [];
$rawInput = file_get_contents('php://input');

if ($isJson) {
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON Parse Error: ' . json_last_error_msg());
        error_log('Raw Input: ' . $rawInput);
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Invalid JSON format in request.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    parse_str($rawInput, $data);
    if (empty($data)) {
        $data = $_POST;
    }
}

error_log('Contact Form Data: ' . print_r($data, true));

/* =========================
   Database Connection
========================= */
try {
    $pdo = getDatabaseConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    error_log('DB Connection failed: ' . $e->getMessage());
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Service temporarily unavailable. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Field Extraction and Mapping
========================= */
function getField(array $data, array $possibleNames, string $default = ''): string
{
    foreach ($possibleNames as $name) {
        if (isset($data[$name]) && trim($data[$name]) !== '') {
            return trim((string) $data[$name]);
        }
    }
    return $default;
}

$fields = [
    'name' => getField($data, ['contactName', 'contact_name', 'name', 'full_name'], ''),
    'email' => getField($data, ['contactEmail', 'contact_email', 'email'], ''),
    'phone' => getField($data, ['contactPhone', 'contact_phone', 'phone', 'mobile'], ''),
    'subject' => getField($data, ['contactSubject', 'contact_subject', 'subject', 'title'], 'General Inquiry'),
    'message' => getField($data, ['contactMessage', 'contact_message', 'message', 'content'], '')
];

error_log('Extracted Fields: ' . print_r($fields, true));

/* =========================
   SIMPLE VALIDATION
========================= */
$errors = [];
$warnings = [];

if (empty($fields['name'])) {
    $errors['contactName'] = 'Please provide your name';
} elseif (strlen($fields['name']) < 2) {
    $warnings['contactName'] = 'Name seems very short';
}

if (empty($fields['email'])) {
    $errors['contactEmail'] = 'Please provide your email address';
} elseif (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    if (!preg_match('/^[^@]+@[^@]+\.[^@]+$/', $fields['email'])) {
        $errors['contactEmail'] = 'Please enter a valid email address';
    }
}

if (empty($fields['phone'])) {
    $errors['contactPhone'] = 'Please provide your phone number';
}

if (empty($fields['subject'])) {
    $fields['subject'] = 'General Inquiry';
    $warnings['contactSubject'] = 'No subject provided, using "General Inquiry"';
}

if (empty($fields['message'])) {
    $errors['contactMessage'] = 'Please enter your message';
} elseif (strlen($fields['message']) < 10) {
    $warnings['contactMessage'] = 'Message seems very short';
}

function normalizePhoneWithPlus(string $phone): string
{
    if (empty($phone)) {
        return '';
    }

    $phone = trim($phone);
    
    $phone = ltrim($phone, '+');
    
    $digits = preg_replace('/\D/', '', $phone);
    
    if (empty($digits)) {
        return '';
    }

    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        return '234' . substr($digits, 1);
    }
    
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return $digits;
    }

    return $digits;
}

function formatPhoneForDisplay(string $phone): string
{
    if (empty($phone)) {
        return '';
    }
    
    $normalized = normalizePhoneWithPlus($phone);
    if (empty($normalized)) {
        return '';
    }
    
    return '+' . $normalized;
}

$normalizedPhone = normalizePhoneWithPlus($fields['phone']);

if (!empty($fields['phone']) && !empty($normalizedPhone) && (strlen($normalizedPhone) < 10 || strlen($normalizedPhone) > 15)) {
    $errors['contactPhone'] = 'Phone number must be 10-15 digits';
}

/* =========================
   Return validation errors if any
========================= */
if (!empty($errors)) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Please fix the following errors:',
        'errors' => $errors,
        'warnings' => $warnings
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Prepare data for database
========================= */
$dbName = htmlspecialchars($fields['name'], ENT_QUOTES, 'UTF-8');
$dbEmail = htmlspecialchars($fields['email'], ENT_QUOTES, 'UTF-8');
$dbSubject = htmlspecialchars($fields['subject'], ENT_QUOTES, 'UTF-8');
$dbMessage = htmlspecialchars($fields['message'], ENT_QUOTES, 'UTF-8');
$dbPhone = !empty($normalizedPhone) ? $normalizedPhone : htmlspecialchars($fields['phone'], ENT_QUOTES, 'UTF-8');

/* =========================
   Save to Database
========================= */
try {
    $stmt = $pdo->prepare(
        "INSERT INTO contacts 
        (name, email, phone, subject, message, submission_date)
        VALUES (:name, :email, :phone, :subject, :message, NOW())"
    );

    $stmt->execute([
        ':name' => $dbName,
        ':email' => $dbEmail,
        ':phone' => $dbPhone,
        ':subject' => $dbSubject,
        ':message' => $dbMessage,
    ]);

    $contactId = $pdo->lastInsertId();
    error_log("Contact saved with ID: $contactId");

} catch (Throwable $e) {
    error_log('Database insert error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'We could not save your message. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Send Email Notification (Optional)
========================= */
$emailSent = false;
$displayPhone = formatPhoneForDisplay($fields['phone']);

if (defined('SMTP_HOST') && SMTP_HOST && defined('SMTP_FROM') && SMTP_FROM) {
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USER') ? SMTP_USER : SCHOOL_EMAIL;
        $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : 'uosk qqgm ctsm kjcc';
        $mail->SMTPSecure = defined('SMTP_SECURE') ? constant('SMTP_SECURE') : 'tls';
        $mail->Port = defined('SMTP_PORT') ? constant('SMTP_PORT') : 587;

        // IMPORTANT: Always send FROM your school email
        // Trying to send FROM user's email will fail on Gmail/Google SMTP
        $mail->setFrom(SMTP_FROM, 'Beautiful Minds Schools Contact Form');
        
        // Set user as Reply-To so replies go to them
        if (filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($fields['email'], $fields['name']);
        }
        
        $mail->addAddress(SMTP_FROM); // Send to school
        
        // Also send to an alternate admin email if different from SMTP_FROM
        if (defined('ADMIN_EMAIL') && ADMIN_EMAIL && ADMIN_EMAIL !== SMTP_FROM) {
            $mail->addAddress(ADMIN_EMAIL);
        }

        // Format subject to show it's from the user
        $mail->isHTML(true);
        $mail->Subject = "[Contact Form] From: " . $dbName . " - " . $dbSubject;

        $body = "<h3 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>New Contact Form Submission</h3>";
        
        $body .= "<div style='background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;'>";
        $body .= "<p style='margin: 5px 0;'><strong>Submitted:</strong> " . date('F j, Y, g:i a') . "</p>";
        $body .= "<p style='margin: 5px 0;'><strong>Contact ID:</strong> <code>$contactId</code></p>";
        $body .= "<p style='margin: 5px 0;'><strong>Message From:</strong> " . $dbName . " &lt;" . $dbEmail . "&gt;</p>";
        $body .= "</div>";
        
        $body .= "<h4 style='color: #2c3e50;'>Contact Information</h4>";
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Name</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>{$dbName}</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Email</strong></td><td style='padding: 10px; border: 1px solid #ddd;'><a href='mailto:{$dbEmail}'>{$dbEmail}</a></td></tr>";
        
        if (!empty($displayPhone)) {
            $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Phone</strong></td><td style='padding: 10px; border: 1px solid #ddd;'><a href='tel:{$displayPhone}'>{$displayPhone}</a></td></tr>";
        }
        
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Subject</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>{$dbSubject}</td></tr>";
        $body .= "</table>";
        
        $body .= "<h4 style='color: #2c3e50;'>Message</h4>";
        $body .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db;'>";
        $body .= nl2br($dbMessage);
        $body .= "</div>";
        
        $body .= "<div style='background: #e8f4fc; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db; margin-top: 20px;'>";
        $body .= "<p style='margin: 0;'><strong>Quick Actions:</strong></p>";
        $body .= "<ul style='margin: 10px 0 0 0; padding-left: 20px;'>";
        $body .= "<li><a href='mailto:{$dbEmail}?subject=Re: {$dbSubject}'>Reply to {$dbName}</a></li>";
        if (!empty($displayPhone)) {
            $body .= "<li><a href='tel:{$displayPhone}'>Call {$dbName}</a></li>";
        }
        $body .= "</ul>";
        $body .= "</div>";
        
        $body .= "<p style='color: #7f8c8d; font-size: 12px; margin-top: 20px; border-top: 1px solid #ecf0f1; padding-top: 10px;'>";
        $body .= "This email was automatically generated by the Beautiful Minds Schools contact form.";
        $body .= "</p>";

        $mail->Body = $body;
        
        // Plain text version
        $plainBody = "NEW CONTACT FORM SUBMISSION\n";
        $plainBody .= "============================\n\n";
        $plainBody .= "Submitted: " . date('F j, Y, g:i a') . "\n";
        $plainBody .= "Contact ID: $contactId\n";
        $plainBody .= "Message From: " . $dbName . " <" . $dbEmail . ">\n\n";
        
        $plainBody .= "CONTACT INFORMATION\n";
        $plainBody .= "-------------------\n";
        $plainBody .= "Name: {$dbName}\n";
        $plainBody .= "Email: {$dbEmail}\n";
        if (!empty($displayPhone)) {
            $plainBody .= "Phone: {$displayPhone}\n";
        }
        $plainBody .= "Subject: {$dbSubject}\n\n";
        
        $plainBody .= "MESSAGE\n";
        $plainBody .= "-------\n";
        $plainBody .= strip_tags($dbMessage) . "\n\n";
        
        $plainBody .= "QUICK ACTIONS:\n";
        $plainBody .= "* Reply to: {$dbEmail}\n";
        if (!empty($displayPhone)) {
            $plainBody .= "* Call: {$displayPhone}\n";
        }
        
        $mail->AltBody = $plainBody;

        $mail->send();
        $emailSent = true;
        error_log('Contact email sent successfully');

    } catch (Exception $e) {
        error_log('Email sending failed: ' . $e->getMessage());
        $emailSent = false;
    }
}

/* =========================
   Prepare Success Response
========================= */
$responseData = [
    'contactId' => $contactId,
    'name' => $fields['name'],
    'email' => $fields['email'],
    'subject' => $fields['subject']
];

if (!empty($displayPhone)) {
    $responseData['phone'] = $displayPhone;
} elseif (!empty($fields['phone'])) {
    $responseData['phone'] = $fields['phone'];
}

if (!empty($fields['message'])) {
    if (strlen($fields['message']) > 200) {
        $responseData['message'] = substr($fields['message'], 0, 200) . '...';
    } else {
        $responseData['message'] = $fields['message'];
    }
}

/* =========================
   Success Response
========================= */
header('Content-Type: application/json; charset=UTF-8');
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'success' => true,
    'message' => 'Thank you! Your message has been received. We will contact you shortly.',
    'emailSent' => $emailSent,
    'warnings' => $warnings,
    'contactId' => $contactId,
    'databaseSaved' => true,
    'data' => $responseData
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
?>