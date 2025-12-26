<?php
declare(strict_types=1);

/* ========================
   ENVIRONMENT
======================== */
define('APP_ENV', getenv('APP_ENV') ?: 'production');
define('APP_DEBUG', APP_ENV !== 'production');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Lagos');

/* ========================
   ERROR HANDLING
======================== */
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

$logDir = realpath(__DIR__ . '/../logs') ?: __DIR__ . '/../logs';
if (!is_dir($logDir))
    mkdir($logDir, 0755, true);
ini_set('error_log', $logDir . '/php-error.log');

/* ========================
   SECURITY HEADERS
======================== */
if (!headers_sent()) {
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
    $dbName = ltrim($db['path'] ?? '/beautiful_minds_schools', '/');
    $dbUser = $db['user'] ?? 'root';
    $dbPass = $db['pass'] ?? '';
    $dbPort = (int) ($db['port'] ?? 3306);
} else {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'beautiful_minds_school';
    $dbUser = getenv('DB_USER') ?: 'beautiful_minds_web';
    $dbPass = getenv('DB_PASS') ?: 'B3autiful!M1nds2025';
    $dbPort = (int) (getenv('DB_PORT') ?: 3306);
}

/* ========================
   SCHOOL CONFIG
======================== */
define('SCHOOL_NAME', getenv('SCHOOL_NAME') ?: 'Beautiful Minds Schools');
define('SCHOOL_EMAIL', getenv('SCHOOL_EMAIL') ?: 'beautifulmindsschools@gmail.com');
define('SCHOOL_PHONE', getenv('SCHOOL_PHONE') ?: '+2347033546935');
define('SCHOOL_ADDRESS', getenv('SCHOOL_ADDRESS') ?: 'John Edia Str, Ankpa Qtrs Extension , Makurdi, Nigeria');

/* ========================
   SMTP CONFIG
======================== */
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('SMTP_USER', getenv('SMTP_USER') ?: SCHOOL_EMAIL);
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'uosk qqgm ctsm kjcc');
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
        return $pdo;
    }

    $lastError = null;

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbHost,
                $dbPort,
                $dbName
            );

            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            return $pdo;

        } catch (PDOException $e) {
            $lastError = $e;
            error_log("DB CONNECTION ATTEMPT {$attempt} FAILED: " . $e->getMessage());
            sleep($delaySeconds);
        }
    }

    // Final failure response
    if (!headers_sent() && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => APP_DEBUG ? $lastError->getMessage() : 'Database unavailable'
        ]);
        exit;
    }

    die(APP_DEBUG ? $lastError->getMessage() : 'Service unavailable');
}


/* ========================
   EMAIL SENDING
======================== */
function sendEmail(string $to, string $subject, string $body, bool $isHTML = true, string $replyTo = ''): bool
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to);
            if ($replyTo)
                $mail->addReplyTo($replyTo);

            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body = $body . getEmailSignature(true);
            $mail->AltBody = strip_tags($body) . getEmailSignature(false);

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('PHPMailer Error: ' . $e->getMessage());
        }
    }

    return sendEmailNative($to, $subject, $body, $isHTML, $replyTo);
}

function sendEmailNative(string $to, string $subject, string $body, bool $isHTML = true, string $replyTo = ''): bool
{
    $headers = [
        'From: ' . SCHOOL_NAME . ' <' . SCHOOL_EMAIL . '>',
        'Reply-To: ' . ($replyTo ?: SCHOOL_EMAIL),
        'MIME-Version: 1.0',
        'Content-Type: ' . ($isHTML ? 'text/html' : 'text/plain') . '; charset=UTF-8'
    ];

    $sent = mail($to, $subject, $body . getEmailSignature($isHTML), implode("\r\n", $headers));
    if (!$sent)
        logFailedEmail($to, $subject);
    return $sent;
}

function getEmailSignature(bool $isHTML): string
{
    return $isHTML
        ? "<br><br><strong>" . SCHOOL_NAME . "</strong><br>" . SCHOOL_ADDRESS .
        "<br>Phone: " . SCHOOL_PHONE . "<br>Email: " . SCHOOL_EMAIL
        : "\n\n--\n" . SCHOOL_NAME . "\n" . SCHOOL_ADDRESS .
        "\nPhone: " . SCHOOL_PHONE . "\nEmail: " . SCHOOL_EMAIL;
}

function logFailedEmail(string $to, string $subject): void
{
    $dir = __DIR__ . '/../logs/email_failures';
    if (!is_dir($dir))
        mkdir($dir, 0755, true);
    file_put_contents(
        $dir . '/' . date('Y-m-d') . '.log',
        '[' . date('H:i:s') . "] TO: $to | SUBJECT: $subject\n",
        FILE_APPEND
    );
}

/* ========================
   AUTOLOAD PHPMailer
======================== */
$vendor = realpath(__DIR__ . '/../vendor');
if ($vendor && file_exists($vendor . '/autoload.php')) {
    require_once $vendor . '/autoload.php';
}
