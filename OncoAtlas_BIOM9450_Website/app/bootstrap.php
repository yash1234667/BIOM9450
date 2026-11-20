<?php
// -----------------------------------------------------------------------------
// OncoAtlas: shared PHP setup (similar to PetStore's apps/db.php).
//
// Every PHP page includes this file through require_once.
// It provides the database connection, login checks, form protection,
// small SQL helpers, and activity logging.
//
// We keep PDO because the existing prediction code uses PDO transactions.
// -----------------------------------------------------------------------------

declare(strict_types=1);

const ROOT_DIR = __DIR__ . '/..';

// 1. Start a PHP session so we can remember which user has logged in.
// The settings help protect the session cookie.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

// 2. Load the MySQL settings from config/config.php.
// "static" means PHP only loads the file once, even if config() is called again.
function config(): array {
    static $settings = null;

    if ($settings === null) {
        $settings = require ROOT_DIR . '/config/config.php';
    }

    return $settings;
}

// 3. Open the database connection. PDO is PHP's database interface.
// We reuse one connection, rather than opening a new one for every query.
function db(): PDO {
    static $connection = null;

    if ($connection === null) {
        $settings = config();

        $dsn = "mysql:host={$settings['db_host']};"
             . "port={$settings['db_port']};"
             . "dbname={$settings['db_name']};charset=utf8mb4";

        $connection = new PDO($dsn, $settings['db_user'], $settings['db_pass']);

        // Throw an error when a SQL statement fails (caught in the page).
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Return database rows as arrays: $row['Name'], $row['Age'], etc.
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Send prepared SQL statements to MySQL, instead of building SQL
        // by adding user-submitted text to a query string.
        $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    return $connection;
}

// 4. Display database or form text safely inside HTML.
// htmlspecialchars() stops a name such as "<script>" becoming HTML code.
function h(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Print a dash when an optional database field has no value.
// h() is still used to escape all other values.
function display(mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return h($value);
}

// 5. Check the user saved in $_SESSION when the login form succeeds.
// The role controls which pages a user can access.
function app_user(): ?array {
    return $_SESSION['account'] ?? null;
}

function account_role(): string {
    return (string)(app_user()['role'] ?? '');
}

function signed_in(): bool {
    return app_user() !== null;
}

function is_professional(): bool {
    return account_role() === 'Researcher' || account_role() === 'Oncologist';
}

function is_researcher(): bool {
    return account_role() === 'Researcher';
}

// Send the browser to another page, then stop running this PHP script.
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// This page requires a login. AJAX requests receive JSON instead of a redirect.
function require_login(): void {
    if (signed_in()) {
        return;
    }

    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        json_response(['error' => 'Sign in required'], 401);
    }

    redirect('login.php');
}

// Both oncologists and researchers may view patients and mutations.
function require_professional(): void {
    require_login();

    if (!is_professional()) {
        forbidden();
    }
}

// Advanced analytics are available only to researchers.
function require_researcher(): void {
    require_login();

    if (!is_researcher()) {
        forbidden();
    }
}

function forbidden(): never {
    http_response_code(403);

    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        json_response(['error' => 'Access denied'], 403);
    }

    exit('403 — You do not have permission to view this page.');
}

// 6. Forms use a hidden CSRF token. This checks that a form was submitted
// from this user's session rather than from another website.
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        throw new RuntimeException('This form expired. Refresh and try again.');
    }
}

// 7. Flash messages appear once, on the next page, after a redirect.
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['text' => $message, 'type' => $type];
}

function flash_take(): ?array {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

// 8. AJAX uses JSON. json_encode() converts a PHP array into JSON text.
// exit stops any HTML from being added after the JSON.
function json_response(array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// 9. Save important actions in the AuditLog table.
// An audit problem must not accidentally crash a normal page.
function audit(string $action, ?string $entity = null, ?string $entityId = null): void {
    try {
        $sql = 'INSERT INTO AuditLog (UserID, Action, EntityType, EntityID)
                VALUES (?, ?, ?, ?)';
        $stmt = db()->prepare($sql);
        $stmt->execute([app_user()['id'] ?? null, $action, $entity, $entityId]);
    } catch (Throwable $error) {
        error_log('Audit error: ' . $error->getMessage());
    }
}

// 10. Read a positive or zero integer from GET or POST inputs.
// This rejects letters, decimals, negatives and values outside the range.
function int_param(mixed $value, int $minimum = 1, int $maximum = 2147483647): ?int {
    if (!is_scalar($value) || !preg_match('/^[0-9]+$/', (string)$value)) {
        return null;
    }

    $number = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum, 'max_range' => $maximum]
    ]);

    return $number === false ? null : $number;
}

// Missing values in cBioPortal are often blank, NA or a period.
// We turn these into SQL NULL instead of storing misleading text.
function clean_nullable(mixed $value): ?string {
    $text = trim((string)($value ?? ''));

    if ($text === '' || in_array(strtoupper($text), ['NA', 'N/A', 'NULL', 'NOT AVAILABLE', '.'], true)) {
        return null;
    }

    return $text;
}

// 11. These three helpers keep SELECT queries easy to read on pages.
// All question marks (?) are values supplied separately through execute().
//
// query_rows()  -> many rows, such as all cancer types
// query_one()   -> one row, such as a single patient
// query_value() -> one value, such as COUNT(*) or a primary key
function query_rows(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function query_value(string $sql, array $params = []): mixed {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function require_get(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response(['error' => 'GET required'], 405);
    }
}

// Read one optional form field while checking its maximum length.
function nullable_post(string $key, int $max = 255): ?string {
    $value = clean_nullable($_POST[$key] ?? null);

    if ($value !== null && strlen($value) > $max) {
        throw new RuntimeException("$key is too long (max $max characters).");
    }

    return $value;
}

// 12. Patient accounts only see the patient profile linked to their UserID.
function linked_patient_id(): ?int {
    $user = app_user();
    if (!$user) {
        return null;
    }

    $id = query_value('SELECT PatientID FROM Patient WHERE UserID = ?', [$user['id']]);

    return $id === false ? null : (int)$id;
}

function can_view_patient(int $patientId): bool {
    if (is_professional()) {
        return true;
    }

    return account_role() === 'Patient' && linked_patient_id() === $patientId;
}
