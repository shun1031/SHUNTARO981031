<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'シフト管理';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// If no year/month specified in URL, find latest month with data
if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $latestRow = getDB()->prepare("SELECT shift_year, shift_month FROM sales_shifts WHERE company_id = ? ORDER BY shift_year DESC, shift_month DESC LIMIT 1");
    $latestRow->execute([$cid]);
    $latest = $latestRow->fetch();
    if ($latest) {
        $year = (int)$latest['shift_year'];
        $month = (int)$latest['shift_month'];
    }
}

$csrf = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_absent' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if (isAdmin()) {
        $absentEmpName = trim($_POST['employee_name'] ?? '');
        $absentDate    = trim($_POST['shift_date'] ?? '');
        if ($absentEmpName !== '' && $absentDate !== '') {
            saveAttendanceStatus($cid, [
                'employee_name'     => $absentEmpName,
                'work_date'         => $absentDate,
                'attendance_status' => '欠勤',
            ]);
        }
    }
    redirect(BASE_PATH . '/public/sales_shifts.php?year='.$year.'&month='.$month.'&msg=absent_set');
}

$empFilter = getEmployeeNameFilter();
$grid = getShiftGrid($cid, $year, $month, $empFilter);
$employees = array_keys($grid);
if (empty($employees)) {
    $employees = getShiftEmployees($cid, $year, $month, $empFilter);
}
$summary = getShiftSummary($cid, $year, $month, $empFilter);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-calendar3 me-2"></i>シフト管理</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <?php
            $prevM = $month - 1; $prevY = $year;
            if ($prevM < 1) { $prevM = 12; $prevY--; }
            $nextM = $month + 1; $nextY = $year;
            if ($nextM > 12) { $nextM = 1; $nextY++; }
            ?>
            <div class="d-flex align-items-center gap-1">
                <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'absent_set'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        欠勤として登録しました
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- 詳細テーブル（従業員別・日付別） -->
    <?php
    $today = date('Y-m-d');
    foreach ($employees as $emp):
        $empGrid = $grid[$emp] ?? [];
        $counts = ['work'=>0,'late'=>0,'early'=>0,'absent'=>0];
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-bold"><?= h($emp) ?></span>
            <div id="kpi_<?= h($emp) ?>" class="d-flex gap-3" style="font-size:.78rem"></div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
                    <thead class="table-light">
                        <tr>
                            <th style="width:100px">日付</th>
                            <th style="width:60px">曜日</th>
                            <th style="width:130px">シフト時間</th>
                            <th style="width:90px">出勤時間</th>
                            <th style="width:90px">退勤時間</th>
                            <th style="width:80px">ステータス</th>
                            <th>備考</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dow     = (int)date('w', mktime(0,0,0,$month,$d,$year));
                            $dowLbl  = ['日','月','火','水','木','金','土'][$dow];
                            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $s = $empGrid[$d] ?? null;

                            $startTime = $s['start_time']    ?? '';
                            $endTime   = $s['end_time']      ?? '';
                            $dayOff    = !empty($s['is_day_off']);
                            $checkin   = $s['checkin_time']  ?? '';
                            $checkout  = $s['checkout_time'] ?? '';
                            $note      = $s['note']          ?? '';
                            $location  = $s['location']      ?? '';

                            // ステータス自動計算
                            $autoStatus = $s ? calcShiftStatus($s) : '';
                            // 自動欠勤判定を無効化: DBに attendance_status='欠勤' が明示設定された場合のみ欠勤扱い
                            if ($autoStatus === '欠勤' && ($s['attendance_status'] ?? '') !== '欠勤') {
                                $autoStatus = ''; // 未報告として扱う
                            }

                            // 未来の日付はステータスを表示しない
                            $isFuture = ($dateStr > $today);

                            // 表示設定
                            $rowClass = '';
                            $statusBadge = '';
                            $statusColor = '';
                            if ($dayOff || $autoStatus === '休み') {
                                $rowClass = 'table-secondary';
                                if (!$isFuture) { $statusBadge = '休日'; $statusColor = 'secondary'; }
                            } elseif (!$isFuture && $autoStatus === '欠勤') {
                                $rowClass = 'table-danger'; $statusBadge = '欠勤'; $statusColor = 'danger'; $counts['absent']++;
                            } elseif (!$isFuture && $autoStatus === '遅刻') {
                                $statusBadge = '遅刻'; $statusColor = 'warning'; $counts['late']++;
                            } elseif (!$isFuture && $autoStatus === '早退') {
                                $statusBadge = '早退'; $statusColor = 'warning'; $counts['early']++;
                            } elseif (!$isFuture && $autoStatus === '出勤') {
                                $statusBadge = '出勤'; $statusColor = 'success'; $counts['work']++;
                            } elseif ($dow === 0 || $dow === 6) {
                                $rowClass = 'table-light';
                            }

                            $isLateCheckin = ($checkin && $startTime && $checkin > $startTime);
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td><?= $dateStr ?></td>
                            <td class="<?= $dow===0?'text-danger':($dow===6?'text-primary':'') ?>"><?= $dowLbl ?></td>
                            <td>
                                <?php if ($dayOff): ?>
                                <span class="text-muted">休み</span>
                                <?php elseif ($startTime): ?>
                                <?= h($startTime) ?><?= $endTime ? ' 〜 '.h($endTime) : '' ?>
                                <?php elseif ($location): ?>
                                <span class="text-muted small"><?= h($location) ?></span>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td class="<?= $isLateCheckin ? 'text-danger fw-bold' : '' ?>">
                                <?php if ($autoStatus === '欠勤'): ?>
                                <span class="text-danger">欠勤</span>
                                <?php elseif ($checkin): ?>
                                <?= h($checkin) ?>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($autoStatus === '欠勤'): ?>
                                <span class="text-danger">欠勤</span>
                                <?php elseif ($checkout): ?>
                                <?= h($checkout) ?>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isFuture): ?>
                                <?php elseif ($statusBadge): ?>
                                <span class="badge bg-<?= $statusColor ?>"><?= $statusBadge ?></span>
                                <?php elseif ($startTime): ?>
                                <span class="text-muted small">未報告</span>
                                <?php if (isAdmin()): ?>
                                <form method="post" class="d-inline ms-1" onsubmit="return confirm('<?= h($emp) ?> <?= $dateStr ?> を欠勤として確定しますか？');">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="mark_absent">
                                    <input type="hidden" name="employee_name" value="<?= h($emp) ?>">
                                    <input type="hidden" name="shift_date" value="<?= h($dateStr) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.65rem">欠勤</button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= h($note) ?></td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex gap-3" style="font-size:.75rem;background:#f8f9fa">
            <span class="text-success"><i class="bi bi-check-circle me-1"></i>出勤 <?= $counts['work'] ?>日</span>
            <span class="text-warning"><i class="bi bi-alarm me-1"></i>遅刻 <?= $counts['late'] ?>回</span>
            <span class="text-warning"><i class="bi bi-box-arrow-left me-1"></i>早退 <?= $counts['early'] ?>回</span>
            <span class="text-danger"><i class="bi bi-x-circle me-1"></i>欠勤 <?= $counts['absent'] ?>日</span>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($employees)): ?>
    <div class="card"><div class="card-body text-center text-muted py-4">シフトデータがありません</div></div>
    <?php endif; ?>

    <!-- サマリー -->
    <?php if (!empty($summary)): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>月間サマリー</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>社員名</th><th class="text-center">シフト数</th><th class="text-center">出勤数</th><th class="text-center">日報提出</th><th class="text-center">出勤率</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $s): ?>
                    <tr>
                        <td><?= h($s['employee_name']) ?></td>
                        <td class="text-center"><?= $s['total_shifts'] ?></td>
                        <td class="text-center"><?= $s['attended'] ?></td>
                        <td class="text-center"><?= $s['reported'] ?></td>
                        <td class="text-center"><?= $s['total_shifts'] > 0 ? round($s['attended'] / $s['total_shifts'] * 100) : 0 ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <?php endif; ?>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
