<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'イベント案件';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$db = getDB();

// CSV出力
if (isset($_GET['export'])) {
    $csvEmpFilter = getEmployeeNameFilter();
    $filters = ['case_type' => 'event', 'year' => $_GET['year'] ?? '', 'month' => $_GET['month'] ?? '', 'client_id' => $_GET['client_id'] ?? '', 'status' => $_GET['status'] ?? '', 'employee_name' => $csvEmpFilter ?? ''];
    exportSalesCasesCsv($cid, $filters);
    exit;
}

// POST: 案件追加/更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $data = [
            'case_type'      => 'event',
            'client_id'      => ($_POST['client_id'] ?? '') ?: null,
            'start_date'     => $_POST['start_date'] ?? '',
            'end_date'       => $_POST['end_date'] ?? '',
            'sales_rep'      => trim($_POST['sales_rep'] ?? ''),
            'manager'        => trim($_POST['manager_name'] ?? ''),
            'recruiter'      => trim($_POST['recruiter_name'] ?? ''),
            'worker_type'    => $_POST['worker_type'] ?? '正社員',
            'worker_name'    => trim($_POST['worker_name'] ?? ''),
            'alliance_id'    => ($_POST['alliance_id'] ?? '') ?: null,
            'carrier'        => $_POST['carrier'] ?? '',
            'area_id'        => ($_POST['area_id'] ?? '') ?: null,
            'store_name'     => trim($_POST['store_name'] ?? ''),
            'unit_price_in'  => (int)($_POST['unit_price_in'] ?? 0),
            'unit_price_out' => (int)($_POST['unit_price_out'] ?? 0),
            'days_worked'    => (int)($_POST['days_worked'] ?? 0),
            'status'         => $_POST['status'] ?? 'confirmed',
            'note'           => trim($_POST['notes'] ?? ''),
        ];
        if ($action === 'create') {
            createSalesCase($cid, $data);
            redirect(BASE_PATH . '/public/sales_events.php?msg=' . urlencode('案件を追加しました'));
        } else {
            $id = (int)$_POST['id'];
            updateSalesCase($id, $cid, $data);
            redirect(BASE_PATH . '/public/sales_events.php?msg=' . urlencode('案件を更新しました'));
        }
    }
    if ($action === 'cancel') {
        cancelSalesCase((int)$_POST['id'], $cid);
        redirect(BASE_PATH . '/public/sales_events.php?msg=' . urlencode('案件をキャンセルしました'));
    }
    if ($action === 'delete') {
        deleteSalesCase((int)$_POST['id'], $cid);
        redirect(BASE_PATH . '/public/sales_events.php?msg=' . urlencode('案件を削除しました'));
    }
}

$msg = $_GET['msg'] ?? '';

