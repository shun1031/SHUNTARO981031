<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$prevYear = $year - 1;

$empFilter = getEmployeeNameFilter();
$report = getSalesRepReport($cid, $year, $empFilter);
$prevReport = getSalesRepReport($cid, $prevYear, $empFilter);

$prevByRep = [];
foreach ($prevReport as $rep => $pr) {
    $prevByRep[$rep] = $pr;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-badge me-2"></i>担当者別売上レポート</h1>
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

    <?php $rank = 0; foreach ($report as $rep => $data): $rank++; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                <strong><?= h($data['sales_rep']) ?></strong>
                <span class="text-muted small ms-2"><?= $data['total_cases'] ?>件</span>
            </span>
            <span>
                <span class="fw-bold" style="color:#059669"><?= number_format($data['total_revenue']) ?></span>
                <span class="text-muted small ms-1">粗利 <?= number_format($data['total_profit']) ?></span>
                <span class="text-muted small ms-1">(<?= $data['total_revenue'] > 0 ? round($data['total_profit'] / $data['total_revenue'] * 100, 1) : 0 ?>%)</span>
                <?php
                $prevRev = $prevByRep[$rep]['total_revenue'] ?? 0;
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
                            <td class="row-label fw-bold">合計売上</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['revenue'] ?? 0; ?>
                            <td class="<?= $v > 0 ? 'amount-positive' : '' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive fw-bold"><?= number_format($data['total_revenue']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label"><span class="badge bg-info" style="font-size:.65rem">常勤売上</span></td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['regular_revenue'] ?? 0; ?>
                            <td class="small"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col small"><?= number_format($data['regular_revenue']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label"><span class="badge" style="background:#8b5cf6;font-size:.65rem">イベント売上</span></td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['event_revenue'] ?? 0; ?>
                            <td class="small"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col small"><?= number_format($data['event_revenue']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">粗利</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['profit'] ?? 0; ?>
                            <td class="<?= $v >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= $v ? number_format($v) : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col amount-positive"><?= number_format($data['total_profit']) ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">案件数</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['case_count'] ?? 0; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $data['total_cases'] ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">新規取引数</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['new_transactions'] ?? 0; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $data['total_new_transactions'] ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">商談数</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['negotiations_count'] ?? 0; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $data['total_negotiations'] ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">成約数</td>
                            <?php for ($m = 1; $m <= 12; $m++): $v = $data['months'][$m]['contracts_count'] ?? 0; ?>
                            <td><?= $v ?: '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col"><?= $data['total_contracts'] ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="row-label">成約率</td>
                            <?php for ($m = 1; $m <= 12; $m++):
                                $neg = $data['months'][$m]['negotiations_count'] ?? 0;
                                $con = $data['months'][$m]['contracts_count'] ?? 0;
                                $rate = $neg > 0 ? round($con / $neg * 100, 1) : null;
                            ?>
                            <td class="small"><?= $rate !== null ? $rate . '%' : '-' ?></td>
                            <?php endfor; ?>
                            <td class="total-col small"><?= $data['total_negotiations'] > 0 ? round($data['total_contracts'] / $data['total_negotiations'] * 100, 1) . '%' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($report)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-person-badge fs-1 d-block mb-2"></i>担当者データがありません</div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
