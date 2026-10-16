<?php
// ==========================================
// สคริปต์จำลองข้อมูลสำหรับ Database B (st67010999_burnout)
// ==========================================
$servername = "localhost";
$username_db = "st67010999_burnout";
$password_db = "Af9UXvXtd32mm53wAFC2";
$dbname = "st67010999_burnout";

$conn = new mysqli($servername, $username_db, $password_db, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// กำหนดเป้าหมายไปที่ id 7 (RFID: 11111111)
$test_rfid = "11111111";

// 1. สุ่มข้อมูลลงในตาราง user_logs (จำลอง 35 ครั้งย้อนหลัง)
echo "กำลังส่งข้อมูลจำลองเข้า user_logs สำหรับ RFID: $test_rfid...<br>";
for ($i = 35; $i >= 1; $i--) {
    $recorded_time = date('Y-m-d H:i:s', strtotime("-$i hours"));
    $camera_state = (rand(1, 10) > 7) ? 1 : 0; // โอกาสเป็น Burnout (1) หรือ Neutral (0)
    $survey_answer = rand(10, 85);
    $hrv = rand(55, 95);

    $conn->query("INSERT INTO user_logs (rfid_uid, camera_state, survey_answer, hrv, recorded_time) 
                  VALUES ('$test_rfid', '$camera_state', '$survey_answer', '$hrv', '$recorded_time')");
}

// 2. สุ่มข้อมูลลงในตาราง summary_results (สำหรับคำนวณ Overall Risk)
echo "กำลังส่งข้อมูลจำลองเข้า summary_results สำหรับ RFID: $test_rfid...<br>";
for ($i = 35; $i >= 1; $i--) {
    $created_at = date('Y-m-d H:i:s', strtotime("-$i hours"));
    $final_score = rand(20, 90);

    $conn->query("INSERT INTO summary_results (rfid_uid, final_score, created_at) 
                  VALUES ('$test_rfid', '$final_score', '$created_at')");
}

echo "<h3 style='color: green;'>✅ ส่งค่าจำลองเข้า Database B (id 7 / RFID: 11111111) สำเร็จเรียบร้อย!</h3>";
echo "<a href='index.php?rfid=$test_rfid'>คลิกที่นี่เพื่อไปดูหน้า Dashboard ของ test (id 7)</a>";

$conn->close();
?>