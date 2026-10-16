import cv2
import time
import serial
from pathlib import Path
from collections import Counter
from ultralytics import YOLO
import mysql.connector

# ==================== 1. ตั้งค่า Serial Port & DB ====================
SERIAL_PORT = 'COM7'   # ⚠️ เปลี่ยนพอร์ต COM ให้ตรงกับ ESP32-C3
BAUD_RATE = 115200

DB_CONFIG = {
    "host": "10.26.1.95",       # IP เครื่องเซิร์ฟเวอร์ phpMyAdmin / MySQL
    "user": "admin_user",
    "password": "123456",
    "database": "my_database",
    "port": 3306
}

# ==================== 2. โหลดโมเดล YOLOv8 ====================
BASE_DIR = Path(__file__).resolve().parent
MODEL_PATH = BASE_DIR / "best.pt"
model = YOLO(str(MODEL_PATH))

# เงื่อนไขเวลาและจำนวนตัวอย่างสแกนหน้า
VERIFY_DURATION = 3.0   # เวลาสแกนยืนยันใบหน้า
SAMPLE_INTERVAL = 3.0   # ระยะห่างการเก็บค่า AI (วินาที)
TARGET_SAMPLES = 5      # จำนวนตัวอย่างสแกนหน้า

# ตัวแปรเก็บข้อมูลผู้ใช้งานปัจจุบันที่แตะบัตรผ่าน
current_user = {"hierarchy": "None", "username": "Guest", "uid": None}

# ==================== 3. ฟังก์ชันเช็กบัตร RFID กับ Database ====================


def verify_rfid(scanned_uid):
    db = None
    try:
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor(dictionary=True)

        sql_query = "SELECT * FROM registered_users WHERE rfid_uid = %s"
        cursor.execute(sql_query, (scanned_uid,))
        user = cursor.fetchone()

        if user:
            username = user.get('username', 'Unknown')
            Hierarchy = 'registered_users'
            print(
                f"✅ [registered_users] พบผู้ใช้: {username} (ID: {Hierarchy}) | UID: {scanned_uid}")
            return True, Hierarchy, username
        
        sql_query = "SELECT * FROM visitor_cards WHERE rfid_uid = %s"
        cursor.execute(sql_query, (scanned_uid,))
        user = cursor.fetchone()
        if user:
            username = user.get('username', 'Unknown')
            Hierarchy = 'visitor_cards'
            print(
                f"✅ [visitor_cards] พบผู้ใช้: {username} (ID: {Hierarchy}) | UID: {scanned_uid}")
            return True, Hierarchy, username
        else:
            print(f"❌ [AUTH DENIED] ไม่พบ UID: {scanned_uid} ในระบบ")
            return False, None, None

    except mysql.connector.Error as err:
        print(f"⚠️ เกิดข้อผิดพลาด DB: {err}")
        return False, None, None

    finally:
        if db and db.is_connected():
            cursor.close()
            db.close()


# ==================== 4. เริ่มต้นเชื่อมต่อ Serial ====================
try:
    ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
    ser.reset_input_buffer()
    print(f"✅ เชื่อมต่อ ESP32 พอร์ต {SERIAL_PORT} สำเร็จ!")
except Exception as e:
    print(f"❌ ไม่สามารถเปิดพอร์ต {SERIAL_PORT} ได้: {e}")
    exit()

print("\n" + "="*60)
print("🔘 ระบบพร้อมทำงาน! สแกนบัตร RFID")
print("="*60)

