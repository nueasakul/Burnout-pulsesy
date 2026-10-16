<?php
// ไฟล์ api.php 
header('Content-Type: application/json; charset=utf-8');

$servername = "localhost"; // บน Cloud ใช้ localhost 
$username_db = "st67010999_burnout";
$password_db = "Af9UXvXtd32mm53wAFC2";
$dbname = "st67010999_burnout";

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB Connection Failed"]);
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// ฟังก์ชันตรวจสอบบัตร RFID
if ($action == 'verify_rfid') {
    $rfid_uid = isset($_POST['rfid_uid']) ? $_POST['rfid_uid'] : '';
    
    $stmt = $conn->prepare("SELECT username FROM registered_users WHERE rfid_uid = ?");
    $stmt->bind_param("s", $rfid_uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode(["status" => "success", "is_valid" => true, "hierarchy" => "registered_users", "username" => $row['username']]);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT username FROM visitor_cards WHERE rfid_uid = ?");
    $stmt->bind_param("s", $rfid_uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode(["status" => "success", "is_valid" => true, "hierarchy" => "visitor_cards", "username" => $row['username']]);
        exit;
    }
    
    echo json_encode(["status" => "success", "is_valid" => false]);
    exit;
}

// ฟังก์ชันบันทึกข้อมูลผลลัพธ์
if ($action == 'save_log') {
    $rfid_uid = isset($_POST['rfid_uid']) ? $_POST['rfid_uid'] : '';
    $camera_state = isset($_POST['camera_state']) ? $_POST['camera_state'] : 0;
    $hrv = isset($_POST['hrv']) ? $_POST['hrv'] : 0;
    $survey_answer = isset($_POST['survey_answer']) ? $_POST['survey_answer'] : 0;
    $recorded_time = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO user_logs (rfid_uid, camera_state, survey_answer, hrv, recorded_time) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sidds", $rfid_uid, $camera_state, $survey_answer, $hrv, $recorded_time);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Saved"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Insert Failed"]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Invalid action"]);
$conn->close();
?>