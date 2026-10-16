/* ==========================================================
   Burnout Pulse AI | Admin  —  script.js
   ----------------------------------------------------------
   ค่าตั้งค่า (URL, โหมด API, รูปแบบ RFID ฯลฯ) อยู่ใน config.js
   ไฟล์นี้แบ่งเป็น
   1) DATA LAYER  ชั้นดึง/บันทึกข้อมูล (mock <-> PHP API) + ล็อกอิน
   2) UI          เรนเดอร์หน้า, เรียงข้อมูล, modal, toast, export CSV
   ========================================================== */
 
/* ----------------------------------------------------------
   1) DATA LAYER
   ----------------------------------------------------------
   รูปแบบ JSON ที่ PHP ควรส่งกลับ
 
   GET  cards.php?type=employee   ->  [
     { "id": 1, "type": "employee", "name": "พนักงาน นามสกุล",
       "rfid": "8E:89:AB:12:45:67", "checked_in_today": true }, ...
   ]
   - พนักงาน (employee): หน้าเว็บแสดง "มาทำงาน / ยังไม่มา" จากฟิลด์ checked_in_today
     (true = วันนี้มีการแสกนบัตรเข้าทำงานแล้ว) ให้ฝั่งฐานข้อมูลเป็นคนคำนวณ เช่น
       EXISTS (SELECT 1 FROM records r WHERE r.card_id = c.id AND r.record_date = CURDATE())
   POST   cards.php        body: {type,name,rfid}  ->  card ที่สร้างแล้ว (มี id)
   PUT    cards.php?id=1   body: {type,name,rfid}  ->  card ที่แก้แล้ว
   DELETE cards.php?id=1   ->  { "ok": true }
   API จัดการเฉพาะพนักงานใน registered_users เท่านั้น
 
   GET  records.php?limit=10   ->  แถวล่าสุดก่อน ไม่เกิน limit แถว  เป็น array
        [ { "id": 1, "name": "พนักงาน นามสกุล", "date": "2026-10-10", "time": "09:00:00",
            "face_status": "normal",            // "normal" | "risk"
            "heart_rate": 85,
            "answer1": "น้อย", "answer2": "ปานกลาง", "answer3": "น้อย" }, ... ]
        หรือจะส่งเป็น { "total": 1234, "rows": [ ...แถวเหมือนข้างบน... ] }
        ถ้ามี total หน้าเว็บจะบอกว่า "แสดง 10 แถวล่าสุด จากทั้งหมด N แถว"
 
   ล็อกอิน (ใช้ PHP session / cookie)  auth.php
     GET  auth.php?action=session  ->  { "authenticated": true, "user": "admin" }  หรือ { "authenticated": false }
     POST auth.php?action=login    body {"username","password"}  ->  { "user": "admin" }  |  401 { "error": "..." }
     POST auth.php?action=logout   ->  { "ok": true }
   สำคัญ: cards.php และ records.php ต้องตรวจ session เองทุกครั้ง และตอบ 401 ถ้ายังไม่ล็อกอิน
          (หน้าเว็บจะเด้งกลับหน้าล็อกอินให้เอง) — การล็อกอินฝั่งเว็บอย่างเดียวไม่ใช่ความปลอดภัยจริง
 
   ข้อความ error: ตอบ status 4xx/5xx พร้อม JSON ที่มีข้อความภาษาไทยให้ผู้ใช้เห็นได้เลย
     { "error": "RFID นี้ถูกใช้งานแล้ว", "field": "rfid" }     // field ไม่บังคับ ถ้าใส่ จะแสดงข้อความใต้ช่องนั้น
     เช่น RFID ซ้ำ -> 409 | ข้อมูลไม่ครบ -> 422
   ---------------------------------------------------------- */
 
