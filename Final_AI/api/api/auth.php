<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$method = request_method();
$action = strtolower(trim((string)($_GET['action'] ?? 'session')));

if ($action === 'session') {
    if ($method !== 'GET') {
        header('Allow: GET');
        json_response(['error' => 'Session endpoint requires GET'], 405);
    }

    $role = null;
    $user = null;

    if (!empty($_SESSION['hr_user'])) {
        $role = 'hr';
        $user = (string)$_SESSION['hr_user'];
    } elseif (!empty($_SESSION['admin_user'])) {
        $role = 'admin';
        $user = (string)$_SESSION['admin_user'];
    }

    json_response([
        'authenticated' => $role !== null,
        'user' => $user,
        'role' => $role,
    ]);
}

if ($action === 'login') {
    if ($method !== 'POST') {
        header('Allow: POST');
        json_response(['error' => 'Login endpoint requires POST'], 405);
    }

    $body = json_input();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_response(['error' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'], 400);
    }

    $isAdmin = defined('ADMIN_USERNAME')
        && defined('ADMIN_PASSWORD_HASH')
        && hash_equals((string)ADMIN_USERNAME, $username)
        && password_verify($password, (string)ADMIN_PASSWORD_HASH);

    $isHR = defined('HR_USERNAME')
        && defined('HR_PASSWORD_HASH')
        && hash_equals((string)HR_USERNAME, $username)
        && password_verify($password, (string)HR_PASSWORD_HASH);

    if (!$isAdmin && !$isHR) {
        json_response(['error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
    }

    session_regenerate_id(true);
    // Ensure a session never retains both roles.
    unset($_SESSION['admin_user'], $_SESSION['hr_user']);

    if ($isHR) {
        $_SESSION['hr_user'] = $username;
        $role = 'hr';
    } else {
        $_SESSION['admin_user'] = $username;
        $role = 'admin';
    }

    json_response(['user' => $username, 'role' => $role]);
}

if ($action === 'logout') {
    if ($method !== 'POST') {
        header('Allow: POST');
        json_response(['error' => 'Logout endpoint requires POST'], 405);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    json_response(['ok' => true]);
}

json_response(['error' => 'ไม่พบ action ที่ร้องขอ'], 404);
