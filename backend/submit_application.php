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

error_log('Application Request - Content-Type: ' . $contentType);
error_log('Application Request - Is JSON: ' . ($isJson ? 'YES' : 'NO'));

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

error_log('Application Form Data: ' . print_r($data, true));

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
   Field Extraction with CORRECT HTML Form Field Name Mapping
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
    'full_name' => getField($data, ['fullName', 'full_name', 'name'], ''),
    'dob' => getField($data, ['dob', 'dateOfBirth', 'birth_date', 'birthdate'], ''),
    'religion' => getField($data, ['religion'], ''),
    'class_interest' => getField($data, ['classInterest', 'class_interest', 'class'], ''),
    'gender' => getField($data, ['gender', 'sex'], ''),
    'address' => getField($data, ['address', 'home_address'], ''),
    'nationality' => getField($data, ['nationality', 'country'], 'Nigeria'),
    'state' => getField($data, ['state', 'province', 'region'], ''),
    'city' => getField($data, ['city', 'town'], ''),
    'student_phone' => getField($data, ['studentPhone', 'student_phone', 'phone'], ''),
    'student_email' => getField($data, ['studentEmail', 'student_email'], ''),

    'mother_name' => getField($data, ['motherName', 'mother_name', 'mother'], ''),
    'father_name' => getField($data, ['fatherName', 'father_name', 'father'], ''),
    'mother_phone' => getField($data, ['motherPhone', 'mother_phone', 'mother_contact'], ''),
    'father_phone' => getField($data, ['fatherPhone', 'father_phone', 'father_contact'], ''),
    'parent_email' => getField($data, ['parentEmail', 'parent_email', 'email'], ''),
    'parent_address' => getField($data, ['parentAddress', 'parent_address'], '')
];

error_log('Extracted Fields: ' . print_r($fields, true));

/* =========================
   SIMPLE VALIDATION
========================= */
$errors = [];
$warnings = [];

if (empty($fields['full_name'])) {
    $errors['fullName'] = 'Student full name is required';
} elseif (strlen($fields['full_name']) < 3) {
    $warnings['fullName'] = 'Student name seems very short';
}

if (empty($fields['dob'])) {
    $errors['dob'] = 'Date of birth is required';
}

if (empty($fields['religion'])) {
    $errors['religion'] = 'Religion is required';
}

if (empty($fields['gender'])) {
    $errors['gender'] = 'Gender is required';
}

if (empty($fields['class_interest'])) {
    $errors['classInterest'] = 'Class of interest is required';
}

if (empty($fields['address'])) {
    $errors['address'] = 'Residential address is required';
}

if (empty($fields['nationality'])) {
    $errors['nationality'] = 'Nationality is required';
}

if (empty($fields['state'])) {
    $errors['state'] = 'State is required';
}

if (empty($fields['city'])) {
    $errors['city'] = 'City is required';
}

if (empty($fields['mother_name'])) {
    $errors['motherName'] = "Mother's name is required";
} elseif (strlen($fields['mother_name']) < 3) {
    $warnings['motherName'] = "Mother's name seems very short";
}

if (empty($fields['father_name'])) {
    $errors['fatherName'] = "Father's name is required";
} elseif (strlen($fields['father_name']) < 3) {
    $warnings['fatherName'] = "Father's name seems very short";
}

if (empty($fields['mother_phone'])) {
    $errors['motherPhone'] = "Mother's phone number is required";
}

if (empty($fields['father_phone'])) {
    $errors['fatherPhone'] = "Father's phone number is required";
}

if (empty($fields['parent_email'])) {
    $errors['parentEmail'] = 'Parent email address is required';
} elseif (!filter_var($fields['parent_email'], FILTER_VALIDATE_EMAIL)) {
    if (!preg_match('/^[^@]+@[^@]+\.[^@]+$/', $fields['parent_email'])) {
        $errors['parentEmail'] = 'Please enter a valid parent email address';
    }
}

if (empty($fields['parent_address'])) {
    $errors['parentAddress'] = 'Parent address is required';
}

if (!empty($fields['student_email']) && !filter_var($fields['student_email'], FILTER_VALIDATE_EMAIL)) {
    if (!preg_match('/^[^@]+@[^@]+\.[^@]+$/', $fields['student_email'])) {
        $warnings['studentEmail'] = 'Student email format appears incorrect';
    }
}

