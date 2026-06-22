<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '取引先別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY = $year - 1; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY = $year + 1; }

$empFilter = getEmployeeNameFilter();

// 今月データ
$report = getSalesClientReport($cid, $year, $empFilter);
// 前年同月データ
$prevReport = getSalesClientReport($cid, $year - 1, $empFilter);
$prevByClient = [];
foreach ($prevReport as $clientId => $pr) {
    $prevByClient[$clientId] = $pr;
}

// 今月の売上順にソート
uasort($report, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});

// 今月データのある取引先のみ表示
$report = array_filter($report, fn($d) => ($d['months'][$month]['revenue'] ?? 0) > 0 || ($d['months'][$month]['case_count'] ?? 0) > 0);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-building me-2"></i>取引先別売上レポート</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
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
        <i class="bi bi-building fs-1 d-block mb-2"></i><?= $year ?>年<?= $month ?>月のデータがありません
    </div></div>
    <?php endif; ?>

    <?php $rank = 0; foreach ($report as $clientId => $data): $rank++;
        $cur  = $data['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0];
        $prev = $prevByClient[$clientId]['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0];
        $yoy  = $prev['revenue'] > 0 ? round(($cur['revenue'] - $prev['revenue']) / $prev['revenue'] * 100, 1) : null;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <strong><?= h($data['client_name']) ?></strong>
                <span class="text-muted small ms-2"><?= $cur['case_count'] ?>件</span>
            </span>
            <span>
                <span class="fw-bold" style="color:#059669"><?= number_format($cur['revenue']) ?></span>
                <span class="text-muted small ms-1">粗利 <?= number_format($cur['profit']) ?></span>
                <?php if ($yoy !== null): ?>
                <span class="kpi-badge <?= $yoy >= 0 ? 'kpi-up' : 'kpi-down' ?> ms-2">
                    <i class="bi bi-arrow-<?= $yoy >= 0 ? 'up' : 'down' ?>"></i> <?= $yoy ?>%
                </span>
                <?php endif; ?>
            </span>
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
                        <td style="padding-left:.75rem">売上</td>
                        <td class="text-end amount-positive" style="padding-right:.75rem"><?= $cur['revenue'] ? number_format($cur['revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $prev['revenue'] ? number_format($prev['revenue']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">粗利</td>
                        <td class="text-end <?= $cur['profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>" style="padding-right:.75rem"><?= $cur['profit'] ? number_format($cur['profit']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $prev['profit'] ? number_format($prev['profit']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">件数</td>
                        <td class="text-end" style="padding-right:.75rem"><?= $cur['case_count'] ?: '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $prev['case_count'] ?: '-' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
