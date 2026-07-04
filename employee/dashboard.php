<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getMyEmployeeName();
$pageTitle = 'ダッシュボード';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$counts = $myName ? getAttendanceStatusCounts($cid, $myName, $year, $month) : ['present' => 0, 'absent' => 0, 'early_leave' => 0, 'late' => 0];

$today    = date('Y-m-d');
$weekEnd  = date('Y-m-d', strtotime('+6 days'));
$upcomingShifts = $myName ? getShiftsBetween($cid, $myName, $today, $weekEnd) : [];
$dowLabels = ['日','月','火','水','木','金','土'];

$todayShift = $myName ? getShiftByDate($cid, $myName, $today) : false;
$showAttendanceAlert = $todayShift
    && empty($todayShift['is_day_off'])
    && (!empty($todayShift['start_time']) || !empty($todayShift['end_time']) || !empty($todayShift['scheduled_time']))
    && empty($todayShift['attendance_status']);

// ────────────────────────────────────────────────────────────────
// アラート判定（リアルタイム判定・DBに保存しない・Asia/Tokyo基準）
// ────────────────────────────────────────────────────────────────
$alerts = [];
if ($myName && $cid) {
    $tzJST    = new DateTimeZone('Asia/Tokyo');
    $nowJST   = new DateTime('now', $tzJST);
    $todayJST = $nowJST->format('Y-m-d');
    $nowHHMM  = $nowJST->format('H:i');
    $cyear    = (int)$nowJST->format('Y');
    $cmonth   = (int)$nowJST->format('n');
    $past2200 = ($nowHHMM >= '22:00');

    $pmonth = $cmonth - 1; $pyear = $cyear;
    if ($pmonth < 1) { $pmonth = 12; $pyear--; }
    $alertFrom = sprintf('%04d-%02d-01', $pyear, $pmonth);

    $db = getDB();

    // Q1: シフト（前月1日〜今日）① ② ③ 判定用
    $st = $db->prepare(
        "SELECT shift_date, start_time, checkin_time, checkout_time, attendance_status, is_day_off, scheduled_time
         FROM sales_shifts
         WHERE company_id=? AND employee_name=? AND shift_date BETWEEN ? AND ?
         ORDER BY shift_date DESC"
    );
    $st->execute([$cid, $myName, $alertFrom, $todayJST]);
    $aShifts = $st->fetchAll();

    // Q2: 日報提出済み日付一覧（前月1日〜今日）③ 判定用
    $st2 = $db->prepare(
        "SELECT work_date FROM sales_daily_reports WHERE company_id=? AND employee_name=? AND work_date BETWEEN ? AND ?"
    );
    $st2->execute([$cid, $myName, $alertFrom, $todayJST]);
    $rDates = array_column($st2->fetchAll(), 'work_date');

    // チェック範囲: 過去12ヶ月（当月含む）④ ⑤ 共通
    $ckStartM = $cmonth - 11; $ckStartY = $cyear;
    while ($ckStartM < 1) { $ckStartM += 12; $ckStartY--; }
    $ckStartYM = $ckStartY * 100 + $ckStartM;
    $ckEndYM   = $cyear * 100 + $cmonth;

    // Q3: シフト提出有無（過去12ヶ月・当月含む）④ 判定用
    $st3 = $db->prepare(
        "SELECT DISTINCT shift_year, shift_month FROM sales_shifts
         WHERE company_id=? AND employee_name=?
           AND (shift_year * 100 + shift_month) BETWEEN ? AND ?"
    );
    $st3->execute([$cid, $myName, $ckStartYM, $ckEndYM]);
    $shiftMonths = [];
    foreach ($st3->fetchAll() as $r) { $shiftMonths[$r['shift_year'].'-'.$r['shift_month']] = true; }

    // Q4: 交通費提出有無（過去12ヶ月・当月含む）⑤ 判定用
    $st4 = $db->prepare(
        "SELECT target_year, target_month FROM sales_transport_costs
         WHERE company_id=? AND employee_name=?
           AND (target_year * 100 + target_month) BETWEEN ? AND ?"
    );
    $st4->execute([$cid, $myName, $ckStartYM, $ckEndYM]);
    $transpMonths = [];
    foreach ($st4->fetchAll() as $r) { $transpMonths[$r['target_year'].'-'.$r['target_month']] = true; }

    // ① 出勤忘れ: シフト開始時刻+1分経過・出勤報告なし
    foreach ($aShifts as $s) {
        $dt = $s['shift_date'];
        if (!empty($s['is_day_off'])) continue;
        if (empty($s['start_time'])) continue;
        if (!empty($s['checkin_time'])) continue;
        if (($s['attendance_status'] ?? '') === '欠勤') continue;
        if ($dt === $todayJST) {
            $stDT = DateTime::createFromFormat('Y-m-d H:i', $dt.' '.$s['start_time'], $tzJST);
            if (!$stDT) continue;
            $stDT->modify('+1 minute');
            if ($nowJST < $stDT) continue;
        }
        $md = (int)date('n', strtotime($dt)).'/'.(int)date('j', strtotime($dt));
        $alerts[] = ['date' => $dt, 'type' => 1, 'text' => "{$md} 出勤しておりません。"];
    }

    // ② 退勤忘れ: 出勤済み・22:00過ぎ・退勤報告なし
    foreach ($aShifts as $s) {
        $dt = $s['shift_date'];
        if (!empty($s['is_day_off'])) continue;
        if (empty($s['checkin_time'])) continue;
        if (!empty($s['checkout_time'])) continue;
        if ($dt === $todayJST && !$past2200) continue;
        $md = (int)date('n', strtotime($dt)).'/'.(int)date('j', strtotime($dt));
        $alerts[] = ['date' => $dt, 'type' => 2, 'text' => "{$md} 退勤しておりません。"];
    }

    // ③ 日報未提出: シフトあり・欠勤でない・22:00過ぎ・日報なし
    foreach ($aShifts as $s) {
        $dt = $s['shift_date'];
        if (!empty($s['is_day_off'])) continue;
        if (empty($s['start_time']) && empty($s['scheduled_time'])) continue;
        if (($s['attendance_status'] ?? '') === '欠勤') continue;
        if (in_array($dt, $rDates, true)) continue;
        if ($dt === $todayJST && !$past2200) continue;
        $md = (int)date('n', strtotime($dt)).'/'.(int)date('j', strtotime($dt));
        $alerts[] = ['date' => $dt, 'type' => 3, 'text' => "{$md} 日報提出しておりません。"];
    }

    // ④ シフト未提出 ⑤ 交通費未提出（過去12ヶ月・当月含む・すべてチェック）
    $iterY = $ckStartY; $iterM = $ckStartM;
    while ($iterY * 100 + $iterM <= $ckEndYM) {
        $key = $iterY.'-'.$iterM;
        if (!isset($shiftMonths[$key])) {
            $alerts[] = ['date' => sprintf('%04d-%02d-01', $iterY, $iterM), 'type' => 4, 'text' => "{$iterM}月分シフト提出しておりません。"];
        }
        if (!isset($transpMonths[$key])) {
            $alerts[] = ['date' => sprintf('%04d-%02d-01', $iterY, $iterM), 'type' => 5, 'text' => "{$iterM}月分交通費提出しておりません。"];
        }
        $iterM++; if ($iterM > 12) { $iterM = 1; $iterY++; }
    }

    // 日付降順・種別昇順でソート（新しい順、同日は出勤>退勤>日報>シフト>交通費）
    usort($alerts, function($a, $b) {
        if ($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']);
        return $a['type'] <=> $b['type'];
    });
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-grid-1x2 me-2"></i>ダッシュボード</h1>
                <p><?= h($myName ?? '') ?>さん、お疲れ様です</p>
                <?php if ($showAttendanceAlert): ?>
                <div class="alert alert-danger py-2 px-3 mb-2 d-inline-block">
                    🔴 本日は出勤予定です。まだ出勤報告が完了していません。
                </div>
                <?php endif; ?>
            </div>
            <select onchange="location.href='?year=<?= $year ?>&month='+this.value" class="form-select form-select-sm" style="width:100px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月の集計</option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <!-- アラート一覧 -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between py-2" style="background:#fff5f5">
            <span class="fw-bold" style="color:#dc2626">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>アラート一覧
                <?php if (!empty($alerts)): ?>
                <span class="badge bg-danger ms-1" style="font-size:.75rem"><?= count($alerts) ?>件</span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-up text-muted"></i>
        </div>
        <div class="card-body p-0">
            <?php if (empty($alerts)): ?>
            <div class="px-3 py-2" style="min-height:44px;display:flex;align-items:center">アラートはありません</div>
            <?php else: ?>
            <?php foreach ($alerts as $i => $a): ?>
            <div class="px-3 py-2 text-danger fw-semibold<?= $i < count($alerts) - 1 ? ' border-bottom' : '' ?>" style="min-height:44px;display:flex;align-items:center"><?= h($a['text']) ?></div>
            <?php endforeach; ?>
            <?php endif; ?>
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
                <div class="stat-number" style="color:#eab308"><?= $counts['late'] ?></div>
                <div class="stat-label"><i class="bi bi-alarm me-1"></i>遅刻数</div>
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
                                        <?php $sd = getShiftStatusDisplay($s, $s['shift_date'], $today); ?>
                                        <?php if ($sd['badge']): ?>
                                        <span class="badge bg-<?= $sd['color'] ?>"><?= h($sd['badge']) ?></span>
                                        <?php elseif ($sd['text']): ?>
                                        <span class="text-danger small fw-semibold"><?= h($sd['text']) ?></span>
                                        <?php else: ?>
                                        <span class="text-muted small">-</span>
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
