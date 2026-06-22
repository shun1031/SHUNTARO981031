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
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY++; }

$empFilter  = getEmployeeNameFilter();
$report     = getSalesRepReport($cid, $year,     $empFilter);
$prevReport = getSalesRepReport($cid, $year - 1, $empFilter);

$prevByRep = [];
foreach ($prevReport as $rep => $pr) { $prevByRep[$rep] = $pr; }

uasort($report, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});
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
                <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
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
        $cur     = $data['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0, 'regular_revenue' => 0, 'event_revenue' => 0];
        $prev    = $prevByRep[$rep]['months'][$month] ?? ['revenue' => 0];
        $yoyRev  = $prev['revenue'] ?? 0;
        $yoyText = $yoyRev > 0
            ? round(($cur['revenue'] - $yoyRev) / $yoyRev * 100, 1) . '%'
            : '-';
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    <span class="fw-bold fs-6"><?= h($data['sales_rep']) ?></span>
                    <span class="text-muted small"><?= $cur['case_count'] ?>件</span>
                </div>
                <div>
                    <span class="fw-bold" style="color:#059669"><?= number_format($cur['revenue']) ?></span>
                    <span class="text-muted small ms-2">粗利 <?= number_format($cur['profit'] ?? 0) ?></span>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <td style="padding-left:.75rem">合計売上</td>
                        <td class="text-end fw-bold" style="padding-right:.75rem"><?= $cur['revenue'] ? number_format($cur['revenue']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span style="color:#3b82f6;font-size:.8rem">●</span> 常勤売上</td>
                        <td class="text-end" style="padding-right:.75rem"><?= ($cur['regular_revenue'] ?? 0) ? number_format($cur['regular_revenue']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><span style="color:#8b5cf6;font-size:.8rem">●</span> イベント売上</td>
                        <td class="text-end" style="padding-right:.75rem"><?= ($cur['event_revenue'] ?? 0) ? number_format($cur['event_revenue']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">粗利</td>
                        <td class="text-end" style="padding-right:.75rem"><?= ($cur['profit'] ?? 0) ? number_format($cur['profit']) : '-' ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">案件数</td>
                        <td class="text-end" style="padding-right:.75rem"><?= $cur['case_count'] ?: '-' ?></td>
                    </tr>
                    <tr style="background:#f9fafb">
                        <td colspan="2" class="text-muted small" style="padding-left:.75rem;padding-right:.75rem">
                            <?= $year ?>年<?= $month ?>月（前年同月：<?= $yoyText ?>）
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
