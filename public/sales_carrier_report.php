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
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY++; }

$db = getDB();

// 今月データ
$stmt = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier,
           COALESCE(NULLIF(case_type,''), 'regular') AS case_type,
           COUNT(*) AS case_count,
           COALESCE(SUM(revenue), 0) AS revenue,
           COALESCE(SUM(gross_profit), 0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND case_year = ? AND case_month = ? AND status = 'confirmed'
    GROUP BY carrier, case_type
");
$stmt->execute([$cid, $year, $month]);

function buildCarrierMap(array $rows): array {
    $map = [];
    foreach ($rows as $r) {
        $c    = $r['carrier'];
        $type = in_array($r['case_type'], ['regular','event']) ? $r['case_type'] : 'regular';
        if (!isset($map[$c])) {
            $map[$c] = ['total_revenue'=>0,'total_profit'=>0,'total_cases'=>0,
                        'regular'=>['revenue'=>0,'profit'=>0,'cases'=>0],
                        'event'  =>['revenue'=>0,'profit'=>0,'cases'=>0]];
        }
        $map[$c][$type]['revenue'] += (int)$r['revenue'];
        $map[$c][$type]['profit']  += (int)$r['profit'];
        $map[$c][$type]['cases']   += (int)$r['case_count'];
        $map[$c]['total_revenue']  += (int)$r['revenue'];
        $map[$c]['total_profit']   += (int)$r['profit'];
        $map[$c]['total_cases']    += (int)$r['case_count'];
    }
    uasort($map, fn($a,$b) => $b['total_revenue'] <=> $a['total_revenue']);
    return $map;
}

$monthlyReport = buildCarrierMap($stmt->fetchAll());

// 年間データ
$stmtY = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier,
           COALESCE(NULLIF(case_type,''), 'regular') AS case_type,
           COUNT(*) AS case_count,
           COALESCE(SUM(revenue), 0) AS revenue,
           COALESCE(SUM(gross_profit), 0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND case_year = ? AND status = 'confirmed'
    GROUP BY carrier, case_type
");
$stmtY->execute([$cid, $year]);
$annualReport = buildCarrierMap($stmtY->fetchAll());

// 前年同月
$stmtPrev = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier, COALESCE(SUM(revenue),0) AS revenue
    FROM sales_cases WHERE company_id=? AND case_year=? AND case_month=? AND status='confirmed' GROUP BY carrier
");
$stmtPrev->execute([$cid, $year-1, $month]);
$prevMonthRev = [];
foreach ($stmtPrev->fetchAll() as $r) $prevMonthRev[$r['carrier']] = (int)$r['revenue'];

// 前年年間
$stmtPrevY = $db->prepare("
    SELECT COALESCE(NULLIF(carrier,''), '未設定') AS carrier, COALESCE(SUM(revenue),0) AS revenue
    FROM sales_cases WHERE company_id=? AND case_year=? AND status='confirmed' GROUP BY carrier
");
$stmtPrevY->execute([$cid, $year-1]);
$prevAnnualRev = [];
foreach ($stmtPrevY->fetchAll() as $r) $prevAnnualRev[$r['carrier']] = (int)$r['revenue'];

require_once __DIR__ . '/../includes/header.php';

function renderCarrierCard(string $carrier, array $data, string $yoyText): string {
    $margin = $data['total_revenue'] > 0 ? round($data['total_profit'] / $data['total_revenue'] * 100, 1) : 0;
    $reg = $data['regular']; $evt = $data['event'];
    ob_start(); ?>
        <div class="card-header" style="padding-bottom:.5rem">
            <div class="d-flex justify-content-between align-items-center">
                <div class="fw-bold fs-6"><?= h($carrier) ?> <span class="text-muted small fw-normal ms-1"><?= $data['total_cases'] ?>件</span></div>
                <div>
                    <span class="fw-bold" style="color:#059669"><?= number_format($data['total_revenue']) ?></span>
                    <span class="text-muted small ms-2">粗利 <?= number_format($data['total_profit']) ?></span>
                </div>
            </div>
            <div style="margin-top:.35rem">
                <span class="badge" style="background:#d1fae5;color:#059669;font-size:.72rem">粗利率 <?= $margin ?>%</span>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td style="padding-left:.75rem">売上合計</td><td class="text-end fw-bold" style="padding-right:.75rem"><?= number_format($data['total_revenue']) ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#3b82f6;font-size:.8rem">●</span> 常勤売上</td><td class="text-end" style="padding-right:.75rem"><?= $reg['revenue'] ? number_format($reg['revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#8b5cf6;font-size:.8rem">●</span> イベント売上</td><td class="text-end" style="padding-right:.75rem"><?= $evt['revenue'] ? number_format($evt['revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">粗利</td><td class="text-end" style="padding-right:.75rem"><?= number_format($data['total_profit']) ?></td></tr>
                    <tr><td style="padding-left:.75rem">件数</td><td class="text-end" style="padding-right:.75rem"><?= $data['total_cases'] ?></td></tr>
                    <tr style="background:#f9fafb"><td colspan="2" class="text-muted small" style="padding-left:.75rem;padding-right:.75rem"><?= $yoyText ?></td></tr>
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
                <h1><i class="bi bi-reception-4 me-2"></i>キャリア別売上レポート</h1>
                <p class="text-muted mb-0"><?= $year ?>年<?= $month ?>月</p>
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
            <?php if (empty($monthlyReport)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($monthlyReport as $carrier => $data): $rank++; ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderCarrierCard($carrier, $data,
                            $year.'年'.$month.'月（前年同月：'.($prevMonthRev[$carrier] ?? 0 > 0 ? round(($data['total_revenue'] - ($prevMonthRev[$carrier]??0)) / ($prevMonthRev[$carrier]??1) * 100, 1).'%' : '-').'）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 年間総合ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">年間総合ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年</small></h5>
            <?php if (empty($annualReport)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($annualReport as $carrier => $data): $rank++; ?>
            <?php $yoyA = ($prevAnnualRev[$carrier] ?? 0) > 0 ? round(($data['total_revenue'] - $prevAnnualRev[$carrier]) / $prevAnnualRev[$carrier] * 100, 1).'%' : '-'; ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderCarrierCard($carrier, $data, $year.'年度合計（前年比：'.$yoyA.'）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
