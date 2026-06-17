<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireRole('super_admin', 'company_admin');
$cid  = getCompanyId();
$db   = getDB();

$periods = getEvalPeriods($cid);
$periodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
$period = $periodId ? getEvalPeriod($periodId, $cid) : null;

// CSV Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv' && $period) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $sheets = getEvalSheetsForPeriod($periodId, $cid);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="eval_' . $period['fiscal_year'] . '_' . $period['half'] . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['社員番号','氏名','部署','評価者','ステータス','業績','行動','コンピテンシー','総合','等級']);
    foreach ($sheets as $s) {
        $total = $s['final_score_total'] ?? $s['primary_score_total'] ?? '';
        $grade = $s['final_grade'] ?: ($total !== '' ? getEvalGrade((float)$total) : '');
        fputcsv($out, [$s['employee_number'],$s['employee_name'],$s['department']??'',$s['evaluator_name']??'',$s['status'],
            $s['final_score_performance']??'',$s['final_score_action']??'',$s['final_score_competency']??'',$total,$grade]);
    }
    fclose($out);
    exit;
}

$sheets = $period ? getEvalSheetsForPeriod($periodId, $cid) : [];

// 集計データ
$gradeCount = ['S'=>0,'A'=>0,'B'=>0,'C'=>0,'D'=>0];
$deptScores = [];
foreach ($sheets as $s) {
    $total = (float)($s['final_score_total'] ?? $s['primary_score_total'] ?? 0);
    $grade = $s['final_grade'] ?: getEvalGrade($total);
    $gradeCount[$grade]++;
    $dept = $s['department'] ?? '未設定';
    $deptScores[$dept][] = [
        'perf' => (float)($s['final_score_performance'] ?? 0),
        'act'  => (float)($s['final_score_action'] ?? 0),
        'comp' => (float)($s['final_score_competency'] ?? 0),
        'total' => $total,
    ];
}
$deptAvg = [];
foreach ($deptScores as $dept => $scores) {
    $n = count($scores);
    $deptAvg[$dept] = [
        'perf' => round(array_sum(array_column($scores,'perf'))/$n,1),
        'act'  => round(array_sum(array_column($scores,'act'))/$n,1),
        'comp' => round(array_sum(array_column($scores,'comp'))/$n,1),
        'total'=> round(array_sum(array_column($scores,'total'))/$n,1),
    ];
}

$pageTitle = '評価レポート';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="eval_periods.php">評価管理</a></li>
        <li class="breadcrumb-item active">レポート</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-graph-up me-2"></i>評価レポート</h4>
    <div class="d-flex gap-2">
        <?php if (!empty($periods)): ?>
        <select id="periodSelect" class="form-select" style="width:auto" onchange="location.href='?period_id='+this.value">
            <?php foreach ($periods as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id']==$periodId?'selected':'' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>評価期間が未登録です</span>
        <?php endif; ?>
        <?php if ($period): ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= h(getCsrfToken()) ?>">
            <button name="action" value="export_csv" class="btn btn-success"><i class="bi bi-download me-1"></i>CSV</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!$sheets): ?>
<div class="alert alert-info">データがありません。評価期間を選択してください。</div>
<?php else: ?>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card"><div class="card-header">等級分布</div><div class="card-body"><canvas id="gradeChart"></canvas></div></div>
    </div>
    <div class="col-md-6">
        <div class="card"><div class="card-header">部署別平均スコア</div><div class="card-body"><canvas id="deptChart"></canvas></div></div>
    </div>
</div>

<!-- サマリーテーブル -->
<div class="card">
    <div class="card-header">部署別平均</div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>部署</th><th class="text-end">人数</th><th class="text-end">業績</th><th class="text-end">行動</th><th class="text-end">コンピテンシー</th><th class="text-end">総合</th></tr>
            </thead>
            <tbody>
                <?php foreach ($deptAvg as $dept => $avg): ?>
                <tr>
                    <td><strong><?= h($dept) ?></strong></td>
                    <td class="text-end"><?= count($deptScores[$dept]) ?></td>
                    <td class="text-end"><?= $avg['perf'] ?></td>
                    <td class="text-end"><?= $avg['act'] ?></td>
                    <td class="text-end"><?= $avg['comp'] ?></td>
                    <td class="text-end fw-bold"><?= $avg['total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
new Chart(document.getElementById('gradeChart'), {
    type:'pie',
    data:{
        labels:['S','A','B','C','D'],
        datasets:[{data:<?= json_encode(array_values($gradeCount)) ?>,backgroundColor:['#e74c3c','#3498db','#27ae60','#f39c12','#95a5a6']}]
    }
});
new Chart(document.getElementById('deptChart'), {
    type:'bar',
    data:{
        labels:<?= json_encode(array_keys($deptAvg)) ?>,
        datasets:[
            {label:'業績',data:<?= json_encode(array_column($deptAvg,'perf')) ?>,backgroundColor:'rgba(52,152,219,0.7)'},
            {label:'行動',data:<?= json_encode(array_column($deptAvg,'act')) ?>,backgroundColor:'rgba(39,174,96,0.7)'},
            {label:'コンピテンシー',data:<?= json_encode(array_column($deptAvg,'comp')) ?>,backgroundColor:'rgba(155,89,182,0.7)'}
        ]
    },
    options:{responsive:true,scales:{y:{beginAtZero:true,max:100}}}
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
