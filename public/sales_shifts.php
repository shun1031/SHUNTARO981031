<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $lRow = getDB()->prepare("SELECT shift_year, shift_month FROM sales_shifts WHERE company_id = ? ORDER BY shift_year DESC, shift_month DESC LIMIT 1");
    $lRow->execute([$cid]);
    $latest = $lRow->fetch();
    if ($latest) { $year = (int)$latest['shift_year']; $month = (int)$latest['shift_month']; }
}

$csrf        = getCsrfToken();
$empFilter   = getEmployeeNameFilter();
$isAdminView = ($empFilter === null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_absent' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if (isAdmin()) {
        $aName = trim($_POST['employee_name'] ?? '');
        $aDate = trim($_POST['shift_date'] ?? '');
        if ($aName !== '' && $aDate !== '') {
            saveAttendanceStatus($cid, ['employee_name' => $aName, 'work_date' => $aDate, 'attendance_status' => '欠勤']);
        }
    }
    redirect(BASE_PATH . '/public/sales_shifts.php?year='.$year.'&month='.$month.'&emp='.urlencode($_POST['employee_name'] ?? '').'&msg=absent_set');
}

// 社員リスト（ドロップダウン用：シフト提出済み社員のみ）
$allEmployees = getShiftEmployees($cid, $year, $month, $isAdminView ? null : $empFilter);

// 選択中社員の決定
$selectedEmp = trim($_GET['emp'] ?? '');
if ($selectedEmp === '' || !in_array($selectedEmp, $allEmployees, true)) {
    $selectedEmp = !empty($allEmployees) ? $allEmployees[0] : '';
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$today       = date('Y-m-d');
$grid        = $selectedEmp ? getShiftGrid($cid, $year, $month, $selectedEmp)    : [];
$summary     = $selectedEmp ? getShiftSummary($cid, $year, $month, $selectedEmp) : [];
$empGrid     = $grid[$selectedEmp] ?? [];

$isAjax = ($_GET['ajax'] ?? '') === '1';

// ─── コンテンツ HTML を生成（AJAX・通常共通） ───
ob_start();
$counts = ['work'=>0,'late'=>0,'early'=>0,'absent'=>0];
if (!empty($selectedEmp)):
?>
<div class="card mb-3">
    <div class="card-header">
        <span class="fw-bold"><?= h($selectedEmp) ?></span>
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

                        $autoStatus = $s ? calcShiftStatus($s) : '';
                        if ($autoStatus === '欠勤' && ($s['attendance_status'] ?? '') !== '欠勤') {
                            $autoStatus = '';
                        }

                        $isFuture = ($dateStr > $today);

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
                            <?php if (!$isFuture && $autoStatus === '欠勤'): ?>
                            <span class="text-danger">欠勤</span>
                            <?php elseif ($checkin): ?>
                            <?= h($checkin) ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isFuture && $autoStatus === '欠勤'): ?>
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
                            <form method="post" class="d-inline ms-1" onsubmit="return confirm('<?= h($selectedEmp) ?> <?= $dateStr ?> を欠勤として確定しますか？');">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="mark_absent">
                                <input type="hidden" name="employee_name" value="<?= h($selectedEmp) ?>">
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
<?php else: ?>
<div class="card mb-3"><div class="card-body text-center text-muted py-4">シフトデータがありません</div></div>
<?php endif; ?>

<?php if (!empty($summary)): ?>
<div class="card mb-3">
    <div class="card-header"><strong>月間サマリー</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>社員名</th><th class="text-center">シフト数</th><th class="text-center">出勤数</th><th class="text-center">日報提出</th><th class="text-center">出勤率</th></tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $row): ?>
                <tr>
                    <td><?= h($row['employee_name']) ?></td>
                    <td class="text-center"><?= $row['total_shifts'] ?></td>
                    <td class="text-center"><?= $row['attended'] ?></td>
                    <td class="text-center"><?= $row['reported'] ?></td>
                    <td class="text-center"><?= $row['total_shifts'] > 0 ? round($row['attended'] / $row['total_shifts'] * 100) : 0 ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();

// AJAX モード: コンテンツのみ返して終了
if ($isAjax) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $contentHtml;
    exit;
}

// 通常モード: フルページ出力
$pageTitle = 'シフト管理';
$extraCss  = ['sales.css'];
$extraJs   = ['sales.js'];
require_once __DIR__ . '/../includes/header.php';

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1)  { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1;  $nextY++; }
$empParam = $selectedEmp ? '&emp='.urlencode($selectedEmp) : '';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <h1 class="mb-0"><i class="bi bi-calendar3 me-2"></i>シフト管理</h1>
                <?php if ($isAdminView && count($allEmployees) > 1): ?>
                <select id="empSelector" class="form-select form-select-sm" style="width:auto;min-width:160px">
                    <?php foreach ($allEmployees as $empName): ?>
                    <option value="<?= h($empName) ?>" <?= $empName === $selectedEmp ? 'selected' : '' ?>><?= h($empName) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif (!empty($selectedEmp)): ?>
                <span class="fw-semibold text-muted"><?= h($selectedEmp) ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-1" id="monthNav">
                <a href="?year=<?= $prevY ?>&month=<?= $prevM ?><?= $empParam ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextY ?>&month=<?= $nextM ?><?= $empParam ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'absent_set'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        欠勤として登録しました
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div id="shiftContent">
        <?= $contentHtml ?>
    </div>
</div>

<?php
$inlineJs = <<<'JS'
(function () {
    var sel = document.getElementById('empSelector');
    if (!sel) return;

    function updateMonthNavLinks(emp) {
        document.querySelectorAll('#monthNav a').forEach(function (a) {
            try {
                var u = new URL(a.href);
                u.searchParams.set('emp', emp);
                a.href = u.toString();
            } catch (e) {}
        });
    }

    function loadContent(emp) {
        var u = new URL(window.location.href);
        u.searchParams.set('emp', emp);
        u.searchParams.set('ajax', '1');
        fetch(u.toString())
            .then(function (r) { return r.text(); })
            .then(function (html) {
                document.getElementById('shiftContent').innerHTML = html;
                // ブラウザ履歴・月ナビを更新
                u.searchParams.delete('ajax');
                history.replaceState(null, '', u.toString());
                updateMonthNavLinks(emp);
            })
            .catch(function (e) { console.error('AJAX error', e); });
    }

    sel.addEventListener('change', function () {
        loadContent(this.value);
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