// ----- ข้อมูลจำลอง (mock) -----
const MOCK = {
  cards: [
    { id: 1,  type: 'employee', name: 'พนักงาน นามสกุล',     rfid: '8E:89:AB:12:45:67' },
    { id: 2,  type: 'employee', name: 'กิตติพงษ์ กิตติ',      rfid: '9A:22:CC:88:11:33' },
    { id: 3,  type: 'employee', name: 'นันทพร นันทวัฒน์',     rfid: '7D:44:BB:00:99:AA' },
    { id: 4,  type: 'employee', name: 'ปาริชาติ สาดไปสมาชิก', rfid: '3C:5F:21:AA:07:D4' },
    { id: 5,  type: 'employee', name: 'สมชาย ใจดี',           rfid: 'B1:09:6E:42:F3:18' },
    { id: 6,  type: 'employee', name: 'วิภา รัตนากร',         rfid: '1A:7C:90:E5:20:6B' },
    { id: 7,  type: 'employee', name: 'ธนากร เจริญสุข',       rfid: 'D2:48:0F:B7:33:91' },
    { id: 8,  type: 'employee', name: 'สุภาพร แสงทอง',        rfid: '6E:A0:15:C9:7B:42' },
    { id: 9,  type: 'employee', name: 'ณัฐพล มั่นคง',         rfid: 'F4:33:8A:61:0D:E7' },
    { id: 10, type: 'employee', name: 'อรอนงค์ ศรีสวัสดิ์',   rfid: '29:BD:56:14:A8:C0' },
    { id: 11, type: 'employee', name: 'ปิยะ วงศ์ใหญ่',        rfid: '85:E1:3D:7A:52:9F' },
    { id: 12, type: 'employee', name: 'ชลธิชา พูนผล',         rfid: '4B:C6:A2:08:F9:35' },
 
  ],
  // เรียงใหม่สุดก่อน (เหมือนที่ API ควรส่ง)
  records: [
    r(1,  'พนักงาน นามสกุล',     '2026-10-10', '09:00:00', 'normal', 85, 'น้อย',     'ปานกลาง', 'น้อย'),
    r(2,  'กิตติพงษ์ กิตติ',      '2026-10-10', '08:53:00', 'normal', 91, 'ปานกลาง', 'น้อย',     'ปานกลาง'),
    r(3,  'นันทพร นันทวัฒน์',     '2026-10-10', '08:50:00', 'risk',   87, 'มาก',      'ปานกลาง', 'มาก'),
    r(4,  'พนักงาน นามสกุล',     '2026-10-10', '09:00:00', 'normal', 85, 'น้อย',     'ปานกลาง', 'น้อย'),
    r(5,  'ปาริชาติ สาดไปสมาชิก', '2026-10-09', '09:08:00', 'normal', 85, 'น้อย',     'น้อย',     'มาก'),
    r(6,  'พนักงาน นามสกุล',     '2026-10-09', '09:02:00', 'normal', 85, 'น้อย',     'ปานกลาง', 'น้อย'),
    r(7,  'นันทพร นันทวัฒน์',     '2026-10-09', '08:55:00', 'risk',   89, 'มาก',      'ปานกลาง', 'มาก'),
    r(8,  'กิตติพงษ์ กิตติ',      '2026-10-09', '08:32:00', 'normal', 57, 'น้อย',     'น้อย',     'ปานกลาง'),
    r(9,  'กิตติพงษ์ กิตติ',      '2026-10-08', '09:28:00', 'normal', 95, 'ปานกลาง', 'น้อย',     'น้อย'),
    r(10, 'นันทพร นันทวัฒน์',     '2026-10-08', '09:01:00', 'risk',   90, 'มาก',      'ปานกลาง', 'มาก'),
    r(11, 'ปาริชาติ สาดไปสมาชิก', '2026-10-08', '08:47:00', 'normal', 78, 'น้อย',     'น้อย',     'มาก'),
    r(12, 'พนักงาน นามสกุล',     '2026-10-08', '08:40:00', 'normal', 85, 'ปานกลาง', 'มาก',      'น้อย'),
  ],
};
// วันที่ "วันนี้" ของข้อมูลจำลอง = วันที่ล่าสุดในบันทึก (โหมด API เซิร์ฟเวอร์เป็นคนตัดสิน)
function mockToday() { return MOCK.records.map(x => x.date).sort().pop(); }
function r(id, name, date, time, face_status, heart_rate, answer1, answer2, answer3) {
  return { id, name, date, time, face_status, heart_rate, answer1, answer2, answer3 };
}
 
// ----- เรียก API + แปลง error ให้เป็นข้อความที่อ่านรู้เรื่อง -----
class ApiError extends Error {
  constructor(message, status = 0, field = '') {
    super(message);
    this.status = status;   // HTTP status (0 = ต่อเซิร์ฟเวอร์ไม่ได้)
    this.field = field;     // ชื่อช่องฟอร์มที่เซิร์ฟเวอร์ระบุ (ถ้ามี)
  }
}
const HTTP_MESSAGES = {
  400: 'ข้อมูลที่ส่งไปไม่ถูกต้อง',
  401: 'ยังไม่ได้เข้าสู่ระบบ หรือเซสชันหมดอายุ',
  403: 'ไม่มีสิทธิ์ทำรายการนี้',
  404: 'ไม่พบไฟล์ API ตรวจสอบ path ใน config.js',
  409: 'ข้อมูลซ้ำกับที่มีอยู่แล้ว',
  422: 'ข้อมูลที่ส่งไปไม่ถูกต้อง',
  500: 'เซิร์ฟเวอร์ผิดพลาด ลองอีกครั้ง หรือแจ้งผู้ดูแลระบบ',
};
async function http(url, options = {}) {
  let res, text;
  try {
    res = await fetch(url, {
      credentials: CONFIG.API_CREDENTIALS,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      ...options,
    });
    text = await res.text();
  } catch {
    throw new ApiError('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจสอบว่าเซิร์ฟเวอร์เปิดอยู่ และลิงก์ API ใน config.js ถูกต้อง');
  }
  let body = null;
  if (text) {
    try { body = JSON.parse(text); }
    catch { if (res.ok) throw new ApiError('เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON (ตรวจสอบว่าไฟล์ PHP ไม่มี error หรือข้อความอื่นปนออกมา)'); }
  }
  if (!res.ok) {
    throw new ApiError(body?.error || body?.message || HTTP_MESSAGES[res.status] || `เซิร์ฟเวอร์ตอบกลับ ${res.status}`, res.status, body?.field || '');
  }
  return body;
}
function expectArray(body) {
  if (!Array.isArray(body)) throw new ApiError('รูปแบบข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง (ต้องเป็น array)');
  return body;
}
 
