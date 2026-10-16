<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_admin();
check_method(['GET']);

$pdo = db();
$limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT);
if ($limit === false || $limit < 1) $limit = 10;
$limit = min((int)$limit, 500);

$total = (int)$pdo->query('SELECT COUNT(*) FROM user_logs')->fetchColumn();

$sql = "SELECT l.recorded_time, l.rfid_uid, u.username AS employee_name,
               l.camera_state, l.hrv, l.survey_answer
        FROM user_logs l
        LEFT JOIN registered_users u
          ON UPPER(REPLACE(REPLACE(REPLACE(u.rfid_uid, ':', ''), '-', ''), ' ', '')) =
             UPPER(REPLACE(REPLACE(REPLACE(l.rfid_uid, ':', ''), '-', ''), ' ', ''))
        ORDER BY l.recorded_time DESC
        LIMIT :limit";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = [];
foreach ($stmt->fetchAll() as $index => $row) {
    $time = $row['recorded_time'] ? strtotime((string)$row['recorded_time']) : false;
    $rawState = $row['camera_state'];
    if ($rawState === null || $rawState === '') {
        $faceStatus = 'unknown';
    } else {
        $stateText = strtolower(trim((string)$rawState));
        $faceStatus = in_array($stateText, ['0', 'normal', 'normal_face', 'ok'], true) ? 'normal' : 'risk';
    }

    $rows[] = [
        'id' => $index + 1,
        'name' => (string)($row['employee_name'] ?: display_rfid((string)$row['rfid_uid'])),
        'rfid' => display_rfid((string)$row['rfid_uid']),
        'date' => $time !== false ? date('Y-m-d', $time) : '',
        'time' => $time !== false ? date('H:i:s', $time) : '',
        'face_status' => $faceStatus,
        'camera_state' => $rawState,
        'hrv' => $row['hrv'] !== null ? (float)$row['hrv'] : null,
        'survey_answer' => $row['survey_answer'] !== null ? (float)$row['survey_answer'] : null,
    ];
}

json_response(['total' => $total, 'rows' => $rows]);
