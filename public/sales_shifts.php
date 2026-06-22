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

    <!-- シフトカレンダー -->
    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0" style="font-size:.75rem">
                    <thead class="table-light">
                        <tr>
                            <th class="sticky-col" style="min-width:100px;position:sticky;left:0;z-index:2;background:#f8f9fa">社員名</th>
                            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                $dow = date('w', mktime(0,0,0,$month,$d,$year));
                                $isWeekend = ($dow == 0 || $dow == 6);
                                $dowLabel = ['日','月','火','水','木','金','土'][$dow];
                            ?>
                            <th class="text-center <?= $isWeekend ? 'table-secondary' : '' ?>" style="min-width:50px">
                                <?= $d ?><br><small class="<?= $dow == 0 ? 'text-danger' : ($dow == 6 ? 'text-primary' : '') ?>"><?= $dowLabel ?></small>
                            </th>
                            <?php endfor; ?>
                            <th class="text-center" style="min-width:50px">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp):
                            $empGrid = $grid[$emp] ?? [];
                            $total = 0;
                        ?>
                        <tr>
                            <td class="fw-medium" style="position:sticky;left:0;z-index:1;background:#fff"><?= h($emp) ?></td>
                            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                $dow = date('w', mktime(0,0,0,$month,$d,$year));
                                $isWeekend = ($dow == 0 || $dow == 6);
                                $s = $empGrid[$d] ?? null;
                                $startTime = $s['start_time'] ?? '';
                                $endTime   = $s['end_time'] ?? '';
                                $dayOff    = !empty($s['is_day_off']);
                                $location  = $s['location'] ?? '';
                                $checkin   = $s['checkin_time'] ?? '';
                                $status    = $s['attendance_status'] ?? '';
                                $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $statusBadge = ['出勤' => 'success', '遅刻' => 'warning', '早退' => 'orange', '欠勤' => 'danger'][$status] ?? '';
                                $bgClass = '';
                                if ($dayOff) { $bgClass = 'table-secondary'; }
                                elseif ($status === '欠勤') { $bgClass = 'bg-danger-subtle'; $total++; }
                                elseif ($status) { $bgClass = 'bg-success-subtle'; $total++; }
                                elseif ($startTime || $endTime) { $bgClass = 'bg-warning-subtle'; $total++; }
                                elseif ($isWeekend) { $bgClass = 'table-secondary'; }
                            ?>
                            <td class="text-center <?= $bgClass ?>">
                                <?php if ($dayOff): ?>
                                <span class="badge bg-secondary" style="font-size:.55rem">休</span>
                                <?php else: ?>
                                <?php if ($startTime || $endTime): ?>
                                <div style="font-size:.7rem;line-height:1.2">
                                    <?= h($startTime) ?><?= $endTime ? ('~'.h($endTime)) : '' ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($location): ?>
                                <div class="text-muted" style="font-size:.6rem;overflow:hidden;white-space:nowrap;max-width:48px"><?= h($location) ?></div>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($status): ?>
                                <span class="badge bg-<?= $statusBadge ?>" style="font-size:.5rem;<?= $statusBadge === 'orange' ? 'background:#f97316' : '' ?>"><?= h($status) ?><?= $checkin ? ' '.h($checkin) : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endfor; ?>
                            <td class="text-center fw-bold"><?= $total ?></td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($employees)): ?>
                        <tr><td colspan="<?= $daysInMonth + 2 ?>" class="text-center text-muted py-4">シフトデータがありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
