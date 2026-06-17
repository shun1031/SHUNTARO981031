<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '年間シート';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));

$empFilter = getEmployeeNameFilter();
$annual = getSalesAnnualSummary($cid, $year, $empFilter);
$targets = getSalesTargets($cid, $year);

// 年合計
$yearTotals = ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'cases' => 0,
    'event_rev' => 0, 'event_cost' => 0, 'event_profit' => 0, 'event_cases' => 0,
    'regular_rev' => 0, 'regular_cost' => 0, 'regular_profit' => 0, 'regular_cases' => 0,
    'target' => 0];
for ($m = 1; $m <= 12; $m++) {
    $yearTotals['revenue'] += $annual[$m]['total']['revenue'];
    $yearTotals['cost'] += $annual[$m]['total']['cost'];
    $yearTotals['profit'] += $annual[$m]['total']['profit'];
    $yearTotals['cases'] += $annual[$m]['total']['case_count'];
    $yearTotals['event_rev'] += $annual[$m]['event']['revenue'];
    $yearTotals['event_cost'] += $annual[$m]['event']['cost'];
    $yearTotals['event_profit'] += $annual[$m]['event']['profit'];
    $yearTotals['event_cases'] += $annual[$m]['event']['case_count'];
    $yearTotals['regular_rev'] += $annual[$m]['regular']['revenue'];
    $yearTotals['regular_cost'] += $annual[$m]['regular']['cost'];
    $yearTotals['regular_profit'] += $annual[$m]['regular']['profit'];
    $yearTotals['regular_cases'] += $annual[$m]['regular']['case_count'];
    $yearTotals['target'] += (int)($targets[$m]['total']['revenue_target'] ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-table me-2"></i>年間シート</h1>
                <p><?= $year ?>年 売上・粗利の月別推移</p>
            </div>
            <div>
                <select onchange="location.href='?year='+this.value" class="form-select form-select-sm" style="width:120px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- KPIサマリー -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="color:#059669;font-size:1.2rem"><?= number_format($yearTotals['revenue']) ?></div><div class="kpi-label">年間売上</div></div></div>
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="color:#3b82f6;font-size:1.2rem"><?= number_format($yearTotals['profit']) ?></div><div class="kpi-label">年間粗利</div></div></div>
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="font-size:1.2rem"><?= $yearTotals['revenue'] > 0 ? round($yearTotals['profit'] / $yearTotals['revenue'] * 100, 1) : 0 ?>%</div><div class="kpi-label">粗利率</div></div></div>
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="color:#8b5cf6;font-size:1.2rem"><?= number_format($yearTotals['cases']) ?></div><div class="kpi-label">案件数</div></div></div>
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="color:#f59e0b;font-size:1.2rem"><?= number_format($yearTotals['target']) ?></div><div class="kpi-label">年間目標</div></div></div>
        <div class="col-6 col-md-2"><div class="sales-kpi"><div class="kpi-value" style="font-size:1.2rem"><?= $yearTotals['target'] > 0 ? round($yearTotals['revenue'] / $yearTotals['target'] * 100, 1) : 0 ?>%</div><div class="kpi-label">達成率</div></div></div>
    </div>

    <!-- チャート -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="sales-chart-wrap">
                <canvas id="annualChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 年間テーブル -->
    <?php
    $sections = [
        '合計' => ['key' => 'total', 'color' => '#059669'],
        '常勤' => ['key' => 'regular', 'color' => '#3b82f6'],
        'イベント' => ['key' => 'event', 'color' => '#8b5cf6'],
    ];
    foreach ($sections as $secLabel => $sec):
        $k = $sec['key'];
    ?>
    <div class="card mb-3">
        <div class="card-header fw-bold" style="border-left: 3px solid <?= $sec['color'] ?>">
            <?= $secLabel ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table annual-table mb-0">
                    <thead>
                        <tr>
                            <th class="row-label">項目</th>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <th class="text-center"><?= $m ?>月</th>
                            <?php endfor; ?>
                            <th class="text-center total-col">年合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($k === 'total'): ?>
                        <tr>
                            <td class="row-label">目標</td>
                            <?php $tgtSum = 0; for ($m = 1; $m <= 12; $m++): $v = (int)($targets[$m]['total']['revenue_target'] ?? 0); $tgtSum += $v; ?>
                            <td><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= number_format($tgtSum) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="row-label">売上</td>
                            <?php $sum = 0; for ($m = 1; $m <= 12; $m++): $v = $annual[$m][$k]['revenue']; $sum += $v; ?>
                            <td class="<?= $v > 0 ? 'amount-positive' : '' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive"><?= number_format($sum) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">原価</td>
                            <?php $sum = 0; for ($m = 1; $m <= 12; $m++): $v = $annual[$m][$k]['cost']; $sum += $v; ?>
                            <td><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= number_format($sum) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">粗利</td>
                            <?php $sum = 0; for ($m = 1; $m <= 12; $m++): $v = $annual[$m][$k]['profit']; $sum += $v; ?>
                            <td class="<?= $v >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive"><?= number_format($sum) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">粗利率</td>
                            <?php for ($m = 1; $m <= 12; $m++): $rev = $annual[$m][$k]['revenue']; $pro = $annual[$m][$k]['profit']; ?>
                            <td><?= $rev > 0 ? round($pro / $rev * 100, 1) . '%' : '-' ?></td>
                            <?php endfor; ?>
                            <?php $tr = array_sum(array_column(array_column($annual, $k), 'revenue')); $tp = array_sum(array_column(array_column($annual, $k), 'profit')); ?>
                            <td class="total-col"><?= $tr > 0 ? round($tp / $tr * 100, 1) . '%' : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">案件数</td>
                            <?php $sum = 0; for ($m = 1; $m <= 12; $m++): $v = $annual[$m][$k]['case_count']; $sum += $v; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $sum ?></td>
                        </tr>
                        <?php if ($k === 'total'): ?>
                        <tr style="background:#fef3c7">
                            <td class="row-label fw-bold">達成率</td>
                            <?php for ($m = 1; $m <= 12; $m++):
                                $tgt = (int)($targets[$m]['total']['revenue_target'] ?? 0);
                                $act = $annual[$m]['total']['revenue'];
                                $rate = $tgt > 0 ? round($act / $tgt * 100, 1) : 0;
                            ?>
                            <td class="fw-bold <?= $rate >= 100 ? 'amount-positive' : ($rate >= 80 ? '' : 'amount-negative') ?>"><?= $tgt > 0 ? $rate . '%' : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col fw-bold"><?= $yearTotals['target'] > 0 ? round($yearTotals['revenue'] / $yearTotals['target'] * 100, 1) . '%' : '-' ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php
// チャートデータ
$chartData = [];
$chartTargets = [];
for ($m = 1; $m <= 12; $m++) {
    $chartData[$m] = [
        'revenue' => $annual[$m]['total']['revenue'],
        'profit' => $annual[$m]['total']['profit'],
    ];
    $chartTargets[$m] = (int)($targets[$m]['total']['revenue_target'] ?? 0);
}
$inlineJs = 'salesDrawTrendChart("annualChart", ' . json_encode($chartData) . ', ' . json_encode($chartTargets) . ');';
require_once __DIR__ . '/../includes/footer.php';
?>
