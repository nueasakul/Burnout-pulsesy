<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_admin();

$pdo = db();
$method = request_method();
check_method(['GET', 'POST', 'PUT', 'DELETE']);

// ระบบนี้จัดการเฉพาะพนักงานใน registered_users ไม่มีการใช้ visitor_cards
$type = strtolower((string)($_GET['type'] ?? 'employee'));
if ($type !== 'employee') {
    json_response(['error' => 'ระบบนี้รองรับเฉพาะข้อมูลพนักงาน'], 410);
}

function employee_duplicate_rfid(PDO $pdo, string $rfid, ?int $ignoreId = null): bool
{
    $normalizedSql = "UPPER(REPLACE(REPLACE(REPLACE(rfid_uid, ':', ''), '-', ''), ' ', ''))";
    if ($ignoreId !== null) {
        $sql = "SELECT id FROM registered_users WHERE {$normalizedSql} = ? AND id <> ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rfid, $ignoreId]);
    } else {
        $sql = "SELECT id FROM registered_users WHERE {$normalizedSql} = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rfid]);
    }
    return (bool)$stmt->fetch();
}

function employee_payload(array $body): array
{
    $name = trim((string)($body['name'] ?? ''));
    $rfid = normalize_rfid($body['rfid'] ?? '');

    if ($name === '' || mb_strlen($name) > 150) {
        json_response(['error' => 'กรุณากรอกชื่อ-นามสกุล ไม่เกิน 150 ตัวอักษร', 'field' => 'name'], 422);
    }
    if ($rfid === null) {
        json_response(['error' => 'RFID ไม่ถูกต้อง กรุณาตรวจเลขฐานสิบหก 8–20 ตัว', 'field' => 'rfid'], 422);
    }

    return ['name' => $name, 'rfid' => $rfid];
}

if ($method === 'GET') {
    $sql = "SELECT u.id, u.username AS name, u.rfid_uid,
            EXISTS (
                SELECT 1 FROM user_logs l
                WHERE UPPER(REPLACE(REPLACE(REPLACE(l.rfid_uid, ':', ''), '-', ''), ' ', '')) =
                      UPPER(REPLACE(REPLACE(REPLACE(u.rfid_uid, ':', ''), '-', ''), ' ', ''))
                  AND DATE(l.recorded_time) = CURDATE()
            ) AS checked_in_today
            FROM registered_users u ORDER BY u.username ASC";
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'type' => 'employee',
            'name' => (string)$row['name'],
            'rfid' => display_rfid((string)$row['rfid_uid']),
            'checked_in_today' => (bool)$row['checked_in_today'],
        ];
    }
    json_response($out);
}

if ($method === 'POST') {
    $body = json_input();
    if (isset($body['type']) && strtolower((string)$body['type']) !== 'employee') {
        json_response(['error' => 'ระบบนี้รองรับเฉพาะข้อมูลพนักงาน'], 410);
    }
    $data = employee_payload($body);

    if (employee_duplicate_rfid($pdo, $data['rfid'])) {
        json_response(['error' => 'RFID นี้ถูกใช้งานแล้ว', 'field' => 'rfid'], 409);
    }

    $stmt = $pdo->prepare('INSERT INTO registered_users (rfid_uid, username) VALUES (?, ?)');
    $stmt->execute([$data['rfid'], $data['name']]);
    json_response([
        'id' => (int)$pdo->lastInsertId(),
        'type' => 'employee',
        'name' => $data['name'],
        'rfid' => display_rfid($data['rfid']),
        'checked_in_today' => false,
    ], 201);
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) json_response(['error' => 'ไม่พบรหัสรายการ'], 422);

if ($method === 'PUT') {
    $body = json_input();
    if (isset($body['type']) && strtolower((string)$body['type']) !== 'employee') {
        json_response(['error' => 'ระบบนี้รองรับเฉพาะข้อมูลพนักงาน'], 410);
    }
    $data = employee_payload($body);
    if (employee_duplicate_rfid($pdo, $data['rfid'], $id)) {
        json_response(['error' => 'RFID นี้ถูกใช้งานแล้ว', 'field' => 'rfid'], 409);
    }

    $stmt = $pdo->prepare('UPDATE registered_users SET username = ?, rfid_uid = ? WHERE id = ?');
    $stmt->execute([$data['name'], $data['rfid'], $id]);

    if ($stmt->rowCount() === 0) {
        // rowCount=0 อาจหมายถึงข้อมูลไม่เปลี่ยน จึงตรวจว่ารายการยังมีอยู่หรือไม่
        $check = $pdo->prepare('SELECT id FROM registered_users WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) json_response(['error' => 'ไม่พบรายการที่ต้องการแก้ไข'], 404);
    }
    json_response(['ok' => true, 'id' => $id]);
}

if ($method === 'DELETE') {
    $stmt = $pdo->prepare('DELETE FROM registered_users WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_response(['error' => 'ไม่พบรายการที่ต้องการลบ'], 404);
    json_response(['ok' => true]);
}
