import cv2
import time
from pathlib import Path
from collections import Counter
from ultralytics import YOLO

# 1. ดึงตำแหน่งโฟลเดอร์ที่ไฟล์ Burnout_Pulse_AI.py วางอยู่โดยอัตโนมัติ
BASE_DIR = Path(__file__).resolve().parent
MODEL_PATH = BASE_DIR / "best.pt"

# โหลดโมเดล YOLOv8 ผ่าน Path ที่ถูกต้อง
model = YOLO(str(MODEL_PATH))

# 2. ตั้งค่าเงื่อนไขเวลาและจำนวนตัวอย่าง
VERIFY_DURATION = 3.0   # ต้องเห็นหน้าต่อเนื่องครบ 3 วินาทีเพื่อยืนยันตัวตน
SAMPLE_INTERVAL = 1.0   # เว้นระยะห่างการเก็บข้อมูลทุกๆ 1 วินาที
TARGET_SAMPLES = 100     # จำนวนข้อมูลที่ต้องการเก็บทั้งหมด (100 ค่า)

# ตัวแปรสำหรับควบคุมสถานะ
samples = []
is_verified = False
face_detected_start = None
last_sample_time = 0

# 3. เปิดกล้อง Webcam
cap = cv2.VideoCapture(0)
cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

print("📷 กำลังเปิดกล้อง... กรุณามองมาที่กล้อง")

while cap.isOpened():
    success, frame = cap.read()
    if not success:
        print("❌ ไม่สามารถเปิดกล้องได้")
        break

    current_time = time.time()
    results = model(frame, conf=0.65)

    detected_class = None
    detected_conf = 0.0

    # ดึงค่าคลาสที่ตรวจพบได้แรกสุดในเฟรม
    for result in results:
        for box in result.boxes:
            class_id = int(box.cls[0])
            detected_class = model.names[class_id]
            detected_conf = float(box.conf[0])
            break
        if detected_class:
            break

    annotated_frame = results[0].plot()

    # ==================== ลอจิกการทำงานตามเงื่อนไขเวลา ====================
    if detected_class:
        if face_detected_start is None:
            face_detected_start = current_time

        elapsed_face_time = current_time - face_detected_start

        # สเต็ปที่ 1: ตรวจเช็กว่าเห็นหน้าติดต่อกันครบ 3 วินาทีหรือไม่
        if not is_verified:
            status_text = f"Verifying Face... ({elapsed_face_time:.1f}/{VERIFY_DURATION}s)"
            color = (0, 255, 255) # สีเหลือง
            
            if elapsed_face_time >= VERIFY_DURATION:
                is_verified = True
                last_sample_time = current_time - SAMPLE_INTERVAL # บังคับให้เริ่มเก็บค่าแรกทันที
        
        # สเต็ปที่ 2: เมื่อยืนยันตัวตนแล้ว ทำการบันทึกค่าทุกๆ 5 วินาที
        else:
            time_since_last_sample = current_time - last_sample_time
            countdown = max(0.0, SAMPLE_INTERVAL - time_since_last_sample)
            
            status_text = f"Verified! Sample in: {countdown:.1f}s | Progress: [{len(samples)}/{TARGET_SAMPLES}]"
            color = (0, 255, 0) # สีเขียว

            if time_since_last_sample >= SAMPLE_INTERVAL:
                # 🛠️ [จุดที่ 1] บันทึกข้อมูล (เก็บทั้ง raw float สำหรับคำนวณ และ string สำหรับโชว์)
                sample_data = {
                    "no": len(samples) + 1,
                    "state": detected_class,
                    "conf_val": detected_conf * 100, # ค่าตัวเลข % สำหรับนำไปหาค่าเฉลี่ย
                    "confidence": f"{detected_conf*100:.1f}%",
                    "time": time.strftime("%H:%M:%S")
                }
                samples.append(sample_data)
                last_sample_time = current_time
                
                print(f"📌 [บันทึกค่าที่ {len(samples)}/{TARGET_SAMPLES}] สภาวะ: {detected_class} ({detected_conf*100:.1f}%) เวลา {sample_data['time']}")

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

    # วาดข้อความแจ้งสถานะลงบนภาพวิดีโอสด
    cv2.putText(annotated_frame, status_text, (30, 50), cv2.FONT_HERSHEY_SIMPLEX, 0.8, color, 2)
    cv2.imshow("Burnout AI State Detection", annotated_frame)

    # 🛠️ [จุดที่ 2] สเต็ปที่ 3: เมื่อได้ข้อมูลครบ 10 ค่า ให้คำนวณเฉลี่ยสรุปผลแล้วปิดการทำงาน
    if len(samples) >= TARGET_SAMPLES:
        print("\n==========================================")
        print("✅ เก็บข้อมูลครบ 10 ค่าเรียบร้อยแล้ว!")
        print("==========================================")
        print("รายการสภาวะใบหน้าที่ตรวจพบทั้งหมด:")
        for s in samples:
            print(f" ค่าที่ {s['no']}: Status = {s['state']} | Confidence = {s['confidence']} | Time = {s['time']}")

        # --- ส่วนคำนวณสรุปผลรวม (Summary & Average) ---
        all_states = [s['state'] for s in samples]
        state_counts = Counter(all_states)
        most_common_state, count = state_counts.most_common(1)[0]
        
        # คำนวณค่าเฉลี่ยความมั่นใจรวม
        avg_confidence = sum(s['conf_val'] for s in samples) / len(samples)

        print("\n------------------------------------------")
        print("📊 สรุปผลการวิเคราะห์ภาพรวม (FINAL SUMMARY)")
        print("------------------------------------------")
        print(f"🏆 สภาวะหลักที่พบมากที่สุด (Dominant State) : {most_common_state}")
        print(f"📈 ความถี่สภาวะหลัก                     : {count}/{TARGET_SAMPLES} ครั้ง ({count/TARGET_SAMPLES*100:.0f}%)")
        print(f"🎯 ค่าความมั่นใจเฉลี่ยรวม (Avg Confidence)  : {avg_confidence:.2f}%")
        
        print("\nสัดส่วนสภาวะทั้งหมดที่ตรวจพบ:")
        for state, st_count in state_counts.items():
            print(f"  - {state}: {st_count} ครั้ง ({st_count/TARGET_SAMPLES*100:.0f}%)")

        print("==========================================")
        print("🔒 ปิดกล้องและยุติการทำงาน...")
        break

    # กด 'q' หากต้องการยกเลิกกลางคัน
    if cv2.waitKey(1) & 0xFF == ord('q'):
        print("\n⚠️ ปิดโปรแกรมโดยผู้ใช้")
        break

# คืนค่าทรัพยากรกล้องและหน้าต่างวิดีโอ
cap.release()
cv2.destroyAllWindows()