$dobFormatted = '';
if (!empty($fields['dob'])) {
    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
    $dateValid = false;

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $fields['dob']);
        if ($date && $date->format($format) === $fields['dob']) {
            $currentYear = (int) date('Y');
            $birthYear = (int) $date->format('Y');

            if ($birthYear >= 1900 && $birthYear <= $currentYear) {
                $dobFormatted = $date->format('Y-m-d');
                $dateValid = true;
                break;
            }
        }
    }

    if (!$dateValid) {
        $timestamp = strtotime($fields['dob']);
        if ($timestamp !== false) {
            $date = new DateTime('@' . $timestamp);
            $birthYear = (int) $date->format('Y');
            $currentYear = (int) date('Y');

            if ($birthYear >= 1900 && $birthYear <= $currentYear) {
                $dobFormatted = $date->format('Y-m-d');
                $dateValid = true;
            }
        }
    }

    if (!$dateValid) {
        $errors['dob'] = 'Please enter a valid date of birth (YYYY-MM-DD format preferred)';
    }
}

function normalizePhoneWithPlus(string $phone): string
{
    if (empty($phone)) {
        return '';
    }

    $phone = trim($phone);

    // Remove any existing + sign for processing
    $phone = ltrim($phone, '+');

    // Extract digits only
    $digits = preg_replace('/\D/', '', $phone);

    if (empty($digits)) {
        return '';
    }

    // If it's 11 digits and starts with 0 (Nigeria), convert to 234
    if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
        return '234' . substr($digits, 1);
    }

    // If it's 10 digits and doesn't start with 0, assume it's already country code + number
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

$motherPhoneDigits = normalizePhoneWithPlus($fields['mother_phone']);
$fatherPhoneDigits = normalizePhoneWithPlus($fields['father_phone']);
$studentPhoneDigits = normalizePhoneWithPlus($fields['student_phone']);

$displayMotherPhone = formatPhoneForDisplay($fields['mother_phone']);
$displayFatherPhone = formatPhoneForDisplay($fields['father_phone']);
$displayStudentPhone = formatPhoneForDisplay($fields['student_phone']);

if (!empty($motherPhoneDigits) && (strlen($motherPhoneDigits) < 10 || strlen($motherPhoneDigits) > 15)) {
    $errors['motherPhone'] = 'Mother phone number must be 10-15 digits';
}

if (!empty($fatherPhoneDigits) && (strlen($fatherPhoneDigits) < 10 || strlen($fatherPhoneDigits) > 15)) {
    $errors['fatherPhone'] = 'Father phone number must be 10-15 digits';
}