# ==================== MAIN LOOP (ทำงานวนรอบรอคำสั่ง) ====================
while True:
    if ser.in_waiting > 0:
        line = ser.readline().decode('utf-8', errors='ignore').strip()
        
        if line:
            print(f"📩 [ESP32 Log]: {line}")

        # ------------------- กรณีที่ 1: เช็กบัตร RFID -------------------
        if line.startswith("RFID_SCAN|"):
            scanned_uid = line.split("|")[1]
            print(f"\n🎴 [RFID SCAN] รับค่า UID จาก ESP32: {scanned_uid}")

            is_valid, hierarchy, username = verify_rfid(scanned_uid)

            if is_valid:
                current_user["hierarchy"] = hierarchy
                current_user["username"] = username
                current_user["uid"] = scanned_uid
                # ตรวจสอบว่าถ้าเป็น Visitor ให้ส่งสัญญาณแยก
                if hierarchy == "visitor_cards":
                    ser.write(b"RFID_VISITOR\n")    # ตอบกลับ ESP32 ว่าเป็น Visitor
                    print(f"ตอบกลับ ESP32 ว่าเป็น RFID_VISITOR")
                else:
                    ser.write(b"RFID_OK\n")         # ตอบกลับ ESP32 ให้ผ่าน (ผู้ใช้งานทั่วไป)
                    print(f"ตอบกลับ ESP32 ว่าเป็นผู้ใช้งานทั่วไป")
            else:
                current_user = {"hierarchy": "None", "username": "Guest", "uid": None}
                ser.write(b"RFID_DENIED\n")   # ตอบกลับ ESP32 ว่าไม่ผ่าน
                print(f"ตอบกลับ ESP32 ว่าเป็น RFID_DENIED")

        # ------------------- กรณีที่ 2: START (เปิดกล้อง) -------------------
        elif line == "SYSTEM_START":
            print(
                f"\n🚀 [START] เริ่มต้นการประเมินของผู้ใช้: {current_user['username']}! กำลังเปิดกล้อง...")

            cap = cv2.VideoCapture(0)
            cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
            cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

            samples = []
            is_verified = False
            face_detected_start = None
            last_sample_time = 0
            is_stopped_by_hw = False

            # ลูปเปิดกล้องสแกน AI
            while cap.isOpened():
                if ser.in_waiting > 0:
                    cmd = ser.readline().decode('utf-8', errors='ignore').strip()
                    if cmd == "SYSTEM_STOP":
                        print("🛑 [STOP] ได้รับสัญญาณสั่งหยุด! กำลังปิดกล้อง...")
                        is_stopped_by_hw = True
                        break

                success, frame = cap.read()
                if not success:
                    print("❌ ไม่สามารถดึงภาพจากกล้องได้")
                    break

                current_time = time.time()
                results = model(frame, conf=0.65)

                detected_class = None
                detected_conf = 0.0

                for result in results:
                    for box in result.boxes:
                        class_id = int(box.cls[0])
                        detected_class = model.names[class_id]
                        detected_conf = float(box.conf[0])
                        break
                    if detected_class:
                        break

                annotated_frame = results[0].plot()

                if detected_class:
                    # ⚠️ ถ้าเจอ out_of_range ให้ขึ้นเตือน และหยุดนับเวลา
                    if detected_class == "out_of_range":
                        status_text = "⚠️ Out of Range! Please adjust face position..."
                        color = (0, 0, 255)  # ตัวหนังสือสีแดง
                        face_detected_start = None  # รีเซ็ตเวล่ายืนยันหน้า
                    else:
                        # กรณีใบหน้าอยู่ในระยะปกติ
                        if face_detected_start is None:
                            face_detected_start = current_time

                        elapsed_face_time = current_time - face_detected_start

                        if not is_verified:
                            status_text = f"Verifying Face... ({elapsed_face_time:.1f}/{VERIFY_DURATION}s)"
                            color = (0, 255, 255)
                            if elapsed_face_time >= VERIFY_DURATION:
                                is_verified = True
                                last_sample_time = current_time - SAMPLE_INTERVAL
                        else:
                            time_since_last_sample = current_time - last_sample_time
                            countdown = max(
                                0.0, SAMPLE_INTERVAL - time_since_last_sample)
                            status_text = f"Verified! Sample in: {countdown:.1f}s | Progress: [{len(samples)}/{TARGET_SAMPLES}]"
                            color = (0, 0, 255) if countdown > 0 else (
                                0, 255, 0)

                            if time_since_last_sample >= SAMPLE_INTERVAL:
                                sample_data = {
                                    "no": len(samples) + 1,
                                    "state": detected_class,
                                    "conf_val": detected_conf * 100,
                                    "confidence": f"{detected_conf*100:.1f}%",
                                    "time": time.strftime("%H:%M:%S")
                                }
                                samples.append(sample_data)
                                last_sample_time = current_time
                                print(
                                    f"📌 [AI Sample {len(samples)}/{TARGET_SAMPLES}] สภาวะ: {detected_class} ({detected_conf*100:.1f}%)")
                else:
                    face_detected_start = None
                    if not is_verified:
                        status_text = "Searching for face..."
                        color = (0, 0, 255)
                    else:
                        time_since_last_sample = current_time - last_sample_time
                        countdown = max(0.0, SAMPLE_INTERVAL -
                                        time_since_last_sample)
                        status_text = f"Face Lost! Waiting... | Progress: [{len(samples)}/{TARGET_SAMPLES}]"
                        color = (0, 0, 255)
                # ==================================================================

                cv2.putText(annotated_frame, status_text, (30, 50),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.8, color, 2)
                cv2.imshow("Burnout AI State Detection", annotated_frame)

                # เก็บครบ 5 ค่าแล้ว ปิดกล้อง
                if len(samples) >= TARGET_SAMPLES:
                    print("\n✅ สแกนใบหน้าครบ 5 ค่าเรียบร้อย! ปิดกล้อง...")
                    break

                if cv2.waitKey(1) & 0xFF == ord('q'):
                    print("\n⚠️ ปิดโปรแกรมโดยผู้ใช้")
                    cap.release()
                    cv2.destroyAllWindows()
                    ser.write(b"END_CAMERA\n")   # ตอบกลับ ESP32 END_CAMERA
                    ser.close()
                    exit()

            cap.release()
            cv2.destroyAllWindows()
            ser.write(b"END_CAMERA\n")   # ตอบกลับ ESP32 END_CAMERA

            if is_stopped_by_hw:
                print("\n--- รีเซ็ตระบบ กรุณาแตะบัตรใหม่ ---")
                continue

            # คำนวณสรุปผล AI
            all_states = [s['state'] for s in samples]
            state_counts = Counter(all_states)
            most_common_state, count = state_counts.most_common(1)[0]
            avg_confidence = sum(s['conf_val'] for s in samples) / len(samples)

            # ------------------- รอรับข้อมูล HW_RESULT จาก ESP32 -------------------
            print("\n⏳ กำลังรอรับข้อมูลแบบประเมิน (3 ข้อ) และชีพจรจาก ESP32...")
            hw_data = None

            while True:
                if ser.in_waiting > 0:
                    line_hw = ser.readline().decode('utf-8', errors='ignore').strip()

                    if line_hw:
                                print(f"📩 [ESP32 Log]: {line_hw}")

                    if line_hw == "SYSTEM_STOP":
                        print("\n" + "="*60)
                        print("🛑 [STOP] ถูกสั่งหยุดระบบระหว่างทำแบบประเมิน!")
                        print("]\nกรุณาแตะบัตรใหม่! สแกนบัตร RFID ที่ ESP32...")
                        print("="*60)
                        break

                    if line_hw.startswith("HW_RESULT|"):
                        parts = line_hw.split('|')
                        if len(parts) >= 6:
                            hw_data = {
                                "avg_bpm": float(parts[1]),
                                "rmssd": float(parts[2]),
                                "hrv_score": int(parts[3]),
                                "q_score": float(parts[4]),
                                "answers": parts[5].split(',')
                            }
                            break
                time.sleep(0.1)

            # ------------------- แสดงรายงานสรุปผลลัพธ์ -------------------
            if hw_data:
                print("\n" + "="*60)
                print("         📊 รายงานสรุปผลประเมินภาวะ BURNOUT AI (FINAL REPORT)")
                print("="*60)
                print(
                    f"👤 [ข้อมูลผู้ใช้งาน] : {current_user['username']} [ตำแหน่ง]: {current_user['hierarchy'] or 'N/A'})")
                print("------------------------------------------------------------")
                print("🤖 [1. การวิเคราะห์อารมณ์จากใบหน้า - AI (สแกน 5 ค่า)]")
                print(
                    f"  • สภาวะหลักที่พบ : {most_common_state} ({count}/{TARGET_SAMPLES} ครั้ง - {count/TARGET_SAMPLES*100:.0f}%)")
                print(f"  • ความมั่นใจเฉลี่ย : {avg_confidence:.2f}%")

                print("\n🫀 [2. การวิเคราะห์ค่าทางกายภาพ - Pulse & HRV]")
                print(
                    f"  • อัตราการเต้นหัวใจเฉลี่ย : {hw_data['avg_bpm']:.1f} BPM")
                print(
                    f"  • ค่าความแปรผันหัวใจ (RMSSD) : {hw_data['rmssd']:.1f} ms")
                print(f"  • คะแนนความเครียด HRV : {hw_data['hrv_score']}/100")

                print("\n📝 [3. ผลการตอบแบบประเมิน (สุ่ม 3 ข้อ)]")
                print(f"  • คะแนนเฉลี่ยแบบสอบถาม : {hw_data['q_score']:.2f}%")
                print("  • คำตอบ 3 ข้อที่สุ่มได้ : " +
                      " | ".join([f"Q{i+1}:{ans}" for i, ans in enumerate(hw_data['answers'])]))
                print("="*60)

                # รีเซ็ต User หลังจบการประเมิน
                current_user = {"hierarchy": "None", "username": "Guest", "uid": None}
                print("\n--- จบการประเมิน กรุณาแตะบัตรใหม่ ---")

    time.sleep(0.05)
