<?php

/* ========================
LOAD ENV VARS
======================== */
require_once __DIR__ . '/env_loader.php';
loadEnv(__DIR__ . '/../../.env');

/* ========================
ENVIRONMENT
======================== */
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Lagos');

/* ========================
ERROR HANDLING
======================== */
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE); // Only critical errors
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

ini_set('log_errors', '1');

$logDir = realpath(__DIR__ . '/../logs') ?: __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php-error.log');

/* ========================
SECURITY HEADERS
======================== */
if (!headers_sent() && php_sapi_name() !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (APP_ENV === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

/* ========================
DATABASE CONFIG
======================== */
$databaseUrl = getenv('DATABASE_URL') ?: null;

if ($databaseUrl) {
    $db = parse_url($databaseUrl);
    $dbHost = $db['host'] ?? '127.0.0.1';
    $dbName = ltrim($db['path'] ?? '/beautiful_minds_school', '/'); // Fixed typo in variable fallback
    $dbUser = $db['user'] ?? 'root';
    $dbPass = $db['pass'] ?? '';
    $dbPort = (int) ($db['port'] ?? 3306);
} else {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'beautiful_minds_school';
    $dbUser = getenv('DB_USER') ?: 'beautiful_minds_web';
    $dbPass = getenv('DB_PASS') ?: ''; // Removed hardcoded password
    $dbPort = (int) (getenv('DB_PORT') ?: 3306);
}

/* ========================
SCHOOL CONFIG
======================== */
define('SCHOOL_NAME', getenv('SCHOOL_NAME') ?: 'Beautiful Minds Schools');
define('SCHOOL_EMAIL', getenv('SCHOOL_EMAIL') ?: 'beautifulmindsschools@gmail.com');
define('SCHOOL_PHONE', getenv('SCHOOL_PHONE') ?: '+234 703 354 6935 | +234 703 095 1884');
define('SCHOOL_ADDRESS', getenv('SCHOOL_ADDRESS') ?: 'John Edia Str, Ankpa Qtrs Extension , Makurdi, Nigeria');

/* ========================
SMTP CONFIG
======================== */
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_USER', getenv('SMTP_USER') ?: SCHOOL_EMAIL);
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: SCHOOL_EMAIL);
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: SCHOOL_NAME);

/* ========================
PDO CONNECTION WITH RETRY
======================== */
function getDatabaseConnection(int $retries = 3, int $delaySeconds = 2): PDO
{
    static $pdo;
    global $dbHost, $dbName, $dbUser, $dbPass, $dbPort;

    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            // Connection lost, reinitialize
            $pdo = null;
        }
    }

    $lastError = null;
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_PERSISTENT => false,
            ]);

            return $pdo;

        } catch (PDOException $e) {
            $lastError = $e;
            error_log("DB CONNECTION ATTEMPT {$attempt} FAILED: " . $e->getMessage());

            if ($attempt < $retries) {
                sleep($delaySeconds);
            }
        }
    } // Final failure - throw exception instead of calling
    throw new PDOException('Database connection failed after ' . $retries . ' attempts: ' . $lastError->getMessage());
}

/* ========================
EMAIL SENDING - UPDATED FOR SENDER DISPLAY
======================== */
function sendEmailFromUser(
    string $userEmail,
    string $userName,
    string $to,
    string $subject,
    string $body,
    bool $isHTML
    = true
): bool {
    // Check if PHPMailer is available
    $phpmailerAvailable = class_exists(' PHPMailer\PHPMailer\PHPMailer') && class_exists('PHPMailer\PHPMailer\SMTP') &&
        class_exists('PHPMailer\PHPMailer\Exception');
    if ($phpmailerAvailable) {
        try {
            $mail = new
                PHPMailer\PHPMailer\PHPMailer(true); // SMTP Configuration $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            // Set sender as the user (for inbox display)
            if (filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $mail->setFrom($userEmail, $userName);
                // Add school as Reply-To for internal tracking
                $mail->addReplyTo(SMTP_FROM, SMTP_FROM_NAME);
            } else {
                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            }

            // Recipients
            $mail->addAddress($to);

            // Email content
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;

            if ($isHTML) {
                $mail->Body = $body . getEmailSignature(true);
                $mail->AltBody = strip_tags($body) . getEmailSignature(false);
            } else {
                $mail->Body = $body . getEmailSignature(false);
            }

            // SMTP Debugging for development
            if (APP_DEBUG) {
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function ($str, $level) {
                    error_log("PHPMailer: $str");
                };
            }

            // Send email
            if ($mail->send()) {
                return true;
            } else {
                error_log('PHPMailer Error: Send failed for user email: ' . $userEmail);
                return false;
            }

        } catch (Exception $e) {
            error_log('PHPMailer Exception for user email ' . $userEmail . ': ' . $e->getMessage());
            // Fall back to sending from school
            return sendEmail($to, $subject, $body, $isHTML, $userEmail);
        }
    }

    // Fallback to native mail function
    return sendEmailNative($to, $subject, $body, $isHTML, $userEmail);
}