if (!empty($studentPhoneDigits) && (strlen($studentPhoneDigits) < 10 || strlen($studentPhoneDigits) > 15)) {
    $warnings['studentPhone'] = 'Student phone number may be invalid';
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
   Generate Application ID
========================= */
$applicationId = 'APP' . date('Ymd') . strtoupper(substr(uniqid(), -6));

/* =========================
   Prepare data for database
========================= */
$dbData = [
    ':full_name' => htmlspecialchars($fields['full_name'], ENT_QUOTES, 'UTF-8'),
    ':date_of_birth' => $dobFormatted,
    ':religion' => htmlspecialchars($fields['religion'], ENT_QUOTES, 'UTF-8'),
    ':class_interest' => htmlspecialchars($fields['class_interest'], ENT_QUOTES, 'UTF-8'),
    ':gender' => htmlspecialchars($fields['gender'], ENT_QUOTES, 'UTF-8'),
    ':address' => htmlspecialchars($fields['address'], ENT_QUOTES, 'UTF-8'),
    ':nationality' => htmlspecialchars($fields['nationality'], ENT_QUOTES, 'UTF-8'),
    ':state' => htmlspecialchars($fields['state'], ENT_QUOTES, 'UTF-8'),
    ':city' => htmlspecialchars($fields['city'], ENT_QUOTES, 'UTF-8'),
    ':student_phone' => $studentPhoneDigits,
    ':student_email' => htmlspecialchars($fields['student_email'], ENT_QUOTES, 'UTF-8'),
    ':mother_name' => htmlspecialchars($fields['mother_name'], ENT_QUOTES, 'UTF-8'),
    ':father_name' => htmlspecialchars($fields['father_name'], ENT_QUOTES, 'UTF-8'),
    ':mother_phone' => $motherPhoneDigits,
    ':father_phone' => $fatherPhoneDigits,
    ':parent_email' => htmlspecialchars($fields['parent_email'], ENT_QUOTES, 'UTF-8'),
    ':parent_address' => htmlspecialchars($fields['parent_address'], ENT_QUOTES, 'UTF-8'),
    ':application_id' => $applicationId,
];

error_log('Application DB Data: ' . print_r($dbData, true));

/* =========================
   Save to Database
========================= */
try {
    $stmt = $pdo->prepare("
        INSERT INTO applications (
            full_name, date_of_birth, religion, class_interest, gender, address,
            nationality, state, city, student_phone, student_email, 
            mother_name, father_name, mother_phone, father_phone, 
            parent_email, parent_address,
            submission_date, status, application_id
        ) VALUES (
            :full_name, :date_of_birth, :religion, :class_interest, :gender, :address,
            :nationality, :state, :city, :student_phone, :student_email,
            :mother_name, :father_name, :mother_phone, :father_phone,
            :parent_email, :parent_address,
            NOW(), 'pending', :application_id
        )
    ");

    error_log('Prepared SQL: ' . $stmt->queryString);

    $stmt->execute($dbData);

    $insertId = $pdo->lastInsertId();
    error_log("Application saved successfully. App ID: $applicationId, DB ID: $insertId");

} catch (Throwable $e) {
    error_log('Database insert error: ' . $e->getMessage());
    error_log('SQL Error Info: ' . print_r($stmt->errorInfo() ?? [], true));
    error_log('Data being inserted: ' . print_r($dbData, true));
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'We could not save your application. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   Send Email Notification
========================= */
$emailSent = false;
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

        $mail->setFrom(SMTP_FROM, 'Beautiful Minds Schools');
        $mail->addAddress(SMTP_FROM);

        if (filter_var($fields['parent_email'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($fields['parent_email'], $fields['full_name'] . "'s Parent");
        }

        $mail->isHTML(true);
        $mail->Subject = "New Application Submitted: {$applicationId} - " . htmlspecialchars($fields['full_name']);

        $body = "<h3 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>
                New Student Application - {$applicationId}</h3>";

        $body .= "<div style='background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;'>";
        $body .= "<h4 style='color: #2c3e50; margin-top: 0;'>Application Summary</h4>";
        $body .= "<p><strong>Submission Time:</strong> " . date('F j, Y, g:i a') . "</p>";
        $body .= "<p><strong>Application ID:</strong> <code>{$applicationId}</code></p>";
        $body .= "</div>";

        $body .= "<h4 style='color: #2c3e50;'>Student Information</h4>";
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Full Name</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['full_name']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Date of Birth</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . ($dobFormatted ?: htmlspecialchars($fields['dob'])) . "</td></tr>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Gender</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['gender']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Religion</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['religion']) . "</td></tr>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Class Interest</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['class_interest']) . "</td></tr>";

        if (!empty($displayStudentPhone)) {
            $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Student Phone</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . $displayStudentPhone . "</td></tr>";
        }

        if (!empty($fields['student_email'])) {
            $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Student Email</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['student_email']) . "</td></tr>";
        }
        $body .= "</table>";

        $body .= "<h4 style='color: #2c3e50;'>Contact Information</h4>";
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Address</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['address']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>City</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['city']) . "</td></tr>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>State</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['state']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Nationality</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['nationality']) . "</td></tr>";
        $body .= "</table>";

        $body .= "<h4 style='color: #2c3e50;'>Parent Information</h4>";
        $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Mother's Name</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['mother_name']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Mother's Phone</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . $displayMotherPhone . "</td></tr>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Father's Name</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['father_name']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Father's Phone</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . $displayFatherPhone . "</td></tr>";
        $body .= "<tr style='background-color: #f2f2f2;'><td style='padding: 10px; border: 1px solid #ddd;'><strong>Parent Email</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['parent_email']) . "</td></tr>";
        $body .= "<tr><td style='padding: 10px; border: 1px solid #ddd;'><strong>Parent Address</strong></td><td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($fields['parent_address']) . "</td></tr>";
        $body .= "</table>";

        $body .= "<div style='background: #e8f4fc; padding: 15px; border-radius: 5px; border-left: 4px solid #3498db; margin-top: 20px;'>";
        $body .= "<p style='margin: 0;'><strong>Next Steps:</strong></p>";
        $body .= "<ul style='margin: 10px 0 0 0; padding-left: 20px;'>";
        $body .= "<li>Contact parent at: " . $displayMotherPhone . " (Mother) or " . $displayFatherPhone . " (Father)</li>";
        $body .= "<li>Follow up via email: " . htmlspecialchars($fields['parent_email']) . "</li>";
        $body .= "</ul>";
        $body .= "</div>";

        $body .= "<p style='color: #7f8c8d; font-size: 12px; margin-top: 20px; border-top: 1px solid #ecf0f1; padding-top: 10px;'>";
        $body .= "This email was automatically generated by the Beautiful Minds Schools application system.";
        $body .= "</p>";

        $mail->Body = $body;

        $plainBody = "NEW STUDENT APPLICATION\n";
        $plainBody .= "=======================\n\n";
        $plainBody .= "Application ID: {$applicationId}\n";
        $plainBody .= "Submission Time: " . date('F j, Y, g:i a') . "\n";

        $plainBody .= "STUDENT INFORMATION\n";
        $plainBody .= "-------------------\n";
        $plainBody .= "Full Name: " . $fields['full_name'] . "\n";
        $plainBody .= "Date of Birth: " . ($dobFormatted ?: $fields['dob']) . "\n";
        $plainBody .= "Gender: " . $fields['gender'] . "\n";
        $plainBody .= "Religion: " . $fields['religion'] . "\n";
        $plainBody .= "Class Interest: " . $fields['class_interest'] . "\n";

        if (!empty($displayStudentPhone)) {
            $plainBody .= "Student Phone: " . $displayStudentPhone . "\n";
        }

        if (!empty($fields['student_email'])) {
            $plainBody .= "Student Email: " . $fields['student_email'] . "\n";
        }

        $plainBody .= "\nCONTACT INFORMATION\n";
        $plainBody .= "--------------------\n";
        $plainBody .= "Address: " . $fields['address'] . "\n";
        $plainBody .= "City: " . $fields['city'] . "\n";
        $plainBody .= "State: " . $fields['state'] . "\n";
        $plainBody .= "Nationality: " . $fields['nationality'] . "\n";

        $plainBody .= "\nPARENT INFORMATION\n";
        $plainBody .= "-------------------\n";
        $plainBody .= "Mother's Name: " . $fields['mother_name'] . "\n";
        $plainBody .= "Mother's Phone: " . $displayMotherPhone . "\n";
        $plainBody .= "Father's Name: " . $fields['father_name'] . "\n";
        $plainBody .= "Father's Phone: " . $displayFatherPhone . "\n";
        $plainBody .= "Parent Email: " . $fields['parent_email'] . "\n";
        $plainBody .= "Parent Address: " . $fields['parent_address'] . "\n\n";

        $plainBody .= "NEXT STEPS:\n";
        $plainBody .= "* Contact parent at: " . $displayMotherPhone . " (Mother) or " . $displayFatherPhone . " (Father)\n";
        $plainBody .= "* Follow up via email: " . $fields['parent_email'] . "\n";

        $mail->AltBody = $plainBody;

        $mail->send();
        $emailSent = true;
        error_log("Application email sent successfully for Application ID: {$applicationId}");

    } catch (Exception $e) {
        error_log('Application email sending failed: ' . $e->getMessage());
        $emailSent = false;
    }
}

