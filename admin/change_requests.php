<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/admin/companies.php'); }

$pageTitle = '申請承認';
$csrf = getCsrfToken();
$user = getCurrentUser();
$reviewerName = $user['display_name'] ?: 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'approve' && $id) {
        approveChangeRequest($id, $cid, $reviewerName);
        redirect(BASE_PATH . '/admin/change_requests.php?msg=approved');
    }
    if ($action === 'reject' && $id) {
        rejectChangeRequest($id, $cid, $reviewerName);
        redirect(BASE_PATH . '/admin/change_requests.php?msg=rejected');
    }
}

$filter = $_GET['status'] ?? 'pending';
$requests = getChangeRequests($cid, null, $filter !== 'all' ? $filter : null);
$pendingCount = countPendingChangeRequests($cid);

$statusLabel = ['pending' => '承認待ち', 'approved' => '承認済み', 'rejected' => '却下'];
$statusBadge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$typeLabel = ['shift_change' => 'シフト変更', 'attendance_change' => '出退勤時間変更'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-inbox me-2"></i>申請承認</h1>
                <p>社員からのシフト変更・出退勤時間変更の申請を確認・承認できます</p>
            </div>
            <?php if ($pendingCount): ?>
            <span class="badge bg-warning text-dark fs-6"><?= $pendingCount ?>件 承認待ち</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible"><?= $_GET['msg'] === 'approved' ? '承認しました。データに反映されました。' : '却下しました。' ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body p-2 d-flex gap-2 flex-wrap">
            <a href="?status=pending" class="btn btn-sm <?= $filter==='pending' ? 'btn-warning' : 'btn-outline-warning' ?>">承認待ち</a>
            <a href="?status=approved" class="btn btn-sm <?= $filter==='approved' ? 'btn-success' : 'btn-outline-success' ?>">承認済み</a>
            <a href="?status=rejected" class="btn btn-sm <?= $filter==='rejected' ? 'btn-danger' : 'btn-outline-danger' ?>">却下</a>
            <a href="?status=all" class="btn btn-sm <?= $filter==='all' ? 'btn-primary' : 'btn-outline-primary' ?>">すべて</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>申請日時</th><th>社員名</th><th>種別</th><th>対象日</th><th>変更内容</th><th>理由</th><th>状態</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                        <tr>
                            <td class="small text-muted" style="white-space:nowrap"><?= date('Y/n/j H:i', strtotime($r['created_at'])) ?></td>
                            <td class="fw-medium"><?= h($r['employee_name']) ?></td>
                            <td><?= h($typeLabel[$r['request_type']] ?? $r['request_type']) ?></td>
                            <td><?= date('n/j', strtotime($r['target_date'])) ?></td>
                            <td class="small"><?= h($r['current_value'] ?? '-') ?> → <span class="fw-semibold"><?= h($r['requested_value']) ?></span></td>
                            <td class="small text-muted"><?= h($r['reason'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $statusBadge[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></span>
                                <?php if ($r['status'] !== 'pending'): ?>
                                <div class="small text-muted"><?= h($r['reviewed_by'] ?? '') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <div class="d-flex gap-1">
                                    <form method="post" onsubmit="return confirm('承認しますか？データに反映されます。')">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> 承認</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('却下しますか？')">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
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
                        <tr><td colspan="8" class="text-center text-muted py-4">申請がありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
