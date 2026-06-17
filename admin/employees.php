<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = '社員管理';
$db  = getDB();
$cid = getCompanyId();

// 削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }
    $targetId = (int)($_POST['id'] ?? 0);
    if ($_POST['action'] === 'hide' && $targetId) {
        $sql = 'UPDATE employees SET is_active = 0 WHERE id = ?';
        $params = [$targetId];
        if ($cid) { $sql .= ' AND company_id = ?'; $params[] = $cid; }
        $db->prepare($sql)->execute($params);
        redirect(BASE_PATH . '/admin/employees.php?msg=' . urlencode('非表示にしました'));
    }
    if ($_POST['action'] === 'delete' && $targetId) {
        // 関連データも含めて完全削除
        $checkSql = 'SELECT id FROM employees WHERE id = ?';
        $checkParams = [$targetId];
        if ($cid) { $checkSql .= ' AND company_id = ?'; $checkParams[] = $cid; }
        $check = $db->prepare($checkSql);
        $check->execute($checkParams);
        if ($check->fetch()) {
            // 関連ユーザーアカウントも削除
            $db->prepare('DELETE FROM users WHERE employee_id = ?')->execute([$targetId]);
            // 社員を完全削除（FK CASCADEで関連データも削除される）
            $db->prepare('DELETE FROM employees WHERE id = ?')->execute([$targetId]);
            redirect(BASE_PATH . '/admin/employees.php?msg=' . urlencode('社員を削除しました'));
        }
    }
}

$filter = $_GET['filter'] ?? '';

$sql = 'SELECT e.*, t.name AS team_name,
               (SELECT COUNT(*) FROM strengths_finder WHERE employee_id = e.id) AS has_sf,
               (SELECT COUNT(*) FROM spi_results WHERE employee_id = e.id) AS has_spi
        FROM employees e
        LEFT JOIN team_members tm ON e.id = tm.employee_id
        LEFT JOIN teams t ON tm.team_id = t.id
        WHERE e.is_active = 1';
$params = [];
if ($cid) { $sql .= ' AND e.company_id = ?'; $params[] = $cid; }

if ($filter === 'no_sf') {
    $sql .= ' AND NOT EXISTS (SELECT 1 FROM strengths_finder WHERE employee_id = e.id)';
} elseif ($filter === 'no_spi') {
    $sql .= ' AND NOT EXISTS (SELECT 1 FROM spi_results WHERE employee_id = e.id)';
}
$sql .= ' ORDER BY e.employee_number, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-lines-fill me-2"></i>社員管理</h1>
                <p><?= count($employees) ?>名</p>
            </div>
            <div class="d-flex gap-2">
                <a href="employee_form.php" class="btn btn-primary">
                    <i class="bi bi-person-plus me-1"></i>新規登録
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success alert-dismissible">保存しました。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- フィルター -->
    <div class="card mb-3">
        <div class="card-body p-2 d-flex gap-2 flex-wrap">
            <a href="employees.php" class="btn btn-sm <?= !$filter ? 'btn-primary' : 'btn-outline-secondary' ?>">全員</a>
            <a href="employees.php?filter=no_sf" class="btn btn-sm <?= $filter==='no_sf' ? 'btn-warning' : 'btn-outline-warning' ?>">SF未登録</a>
            <a href="employees.php?filter=no_spi" class="btn btn-sm <?= $filter==='no_spi' ? 'btn-success' : 'btn-outline-success' ?>">SPI未登録</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>社員番号</th>
                            <th>名前</th>
                            <th>チーム</th>
                            <th>役職</th>
                            <th class="text-center">SF</th>
                            <th class="text-center">SPI</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td class="text-muted small"><?= h($emp['employee_number'] ?? '') ?></td>
                            <td>
                                <a href="<?= BASE_PATH ?>/public/employee.php?id=<?= $emp['id'] ?>" class="fw-medium text-decoration-none" target="_blank">
                                    <?= h($emp['name']) ?>
                                </a>
                            </td>
                            <td class="small"><?= h($emp['team_name'] ?? '-') ?></td>
                            <td class="small"><?= h($emp['job_title'] ?? '-') ?></td>
                            <td class="text-center">
                                <?php if ($emp['has_sf']): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <?php else: ?>
                                <i class="bi bi-circle text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($emp['has_spi']): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <?php else: ?>
                                <i class="bi bi-circle text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="employee_form.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="編集">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-danger" title="削除"
                                            onclick="confirmAction(<?= $emp['id'] ?>, '<?= h($emp['name']) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 削除確認モーダル -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>社員の削除</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong id="actionTargetName"></strong>さんをどうしますか？</p>
                <div class="alert alert-warning py-2 small mb-2">
                    <i class="bi bi-info-circle me-1"></i><strong>非表示</strong>: データは残りますが一覧に表示されなくなります（復元可能）
                </div>
                <div class="alert alert-danger py-2 small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i><strong>完全削除</strong>: 社員データ・ログインアカウント・診断結果がすべて削除されます（復元不可）
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <div class="d-flex gap-2">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="hide">
                        <input type="hidden" name="id" class="actionTargetId">
                        <button type="submit" class="btn btn-warning"><i class="bi bi-eye-slash me-1"></i>非表示</button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('本当に完全削除しますか？この操作は取り消せません。')">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" class="actionTargetId">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>完全削除</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmAction(id, name) {
    document.getElementById('actionTargetName').textContent = name;
    document.querySelectorAll('.actionTargetId').forEach(function(el) { el.value = id; });
    new bootstrap.Modal(document.getElementById('actionModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
