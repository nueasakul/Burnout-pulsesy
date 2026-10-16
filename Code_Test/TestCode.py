import cv2
import time
import serial
from pathlib import Path
from collections import Counter
from ultralytics import YOLO

# ==================== ตั้งค่า Serial Port ====================
SERIAL_PORT = 'COM5'   # ⚠️ เปลี่ยนพอร์ต COM ให้ตรงกับ ESP32
BAUD_RATE = 115200

# ==================== 1. โหลดโมเดล YOLOv8 ====================
BASE_DIR = Path(__file__).resolve().parent
MODEL_PATH = BASE_DIR / "best.pt"
model = YOLO(str(MODEL_PATH))

# 🛠️ ปรับแก้เงื่อนไขเวลาและจำนวนตัวอย่างตามที่ต้องการ
VERIFY_DURATION = 3.0   # ยืนยันตัวตน 3 วินาที
SAMPLE_INTERVAL = 3.0   # 🛠️ เปลี่ยนระยะห่างเก็บข้อมูลจาก 5s เหลือ 3s
TARGET_SAMPLES = 5      # 🛠️ เปลี่ยนจำนวนตัวอย่างสแกนหน้าจาก 10 ค่า เหลือ 5 ค่า

# เชื่อมต่อ Serial
try:
    ser = serial.Serial(SERIAL_PORT, BAUD_RATE, timeout=1)
    ser.reset_input_buffer()
    print(f"✅ เชื่อมต่อ ESP32 พอร์ต {SERIAL_PORT} สำเร็จ!")
except Exception as e:
    print(f"❌ ไม่สามารถเปิดพอร์ต {SERIAL_PORT} ได้: {e}")
    exit()

print("\n" + "="*60)
print("🔘 ระบบพร้อมทำงาน! รอกดปุ่ม START ที่บอร์ด ESP32...")
print("="*60)

# ==================== MAIN LOOP (ทำงานวนรอบรอคำสั่ง) ====================
while True:
    # ------------------- สเต็ปที่ 1: รอกดปุ่มเปิดกล้อง -------------------
    if ser.in_waiting > 0:
        line = ser.readline().decode('utf-8', errors='ignore').strip()
        
        if line == "SYSTEM_START":
            print("\n🚀 [START] ได้รับสัญญาณกดปุ่มเริ่ม! กำลังเปิดกล้อง...")
            
            cap = cv2.VideoCapture(0)
            cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
            cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

            samples = []
            is_verified = False
            face_detected_start = None
            last_sample_time = 0
            is_stopped_by_hw = False

            # ------------------- สเต็ปที่ 2: ลูปเปิดกล้องสแกน AI -------------------
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
                        countdown = max(0.0, SAMPLE_INTERVAL - time_since_last_sample)
                        status_text = f"Verified! Sample in: {countdown:.1f}s | Progress: [{len(samples)}/{TARGET_SAMPLES}]"
                        color = (0, 0, 255) if countdown > 0 else (0, 255, 0)

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
                            print(f"📌 [AI Sample {len(samples)}/{TARGET_SAMPLES}] สภาวะ: {detected_class} ({detected_conf*100:.1f}%)")
                else:
                    face_detected_start = None
                    if not is_verified:
                        status_text = "Searching for face..."
                        color = (0, 0, 255)
                    else:
                        time_since_last_sample = current_time - last_sample_time
                        countdown = max(0.0, SAMPLE_INTERVAL - time_since_last_sample)
                        status_text = f"Face Lost! Waiting... | Progress: [{len(samples)}/{TARGET_SAMPLES}]"
                        color = (0, 0, 255)

                cv2.putText(annotated_frame, status_text, (30, 50), cv2.FONT_HERSHEY_SIMPLEX, 0.8, color, 2)
                cv2.imshow("Burnout AI State Detection", annotated_frame)

                # เก็บครบ 5 ค่าแล้ว ปิดกล้องเพื่อเตรียมสรุปผล
                if len(samples) >= TARGET_SAMPLES:
                    print("\n✅ สแกนใบหน้าครบ 5 ค่าเรียบร้อย! ปิดกล้อง...")
                    break

                if cv2.waitKey(1) & 0xFF == ord('q'):
                    print("\n⚠️ ปิดโปรแกรมโดยผู้ใช้")
                    cap.release()
                    cv2.destroyAllWindows()
                    ser.close()
                    exit()

            cap.release()
            cv2.destroyAllWindows()

            if is_stopped_by_hw:
                print("\n--- รีเซ็ตระบบ รอกดปุ่ม START ใหม่ ---")
                continue

            # คำนวณสรุปผลฝั่ง AI (เฉลี่ยจาก 5 ค่า)
            all_states = [s['state'] for s in samples]
            state_counts = Counter(all_states)
            most_common_state, count = state_counts.most_common(1)[0]
            avg_confidence = sum(s['conf_val'] for s in samples) / len(samples)

            # ------------------- สเต็ปที่ 3: รอนำเข้า HW_RESULT -------------------
            print("\n⏳ กำลังรอรับข้อมูลแบบประเมิน (3 ข้อ) และชีพจรจาก ESP32...")
            hw_data = None

            while True:
                if ser.in_waiting > 0:
                    line_hw = ser.readline().decode('utf-8', errors='ignore').strip()
                    
                    if line_hw == "SYSTEM_STOP":
                        print("🛑 [STOP] ถูกสั่งหยุดระบบระหว่างทำแบบประเมิน!")
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

            # ------------------- สเต็ปที่ 4: แสดงรายงานสรุป -------------------
            if hw_data:
                print("\n" + "="*60)
                print("           📊 รายงานสรุปผลประเมินภาวะ BURNOUT AI (FINAL REPORT)")
                print("="*60)
                print("\n🤖 [1. การวิเคราะห์อารมณ์จากใบหน้า - AI (สแกน 5 ค่า)]")
                print(f"  • สภาวะหลักที่พบ : {most_common_state} ({count}/{TARGET_SAMPLES} ครั้ง - {count/TARGET_SAMPLES*100:.0f}%)")
                print(f"  • ความมั่นใจเฉลี่ย : {avg_confidence:.2f}%")

                print("\n🫀 [2. การวิเคราะห์ค่าทางกายภาพ - Pulse & HRV]")
                print(f"  • อัตราการเต้นหัวใจเฉลี่ย : {hw_data['avg_bpm']:.1f} BPM")
                print(f"  • ค่าความแปรผันหัวใจ (RMSSD) : {hw_data['rmssd']:.1f} ms")
                print(f"  • คะแนนความเครียด HRV : {hw_data['hrv_score']}/100")

                print("\n📝 [3. ผลการตอบแบบประเมิน (สุ่ม 3 ข้อ)]")
                print(f"  • คะแนนเฉลี่ยแบบสอบถาม : {hw_data['q_score']:.2f}%")
                print("  • คำตอบ 3 ข้อที่สุ่มได้ : " + " | ".join([f"Q{i+1}:{ans}" for i, ans in enumerate(hw_data['answers'])]))
                print("="*60)

            print("\n--- จบการประเมิน รอกดปุ่ม START เพื่อเริ่มรอบใหม่ ---")

    time.sleep(0.05)