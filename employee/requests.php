<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/login.php'); }

$myName = getMyEmployeeName();
$pageTitle = '申請';
$csrf = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $type    = trim($_POST['request_type'] ?? '');
    $date    = trim($_POST['target_date'] ?? '');
    $current = trim($_POST['current_value'] ?? '');
    $newVal  = trim($_POST['requested_value'] ?? '');
    $reason  = trim($_POST['reason'] ?? '');

    if (!in_array($type, ['shift_change', 'attendance_change'], true) || $date === '' || $newVal === '' || !$myName) {
        redirect(BASE_PATH . '/employee/requests.php?err=1');
    }
    createChangeRequest($cid, [
        'employee_name'   => $myName,
        'request_type'    => $type,
        'target_date'     => $date,
        'current_value'   => $current ?: null,
        'requested_value' => $newVal,
        'reason'          => $reason ?: null,
    ]);
    redirect(BASE_PATH . '/employee/requests.php?msg=sent');
}

$myRequests = $myName ? getChangeRequests($cid, $myName) : [];

// 直近の自分のシフトを date => [scheduled_time, checkin_time] のマップで渡す（JSで現在値表示に使用）
$year = (int)date('Y'); $month = (int)date('n');
$recentShifts = $myName ? getShifts($cid, $year, $month, $myName) : [];
$nextMonth = $month == 12 ? 1 : $month + 1;
$nextYear  = $month == 12 ? $year + 1 : $year;
$recentShifts = array_merge($recentShifts, $myName ? getShifts($cid, $nextYear, $nextMonth, $myName) : []);
$shiftMap = [];
foreach ($recentShifts as $s) {
    $shiftMap[$s['shift_date']] = ['scheduled_time' => $s['scheduled_time'], 'checkin_time' => $s['checkin_time']];
}

$statusLabel = ['pending' => '承認待ち', 'approved' => '承認済み', 'rejected' => '却下'];
$statusBadge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$typeLabel = ['shift_change' => 'シフト変更', 'attendance_change' => '出退勤時間変更'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-pencil-square me-2"></i>申請</h1>
        <p>シフト変更・出退勤時間変更を申請できます</p>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible">申請を送信しました。管理者の承認をお待ちください。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
    <div class="alert alert-danger alert-dismissible">必須項目を入力してください。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 申請フォーム -->
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2" style="color:var(--a600)"></i>新規申請</div>
                <div class="card-body">
                    <form method="post" id="reqForm">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">申請種別 <span class="text-danger">*</span></label>
                            <select name="request_type" id="rq_type" class="form-select" required>
                                <option value="">-- 選択 --</option>
                                <option value="shift_change">シフト変更</option>
                                <option value="attendance_change">出退勤時間変更</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">対象日 <span class="text-danger">*</span></label>
                            <input type="date" name="target_date" id="rq_date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">現在の登録内容</label>
                            <input type="text" name="current_value" id="rq_current" class="form-control" placeholder="自動表示されます（手動編集も可）">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">変更後の希望 <span class="text-danger">*</span></label>
                            <input type="text" name="requested_value" id="rq_new" class="form-control" maxlength="10" placeholder="例: 10:00" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">理由</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="任意"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-send me-1"></i>申請する</button>
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
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>申請日時</th><th>種別</th><th>対象日</th><th>変更内容</th><th>状態</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myRequests as $r): ?>
                                <tr>
                                    <td class="small text-muted"><?= date('n/j H:i', strtotime($r['created_at'])) ?></td>
                                    <td><?= h($typeLabel[$r['request_type']] ?? $r['request_type']) ?></td>
                                    <td><?= date('n/j', strtotime($r['target_date'])) ?></td>
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
const shiftMap = <?= json_encode($shiftMap, JSON_UNESCAPED_UNICODE) ?>;
function updateCurrentValue() {
    const date = document.getElementById('rq_date').value;
    const type = document.getElementById('rq_type').value;
    if (!date || !type || !shiftMap[date]) { return; }
    const s = shiftMap[date];
    document.getElementById('rq_current').value = type === 'shift_change' ? (s.scheduled_time || '') : (s.checkin_time || '');
}
document.getElementById('rq_date').addEventListener('change', updateCurrentValue);
document.getElementById('rq_type').addEventListener('change', updateCurrentValue);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
