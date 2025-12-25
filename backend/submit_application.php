<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

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
error_log('Application Request - Content-Type: ' . $contentType);
error_log('Application Request - Is JSON: ' . ($isJson ? 'YES' : 'NO'));

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
error_log('Application Form Data: ' . print_r($data, true));

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
   Field Extraction with HTML Form Field Name Mapping
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

// Map HTML field names (camelCase) to database column names (snake_case)
$fields = [
    // Student Information - HTML: fullName → DB: full_name
    'full_name' => getField($data, ['fullName', 'full_name', 'name'], ''),

    // HTML: dob → DB: date_of_birth
    'dob' => getField($data, ['dob', 'dateOfBirth', 'birth_date', 'birthdate'], ''),

    // HTML: religion → DB: religion
    'religion' => getField($data, ['religion'], ''),

    // HTML: classInterest → DB: class_interest
    'class_interest' => getField($data, ['classInterest', 'class_interest', 'class'], ''),

    // HTML: gender → DB: gender
    'gender' => getField($data, ['gender', 'sex'], ''),

    // HTML: address → DB: address
    'address' => getField($data, ['address', 'home_address'], ''),

    // HTML: nationality → DB: nationality
    'nationality' => getField($data, ['nationality', 'country'], 'Nigeria'),

    // HTML: state → DB: state
    'state' => getField($data, ['state', 'province', 'region'], ''),

    // HTML: city → DB: city
    'city' => getField($data, ['city', 'town'], ''),

    // NEW: HTML: studentPhone → DB: student_phone
    'student_phone' => getField($data, ['studentPhone', 'student_phone', 'phone'], ''),

    // NEW: HTML: studentEmail → DB: student_email
    'student_email' => getField($data, ['studentEmail', 'student_email'], ''),

    // Parent Information - HTML: motherName → DB: mother_name
    'mother_name' => getField($data, ['motherName', 'mother_name', 'mother'], ''),

    // HTML: fatherName → DB: father_name
    'father_name' => getField($data, ['fatherName', 'father_name', 'father'], ''),

    // HTML: motherPhone → DB: mother_phone
    'mother_phone' => getField($data, ['motherPhone', 'mother_phone', 'mother_contact'], ''),

    // HTML: fatherPhone → DB: father_phone
    'father_phone' => getField($data, ['fatherPhone', 'father_phone', 'father_contact'], ''),

    // HTML: parentEmail → DB: parent_email
    'parent_email' => getField($data, ['parentEmail', 'parent_email', 'email'], ''),

    // HTML: parentAddress → DB: parent_address
    'parent_address' => getField($data, ['parentAddress', 'parent_address'], '')
];

// Log extracted fields
error_log('Extracted Fields: ' . print_r($fields, true));

/* =========================
   SIMPLE VALIDATION
========================= */
$errors = [];
$warnings = [];

// REQUIRED FIELDS (matching HTML form requirements)
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

// OPTIONAL FIELDS with basic validation (student email and phone)
if (!empty($fields['student_email']) && !filter_var($fields['student_email'], FILTER_VALIDATE_EMAIL)) {
    if (!preg_match('/^[^@]+@[^@]+\.[^@]+$/', $fields['student_email'])) {
        $warnings['studentEmail'] = 'Student email format appears incorrect';
    }
}

// Date of Birth validation
$dobFormatted = '';
if (!empty($fields['dob'])) {
    // Try multiple date formats
    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
    $dateValid = false;

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $fields['dob']);
        if ($date && $date->format($format) === $fields['dob']) {
            $currentYear = (int) date('Y');
            $birthYear = (int) $date->format('Y');

            // Check if birth year is reasonable (between 1900 and current year)
            if ($birthYear >= 1900 && $birthYear <= $currentYear) {
                $dobFormatted = $date->format('Y-m-d');
                $dateValid = true;
                break;
            }
        }
    }

    // If formal parsing fails, try strtotime as last resort
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

// Phone number validation
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

// Normalize phone numbers
$motherPhone = normalizePhoneSimple($fields['mother_phone']);
$fatherPhone = normalizePhoneSimple($fields['father_phone']);
$studentPhone = normalizePhoneSimple($fields['student_phone']); // NEW

// Check phone lengths if provided
if (!empty($motherPhone) && (strlen($motherPhone) < 10 || strlen($motherPhone) > 15)) {
    $errors['motherPhone'] = 'Mother phone number must be 10-15 digits';
}

