<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getMyEmployeeName();
$pageTitle = 'シフト提出';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$csrf = getCsrfToken();

$startOpts = ['09:45', '10:00', '11:00', '12:00'];
$endOpts   = ['18:45', '18:00', '19:00', '20:00', '21:00'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    if (!$myName) {
        redirect(BASE_PATH . '/employee/shift.php?year='.$year.'&month='.$month.'&err=1');
    }
    $daysInMonthPost = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $location   = trim($_POST['location'] ?? '');   // 月全体共通の勤務地（1回入力）
    $startTimes = $_POST['start_time'] ?? [];
    $endTimes   = $_POST['end_time'] ?? [];
    $isDayOff   = $_POST['is_day_off'] ?? [];
    for ($d = 1; $d <= $daysInMonthPost; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $start   = trim($startTimes[$dateStr] ?? '');
        $end     = trim($endTimes[$dateStr] ?? '');
        $dayOff  = isset($isDayOff[$dateStr]) ? 1 : 0;
        if (!$start && !$end && !$dayOff) continue;
        saveShift($cid, [
            'employee_name' => $myName,
            'shift_date'    => $dateStr,
            'start_time'    => $start ?: null,
            'end_time'      => $end ?: null,
            'is_day_off'    => $dayOff,
            'location'      => $location ?: null,
        ]);
    }
    redirect(BASE_PATH . '/employee/shift.php?year='.$year.'&month='.$month.'&msg=saved');
}

$shifts = $myName ? getShifts($cid, $year, $month, $myName) : [];
$shiftsByDate = [];
$defaultLocation = '';
foreach ($shifts as $s) {
    $shiftsByDate[$s['shift_date']] = $s;
    if ($defaultLocation === '' && !empty($s['location'])) {
        $defaultLocation = $s['location'];
    }
}
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$dowLabels = ['日','月','火','水','木','金','土'];
$today = date('Y-m-d');

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-calendar3 me-2"></i>シフト提出</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php
                $prevM = $month - 1; $prevY = $year; if ($prevM < 1) { $prevM = 12; $prevY--; }
                $nextM = $month + 1; $nextY = $year; if ($nextM > 12) { $nextM = 1;  $nextY++; }
                ?>
                <div class="d-flex align-items-center gap-1">
                    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                    <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
                </div>
                <button class="btn btn-success" onclick="openShiftModal()"><i class="bi bi-plus-lg me-1"></i>シフトを登録</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible">保存しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible">保存に失敗しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- シフト一覧（表示のみ） -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:140px">日付</th>
                            <th>予定時間</th>
                            <th>勤務地</th>
                            <th style="width:80px" class="text-center">出退勤</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $dow = (int)date('w', mktime(0,0,0,$month,$d,$year));
                            $s = $shiftsByDate[$dateStr] ?? null;
                            if (!$s && $dateStr < $today) continue;
                        ?>
                        <tr class="<?= $dateStr === $today ? 'table-success' : '' ?>">
                            <td class="fw-medium" style="white-space:nowrap">
                                <?= $month ?>/<?= $d ?>
                                (<span class="<?= $dow === 0 ? 'text-danger' : ($dow === 6 ? 'text-primary' : '') ?>"><?= $dowLabels[$dow] ?></span>)
                                <?php if ($dateStr === $today): ?><span class="badge bg-success ms-1">本日</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($s['is_day_off'])): ?>
                                <span class="badge bg-secondary">休み</span>
                                <?php elseif (!empty($s['start_time']) || !empty($s['end_time'])): ?>
                                <?= h($s['start_time'] ?? '') ?><?= !empty($s['end_time']) ? '~'.h($s['end_time']) : '' ?>
                                <?php else: ?>
                                <span class="text-muted small">未登録</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= !empty($s['is_day_off']) ? '' : h($s['location'] ?? '') ?></td>
                            <td class="text-center">
                                <?php $sd = getShiftStatusDisplay($s, $dateStr, $today); ?>
                                <?php if ($sd['badge']): ?>
                                <span class="badge bg-<?= $sd['color'] ?>"><?= h($sd['badge']) ?></span>
                                <?php elseif ($sd['text']): ?>
                                <span class="text-danger small fw-semibold"><?= h($sd['text']) ?></span>
                                <?php else: ?>
                                <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- シフト登録モーダル（月一括登録） -->
