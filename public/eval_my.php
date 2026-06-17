<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();

$sheetId = (int)($_GET['sheet_id'] ?? 0);
$sheet   = getEvalSheet($sheetId, $cid);
if (!$sheet) { header('Location: eval_dashboard.php'); exit; }

// 自分のシートか確認
$myEmpId = $_SESSION['employee_id'] ?? 0;
if ($sheet['employee_id'] != $myEmpId) { header('Location: eval_dashboard.php'); exit; }

$weights   = getAxisWeight($cid, $sheet['department_key'] ?? 'general') ?: ['weight_performance'=>40,'weight_action'=>40,'weight_competency'=>20];
$perfItems = getPerformanceScores($sheetId);
$actItems  = getActionScores($sheetId);
$compItems = getCompetencyScores($sheetId);

$error = '';
$success = '';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { die('不正なリクエストです'); }

    $action = $_POST['action'] ?? 'save';

    // 業績スコア保存
    foreach ($_POST['perf'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_performance_scores SET actual_value = ?, self_score = ?, self_comment = ? WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['actual'] ?? null, $vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }

    // 行動スコア保存
    foreach ($_POST['act'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_action_scores SET actual_value = ?, self_score = ?, self_comment = ? WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['actual'] ?? null, $vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }

    // コンピテンシースコア保存
    foreach ($_POST['comp'] ?? [] as $scoreId => $vals) {
        $db->prepare('UPDATE eval_competency_scores SET self_level = ?, self_comment = ? WHERE id = ? AND sheet_id = ?')
           ->execute([$vals['score'] ?? null, $vals['comment'] ?? '', (int)$scoreId, $sheetId]);
    }

    // 自由コメント
    $db->prepare('UPDATE eval_sheets SET self_comment = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$_POST['self_comment'] ?? '', $sheetId]);

    if ($action === 'submit') {
        $db->prepare("UPDATE eval_sheets SET status = 'self_submitted', self_submitted_at = NOW() WHERE id = ?")->execute([$sheetId]);
        $success = '自己評価を提出しました。';
    } else {
        $success = '保存しました。';
    }

    // 再取得
    $sheet    = getEvalSheet($sheetId, $cid);
    $perfItems = getPerformanceScores($sheetId);
    $actItems  = getActionScores($sheetId);
    $compItems = getCompetencyScores($sheetId);
}

$csrf = getCsrfToken();
$isEditable = in_array($sheet['status'], ['draft','open','self_eval']);

$pageTitle = '自己評価';
$extraCss  = ['assets/css/eval.css'];
$extraJs   = ['assets/js/eval.js'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="eval_dashboard.php">評価</a></li>
            <li class="breadcrumb-item active">自己評価</li>
        </ol>
    </nav>

    <div class="page-header">
        <div>
            <h1><i class="bi bi-pencil-square me-2"></i>自己評価</h1>
            <p><?= h($sheet['period_name']) ?> ─ <?= h($sheet['employee_name']) ?></p>
        </div>
        <?php $sl = getEvalStatusLabel($sheet['status']); ?>
        <span class="badge <?= $sl['class'] ?> fs-6"><?= $sl['label'] ?></span>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <form method="POST" id="evalForm" data-sheet-id="<?= $sheetId ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save" id="formAction">

        <!-- タブナビゲーション -->
        <ul class="nav nav-tabs mb-3" id="evalTabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-performance">
                <i class="bi bi-graph-up me-1"></i>業績 <span class="badge bg-secondary ms-1"><?= $weights['weight_performance'] ?>%</span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-action">
                <i class="bi bi-lightning me-1"></i>行動 <span class="badge bg-secondary ms-1"><?= $weights['weight_action'] ?>%</span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-competency">
                <i class="bi bi-award me-1"></i>コンピテンシー <span class="badge bg-secondary ms-1"><?= $weights['weight_competency'] ?>%</span></a></li>
        </ul>

        <div class="tab-content">
            <!-- 業績タブ -->
            <div class="tab-pane fade show active" id="tab-performance">
                <div class="card">
                    <div class="card-header">業績評価</div>
                    <div class="card-body">
                        <?php if (empty($perfItems)): ?>
                            <p class="text-muted">業績評価項目がありません。</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table eval-table">
                                <thead><tr><th>項目</th><th style="width:100px">実績値</th><th style="width:100px">スコア</th><th>コメント</th></tr></thead>
                                <tbody>
                                <?php foreach ($perfItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($item['item_name']) ?></strong>
                                        <?php if ($item['unit']): ?><span class="text-muted small">(<?= h($item['unit']) ?>)</span><?php endif; ?>
                                    </td>
                                    <td><input type="number" step="0.01" name="perf[<?= $item['id'] ?>][actual]" class="form-control form-control-sm eval-score-input" value="<?= h($item['actual_value'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    <td><input type="number" step="0.1" min="0" max="100" name="perf[<?= $item['id'] ?>][score]" class="form-control form-control-sm eval-score-input" value="<?= h($item['self_score'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    <td><input type="text" name="perf[<?= $item['id'] ?>][comment]" class="form-control form-control-sm" value="<?= h($item['self_comment'] ?? '') ?>" placeholder="補足コメント" <?= $isEditable ? '' : 'disabled' ?>></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 行動タブ -->
            <div class="tab-pane fade" id="tab-action">
                <div class="card">
                    <div class="card-header">行動評価</div>
                    <div class="card-body">
                        <?php if (empty($actItems)): ?>
                            <p class="text-muted">行動評価項目がありません。</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table eval-table">
                                <thead><tr><th>項目</th><th style="width:100px">実績</th><th style="width:100px">スコア</th><th>コメント</th></tr></thead>
                                <tbody>
                                <?php foreach ($actItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($item['item_name']) ?></strong>
                                        <?php if ($item['item_target']): ?><br><span class="text-muted small">目標: <?= h($item['item_target']) ?><?= $item['target_unit'] ? h($item['target_unit']) : '' ?></span><?php endif; ?>
                                    </td>
                                    <td><input type="number" step="0.01" name="act[<?= $item['id'] ?>][actual]" class="form-control form-control-sm eval-score-input" value="<?= h($item['actual_value'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    <td><input type="number" step="0.1" min="0" max="100" name="act[<?= $item['id'] ?>][score]" class="form-control form-control-sm eval-score-input" value="<?= h($item['self_score'] ?? '') ?>" <?= $isEditable ? '' : 'disabled' ?>></td>
                                    <td><input type="text" name="act[<?= $item['id'] ?>][comment]" class="form-control form-control-sm" value="<?= h($item['self_comment'] ?? '') ?>" placeholder="補足コメント" <?= $isEditable ? '' : 'disabled' ?>></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- コンピテンシータブ -->
            <div class="tab-pane fade" id="tab-competency">
                <div class="card">
                    <div class="card-header">コンピテンシー評価</div>
                    <div class="card-body">
                        <?php if (empty($compItems)): ?>
                            <p class="text-muted">コンピテンシー項目がありません。</p>
                        <?php else: ?>
                        <?php foreach ($compItems as $item): ?>
                        <div class="card mb-3 eval-comp-card">
                            <div class="card-body">
                                <h6><?= h($item['item_name']) ?></h6>
                                <?php if ($item['description']): ?><p class="text-muted small mb-2"><?= h($item['description']) ?></p><?php endif; ?>
                                <div class="row g-2 mb-2">
                                    <?php for ($lv = 1; $lv <= 5; $lv++):
                                        $desc = $item["level{$lv}_desc"] ?? ''; ?>
                                    <div class="col">
                                        <div class="eval-level-card text-center p-2 <?= ($item['self_level'] ?? 0) == $lv ? 'active' : '' ?>" data-score="<?= $lv ?>" data-target="comp[<?= $item['id'] ?>][score]">
                                            <div class="fw-bold">Lv.<?= $lv ?></div>
                                            <?php if ($desc): ?><div class="small text-muted"><?= h($desc) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="comp[<?= $item['id'] ?>][score]" value="<?= h($item['self_level'] ?? '') ?>" class="eval-score-input">
                                <textarea name="comp[<?= $item['id'] ?>][comment]" class="form-control form-control-sm mt-2" rows="2" placeholder="根拠・具体例を記入" <?= $isEditable ? '' : 'disabled' ?>><?= h($item['self_comment'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 総合コメント & ボタン -->
        <div class="card mt-3">
            <div class="card-header">総合コメント</div>
            <div class="card-body">
                <textarea name="self_comment" class="form-control" rows="4" placeholder="今期の振り返り、来期に向けた課題など" <?= $isEditable ? '' : 'disabled' ?>><?= h($sheet['self_comment'] ?? '') ?></textarea>
            </div>
        </div>

        <?php if ($isEditable): ?>
        <div class="d-flex gap-2 mt-3 mb-5">
            <button type="submit" class="btn btn-secondary" onclick="document.getElementById('formAction').value='save'">
                <i class="bi bi-save me-1"></i>一時保存
            </button>
            <button type="submit" class="btn btn-primary" onclick="document.getElementById('formAction').value='submit'" data-confirm="自己評価を提出します。提出後は修正できません。よろしいですか？">
                <i class="bi bi-send me-1"></i>提出する
            </button>
            <span class="text-muted small align-self-center ms-auto" id="autoSaveStatus"></span>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
