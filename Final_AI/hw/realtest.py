import cv2
import time
import serial
import requests
from pathlib import Path
from collections import Counter
from ultralytics import YOLO

# ==================== 1. ตั้งค่า Serial Port & URL ของ API บน Cloud ====================
SERIAL_PORT = 'COM6'   
BAUD_RATE = 115200
API_URL = "http://st67010999.consolutechcloud.com/db/api.php"

# ==================== 2. โหลดโมเดล YOLOv8 ====================
print("⏳ กำลังโหลดโมเดล AI (YOLOv8)... โปรดรอสักครู่")
BASE_DIR = Path(__file__).resolve().parent
MODEL_PATH = BASE_DIR / "best108.pt"
model = YOLO(str(MODEL_PATH))
print("✅ โหลดโมเดล AI สำเร็จ!")

VERIFY_DURATION = 1.0 
SAMPLE_INTERVAL = 1.0   
TARGET_SAMPLES = 5      

current_user = {"hierarchy": "None", "username": "Guest", "uid": None}

def reset_user():
    global current_user
    current_user = {"hierarchy": "None", "username": "Guest", "uid": None}

# ==================== 3. ฟังก์ชันคุยกับ Database ผ่าน API ====================
def verify_rfid(scanned_uid):
    try:
        print(f"🔄 [API] กำลังส่ง UID: {scanned_uid} ไปเช็คที่ฐานข้อมูลบน Cloud...")
        payload = {"action": "verify_rfid", "rfid_uid": scanned_uid}
        response = requests.post(API_URL, data=payload, timeout=5)
        data = response.json()
        
        if data.get("is_valid"):
            username = data.get("username")
            hierarchy = data.get("hierarchy")
            print(f"✅ [API] พบผู้ใช้: {username} (สิทธิ์: {hierarchy})")
            return True, hierarchy, username
        else:
            print(f"❌ [API] ไม่พบข้อมูลของ UID: {scanned_uid} ในระบบ")
            return False, None, None
    except Exception as e:
        print(f"⚠️ [API ERROR] ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้: {e}")
        return False, None, None

def save_user_log(rfid_uid, camera_state, hrv, survey_answer):
    try:
        print("🔄 [API] กำลังนำผลประเมินบันทึกขึ้น Cloud Database...")
        payload = {
            "action": "save_log",
            "rfid_uid": rfid_uid,
            "camera_state": camera_state,
            "hrv": hrv,
            "survey_answer": survey_answer
        }
        response = requests.post(API_URL, data=payload, timeout=5)
        data = response.json()
        
        if data.get("status") == "success":
            print(f"💾 [API SUCCESS] บันทึกข้อมูลสำเร็จ! ตรวจสอบหน้า Dashboard ได้เลย")
            return True
        else:
            print(f"❌ [API ERROR] เซิร์ฟเวอร์ตอบกลับผิดพลาด: {data.get('message')}")
            return False
    except Exception as e:
        print(f"⚠️ [API ERROR] เชื่อมต่อเพื่อบันทึกข้อมูลล้มเหลว: {e}")
        return False

# ==================== 4. เริ่มทำงาน (Main Loop) ====================
print("⏳ กำลังเชื่อมต่อบอร์ด ESP32...")
try:
    ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
    ser.reset_input_buffer()
    print(f"✅ เชื่อมต่อ ESP32 พอร์ต {SERIAL_PORT} สำเร็จ!")
except Exception as e:
    print(f"❌ ไม่สามารถเปิดพอร์ต {SERIAL_PORT} ได้: {e}")
    exit()

print("\n" + "="*60)
print("🔘 ระบบพร้อมทำงาน (API Mode)! นำบัตรไปสแกนที่บอร์ดได้เลย...")
print("="*60)

