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

$empFilter   = getEmployeeNameFilter();
$yearlyData  = getSalesRepReport($cid, $year,     $empFilter);
$prevYearly  = getSalesRepReport($cid, $year - 1, $empFilter);

// 月間: 直営業を分離 → 残りを売上順 → 直営業を末尾
$monthlyData = $yearlyData;
uasort($monthlyData, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});
// 今月0円でも年間売上がある（または年間案件数がある）人は表示する
$monthlyData    = array_filter($monthlyData, fn($d) =>
    ($d['months'][$month]['revenue'] ?? 0) > 0 ||
    ($d['months'][$month]['case_count'] ?? 0) > 0 ||
    $d['total_revenue'] > 0 ||
    $d['total_cases'] > 0
);
$monthlyDirect  = isset($monthlyData['直営業']) ? ['直営業' => $monthlyData['直営業']] : [];
$monthlyData    = array_filter($monthlyData, fn($d) => $d['sales_rep'] !== '直営業');

// 年間: 直営業を分離 → 残りを売上順 → 直営業を末尾
uasort($yearlyData, fn($a,$b) => $b['total_revenue'] <=> $a['total_revenue']);
$yearlyData    = array_filter($yearlyData, fn($d) => $d['total_revenue'] > 0);
$yearlyDirect  = isset($yearlyData['直営業']) ? ['直営業' => $yearlyData['直営業']] : [];
$yearlyData    = array_filter($yearlyData, fn($d) => $d['sales_rep'] !== '直営業');

// 強制表示メンバー（売上0でも必ずランキングに表示）
$forcedMembers = ['山根脩平'];
$emptyMonthEntry = ['revenue'=>0,'profit'=>0,'case_count'=>0,'regular_revenue'=>0,'event_revenue'=>0];
$emptyYearEntry  = ['sales_rep'=>'','total_revenue'=>0,'total_profit'=>0,'total_cases'=>0,'regular_revenue'=>0,'event_revenue'=>0,'months'=>[]];
foreach ($forcedMembers as $fm) {
    // 月間にいなければ0で追加
    $inMonthly = false;
    foreach ($monthlyData as $d) { if ($d['sales_rep'] === $fm) { $inMonthly = true; break; } }
    if (!$inMonthly) {
        $stub = $emptyYearEntry; $stub['sales_rep'] = $fm;
        $monthlyData[$fm] = $stub;
    }
    // 年間にいなければ0で追加
    $inYearly = false;
    foreach ($yearlyData as $d) { if ($d['sales_rep'] === $fm) { $inYearly = true; break; } }
    if (!$inYearly) {
        $stub = $emptyYearEntry; $stub['sales_rep'] = $fm;
        $yearlyData[$fm] = $stub;
    }
}

// インセンティブ率マップ（0=なし、それ以外は割合）
$INCENTIVE_RATES = [
    '竹内陽'   => 0,
    '直営業'   => 0,
    '佐藤思杰' => 0.20,
    '近藤航'   => 0.20,
];
$INCENTIVE_DEFAULT = 0.30;

require_once __DIR__ . '/../includes/header.php';

