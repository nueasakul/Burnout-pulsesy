import mysql.connector
import serial
import time

# ==================== DB Configuration ====================
DB_CONFIG = {
    "host": "10.26.1.95",       # IP เครื่องเพื่อน/เซิร์ฟเวอร์
    "user": "admin_user",
    "password": "123456",
    "database": "my_database",
    "port": 3306
}

# ==================== Function เช็กบัตร RFID ====================
def verify_rfid(scanned_uid):
    db = None
    try:
        # 1. เชื่อมต่อไปยังฐานข้อมูล
        db = mysql.connector.connect(**DB_CONFIG)
        cursor = db.cursor(dictionary=True)

        # 2. เขียนคำสั่ง SQL ค้นหา UID ที่ตรงกับที่สแกนเข้ามา (%s ช่วยป้องกัน SQL Injection)
        sql_query = "SELECT * FROM registered_users WHERE rfid_uid = %s"
        cursor.execute(sql_query, (scanned_uid,))

        # 3. ดึงข้อมูลรายการแรกที่เจอ
        user = cursor.fetchone()

        # 4. ตรวจสอบผลลัพธ์
        if user:
            username = user.get('username', 'Unknown')
            user_id = user.get('id')
            print(f"✅ [AUTH PASS] พบผู้ใช้: {username} (ID: {user_id}) | UID: {scanned_uid}")
            return True, username
        else:
            print(f"❌ [AUTH DENIED] ไม่พบ UID: {scanned_uid} ในระบบ")
            return False, None

    except mysql.connector.Error as err:
        print(f"⚠️ เกิดข้อผิดพลาด DB: {err}")
        return False, None

    finally:
        # ปิดการเชื่อมต่อเสมอ
        if db and db.is_connected():
            cursor.close()
            db.close()

# ==================== Main Serial Loop ====================
if __name__ == "__main__":
    # ตั้งค่า Serial Port ตามที่เชื่อมต่อกับ ESP32-C3
    try:
        ser = serial.Serial('COM5', 115200, timeout=1) # เปลี่ยน COM5 ให้ตรงกับเครื่องคุณ
        print("🚀 เชื่อมต่อ Serial กับ ESP32 เรียบร้อยแล้ว...")
    except Exception as e:
        print(f"❌ ไม่สามารถเปิด Serial Port: {e}")
        exit()

    while True:
        if ser.in_waiting > 0:
            # อ่านข้อความจาก ESP32
            line = ser.readline().decode('utf-8', errors='ignore').strip()
            
            # เมื่อ ESP32 ส่งรูปแบบ: RFID_SCAN|3AB9224A
            if line.startswith("RFID_SCAN|"):
                scanned_uid = line.split("|")[1]
                print(f"\n🎴 รับค่าจาก ESP32 -> UID: {scanned_uid}")
                
                # เช็กบัตรกับฐานข้อมูล
                is_valid, username = verify_rfid(scanned_uid)
                
                # ตอบกลับ ESP32
                if is_valid:
                    ser.write(b"RFID_OK\n")      # ส่งสัญญาณบอก ESP32 ว่าผ่าน
                else:
                    ser.write(b"RFID_DENIED\n")  # ส่งสัญญาณบอก ESP32 ว่าไม่ผ่าน
            
            # รับข้อมูลสรุปผลคะแนนแบบประเมินเมื่อทำเสร็จ
            elif line.startswith("HW_RESULT|"):
                print(f"📊 ได้รับผลการประเมินจาก ESP32: {line}")
                # TODO: เพิ่มโค้ดบันทึกคะแนนลง DB ในอนาคตได้ตรงนี้

        time.sleep(0.05)