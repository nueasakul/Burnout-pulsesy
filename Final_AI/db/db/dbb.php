<?php
// Burnout Pulse AI - Report access for Admin and HR.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path'     => '/',
    ]);
    session_start();
}
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$isHR = !empty($_SESSION['hr_user']);
$isAdmin = !$isHR && !empty($_SESSION['admin_user']);
if (!$isAdmin && !$isHR) {
    header('Location: ../index.html');
    exit;
}

$homeUrl = 'dbb.php';
$adminHomeUrl = '../index.html';

require_once __DIR__ . '/../api/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Burnout report DB error: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed');
}

$raw_rfid = trim((string)($_GET['rfid'] ?? ''));
$users_data = [];
$profile_name = '';

if ($raw_rfid !== '' && !preg_match('/^[0-9A-Fa-f:\-\s]{8,60}$/', $raw_rfid)) {
    http_response_code(400);
    exit('Invalid RFID');
}
$selected_rfid = strtoupper(preg_replace('/[^0-9A-F]/i', '', $raw_rfid) ?? '');
if ($selected_rfid !== '' && (strlen($selected_rfid) < 8 || strlen($selected_rfid) > 20 || strlen($selected_rfid) % 2 !== 0)) {
    http_response_code(400);
    exit('Invalid RFID');
}