if (!empty($fatherPhone) && (strlen($fatherPhone) < 10 || strlen($fatherPhone) > 15)) {
    $errors['fatherPhone'] = 'Father phone number must be 10-15 digits';
}

// Student phone is optional, only validate if provided
if (!empty($studentPhone) && (strlen($studentPhone) < 10 || strlen($studentPhone) > 15)) {
    $warnings['studentPhone'] = 'Student phone number may be invalid';
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
   Generate Application ID
========================= */
$applicationId = 'APP' . date('Ymd') . strtoupper(substr(uniqid(), -6));

/* =========================
   Save to Database
========================= */
try {
    // Prepare data for insertion
    $dbData = [
        ':full_name' => htmlspecialchars($fields['full_name'], ENT_QUOTES, 'UTF-8'),
        ':dob' => $dobFormatted,
        ':religion' => htmlspecialchars($fields['religion'], ENT_QUOTES, 'UTF-8'),
        ':class_interest' => htmlspecialchars($fields['class_interest'], ENT_QUOTES, 'UTF-8'),
        ':gender' => htmlspecialchars($fields['gender'], ENT_QUOTES, 'UTF-8'),
        ':address' => htmlspecialchars($fields['address'], ENT_QUOTES, 'UTF-8'),
        ':nationality' => htmlspecialchars($fields['nationality'], ENT_QUOTES, 'UTF-8'),
        ':state' => htmlspecialchars($fields['state'], ENT_QUOTES, 'UTF-8'),
        ':city' => htmlspecialchars($fields['city'], ENT_QUOTES, 'UTF-8'),
        ':student_phone' => $studentPhone, // NEW
        ':student_email' => htmlspecialchars($fields['student_email'], ENT_QUOTES, 'UTF-8'), // NEW
        ':mother_name' => htmlspecialchars($fields['mother_name'], ENT_QUOTES, 'UTF-8'),
        ':father_name' => htmlspecialchars($fields['father_name'], ENT_QUOTES, 'UTF-8'),
        ':mother_phone' => $motherPhone,
        ':father_phone' => $fatherPhone,
        ':parent_email' => htmlspecialchars($fields['parent_email'], ENT_QUOTES, 'UTF-8'),
        ':parent_address' => htmlspecialchars($fields['parent_address'], ENT_QUOTES, 'UTF-8'),
        ':application_id' => $applicationId,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];

    // Log data before insertion
    error_log('Application DB Data: ' . print_r($dbData, true));

    $stmt = $pdo->prepare("
        INSERT INTO applications (
            full_name, date_of_birth, religion, class_interest, gender, address,
            nationality, state, city, student_phone, student_email, 
            mother_name, father_name, mother_phone, father_phone, 
            parent_email, parent_address,
            submission_date, status, application_id, ip_address
        ) VALUES (
            :full_name, :dob, :religion, :class_interest, :gender, :address,
            :nationality, :state, :city, :student_phone, :student_email,
            :mother_name, :father_name, :mother_phone, :father_phone,
            :parent_email, :parent_address,
            NOW(), 'pending', :application_id, :ip_address
        )
    ");

    $stmt->execute($dbData);

    $insertId = $pdo->lastInsertId();
    error_log("Application saved successfully. ID: $insertId, App ID: $applicationId");

} catch (Throwable $e) {
    error_log('Database insert error: ' . $e->getMessage());
    jsonResponse(500, 'We could not save your application. Please try again later.');
}

/* =========================
   Prepare Success Response
========================= */
$responseData = [
    'application_id' => $applicationId,
    'database_id' => $insertId,
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
    'parent_address' => $fields['parent_address']
];

// Add student phone and email if provided
if (!empty($studentPhone)) {
    $responseData['student_phone'] = $studentPhone;
}
if (!empty($fields['student_email'])) {
    $responseData['student_email'] = $fields['student_email'];
}

// Add parent phone numbers if they exist
if (!empty($motherPhone)) {
    $responseData['mother_phone'] = $motherPhone;
}
if (!empty($fatherPhone)) {
    $responseData['father_phone'] = $fatherPhone;
}

// Add address
if (!empty($fields['address'])) {
    $responseData['address'] = $fields['address'];
}

/* =========================
   Success Response
========================= */
jsonResponse(200, 'Application submitted successfully! Our admissions team will contact you within 2-3 business days.', [
    'application_id' => $applicationId,
    'warnings' => $warnings,
    'databaseSaved' => true,
    'data' => $responseData
]);
?>