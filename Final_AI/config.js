/* ==========================================================
   Burnout Pulse AI | Admin  —  config.js
   ไฟล์ตั้งค่า: แก้ที่นี่ที่เดียว ไม่ต้องแตะ script.js
   (ต้องโหลดก่อน script.js ใน index.html)
   ========================================================== */

const CONFIG = {
  // false = ใช้ข้อมูลจำลอง + ล็อกอินทดสอบ | true = เรียก PHP API จริง
  USE_API: true,

  // เล็งเป้าหมายชี้ไปที่ตาราง user_logs ของฐานข้อมูล my_database โดยตรง
  PHPMYADMIN_URL: 'https://mail.consolutechcloud.com/phpMyAdmin/index.php?route=/database/structure&db=st67010999_burnout',

  // TODO: path / URL ของไฟล์ PHP (รูปแบบ JSON ที่ต้องการดูคอมเมนต์ใน script.js ส่วน DATA LAYER)
  API: {
    cards:   'api/cards.php',    // บัตรพนักงาน/ผู้มาเยือน
    records: 'api/records.php',  // ข้อมูลหน้า "ฐานข้อมูล"
    auth:    'api/auth.php',     // ล็อกอิน / ตรวจ session / ออกจากระบบ
  },

  // ถ้า API อยู่คนละโดเมนกับเว็บ ให้เปลี่ยนเป็น 'include' (และฝั่ง PHP ต้องตั้ง CORS แบบมี credentials)
  API_CREDENTIALS: 'same-origin',

  // หน้าฐานข้อมูลแสดงกี่แถวล่าสุด (ที่เหลือให้ไปดูใน phpMyAdmin)
  RECORDS_PREVIEW_LIMIT: 10,

  // รีเฟรชข้อมูลอัตโนมัติทุกกี่วินาที (ใช้เฉพาะ USE_API = true) | 0 = ปิด
  REFRESH_SECONDS: 5,

  // ล็อกอินทดสอบ ใช้เฉพาะตอน USE_API = false (ไม่ใช่ระบบความปลอดภัยจริง)
  MOCK_LOGIN: { username: 'admin', password: 'admin1234' },

  // รูปแบบ RFID TAG ID — ปรับให้ตรงกับเครื่องอ่านบัตรจริง
  RFID: {
    placeholder: '8E:89:AB:12:45:67',
    hint: 'เลขฐานสิบหก คั่นด้วย : หรือไม่คั่นก็ได้',
    error: 'รูปแบบ RFID ไม่ถูกต้อง เช่น 8E:89:AB:12:45:67',

    // รับค่าที่ผู้ใช้กรอก/แสกน คืนค่าที่จะบันทึก หรือ null ถ้ารูปแบบไม่ถูกต้อง
    // ค่าเริ่มต้น: เลขฐานสิบหก 4-10 ไบต์ จะคั่นด้วย : - หรือเว้นวรรค หรือไม่คั่นก็ได้ แล้วแปลงเป็น 8E:89:AB:...
    normalize(raw) {
      const hex = raw.replace(/[\s:\-]/g, '').toUpperCase();
      if (!/^[0-9A-F]{8,20}$/.test(hex) || hex.length % 2) return null;
      return hex.match(/../g).join(':');
    },

    // ตัวอย่าง: ถ้าเครื่องอ่านส่งเป็นเลขฐานสิบ 8-10 หลัก ให้ใช้แบบนี้แทน (และแก้ placeholder / hint / error ด้วย)
    // normalize(raw) { const v = raw.trim(); return /^\d{8,10}$/.test(v) ? v : null; },
  },
};