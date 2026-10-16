import mysql.connector

try:
    # 1. เชื่อมต่อไปยังฐานข้อมูลเครื่องเพื่อน
    db = mysql.connector.connect(
        host="10.26.1.95",        # เช่น "192.168.1.100" 
        user="admin_user",
        password="123456",
        database="my_database",
        port=3306
    )

    # ใช้ dictionary=True เพื่อให้ผลลัพธ์ออกมาเป็น Dict คล้าย JSON อ่านง่ายตามชื่อคอลัมน์
    cursor = db.cursor(dictionary=True)

    # 2. เขียนคำสั่ง SQL เพื่อขอ/ถามข้อมูล (เปลี่ยนชื่อตารางตามของเพื่อน)
    sql_query = "SELECT * FROM registered_users"
    
    cursor.execute(sql_query)

    # 3. ดึงข้อมูลทั้งหมดออกมา
    results = cursor.fetchall()

    # 4. แสดงผลลัพธ์
    print(f"ค้นพบข้อมูลทั้งหมด {len(results)} รายการ:")
    for row in results:
        # สมมติว่ามีคอลัมน์ id, rfid_uid, username
        print(f"ID: {row.get('id')} | RFID UID: {row.get('rfid_uid')} | Username: {row.get('username')}")

except mysql.connector.Error as err:
    print(f"เกิดข้อผิดพลาด: {err}")

finally:
    # ปิดการเชื่อมต่อเสมอ
    if 'db' in locals() and db.is_connected():
        cursor.close()
        db.close()