/* =========================
   Prepare Success Response
========================= */
$responseData = [
    'application_id' => $applicationId,
    'full_name' => $fields['full_name'],
    'dob' => $dobFormatted ?: $fields['dob'],
    'religion' => $fields['religion'],
    'class_interest' => $fields['class_interest'],
    'gender' => $fields['gender'],
    'nationality' => $fields['nationality'],
    'state' => $fields['state'],
    'city' => $fields['city'],
    'mother_name' => $fields['mother_name'],
    'father_name' => $fields['father_name'],
    'parent_email' => $fields['parent_email'],
    'parent_address' => $fields['parent_address'],
    'address' => $fields['address'],
];

if (!empty($displayStudentPhone)) {
    $responseData['student_phone'] = $displayStudentPhone;
}
if (!empty($fields['student_email'])) {
    $responseData['student_email'] = $fields['student_email'];
}
if (!empty($displayMotherPhone)) {
    $responseData['mother_phone'] = $displayMotherPhone;
}
if (!empty($displayFatherPhone)) {
    $responseData['father_phone'] = $displayFatherPhone;
}

/* =========================
   Success Response
========================= */
header('Content-Type: application/json; charset=UTF-8');
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'success' => true,
    'message' => 'Application submitted successfully! Our admissions team will contact you within 2-3 business days.',
    'application_id' => $applicationId,
    'emailSent' => $emailSent,
    'warnings' => $warnings,
    'databaseSaved' => true,
    'data' => $responseData
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
?>