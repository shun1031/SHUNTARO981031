<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '個人別請求書';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// If no year/month specified in URL, find latest month with data
if (!isset($_GET['year']) && !isset($_GET['month'])) {
    $latestRow = getDB()->prepare("SELECT invoice_year, invoice_month FROM sales_personal_invoices WHERE company_id = ? ORDER BY invoice_year DESC, invoice_month DESC LIMIT 1");
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
        savePersonalInvoice($cid, $_POST);
        redirect(BASE_PATH . '/admin/sales_invoices.php?year='.$year.'&month='.$month.'&msg=saved');
    }
    if ($action === 'delete') {
        deletePersonalInvoice((int)$_POST['id'], $cid);
        redirect(BASE_PATH . '/admin/sales_invoices.php?year='.$year.'&month='.$month.'&msg=deleted');
    }
}

$invoices = getPersonalInvoices($cid, $year, $month);

$totalFee = array_sum(array_column($invoices, 'base_fee'));
$totalInvoice = array_sum(array_column($invoices, 'invoice_amount'));
$totalIncentive = array_sum(array_column($invoices, 'incentive_total'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-receipt me-2"></i>個人別請求書</h1>
                <p><?= $year ?>年<?= $month ?>月　請求合計: <strong style="color:#059669"><?= number_format($totalInvoice) ?>円</strong></p>
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
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#invoiceModal" onclick="clearInvModal()"><i class="bi bi-plus"></i> 請求書追加</button>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_GET['msg'] === 'saved' ? '保存しました' : '削除しました' ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.65rem">
                    <thead class="table-light">
                        <tr>
                            <th>氏名</th><th>等級</th><th>区分</th><th>業務委託先</th>
                            <th class="text-end">業務委託費</th><th class="text-end">交通費</th><th class="text-end">交通費(先方)</th>
                            <th class="text-end">シフト日数</th><th class="text-end">実稼働</th>
                            <th class="text-end">CL.ラダー</th><th class="text-end">代理店ラダー</th>
                            <th class="text-end">追加売上</th><th class="text-end">欠勤減額</th><th class="text-end">その他</th>
                            <th class="text-end">小計</th><th class="text-end">EV</th>
                            <th class="text-end">営業</th><th class="text-end">管理</th><th class="text-end">採用</th><th class="text-end">役職</th>
                            <th class="text-end">インセン計</th><th class="text-end">福利</th><th class="text-end">社保</th>
                            <th class="text-end fw-bold">請求金額</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-medium"><?= h($inv['employee_name']) ?></td>
                            <td><?= h($inv['grade'] ?? '') ?></td>
                            <td><?= h($inv['alliance_type'] ?? '') ?></td>
                            <td><?= h($inv['alliance_name'] ?? '') ?></td>
                            <td class="text-end"><?= number_format($inv['base_fee']) ?></td>
                            <td class="text-end"><?= number_format($inv['transport_cost']) ?></td>
                            <td class="text-end"><?= number_format($inv['transport_cost_client']) ?></td>
                            <td class="text-end"><?= $inv['shift_days'] ?></td>
                            <td class="text-end"><?= $inv['actual_work_days'] ?></td>
                            <td class="text-end"><?= number_format($inv['client_ladder']) ?></td>
                            <td class="text-end"><?= number_format($inv['agency_ladder']) ?></td>
                            <td class="text-end"><?= number_format($inv['extra_work_revenue']) ?></td>
                            <td class="text-end"><?= number_format($inv['absence_deduction']) ?></td>
                            <td class="text-end"><?= number_format($inv['other_charges']) ?></td>
                            <td class="text-end"><?= number_format($inv['subtotal_fee']) ?></td>
                            <td class="text-end"><?= number_format($inv['event_fee']) ?></td>
                            <td class="text-end"><?= number_format($inv['sales_incentive']) ?></td>
                            <td class="text-end"><?= number_format($inv['mgmt_incentive']) ?></td>
                            <td class="text-end"><?= number_format($inv['recruit_incentive']) ?></td>
                            <td class="text-end"><?= number_format($inv['role_allowance']) ?></td>
                            <td class="text-end"><?= number_format($inv['incentive_total']) ?></td>
                            <td class="text-end"><?= number_format($inv['welfare']) ?></td>
                            <td class="text-end"><?= number_format($inv['social_insurance']) ?></td>
                            <td class="text-end fw-bold" style="color:#059669"><?= number_format($inv['invoice_amount']) ?></td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:.6rem" onclick='editInv(<?= json_encode($inv) ?>)'><i class="bi bi-pencil"></i></button>
                                <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                                    <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.6rem"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($invoices)): ?>
                        <tr><td colspan="25" class="text-center text-muted py-4">請求書データがありません</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($invoices)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4">合計</td>
                            <td class="text-end"><?= number_format($totalFee) ?></td>
                            <td colspan="15"></td>
                            <td class="text-end"><?= number_format($totalIncentive) ?></td>
                            <td colspan="2"></td>
                            <td class="text-end" style="color:#059669"><?= number_format($totalInvoice) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 請求書モーダル -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="invId" value="">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-receipt me-1"></i>個人別請求書</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="font-size:.8rem">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><label class="form-label">氏名</label><input type="text" name="employee_name" id="invEmp" class="form-control form-control-sm" required></div>
                        <div class="col-md-2"><label class="form-label">等級</label><input type="text" name="grade" id="invGrade" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="form-label">区分</label><input type="text" name="alliance_type" id="invAlType" class="form-control form-control-sm"></div>
                        <div class="col-md-3"><label class="form-label">業務委託先</label><input type="text" name="alliance_name" id="invAlName" class="form-control form-control-sm"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">業務委託費</label><input type="number" name="base_fee" id="invBaseFee" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">交通費</label><input type="number" name="transport_cost" id="invTransport" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">交通費(先方)</label><input type="number" name="transport_cost_client" id="invTransportCl" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">シフト日数</label><input type="number" name="shift_days" id="invShift" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">実稼働日数</label><input type="number" name="actual_work_days" id="invActual" class="form-control form-control-sm" min="0" value="0"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">CL.ラダー</label><input type="number" name="client_ladder" id="invCLadder" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">代理店ラダー</label><input type="number" name="agency_ladder" id="invALadder" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">追加売上</label><input type="number" name="extra_work_revenue" id="invExtra" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">欠勤減額</label><input type="number" name="absence_deduction" id="invAbsence" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">その他</label><input type="number" name="other_charges" id="invOther" class="form-control form-control-sm" min="0" value="0"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">小計</label><input type="number" name="subtotal_fee" id="invSubtotal" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">EV</label><input type="number" name="event_fee" id="invEvent" class="form-control form-control-sm" min="0" value="0"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">営業インセン</label><input type="number" name="sales_incentive" id="invSalesInc" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">管理インセン</label><input type="number" name="mgmt_incentive" id="invMgmtInc" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">採用インセン</label><input type="number" name="recruit_incentive" id="invRecInc" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">役職手当</label><input type="number" name="role_allowance" id="invRole" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">インセン合計</label><input type="number" name="incentive_total" id="invIncTotal" class="form-control form-control-sm" min="0" value="0"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-2"><label class="form-label">福利厚生</label><input type="number" name="welfare" id="invWelfare" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">社保</label><input type="number" name="social_insurance" id="invSocial" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label fw-bold">請求金額</label><input type="number" name="invoice_amount" id="invAmount" class="form-control form-control-sm" min="0" value="0"></div>
                        <div class="col-md-2"><label class="form-label">終了日</label><input type="date" name="end_date" id="invEndDate" class="form-control form-control-sm"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備考</label>
                        <textarea name="note" id="invNote" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
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
function clearInvModal() {
    document.querySelectorAll('#invoiceModal input, #invoiceModal textarea').forEach(el => {
        if (el.type === 'number') el.value = '0';
        else if (el.type !== 'hidden') el.value = '';
    });
    document.getElementById('invId').value = '';
}
function editInv(inv) {
    document.getElementById('invId').value = inv.id;
    const fields = ['employee_name','grade','alliance_type','alliance_name','base_fee','transport_cost','transport_cost_client',
        'shift_days','actual_work_days','client_ladder','agency_ladder','extra_work_revenue','absence_deduction','other_charges',
        'subtotal_fee','event_fee','sales_incentive','mgmt_incentive','recruit_incentive','role_allowance','incentive_total',
        'welfare','social_insurance','invoice_amount','end_date','note'];
    const ids = ['invEmp','invGrade','invAlType','invAlName','invBaseFee','invTransport','invTransportCl',
        'invShift','invActual','invCLadder','invALadder','invExtra','invAbsence','invOther',
        'invSubtotal','invEvent','invSalesInc','invMgmtInc','invRecInc','invRole','invIncTotal',
        'invWelfare','invSocial','invAmount','invEndDate','invNote'];
    fields.forEach((f, i) => { document.getElementById(ids[i]).value = inv[f] || (document.getElementById(ids[i]).type === 'number' ? 0 : ''); });
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