// フィルタ
$year = (int)($_GET['year'] ?? date('Y'));
$month = $_GET['month'] ?? '';
$empFilter = getEmployeeNameFilter();
$filters = [
    'case_type' => 'event',
    'year' => $year,
    'month' => $month,
    'client_id' => $_GET['client_id'] ?? '',
    'worker_type' => $_GET['worker_type'] ?? '',
    'search' => $_GET['search'] ?? '',
    'status' => $_GET['status'] ?? '',
    'employee_name' => $empFilter ?? '',
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$cases = getSalesCases($cid, $filters, $perPage, ($page - 1) * $perPage);
$totalCount = countSalesCases($cid, $filters);
$totalPages = ceil($totalCount / $perPage);

// マスタデータ
$clients = getSalesClients($cid);
$alliances = getSalesAlliances($cid);
$brands = getSalesStoreBrands($cid);
$areas = getSalesAreas($cid);
$workers = getSalesWorkers($cid);

// KPI集計（社員フィルタ・月フィルタ反映）
$kpiWhere = "company_id = ? AND case_year = ? AND case_type = 'event' AND status != '終了'";
$kpiParams = [$cid, $year];
if ($month) { $kpiWhere .= ' AND case_month = ?'; $kpiParams[] = (int)$month; }
if ($empFilter) { $kpiWhere .= ' AND (sales_rep = ? OR worker_name = ?)'; $kpiParams[] = $empFilter; $kpiParams[] = $empFilter; }
$sumStmt = $db->prepare("SELECT
    COUNT(*) as case_count,
    COALESCE(SUM(revenue),0) as total_revenue,
    COALESCE(SUM(cost),0) as total_cost,
    COALESCE(SUM(gross_profit),0) as total_profit,
    CASE WHEN SUM(revenue) > 0 THEN ROUND(SUM(gross_profit)/SUM(revenue)*100, 1) ELSE 0 END as avg_margin
FROM sales_cases WHERE $kpiWhere");
$sumStmt->execute($kpiParams);
$monthSummary = $sumStmt->fetch();

$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-calendar-event me-2"></i>イベント案件</h1>
                <p><?= $year ?>年<?= $month ? $month . '月' : '' ?> / <?= $totalCount ?>件</p>
            </div>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 1])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download me-1"></i>CSV
                </a>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#caseModal" onclick="resetCaseForm()">
                    <i class="bi bi-plus-lg me-1"></i>案件追加
                </button>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i><?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPIサマリー -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" style="color:#059669"><?= number_format($monthSummary['total_revenue'] ?? 0) ?></div><div class="kpi-label">売上</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" style="color:#3b82f6"><?= number_format($monthSummary['total_profit'] ?? 0) ?></div><div class="kpi-label">粗利</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" style="color:#8b5cf6"><?= $monthSummary['case_count'] ?? 0 ?></div><div class="kpi-label">案件数</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" style="color:#f59e0b"><?= ($monthSummary['avg_margin'] ?? 0) ?>%</div><div class="kpi-label">平均粗利率</div></div></div>
    </div>

    <!-- フィルタ -->
    <form id="salesFilterForm" class="sales-filters">
        <select name="month" class="form-select" onchange="this.form.submit()">
            <option value="">全月</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
            <?php endfor; ?>
        </select>
        <select name="year" class="form-select" onchange="this.form.submit()">
            <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
            <?php endfor; ?>
        </select>
        <select name="client_id" class="form-select" onchange="this.form.submit()">
            <option value="">全取引先</option>
            <?php foreach ($clients as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= ($_GET['client_id'] ?? '') == $cl['id'] ? 'selected' : '' ?>><?= h($cl['client_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="worker_type" class="form-select" onchange="this.form.submit()">
            <option value="">全区分</option>
            <?php foreach (['正社員','自社外注','アライアンス','個人外注','アルバイト'] as $wt): ?>
            <option value="<?= $wt ?>" <?= ($_GET['worker_type'] ?? '') === $wt ? 'selected' : '' ?>><?= $wt ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="search" class="form-control" placeholder="検索..." value="<?= h($_GET['search'] ?? '') ?>" style="max-width:150px">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>

    <!-- 案件一覧 -->
    <div class="card">
        <div class="table-responsive">
            <table class="table sales-table mb-0">
                <thead>
                    <tr>
                        <th>日付</th>
                        <th>取引先</th>
                        <th>営業</th>
                        <th>区分</th>
                        <th>外注先</th>
                        <th>スタッフ</th>
                        <th>キャリア/店舗</th>
                        <th class="text-end">請求</th>
                        <th class="text-end">支払</th>
                        <th class="text-end">日数</th>
                        <th class="text-end">売上</th>
                        <th class="text-end">粗利</th>
                        <th class="text-end">率</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cases)): ?>
                    <tr><td colspan="14" class="text-center text-muted py-4">案件がありません</td></tr>
                    <?php endif; ?>
                    <?php foreach ($cases as $c): ?>
                    <tr class="<?= $c['status'] === 'cancelled' ? 'table-secondary' : '' ?>">
                        <td class="small"><?= h(substr($c['start_date'] ?? '', 5)) ?><?php if ($c['end_date'] && $c['end_date'] !== $c['start_date']): ?>~<?= h(substr($c['end_date'], 5)) ?><?php endif; ?></td>
                        <td class="fw-medium"><?= h($c['client_name'] ?? '') ?></td>
                        <td class="small"><?= h($c['sales_rep']) ?></td>
                        <td><span class="wt-badge wt-<?= h($c['worker_type']) ?>"><?= h($c['worker_type']) ?></span></td>
                        <td class="small"><?= h($c['alliance_name'] ?? '') ?></td>
                        <td class="fw-medium"><?= h($c['worker_name']) ?></td>
                        <td class="small"><?= h(trim(($c['carrier'] ?? '') . ' ' . ($c['store_name'] ?? ''))) ?></td>
                        <td class="amount"><?= number_format($c['unit_price_in']) ?></td>
                        <td class="amount"><?= number_format($c['unit_price_out']) ?></td>
                        <td class="amount"><?= $c['days_worked'] ?></td>
                        <td class="amount amount-positive"><?= number_format($c['revenue']) ?></td>
                        <td class="amount <?= $c['gross_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= number_format($c['gross_profit']) ?></td>
                        <td class="amount"><?= round($c['margin'] * 100, 1) ?>%</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='editCase(<?= json_encode($c) ?>)'><i class="bi bi-pencil"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($cases)): ?>
                <tfoot>
                    <tr class="fw-bold" style="background:#f0fdf4">
                        <td colspan="10" class="text-end">合計</td>
                        <td class="amount amount-positive"><?= number_format(array_sum(array_column($cases, 'revenue'))) ?></td>
                        <td class="amount amount-positive"><?= number_format(array_sum(array_column($cases, 'gross_profit'))) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ページネーション -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- 案件入力モーダル -->
