import mysql.connector

try:
    # 1. เชื่อมต่อไปยังฐานข้อมูลเครื่องเพื่อน
    db = mysql.connector.connect(
        host="10.26.1.95",
        user="admin_user",
        password="123456",
        database="my_database",
        port=3306,
    )

    cursor = db.cursor()

    # 2. กำหนดข้อมูลที่ต้องการเพิ่ม (ปรับเปลี่ยนค่าได้ตามต้องการ)
    new_rfid = "9A461B4A"
    new_username = "hopโอปอ"

    # 3. เขียนคำสั่ง SQL สำหรับ INSERT (ไม่ต้องใส่ id เพราะปกติระบบจะ Auto Increment ให้เอง)
    sql_query = (
        "INSERT INTO registered_users (rfid_uid, username) VALUES (%s, %s)"
    )
    val = (new_rfid, new_username)

    # 4. ส่งคำสั่งไปยังฐานข้อมูล
    cursor.execute(sql_query, val)

    # 5. ยืนยันการบันทึกข้อมูลลงฐานข้อมูล (สำคัญมาก! ถ้าไม่มีบรรทัดนี้ ข้อมูลจะไม่ถูกเซฟ)
    db.commit()

    print(
        f"เพิ่มข้อมูลสำเร็จ! (ไอดีล่าสุดที่สร้างคือ ID: {cursor.lastrowid})"
    )

except mysql.connector.Error as err:
    print(f"เกิดข้อผิดพลาด: {err}")

finally:
    # ปิดการเชื่อมต่อเสมอ
    if "db" in locals() and db.is_connected():
        cursor.close()
        db.close()