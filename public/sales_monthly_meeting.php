<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '月末総会';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
if (isset($_GET['month'])) {
    $month = (int)$_GET['month'];
} else {
    $month = (int)date('n');
    $db = getDB();
    $latestStmt = $db->prepare("SELECT MAX(case_month) FROM sales_cases WHERE company_id = ? AND case_year = ? AND status = 'confirmed'");
    $latestStmt->execute([$cid, $year]);
    $latestMonth = $latestStmt->fetchColumn();
    if ($latestMonth && (int)$latestMonth < $month) {
        $month = (int)$latestMonth;
    }
}

$empFilter = getEmployeeNameFilter();
$meeting = getMonthlyMeetingData($cid, $year, $month, $empFilter);
$personal = getPersonalSalesBreakdown($cid, $year, $month, $empFilter);
$totalRevenue = $meeting['overall']['revenue'] ?? 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-megaphone me-2"></i>月末総会サマリー</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex gap-2">
                <select onchange="location.href='?year='+this.value+'&month=<?= $month ?>'" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month='+this.value" class="form-select form-select-sm" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- KPIカード -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">目標達成率</div>
                    <div class="fs-2 fw-bold <?= $meeting['achievement'] >= 100 ? 'text-success' : ($meeting['achievement'] >= 80 ? 'text-warning' : 'text-danger') ?>">
                        <?= $meeting['achievement'] ?>%
                    </div>
                    <div class="progress mt-2" style="height:8px">
                        <div class="progress-bar <?= $meeting['achievement'] >= 100 ? 'bg-success' : ($meeting['achievement'] >= 80 ? 'bg-warning' : 'bg-danger') ?>"
                             style="width:<?= min($meeting['achievement'], 100) ?>%"></div>
                    </div>
                    <div class="mt-2 small text-muted">
                        目標: <?= number_format($meeting['target']) ?> / 実績: <?= number_format($meeting['overall']['revenue'] ?? 0) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">全体売上</div>
                    <div class="fs-3 fw-bold" style="color:#059669"><?= number_format($meeting['overall']['revenue'] ?? 0) ?></div>
                    <div class="mt-1 small">
                        粗利: <strong><?= number_format($meeting['overall']['profit'] ?? 0) ?></strong>
                        <span class="ms-1">(<?= $meeting['overall']['margin'] ?? 0 ?>%)</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">案件数 / 稼働日数</div>
                    <div class="fs-3 fw-bold"><?= $meeting['overall']['case_count'] ?? 0 ?><small class="text-muted fs-6">件</small></div>
                    <div class="mt-1 small">
                        稼働: <strong><?= $meeting['overall']['days'] ?? 0 ?>日</strong>
                        <span class="ms-1">日当平均: <?= number_format($meeting['overall']['avg_daily'] ?? 0) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 全体/常勤/イベント 比較テーブル -->
    <div class="card mb-4">
        <div class="card-header"><strong><i class="bi bi-table me-1"></i>カテゴリ別比較</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>項目</th>
                        <th class="text-end">全体</th>
                        <th class="text-end">常勤</th>
                        <th class="text-end">イベント</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rows = [
                        ['売上', 'revenue', true],
                        ['原価', 'cost', true],
                        ['粗利', 'profit', true],
                        ['粗利率', 'margin', false],
                        ['案件数', 'case_count', false],
                        ['稼働日数', 'days', false],
                        ['日当平均', 'avg_daily', true],
                    ];
                    foreach ($rows as [$label, $key, $format]):
                    ?>
                    <tr>
                        <td class="fw-medium"><?= $label ?></td>
                        <?php foreach (['overall', 'regular', 'event'] as $cat):
                            $val = $meeting[$cat][$key] ?? 0;
                        ?>
                        <td class="text-end <?= $key === 'profit' && $val > 0 ? 'text-success' : '' ?>">
                            <?= $format ? number_format($val) : $val ?><?= $key === 'margin' ? '%' : '' ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- 個人別売上 -->
    <div class="card mb-3">
        <div class="card-header"><strong><i class="bi bi-people me-1"></i>個人別売上</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">順位</th>
                            <th>担当者</th>
                            <th class="text-end">売上</th>
                            <th class="text-end">原価</th>
                            <th class="text-end">粗利</th>
                            <th class="text-end">粗利率</th>
                            <th class="text-end">EV売上</th>
                            <th class="text-end">常勤売上</th>
                            <th class="text-end">件数</th>
                            <th style="width:200px">売上シェア</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 0; foreach ($personal as $p): $rank++;
                            $share = $totalRevenue > 0 ? round($p['revenue'] / $totalRevenue * 100, 1) : 0;
                            $rankClass = $rank <= 3 ? ['text-bg-warning','text-bg-secondary','text-bg-info'][$rank-1] : 'text-bg-light';
                        ?>
                        <tr>
                            <td><span class="badge <?= $rankClass ?>" style="font-size:.7rem"><?= $rank ?></span></td>
                            <td class="fw-medium"><?= h($p['sales_rep']) ?></td>
                            <td class="text-end fw-bold" style="color:#059669"><?= number_format($p['revenue']) ?></td>
                            <td class="text-end"><?= number_format($p['cost']) ?></td>
                            <td class="text-end <?= $p['profit'] > 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($p['profit']) ?></td>
                            <td class="text-end <?= $p['margin'] < 20 ? 'text-danger' : '' ?>"><?= $p['margin'] ?>%</td>
                            <td class="text-end"><span class="badge" style="background:#8b5cf6;font-size:.65rem"><?= number_format($p['event_revenue']) ?></span></td>
                            <td class="text-end"><span class="badge bg-info" style="font-size:.65rem"><?= number_format($p['regular_revenue']) ?></span></td>
                            <td class="text-end"><?= $p['case_count'] ?></td>
                            <td>
                                <div class="progress" style="height:16px">
                                    <div class="progress-bar bg-success" style="width:<?= $share ?>%;font-size:.65rem"><?= $share ?>%</div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($personal)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">データがありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