while True:
    if ser.in_waiting > 0:
        line = ser.readline().decode('utf-8', errors='ignore').strip()

        if line.startswith("RFID_SCAN|"):
            parts = line.split("|")
            if len(parts) > 1:
                scanned_uid = parts[1]
                print(f"\n" + "-"*40)
                print(f"▶️ [STEP 1] ได้รับข้อมูลบัตร RFID จากบอร์ด: {scanned_uid}")

                is_valid, hierarchy, username = verify_rfid(scanned_uid)

                if is_valid:
                    current_user["hierarchy"] = hierarchy
                    current_user["username"] = username
                    current_user["uid"] = scanned_uid

                    print("✅ กำลังส่งสัญญาณอนุญาตกลับไปให้ ESP32...")
                    if hierarchy == "visitor_cards":
                        ser.write(b"RFID_VISITOR\n")
                    else:
                        ser.write(b"RFID_OK\n")
                else:
                    reset_user()
                    print("❌ สั่งปฏิเสธบัตร ส่งสัญญาณกลับไปให้ ESP32...")
                    ser.write(b"RFID_DENIED\n")

        elif line == "SYSTEM_START":
            print(f"\n▶️ [STEP 2] กำลังเปิดกล้องเพื่อวิเคราะห์ใบหน้าของคุณ {current_user['username']}...")

            cap = cv2.VideoCapture(0)
            cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
            cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

            if not cap.isOpened():
                print("❌ ไม่สามารถเปิดกล้องได้!")
                ser.write(b"END_CAMERA\n")
                reset_user()
                continue

            samples = []
            is_verified = False
            face_detected_start = None
            last_sample_time = 0
            is_stopped_by_hw = False

            print("📷 เปิดกล้องสำเร็จ! กรุณามองที่กล้อง...")
            while cap.isOpened():
                if ser.in_waiting > 0:
                    cmd = ser.readline().decode('utf-8', errors='ignore').strip()
                    if cmd == "SYSTEM_STOP":
                        print("🛑 ได้รับคำสั่งยกเลิกจากบอร์ด! กำลังปิดกล้อง...")
                        is_stopped_by_hw = True
                        break

                success, frame = cap.read()
                if not success: break

                frame = cv2.flip(frame, 1)

                current_time = time.time()
                results = model(frame, conf=0.25)
                detected_class = None
                detected_conf = 0.0

                for result in results:
                    for box in result.boxes:
                        class_id = int(box.cls[0])
                        detected_class = model.names[class_id]
                        detected_conf = float(box.conf[0])
                        break
                    if detected_class: break

                annotated_frame = results[0].plot()

                if detected_class:
                    if detected_class == "out_of_range":
                        status_text = "⚠️ Out of Range! ขยับหน้าให้ตรง"
                        color = (0, 0, 255)
                        face_detected_start = None
                    else:
                        if face_detected_start is None: face_detected_start = current_time
                        elapsed = current_time - face_detected_start

                        if not is_verified:
                            status_text = f"Verifying... ({elapsed:.1f}/{VERIFY_DURATION}s)"
                            color = (0, 255, 255)
                            if elapsed >= VERIFY_DURATION:
                                is_verified = True
                                last_sample_time = current_time - SAMPLE_INTERVAL
                        else:
                            since_last = current_time - last_sample_time
                            countdown = max(0.0, SAMPLE_INTERVAL - since_last)
                            status_text = f"Sample in: {countdown:.1f}s [{len(samples)}/{TARGET_SAMPLES}]"
                            color = (0, 0, 255) if countdown > 0 else (0, 255, 0)

                            if since_last >= SAMPLE_INTERVAL:
                                samples.append({"state": detected_class, "conf_val": detected_conf * 100})
                                last_sample_time = current_time
                                print(f"📌 [เก็บค่า AI แล้ว {len(samples)}/5] พบสถานะใบหน้า: {detected_class} ({detected_conf*100:.1f}%)")
                else:
                    face_detected_start = None
                    status_text = "Searching face..." if not is_verified else "Face Lost!"
                    color = (0, 0, 255)

                cv2.putText(annotated_frame, status_text, (30, 50), cv2.FONT_HERSHEY_SIMPLEX, 0.8, color, 2)
                cv2.imshow("Burnout AI", annotated_frame)

                if len(samples) >= TARGET_SAMPLES:
                    print("✅ เก็บข้อมูลใบหน้าครบ 5 ครั้งแล้ว! กำลังปิดกล้อง...")
                    break
                if cv2.waitKey(1) & 0xFF == ord('q'):
                    cap.release()
                    cv2.destroyAllWindows()
                    ser.write(b"END_CAMERA\n")
                    ser.close()
                    exit()

            cap.release()
            cv2.destroyAllWindows()
            ser.write(b"END_CAMERA\n")

            if is_stopped_by_hw or not samples:
                print("⚠️ ยกเลิกการบันทึกข้อมูลเนื่องจากได้ข้อมูลไม่ครบ หรือโดนสั่งหยุด")
                reset_user()
                continue

            # ================= คำนวณสรุปผล AI =================
            all_states = [s['state'] for s in samples]
            state_counts = Counter(all_states)
            most_common_state, count = state_counts.most_common(1)[0]
            avg_confidence = sum(s['conf_val'] for s in samples) / len(samples)
            camera_state_value = 1 if most_common_state.lower() == "burnout" else 0

            # ================= รอสแกนนิ้วและทำแบบสอบถาม =================
            hw_data = {"avg_bpm": 0, "rmssd": 0, "hrv_score": 0, "q_score": 0, "answers": []}
            is_pulse_done_ui = False
            data_received = False
            
            start_clock = time.strftime("%H:%M:%S")
            print(f"\n▶️ [STEP 3] ผลประเมิน AI: '{most_common_state}'")
            print(f"[{start_clock}] ⏳ ลำดับที่ 1: กรุณาวางนิ้วบนเซ็นเซอร์เพื่อวัดชีพจร (Pulse/BPM)...")
            
            wait_start_time = time.time()
            last_wait_msg_time = wait_start_time
            
            while (time.time() - wait_start_time) < 150:
                current_wait_time = time.time()
                
                if current_wait_time - last_wait_msg_time >= 15:
                    current_clock = time.strftime("%H:%M:%S")
                    if not is_pulse_done_ui:
                        print(f"[{current_clock}] ⏳ (กำลังอ่านค่าชีพจร... โปรดวางนิ้วค้างไว้)")
                    else:
                        print(f"[{current_clock}] ⏳ (รอการกดปุ่ม... กรุณาฟังคำถามให้จบแล้วกดปุ่มเลือกคำตอบ)")
                    last_wait_msg_time = current_wait_time

                if ser.in_waiting > 0:
                    line_hw = ser.readline().decode('utf-8', errors='ignore').strip()
                    
                    if line_hw == "SYSTEM_STOP":
                        print("🛑 ยกเลิกการประเมินกลางคัน")
                        break

                    if "วัดชีพจร สำเร็จเเล้ว!" in line_hw or "วัดชีพจร สำเร็จแล้ว!" in line_hw:
                        is_pulse_done_ui = True
                        current_clock = time.strftime("%H:%M:%S")
                        print(f"\n[{current_clock}] ✅ วัดชีพจรเสร็จแล้ว!")
                        print(f"[{current_clock}] ▶️ ลำดับที่ 2: เข้าสู่ช่วงตอบแบบสอบถาม (3 ข้อ)")
                        print(f"[{current_clock}] 🔊 ระบบกำลังเล่นไฟล์เสียงคำถาม... กรุณาฟังและกดปุ่มเพื่อตอบเมื่อเสียงจบ")
                        last_wait_msg_time = time.time() 
                        continue

                    if line_hw.startswith("HW_RESULT|"):
                        parts = line_hw.split('|')
                        if len(parts) >= 6:
                            try:
                                hw_data["avg_bpm"] = float(parts[1])
                                hw_data["rmssd"] = float(parts[2])
                                hw_data["hrv_score"] = int(parts[3])
                                hw_data["q_score"] = float(parts[4])
                                hw_data["answers"] = parts[5].split(',')
                                data_received = True
                                break
                            except ValueError as e:
                                print(f"⚠️ การแปลงข้อมูลล้มเหลว: {e}")

                    elif line_hw:
                        current_clock = time.strftime("%H:%M:%S")
                        print(f"[{current_clock}] 📡 {line_hw}")
                        last_wait_msg_time = time.time() 

            # ================= แสดงรายงานสรุปผลลัพธ์แบบเต็ม =================
            if data_received:
                end_clock = time.strftime("%H:%M:%S")
                print("\n" + "="*60)
                print(f"        📊 รายงานสรุปผลประเมินภาวะ BURNOUT AI (เวลา: {end_clock})")
                print("="*60)
                print(f"👤 [ข้อมูลผู้ใช้งาน] : {current_user['username']} [ตำแหน่ง]: {current_user['hierarchy'] or 'N/A'}")
                print("------------------------------------------------------------")
                print("🤖 [1. การวิเคราะห์อารมณ์จากใบหน้า - AI (สแกน 5 ค่า)]")
                print(f"  • สภาวะหลักที่พบ : {most_common_state} ({count}/{len(samples)} ครั้ง - {count/len(samples)*100:.0f}%)")
                print(f"  • ความมั่นใจเฉลี่ย : {avg_confidence:.2f}%")

                print("\n🫀 [2. การวิเคราะห์ค่าทางกายภาพ - Pulse & HRV]")
                print(f"  • อัตราการเต้นหัวใจเฉลี่ย : {hw_data['avg_bpm']:.1f} BPM")
                print(f"  • ค่าความแปรผันหัวใจ (RMSSD) : {hw_data['rmssd']:.1f} ms")
                print(f"  • คะแนนความเครียด HRV : {hw_data['hrv_score']}/100")

                print("\n📝 [3. ผลการตอบแบบประเมิน (คำถาม 3 ข้อ)]")
                print(f"  • คะแนนเฉลี่ยแบบสอบถาม : {hw_data['q_score']:.2f}%")
                print("  • คำตอบที่เลือก : " +
                      " | ".join([f"ข้อ{i+1}:{ans}" for i, ans in enumerate(hw_data['answers'])]))
                print("="*60)

                print(f"\n▶️ [STEP 4] เข้าสู่ขั้นตอนสุดท้าย... เริ่มบันทึกข้อมูลขึ้นระบบ")
                save_user_log(
                    rfid_uid=current_user["uid"],
                    camera_state=camera_state_value, 
                    hrv=hw_data["rmssd"],
                    survey_answer=hw_data["q_score"]
                )
            else:
                print("⚠️ [หมดเวลา] ไม่ได้รับข้อมูลจากบอร์ดครบถ้วนภายในเวลาที่กำหนด (150 วินาที)")

            reset_user()
            print("\n" + "="*60)
            print("🏁 กระบวนการทำงานเสร็จสิ้น! ระบบพร้อมสำหรับรับบัตรใบต่อไป...")
            print("="*60)
    time.sleep(0.05)