<div class="modal fade" id="shiftModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>シフト登録（<?= $year ?>年<?= $month ?>月）</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height:75vh;overflow-y:auto">

                    <!-- 勤務地：月全体で1回のみ入力 -->
                    <div class="mb-4 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
                        <label class="form-label fw-semibold">勤務地（月全体で1回入力）<span class="text-danger ms-1">*</span></label>
                        <input type="text" name="location" id="sm_location" class="form-control" maxlength="100"
                            placeholder="例：○○店" value="<?= h($defaultLocation) ?>">
                        <div class="form-text">店舗名を正式名称で記入してください。入力した勤務地が<?= $month ?>月全日付に適用されます。</div>
                        <p id="sm_locationError" class="text-danger mb-0 mt-1" style="font-size:.8rem;display:none">稼働店舗を入力してください</p>
                    </div>

                    <!-- 日付ごとのシフト入力 -->
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width:110px">日付</th>
                                <th>出勤時間</th>
                                <th>退勤時間</th>
                                <th style="width:50px" class="text-center">休み</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                $dateStr     = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $dow         = (int)date('w', mktime(0,0,0,$month,$d,$year));
                                $s           = $shiftsByDate[$dateStr] ?? null;
                                $isToday     = ($dateStr === $today);
                                $isWeekend   = ($dow === 0 || $dow === 6);
                                $dayOffVal   = !empty($s['is_day_off']);
                                $existStart  = $s['start_time'] ?? '';
                                $existEnd    = $s['end_time'] ?? '';
                                $startInOpts = in_array($existStart, $startOpts, true);
                                $endInOpts   = in_array($existEnd, $endOpts, true);
                                $showStartInp = ($existStart !== '' && !$startInOpts);
                                $showEndInp   = ($existEnd !== '' && !$endInOpts);
                            ?>
                            <tr class="<?= $isToday ? 'table-success' : ($isWeekend ? 'table-light' : '') ?>">
                                <td class="fw-medium" style="white-space:nowrap">
                                    <?= $month ?>/<?= $d ?>
                                    (<span class="<?= $dow === 0 ? 'text-danger' : ($dow === 6 ? 'text-primary' : '') ?>"><?= $dowLabels[$dow] ?></span>)
                                    <?php if ($isToday): ?><span class="badge bg-success ms-1" style="font-size:.55rem">本日</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 align-items-center">
                                        <select class="form-select form-select-sm time-sel flex-grow-1"
                                            data-inp="sm_s<?= $d ?>" <?= $dayOffVal ? 'disabled' : '' ?>>
                                            <option value="">--</option>
                                            <?php foreach ($startOpts as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $existStart === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__" <?= $showStartInp ? 'selected' : '' ?>>手入力</option>
                                        </select>
                                        <input type="text" name="start_time[<?= $dateStr ?>]" id="sm_s<?= $d ?>"
                                            value="<?= h($existStart) ?>"
                                            class="form-control form-control-sm day-time-inp"
                                            maxlength="10" placeholder="HH:MM"
                                            style="width:68px;<?= $showStartInp ? '' : 'display:none' ?>"
                                            <?= $dayOffVal ? 'disabled' : '' ?>>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 align-items-center">
                                        <select class="form-select form-select-sm time-sel flex-grow-1"
                                            data-inp="sm_e<?= $d ?>" <?= $dayOffVal ? 'disabled' : '' ?>>
                                            <option value="">--</option>
                                            <?php foreach ($endOpts as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $existEnd === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__" <?= $showEndInp ? 'selected' : '' ?>>手入力</option>
                                        </select>
                                        <input type="text" name="end_time[<?= $dateStr ?>]" id="sm_e<?= $d ?>"
                                            value="<?= h($existEnd) ?>"
                                            class="form-control form-control-sm day-time-inp"
                                            maxlength="10" placeholder="HH:MM"
                                            style="width:68px;<?= $showEndInp ? '' : 'display:none' ?>"
                                            <?= $dayOffVal ? 'disabled' : '' ?>>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" name="is_day_off[<?= $dateStr ?>]" value="1"
                                        class="form-check-input day-off-chk"
                                        <?= $dayOffVal ? 'checked' : '' ?>>
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>まとめて保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openShiftModal() {
    new bootstrap.Modal(document.getElementById('shiftModal')).show();
}

// 稼働店舗バリデーション
document.querySelector('#shiftModal form').addEventListener('submit', function(e) {
    var loc = document.getElementById('sm_location').value.trim();
    var err = document.getElementById('sm_locationError');
    if (!loc) {
        e.preventDefault();
        err.style.display = 'block';
        document.getElementById('sm_location').focus();
        return;
    }
    err.style.display = 'none';
});

// 選択式 → 隠しinputと同期
document.querySelectorAll('.time-sel').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var inp = document.getElementById(this.dataset.inp);
        if (this.value === '__other__') {
            inp.style.display = '';
            inp.value = '';
            inp.focus();
        } else {
            inp.style.display = 'none';
            inp.value = this.value; // '--' のときは空文字になる
        }
    });
});

// 休みチェック時: セレクトとテキスト入力を両方制御
document.querySelectorAll('.day-off-chk').forEach(function(chk) {
    chk.addEventListener('change', function() {
        var row = this.closest('tr');
        row.querySelectorAll('.time-sel').forEach(function(sel) {
            sel.disabled = chk.checked;
            if (chk.checked) sel.selectedIndex = 0;
        });
        row.querySelectorAll('.day-time-inp').forEach(function(inp) {
            inp.disabled = chk.checked;
            if (chk.checked) { inp.value = ''; inp.style.display = 'none'; }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
