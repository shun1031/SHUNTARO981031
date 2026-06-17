<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = '売上目標設定';
$extraCss = ['sales.css'];
$db  = getDB();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/admin/index.php'); }

$year = (int)($_GET['year'] ?? date('Y'));
$msg = '';

// POST: 目標更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $y = (int)$_POST['year'];
    for ($m = 1; $m <= 12; $m++) {
        foreach (['total', 'regular', 'event'] as $type) {
            $rev = (int)str_replace(',', '', $_POST["rev_{$type}_{$m}"] ?? 0);
            $prof = (int)str_replace(',', '', $_POST["prof_{$type}_{$m}"] ?? 0);
            if ($rev > 0 || $prof > 0) {
                upsertSalesTarget($cid, $y, $m, $type, $rev, $prof);
            }
        }
    }
    $msg = '目標を保存しました';
    redirect(BASE_PATH . '/admin/sales_targets.php?year=' . $y . '&msg=' . urlencode($msg));
}

if (!empty($_GET['msg'])) $msg = $_GET['msg'];

$targets = getSalesTargets($cid, $year);
$annual = getSalesAnnualSummary($cid, $year);

$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-bullseye me-2"></i>売上目標設定</h1>
                <p><?= $year ?>年度の月別売上目標</p>
            </div>
            <div class="d-flex gap-2">
                <select onchange="location.href='?year='+this.value" class="form-select form-select-sm" style="width:120px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-1"></i><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="year" value="<?= $year ?>">

        <?php foreach (['total' => '合計', 'regular' => '常勤', 'event' => 'イベント'] as $type => $label): ?>
        <div class="card mb-4">
            <div class="card-header fw-bold">
                <i class="bi bi-<?= $type === 'total' ? 'graph-up-arrow' : ($type === 'regular' ? 'person-workspace' : 'calendar-event') ?> me-1"></i>
                <?= $label ?>目標
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
                                <th class="text-center total-col">年合計</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="row-label">売上目標</td>
                                <?php $yearTotal = 0; ?>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $val = (int)($targets[$m][$type]['revenue_target'] ?? 0);
                                    $yearTotal += $val;
                                ?>
                                <td><input type="text" name="rev_<?= $type ?>_<?= $m ?>" class="form-control form-control-sm text-end" style="min-width:90px" value="<?= $val ? number_format($val) : '' ?>" onfocus="this.value=this.value.replace(/,/g,'')" onblur="if(this.value)this.value=Number(this.value).toLocaleString()"></td>
                                <?php endfor; ?>
                                <td class="total-col fw-bold text-end"><?= number_format($yearTotal) ?></td>
                            </tr>
                            <tr>
                                <td class="row-label">粗利目標</td>
                                <?php $yearPTotal = 0; ?>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $val = (int)($targets[$m][$type]['profit_target'] ?? 0);
                                    $yearPTotal += $val;
                                ?>
                                <td><input type="text" name="prof_<?= $type ?>_<?= $m ?>" class="form-control form-control-sm text-end" style="min-width:90px" value="<?= $val ? number_format($val) : '' ?>" onfocus="this.value=this.value.replace(/,/g,'')" onblur="if(this.value)this.value=Number(this.value).toLocaleString()"></td>
                                <?php endfor; ?>
                                <td class="total-col fw-bold text-end"><?= number_format($yearPTotal) ?></td>
                            </tr>
                            <!-- 実績（参考）-->
                            <tr style="background:#f9fafb">
                                <td class="row-label text-muted">売上実績</td>
                                <?php $yearActual = 0; ?>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $act = $annual[$m]['total']['revenue'] ?? 0;
                                    if ($type === 'regular') $act = $annual[$m]['regular']['revenue'] ?? 0;
                                    if ($type === 'event') $act = $annual[$m]['event']['revenue'] ?? 0;
                                    $yearActual += $act;
                                ?>
                                <td class="text-end text-muted small"><?= $act ? number_format($act) : '-' ?></td>
                                <?php endfor; ?>
                                <td class="total-col text-end text-muted small"><?= number_format($yearActual) ?></td>
                            </tr>
                            <tr style="background:#f9fafb">
                                <td class="row-label text-muted">達成率</td>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $tgt = (int)($targets[$m][$type]['revenue_target'] ?? 0);
                                    $act = $annual[$m][$type === 'total' ? 'total' : $type]['revenue'] ?? 0;
                                    $rate = $tgt > 0 ? round($act / $tgt * 100, 1) : 0;
                                ?>
                                <td class="text-end small <?= $rate >= 100 ? 'amount-positive' : ($rate >= 80 ? '' : 'amount-negative') ?>"><?= $tgt > 0 ? $rate . '%' : '-' ?></td>
                                <?php endfor; ?>
                                <td class="total-col text-end small"><?= $yearTotal > 0 ? round($yearActual / $yearTotal * 100, 1) . '%' : '-' ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="text-end mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>目標を保存</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
