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
   JSON Response Helper
========================= */
function jsonResponse(int $status, string $message, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, X-Requested-With');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT');
        header('Access-Control-Max-Age: 86400');
    }

    @ob_end_clean();

    // Build consistent response structure
    $payload = [
        'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
        'success' => $status >= 200 && $status < 300,
        'message' => $message
    ];

    // Merge extra data
    foreach ($extra as $key => $value) {
        $payload[$key] = $value;
    }

    // Ensure arrays are always arrays, not objects when empty
    if (isset($payload['errors']) && empty($payload['errors'])) {
        $payload['errors'] = [];
    }
    if (isset($payload['warnings']) && empty($payload['warnings'])) {
        $payload['warnings'] = [];
    }
    if (isset($payload['data']) && empty($payload['data'])) {
        $payload['data'] = [];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* =========================
   Request Method Handling
========================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($method !== 'POST') {
    jsonResponse(405, 'Please submit the form using POST method.');
}

/* =========================
   Content-Type Detection
========================= */
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$isJson = stripos($contentType, 'application/json') !== false;

// Log request details for debugging
error_log('Contact Request - Content-Type: ' . $contentType);
error_log('Contact Request - Is JSON: ' . ($isJson ? 'YES' : 'NO'));

/* =========================
   Parse Input Data
========================= */
$data = [];
$rawInput = file_get_contents('php://input');

if ($isJson) {
    // Parse JSON input
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON Parse Error: ' . json_last_error_msg());
        error_log('Raw Input: ' . $rawInput);
        jsonResponse(400, 'Invalid JSON format in request.');
    }
} else {
    // Parse form data
    parse_str($rawInput, $data);
    if (empty($data)) {
        $data = $_POST;
    }
}

// Log received data
error_log('Contact Form Data: ' . print_r($data, true));

/* =========================
   Load DB Config
========================= */
$dbFile = __DIR__ . '/config/database.php';
if (!file_exists($dbFile)) {
    error_log('Database config file missing: ' . $dbFile);
    jsonResponse(500, 'Server misconfiguration. Please try again later.');
}

require_once $dbFile;

if (!function_exists('getDatabaseConnection')) {
    error_log('Database connection function not found');
    jsonResponse(500, 'Server misconfiguration. Database unavailable.');
}

/* =========================
   Database Connection
========================= */
try {
    $pdo = getDatabaseConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    error_log('DB Connection failed: ' . $e->getMessage());
    jsonResponse(500, 'Service temporarily unavailable. Please try again later.');
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

// HTML form fields: contactName, contactEmail, contactPhone, contactSubject, contactMessage
// Database columns: name, email, phone, subject, message
$fields = [
    // HTML: contactName → DB: name
    'name' => getField($data, ['contactName', 'contact_name', 'name', 'full_name'], ''),

    // HTML: contactEmail → DB: email
    'email' => getField($data, ['contactEmail', 'contact_email', 'email'], ''),

    // HTML: contactPhone → DB: phone
    'phone' => getField($data, ['contactPhone', 'contact_phone', 'phone', 'mobile'], ''),

    // HTML: contactSubject → DB: subject
    'subject' => getField($data, ['contactSubject', 'contact_subject', 'subject', 'title'], 'General Inquiry'),

    // HTML: contactMessage → DB: message
    'message' => getField($data, ['contactMessage', 'contact_message', 'message', 'content'], '')
];

// Log extracted fields
error_log('Extracted Fields: ' . print_r($fields, true));

/* =========================
   SIMPLE VALIDATION
========================= */
$errors = [];
$warnings = [];

// REQUIRED FIELDS (matching HTML form requirements)
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

// Phone validation
function normalizePhoneSimple(string $phone): string
{
    if (empty($phone)) {
        return '';
    }

    // Remove all non-digit characters except leading +
    $phone = trim($phone);
    $digits = preg_replace('/\D/', '', $phone);

    if (empty($digits)) {
        return '';
    }

    // Handle Nigerian numbers specifically
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        return '234' . substr($digits, 1);
    }

    // If it already starts with country code, return as is
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return $digits;
    }

    // Return original if can't normalize
    return $phone;
}

// Normalize phone number
$normalizedPhone = normalizePhoneSimple($fields['phone']);

