<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '会社別請求書';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// If no year/month specified in URL, find latest month with data
if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $latestRow = getDB()->prepare("SELECT invoice_year, invoice_month FROM sales_company_invoices WHERE company_id = ? ORDER BY invoice_year DESC, invoice_month DESC LIMIT 1");
    $latestRow->execute([$cid]);
    $latest = $latestRow->fetch();
    if ($latest) {
        $year = (int)$latest['invoice_year'];
        $month = (int)$latest['invoice_month'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $_POST['invoice_year'] = $year;
        $_POST['invoice_month'] = $month;
        saveCompanyInvoice($cid, $_POST);
        redirect(BASE_PATH . '/admin/sales_company_invoices.php?year='.$year.'&month='.$month.'&msg=saved');
    }
    if ($action === 'generate') {
        $count = generateCompanyInvoices($cid, $year, $month);
        redirect(BASE_PATH . '/admin/sales_company_invoices.php?year='.$year.'&month='.$month.'&msg=generated&count='.$count);
    }
    if ($action === 'delete') {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM sales_company_invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([(int)$_POST['id'], $cid]);
        redirect(BASE_PATH . '/admin/sales_company_invoices.php?year='.$year.'&month='.$month.'&msg=deleted');
    }
}

$invoices = getCompanyInvoices($cid, $year, $month);
$totalBase = array_sum(array_column($invoices, 'base_revenue'));
$totalExtra = array_sum(array_column($invoices, 'extra_revenue'));
$totalAll = array_sum(array_column($invoices, 'total_revenue'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-file-earmark-text me-2"></i>会社別請求書</h1>
                <p><?= $year ?>年<?= $month ?>月　合計: <strong style="color:#059669"><?= number_format($totalAll) ?>円</strong></p>
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
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="generate">
                    <button class="btn btn-warning btn-sm" onclick="return confirm('案件データから自動生成しますか？既存データは上書きされます。')"><i class="bi bi-lightning"></i> 自動生成</button>
                </form>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ciModal" onclick="clearCiModal()"><i class="bi bi-plus"></i> 追加</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php if ($_GET['msg'] === 'generated'): ?>
            <?= (int)($_GET['count'] ?? 0) ?>件の請求書を自動生成しました
        <?php else: ?>
            <?= $_GET['msg'] === 'saved' ? '保存しました' : '削除しました' ?>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>クライアント名</th>
                            <th class="text-end">基本売上</th>
                            <th class="text-end">追加売上</th>
                            <th class="text-end fw-bold">合計</th>
                            <th>備考</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-medium"><?= h($inv['client_name']) ?></td>
                            <td class="text-end"><?= number_format($inv['base_revenue']) ?></td>
                            <td class="text-end"><?= number_format($inv['extra_revenue']) ?></td>
                            <td class="text-end fw-bold" style="color:#059669"><?= number_format($inv['total_revenue']) ?></td>
                            <td class="small"><?= h($inv['note'] ?? '') ?></td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:.65rem" onclick='editCi(<?= json_encode($inv) ?>)'><i class="bi bi-pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.65rem"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">請求書データがありません。「自動生成」で案件データから作成できます。</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($invoices)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td>合計</td>
                            <td class="text-end"><?= number_format($totalBase) ?></td>
                            <td class="text-end"><?= number_format($totalExtra) ?></td>
                            <td class="text-end" style="color:#059669"><?= number_format($totalAll) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 会社別請求書モーダル -->
<div class="modal fade" id="ciModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="ciId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text me-1"></i>会社別請求書</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">クライアント名</label><input type="text" name="client_name" id="ciName" class="form-control form-control-sm" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label">基本売上</label><input type="number" name="base_revenue" id="ciBase" class="form-control form-control-sm" min="0" value="0" onchange="calcCiTotal()"></div>
                        <div class="col-md-4"><label class="form-label">追加売上</label><input type="number" name="extra_revenue" id="ciExtra" class="form-control form-control-sm" min="0" value="0" onchange="calcCiTotal()"></div>
                        <div class="col-md-4"><label class="form-label fw-bold">合計</label><input type="number" name="total_revenue" id="ciTotal" class="form-control form-control-sm" min="0" value="0"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">備考</label><textarea name="note" id="ciNote" class="form-control form-control-sm" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary btn-sm">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calcCiTotal() {
    const b = parseInt(document.getElementById('ciBase').value) || 0;
    const e = parseInt(document.getElementById('ciExtra').value) || 0;
    document.getElementById('ciTotal').value = b + e;
}
function clearCiModal() {
    document.getElementById('ciId').value = '';
    document.getElementById('ciName').value = '';
    document.getElementById('ciBase').value = '0';
    document.getElementById('ciExtra').value = '0';
    document.getElementById('ciTotal').value = '0';
    document.getElementById('ciNote').value = '';
}
function editCi(inv) {
    document.getElementById('ciId').value = inv.id;
    document.getElementById('ciName').value = inv.client_name;
    document.getElementById('ciBase').value = inv.base_revenue;
    document.getElementById('ciExtra').value = inv.extra_revenue;
    document.getElementById('ciTotal').value = inv.total_revenue;
    document.getElementById('ciNote').value = inv.note || '';
    new bootstrap.Modal(document.getElementById('ciModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
