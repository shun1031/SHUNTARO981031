<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '取引先別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$prevYear = $year - 1;

$empFilter = getEmployeeNameFilter();
$report = getSalesClientReport($cid, $year, $empFilter);
$prevReport = getSalesClientReport($cid, $prevYear, $empFilter);

// 前年マップ (client_idベースで比較)
$prevByClient = [];
foreach ($prevReport as $clientId => $pr) {
    $prevByClient[$clientId] = $pr;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-building me-2"></i>取引先別売上レポート</h1>
                <p><?= $year ?>年 (前年<?= $prevYear ?>年比較)</p>
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

    <?php $rank = 0; foreach ($report as $clientId => $data): $rank++; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <strong><?= h($data['client_name']) ?></strong>
                <span class="text-muted small ms-2"><?= $data['total_cases'] ?>件</span>
            </span>
            <span>
                <span class="fw-bold" style="color:#059669"><?= number_format($data['total_revenue']) ?></span>
                <span class="text-muted small ms-1">粗利 <?= number_format($data['total_profit']) ?></span>
                <?php
                $prevRev = $prevByClient[$clientId]['total_revenue'] ?? 0;
                if ($prevRev > 0):
                    $yoy = round(($data['total_revenue'] - $prevRev) / $prevRev * 100, 1);
                ?>
                <span class="kpi-badge <?= $yoy >= 0 ? 'kpi-up' : 'kpi-down' ?> ms-2">
                    <i class="bi bi-arrow-<?= $yoy >= 0 ? 'up' : 'down' ?>"></i> <?= $yoy ?>%
                </span>
                <?php endif; ?>
            </span>
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
                            <th class="text-center total-col">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="row-label">売上</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['revenue'] ?? 0; ?>
                            <td class="<?= $v > 0 ? 'amount-positive' : '' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive"><?= number_format($data['total_revenue']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">粗利</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['profit'] ?? 0; ?>
                            <td class="<?= $v >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive"><?= number_format($data['total_profit']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">件数</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['case_count'] ?? 0; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $data['total_cases'] ?></td>
                        </tr>
                        <?php
                        $prevData = $prevByClient[$clientId] ?? null;
                        if ($prevData):
                        ?>
                        <tr style="background:#f9fafb">
                            <td class="row-label text-muted">前年売上</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $prevData['months'][$m]['revenue'] ?? 0; ?>
                            <td class="text-muted small"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col text-muted small"><?= number_format($prevData['total_revenue']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($report)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-building fs-1 d-block mb-2"></i>取引先データがありません</div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