// Check phone length if provided
if (!empty($fields['phone']) && !empty($normalizedPhone) && (strlen($normalizedPhone) < 10 || strlen($normalizedPhone) > 15)) {
    $errors['contactPhone'] = 'Phone number must be 10-15 digits';
}

/* =========================
   Return validation errors if any
========================= */
if (!empty($errors)) {
    jsonResponse(422, 'Please fix the following errors:', [
        'errors' => $errors,
        'warnings' => $warnings
    ]);
}

/* =========================
   Prepare data for database
========================= */
$dbName = htmlspecialchars($fields['name'], ENT_QUOTES, 'UTF-8');
$dbEmail = htmlspecialchars($fields['email'], ENT_QUOTES, 'UTF-8');
$dbSubject = htmlspecialchars($fields['subject'], ENT_QUOTES, 'UTF-8');
$dbMessage = htmlspecialchars($fields['message'], ENT_QUOTES, 'UTF-8');

// Use normalized phone or original if normalization failed
$dbPhone = !empty($normalizedPhone) ? $normalizedPhone : htmlspecialchars($fields['phone'], ENT_QUOTES, 'UTF-8');

/* =========================
   Save to Database
========================= */
try {
    $stmt = $pdo->prepare(
        "INSERT INTO contacts 
        (name, email, phone, subject, message, submission_date, ip_address)
        VALUES (:name, :email, :phone, :subject, :message, NOW(), :ip)"
    );

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $stmt->execute([
        ':name' => $dbName,
        ':email' => $dbEmail,
        ':phone' => $dbPhone,
        ':subject' => $dbSubject,
        ':message' => $dbMessage,
        ':ip' => $ip
    ]);

    $contactId = $pdo->lastInsertId();
    error_log("Contact saved with ID: $contactId");

} catch (Throwable $e) {
    error_log('Database insert error: ' . $e->getMessage());
    jsonResponse(500, 'We could not save your message. Please try again later.');
}

/* =========================
   Send Email Notification (Optional)
========================= */
$emailSent = false;
if (defined('SMTP_HOST') && SMTP_HOST && defined('SMTP_FROM') && SMTP_FROM) {
    try {
        $mail = new PHPMailer(true);

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USER') ? SMTP_USER : '';
        $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : '';
        $mail->SMTPSecure = defined('SMTP_SECURE') ? constant('SMTP_SECURE') : 'tls';
        $mail->Port = defined('SMTP_PORT') ? constant('SMTP_PORT') : 587;

        // Email content
        $mail->setFrom(SMTP_FROM, 'Beautiful Minds Schools');
        $mail->addAddress(SMTP_FROM);

        if (filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($fields['email'], $fields['name']);
        }

        $mail->isHTML(true);
        $mail->Subject = "New Contact Message: " . $dbSubject;

        // Build email body
        $body = "<h3>New Contact Form Submission</h3>";
        $body .= "<p><strong>Name:</strong> {$dbName}</p>";
        $body .= "<p><strong>Email:</strong> {$dbEmail}</p>";

        if (!empty($dbPhone)) {
            $body .= "<p><strong>Phone:</strong> {$dbPhone}</p>";
        }

        $body .= "<p><strong>Subject:</strong> {$dbSubject}</p>";
        $body .= "<p><strong>Message:</strong></p>";
        $body .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
        $body .= nl2br($dbMessage);
        $body .= "</div>";

        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

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

// Add phone if provided
if (!empty($normalizedPhone)) {
    $responseData['phone'] = $normalizedPhone;
} elseif (!empty($fields['phone'])) {
    $responseData['phone'] = $fields['phone'];
}

// Add message if not too long
if (!empty($fields['message'])) {
    // Truncate message for response if too long
    if (strlen($fields['message']) > 200) {
        $responseData['message'] = substr($fields['message'], 0, 200) . '...';
    } else {
        $responseData['message'] = $fields['message'];
    }
}

/* =========================
   Success Response
========================= */
jsonResponse(200, 'Thank you! Your message has been received. We will contact you shortly.', [
    'emailSent' => $emailSent,
    'warnings' => $warnings,
    'contactId' => $contactId,
    'databaseSaved' => true,
    'data' => $responseData
]);
?>