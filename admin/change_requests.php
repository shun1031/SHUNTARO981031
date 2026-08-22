<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/admin/companies.php'); }

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$pageTitle = '各種申請';
$csrf = getCsrfToken();
$user = getCurrentUser();
$reviewerName = $user['display_name'] ?: 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $postYear  = (int)($_POST['year']  ?? $year);
    $postMonth = (int)($_POST['month'] ?? $month);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'approve' && $id) {
        $result = approveChangeRequest($id, $cid, $reviewerName);
        // 希望時間を時刻として読み取れずシフト時間を変更できなかった場合は、
        // 成功メッセージではなく警告を出す（反映漏れに気づけるようにするため）
        $msg = ($result['warning'] ?? null) === 'shift_time_unparsed' ? 'approved_no_time' : 'approved';
        redirect(BASE_PATH . '/admin/change_requests.php?year='.$postYear.'&month='.$postMonth.'&msg='.$msg);
    }
    if ($action === 'reject' && $id) {
        rejectChangeRequest($id, $cid, $reviewerName);
        redirect(BASE_PATH . '/admin/change_requests.php?year='.$postYear.'&month='.$postMonth.'&msg=rejected');
    }
}

$prevM = $month - 1; $prevY = $year; if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year; if ($nextM > 12) { $nextM = 1;  $nextY++; }

$requests     = getChangeRequests($cid, null, null, $year, $month);
$pendingCount = countPendingChangeRequests($cid);

$statusLabel = ['pending' => '承認待ち', 'approved' => '承認済み', 'rejected' => '却下'];
$statusBadge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$typeLabel = [
    'checkin_change'    => '出勤時間変更',
    'checkout_change'   => '退勤時間変更',
    'attendance_add'    => '出退勤打刻追加',
    'shift_change'      => 'シフト変更',
    'daily_report_edit' => '日報修正',
    'transport_edit'    => '交通費修正',
    'attendance_change' => '出退勤時間変更', // 旧型式
];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-inbox me-2"></i>各種申請</h1>
                <p id="crSubtitle"><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center gap-1">
                    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                    <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
                </div>
                <?php if ($pendingCount): ?>
                <span class="badge bg-warning text-dark"><?= $pendingCount ?>件 承認待ち</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // 種別ごとに色と文言を持つ。知らない値が来たときは何も出さない
    $flashMap = [
        'approved'         => ['success', '承認しました。データに反映されました。'],
        'approved_no_time' => ['warning', '承認しましたが、希望時間を時刻として読み取れなかったため、<strong>シフト時間は変更されていません</strong>。シフト管理画面で内容をご確認ください。'],
        'rejected'         => ['success', '却下しました。'],
    ];
    // ?msg[]=... のように配列で渡されても落ちないよう、文字列のときだけ引く
    $msgKey = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
    $flash  = $flashMap[$msgKey] ?? null;
    ?>
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash[0] ?> alert-dismissible"><?= $flash[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th style="white-space:nowrap">申請日時</th><th>社員名</th><th>種別</th><th>対象日</th><th>変更内容</th><th style="width:160px">理由</th><th>状態</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r):
                            // 承認してもシフト時間を反映できない申請。承認前に気づけるよう印を付ける
                            $needsFix = shiftChangeNeedsManualFix($r);
                        ?>
                        <tr>
                            <td class="small text-muted" style="white-space:nowrap"><?= date('Y/n/j H:i', strtotime($r['created_at'])) ?></td>
                            <td class="fw-medium"><?= h($r['employee_name']) ?></td>
                            <td><?= h($typeLabel[$r['request_type']] ?? $r['request_type']) ?></td>
                            <td><?= date('n/j', strtotime($r['target_date'])) ?></td>
                            <td class="small">
                                <?= h($r['current_value'] ?? '-') ?> → <span class="fw-semibold"><?= h($r['requested_value']) ?></span>
                                <?php if ($needsFix && $r['status'] === 'pending'): ?>
                                <div class="mt-1">
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem"
                                          title="時刻（HH:MM）として読み取れないため、承認してもシフト時間は変更されません">
                                        <i class="bi bi-exclamation-triangle me-1"></i>時刻を読み取れません
                                    </span>
                                </div>
                                <?php elseif ($needsFix && $r['status'] === 'approved'): ?>
                                <div class="mt-1">
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem"
                                          title="承認時にシフト時間を変更できていない可能性があります。その後手動で修正済みの場合もあります">
                                        <i class="bi bi-exclamation-triangle me-1"></i>反映されていない可能性
                                    </span>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted reason-cell"
                                style="max-width:160px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:help"
                                title="<?= h($r['reason'] ?? '') ?>"
                                data-reason="<?= h($r['reason'] ?? '') ?>"
                            ><?= h($r['reason'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $statusBadge[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></span>
                                <?php if ($r['status'] !== 'pending' && !empty($r['reviewed_by'])): ?>
                                <div class="small text-muted mt-1">
                                    <?= $r['status'] === 'approved' ? '承認者:' : '却下者:' ?><?= h($r['reviewed_by']) ?>
                                </div>
                                <?php if (!empty($r['reviewed_at'])): ?>
                                <div class="small text-muted"><?= date('Y/n/j H:i', strtotime($r['reviewed_at'])) ?></div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <div class="d-flex gap-1">
                                    <form method="post" class="js-approve"
                                          data-warn="<?= $needsFix ? '1' : '' ?>"
                                          data-value="<?= h($r['requested_value']) ?>">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="year" value="<?= $year ?>">
                                        <input type="hidden" name="month" value="<?= $month ?>">
                                        <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> 承認</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('却下しますか？')">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="year" value="<?= $year ?>">
                                        <input type="hidden" name="month" value="<?= $month ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> 却下</button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($requests)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">この月の申請はありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// 承認の確認ダイアログ。シフト時間を反映できない申請は、承認する前に警告する
document.querySelectorAll('form.js-approve').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var ok;
        if (form.dataset.warn) {
            ok = confirm(
                '希望時間「' + (form.dataset.value || '') + '」は時刻（HH:MM）として読み取れません。\n' +
                '承認してもシフト時間は変更されず、シフト管理画面で手動の対応が必要になります。\n\n' +
                '却下して社員に書き直してもらうこともできます。このまま承認しますか？'
            );
        } else {
            ok = confirm('承認しますか？データに反映されます。');
        }
        if (!ok) e.preventDefault();
    });
});

// モバイル: 申請理由セルをタップで全文表示
document.querySelectorAll('.reason-cell').forEach(function(td) {
    td.addEventListener('click', function() {
        var reason = this.dataset.reason;
        if (reason) alert('申請理由:\n' + reason);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