function sendEmail(
    string $to,
    string $subject,
    string $body,
    bool $isHTML = true,
    string $replyTo = '',
    array
    $cc = [],
    array $bcc = []
): bool {
    // Check if PHPMailer is available
    $phpmailerAvailable = class_exists('PHPMailer\PHPMailer\PHPMailer') &&
        class_exists('PHPMailer\PHPMailer\SMTP') &&
        class_exists('PHPMailer\PHPMailer\Exception');

    if ($phpmailerAvailable) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            // From address (school)
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);

            // Recipients
            $mail->addAddress($to);

            // Reply-To
            if ($replyTo) {
                $mail->addReplyTo($replyTo);
            }

            // CC recipients
            foreach ($cc as $ccEmail) {
                $mail->addCC($ccEmail);
            }

            // BCC recipients
            foreach ($bcc as $bccEmail) {
                $mail->addBCC($bccEmail);
            }

            // Email content
            $mail->isHTML($isHTML);
            $mail->Subject = $subject;

            if ($isHTML) {
                $mail->Body = $body . getEmailSignature(true);
                $mail->AltBody = strip_tags($body) . getEmailSignature(false);
            } else {
                $mail->Body = $body . getEmailSignature(false);
            }

            // SMTP Debugging for development
            if (APP_DEBUG) {
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function ($str, $level) {
                    error_log("PHPMailer: $str");
                };
            }

            // Send email
            if ($mail->send()) {
                return true;
            } else {
                error_log('PHPMailer Error: Send failed');
                return false;
            }

        } catch (Exception $e) {
            error_log('PHPMailer Exception: ' . $e->getMessage());
            // Fall back to native mail
        }
    }

    // Fallback to native mail function
    return sendEmailNative($to, $subject, $body, $isHTML, $replyTo);
}

function sendEmailNative(
    string $to,
    string $subject,
    string $body,
    bool $isHTML = true,
    string $replyTo = ''
): bool {
    $headers = [
        'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . '>',
        'Reply-To: ' . ($replyTo ?: SMTP_FROM),
        'MIME-Version: 1.0'
        ,
        'X-Mailer: PHP/' . phpversion()
    ];
    if ($isHTML) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $fullBody = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($subject) . '</title>
<style>
body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
.content { background: #f9f9f9; padding: 20px; border-radius: 5px; }
.footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="content">' . $body . '</div>
<div class="footer">' . getEmailSignature(true) . '</div>
</body>
</html>';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $fullBody = $body . "\n\n" .
            getEmailSignature(false);
    }
    $headers = implode("\r\n", $headers);
    $sent = mail(
        $to,
        $subject,
        $fullBody,
        $headers
    );
    if (!$sent) {
        logFailedEmail($to, $subject);
    }
    return $sent;
}
function getEmailSignature(
    bool
    $isHTML
): string {
    if ($isHTML) {
        return '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px;">
<strong>' . SCHOOL_NAME . '</strong><br>
' . SCHOOL_ADDRESS . '<br>
Phone: ' . SCHOOL_PHONE . ' | Email: ' . SCHOOL_EMAIL . '
</div>';
    } else {
        return "\n\n--\n" . SCHOOL_NAME . "\n" . SCHOOL_ADDRESS . "\nPhone: " . SCHOOL_PHONE . " | Email: "
            . SCHOOL_EMAIL;
    }
}
function logFailedEmail(string $to, string $subject): void
{
    $dir = __DIR__
        . '/../logs/email_failures';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $logEntry = '[' . date('Y-m-d
            H:i:s') . '] TO: ' . $to . ' | SUBJECT: ' . $subject . "\n";
    file_put_contents($dir . '/' . date('Y-m-d')
        . '.log', $logEntry, FILE_APPEND);
} /*========================AUTOLOAD PHPMailer if not already
loaded========================*/
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $vendorAutoload = realpath(__DIR__ . '/../vendor/autoload.php');
    if (
        $vendorAutoload &&
        file_exists($vendorAutoload)
    ) {
        require_once $vendorAutoload;
    } else { // Check for PHPMailer in common locations
        $phpmailerPaths = [
            __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
            __DIR__
            . '/phpmailer/PHPMailer.php',
            '/usr/share/php/PHPMailer/PHPMailer.php'
        ];
        foreach ($phpmailerPaths as
            $path) {
            if (file_exists($path)) {
                require_once $path;
                require_once str_replace(
                    'PHPMailer.php',
                    'SMTP.php'
                    ,
                    $path
                );
                require_once str_replace('PHPMailer.php', 'Exception.php', $path);
                break;
            }
        }
    }
}
/*========================COMMON FUNCTIONS========================*/
function sanitizeInput($data): string
{
    if (is_array($data)) {
        return '';
    }
    $data = trim((string) $data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function validateEmail(string $email): bool
{
    if (empty($email)) {
        return false;
    }
    // Basic email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    // Check for common patterns
    if (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) {
        return false;
    }
    return true;
}

function normalizePhone(string $phone): string
{
    $phone = trim($phone);
    // Remove all non-digit characters except leading +
    $digits = preg_replace('/[^\d]/', '', $phone);
    if (empty($digits)) {
        return $phone; // Return original if no digits found
    }
    // Handle Nigerian numbers: convert 0XXXXXXXXXX to 234XXXXXXXXXX
    if (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
        return '234' . substr($digits, 1);
    }
    // Handle numbers with country code already
    if (strlen($digits) >= 10 && strlen($digits) <= 15) {
        return $digits;
    }
    return $phone; // Return original if can't normalize
}
?>