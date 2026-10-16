<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_admin();
$pdo = db();
$method = request_method();
check_method(['GET', 'POST', 'PUT', 'DELETE']);

function visitor_data(array $body): array {
    $name = trim((string)($body['name'] ?? ''));
    $rfid = normalize_rfid($body['rfid'] ?? '');
    $organization = trim((string)($body['organization'] ?? ''));
    $purpose = trim((string)($body['purpose'] ?? ''));
    $status = (string)($body['status'] ?? 'active');
    if ($name === '' || mb_strlen($name) > 150) json_response(['error'=>'กรุณากรอกชื่อ ไม่เกิน 150 ตัวอักษร','field'=>'name'], 422);
    if ($rfid === null) json_response(['error'=>'RFID ต้องเป็นเลขฐานสิบหก 8–20 ตัว','field'=>'rfid'], 422);
    if (mb_strlen($organization) > 150 || mb_strlen($purpose) > 255) json_response(['error'=>'หน่วยงานหรือวัตถุประสงค์ยาวเกินไป'], 422);
    if (!in_array($status, ['active', 'inactive'], true)) json_response(['error'=>'สถานะไม่ถูกต้อง','field'=>'status'], 422);
    return compact('name','rfid','organization','purpose','status');
}
function visitor_rfid_in_use(PDO $pdo, string $rfid, ?int $ignoreId = null): bool {
    $normalized = "UPPER(REPLACE(REPLACE(REPLACE(rfid_uid, ':', ''), '-', ''), ' ', ''))";
    $q = $pdo->prepare("SELECT id FROM visitor_cards WHERE $normalized = ?" . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1');
    $args = [$rfid];
    if ($ignoreId) $args[] = $ignoreId;
    $q->execute($args);
    if ($q->fetch()) return true;
    $q = $pdo->prepare("SELECT id FROM registered_users WHERE $normalized = ? LIMIT 1");
    $q->execute([$rfid]);
    return (bool)$q->fetch();
}
function visitor_output(array $row): array {
    return [
        'id'=>(int)$row['id'], 'type'=>'visitor',
        'name'=>(string)$row['username'], 'rfid'=>display_rfid((string)$row['rfid_uid']),
        'organization'=>(string)($row['organization'] ?? ''),
        'purpose'=>(string)($row['purpose'] ?? ''),
        'status'=>(string)$row['status'],
    ];
}
try {
    if ($method === 'GET') {
        $rows = $pdo->query('SELECT id, rfid_uid, username, organization, purpose, status FROM visitor_cards ORDER BY id DESC')->fetchAll();
        json_response(array_map('visitor_output', $rows));
    }
    if ($method === 'POST') {
        $data = visitor_data(json_input());
        if (visitor_rfid_in_use($pdo, $data['rfid'])) json_response(['error'=>'RFID นี้ถูกใช้งานแล้ว','field'=>'rfid'],409);
        $q = $pdo->prepare('INSERT INTO visitor_cards (username,rfid_uid,organization,purpose,status) VALUES (?,?,?,?,?)');
        $q->execute([$data['name'],$data['rfid'],$data['organization'],$data['purpose'],$data['status']]);
        json_response(['id'=>(int)$pdo->lastInsertId(),'type'=>'visitor','name'=>$data['name'],'rfid'=>display_rfid($data['rfid']),'organization'=>$data['organization'],'purpose'=>$data['purpose'],'status'=>$data['status']],201);
    }
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) json_response(['error'=>'ไม่พบรหัสรายการ'],422);
    if ($method === 'PUT') {
        $data = visitor_data(json_input());
        if (visitor_rfid_in_use($pdo, $data['rfid'], (int)$id)) json_response(['error'=>'RFID นี้ถูกใช้งานแล้ว','field'=>'rfid'],409);
        $q = $pdo->prepare('UPDATE visitor_cards SET username=?,rfid_uid=?,organization=?,purpose=?,status=? WHERE id=?');
        $q->execute([$data['name'],$data['rfid'],$data['organization'],$data['purpose'],$data['status'],$id]);
        $check = $pdo->prepare('SELECT id FROM visitor_cards WHERE id=?');
        $check->execute([$id]);
        if (!$check->fetch()) json_response(['error'=>'ไม่พบผู้มาเยือน'],404);
        json_response(['ok'=>true,'id'=>(int)$id]);
    }
    if ($method === 'DELETE') {
        $q = $pdo->prepare('DELETE FROM visitor_cards WHERE id=?');
        $q->execute([$id]);
        if (!$q->rowCount()) json_response(['error'=>'ไม่พบผู้มาเยือน'],404);
        json_response(['ok'=>true]);
    }
} catch (PDOException $e) {
    error_log('Visitor API DB error: '.$e->getMessage());
    if ($e->getCode() === '23000') json_response(['error'=>'RFID นี้ถูกใช้งานแล้ว','field'=>'rfid'],409);
    json_response(['error'=>'ดึงหรือบันทึกข้อมูลผู้มาเยือนไม่สำเร็จ ตรวจสอบ visitor_cards และสิทธิ์ฐานข้อมูล'],500);
}
