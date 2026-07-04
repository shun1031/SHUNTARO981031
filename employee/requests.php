<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getMyEmployeeName();
$pageTitle = '申請';
$csrf = getCsrfToken();

$allowedTypes = ['checkin_change','checkout_change','attendance_add','shift_change','daily_report_edit','transport_edit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $type    = trim($_POST['request_type'] ?? '');
    $date    = trim($_POST['target_date'] ?? '');
    $current = trim($_POST['current_value'] ?? '');
    $newVal  = trim($_POST['requested_value'] ?? '');
    $reason  = trim($_POST['reason'] ?? '');

    if (!in_array($type, $allowedTypes, true) || $date === '' || $newVal === '' || $reason === '' || !$myName) {
        redirect(BASE_PATH . '/employee/requests.php?err=1');
    }
    createChangeRequest($cid, [
        'employee_name'   => $myName,
        'request_type'    => $type,
        'target_date'     => $date,
        'current_value'   => $current ?: null,
        'requested_value' => $newVal,
        'reason'          => $reason,
    ]);
    redirect(BASE_PATH . '/employee/requests.php?msg=sent');
}

$myRequests = $myName ? getChangeRequests($cid, $myName) : [];

// ─── 自動入力用データ取得（前月・今月・翌月） ───
$year  = (int)date('Y');
$month = (int)date('n');
$periods = [
    [$month === 1  ? $year-1 : $year, $month === 1  ? 12 : $month-1],
    [$year, $month],
    [$month === 12 ? $year+1 : $year, $month === 12 ? 1  : $month+1],
];

$shiftMap     = []; // date  => {scheduled_time, checkin_time, checkout_time}
$reportMap    = []; // date  => '提出済み'
$transportMap = []; // 'Y-n' => total_amount

if ($myName) {
    foreach ($periods as [$ry, $rm]) {
        foreach (getShifts($cid, $ry, $rm, $myName) as $s) {
            $shiftMap[$s['shift_date']] = [
                'scheduled_time' => $s['scheduled_time'] ?? '',
                'checkin_time'   => $s['checkin_time']   ?? '',
                'checkout_time'  => $s['checkout_time']  ?? '',
            ];
        }
        foreach (getDailyReports($cid, $ry, $rm, $myName) as $r) {
            $reportMap[$r['work_date']] = '提出済み';
        }
        foreach (getTransportCosts($cid, $ry, $rm, $myName) as $t) {
            $transportMap[$t['target_year'].'-'.$t['target_month']] = (int)$t['total_amount'];
        }
    }
}