function renderRepCard(string $repName, array $cur, string $footerText): string {
    global $INCENTIVE_RATES, $INCENTIVE_DEFAULT;
    $rate = array_key_exists($repName, $INCENTIVE_RATES)
        ? $INCENTIVE_RATES[$repName]
        : $INCENTIVE_DEFAULT;
    $profit     = (int)($cur['profit'] ?? 0);
    $revenue    = (int)($cur['revenue'] ?? 0);
    $profitRate = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;
    $incentive  = ($rate > 0 && $profit > 0) ? (int)round($profit * $rate) : null;
    ob_start(); ?>
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <div class="fw-bold fs-6"><?= h($repName) ?> <span class="text-muted small fw-normal ms-1"><?= ($cur['case_count'] ?? 0) ?>件</span></div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span><span class="text-muted small">売上</span> <span class="fw-bold" style="color:#059669"><?= number_format($revenue) ?></span></span>
                    <span class="text-muted small">粗利 <?= number_format($profit) ?></span>
                    <?php if ($profitRate !== null): ?>
                    <span class="text-muted small">粗利率 <?= number_format($profitRate, 1) ?>%</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td style="padding-left:.75rem">合計売上</td><td class="text-end fw-bold" style="padding-right:.75rem"><?= ($cur['revenue'] ?? 0) ? number_format($cur['revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#3b82f6;font-size:.8rem">●</span> 常勤売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['regular_revenue'] ?? 0) ? number_format($cur['regular_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#8b5cf6;font-size:.8rem">●</span> イベント売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['event_revenue'] ?? 0) ? number_format($cur['event_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">粗利</td><td class="text-end" style="padding-right:.75rem"><?= $profit ? number_format($profit) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">案件数</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['case_count'] ?? 0) ?: '-' ?></td></tr>
                    <?php if ($incentive !== null): ?>
                    <tr style="background:#fffbeb">
                        <td style="padding-left:.75rem;color:#d97706;font-weight:500">インセンティブ</td>
                        <td class="text-end fw-bold" style="padding-right:.75rem;color:#d97706"><?= number_format($incentive) ?></td>
                    </tr>
                    <?php elseif ($rate === 0): ?>
                    <tr style="background:#f9fafb">
                        <td style="padding-left:.75rem;color:#9ca3af;font-size:.8rem">インセンティブ</td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">なし</td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:#f9fafb"><td colspan="2" class="text-muted small" style="padding-left:.75rem;padding-right:.75rem"><?= $footerText ?></td></tr>
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

    <div class="row g-4">
        <!-- 月間ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">月間ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年<?= $month ?>月</small></h5>
            <?php if (empty($monthlyData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($monthlyData as $rep => $data): $rank++;
                $cur = $data['months'][$month] ?? [];
                $cur['revenue']         = $cur['revenue']         ?? 0;
                $cur['profit']          = $cur['profit']          ?? 0;
                $cur['case_count']      = $cur['case_count']      ?? 0;
                $cur['regular_revenue'] = $cur['regular_revenue'] ?? 0;
                $cur['event_revenue']   = $cur['event_revenue']   ?? 0;
                $prevMRev = $prevYearly[$rep]['months'][$month]['revenue'] ?? 0;
                $yoyText  = $prevMRev > 0 ? round(($cur['revenue'] - $prevMRev) / $prevMRev * 100, 1).'%' : '-';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard($data['sales_rep'], $cur, $year.'年'.$month.'月（前年同月：'.$yoyText.'）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php /* 直営業は順位なしで末尾に表示 */
            foreach ($monthlyDirect as $data):
                $cur = $data['months'][$month] ?? [];
                $cur['revenue']         = $cur['revenue']         ?? 0;
                $cur['profit']          = $cur['profit']          ?? 0;
                $cur['case_count']      = $cur['case_count']      ?? 0;
                $cur['regular_revenue'] = $cur['regular_revenue'] ?? 0;
                $cur['event_revenue']   = $cur['event_revenue']   ?? 0;
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard('直営業', $cur, $year.'年'.$month.'月（前年同月：-）') ?>
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
            <?php $rank = 0; foreach ($yearlyData as $rep => $data): $rank++;
                $annual = [
                    'revenue'         => $data['total_revenue'],
                    'profit'          => $data['total_profit'],
                    'case_count'      => $data['total_cases'],
                    'regular_revenue' => $data['regular_revenue'] ?? 0,
                    'event_revenue'   => $data['event_revenue']   ?? 0,
                ];
                $prevARev = $prevYearly[$rep]['total_revenue'] ?? 0;
                $yoyAText = $prevARev > 0 ? round(($data['total_revenue'] - $prevARev) / $prevARev * 100, 1).'%' : '-';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard($data['sales_rep'], $annual, $year.'年度合計（前年比：'.$yoyAText.'）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php /* 直営業は順位なしで末尾に表示 */
            foreach ($yearlyDirect as $data):
                $annual = [
                    'revenue'         => $data['total_revenue'],
                    'profit'          => $data['total_profit'],
                    'case_count'      => $data['total_cases'],
                    'regular_revenue' => $data['regular_revenue'] ?? 0,
                    'event_revenue'   => $data['event_revenue']   ?? 0,
                ];
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard('直営業', $annual, $year.'年度合計（前年比：-）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
