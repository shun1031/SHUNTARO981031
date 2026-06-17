<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();
$user = getCurrentUser();
$isAdmin = in_array($user['role'] ?? '', ['super_admin','company_admin']);
$myEmpId = $_SESSION['employee_id'] ?? 0;

$targetEmpId = $isAdmin ? (int)($_GET['employee_id'] ?? $myEmpId) : $myEmpId;
if (!$targetEmpId) { header('Location: eval_dashboard.php'); exit; }

// 対象社員情報
$empStmt = $db->prepare('SELECT * FROM employees WHERE id = ? AND company_id = ?');
$empStmt->execute([$targetEmpId, $cid]);
$emp = $empStmt->fetch();
if (!$emp) { header('Location: eval_dashboard.php'); exit; }

// 全シート取得
$stmt = $db->prepare(
    'SELECT es.*, ep.name AS period_name, ep.fiscal_year, ep.half
     FROM eval_sheets es
     JOIN eval_periods ep ON es.period_id = ep.id
     WHERE es.employee_id = ? AND es.company_id = ?
     ORDER BY ep.fiscal_year DESC, ep.half DESC'
);
$stmt->execute([$targetEmpId, $cid]);
$sheets = $stmt->fetchAll();

// チャート用データ
$chartLabels = []; $chartPerf = []; $chartAct = []; $chartComp = []; $chartTotal = [];
foreach (array_reverse($sheets) as $s) {
    $total = $s['final_score_total'] ?? $s['primary_score_total'] ?? $s['self_score_total'];
    if ($total !== null) {
        $chartLabels[] = $s['period_name'];
        $chartPerf[]   = (float)($s['final_score_performance'] ?? $s['primary_score_performance'] ?? 0);
        $chartAct[]    = (float)($s['final_score_action'] ?? $s['primary_score_action'] ?? 0);
        $chartComp[]   = (float)($s['final_score_competency'] ?? $s['primary_score_competency'] ?? 0);
        $chartTotal[]  = (float)$total;
    }
}

$pageTitle = '評価履歴 - ' . h($emp['name']);
$extraCss = ['eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_dashboard.php">評価</a></li>
        <li class="breadcrumb-item active"><?= h($emp['name']) ?> 評価履歴</li>
    </ol>
</nav>

<h4 class="mb-4"><i class="bi bi-clock-history me-2"></i><?= h($emp['name']) ?> の評価履歴</h4>

<?php if (count($chartLabels) >= 2): ?>
<div class="card mb-4">
    <div class="card-header">スコア推移</div>
    <div class="card-body">
        <canvas id="trendChart" style="max-height:300px"></canvas>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>評価期間</th>
                        <th>ステータス</th>
                        <th class="text-end">業績</th>
                        <th class="text-end">行動</th>
                        <th class="text-end">コンピテンシー</th>
                        <th class="text-end">総合</th>
                        <th class="text-center">等級</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sheets as $s):
                        $sl = getEvalStatusLabel($s['status']);
                        $total = $s['final_score_total'] ?? $s['primary_score_total'] ?? $s['self_score_total'];
                        $grade = $s['final_grade'] ?: ($total !== null ? getEvalGrade((float)$total) : '-');
                        $gc = $grade !== '-' ? getGradeBadgeClass($grade) : 'bg-light text-dark';
                    ?>
                    <tr>
                        <td><strong><?= h($s['period_name']) ?></strong></td>
                        <td><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
                        <td class="text-end"><?= $s['final_score_performance'] !== null ? number_format((float)$s['final_score_performance'],1) : '-' ?></td>
                        <td class="text-end"><?= $s['final_score_action'] !== null ? number_format((float)$s['final_score_action'],1) : '-' ?></td>
                        <td class="text-end"><?= $s['final_score_competency'] !== null ? number_format((float)$s['final_score_competency'],1) : '-' ?></td>
                        <td class="text-end fw-bold"><?= $total !== null ? number_format((float)$total,1) : '-' ?></td>
                        <td class="text-center"><span class="badge <?= $gc ?>"><?= h($grade) ?></span></td>
                        <td><a href="eval_feedback.php?sheet_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">詳細</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$sheets): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">評価履歴がありません</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (count($chartLabels) >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            {label:'総合',data:<?= json_encode($chartTotal) ?>,borderColor:'#2c3e50',borderWidth:3,tension:0.3},
            {label:'業績',data:<?= json_encode($chartPerf) ?>,borderColor:'#3498db',borderWidth:1.5,borderDash:[5,5],tension:0.3},
            {label:'行動',data:<?= json_encode($chartAct) ?>,borderColor:'#27ae60',borderWidth:1.5,borderDash:[5,5],tension:0.3},
            {label:'コンピテンシー',data:<?= json_encode($chartComp) ?>,borderColor:'#9b59b6',borderWidth:1.5,borderDash:[5,5],tension:0.3}
        ]
    },
    options:{responsive:true,scales:{y:{beginAtZero:true,max:100}}}
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
