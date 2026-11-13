<?php
declare(strict_types=1);

/*
 * Database configuration
 * Local development can use the defaults below. On InfinityFree, enter the
 * database hostname, name, username and password shown in its MySQL Databases
 * panel. Do not upload this file with real credentials to a public repository.
 */
const INFINITYFREE_DB_HOST = 'MY_DB_HOST';
const INFINITYFREE_DB_NAME = 'MY_DB_NAME';
const INFINITYFREE_DB_USER = 'MY_DB_USER';
const INFINITYFREE_DB_PASSWORD = 'MY_DB_PASSWORD';

function db(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $requestHost = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST));
    $isLocal = in_array($requestHost, ['localhost', '127.0.0.1'], true);
    $host = getenv('MEDIVAULT_DB_HOST') ?: ($isLocal ? '127.0.0.1' : INFINITYFREE_DB_HOST);
    $user = getenv('MEDIVAULT_DB_USER') ?: ($isLocal ? 'root' : INFINITYFREE_DB_USER);
    $password = getenv('MEDIVAULT_DB_PASSWORD') ?: ($isLocal ? '' : INFINITYFREE_DB_PASSWORD);
    $database = getenv('MEDIVAULT_DB_NAME') ?: ($isLocal ? 'medivault' : INFINITYFREE_DB_NAME);
    $port = (int)(getenv('MEDIVAULT_DB_PORT') ?: 3306);

    if ($host === '' || $user === '' || $database === '') {
        throw new RuntimeException('InfinityFree database settings are missing. Edit config.php with the MySQL host, database name, username, and password from the hosting control panel.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($host, $user, $password, $database, $port);
    $connection->set_charset('utf8mb4');
    return $connection;
}

function clean(string $value): string
{
    return trim($value);
}

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_phone(string $phone): bool
{
    return preg_match('/^[0-9+().\s-]{7,25}$/', $phone) === 1;
}

function is_clinic_slot(string $date, string $time): bool
{
    $dateValue = DateTime::createFromFormat('!Y-m-d', $date);
    $timeValue = DateTime::createFromFormat('!H:i', $time);
    if (!$dateValue || !$timeValue || $dateValue->format('Y-m-d') !== $date || $timeValue->format('H:i') !== $time) {
        return false;
    }
    $weekday = (int)$dateValue->format('N');
    $minutes = ((int)$timeValue->format('H') * 60) + (int)$timeValue->format('i');
    return $weekday >= 1 && $weekday <= 5 && $minutes >= 540 && $minutes < 1020 && $minutes % 30 === 0;
}

function json_response(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function require_login(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['user_id'])) {
        json_response(false, 'Please log in to continue.', [], 401);
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'] ?? '', $roles, true)) {
        json_response(false, 'You do not have permission to perform this action.', [], 403);
    }
    return $user;
}