$typeLabel = [
    'checkin_change'    => '出勤時間変更',
    'checkout_change'   => '退勤時間変更',
    'attendance_add'    => '出退勤打刻追加',
    'shift_change'      => 'シフト変更',
    'daily_report_edit' => '日報修正',
    'transport_edit'    => '交通費修正',
    'attendance_change' => '出退勤時間変更', // 旧型式表示用
];
$statusLabel = ['pending' => '承認待ち', 'approved' => '承認済み', 'rejected' => '却下'];
$statusBadge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-pencil-square me-2"></i>申請</h1>
        <p>出退勤・シフト・日報・交通費の変更申請ができます</p>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible">申請を送信しました。管理者の承認をお待ちください。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible">必須項目をすべて入力してください。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 申請フォーム -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2" style="color:var(--a600)"></i>新規申請</div>
                <div class="card-body">
                    <form method="post" id="reqForm" novalidate>
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

                        <!-- 申請種別 -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">申請種別 <span class="text-danger">*</span></label>
                            <select name="request_type" id="rq_type" class="form-select" onchange="onTypeChange()">
                                <option value="">-- 選択 --</option>
                                <option value="checkin_change">出勤時間変更</option>
                                <option value="checkout_change">退勤時間変更</option>
                                <option value="attendance_add">出退勤打刻追加（打刻を忘れた日）</option>
                                <option value="shift_change">シフト変更（取消も可）</option>
                                <option value="transport_edit">交通費修正（取消も可）</option>
                            </select>
                        </div>

                        <!-- 対象日（日付指定） -->
                        <div class="mb-3" id="sec_date">
                            <label class="form-label fw-semibold">対象日 <span class="text-danger">*</span></label>
                            <input type="date" name="target_date" id="rq_date" class="form-control" onchange="onDateChange()">
                        </div>

                        <!-- 対象月（交通費用） -->
                        <div class="mb-3" id="sec_month" style="display:none">
                            <label class="form-label fw-semibold">対象月 <span class="text-danger">*</span></label>
                            <input type="month" id="rq_month" class="form-control" onchange="onMonthChange()">
                        </div>

                        <!-- 修正前の内容 -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">修正前の内容</label>
                            <input type="text" name="current_value" id="rq_current" class="form-control" placeholder="自動表示されます" readonly>
                        </div>

                        <!-- 修正後の希望（標準テキスト） -->
                        <div class="mb-3" id="sec_new_std">
                            <label class="form-label fw-semibold">修正後の希望 <span class="text-danger">*</span></label>
                            <input type="text" name="requested_value" id="rq_new" class="form-control" maxlength="100" placeholder="申請種別を選択してください">
                            <div id="rq_new_hint" class="form-text" style="display:none"></div>
                        </div>

                        <!-- 修正後の希望（出退勤打刻追加: 2フィールド） -->
                        <div class="mb-3" id="sec_new_add" style="display:none">
                            <label class="form-label fw-semibold">出勤・退勤時刻 <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col">
                                    <label class="form-label small text-muted">出勤時刻</label>
                                    <input type="text" id="rq_cin" class="form-control" placeholder="例: 09:45" maxlength="5">
                                </div>
                                <div class="col">
                                    <label class="form-label small text-muted">退勤時刻（任意）</label>
                                    <input type="text" id="rq_cout" class="form-control" placeholder="例: 18:45" maxlength="5">
                                </div>
                            </div>
                        </div>

                        <!-- 申請理由（必須） -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">申請理由 <span class="text-danger">*</span></label>
                            <textarea name="reason" id="rq_reason" class="form-control" rows="2" placeholder="申請理由を入力してください"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-1"></i>申請する</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 申請履歴 -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-list-ul me-2" style="color:var(--a600)"></i>申請履歴</div>
                <div class="card-body p-0">
                    <?php if (empty($myRequests)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>申請履歴はありません</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                            <thead class="table-light">
                                <tr><th>申請日時</th><th>種別</th><th>対象日</th><th>変更内容</th><th>状態</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myRequests as $r): ?>
                                <tr>
                                    <td class="text-muted" style="white-space:nowrap"><?= date('n/j H:i', strtotime($r['created_at'])) ?></td>
                                    <td><?= h($typeLabel[$r['request_type']] ?? $r['request_type']) ?></td>
                                    <td style="white-space:nowrap">
                                        <?php if ($r['request_type'] === 'transport_edit'): ?>
                                        <?= date('Y年n月', strtotime($r['target_date'])) ?>
                                        <?php else: ?>
                                        <?= date('n/j', strtotime($r['target_date'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= h($r['current_value'] ?? '-') ?> → <span class="fw-semibold"><?= h($r['requested_value']) ?></span>
                                    </td>
                                    <td><span class="badge bg-<?= $statusBadge[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const shiftMap     = <?= json_encode($shiftMap,     JSON_UNESCAPED_UNICODE) ?>;
const reportMap    = <?= json_encode($reportMap,    JSON_UNESCAPED_UNICODE) ?>;
const transportMap = <?= json_encode($transportMap, JSON_UNESCAPED_UNICODE) ?>;

// 種別ごとのプレースホルダー・ヒント
const typeConfig = {
    checkin_change:    { ph: '例: 09:45',   hint: '' },
    checkout_change:   { ph: '例: 18:45',   hint: '' },
    shift_change:      { ph: '例: 10:00〜19:00',  hint: '取消の場合は「取消」と入力してください' },
    daily_report_edit: { ph: '修正内容を具体的に記入', hint: '取消の場合は「取消」と入力してください' },
    transport_edit:    { ph: '修正内容を具体的に記入', hint: '取消の場合は「取消」と入力してください' },
};

function onTypeChange() {
    const type = document.getElementById('rq_type').value;
    const isTransport    = (type === 'transport_edit');
    const isAttendAdd    = (type === 'attendance_add');

    // 対象日 / 対象月 切り替え
    document.getElementById('sec_date').style.display  = isTransport ? 'none'  : 'block';
    document.getElementById('sec_month').style.display = isTransport ? 'block' : 'none';

    // 修正後の希望 切り替え
    document.getElementById('sec_new_std').style.display = isAttendAdd ? 'none'  : 'block';
    document.getElementById('sec_new_add').style.display = isAttendAdd ? 'block' : 'none';

    // プレースホルダー・ヒント更新
    const cfg = typeConfig[type] || { ph: '申請種別を選択してください', hint: '' };
    document.getElementById('rq_new').placeholder = cfg.ph;
    const hint = document.getElementById('rq_new_hint');
    if (cfg.hint) { hint.textContent = cfg.hint; hint.style.display = 'block'; }
    else          { hint.style.display = 'none'; }

    document.getElementById('rq_current').value = '';
    document.getElementById('rq_new').value     = '';
    updateCurrentValue();
}

function updateCurrentValue() {
    const type = document.getElementById('rq_type').value;
    const cur  = document.getElementById('rq_current');
    if (!type) { cur.value = ''; return; }

    if (type === 'transport_edit') {
        const month = document.getElementById('rq_month').value; // "YYYY-MM"
        if (!month) { cur.value = ''; return; }
        const [y, m] = month.split('-');
        const key = y + '-' + parseInt(m);
        cur.value = (transportMap[key] !== undefined) ? transportMap[key] + '円' : '未提出';
        return;
    }

    const date = document.getElementById('rq_date').value;
    if (!date) { cur.value = ''; return; }

    if (type === 'checkin_change') {
        cur.value = (shiftMap[date] && shiftMap[date].checkin_time) ? shiftMap[date].checkin_time : '';
    } else if (type === 'checkout_change') {
        cur.value = (shiftMap[date] && shiftMap[date].checkout_time) ? shiftMap[date].checkout_time : '';
    } else if (type === 'attendance_add') {
        cur.value = '（未打刻）';
    } else if (type === 'shift_change') {
        cur.value = (shiftMap[date] && shiftMap[date].scheduled_time) ? shiftMap[date].scheduled_time : '';
    } else if (type === 'daily_report_edit') {
        cur.value = reportMap[date] ? '提出済み' : '未提出';
    }
}

function onDateChange() { updateCurrentValue(); }
function onMonthChange() {
    const month = document.getElementById('rq_month').value;
    if (month) { document.getElementById('rq_date').value = month + '-01'; }
    updateCurrentValue();
}

// フォーム送信バリデーション
document.getElementById('reqForm').addEventListener('submit', function(e) {
    const type   = document.getElementById('rq_type').value;
    const reason = document.getElementById('rq_reason').value.trim();

    if (!type) { e.preventDefault(); alert('申請種別を選択してください'); return; }
    if (!reason) { e.preventDefault(); alert('申請理由を入力してください'); return; }

    if (type === 'transport_edit') {
        const month = document.getElementById('rq_month').value;
        if (!month) { e.preventDefault(); alert('対象月を選択してください'); return; }
        document.getElementById('rq_date').value = month + '-01';
    } else {
        if (!document.getElementById('rq_date').value) { e.preventDefault(); alert('対象日を選択してください'); return; }
    }

    if (type === 'attendance_add') {
        const cin  = document.getElementById('rq_cin').value.trim();
        const cout = document.getElementById('rq_cout').value.trim();
        if (!cin) { e.preventDefault(); alert('出勤時刻を入力してください'); return; }
        document.getElementById('rq_new').value = cin + (cout ? '/' + cout : '');
    } else {
        if (!document.getElementById('rq_new').value.trim()) { e.preventDefault(); alert('修正後の希望を入力してください'); return; }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
