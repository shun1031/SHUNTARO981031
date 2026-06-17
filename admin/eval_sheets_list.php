<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$cid  = getCompanyId();
$db   = getDB();
$csrf = getCsrfToken();

$periodId = (int)($_GET['period_id'] ?? 0);
if (!$periodId) { header('Location: eval_periods.php'); exit; }
$period = getEvalPeriod($periodId, $cid);
if (!$period) { header('Location: eval_periods.php'); exit; }

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'generate_sheets') {
        $count = generateEvalSheets($periodId, $cid);
        $_SESSION['flash'] = "{$count}件のシートを生成しました";
    } elseif ($action === 'assign_evaluator') {
        $sid = (int)$_POST['sheet_id'];
        $eid = (int)$_POST['evaluator_id'];
        $db->prepare('UPDATE eval_sheets SET evaluator_id = ? WHERE id = ? AND company_id = ?')->execute([$eid ?: null, $sid, $cid]);
        $_SESSION['flash'] = '評価者を更新しました';
    }
    header("Location: eval_sheets_list.php?period_id={$periodId}");
    exit;
}

$filterStatus = $_GET['status'] ?? '';
$sheets = getEvalSheetsForPeriod($periodId, $cid, $filterStatus ?: null);
$allSheets = $filterStatus ? getEvalSheetsForPeriod($periodId, $cid) : $sheets;
$employees = getAllEmployees(true, $cid);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// 集計
$statusCounts = [];
foreach ($allSheets as $s) { $statusCounts[$s['status']] = ($statusCounts[$s['status']] ?? 0) + 1; }

$psl = getEvalStatusLabel($period['status']);

$pageTitle = 'シート一覧 - ' . h($period['name']);
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価期間</a></li>
        <li class="breadcrumb-item active"><?= h($period['name']) ?> シート一覧</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="bi bi-file-earmark-text me-2"></i><?= h($period['name']) ?> <span class="badge <?= $psl['class'] ?>"><?= $psl['label'] ?></span></h4>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <button name="action" value="generate_sheets" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>シート一括生成</button>
    </form>
</div>

<!-- 集計カード -->
<div class="row mb-3">
    <div class="col"><div class="card text-center p-2"><div class="fs-4 fw-bold"><?= count($allSheets) ?></div><small class="text-muted">全体</small></div></div>
    <?php foreach (['draft'=>'下書き','self_submitted'=>'自己提出','primary_submitted'=>'1次提出','adjusted'=>'調整済','feedback_done'=>'FB完了'] as $sk=>$sl): ?>
    <div class="col"><div class="card text-center p-2"><div class="fs-4 fw-bold"><?= $statusCounts[$sk] ?? 0 ?></div><small class="text-muted"><?= $sl ?></small></div></div>
    <?php endforeach; ?>
</div>

<!-- フィルタタブ -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= !$filterStatus ? 'active' : '' ?>" href="?period_id=<?= $periodId ?>">全て</a></li>
    <?php foreach (['draft'=>'下書き','self_submitted'=>'自己提出済','primary_submitted'=>'1次提出済','adjusted'=>'調整済','feedback_done'=>'FB完了'] as $fk=>$fl): ?>
    <li class="nav-item"><a class="nav-link <?= $filterStatus === $fk ? 'active' : '' ?>" href="?period_id=<?= $periodId ?>&status=<?= $fk ?>"><?= $fl ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>社員番号</th><th>氏名</th><th>部署</th><th>評価者</th><th>ステータス</th>
                        <th class="text-end">自己</th><th class="text-end">1次</th><th class="text-end">最終</th><th class="text-center">等級</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sheets as $s):
                        $sl2 = getEvalStatusLabel($s['status']);
                        $total = $s['final_score_total'] ?? $s['primary_score_total'] ?? $s['self_score_total'];
                        $grade = $s['final_grade'] ?: ($total !== null ? getEvalGrade((float)$total) : '-');
                        $gc = $grade !== '-' ? getGradeBadgeClass($grade) : 'bg-light text-dark';
                    ?>
                    <tr>
                        <td><?= h($s['employee_number']) ?></td>
                        <td><strong><?= h($s['employee_name']) ?></strong></td>
                        <td><?= h($s['department'] ?? '') ?></td>
                        <td>
                            <?= h($s['evaluator_name'] ?? '未設定') ?>
                            <button class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#evalModal" data-sheet="<?= $s['id'] ?>" data-current="<?= $s['evaluator_id'] ?? '' ?>"><i class="bi bi-pencil"></i></button>
                        </td>
                        <td><span class="badge <?= $sl2['class'] ?>"><?= $sl2['label'] ?></span></td>
                        <td class="text-end"><?= $s['self_score_total'] !== null ? number_format((float)$s['self_score_total'],1) : '-' ?></td>
                        <td class="text-end"><?= $s['primary_score_total'] !== null ? number_format((float)$s['primary_score_total'],1) : '-' ?></td>
                        <td class="text-end fw-bold"><?= $total !== null ? number_format((float)$total,1) : '-' ?></td>
                        <td class="text-center"><span class="badge <?= $gc ?>"><?= h($grade) ?></span></td>
                        <td><a href="<?= BASE_PATH ?>/public/eval_feedback.php?sheet_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">詳細</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$sheets): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">シートがありません</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 評価者割当モーダル -->
<div class="modal fade" id="evalModal"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="assign_evaluator">
        <input type="hidden" name="sheet_id" id="evalSheetId">
        <div class="modal-header"><h5 class="modal-title">評価者割当</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <select name="evaluator_id" class="form-select" id="evalSelect">
                <option value="">未設定</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>"><?= h($e['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary">保存</button></div>
    </form>
</div></div></div>

<script>
document.getElementById('evalModal')?.addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('evalSheetId').value = btn.dataset.sheet;
    document.getElementById('evalSelect').value = btn.dataset.current || '';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
