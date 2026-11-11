<?php
// MediVault database configuration.
// Uses the existing MySQL80 service on localhost:3306.
$DB_HOST = 'sql101.infinityfree.com';
$DB_PORT = 3306;
$DB_NAME = 'if0_43329511_bookingsystem_db';
$DB_USER = 'if0_43329511';
$DB_PASS = 'Medivault2026';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    static $conn = null;
    if ($conn instanceof mysqli) return $conn;
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $conn->set_charset('utf8mb4');
    return $conn;
}

function json_response(bool $success, string $message = '', array $data = [], int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_login(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user'])) json_response(false, 'Please log in first.', [], 401);
    return $_SESSION['user'];
}

function require_role(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) json_response(false, 'You are not authorised to perform this action.', [], 403);
    return $user;
}

function clean(string $value): string { return trim($value); }

function valid_email(string $email): bool { return (bool)filter_var($email, FILTER_VALIDATE_EMAIL); }

function valid_phone(string $phone): bool { return (bool)preg_match('/^[0-9 +()-]{7,20}$/', $phone); }
