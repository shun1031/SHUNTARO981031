<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('employee');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getEmployeeNameFilter();
$pageTitle = '出退勤報告';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$csrf = getCsrfToken();
$validStatuses = ['出勤', '欠勤', '早退', '遅刻'];
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $date       = trim($_POST['work_date'] ?? '');
    $status     = trim($_POST['attendance_status'] ?? '');
    $time       = trim($_POST['checkin_time'] ?? '');
    $checkoutT  = trim($_POST['checkout_time'] ?? '');
    $reportType = $_POST['report_type'] ?? 'checkin'; // checkin or checkout

    if ($date !== $today || !$myName) {
        redirect(BASE_PATH . '/employee/attendance.php?year='.$year.'&month='.$month.'&err=1');
    }

    if ($reportType === 'checkout') {
        // 退勤報告: checkout_time のみ更新（出勤ステータスは保持）
        $checkoutStatus = ($status === '早退') ? '早退' : null;
        saveAttendanceStatus($cid, [
            'employee_name' => $myName,
            'work_date'     => $date,
            'attendance_status' => $checkoutStatus,
            'checkout_time' => $checkoutT ?: null,
            'checkout_only' => ($checkoutStatus === null), // 早退以外はステータスを保持
        ]);
    } else {
        // 出勤報告
        if (!in_array($status, $validStatuses, true)) {
            redirect(BASE_PATH . '/employee/attendance.php?year='.$year.'&month='.$month.'&err=1');
        }
        saveAttendanceStatus($cid, [
            'employee_name'     => $myName,
            'work_date'         => $date,
            'attendance_status' => $status,
            'checkin_time'      => $time ?: null,
        ]);
    }
    redirect(BASE_PATH . '/employee/attendance.php?year='.date('Y').'&month='.date('n').'&msg=saved');
}

$shifts = $myName ? getShifts($cid, $year, $month, $myName) : [];
$shiftsByDate = [];
foreach ($shifts as $s) { $shiftsByDate[$s['shift_date']] = $s; }
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$dowLabels = ['日','月','火','水','木','金','土'];

