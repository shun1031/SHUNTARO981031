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
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY++; }

$empFilter  = getEmployeeNameFilter();
$report     = getSalesClientReport($cid, $year,     $empFilter);
$prevReport = getSalesClientReport($cid, $year - 1, $empFilter);

$prevByClient = [];
foreach ($prevReport as $clientId => $pr) { $prevByClient[$clientId] = $pr; }

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
                <h1><i class="bi bi-building me-2"></i>取引先別売上レポート</h1>
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
        <i class="bi bi-building fs-1 d-block mb-2"></i><?= $year ?>年<?= $month ?>月のデータがありません
    </div></div>
    <?php endif; ?>

    <?php $rank = 0; foreach ($report as $clientId => $data): $rank++;
        $cur    = $data['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0];
        $prev   = $prevByClient[$clientId]['months'][$month] ?? ['revenue' => 0, 'profit' => 0, 'case_count' => 0];
        $yoyRev = $prev['revenue'] ?? 0;
        $yoyRevText   = $yoyRev > 0 ? number_format($yoyRev) : '-';
        $yoyProfText  = ($prev['profit'] ?? 0) > 0 ? number_format($prev['profit']) : '-';
        $yoyCntText   = ($prev['case_count'] ?? 0) > 0 ? $prev['case_count'] : '-';
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    <span class="fw-bold fs-6"><?= h($data['client_name']) ?></span>
                    <span class="text-muted small"><?= $cur['case_count'] ?>件</span>
                </div>
                <div>
                    <span class="fw-bold" style="color:#059669"><?= number_format($cur['revenue']) ?></span>
                    <span class="text-muted small ms-2">粗利 <?= number_format($cur['profit']) ?></span>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="padding-left:.75rem"></th>
                        <th class="text-end" colspan="2" style="padding-right:.75rem;font-size:.78rem;color:#6b7280">
                            <?= $year ?>年<?= $month ?>月（前年同月比）
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding-left:.75rem">
                            <i class="bi bi-person-standing-dress" style="color:#059669;margin-right:.4rem"></i>売上
                        </td>
                        <td class="text-end fw-bold amount-positive"><?= $cur['revenue'] ? number_format($cur['revenue']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyRevText ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">
                            <i class="bi bi-cash-coin" style="color:#f59e0b;margin-right:.4rem"></i>粗利
                        </td>
                        <td class="text-end amount-positive"><?= $cur['profit'] ? number_format($cur['profit']) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyProfText ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem">
                            <i class="bi bi-person" style="color:#3b82f6;margin-right:.4rem"></i>件数
                        </td>
                        <td class="text-end"><?= $cur['case_count'] ?: '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyCntText ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
