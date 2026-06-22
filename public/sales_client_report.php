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
$yearlyData = getSalesClientReport($cid, $year,     $empFilter);
$prevYearly = getSalesClientReport($cid, $year - 1, $empFilter);

// 月間
$monthlyData = $yearlyData;
uasort($monthlyData, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});
$monthlyData = array_filter($monthlyData, fn($d) => ($d['months'][$month]['revenue'] ?? 0) > 0 || ($d['months'][$month]['case_count'] ?? 0) > 0);

// 年間
uasort($yearlyData, fn($a,$b) => $b['total_revenue'] <=> $a['total_revenue']);
$yearlyData = array_filter($yearlyData, fn($d) => $d['total_revenue'] > 0);

require_once __DIR__ . '/../includes/header.php';

function renderClientCard(string $clientName, int $revenue, int $profit, int $cases, int $prevRev, int $prevProfit, int $prevCases, string $colHeader): string {
    $yoyRev  = $prevRev    > 0 ? number_format($prevRev)    : '-';
    $yoyPro  = $prevProfit > 0 ? number_format($prevProfit) : '-';
    $yoyCnt  = $prevCases  > 0 ? $prevCases                 : '-';
    ob_start(); ?>
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div class="fw-bold fs-6"><?= h($clientName) ?> <span class="text-muted small fw-normal ms-1"><?= $cases ?>件</span></div>
                <div>
                    <span class="fw-bold" style="color:#059669"><?= number_format($revenue) ?></span>
                    <span class="text-muted small ms-2">粗利 <?= number_format($profit) ?></span>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="padding-left:.75rem"></th>
                        <th class="text-end" colspan="2" style="padding-right:.75rem;font-size:.75rem;color:#6b7280"><?= $colHeader ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding-left:.75rem"><i class="bi bi-person-standing-dress" style="color:#059669;margin-right:.4rem"></i>売上</td>
                        <td class="text-end fw-bold amount-positive"><?= $revenue ? number_format($revenue) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyRev ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><i class="bi bi-cash-coin" style="color:#f59e0b;margin-right:.4rem"></i>粗利</td>
                        <td class="text-end amount-positive"><?= $profit ? number_format($profit) : '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyPro ?></td>
                    </tr>
                    <tr>
                        <td style="padding-left:.75rem"><i class="bi bi-person" style="color:#3b82f6;margin-right:.4rem"></i>件数</td>
                        <td class="text-end"><?= $cases ?: '-' ?></td>
                        <td class="text-end text-muted small" style="padding-right:.75rem"><?= $yoyCnt ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php return ob_get_clean();
}
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

    <div class="row g-4">
        <!-- 月間ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">月間ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年<?= $month ?>月</small></h5>
            <?php if (empty($monthlyData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($monthlyData as $clientId => $data): $rank++;
                $cur  = $data['months'][$month] ?? ['revenue'=>0,'profit'=>0,'case_count'=>0];
                $prev = $prevYearly[$clientId]['months'][$month] ?? ['revenue'=>0,'profit'=>0,'case_count'=>0];
                $colH = $year.'年'.$month.'月（前年同月比）';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderClientCard($data['client_name'], $cur['revenue'], $cur['profit'], $cur['case_count'], $prev['revenue'], $prev['profit'], $prev['case_count'], $colH) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 年間総合ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">年間総合ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年</small></h5>
            <?php if (empty($yearlyData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($yearlyData as $clientId => $data): $rank++;
                $prev = $prevYearly[$clientId] ?? ['total_revenue'=>0,'total_profit'=>0,'total_cases'=>0];
                $colH = $year.'年度合計（前年比）';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderClientCard($data['client_name'], $data['total_revenue'], $data['total_profit'], $data['total_cases'], $prev['total_revenue'], $prev['total_profit'], $prev['total_cases'], $colH) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
