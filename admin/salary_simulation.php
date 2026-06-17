<?php
/**
 * 給与シミュレーション画面
 * 評価確定前に「この評価だと来期の給与総額がいくらになるか」を確認
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$db   = getDB();
$csrf = getCsrfToken();

// SA はcompany_id未設定 → 会社選択が必要
$cid = getCompanyId();
if (!$cid && isSuperAdmin()) {
    $companies = $db->query('SELECT id, company_name AS name FROM companies WHERE is_active = 1 ORDER BY company_name')->fetchAll();
    if (!empty($_GET['company_id'])) {
        $cid = (int)$_GET['company_id'];
    } elseif (count($companies) === 1) {
        $cid = $companies[0]['id'];
    }
}
if (!$cid) {
    $pageTitle = '給与シミュレーション';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="container py-4"><div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>会社を選択してください。</div>';
    echo '<div class="list-group">';
    foreach ($companies ?? [] as $c) {
        echo '<a href="?company_id=' . $c['id'] . '" class="list-group-item list-group-item-action">' . h($c['name']) . '</a>';
    }
    echo '</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$periods = getEvalPeriods($cid);
$periodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
$period = $periodId ? getEvalPeriod($periodId, $cid) : null;

$simulation = null;
$bonusSettings = getBonusSettings($cid, $periodId);
$salaryRules = getSalaryRules($cid);

// シミュレーション実行
if ($period) {
    $simulation = simulateAllSalaries($cid, $periodId);
}

// 賞与設定更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'update_bonus') {
        $baseMonths = (float)$_POST['base_months'];
        $compIdx = (float)$_POST['company_performance_index'];
        $minRate = (float)$_POST['min_guarantee_rate'];
        if ($bonusSettings) {
            $db->prepare('UPDATE bonus_settings SET base_months=?, company_performance_index=?, min_guarantee_rate=?, updated_at=NOW() WHERE id=?')
               ->execute([$baseMonths, $compIdx, $minRate, $bonusSettings['id']]);
        } else {
            $db->prepare('INSERT INTO bonus_settings (company_id, period_id, base_months, company_performance_index, min_guarantee_rate) VALUES (?,?,?,?,?)')
               ->execute([$cid, $periodId, $baseMonths, $compIdx, $minRate]);
        }
        $_SESSION['flash'] = '賞与設定を更新しました';
        header("Location: salary_simulation.php?period_id={$periodId}" . ($cid && isSuperAdmin() ? "&company_id={$cid}" : ""));
        exit;
    } elseif ($action === 'apply_grades') {
        // 等級変動を社員に適用
        $count = 0;
        foreach ($simulation['employees'] ?? [] as $emp) {
            if ($emp['new_grade_rank'] && $emp['new_step']) {
                $db->prepare("INSERT INTO employee_grades (company_id, employee_id, grade_rank, step, effective_date, reason, eval_grade, period_id) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$cid, $emp['employee_id'], $emp['new_grade_rank'], $emp['new_step'], date('Y-m-d', strtotime('+1 month', strtotime($period['end_date']))), '評価結果に基づく自動昇給/降給', $emp['eval_grade'], $periodId]);
                $count++;
            }
        }
        $_SESSION['flash'] = "{$count}名の等級を更新しました";
        header("Location: salary_simulation.php?period_id={$periodId}" . ($cid && isSuperAdmin() ? "&company_id={$cid}" : ""));
        exit;
    }
}

$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

$pageTitle = '給与シミュレーション';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価管理</a></li>
        <li class="breadcrumb-item active">給与シミュレーション</li>
    </ol>
</nav>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-calculator me-2"></i>給与シミュレーション</h4>
    <?php if (!empty($periods)): ?>
    <select class="form-select" style="width:auto" onchange="location.href='?period_id='+this.value">
        <?php foreach ($periods as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $p['id']==$periodId?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>評価期間が未登録です</span>
    <?php endif; ?>
</div>

<?php if (!$period): ?>
<div class="alert alert-info">評価期間を選択してください。</div>
<?php else: ?>

<!-- 賞与算定式の説明 -->
<div class="alert alert-info small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    <strong>賞与計算式:</strong> 基本給 × 基準月数(<?= number_format((float)($bonusSettings['base_months'] ?? 2.0), 1) ?>ヶ月) × 業績評価係数 × 会社業績指数(<?= number_format((float)($bonusSettings['company_performance_index'] ?? 1.0), 2) ?>)
    <br><strong>昇給ルール:</strong>
    <?php foreach ($salaryRules as $r): ?>
    <span class="badge <?= getGradeBadgeClass($r['eval_grade']) ?> me-1"><?= h($r['eval_grade']) ?>: <?= $r['step_change'] >= 0 ? '+' : '' ?><?= $r['step_change'] ?>号俸, ×<?= number_format((float)$r['bonus_coefficient'], 2) ?></span>
    <?php endforeach; ?>
</div>

<div class="row mb-4">
    <!-- サマリーカード -->
    <div class="col-md-3">
        <div class="card text-center h-100 border-primary">
            <div class="card-body">
                <div class="text-muted small">対象人数</div>
                <div class="fs-2 fw-bold text-primary"><?= $simulation['summary']['employee_count'] ?? 0 ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">月額給与増減</div>
                <?php $diff = $simulation['summary']['monthly_diff'] ?? 0; ?>
                <div class="fs-3 fw-bold <?= $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-success' : '') ?>">
                    <?= $diff >= 0 ? '+' : '' ?><?= number_format($diff) ?>円
                </div>
                <div class="text-muted small">年間: <?= $diff >= 0 ? '+' : '' ?><?= number_format($diff * 12) ?>円</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">月額給与総額（変更後）</div>
                <div class="fs-4 fw-bold"><?= number_format($simulation['summary']['total_new_monthly'] ?? 0) ?>円</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100 border-warning">
            <div class="card-body">
                <div class="text-muted small">賞与総額</div>
                <div class="fs-4 fw-bold text-warning"><?= number_format($simulation['summary']['total_bonus'] ?? 0) ?>円</div>
            </div>
        </div>
    </div>
</div>

<!-- 個人別シミュレーション -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>個人別シミュレーション結果</span>
        <form method="post" class="d-inline" onsubmit="return confirm('全社員の等級を更新します。よろしいですか？')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button name="action" value="apply_grades" class="btn btn-sm btn-danger"><i class="bi bi-check-circle me-1"></i>等級確定・適用</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>社員番号</th>
                        <th>氏名</th>
                        <th>部署</th>
                        <th class="text-center">評価</th>
                        <th class="text-center">スコア</th>
                        <th class="text-end">現在等級</th>
                        <th class="text-center">→</th>
                        <th class="text-start">新等級</th>
                        <th class="text-end">現在給与</th>
                        <th class="text-center">→</th>
                        <th class="text-end">新給与</th>
                        <th class="text-end">増減</th>
                        <th class="text-end">賞与額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($simulation['employees'] ?? [] as $emp):
                        $gc = getGradeBadgeClass($emp['eval_grade']);
                        $diffClass = ($emp['salary_diff'] ?? 0) > 0 ? 'text-danger' : (($emp['salary_diff'] ?? 0) < 0 ? 'text-success' : '');
                    ?>
                    <tr>
                        <td><?= h($emp['employee_number'] ?? '') ?></td>
                        <td><strong><?= h($emp['employee_name'] ?? '') ?></strong></td>
                        <td class="small"><?= h($emp['department'] ?? '') ?></td>
                        <td class="text-center"><span class="badge <?= $gc ?>"><?= h($emp['eval_grade']) ?></span></td>
                        <td class="text-center"><?= $emp['score_total'] !== null ? number_format((float)$emp['score_total'], 1) : '-' ?></td>
                        <td class="text-end small">
                            <?php if ($emp['current_grade_rank']): ?>
                            <?= h($emp['current_grade_rank']) ?>-<?= $emp['current_step'] ?>
                            <?php else: ?><span class="text-muted">未設定</span><?php endif; ?>
                        </td>
                        <td class="text-center"><i class="bi bi-arrow-right text-muted"></i></td>
                        <td class="small">
                            <?php if ($emp['new_grade_rank']): ?>
                            <strong><?= h($emp['new_grade_rank']) ?>-<?= $emp['new_step'] ?></strong>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="text-end"><?= $emp['current_salary'] ? number_format($emp['current_salary']) : '-' ?></td>
                        <td class="text-center"><i class="bi bi-arrow-right text-muted"></i></td>
                        <td class="text-end fw-bold"><?= $emp['new_salary'] ? number_format($emp['new_salary']) : '-' ?></td>
                        <td class="text-end <?= $diffClass ?>">
                            <?php if ($emp['salary_diff'] !== null): ?>
                            <?= $emp['salary_diff'] >= 0 ? '+' : '' ?><?= number_format($emp['salary_diff']) ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="text-end"><?= $emp['bonus_amount'] ? number_format($emp['bonus_amount']) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($simulation['employees'])): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">シミュレーション対象がありません。評価シートを作成してください。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 賞与設定パネル -->
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-gear me-2"></i>賞与設定</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="update_bonus">
                    <div class="mb-3">
                        <label class="form-label">基準月数</label>
                        <div class="input-group">
                            <input type="number" name="base_months" class="form-control" step="0.1" min="0" value="<?= h($bonusSettings['base_months'] ?? 2.0) ?>">
                            <span class="input-group-text">ヶ月分</span>
                        </div>
                        <div class="form-text">賞与の基準額 = 基本給 × この月数</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">会社業績指数</label>
                        <input type="number" name="company_performance_index" class="form-control" step="0.01" min="0" max="2" value="<?= h($bonusSettings['company_performance_index'] ?? 1.00) ?>">
                        <div class="form-text">1.00 = 標準。会社全体の業績に応じて調整</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">最低保証率</label>
                        <div class="input-group">
                            <input type="number" name="min_guarantee_rate" class="form-control" step="0.01" min="0" max="1" value="<?= h($bonusSettings['min_guarantee_rate'] ?? 0.50) ?>">
                            <span class="input-group-text">倍</span>
                        </div>
                        <div class="form-text">D評価でも基準額のこの割合は保証</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">設定を更新してシミュレーション再計算</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-table me-2"></i>昇給・賞与ルール</div>
            <div class="card-body p-0">
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>評価</th><th>号俸変動</th><th>賞与係数</th><th>内容</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salaryRules as $r): ?>
                        <tr>
                            <td><span class="badge <?= getGradeBadgeClass($r['eval_grade']) ?>"><?= h($r['eval_grade']) ?></span></td>
                            <td class="<?= $r['step_change'] > 0 ? 'text-danger' : ($r['step_change'] < 0 ? 'text-success' : '') ?>">
                                <?= $r['step_change'] >= 0 ? '+' : '' ?><?= $r['step_change'] ?>
                            </td>
                            <td>×<?= number_format((float)$r['bonus_coefficient'], 2) ?></td>
                            <td class="small"><?= h($r['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$salaryRules): ?>
                        <tr><td colspan="4" class="text-muted text-center">未設定（サンプルデータを投入してください）</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
            <div class="card-footer text-end">
                <a href="eval_grade_table.php" class="btn btn-sm btn-outline-primary">等級テーブル管理 →</a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
