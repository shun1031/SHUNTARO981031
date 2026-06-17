<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('employee');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getEmployeeNameFilter();
$pageTitle = 'ダッシュボード';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$counts = $myName ? getAttendanceStatusCounts($cid, $myName, $year, $month) : ['present' => 0, 'absent' => 0, 'early_leave' => 0, 'late' => 0];

$today    = date('Y-m-d');
$weekEnd  = date('Y-m-d', strtotime('+6 days'));
$upcomingShifts = $myName ? getShiftsBetween($cid, $myName, $today, $weekEnd) : [];
$dowLabels = ['日','月','火','水','木','金','土'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-grid-1x2 me-2"></i>ダッシュボード</h1>
                <p><?= h($myName ?? '') ?>さん、お疲れ様です</p>
            </div>
            <select onchange="location.href='?year=<?= $year ?>&month='+this.value" class="form-select form-select-sm" style="width:100px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月の集計</option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <!-- KPIカード -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color:#059669"><?= $counts['present'] ?></div>
                <div class="stat-label"><i class="bi bi-check-circle me-1"></i>出勤日数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color:#ef4444"><?= $counts['absent'] ?></div>
                <div class="stat-label"><i class="bi bi-x-circle me-1"></i>欠勤数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color:#f97316"><?= $counts['early_leave'] ?></div>
                <div class="stat-label"><i class="bi bi-box-arrow-left me-1"></i>早退数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color:#eab308"><?= $counts['late'] ?></div>
                <div class="stat-label"><i class="bi bi-alarm me-1"></i>遅刻数</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 直近1週間のシフト -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar3 me-2" style="color:var(--a600)"></i>直近1週間のシフト</span>
                    <a href="<?= BASE_PATH ?>/employee/shift.php" class="btn btn-sm btn-outline-success">シフト提出 <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingShifts)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <p>直近1週間のシフトはまだ登録されていません</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>日付</th><th>予定時間</th><th>状態</th><th>勤務地</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingShifts as $s):
                                    $dow = (int)date('w', strtotime($s['shift_date']));
                                    $isToday = $s['shift_date'] === $today;
                                ?>
                                <tr class="<?= $isToday ? 'table-success' : '' ?>">
                                    <td>
                                        <?= date('n/j', strtotime($s['shift_date'])) ?>
                                        (<span class="<?= $dow === 0 ? 'text-danger' : ($dow === 6 ? 'text-primary' : '') ?>"><?= $dowLabels[$dow] ?></span>)
                                        <?php if ($isToday): ?><span class="badge bg-success ms-1">本日</span><?php endif; ?>
                                    </td>
                                    <td><?= h($s['scheduled_time'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($s['attendance_status']): ?>
                                        <span class="badge bg-<?= $s['attendance_status'] === '出勤' ? 'success' : ($s['attendance_status'] === '欠勤' ? 'danger' : 'warning') ?>"><?= h($s['attendance_status']) ?></span>
                                        <?php else: ?>
                                        <span class="text-muted small">未報告</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= h($s['location'] ?: '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- クイックアクセス -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-grid me-2" style="color:var(--a600)"></i>クイックアクセス</div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/employee/shift.php" class="quick-action">
                                <i class="bi bi-calendar3"></i>
                                <small>シフト提出</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/employee/attendance.php" class="quick-action">
                                <i class="bi bi-clock-history" style="color:var(--cyan)"></i>
                                <small>出退勤報告</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/public/sales_daily_report.php" class="quick-action">
                                <i class="bi bi-journal-check" style="color:var(--purple)"></i>
                                <small>日報提出</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/public/sales_transport.php" class="quick-action">
                                <i class="bi bi-car-front" style="color:var(--amber)"></i>
                                <small>交通費申請</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/employee/requests.php" class="quick-action">
                                <i class="bi bi-pencil-square"></i>
                                <small>申請</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
