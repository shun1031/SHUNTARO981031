<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();

$myEmpId = $_SESSION['employee_id'] ?? 0;
$period  = getActivePeriod($cid);

$sheetId = (int)($_GET['sheet_id'] ?? 0);
$sheet   = null;

if ($sheetId) {
    $sheet = getEvalSheet($sheetId, $cid);
    if (!$sheet || $sheet['evaluator_id'] != $myEmpId) {
        header('Location: eval_dashboard.php');
        exit;
    }
}

// 部下一覧
$subSheets = $period ? getSubordinateSheets($period['id'], $myEmpId) : [];

// POST処理（個別シート評価）
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sheet) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { die('不正なリクエストです'); }

    $action = $_POST['action'] ?? 'save';

    foreach ($_POST['perf'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_performance_scores SET primary_score = ?, primary_comment = ?, updated_at = NOW() WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }
    foreach ($_POST['act'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_action_scores SET primary_score = ?, primary_comment = ?, updated_at = NOW() WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }
    foreach ($_POST['comp'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_competency_scores SET primary_score = ?, primary_comment = ?, updated_at = NOW() WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }

    $db->prepare('UPDATE eval_sheets SET primary_comment = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$_POST['primary_comment'] ?? '', $sheetId]);

    if ($action === 'submit') {
        $db->prepare("UPDATE eval_sheets SET status = 'primary_submitted', primary_submitted_at = NOW() WHERE id = ?")->execute([$sheetId]);
        $success = '1次評価を提出しました。';
    } else {
        $success = '保存しました。';
    }

    $sheet = getEvalSheet($sheetId, $cid);
}

$csrf = getCsrfToken();

$pageTitle = '1次評価';
$extraCss  = ['assets/css/eval.css'];
$extraJs   = ['assets/js/eval.js'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="eval_dashboard.php">評価</a></li>
            <li class="breadcrumb-item active">1次評価</li>
        </ol>
    </nav>

    <div class="page-header">
        <div>
            <h1><i class="bi bi-person-check me-2"></i>1次評価</h1>
            <p><?= $period ? h($period['name']) : '' ?></p>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 部下一覧サイドバー -->
        <div class="col-lg-3">
            <div class="card">
                <div class="card-header"><i class="bi bi-people me-2"></i>評価対象者</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($subSheets as $s):
                        $sl = getEvalStatusLabel($s['status']); ?>
                    <a href="?sheet_id=<?= $s['id'] ?>" class="list-group-item list-group-item-action <?= $sheetId == $s['id'] ? 'active' : '' ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-medium"><?= h($s['employee_name']) ?></div>
                                <div class="small <?= $sheetId == $s['id'] ? '' : 'text-muted' ?>"><?= h($s['department'] ?? '') ?></div>
                            </div>
                            <span class="badge <?= $sl['class'] ?> badge-sm"><?= $sl['label'] ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($subSheets)): ?>
                    <div class="list-group-item text-muted">対象者がいません</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- メイン評価エリア -->
        <div class="col-lg-9">
            <?php if ($sheet): ?>
                <?php
                $perfItems = getPerformanceScores($sheetId);
                $actItems  = getActionScores($sheetId);
                $compItems = getCompetencyScores($sheetId);
                $isEditable = in_array($sheet['status'], ['self_submitted','primary_eval']);
                ?>
                <form method="POST" id="evalForm">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="save" id="formAction">

                    <h4 class="mb-3"><?= h($sheet['employee_name']) ?> さんの評価</h4>

                    <!-- 業績 -->
                    <div class="card mb-3">
                        <div class="card-header"><i class="bi bi-graph-up me-1"></i>業績評価</div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm eval-table">
                                    <thead><tr><th>項目</th><th>自己スコア</th><th>自己コメント</th><th style="width:100px">1次スコア</th><th>コメント</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($perfItems as $item): ?>
                                    <tr>
                                        <td><strong><?= h($item['item_name']) ?></strong></td>
                                        <td class="text-center"><?= $item['self_score'] !== null ? h($item['self_score']) : '-' ?></td>
                                        <td class="text-muted small"><?= h($item['self_comment'] ?? '') ?></td>
                                        <td><input type="number" step="0.1" min="0" max="100" name="perf[<?= $item['id'] ?>][score]" class="form-control form-control-sm eval-score-input" value="<?= h($item['primary_score'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                        <td><input type="text" name="perf[<?= $item['id'] ?>][comment]" class="form-control form-control-sm" value="<?= h($item['primary_comment'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 行動 -->
                    <div class="card mb-3">
                        <div class="card-header"><i class="bi bi-lightning me-1"></i>行動評価</div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm eval-table">
                                    <thead><tr><th>項目</th><th>自己スコア</th><th>自己コメント</th><th style="width:100px">1次スコア</th><th>コメント</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($actItems as $item): ?>
                                    <tr>
                                        <td><strong><?= h($item['item_name']) ?></strong></td>
                                        <td class="text-center"><?= $item['self_score'] !== null ? h($item['self_score']) : '-' ?></td>
                                        <td class="text-muted small"><?= h($item['self_comment'] ?? '') ?></td>
                                        <td><input type="number" step="0.1" min="0" max="100" name="act[<?= $item['id'] ?>][score]" class="form-control form-control-sm eval-score-input" value="<?= h($item['primary_score'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                        <td><input type="text" name="act[<?= $item['id'] ?>][comment]" class="form-control form-control-sm" value="<?= h($item['primary_comment'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- コンピテンシー -->
                    <div class="card mb-3">
                        <div class="card-header"><i class="bi bi-award me-1"></i>コンピテンシー評価</div>
                        <div class="card-body">
                            <?php foreach ($compItems as $item): ?>
                            <div class="border rounded p-3 mb-2">
                                <div class="d-flex justify-content-between">
                                    <strong><?= h($item['item_name']) ?></strong>
                                    <span class="text-muted">自己: <?= $item['self_score'] !== null ? $item['self_score'] : '-' ?></span>
                                </div>
                                <?php if ($item['self_comment']): ?><p class="text-muted small mb-2">自己コメント: <?= h($item['self_comment']) ?></p><?php endif; ?>
                                <div class="row g-2">
                                    <div class="col-auto">
                                        <select name="comp[<?= $item['id'] ?>][score]" class="form-select form-select-sm eval-score-input" <?= $isEditable ? '' : 'disabled' ?>>
                                            <option value="">--</option>
                                            <?php for ($lv = 1; $lv <= 5; $lv++): ?>
                                            <option value="<?= $lv ?>" <?= ($item['primary_score'] ?? '') == $lv ? 'selected' : '' ?>>Lv.<?= $lv ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col">
                                        <input type="text" name="comp[<?= $item['id'] ?>][comment]" class="form-control form-control-sm" value="<?= h($item['primary_comment'] ?? '') ?>" placeholder="評価コメント" <?= $isEditable ? '' : 'disabled' ?>>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 総合コメント -->
                    <div class="card mb-3">
                        <div class="card-header">総合コメント</div>
                        <div class="card-body">
                            <textarea name="primary_comment" class="form-control" rows="4" placeholder="総合的な評価コメント" <?= $isEditable ? '' : 'disabled' ?>><?= h($sheet['primary_comment'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <?php if ($isEditable): ?>
                    <div class="d-flex gap-2 mb-5">
                        <button type="submit" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save'">
                            <i class="bi bi-save me-1"></i>一時保存
                        </button>
                        <button type="submit" class="btn btn-warning" onclick="document.getElementById('formAction').value='submit'" data-confirm="1次評価を提出します。よろしいですか？">
                            <i class="bi bi-send me-1"></i>提出する
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>左の一覧から評価する社員を選択してください。</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