// ----- ข้อมูลบัตร / บันทึก (mock หรือ API ขึ้นกับ CONFIG.USE_API) -----
const Data = {
  async listCards(type) {
    if (CONFIG.USE_API) return expectArray(await http(type === 'visitor' ? 'api/visitors.php' : `${CONFIG.API.cards}?type=employee`));
    const today = mockToday();
    return MOCK.cards.filter(c => c.type === type).map(c =>
      type === 'employee'
        ? { ...c, checked_in_today: MOCK.records.some(x => x.date === today && x.name === c.name) }
        : { ...c });
  },
  async createCard(card) {
    if (CONFIG.USE_API) return http(card.type === 'visitor' ? 'api/visitors.php' : CONFIG.API.cards, { method: 'POST', body: JSON.stringify(card) });
    const created = { ...card, id: Date.now() };
    MOCK.cards.push(created);
    return created;
  },
  async updateCard(id, card) {
    if (CONFIG.USE_API) return http(card.type === 'visitor' ? `api/visitors.php?id=${encodeURIComponent(id)}` : `${CONFIG.API.cards}?id=${encodeURIComponent(id)}&type=employee`, { method: 'PUT', body: JSON.stringify(card) });
    const i = MOCK.cards.findIndex(c => c.id === id);
    MOCK.cards[i] = { ...MOCK.cards[i], ...card };
    return MOCK.cards[i];
  },
  async deleteCard(id, type) {
    if (CONFIG.USE_API) return http(type === 'visitor' ? `api/visitors.php?id=${encodeURIComponent(id)}` : `${CONFIG.API.cards}?id=${encodeURIComponent(id)}&type=employee`, { method: 'DELETE' });
    MOCK.cards = MOCK.cards.filter(c => c.id !== id);
    return { ok: true };
  },
  // คืน { rows, total } — total เป็น null ถ้าเซิร์ฟเวอร์ไม่ได้บอกจำนวนทั้งหมด
  async listRecords(limit) {
    if (CONFIG.USE_API) {
      const body = await http(`${CONFIG.API.records}?limit=${encodeURIComponent(limit)}`);
      const rows = Array.isArray(body) ? body : body?.rows;
      if (!Array.isArray(rows)) throw new ApiError('รูปแบบข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง (ต้องเป็น array หรือ {total, rows})');
      const total = !Array.isArray(body) && Number.isFinite(Number(body.total)) ? Number(body.total) : null;
      return { rows: rows.slice(0, limit), total };
    }
    return { rows: MOCK.records.slice(0, limit).map(x => ({ ...x })), total: MOCK.records.length };
  },
};
 
