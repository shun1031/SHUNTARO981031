<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'キャリア別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year     = (int)($_GET['year'] ?? date('Y'));
$prevYear = $year - 1;

$db = getDB();

$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(carrier,''), '未設定') AS carrier,
        COALESCE(NULLIF(case_type,''), 'other') AS case_type,
        case_month,
        COUNT(*) AS case_count,
        COALESCE(SUM(revenue), 0) AS revenue,
        COALESCE(SUM(gross_profit), 0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND case_year = ? AND status = 'confirmed'
    GROUP BY carrier, case_type, case_month
    ORDER BY carrier, case_type, case_month
");
$stmt->execute([$cid, $year]);

$report = [];
foreach ($stmt->fetchAll() as $r) {
    $carrier = $r['carrier'];
    $type    = in_array($r['case_type'], ['regular', 'event']) ? $r['case_type'] : 'regular';
    $month   = (int)$r['case_month'];
    if (!isset($report[$carrier])) {
        $report[$carrier] = [
            'total_revenue' => 0, 'total_profit' => 0, 'total_cases' => 0,
            'types' => [
                'regular' => ['revenue' => 0, 'profit' => 0, 'cases' => 0, 'months' => []],
                'event'   => ['revenue' => 0, 'profit' => 0, 'cases' => 0, 'months' => []],
            ],
        ];
    }
    $report[$carrier]['types'][$type]['months'][$month] = [
        'revenue' => (int)$r['revenue'],
        'profit'  => (int)$r['profit'],
    ];
    $report[$carrier]['types'][$type]['revenue'] += (int)$r['revenue'];
    $report[$carrier]['types'][$type]['profit']  += (int)$r['profit'];
    $report[$carrier]['types'][$type]['cases']   += (int)$r['case_count'];
    $report[$carrier]['total_revenue'] += (int)$r['revenue'];
    $report[$carrier]['total_profit']  += (int)$r['profit'];
    $report[$carrier]['total_cases']   += (int)$r['case_count'];
}
uasort($report, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

$stmtPrev = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier, COALESCE(SUM(revenue),0) AS revenue
    FROM sales_cases WHERE company_id=? AND case_year=? AND status='confirmed' GROUP BY carrier
");
$stmtPrev->execute([$cid, $prevYear]);
$prevRev = [];
foreach ($stmtPrev->fetchAll() as $r) $prevRev[$r['carrier']] = (int)$r['revenue'];

// キャリアカラー
$colorMap = [
    'ドコモ'   => ['solid' => '#ef4444', 'border' => '#dc2626'],
    'SB'       => ['solid' => '#ffffff', 'border' => '#94a3b8'],
    'au'       => ['solid' => '#f97316', 'border' => '#ea580c'],
    'コミュファ' => ['solid' => '#facc15', 'border' => '#ca8a04'],
    '楽天'     => ['solid' => '#f472b6', 'border' => '#ec4899'],
];
$defaultCS = ['solid' => '#6b7280', 'border' => '#4b5563'];

// 会計年度順（9月スタート）
$fyMonths = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8];
$fyLabels = ['9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月','8月'];

// 全キャリア合計（構成比の分母）
$overallGrandTotal = array_sum(array_column($report, 'total_revenue'));

// 月別・全キャリア合計（構成比の月次分母）
$overallMonthlyTotals = [];
foreach ($fyMonths as $m) {
    $overallMonthlyTotals[$m] = 0;
    foreach ($report as $d) {
        $overallMonthlyTotals[$m] += ($d['types']['regular']['months'][$m]['revenue'] ?? 0)
                                   + ($d['types']['event']['months'][$m]['revenue'] ?? 0);
    }
}


require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-reception-4 me-2"></i>キャリア別売上レポート</h1>
                <p class="text-muted mb-0"><?= $year ?>年 (前年<?= $prevYear ?>年比較)</p>
            </div>
            <select onchange="location.href='?year='+this.value" class="form-select form-select-sm" style="width:120px">
                <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <?php if (empty($report)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-reception-4 fs-1 d-block mb-2"></i>キャリアデータがありません
    </div></div>
    <?php else: ?>

    <!-- キャリア別詳細カード -->
    <?php $rank = 0; foreach ($report as $carrier => $data): $rank++;
        $reg = $data['types']['regular'];
        $evt = $data['types']['event'];
        $yoy = ($prevRev[$carrier] ?? 0) > 0
            ? round(($data['total_revenue'] - $prevRev[$carrier]) / $prevRev[$carrier] * 100, 1) : null;
        $cs = $colorMap[$carrier] ?? $defaultCS;
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <span class="fw-bold fs-6"><?= h($carrier) ?></span>
                <span class="badge bg-secondary"><?= $data['total_cases'] ?>件</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="fw-bold" style="color:#059669;font-size:1rem"><?= number_format($data['total_revenue']) ?></span>
                <?php if ($yoy !== null): ?>
                <span class="kpi-badge <?= $yoy >= 0 ? 'kpi-up' : 'kpi-down' ?>">
                    <i class="bi bi-arrow-<?= $yoy >= 0 ? 'up' : 'down' ?>"></i> <?= $yoy ?>%
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- 常勤 / イベント サマリー -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe">
                        <div class="d-flex align-items-center mb-2">
                            <span class="fw-bold" style="color:#1d4ed8">常勤</span>
                            <span class="text-muted small ms-auto"><?= $reg['cases'] ?>件</span>
                        </div>
                        <?php if ($reg['cases'] > 0): ?>
                        <div class="row g-0 text-center">
                            <div class="col"><div class="text-muted" style="font-size:.72rem">売上</div><div class="fw-bold small"><?= number_format($reg['revenue']) ?></div></div>
                            <div class="col border-start"><div class="text-muted" style="font-size:.72rem">粗利</div><div class="fw-bold small"><?= number_format($reg['profit']) ?></div></div>
                        </div>
                        <?php else: ?><div class="text-muted small text-center py-1">データなし</div><?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 rounded" style="background:#fff7ed;border:1px solid #fed7aa">
                        <div class="d-flex align-items-center mb-2">
                            <span class="fw-bold" style="color:#92400e">イベント</span>
                            <span class="text-muted small ms-auto"><?= $evt['cases'] ?>件</span>
                        </div>
                        <?php if ($evt['cases'] > 0): ?>
                        <div class="row g-0 text-center">
                            <div class="col"><div class="text-muted" style="font-size:.72rem">売上</div><div class="fw-bold small"><?= number_format($evt['revenue']) ?></div></div>
                            <div class="col border-start"><div class="text-muted" style="font-size:.72rem">粗利</div><div class="fw-bold small"><?= number_format($evt['profit']) ?></div></div>
                        </div>
                        <?php else: ?><div class="text-muted small text-center py-1">データなし</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 月別売上テーブル -->
            <div class="table-responsive">
                <table class="table annual-table mb-0" style="font-size:.78rem">
                    <thead>
                        <tr>
                            <th style="min-width:80px">種別</th>
                            <?php foreach ($fyLabels as $lbl): ?><th class="text-center"><?= $lbl ?></th><?php endforeach; ?>
                            <th class="text-center total-col">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold" style="white-space:nowrap">常勤売上</td>
                            <?php foreach ($fyMonths as $m): $v = $reg['months'][$m]['revenue'] ?? 0; ?>
                            <td class="text-center <?= $v > 0 ? 'amount-positive' : '' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="total-col text-center <?= $reg['revenue'] > 0 ? 'amount-positive fw-bold' : '' ?>"><?= $reg['revenue'] ? number_format($reg['revenue']) : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold" style="white-space:nowrap">イベント売上</td>
                            <?php foreach ($fyMonths as $m): $v = $evt['months'][$m]['revenue'] ?? 0; ?>
                            <td class="text-center <?= $v > 0 ? 'amount-positive' : '' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="total-col text-center <?= $evt['revenue'] > 0 ? 'amount-positive fw-bold' : '' ?>"><?= $evt['revenue'] ? number_format($evt['revenue']) : '-' ?></td>
                        </tr>
                        <?php $grandTotal = $reg['revenue'] + $evt['revenue']; ?>
                        <tr class="fw-bold" style="background:#f0fdf4">
                            <td style="white-space:nowrap">売上合計</td>
                            <?php foreach ($fyMonths as $m):
                                $total = ($reg['months'][$m]['revenue'] ?? 0) + ($evt['months'][$m]['revenue'] ?? 0); ?>
                            <td class="text-center <?= $total > 0 ? 'amount-positive' : '' ?>"><?= $total ? number_format($total) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="total-col text-center <?= $grandTotal > 0 ? 'amount-positive fw-bold' : '' ?>"><?= $grandTotal ? number_format($grandTotal) : '-' ?></td>
                        </tr>
                        <tr style="background:#f0fdf4;color:#6b7280;font-size:.72rem">
                            <td style="white-space:nowrap">構成比</td>
                            <?php foreach ($fyMonths as $m):
                                $mTotal = ($reg['months'][$m]['revenue'] ?? 0) + ($evt['months'][$m]['revenue'] ?? 0);
                                $pct = $overallMonthlyTotals[$m] > 0 ? round($mTotal / $overallMonthlyTotals[$m] * 100, 1) : 0;
                            ?>
                            <td class="text-center"><?= $mTotal > 0 ? $pct . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <?php $carrierPct = $overallGrandTotal > 0 ? round($grandTotal / $overallGrandTotal * 100, 1) : 0; ?>
                            <td class="total-col text-center fw-bold"><?= $carrierPct ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
