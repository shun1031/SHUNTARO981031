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
    if ($action === 'save_adjustment') {
        $sheetIds = $_POST['sheet_ids'] ?? [];
        $scores   = $_POST['final_scores'] ?? [];
        $grades   = $_POST['final_grades'] ?? [];
        $comments = $_POST['adj_comments'] ?? [];
        foreach ($sheetIds as $i => $sid) {
            $sid = (int)$sid;
            $score = (float)($scores[$i] ?? 0);
            $grade = $grades[$i] ?? getEvalGrade($score);
            $comment = trim($comments[$i] ?? '');
            $db->prepare("UPDATE eval_sheets SET final_score_total = ?, final_grade = ?, adjustment_comment = ?, status = 'adjusted', adjusted_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ?")
               ->execute([$score, $grade, $comment, $sid, $cid]);
        }
        $_SESSION['flash'] = '調整を保存しました';
    }
    header("Location: eval_adjustment.php?period_id={$periodId}");
    exit;
}

$sheets = getEvalSheetsForPeriod($periodId, $cid);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// 等級別に分類
$gradeGroups = ['S'=>[],'A'=>[],'B'=>[],'C'=>[],'D'=>[]];
$gradeTargets = ['S'=>5,'A'=>20,'B'=>50,'C'=>20,'D'=>5];
foreach ($sheets as $s) {
    $score = $s['final_score_total'] ?? $s['primary_score_total'] ?? $s['self_score_total'] ?? 0;
    $grade = $s['final_grade'] ?: getEvalGrade((float)$score);
    $s['_score'] = (float)$score;
    $s['_grade'] = $grade;
    $gradeGroups[$grade][] = $s;
}

$pageTitle = '評価調整 - ' . h($period['name']);
$extraCss = ['eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価期間</a></li>
        <li class="breadcrumb-item"><a href="eval_sheets_list.php?period_id=<?= $periodId ?>"><?= h($period['name']) ?></a></li>
        <li class="breadcrumb-item active">評価調整</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<h4 class="mb-4"><i class="bi bi-sliders me-2"></i>評価調整会議 - <?= h($period['name']) ?></h4>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="save_adjustment">

    <div class="row">
        <?php $idx = 0; foreach ($gradeGroups as $grade => $members):
            $gc = getGradeBadgeClass($grade);
            $target = $gradeTargets[$grade];
            $count = count($members);
            $total = count($sheets);
            $pct = $total > 0 ? round($count / $total * 100) : 0;
        ?>
        <div class="col">
            <div class="card mb-3">
                <div class="card-header text-center">
                    <span class="badge <?= $gc ?> fs-5"><?= $grade ?></span>
                    <div class="small mt-1"><?= $count ?>人 (<?= $pct ?>%) <span class="text-muted">目標:<?= $target ?>%</span></div>
                    <?php if ($pct > $target + 10): ?>
                    <span class="badge bg-danger small">多め</span>
                    <?php elseif ($pct < $target - 10 && $count > 0): ?>
                    <span class="badge bg-info small">少なめ</span>
                    <?php endif; ?>
                </div>
                <div class="card-body grade-column">
                    <?php foreach ($members as $s): ?>
                    <div class="card mb-2">
                        <div class="card-body p-2">
                            <input type="hidden" name="sheet_ids[]" value="<?= $s['id'] ?>">
                            <div class="d-flex justify-content-between">
                                <strong class="small"><?= h($s['employee_name']) ?></strong>
                                <small class="text-muted"><?= h($s['department'] ?? '') ?></small>
                            </div>
                            <div class="d-flex gap-2 mt-1 align-items-center">
                                <input type="number" name="final_scores[]" class="form-control form-control-sm final-score-input" style="width:70px" step="0.1" min="0" max="100" value="<?= number_format($s['_score'], 1) ?>">
                                <select name="final_grades[]" class="form-select form-select-sm grade-select" style="width:60px">
                                    <?php foreach (['S','A','B','C','D'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $s['_grade']===$g?'selected':'' ?>><?= $g ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mt-1">
                                <input type="text" name="adj_comments[]" class="form-control form-control-sm" placeholder="コメント" value="<?= h($s['adjustment_comment'] ?? '') ?>">
                            </div>
                            <div class="text-muted small mt-1">
                                自己:<?= number_format((float)($s['self_score_total']??0),1) ?> / 1次:<?= number_format((float)($s['primary_score_total']??0),1) ?>
                            </div>
                        </div>
                    </div>
                    <?php $idx++; endforeach; ?>
                    <?php if (!$members): ?>
                    <p class="text-muted text-center small">なし</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mb-4">
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save me-1"></i>調整結果を保存</button>
    </div>
</form>

<script src="<?= BASE_PATH ?>/public/assets/js/eval.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
