<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// API ส่ง JSON เท่านั้น ไม่ให้ PHP warning ปะปนกับ response
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['error' => 'รูปแบบข้อมูลที่ส่งมาไม่ถูกต้อง'], 400);
    return $data;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_user'])) {
        json_response(['error' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน'], 401);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('Burnout Pulse DB connection error: ' . $e->getMessage());
        json_response(['error' => 'เชื่อมต่อฐานข้อมูลไม่ได้ ตรวจสอบค่า DB ใน api/config.php และสถานะ MySQL ใน XAMPP'], 500);
    }
    return $pdo;
}

function normalize_rfid($value): ?string
{
    $rfid = strtoupper((string)$value);
    $rfid = preg_replace('/[\s:\-]/u', '', $rfid) ?? '';
    if ($rfid === '' || !preg_match('/^[0-9A-F]{8,20}$/', $rfid) || strlen($rfid) % 2 !== 0) {
        return null;
    }
    return $rfid;
}

function display_rfid(?string $value): string
{
    $rfid = normalize_rfid($value);
    if ($rfid === null) return (string)$value;
    return implode(':', str_split($rfid, 2));
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function check_method(array $allowed): void
{
    if (!in_array(request_method(), $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_response(['error' => 'Method นี้ไม่รองรับ'], 405);
    }
}