$counts = $myName ? getAttendanceStatusCounts($cid, $myName, $year, $month) : ['present'=>0,'absent'=>0,'early_leave'=>0,'late'=>0];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-clock-history me-2"></i>出退勤報告</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <select onchange="location.href='?year='+this.value+'&month=<?= $month ?>'" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month='+this.value" class="form-select form-select-sm" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible">報告しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible">状態を指定してください（出退勤報告は本日分のみ受け付けています）。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- 出退勤報告フォーム（常時表示） -->
    <div class="row g-3 mb-4">
        <!-- 出勤報告 -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-bold" style="background:#f0fdf4;color:#065f46"><i class="bi bi-box-arrow-in-right me-1"></i>出勤報告</div>
                <div class="card-body">
                    <form method="post" id="attForm">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="report_type" value="checkin">
                        <input type="hidden" name="work_date" value="<?= h($today) ?>">
                        <input type="hidden" name="attendance_status" id="am_status">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">時刻</label>
                            <input type="text" name="checkin_time" id="am_time" class="form-control" maxlength="10" placeholder="例: 09:05">
                            <div class="form-text">状態ボタンを押すとその時点の時刻が自動入力されます</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <button type="button" class="btn btn-outline-success att-btn flex-fill" data-status="出勤"><i class="bi bi-box-arrow-in-right me-1"></i>出勤</button>
                            <button type="button" class="btn btn-outline-warning att-btn flex-fill" data-status="遅刻"><i class="bi bi-alarm me-1"></i>遅刻</button>
                            <button type="button" class="btn btn-outline-danger att-btn flex-fill" data-status="欠勤"><i class="bi bi-x-circle me-1"></i>欠勤</button>
                        </div>
                        <div id="am_statusErr" class="text-danger small mb-2" style="display:none">状態（出勤／遅刻／欠勤）を選択してください</div>
                        <div class="text-end"><button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i>報告する</button></div>
                    </form>
                </div>
            </div>
        </div>
        <!-- 退勤報告 -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-bold" style="background:#eff6ff;color:#1e40af"><i class="bi bi-box-arrow-right me-1"></i>退勤報告</div>
                <div class="card-body">
                    <form method="post" id="checkoutForm">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="report_type" value="checkout">
                        <input type="hidden" name="work_date" value="<?= h($today) ?>">
                        <input type="hidden" name="attendance_status" id="co_status" value="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">退勤時刻</label>
                            <input type="text" name="checkout_time" id="co_time" class="form-control" maxlength="10" placeholder="例: 18:00">
                            <div class="form-text">ボタンを押すとその時点の時刻が自動入力されます</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            <button type="button" class="btn btn-outline-primary co-btn flex-fill" data-status=""><i class="bi bi-box-arrow-right me-1"></i>退勤</button>
                            <button type="button" class="btn btn-outline-orange co-btn flex-fill" data-status="早退" style="border-color:#f97316;color:#f97316"><i class="bi bi-box-arrow-left me-1"></i>早退</button>
                        </div>
                        <div class="text-end"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle me-1"></i>報告する</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 今月の集計 -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-number" style="color:#059669"><?= $counts['present'] ?></div><div class="stat-label">出勤日数</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-number" style="color:#ef4444"><?= $counts['absent'] ?></div><div class="stat-label">欠勤数</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-number" style="color:#f97316"><?= $counts['early_leave'] ?></div><div class="stat-label">早退数</div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card"><div class="stat-number" style="color:#eab308"><?= $counts['late'] ?></div><div class="stat-label">遅刻数</div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th style="width:140px">日付</th><th>シフト予定</th><th>状態</th><th>出勤</th><th>退勤</th></tr>
                    </thead>
                    <tbody>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $dow = (int)date('w', mktime(0,0,0,$month,$d,$year));
                            $s = $shiftsByDate[$dateStr] ?? null;
                            if (!$s && $dateStr < $today) continue; // 過去の未登録日は省略
                            if (!$s && $dateStr > $today) continue; // 予定のない未来日は省略
                        ?>
                        <tr class="<?= $dateStr === $today ? 'table-success' : '' ?>">
                            <td class="fw-medium" style="white-space:nowrap">
                                <?= $month ?>/<?= $d ?>
                                (<span class="<?= $dow === 0 ? 'text-danger' : ($dow === 6 ? 'text-primary' : '') ?>"><?= $dowLabels[$dow] ?></span>)
                                <?php if ($dateStr === $today): ?><span class="badge bg-success ms-1">本日</span><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= h($s['scheduled_time'] ?? '') ?: '-' ?></td>
                            <td>
                                <?php if (!empty($s['attendance_status'])): ?>
                                <span class="badge bg-<?= $s['attendance_status'] === '出勤' ? 'success' : ($s['attendance_status'] === '欠勤' ? 'danger' : 'warning') ?>"><?= h($s['attendance_status']) ?></span>
                                <?php elseif ($s && empty($s['is_day_off']) && (!empty($s['start_time']) || !empty($s['end_time']) || !empty($s['scheduled_time']))): ?>
                                <span class="text-danger small fw-semibold">報告未完了</span>
                                <?php else: ?>
                                <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= h($s['checkin_time'] ?? '') ?: '-' ?></td>
                            <td class="small text-muted"><?= h($s['checkout_time'] ?? '') ?: '-' ?></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.btn-outline-orange { color:#f97316; border-color:#f97316; }
.btn-outline-orange:hover, .btn-outline-orange.active { color:#fff; background-color:#f97316; border-color:#f97316; }
.att-btn.active { box-shadow: 0 0 0 3px rgba(0,0,0,.08) inset; }
</style>

<script>
function nowHHMM() {
    const now = new Date();
    return String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
}
function tickClock() {
    const el = document.getElementById('liveClock');
    if (el) el.textContent = nowHHMM();
}
setInterval(tickClock, 1000);
tickClock();

document.querySelectorAll('.att-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.att-btn').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        const status = this.dataset.status;
        document.getElementById('am_status').value = status;
        document.getElementById('am_statusErr').style.display = 'none';
        document.getElementById('am_time').value = status === '欠勤' ? '' : nowHHMM();
    });
});

document.getElementById('attForm').addEventListener('submit', function(e) {
    if (!document.getElementById('am_status').value) {
        e.preventDefault();
        document.getElementById('am_statusErr').style.display = 'block';
    }
});

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