function report_rows(mysqli $conn, string $sql, string $uid): array {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$w_total=0; $w_burnout=0; $w_neutral=0;
$w_high=0; $w_mod=0; $w_low=0;
$w_labels=[]; $w_hrv_data=[]; $w_cam_data=[];
$w_burnout_pct=0; $w_neutral_pct=0; $w_final_score=0;

$m_total=0; $m_burnout=0; $m_neutral=0;
$m_high=0; $m_mod=0; $m_low=0;
$m_labels=[]; $m_hrv_data=[]; $m_cam_data=[];
$m_burnout_pct=0; $m_neutral_pct=0; $m_final_score=0;

try {
    if ($selected_rfid === '') {
        $sql = "SELECT r.rfid_uid, r.username,
                (SELECT u.camera_state FROM user_logs u
                   WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(u.rfid_uid)), ':', ''), '-', ''), ' ', '')
                       = REPLACE(REPLACE(REPLACE(UPPER(TRIM(r.rfid_uid)), ':', ''), '-', ''), ' ', '')
                   ORDER BY u.recorded_time DESC LIMIT 1) AS latest_state,
                (SELECT u.recorded_time FROM user_logs u
                   WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(u.rfid_uid)), ':', ''), '-', ''), ' ', '')
                       = REPLACE(REPLACE(REPLACE(UPPER(TRIM(r.rfid_uid)), ':', ''), '-', ''), ' ', '')
                   ORDER BY u.recorded_time DESC LIMIT 1) AS latest_time,
                (SELECT s.final_score FROM summary_results s
                   WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(s.rfid_uid)), ':', ''), '-', ''), ' ', '')
                       = REPLACE(REPLACE(REPLACE(UPPER(TRIM(r.rfid_uid)), ':', ''), '-', ''), ' ', '')
                   ORDER BY s.created_at DESC LIMIT 1) AS latest_score
                FROM registered_users r ORDER BY r.username";
        $users_data = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    } else {
        $profile = report_rows($conn, "SELECT username FROM registered_users
            WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(rfid_uid)), ':', ''), '-', ''), ' ', '') = ? LIMIT 1", $selected_rfid);
        if (!$profile) {
            http_response_code(404);
            exit('Employee not found');
        }
        $profile_name = $profile[0]['username'];

        $w_score = report_rows($conn, "SELECT AVG(final_score) avg_score FROM
            (SELECT final_score FROM summary_results
             WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(rfid_uid)), ':', ''), '-', ''), ' ', '') = ?
             ORDER BY created_at DESC LIMIT 5) AS sub", $selected_rfid);
        $w_final_score = round((float)($w_score[0]['avg_score'] ?? 0), 2);
        $weekly = report_rows($conn, "SELECT camera_state, survey_answer, hrv, recorded_time FROM user_logs
            WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(rfid_uid)), ':', ''), '-', ''), ' ', '') = ?
            ORDER BY recorded_time DESC LIMIT 5", $selected_rfid);
        foreach (array_reverse($weekly) as $row) {
            $w_total++;
            if ((int)$row['camera_state'] >= 1) $w_burnout++; else $w_neutral++;
            if ((float)$row['survey_answer'] >= 50) $w_high++;
            elseif ((float)$row['survey_answer'] > 16.7) $w_mod++;
            else $w_low++;
            $w_labels[] = date('D H:i', strtotime((string)$row['recorded_time']));
            $w_hrv_data[] = (float)$row['hrv'];
            $w_cam_data[] = ((int)$row['camera_state'] >= 1) ? 100 : 0;
        }
        if ($w_total > 0) {
            $w_burnout_pct = round($w_burnout / $w_total * 100);
            $w_neutral_pct = round($w_neutral / $w_total * 100);
        }

        $m_score = report_rows($conn, "SELECT AVG(final_score) avg_score FROM
            (SELECT final_score FROM summary_results
             WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(rfid_uid)), ':', ''), '-', ''), ' ', '') = ?
             ORDER BY created_at DESC LIMIT 30) AS sub", $selected_rfid);
        $m_final_score = round((float)($m_score[0]['avg_score'] ?? 0), 2);
        $monthly = report_rows($conn, "SELECT camera_state, survey_answer, hrv, recorded_time FROM user_logs
            WHERE REPLACE(REPLACE(REPLACE(UPPER(TRIM(rfid_uid)), ':', ''), '-', ''), ' ', '') = ?
            ORDER BY recorded_time DESC LIMIT 30", $selected_rfid);
        foreach (array_reverse($monthly) as $row) {
            $m_total++;
            if ((int)$row['camera_state'] >= 1) $m_burnout++; else $m_neutral++;
            if ((float)$row['survey_answer'] >= 50) $m_high++;
            elseif ((float)$row['survey_answer'] > 16.7) $m_mod++;
            else $m_low++;
            $m_labels[] = date('d M', strtotime((string)$row['recorded_time']));
            $m_hrv_data[] = (float)$row['hrv'];
            $m_cam_data[] = ((int)$row['camera_state'] >= 1) ? 100 : 0;
        }
        if ($m_total > 0) {
            $m_burnout_pct = round($m_burnout / $m_total * 100);
            $m_neutral_pct = round($m_neutral / $m_total * 100);
        }
    }
} catch (mysqli_sql_exception $e) {
    error_log('Burnout report SQL error: ' . $e->getMessage());
    http_response_code(500);
    exit('Report data unavailable');
} finally {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Burnout Risk Report Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&family=Kanit:wght@600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <link rel="stylesheet" href="./db.css?v=<?php echo time(); ?>">
</head>
<body class="<?php echo $isHR ? 'role-hr' : 'role-admin'; ?>">

    <!-- Top Navigation Bar (สำหรับจอมือถือ/แท็บเล็ต) -->
    <header class="top-header">
        <div class="top-header-left">
            <button type="button" class="sidebar-toggle-btn" onclick="toggleSidebar()" title="เปิด/ปิด เมนู" aria-label="เปิด/ปิด เมนู">
                <svg class="btn-hamburger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="6" x2="20" y2="6"/>
                    <line x1="4" y1="12" x2="20" y2="12"/>
                    <line x1="4" y1="18" x2="20" y2="18"/>
                </svg>
            </button>
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3z"/>
                </svg>
            </div>
            <span class="brand-title">Burnout Pulse AI</span>
        </div>
    </header>

    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebar()"></div>

    <div class="app-layout">
        <!-- Sidebar ด้านซ้าย -->
        <aside class="sidebar-drawer" id="mainSidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand-group">
                    <div class="brand-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3z"/>
                        </svg>
                    </div>
                    <div class="sidebar-brand">Burnout Pulse AI</div>
                </div>
            </div>
            
            <div class="sidebar-body">
                <ul class="sidebar-menu">
                    <!-- 1. หน้าหลัก -->
                    <li>
                        <a href="dbb.php" class="sidebar-item active" onclick="closeSidebar()" title="หน้าหลัก">
                            <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                <polyline points="9 22 9 12 15 12 15 22"/>
                            </svg>
                            <span class="menu-text">หน้าหลัก</span>
                        </a>
                    </li>

                    <!-- 2. กลับหน้า Admin (แสดงเฉพาะแอดมิน) -->
                    <?php if ($isAdmin): ?>
                    <li>
                        <a href="<?php echo htmlspecialchars($adminHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-item" title="กลับหน้า Admin">
                            <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"/>
                                <rect x="14" y="3" width="7" height="7"/>
                                <rect x="14" y="14" width="7" height="7"/>
                                <rect x="3" y="14" width="7" height="7"/>
                            </svg>
                            <span class="menu-text">กลับหน้า Admin</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- 3. อัปเดตข้อมูล -->
                    <li>
                        <a href="javascript:void(0)" onclick="refreshReportData(); closeSidebar();" class="sidebar-item" title="อัปเดตข้อมูล">
                            <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                            </svg>
                            <span class="menu-text">อัปเดตข้อมูล</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 4. ส่วนล่างสุด: โปรไฟล์ผู้ใช้ และ ปุ่มออกจากระบบ -->
            <div class="sidebar-footer">
                <div class="user-profile-box">
                    <div class="user-avatar"><?php echo $isAdmin ? 'A' : 'H'; ?></div>
                    <span class="user-name"><?php echo $isAdmin ? 'admin' : 'hr'; ?></span>
                </div>
                <a href="javascript:void(0)" onclick="logoutFromReport()" class="sidebar-logout-btn" title="ออกจากระบบ">
                    <svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="menu-text">ออกจากระบบ</span>
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <?php if ($selected_rfid === ''): ?>
            <!-- VIEW 1: หน้า MAIN (รายการพนักงาน) -->
            <div id="view-main" class="view active">
                <div class="main-container">
                    <div class="report-main-header">
                        <div class="header-top realtime-clock">
                            <div class="datetime-row">
                                <span class="datetime-label">Date:</span>
                                <span class="datetime-value" id="currentDate">--/--/----</span>
                            </div>
                        
                            <div class="datetime-row">
                                <span class="datetime-label">Time:</span>
                                <span class="datetime-value" id="currentTime">--:--:-- --</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="main-title">Burnout Risk Report for<br>Employee Attendance</div>

                    <div class="emp-grid">
                        <?php if (count($users_data) > 0): ?>
                            <?php foreach ($users_data as $user): ?>
                                <?php 
                                    $latest_time = !empty($user['latest_time']) ? date("h:i A", strtotime($user['latest_time'])) : "ยังไม่มีการสแกน";
                                ?>
                                <a class="emp-card" href="dbb.php?rfid=<?php echo rawurlencode($user['rfid_uid']); ?>">
                                    <img src="https://via.placeholder.com/100" alt="Profile">
                                    <div class="emp-info">
                                        <div class="emp-name">Name: <?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="emp-dept">RFID : <?php echo htmlspecialchars($user['rfid_uid'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="arrival-tag">Arrival Time: <?php echo $latest_time; ?></div>
                                    </div>
                                    <div class="arrow-btn" aria-hidden="true">&gt;</div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-users-msg">ยังไม่มีผู้ลงทะเบียนในระบบ</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- VIEW 2: หน้า DASHBOARD WEEKLY -->
            <div id="view-weekly" class="view active">
                <div class="dashboard-content">
                    <div class="dash-header">
                        <div class="dash-profile">
                            <img src="https://via.placeholder.com/50" alt="Profile">
                            <h2><?php echo htmlspecialchars($profile_name); ?>'s Report</h2>
                        </div>
                    </div>

                    <div class="dash-subheader">
                        <div class="active-badge">Weekly (Last 5 Scans)</div>
                        <div class="switch-badge" onclick="navigateTo('view-monthly')">Monthly</div>
                    </div>

                    <!-- แสดงเวลาเรียลไทม์จาก JavaScript (รูปแบบ AM/PM) -->
                    <div class="report-updated-at">ข้อมูล ณ <span class="live-report-time">--/--/---- --:--:-- --</span></div>

                    <?php if ($w_total === 0): ?>
                        <div class="report-data-notice">
                            <strong>ยังไม่มีข้อมูลการสแกนของพนักงานคนนี้</strong>
                        </div>
                    <?php endif; ?>

                    <div class="risk-banner <?php echo ($w_final_score >= 50) ? 'risk-red' : 'risk-green'; ?>">
                        Overall Burnout Risk: <?php echo $w_final_score; ?> %
                    </div>

                    <div class="grid-dashboard">
                        <!-- คอลัมน์ที่ 1: สถิติ 3 ใบ -->
                        <div class="stat-group">
                            <div class="stat-block">
                                <h3>Total of inspections</h3>
                                <div class="stat-value"><?php echo $w_total; ?> Time</div>
                            </div>
                            <div class="stat-block">
                                <h3>Neutral <?php echo $w_neutral; ?> Time</h3>
                                <div class="stat-value"><?php echo $w_neutral_pct; ?>%</div>
                            </div>
                            <div class="stat-block">
                                <h3>Burnout <?php echo $w_burnout; ?> Time</h3>
                                <div class="stat-value"><?php echo $w_burnout_pct; ?>%</div>
                            </div>
                        </div>

                        <!-- คอลัมน์ที่ 2: Camera Burnout Detection -->
                        <div class="chart-group">
                            <div class="dash-card camera-card">
                                <div class="camera-top-content">
                                    <h3 class="card-title">Camera Burnout Detection</h3>
                                    <div class="heatmap-legend">
                                        <div class="legend-item"><div class="legend-color box-red"></div> Burnout</div>
                                        <div class="legend-item"><div class="legend-color box-green"></div> Neutral</div>
                                    </div>
                                    <div class="donut-container-cam"><canvas id="weeklyDonut1"></canvas></div>
                                </div>
                                <div class="camera-bottom-content">
                                    <hr class="card-divider">
                                    <h3 class="history-title">History (Last 5 Scans)</h3>
                                    <div id="weeklyHeatmap" class="heatmap-weekly"></div>
                                </div>
                            </div>
                        </div>

                        <!-- คอลัมน์ที่ 3: Questionnaire และ HRV -->
                        <div class="chart-group">
                            <div class="dash-card">
                                <h3 class="card-title">Questionnaire Report</h3>
                                <div class="heatmap-legend">
                                    <div class="legend-item"><div class="legend-color box-red"></div> High</div>
                                    <div class="legend-item"><div class="legend-color box-yellow"></div> Moderate</div>
                                    <div class="legend-item"><div class="legend-color box-green"></div> Low</div>
                                </div>
                                <div class="donut-container-q"><canvas id="weeklyDonut2"></canvas></div>
                            </div>
                            <div class="dash-card">
                                <h3>HRV History (Last 5 Scans)</h3>
                                <div class="chart-container-hrv"><canvas id="weeklyBar1"></canvas></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- VIEW 3: หน้า DASHBOARD MONTHLY -->
            <div id="view-monthly" class="view">
                <div class="dashboard-content">
                    <div class="dash-header">
                        <div class="dash-profile">
                            <img src="https://via.placeholder.com/50" alt="Profile">
                            <h2><?php echo htmlspecialchars($profile_name); ?>'s Report</h2>
                        </div>
                    </div>

                    <div class="dash-subheader">
                        <div class="switch-badge" onclick="navigateTo('view-weekly')">Weekly</div>
                        <div class="active-badge">Monthly (Last 30 Scans)</div>
                    </div>

                    <!-- แสดงเวลาเรียลไทม์จาก JavaScript (รูปแบบ AM/PM) -->
                    <div class="report-updated-at">ข้อมูล ณ <span class="live-report-time">--/--/---- --:--:-- --</span></div>

                    <?php if ($m_total === 0): ?>
                        <div class="report-data-notice">
                            <strong>ยังไม่มีข้อมูลการสแกนในช่วงที่แสดง</strong>
                        </div>
                    <?php endif; ?>

                    <div class="risk-banner <?php echo ($m_final_score >= 50) ? 'risk-red' : 'risk-green'; ?>">
                        Overall Burnout Risk: <?php echo $m_final_score; ?> %
                    </div>

                    <div class="grid-dashboard">
                        <!-- คอลัมน์ที่ 1: สถิติ 3 ใบ -->
                        <div class="stat-group">
                            <div class="stat-block">
                                <h3>Total of inspections</h3>
                                <div class="stat-value"><?php echo $m_total; ?> Time</div>
                            </div>
                            <div class="stat-block">
                                <h3>Neutral <?php echo $m_neutral; ?> Time</h3>
                                <div class="stat-value"><?php echo $m_neutral_pct; ?>%</div>
                            </div>
                            <div class="stat-block">
                                <h3>Burnout <?php echo $m_burnout; ?> Time</h3>
                                <div class="stat-value"><?php echo $m_burnout_pct; ?>%</div>
                            </div>
                        </div>

                        <!-- คอลัมน์ที่ 2: Camera Burnout Detection -->
                        <div class="chart-group">
                            <div class="dash-card camera-card">
                                <div class="camera-top-content">
                                    <h3 class="card-title">Camera Burnout Detection</h3>
                                    <div class="heatmap-legend">
                                        <div class="legend-item"><div class="legend-color box-red"></div> Burnout</div>
                                        <div class="legend-item"><div class="legend-color box-green"></div> Neutral</div>
                                    </div>
                                    <div class="donut-container-cam"><canvas id="monthlyDonut1"></canvas></div>
                                </div>
                                <div class="camera-bottom-content">
                                    <hr class="card-divider">
                                    <h3 class="history-title">History (Last 30 Scans)</h3>
                                    <div id="monthlyHeatmap" class="heatmap-monthly"></div>
                                </div>
                            </div>
                        </div>

                        <!-- คอลัมน์ที่ 3: Questionnaire และ HRV -->
                        <div class="chart-group">
                            <div class="dash-card">
                                <h3 class="card-title">Questionnaire Report</h3>
                                <div class="heatmap-legend">
                                    <div class="legend-item"><div class="legend-color box-red"></div> High</div>
                                    <div class="legend-item"><div class="legend-color box-yellow"></div> Moderate</div>
                                    <div class="legend-item"><div class="legend-color box-green"></div> Low</div>
                                </div>
                                <div class="donut-container-q"><canvas id="monthlyDonut2"></canvas></div>
                            </div>
                            <div class="dash-card">
                                <h3>HRV History (Last 30 Scans)</h3>
                                <div class="chart-container-hrv"><canvas id="monthlyLine1"></canvas></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (!sidebar || !backdrop) return;
            sidebar.classList.toggle('open');
            backdrop.classList.toggle('open');

            setTimeout(() => {
                if (window.Chart && Chart.instances) {
                    Object.values(Chart.instances).forEach(chart => chart.resize());
                }
            }, 300);
        }

        function closeSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (sidebar) sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('open');
        }

        async function logoutFromReport() {
            try {
                const response = await fetch('../api/auth.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const data = await response.json();
                if (!data.ok) throw new Error('Logout not confirmed');
                window.location.replace('../index.html?loggedout=1');
            } catch (error) {
                console.error('Logout request failed:', error);
                alert('ออกจากระบบไม่สำเร็จ กรุณาลองอีกครั้ง');
            }
        }

        const reportViewKey = 'bpReportView:' + window.location.search;
        function refreshReportData() {
            const activeView = document.querySelector('.view.active')?.id;
            if (activeView === 'view-weekly' || activeView === 'view-monthly') {
                try { sessionStorage.setItem(reportViewKey, activeView); } catch (e) {}
            }
            window.location.reload();
        }

        let autoRefreshBusy = false;
        setInterval(() => {
            if (document.hidden || autoRefreshBusy) return;
            autoRefreshBusy = true;
            refreshReportData();
        }, 60000);

        // ฟังก์ชันวันที่และเวลาเรียลไทม์ (รูปแบบ 12 ชั่วโมง AM/PM)
        function updateDateTime() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-GB'); // DD/MM/YYYY
            
            // กำหนดรูปแบบเวลาแบบ 12 ชั่วโมง (AM/PM)
            const time12Str = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            });

            // อัปเดตฝั่ง View 1 (หน้าหลัก)
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if (dateEl) dateEl.innerText = dateStr;
            if (timeEl) timeEl.innerText = time12Str;

            // อัปเดตฝั่ง View 2 & 3 (หน้า Dashboard)
            const reportTimeElements = document.querySelectorAll('.live-report-time');
            reportTimeElements.forEach(el => {
                el.innerText = `${dateStr} ${time12Str}`;
            });
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);

        let chartsInitialized = false;
        function navigateTo(viewId) {
            document.querySelectorAll('.view').forEach(view => view.classList.remove('active'));
            const targetView = document.getElementById(viewId);
            if (targetView) targetView.classList.add('active');
            try { sessionStorage.setItem(reportViewKey, viewId); } catch (e) {}
            window.scrollTo(0, 0);

            if (!chartsInitialized && (viewId === 'view-weekly' || viewId === 'view-monthly')) {
                if (window.Chart && window.ChartDataLabels) { initCharts(); chartsInitialized = true; }
            }
            setTimeout(() => { if (chartsInitialized && window.Chart && Chart.instances) { for (let id in Chart.instances) Chart.instances[id].resize(); } }, 50);
        }

        <?php if ($selected_rfid != ''): ?>
            window.onload = function() {
                if (window.Chart && window.ChartDataLabels) { initCharts(); chartsInitialized = true; }
                let savedView = '';
                try { savedView = sessionStorage.getItem(reportViewKey) || ''; } catch (e) {}
                if (savedView === 'view-monthly') {
                    document.getElementById('view-weekly')?.classList.remove('active');
                    document.getElementById('view-monthly')?.classList.add('active');
                } else {
                    try { sessionStorage.setItem(reportViewKey, 'view-weekly'); } catch (e) {}
                }
                setTimeout(() => {
                    if (window.Chart && Chart.instances) {
                        Object.values(Chart.instances).forEach(chart => chart.resize());
                    }
                }, 100);
            };

            function initCharts() {
                Chart.defaults.font.family = "'Inter', 'Noto Sans Thai', sans-serif";
                Chart.register(ChartDataLabels);
                Chart.defaults.set('plugins.datalabels', { display: false });

                const donutOptionsNoPercent = {
                    responsive: true, maintainAspectRatio: false, layout: { padding: 2 }, 
                    plugins: { 
                        legend: { display: false }, 
                        datalabels: { 
                            display: true, 
                            color: '#ffffff', 
                            font: { weight: 'bold', size: 10 }, 
                            formatter: (val) => val > 0 ? val : ''
                        } 
                    }
                };

                // WEEKLY CHARTS
                new Chart(document.getElementById('weeklyDonut1'), {
                    type: 'doughnut',
                    data: { labels: ['Burnout', 'Neutral'], datasets: [{ data: [<?php echo $w_burnout_pct; ?>, <?php echo$w_neutral_pct; ?>], backgroundColor: ['#dc2626', '#16a34a'], borderWidth: 0 }] },
                    options: { 
                        responsive: true, maintainAspectRatio: false, 
                        plugins: { 
                            legend: { display: false }, 
                            datalabels: { 
                                display: true, color: '#ffffff', font: { weight: 'bold', size: 11 }, 
                                formatter: (val) => val > 0 ? val + '%' : '' 
                            } 
                        } 
                    }
                });

                new Chart(document.getElementById('weeklyDonut2'), {
                    type: 'doughnut',
                    data: { labels: ['High', 'Moderate', 'Low'], datasets: [{ data: [<?php echo $w_high; ?>, <?php echo $w_mod; ?>, <?php echo$w_low; ?>], backgroundColor: ['#dc2626', '#eab308', '#16a34a'], borderWidth: 0 }] },
                    options: donutOptionsNoPercent
                });

                const wLabels = <?php echo json_encode($w_labels); ?>;
                const wHrvData = <?php echo json_encode($w_hrv_data); ?>;
                const wCamData = <?php echo json_encode($w_cam_data); ?>;
                
                new Chart(document.getElementById('weeklyBar1'), {
                    type: 'bar',
                    data: { labels: wLabels, datasets: [{ label: 'HRV', data: wHrvData, backgroundColor: ['#a855f7', '#8b5cf6', '#6366f1', '#3b82f6', '#1d4ed8'], borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 } } }, y: { beginAtZero: true, max: 100, ticks: { font: { size: 9 } } } } }
                });

                // MONTHLY CHARTS
                new Chart(document.getElementById('monthlyDonut1'), {
                    type: 'doughnut',
                    data: { labels: ['Burnout', 'Neutral'], datasets: [{ data: [<?php echo $m_burnout_pct; ?>, <?php echo$m_neutral_pct; ?>], backgroundColor: ['#dc2626', '#16a34a'], borderWidth: 0 }] },
                    options: { 
                        responsive: true, maintainAspectRatio: false, 
                        plugins: { 
                            legend: { display: false }, 
                            datalabels: { 
                                display: true, color: '#ffffff', font: { weight: 'bold', size: 11 }, 
                                formatter: (val) => val > 0 ? val + '%' : '' 
                            } 
                        } 
                    }
                });

                new Chart(document.getElementById('monthlyDonut2'), {
                    type: 'doughnut',
                    data: { labels: ['High', 'Moderate', 'Low'], datasets: [{ data: [<?php echo $m_high; ?>, <?php echo $m_mod; ?>, <?php echo$m_low; ?>], backgroundColor: ['#dc2626', '#eab308', '#16a34a'], borderWidth: 0 }] },
                    options: donutOptionsNoPercent
                });

                const mLabels = <?php echo json_encode($m_labels); ?>;
                const mHrvData = <?php echo json_encode($m_hrv_data); ?>;
                const mCamData = <?php echo json_encode($m_cam_data); ?>;

                new Chart(document.getElementById('monthlyLine1'), {
                    type: 'line',
                    data: { labels: mLabels, datasets: [{ label: 'HRV', data: mHrvData, borderColor: '#1d4ed8', backgroundColor: '#1d4ed8', tension: 0.3, pointRadius: 2.5 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { autoSkip: true, maxTicksLimit: 10, font: { size: 9 } } }, y: { beginAtZero: true, max: 100, ticks: { font: { size: 9 } } } } }
                });

                // HEATMAP RENDER FUNCTION
                function renderHeatmap(containerId, dataArray, labelsArray, maxBoxes) {
                    const container = document.getElementById(containerId);
                    if (!container) return;
                    container.innerHTML = '';

                    dataArray.forEach((val, index) => {
                        const box = document.createElement('div');
                        box.className = 'heatmap-box';
                        if (val === 100) {
                            box.classList.add('box-red');
                            box.title = `${labelsArray[index] || ''} : Burnout`;
                        } else {
                            box.classList.add('box-green');
                            box.title = `${labelsArray[index] || ''} : Neutral`;
                        }
                        container.appendChild(box);
                    });

                    const emptyCount = maxBoxes - dataArray.length;
                    for (let i = 0; i < emptyCount; i++) {
                        const box = document.createElement('div');
                        box.className = 'heatmap-box box-empty';
                        box.title = 'No Data';
                        container.appendChild(box);
                    }
                }

                renderHeatmap('weeklyHeatmap', wCamData, wLabels, 5);
                renderHeatmap('monthlyHeatmap', mCamData, mLabels, 30);
            }
        <?php endif; ?>
    </script>
</body>
</html>