// ----- ล็อกอิน -----
let mockUser = null;
try { mockUser = sessionStorage.getItem('bp_user'); } catch { /* ไม่มี storage ก็ใช้ในหน่วยความจำ */ }
const Auth = {
  async session() {
    if (CONFIG.USE_API) {
      try { return await http(`${CONFIG.API.auth}?action=session`); }
      catch (err) { if (err.status === 401) return { authenticated: false }; throw err; }
    }
    return mockUser ? { authenticated: true, user: mockUser, role: 'admin' } : { authenticated: false, user: null, role: null };
  },
  async login(username, password) {
    if (CONFIG.USE_API) {
      try {
        return await http(`${CONFIG.API.auth}?action=login`, { method: 'POST', body: JSON.stringify({ username, password }) });
      } catch (err) {
        if (err.status === 401 && err.message === HTTP_MESSAGES[401]) throw new ApiError('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
        throw err;
      }
    }
    if (username === CONFIG.MOCK_LOGIN.username && password === CONFIG.MOCK_LOGIN.password) {
      mockUser = username;
      try { sessionStorage.setItem('bp_user', username); } catch { /* ignore */ }
      return { user: username, role: 'admin' };
    }
    throw new ApiError('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
  },
  async logout() {
    if (CONFIG.USE_API) { try { await http(`${CONFIG.API.auth}?action=logout`, { method: 'POST' }); } catch { /* ออกจากหน้าเว็บต่อไป */ } }
    mockUser = null;
    try { sessionStorage.removeItem('bp_user'); } catch { /* ignore */ }
  },
};
 
/* ----------------------------------------------------------
   2) UI
   ---------------------------------------------------------- */
 
// ----- ไอคอน (inline SVG สไตล์ Lucide) -----
const ICONS = {
  flame:    '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
  home:     '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
  users:    '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  user:     '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  plus:     '<path d="M5 12h14"/><path d="M12 5v14"/>',
  external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  menu:     '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
  x:        '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  logout:   '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
};
function icon(name) {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`;
}
function hydrateIcons(root = document) {
  root.querySelectorAll('[data-icon]').forEach(el => {
    if (!el.firstElementChild) el.innerHTML = icon(el.dataset.icon);
  });
}
 
// ----- ตัวช่วยทั่วไป -----
const $ = (sel, root = document) => root.querySelector(sel);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
 
function initials(name) {
  const parts = name.trim().split(/\s+/);
  const first = s => (s.replace(/^[เแโใไ]/, '')[0] || s[0] || '');
  return parts.length > 1 ? `${first(parts[0])}.${first(parts[1])}.` : `${first(parts[0])}.`;
}
function fmtDate(iso) {            // 2026-10-10 -> 10/10/69 (พ.ศ. 2 หลัก)
  const [y, m, d] = iso.split('-').map(Number);
  return `${d}/${m}/${String(y + 543).slice(-2)}`;
}
function fmtTime(t) {              // 08:53:00 -> 8.53
  const [h, m] = t.split(':');
  return `${Number(h)}.${m}`;
}
const FACE_LABEL = { normal: 'ปกติ', risk: 'มีความเสี่ยง', unknown: 'ไม่มีข้อมูล' };
// สถานะพนักงาน: วันนี้มีข้อมูลสแกนบัตรหรือไม่
function statusInfo(c) {
  if (c.type === 'visitor') return c.status === 'active' ? { text: 'ใช้งาน', ok: true } : { text: 'ไม่ใช้งาน', ok: false };
  return c.checked_in_today ? { text: 'มาทำงาน', ok: true } : { text: 'ยังไม่มา', ok: false };
}
 
// ----- สถานะของแอป -----
const state = {
  route: 'home',
  cards: { employee: [], visitor: [] },
  homeTab: 'employee',
  records: [],
  recordsTotal: null,
  loading: false,
  error: '',
  sideLoadError: '',
  sort: { key: null, dir: 'asc' },           // การเรียงตาราง (คลิกหัวคอลัมน์)
  authed: false,
  user: '',
};
 
// ----- Toast -----
let toastTimer;
function toast(msg) {
  const el = $('#toast');
  el.textContent = msg;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (el.hidden = true), 3200);
}
 
// ----- หน้าล็อกอิน -----
function setLocked(locked) {
  document.querySelectorAll('.topbar, .sidebar, .main').forEach(el => { el.inert = locked; });
}
function showLogin(message = '') {
  state.authed = false;
  state.user = '';
  closeModal();
  closeSidebar();
  $('#userBox').hidden = true;
  $('#content').innerHTML = '';
  setLocked(true);
  $('#login').hidden = false;
  $('#l-pass').value = '';
  const err = $('#loginError');
  err.textContent = message;
  err.hidden = !message;
  const hint = $('#loginHint');
  hint.hidden = CONFIG.USE_API;
  hint.textContent = `โหมดทดสอบ: ชื่อผู้ใช้ ${CONFIG.MOCK_LOGIN.username} / รหัสผ่าน ${CONFIG.MOCK_LOGIN.password}`;
  ($('#l-user').value ? $('#l-pass') : $('#l-user')).focus();
}
// ----- แยกทางเข้า Admin / HR จาก role ที่ PHP ตรวจสอบแล้ว -----
// api/auth.php ต้องส่ง role: 'admin' หรือ 'hr' ทั้งใน action=login และ action=session
function handleSuccessfulAuth(result) {
  if (result?.role === 'hr') {
    // HR ไม่ควรเห็น UI จัดการ Employee/Visitor ของ Admin
    window.location.replace('./db/dbb.php');
    return;
  }

  if (result?.role === 'admin') {
    onAuthed(result.user);
    return;
  }

  // ไม่ใช้ชื่อผู้ใช้ที่มาจากฝั่ง Browser เพื่อกำหนดสิทธิ์
  showLogin('ระบบไม่ได้รับข้อมูลสิทธิ์ผู้ใช้จากเซิร์ฟเวอร์ กรุณาตรวจ api/auth.php');
}

function onAuthed(user) {
  state.authed = true;
  state.user = user || '';
  $('#login').hidden = true;
  setLocked(false);
  $('#userName').textContent = state.user || 'ผู้ดูแลระบบ';
  $('#userAvatar').textContent = (state.user || 'A').slice(0, 1).toUpperCase();
  $('#userBox').hidden = false;
  goToRoute();
}
// ถ้า error คือ 401 (เซสชันหมดอายุ) ให้กลับไปหน้าล็อกอิน แล้วคืน true
function handleAuthError(err) {
  if (err?.status === 401) { showLogin('เซสชันหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง'); return true; }
  return false;
}
 
// ----- Modal -----
let lastFocus = null;
function openModal(title, bodyHtml) {
  lastFocus = document.activeElement;
  $('#modalTitle').textContent = title;
  $('#modalBody').innerHTML = bodyHtml;
  $('#modal').hidden = false;
  const first = $('#modalBody input, #modalBody select, #modalBody button');
  if (first) first.focus();
}
function closeModal() {
  if ($('#modal').hidden) return;
  $('#modal').hidden = true;
  $('#modalBody').innerHTML = '';
  if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
}
 
function clearFieldErrors(form) {
  form.querySelectorAll('.field').forEach(x => { x.classList.remove('has-error'); x.querySelector('.error')?.remove(); });
}
function showFieldError(form, name, msg) {
  const el = form.elements[name];
  const field = el?.closest('.field');
  if (!field) return false;
  field.classList.add('has-error');
  field.insertAdjacentHTML('beforeend', `<div class="error">${esc(msg)}</div>`);
  return true;
}
 
function openCardForm(type, card = null) {
  const isEdit = !!card;
  const isEmp = type === 'employee';
  const label = isEmp ? 'พนักงาน' : 'ผู้มาเยือน';
  openModal(isEdit ? `แก้ไขข้อมูล${label}` : `เพิ่มบัตรใหม่ (${label})`, `
    <form id="cardForm" novalidate>
      <div class="field">
        <label for="f-name">ชื่อ-นามสกุล</label>
        <input id="f-name" name="name" value="${esc(card?.name)}" autocomplete="off">
      </div>
      ${isEmp ? '' : `
      <div class="field">
        <label for="f-org">มาจาก (หน่วยงาน / บริษัท)</label>
        <input id="f-org" name="organization" value="${esc(card?.organization)}" placeholder="ไม่บังคับ" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-purpose">วัตถุประสงค์ / ผู้ที่มาพบ</label>
        <input id="f-purpose" name="purpose" value="${esc(card?.purpose)}" placeholder="ไม่บังคับ" autocomplete="off">
      </div>`}
      <div class="field">
        <label for="f-rfid">RFID TAG ID</label>
        <input id="f-rfid" name="rfid" value="${esc(card?.rfid)}" placeholder="${esc(CONFIG.RFID.placeholder)}" autocomplete="off" autocapitalize="characters">
        <div class="hint">${esc(CONFIG.RFID.hint)}</div>
      </div>
      ${isEmp ? '' : `
      <div class="field">
        <label for="f-status">สถานะ</label>
        <select id="f-status" name="status">
          <option value="active"   ${card?.status !== 'inactive' ? 'selected' : ''}>ใช้งาน</option>
          <option value="inactive" ${card?.status === 'inactive' ? 'selected' : ''}>ไม่ใช้งาน</option>
        </select>
      </div>`}
      <div class="modal__foot">
        <button type="button" class="btn" data-close>ยกเลิก</button>
        <button type="submit" class="btn btn--primary">${isEdit ? 'บันทึก' : 'เพิ่มบัตร'}</button>
      </div>
    </form>`);
 
  $('#cardForm').addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target;
    const val = n => (f.elements[n]?.value ?? '').trim();
    clearFieldErrors(f);
 
    // ตรวจข้อมูล
    const errors = {};
    const name = val('name');
    const rawRfid = val('rfid');
    const rfid = rawRfid ? CONFIG.RFID.normalize(rawRfid) : null;
    if (!name) errors.name = 'กรุณากรอกชื่อ-นามสกุล';
    if (!rawRfid) errors.rfid = 'กรุณากรอก RFID TAG ID';
    else if (!rfid) errors.rfid = CONFIG.RFID.error;
    else if ([...state.cards.employee, ...state.cards.visitor].some(c => String(c.rfid || '').replace(/[:\-\s]/g, '').toUpperCase() === rfid.replace(/[:\-\s]/g, '').toUpperCase() && !(c.type === type && String(c.id) === String(card?.id)))) errors.rfid = 'RFID นี้ถูกใช้งานแล้ว';
    const keys = Object.keys(errors);
    if (keys.length) {
      keys.forEach(k => showFieldError(f, k, errors[k]));
      f.elements[keys[0]].focus();
      return;
    }
 
    const payload = {
      type, name, rfid,
      ...(isEmp ? {} : { status: val('status'), organization: val('organization'), purpose: val('purpose') }),
    };
 
    const submit = f.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
      if (isEdit) await Data.updateCard(card.id, payload);
      else await Data.createCard(payload);
      closeModal();
      await loadCards();
      render();
      toast(isEdit ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มบัตรใหม่แล้ว');
    } catch (err) {
      submit.disabled = false;
      if (handleAuthError(err)) return;
      // เซิร์ฟเวอร์ระบุช่องมา (เช่น RFID ซ้ำ) -> แสดงใต้ช่องนั้น ไม่งั้นแสดงเป็น toast
      if (err.field && showFieldError(f, err.field, err.message)) f.elements[err.field].focus();
      else toast(err.message || 'บันทึกไม่สำเร็จ');
    }
  });
}
 
function openDeleteConfirm(card) {
  openModal('ลบข้อมูล', `
    <p class="modal__text">ต้องการลบ <strong>${esc(card.name)}</strong> (${esc(card.rfid)}) ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้</p>
    <div class="modal__foot">
      <button type="button" class="btn" data-close>ยกเลิก</button>
      <button type="button" class="btn btn--danger" id="confirmDelete">ลบ</button>
    </div>`);
  $('#confirmDelete').addEventListener('click', async e => {
    e.target.disabled = true;
    try {
      await Data.deleteCard(card.id, card.type);
      closeModal();
      await loadCards();
      render();
      toast('ลบข้อมูลแล้ว');
    } catch (err) {
      e.target.disabled = false;
      if (handleAuthError(err)) return;
      toast(err.message || 'ลบไม่สำเร็จ');
    }
  });
}
 
// ----- เรียงข้อมูล (คลิกหัวคอลัมน์: น้อย->มาก, มาก->น้อย, กลับค่าเดิม) -----
const LEVEL = { 'น้อย': 1, 'ปานกลาง': 2, 'มาก': 3 };
const CARD_SORT = {
  name: c => c.name,
  organization: c => c.organization || '',
  purpose: c => c.purpose || '',
  rfid: c => c.rfid,
  status: c => (statusInfo(c).ok ? 1 : 0),
};
const RECORD_SORT = {
  name: x => x.name,
  date: x => `${x.date} ${x.time}`,
  time: x => x.time,
  face: x => (x.face_status === 'risk' ? 1 : 0),
  hr: x => Number(x.hrv ?? -1),
  survey: x => Number(x.survey_answer ?? -1),
};
function sortList(list, getters) {
  const get = getters[state.sort.key];
  if (!get) return list;
  const dir = state.sort.dir === 'desc' ? -1 : 1;
  return [...list].sort((a, b) => {
    const x = get(a), y = get(b);
    const c = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'th');
    return c * dir;
  });
}
function sortTh(label, key, cls = '') {
  const active = state.sort.key === key;
  const dir = state.sort.dir;
  return `<th class="${cls}" aria-sort="${active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}">
    <button type="button" class="th-sort ${active ? 'is-active' : ''}" data-sort="${key}">${label}<span class="th-sort__ind" aria-hidden="true">${active ? (dir === 'asc' ? '▲' : '▼') : '↕'}</span></button></th>`;
}
function toggleSort(key) {
  const s = state.sort;
  if (s.key !== key) state.sort = { key, dir: 'asc' };
  else if (s.dir === 'asc') state.sort = { key, dir: 'desc' };
  else state.sort = { key: null, dir: 'asc' };
  render();
  document.querySelector(`[data-sort="${key}"]`)?.focus();
}
function resetSort() { state.sort = { key: null, dir: 'asc' }; }
 
const visibleCards = type => sortList(state.cards[type] || [], CARD_SORT);
const visibleRecords = () => sortList(state.records, RECORD_SORT);
 
// ----- Export CSV (ตามที่แสดงและเรียงอยู่บนหน้าจอ) -----
function exportCSV() {
  let rows, name;
  if (state.route === 'database') {
    rows = [['ชื่อ-นามสกุล', 'RFID', 'วันที่', 'เวลา', 'สถานะ Camera/AI', 'HRV', 'Survey Score'],
      ...visibleRecords().map(x => [x.name, x.rfid || '', fmtDate(x.date), fmtTime(x.time), FACE_LABEL[x.face_status] || 'ไม่มีข้อมูล', x.hrv ?? '', x.survey_answer ?? ''])];
    name = 'database';
  } else {
    const type = currentCardType();
    rows = type === 'visitor'
      ? [['ชื่อ-นามสกุล', 'มาจาก', 'วัตถุประสงค์', 'RFID TAG ID', 'สถานะ'],
         ...visibleCards(type).map(c => [c.name, c.organization || '', c.purpose || '', c.rfid, statusInfo(c).text])]
      : [['ชื่อ-นามสกุล', 'RFID TAG ID', 'สถานะ'],
         ...visibleCards(type).map(c => [c.name, c.rfid, statusInfo(c).text])];
    name = type === 'visitor' ? 'visitors' : 'employees';
  }
  const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });   // \ufeff = ให้ Excel อ่านภาษาไทยได้
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${name}-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(a.href);
  toast('ส่งออกไฟล์ CSV แล้ว');
}
 
// ----- phpMyAdmin -----
function openPhpMyAdmin(e) {
  if (!CONFIG.PHPMYADMIN_URL || CONFIG.PHPMYADMIN_URL === '#') {
    e.preventDefault();
    toast('ยังไม่ได้ตั้งค่าลิงก์ phpMyAdmin (แก้ที่ PHPMYADMIN_URL ใน config.js)');
  }
}
 
// ----- การโหลดข้อมูล -----
// แยกการโหลด Employee และ Visitor: API ฝั่งหนึ่งล้มเหลวไม่บล็อกอีกฝั่ง
async function loadCards() {
  const [emp, vis] = await Promise.allSettled([
    Data.listCards('employee'),
    Data.listCards('visitor')
  ]);
  if (emp.status === 'fulfilled') state.cards.employee = emp.value;
  if (vis.status === 'fulfilled') state.cards.visitor = vis.value;

  const type = currentCardType();
  if (type === 'employee' && emp.status === 'rejected') throw emp.reason;
  if (type === 'visitor' && vis.status === 'rejected') throw vis.reason;

  // แจ้งว่าข้อมูลอีกกลุ่มโหลดไม่ได้ แต่ไม่ทำให้กลุ่มที่เปิดอยู่หายไป
  state.sideLoadError = (type === 'employee' && vis.status === 'rejected')
    ? 'ไม่สามารถโหลดข้อมูลผู้มาเยือน: ' + (vis.reason?.message || 'API มีปัญหา')
    : (type === 'visitor' && emp.status === 'rejected')
      ? 'ไม่สามารถโหลดข้อมูลพนักงาน: ' + (emp.reason?.message || 'API มีปัญหา')
      : '';
}

async function loadData() {
  if (state.route === 'database') {
    const { rows, total } = await Data.listRecords(CONFIG.RECORDS_PREVIEW_LIMIT);
    state.records = rows;
    state.recordsTotal = total;
  } else {
    await loadCards();
  }
}
async function loadForRoute() {
  state.loading = true; state.error = ''; state.sideLoadError = '';
  render();
  try {
    await loadData();
  } catch (err) {
    if (handleAuthError(err)) return;
    state.error = err.message;
  }
  state.loading = false;
  render();
}
 
// ----- รีเฟรชอัตโนมัติ (เงียบๆ ไม่กระพริบ) -----
let refreshing = false;
async function refreshSilently() {
  if (refreshing || state.loading || !state.authed || document.hidden) return;
  refreshing = true;
  const route = state.route;
  const snapshot = () => JSON.stringify([state.cards, state.records, state.recordsTotal]);
  const before = snapshot();
  try {
    await loadData();
    if (state.route === route && snapshot() !== before) render();   // เรนเดอร์ใหม่เฉพาะเมื่อข้อมูลเปลี่ยน
  } catch (err) {
    handleAuthError(err);                                           // error อื่นๆ ข้ามไป ลองใหม่รอบหน้า
  }
  refreshing = false;
}
function startAutoRefresh() {
  if (!CONFIG.USE_API || !(CONFIG.REFRESH_SECONDS > 0)) return;
  setInterval(refreshSilently, CONFIG.REFRESH_SECONDS * 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshSilently(); });
}
 
// ----- เรนเดอร์ -----
const PAGES = {
  home:      { title: 'Admin | Home page' },
  employees: { title: 'Admin | จัดการบัตร RFID', sub: 'เพิ่ม แก้ไข และลบข้อมูลพนักงาน' },
  visitors: { title: 'Admin | จัดการบัตร RFID', sub: 'เพิ่ม แก้ไข และลบข้อมูลผู้มาเยือน' },
  database:  { title: 'Admin | ฐานข้อมูล',        sub: 'ระบบจัดการฐานข้อมูล Burnout Pulse AI' },
};
function currentCardType() {
  if (state.route === 'visitors') return 'visitor';
  if (state.route === 'employees') return 'employee';
  return state.homeTab;
}
 
function render() {
  const page = PAGES[state.route];
  document.title = `${page.title} | Burnout Pulse AI`;
  $('#pageTitle').textContent = page.title;
  const sub = $('#pageSubtitle');
  sub.textContent = page.sub || '';
  sub.hidden = !page.sub;
 
  document.querySelectorAll('.nav__item').forEach(a => {
    const active = a.dataset.route === state.route;
    a.classList.toggle('is-active', active);
    active ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current');
  });
 
  // ปุ่มด้านขวาบน
  const isDb = state.route === 'database';
  $('#pageActions').innerHTML = isDb
    ? `<button class="btn" data-action="export"><span data-icon="download"></span>ส่งออก CSV</button>
       <a class="btn btn--primary" data-php-link href="${esc(CONFIG.PHPMYADMIN_URL)}" target="_blank" rel="noopener"><span data-icon="external"></span>เข้าสู่ phpMyAdmin</a>`
    : `<button class="btn" data-action="export"><span data-icon="download"></span>ส่งออก CSV</button>
       <button class="btn btn--primary" data-action="add"><span data-icon="plus"></span>เพิ่มบัตรใหม่</button>`;
 
  // แท็บ
  const tabs = $('#tabs');
  const nEmp = state.cards.employee.length, nVis = state.cards.visitor.length;
  if (state.route === 'home') {
    tabs.innerHTML = `<button class="tab ${state.homeTab === 'employee' ? 'is-active' : ''}" role="tab" data-tab="employee">พนักงาน (${nEmp})</button>
      <button class="tab ${state.homeTab === 'visitor' ? 'is-active' : ''}" role="tab" data-tab="visitor">ผู้มาเยือน (${nVis})</button>`;
  } else if (state.route === 'employees' || state.route === 'visitors') {
    tabs.innerHTML = `<button class="tab is-active" role="tab">${state.route === 'employees' ? 'พนักงาน (' + nEmp + ')' : 'ผู้มาเยือน (' + nVis + ')'}</button>`;
  } else tabs.innerHTML = '';
  // หน้าฐานข้อมูลใช้เส้นคั่นแทนแท็บ
  $('#tabs').classList.toggle('divider', isDb);
 
  // ท้ายหน้า (เฉพาะหน้าหลัก)
  $('#pageFoot').hidden = state.route !== 'home';
 
  // เนื้อหา
  const content = $('#content');
  if (state.loading) content.innerHTML = `<div class="card"><div class="state">กำลังโหลดข้อมูล…</div></div>`;
  else if (state.error) content.innerHTML = `<div class="card"><div class="state"><strong>โหลดข้อมูลไม่สำเร็จ</strong>${esc(state.error)}<br><br><button class="btn" data-action="retry">ลองอีกครั้ง</button></div></div>`;
  else content.innerHTML = (state.sideLoadError && !isDb ? `<div class="card" style="margin-bottom:12px;padding:12px;color:#92400e">${esc(state.sideLoadError)}</div>` : '') + (isDb ? renderRecords() : renderCards(currentCardType()));
 
  hydrateIcons();
  const foot = $('#phpLinkFoot'); if (foot) foot.href = CONFIG.PHPMYADMIN_URL;
}
 
function renderCards(type) {
  const canManage = state.route !== 'home';
  const cards = state.cards[type] || [];
  const title = type === 'visitor' ? 'ผู้มาเยือน' : 'พนักงาน';
  if (!cards.length) return `<div class="card"><div class="state"><strong>ยังไม่มีข้อมูล${title}</strong>กด "เพิ่มบัตรใหม่" เพื่อเริ่มต้น</div></div>`;
  const rows = visibleCards(type).map(c => `
    <tr>
      <td><div class="person"><span class="avatar">${esc(initials(c.name))}</span>${esc(c.name)}</div></td>
      ${type === 'visitor' ? `<td>${esc(c.organization || '—')}</td><td>${esc(c.purpose || '—')}</td>` : ''}
      <td class="mono">${esc(c.rfid)}</td>
      <td><span class="badge ${statusInfo(c).ok ? 'badge--ok' : 'badge--bad'}">${statusInfo(c).text}</span></td>
      ${canManage ? `<td class="col-actions"><div class="actions">
        <button class="btn-sm" data-action="edit" data-id="${esc(c.id)}">แก้ไข</button>
        <button class="btn-sm btn-sm--danger" data-action="delete" data-id="${esc(c.id)}">ลบ</button>
      </div></td>` : ''}
    </tr>`).join('');
  const columns = type === 'visitor' ? [['ชื่อ-นามสกุล','name'],['มาจาก','organization'],['วัตถุประสงค์','purpose'],['RFID TAG ID','rfid'],['สถานะ','status']]
    : [['ชื่อ-นามสกุล','name'],['RFID TAG ID','rfid'],['สถานะ','status']];
  return `<div class="card"><div class="table-wrap"><table>
    <thead><tr>${columns.map(([l,k]) => sortTh(l,k)).join('')}${canManage ? '<th class="col-actions">การดำเนินการ</th>' : ''}</tr></thead>
    <tbody>${rows}</tbody></table></div></div>`;
}

function renderRecords() {
  if (!state.records.length) {
    return `<div class="card"><div class="state"><strong>ยังไม่มีข้อมูล</strong>ยังไม่มีบันทึกการตรวจในฐานข้อมูล</div></div>`;
  }
  const rows = visibleRecords().map(x => `
    <tr>
      <td><div class="person"><span class="avatar">${esc(initials(x.name))}</span>${esc(x.name)}</div></td>
      <td class="mono">${fmtDate(x.date)}</td>
      <td class="mono">${fmtTime(x.time)}</td>
      <td class="col-center"><span class="badge ${x.face_status === 'normal' ? 'badge--ok' : 'badge--bad'}">${FACE_LABEL[x.face_status] || 'ไม่มีข้อมูล'}</span></td>
      <td class="mono">${x.hrv == null ? '—' : `${esc(x.hrv)} ms`}</td>
      <td class="mono">${x.survey_answer == null ? '—' : esc(x.survey_answer)}</td>
    </tr>`).join('');
 
  // แสดงเฉพาะตัวอย่าง (RECORDS_PREVIEW_LIMIT แถว) ที่เหลือไปดูใน phpMyAdmin
  const shown = state.records.length, total = state.recordsTotal;
  const hasMore = total != null ? total > shown : shown >= CONFIG.RECORDS_PREVIEW_LIMIT;
  const note = hasMore ? `
    <div class="table-note">
      <span>แสดง ${shown} แถวล่าสุด${total != null ? ` จากทั้งหมด ${total} แถว` : ''} — ดูข้อมูลทั้งหมดได้ใน phpMyAdmin</span>
      <a class="btn" data-php-link href="${esc(CONFIG.PHPMYADMIN_URL)}" target="_blank" rel="noopener"><span data-icon="external"></span>ดูทั้งหมดใน phpMyAdmin</a>
    </div>` : '';
 
  const cols = [['ชื่อ-นามสกุล', 'name', ''], ['วันที่', 'date', ''], ['เวลา', 'time', ''], ['สถานะ Camera / AI', 'face', 'col-center'],
                ['HRV', 'hr', ''], ['Survey Score', 'survey', '']];
  return `<div class="card"><div class="table-wrap"><table>
    <thead><tr>${cols.map(([l, k, c]) => sortTh(l, k, c)).join('')}</tr></thead>
    <tbody>${rows}</tbody></table></div>${note}</div>`;
}
 
// ----- Routing -----
function routeFromHash() {
  const r = location.hash.replace('#', '');
  return PAGES[r] ? r : 'home';
}
function goToRoute() {
  if (!state.authed) return;
  state.route = routeFromHash();
  state.sideLoadError = '';
  resetSort();
  closeSidebar();
  window.scrollTo(0, 0);
  loadForRoute();
}
 
// ----- Sidebar (มือถือ) -----
function openSidebar()  { $('#sidebar').classList.add('is-open'); $('#backdrop').hidden = false; $('#menuBtn').setAttribute('aria-expanded', 'true'); }
function closeSidebar() { $('#sidebar').classList.remove('is-open'); $('#backdrop').hidden = true;  $('#menuBtn').setAttribute('aria-expanded', 'false'); }
 
// ----- Event listeners -----
document.addEventListener('click', e => {
  if (e.target.closest('[data-php-link]')) { openPhpMyAdmin(e); return; }
 
  const sortBtn = e.target.closest('[data-sort]');
  if (sortBtn) { toggleSort(sortBtn.dataset.sort); return; }
 
  const t = e.target.closest('[data-action], [data-tab], [data-close]');
  if (!t) return;
 
  if (t.hasAttribute('data-close')) { closeModal(); return; }
 
  if (t.hasAttribute('data-tab')) { state.homeTab = t.dataset.tab; resetSort(); loadForRoute(); return; }
  const type = currentCardType();
  const findCard = () => (state.cards[type] || []).find(c => String(c.id) === t.dataset.id);
  switch (t.dataset.action) {
    case 'export': exportCSV(); break;
    case 'add':    openCardForm(type); break;
    case 'edit':   { const c = findCard(); if (c) openCardForm(type, c); break; }
    case 'delete': { const c = findCard(); if (c) openDeleteConfirm(c); break; }
    case 'retry':  loadForRoute(); break;
  }
});
 
$('#modalClose').addEventListener('click', closeModal);
$('#modal').addEventListener('mousedown', e => { if (e.target.id === 'modal') closeModal(); });
$('#menuBtn').addEventListener('click', () => ($('#sidebar').classList.contains('is-open') ? closeSidebar() : openSidebar()));
$('#backdrop').addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (!$('#modal').hidden) closeModal(); else closeSidebar();
});
window.addEventListener('hashchange', goToRoute);
 
$('#loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const f = e.target;
  const username = f.elements.username.value.trim();
  const password = f.elements.password.value;
  const err = $('#loginError');
  err.hidden = true;
  if (!username || !password) {
    err.textContent = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    err.hidden = false;
    return;
  }
  const btn = f.querySelector('[type="submit"]');
  btn.disabled = true;
  try {
    const res = await Auth.login(username, password);
    f.elements.password.value = '';
    handleSuccessfulAuth(res);
  } catch (ex) {
    err.textContent = ex.message || 'เข้าสู่ระบบไม่สำเร็จ';
    err.hidden = false;
    f.elements.password.select();
  }
  btn.disabled = false;
});
 
$('#logoutBtn').addEventListener('click', async () => {
  await Auth.logout();
  showLogin();
});
 
// ----- นาฬิกา / Time stamp มุมขวาบน (เวลาตามเครื่องของผู้ใช้) -----
// นาฬิกา / Timestamp มุมขวาบน
function tickClock() {
  const d = new Date();
  const p = n => String(n).padStart(2, '0');
  const h12 = d.getHours() % 12 || 12;
  $('#clockDate').textContent = `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`;
  $('#clockTime').textContent = `${p(h12)}:${p(d.getMinutes())}:${p(d.getSeconds())} ${d.getHours() < 12 ? 'AM' : 'PM'}`;
}
function startClock() {
  tickClock();
  setInterval(tickClock, 1000);
}
 
 
// ----- เริ่มต้น -----
(async function boot() {
  hydrateIcons();
  startClock();
  startAutoRefresh();
  setLocked(true);
  try {
    const s = await Auth.session();
    if (s?.authenticated) { handleSuccessfulAuth(s); return; }
    showLogin();
  } catch (err) {
    showLogin(err.message);   // เช่น ต่อเซิร์ฟเวอร์ไม่ได้
  }
})();