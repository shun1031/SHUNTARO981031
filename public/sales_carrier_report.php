<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'キャリア別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY = $year - 1; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY = $year + 1; }

$db = getDB();

// 今月データ
$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(carrier,''), '未設定') AS carrier,
        COALESCE(NULLIF(case_type,''), 'regular') AS case_type,
        COUNT(*) AS case_count,
        COALESCE(SUM(revenue), 0) AS revenue,
        COALESCE(SUM(gross_profit), 0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND case_year = ? AND case_month = ? AND status = 'confirmed'
    GROUP BY carrier, case_type
    ORDER BY carrier, case_type
");
$stmt->execute([$cid, $year, $month]);

$report = [];
foreach ($stmt->fetchAll() as $r) {
    $carrier = $r['carrier'];
    $type    = in_array($r['case_type'], ['regular', 'event']) ? $r['case_type'] : 'regular';
    if (!isset($report[$carrier])) {
        $report[$carrier] = [
            'total_revenue' => 0, 'total_profit' => 0, 'total_cases' => 0,
            'regular' => ['revenue' => 0, 'profit' => 0, 'cases' => 0],
            'event'   => ['revenue' => 0, 'profit' => 0, 'cases' => 0],
        ];
    }
    $report[$carrier][$type]['revenue'] += (int)$r['revenue'];
    $report[$carrier][$type]['profit']  += (int)$r['profit'];
    $report[$carrier][$type]['cases']   += (int)$r['case_count'];
    $report[$carrier]['total_revenue'] += (int)$r['revenue'];
    $report[$carrier]['total_profit']  += (int)$r['profit'];
    $report[$carrier]['total_cases']   += (int)$r['case_count'];
}
uasort($report, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

// 前年同月
$stmtPrev = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier, COALESCE(SUM(revenue),0) AS revenue
    FROM sales_cases WHERE company_id=? AND case_year=? AND case_month=? AND status='confirmed' GROUP BY carrier
");
$stmtPrev->execute([$cid, $year - 1, $month]);
$prevRev = [];
foreach ($stmtPrev->fetchAll() as $r) $prevRev[$r['carrier']] = (int)$r['revenue'];

// 今月全体売上（構成比の分母）
$overallTotal = array_sum(array_column($report, 'total_revenue'));

$colorMap = [
    'ドコモ'   => ['solid' => '#ef4444', 'border' => '#dc2626'],
    'SB'       => ['solid' => '#ffffff', 'border' => '#94a3b8'],
    'au'       => ['solid' => '#f97316', 'border' => '#ea580c'],
    'コミュファ' => ['solid' => '#facc15', 'border' => '#ca8a04'],
    '楽天'     => ['solid' => '#f472b6', 'border' => '#ec4899'],
];
$defaultCS = ['solid' => '#6b7280', 'border' => '#4b5563'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-reception-4 me-2"></i>キャリア別売上レポート</h1>
                <p class="text-muted mb-0"><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex align-items-center gap-1">
                <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:120px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <?php if (empty($report)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-reception-4 fs-1 d-block mb-2"></i><?= $year ?>年<?= $month ?>月のデータがありません
    </div></div>
    <?php else: ?>

    <?php $rank = 0; foreach ($report as $carrier => $data): $rank++;
        $reg  = $data['regular'];
        $evt  = $data['event'];
        $yoy  = ($prevRev[$carrier] ?? 0) > 0
            ? round(($data['total_revenue'] - $prevRev[$carrier]) / $prevRev[$carrier] * 100, 1) : null;
        $pct  = $overallTotal > 0 ? round($data['total_revenue'] / $overallTotal * 100, 1) : 0;
        $cs   = $colorMap[$carrier] ?? $defaultCS;
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <span class="fw-bold fs-6"><?= h($carrier) ?></span>
                <span class="badge bg-secondary"><?= $data['total_cases'] ?>件</span>
                <span class="badge bg-light text-dark"><?= $pct ?>%</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="fw-bold" style="color:#059669;font-size:1rem"><?= number_format($data['total_revenue']) ?></span>
                <span class="text-muted small">粗利 <?= number_format($data['total_profit']) ?></span>
                <?php if ($yoy !== null): ?>
                <span class="kpi-badge <?= $yoy >= 0 ? 'kpi-up' : 'kpi-down' ?>">
                    <i class="bi bi-arrow-<?= $yoy >= 0 ? 'up' : 'down' ?>"></i> <?= $yoy ?>%
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="padding-left:.75rem">項目</th>
                        <th class="text-end" style="padding-right:.75rem"><?= $year ?>年<?= $month ?>月</th>
                        <th class="text-end" style="padding-right:.75rem;color:#9ca3af">前年同月</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding-left:.75rem">売上合計</td>
                        <td class="text-end amount-positive fw-bold" style="padding-right:.75rem"><?= number_format($data['total_revenue']) ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= ($prevRev[$carrier] ?? 0) ? number_format($prevRev[$carrier]) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span class="badge bg-info" style="font-size:.65rem">常勤売上</span></td>
                        <td class="text-end small" style="padding-right:.75rem"><?= $reg['revenue'] ? number_format($reg['revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span class="badge" style="background:#8b5cf6;font-size:.65rem">イベント売上</span></td>
                        <td class="text-end small" style="padding-right:.75rem"><?= $evt['revenue'] ? number_format($evt['revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">粗利</td>
                        <td class="text-end <?= $data['total_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>" style="padding-right:.75rem"><?= $data['total_profit'] ? number_format($data['total_profit']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">件数</td>
                        <td class="text-end" style="padding-right:.75rem"><?= $data['total_cases'] ?: '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
