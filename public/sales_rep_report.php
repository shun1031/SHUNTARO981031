<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY = $year - 1; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY = $year + 1; }

$empFilter  = getEmployeeNameFilter();
$report     = getSalesRepReport($cid, $year,     $empFilter);
$prevReport = getSalesRepReport($cid, $year - 1, $empFilter);

$prevByRep = [];
foreach ($prevReport as $rep => $pr) {
    $prevByRep[$rep] = $pr;
}

// 今月の売上順にソート
uasort($report, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});

// 今月データのある担当者のみ表示
$report = array_filter($report, fn($d) => ($d['months'][$month]['revenue'] ?? 0) > 0 || ($d['months'][$month]['case_count'] ?? 0) > 0);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-badge me-2"></i>担当者別売上レポート</h1>
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
        <i class="bi bi-person-badge fs-1 d-block mb-2"></i><?= $year ?>年<?= $month ?>月のデータがありません
    </div></div>
    <?php endif; ?>

    <?php $rank = 0; foreach ($report as $rep => $data): $rank++;
        $cur  = $data['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0, 'regular_revenue' => 0, 'event_revenue' => 0];
        $prev = $prevByRep[$rep]['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0];
        $yoy  = ($prev['revenue'] ?? 0) > 0 ? round(($cur['revenue'] - $prev['revenue']) / $prev['revenue'] * 100, 1) : null;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <strong><?= h($data['sales_rep']) ?></strong>
                <span class="text-muted small ms-2"><?= $cur['case_count'] ?>件</span>
            </span>
            <span>
                <span class="fw-bold" style="color:#059669"><?= number_format($cur['revenue']) ?></span>
                <span class="text-muted small ms-1">粗利 <?= number_format($cur['profit'] ?? 0) ?></span>
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
                        <td style="padding-left:.75rem">合計売上</td>
                        <td class="text-end amount-positive fw-bold" style="padding-right:.75rem"><?= $cur['revenue'] ? number_format($cur['revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= ($prev['revenue'] ?? 0) ? number_format($prev['revenue']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span class="badge bg-info" style="font-size:.65rem">常勤売上</span></td>
                        <td class="text-end small" style="padding-right:.75rem"><?= ($cur['regular_revenue'] ?? 0) ? number_format($cur['regular_revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span class="badge" style="background:#8b5cf6;font-size:.65rem">イベント売上</span></td>
                        <td class="text-end small" style="padding-right:.75rem"><?= ($cur['event_revenue'] ?? 0) ? number_format($cur['event_revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">-</td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">粗利</td>
                        <td class="text-end <?= ($cur['profit'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative' ?>" style="padding-right:.75rem"><?= ($cur['profit'] ?? 0) ? number_format($cur['profit']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= ($prev['profit'] ?? 0) ? number_format($prev['profit']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">案件数</td>
                        <td class="text-end" style="padding-right:.75rem"><?= $cur['case_count'] ?: '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= ($prev['case_count'] ?? 0) ?: '-' ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