<div class="modal fade" id="caseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content case-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" id="form_action" value="create">
            <input type="hidden" name="id" id="form_id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">イベント案件追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-medium">取引先</label>
                        <select name="client_id" id="f_client_id" class="form-select">
                            <option value="">-- 選択 --</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>"><?= h($cl['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">開始日 <span class="text-danger">*</span></label>
                        <input type="date" name="start_date" id="f_start_date" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">終了日</label>
                        <input type="date" name="end_date" id="f_end_date" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">営業担当</label>
                        <input type="text" name="sales_rep" id="f_sales_rep" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">マネージャー</label>
                        <input type="text" name="manager_name" id="f_manager_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">リクルーター</label>
                        <input type="text" name="recruiter_name" id="f_recruiter_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">スタッフ区分</label>
                        <select name="worker_type" id="worker_type" class="form-select" onchange="toggleAllianceGroup()">
                            <option value="正社員">正社員</option>
                            <option value="自社外注">自社外注</option>
                            <option value="アライアンス">アライアンス</option>
                            <option value="個人外注">個人外注</option>
                            <option value="アルバイト">アルバイト</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="alliance_group" style="display:none">
                        <label class="form-label fw-medium">外注先</label>
                        <select name="alliance_id" id="f_alliance_id" class="form-select">
                            <option value="">-- 選択 --</option>
                            <?php foreach ($alliances as $al): ?>
                            <option value="<?= $al['id'] ?>"><?= h($al['alliance_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">スタッフ名</label>
                        <input type="text" name="worker_name" id="f_worker_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">キャリア</label>
                        <select name="carrier" id="f_carrier" class="form-select">
                            <option value="">-- 選択 --</option>
                            <option value="ドコモ">ドコモ</option>
                            <option value="au">au</option>
                            <option value="SB">SB</option>
                            <option value="楽天">楽天</option>
                            <option value="コミュファ">コミュファ</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">エリア</label>
                        <select name="area_id" id="f_area_id" class="form-select">
                            <option value="">-- 選択 --</option>
                            <?php foreach ($areas as $ar): ?>
                            <option value="<?= $ar['id'] ?>"><?= h($ar['area_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">店舗名</label>
                        <input type="text" name="store_name" id="f_store_name" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">請求単価</label>
                        <input type="number" name="unit_price_in" id="unit_price_in" class="form-control" step="1" min="0" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">支払単価</label>
                        <input type="number" name="unit_price_out" id="unit_price_out" class="form-control" step="1" min="0" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">稼働日数</label>
                        <input type="number" name="days_worked" id="days_worked" class="form-control" step="1" min="0" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">ステータス</label>
                        <select name="status" id="f_status" class="form-select">
                            <option value="confirmed">確定</option>
                            <option value="draft">ドラフト</option>
                            <option value="cancelled">キャンセル</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="row g-2 text-center" style="background:#f8f9fa;border-radius:8px;padding:.75rem .5rem">
                            <div class="col-3">
                                <div class="text-muted small">売上</div>
                                <div class="fw-bold" id="calc_revenue">¥0</div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted small">原価</div>
                                <div class="fw-bold" id="calc_cost">¥0</div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted small">粗利</div>
                                <div class="fw-bold" id="calc_profit">¥0</div>
                            </div>
                            <div class="col-3">
                                <div class="text-muted small">粗利率</div>
                                <div class="fw-bold" id="calc_margin">0.0%</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium">備考</label>
                        <textarea name="notes" id="f_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">追加</button>
            </div>
        </form>
    </div>
</div>

<?php
$inlineJs = <<<'JS'
function toggleAllianceGroup() {
    var wt = document.getElementById('worker_type').value;
    var ag = document.getElementById('alliance_group');
    if (ag) ag.style.display = wt === 'アライアンス' ? 'block' : 'none';
}
function resetCaseForm() {
    document.getElementById('form_action').value = 'create';
    document.getElementById('form_id').value = '';
    document.getElementById('modalTitle').textContent = 'イベント案件追加';
    document.getElementById('submitBtn').textContent = '追加';
    document.getElementById('f_client_id').value = '';
    document.getElementById('f_start_date').value = '';
    document.getElementById('f_end_date').value = '';
    document.getElementById('f_sales_rep').value = '';
    document.getElementById('f_manager_name').value = '';
    document.getElementById('f_recruiter_name').value = '';
    document.getElementById('worker_type').value = '正社員';
    document.getElementById('f_alliance_id').value = '';
    document.getElementById('f_worker_name').value = '';
    document.getElementById('f_carrier').value = '';
    document.getElementById('f_area_id').value = '';
    document.getElementById('f_store_name').value = '';
    document.getElementById('unit_price_in').value = 0;
    document.getElementById('unit_price_out').value = 0;
    document.getElementById('days_worked').value = 0;
    document.getElementById('f_status').value = 'confirmed';
    document.getElementById('f_notes').value = '';
    toggleAllianceGroup();
    salesCalcAmounts();
}
function editCase(c) {
    document.getElementById('form_action').value = 'update';
    document.getElementById('form_id').value = c.id;
    document.getElementById('modalTitle').textContent = 'イベント案件編集 #' + c.id;
    document.getElementById('submitBtn').textContent = '更新';
    document.getElementById('f_client_id').value = c.client_id || '';
    document.getElementById('f_start_date').value = c.start_date || '';
    document.getElementById('f_end_date').value = c.end_date || '';
    document.getElementById('f_sales_rep').value = c.sales_rep || '';
    document.getElementById('f_manager_name').value = c.manager || '';
    document.getElementById('f_recruiter_name').value = c.recruiter || '';
    document.getElementById('worker_type').value = c.worker_type || '正社員';
    document.getElementById('f_alliance_id').value = c.alliance_id || '';
    document.getElementById('f_worker_name').value = c.worker_name || '';
    document.getElementById('f_carrier').value = c.carrier || '';
    document.getElementById('f_area_id').value = c.area_id || '';
    document.getElementById('f_store_name').value = c.store_name || '';
    document.getElementById('unit_price_in').value = c.unit_price_in || 0;
    document.getElementById('unit_price_out').value = c.unit_price_out || 0;
    document.getElementById('days_worked').value = c.days_worked || 0;
    document.getElementById('f_status').value = c.status || 'confirmed';
    document.getElementById('f_notes').value = c.note || '';
    toggleAllianceGroup();
    salesCalcAmounts();
    new bootstrap.Modal(document.getElementById('caseModal')).show();
}
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
