<?php
/**
 * 等級・号俸テーブル管理画面
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$cid  = getCompanyId();
$db   = getDB();
$csrf = getCsrfToken();

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_grade') {
        $rank  = trim($_POST['grade_rank'] ?? '');
        $label = trim($_POST['grade_label'] ?? '');
        $steps = (int)($_POST['steps'] ?? 5);
        $baseSalary = (int)($_POST['base_salary'] ?? 200000);
        $stepIncrement = (int)($_POST['step_increment'] ?? 5000);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($rank && $label) {
            for ($i = 1; $i <= $steps; $i++) {
                $salary = $baseSalary + ($i - 1) * $stepIncrement;
                $db->prepare("INSERT INTO grade_table (company_id, grade_rank, grade_label, step, base_salary, sort_order) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), grade_label = VALUES(grade_label)")
                   ->execute([$cid, $rank, $label, $i, $salary, $sortOrder]);
            }
            $_SESSION['flash'] = "等級 {$rank} を追加しました（{$steps}号俸）";
        }
    } elseif ($action === 'update_salary') {
        $id = (int)$_POST['id'];
        $salary = (int)$_POST['base_salary'];
        $db->prepare('UPDATE grade_table SET base_salary = ? WHERE id = ? AND company_id = ?')->execute([$salary, $id, $cid]);
        $_SESSION['flash'] = '給与額を更新しました';
    } elseif ($action === 'delete_grade') {
        $rank = $_POST['grade_rank'] ?? '';
        $db->prepare('DELETE FROM grade_table WHERE company_id = ? AND grade_rank = ?')->execute([$cid, $rank]);
        $_SESSION['flash'] = "等級 {$rank} を削除しました";
    } elseif ($action === 'update_rule') {
        $evalGrade = $_POST['eval_grade'] ?? '';
        $stepChange = (int)$_POST['step_change'];
        $coeff = (float)$_POST['bonus_coefficient'];
        $desc = trim($_POST['description'] ?? '');
        $db->prepare("INSERT INTO salary_rules (company_id, eval_grade, step_change, bonus_coefficient, description) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE step_change=VALUES(step_change), bonus_coefficient=VALUES(bonus_coefficient), description=VALUES(description)")
           ->execute([$cid, $evalGrade, $stepChange, $coeff, $desc]);
        $_SESSION['flash'] = '昇給ルールを更新しました';
    }

    header('Location: eval_grade_table.php');
    exit;
}

$gradeGrouped = getGradeTableGrouped($cid);
$gradeRows = getGradeTable($cid);
$salaryRules = getSalaryRules($cid);
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$pageTitle = '等級・号俸テーブル管理';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価管理</a></li>
        <li class="breadcrumb-item active">等級テーブル</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<h4 class="mb-4"><i class="bi bi-table me-2"></i>等級・号俸テーブル</h4>

<div class="row">
    <!-- 等級テーブル -->
    <div class="col-lg-8">
        <?php if (!$gradeGrouped): ?>
        <div class="alert alert-info">等級テーブルが未設定です。右のフォームから追加してください。</div>
        <?php else: ?>
        <?php foreach ($gradeGrouped as $rank => $data): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><strong><?= h($rank) ?></strong> - <?= h($data['label']) ?></span>
                <form method="post" class="d-inline" onsubmit="return confirm('等級 <?= h($rank) ?> を削除しますか？')">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete_grade">
                    <input type="hidden" name="grade_rank" value="<?= h($rank) ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($data['steps'] as $step => $salary): ?>
                            <th class="text-center"><?= $step ?>号俸</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($data['steps'] as $step => $salary): ?>
                            <td class="text-center">
                                <strong><?= number_format($salary) ?>円</strong>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- 昇給ルール -->
        <div class="card mt-4">
            <div class="card-header"><i class="bi bi-arrow-up-circle me-2"></i>評価別 昇給・賞与ルール</div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table mb-0">
                    <thead class="table-light">
                        <tr><th>評価</th><th>号俸変動</th><th>賞与係数</th><th>説明</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (['S','A','B','C','D'] as $eg):
                            $rule = null;
                            foreach ($salaryRules as $r) { if ($r['eval_grade'] === $eg) { $rule = $r; break; } }
                        ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="update_rule">
                                <input type="hidden" name="eval_grade" value="<?= $eg ?>">
                                <td><span class="badge <?= getGradeBadgeClass($eg) ?> fs-6"><?= $eg ?></span></td>
                                <td><input type="number" name="step_change" class="form-control form-control-sm" style="width:80px" value="<?= $rule['step_change'] ?? 0 ?>"></td>
                                <td><input type="number" name="bonus_coefficient" class="form-control form-control-sm" style="width:80px" step="0.01" value="<?= number_format((float)($rule['bonus_coefficient'] ?? 1.0), 2) ?>"></td>
                                <td><input type="text" name="description" class="form-control form-control-sm" value="<?= h($rule['description'] ?? '') ?>" placeholder="説明"></td>
                                <td><button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i></button></td>
                            </form>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>

    <!-- 新規等級追加 -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>等級追加</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="add_grade">
                    <div class="mb-2">
                        <label class="form-label">等級コード *</label>
                        <input name="grade_rank" class="form-control" required placeholder="G1, G2...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">等級名 *</label>
                        <input name="grade_label" class="form-control" required placeholder="一般1, 主任...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">号俸数</label>
                        <input name="steps" type="number" class="form-control" value="5" min="1" max="20">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">1号俸の基本給（円）</label>
                        <input name="base_salary" type="number" class="form-control" value="200000" step="1000">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">号俸間の昇給額（円）</label>
                        <input name="step_increment" type="number" class="form-control" value="5000" step="1000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">表示順</label>
                        <input name="sort_order" type="number" class="form-control" value="<?= count($gradeGrouped) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">追加</button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body text-center">
                <a href="salary_simulation.php" class="btn btn-warning"><i class="bi bi-calculator me-1"></i>給与シミュレーション</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
