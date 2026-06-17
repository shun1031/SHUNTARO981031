<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = '案件管理(管理者)';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];
$db  = getDB();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/admin/index.php'); }

// CSV出力
if (isset($_GET['export'])) {
    $filters = [
        'case_type' => $_GET['case_type'] ?? '',
        'year' => $_GET['year'] ?? '',
        'month' => $_GET['month'] ?? '',
        'client_id' => $_GET['client_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'search' => $_GET['search'] ?? '',
    ];
    exportSalesCasesCsv($cid, $filters);
    exit;
}

// POST: 一括操作/削除
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        deleteSalesCase($id, $cid);
        $msg = '案件を削除しました';
    } elseif ($action === 'cancel') {
        $id = (int)$_POST['id'];
        cancelSalesCase($id, $cid);
        $msg = '案件をキャンセルしました';
    } elseif ($action === 'bulk_delete' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        foreach ($ids as $id) { deleteSalesCase($id, $cid); }
        $msg = count($ids) . '件削除しました';
    }
    if ($msg) {
        redirect(BASE_PATH . '/admin/sales_cases.php?' . http_build_query(array_diff_key($_GET, ['msg' => 1])) . '&msg=' . urlencode($msg));
    }
}

if (!empty($_GET['msg'])) $msg = $_GET['msg'];

// フィルタ
$year = (int)($_GET['year'] ?? date('Y'));
$filters = [
    'case_type' => $_GET['case_type'] ?? '',
    'year' => $year,
    'month' => $_GET['month'] ?? '',
    'client_id' => $_GET['client_id'] ?? '',
    'worker_type' => $_GET['worker_type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$cases = getSalesCases($cid, $filters, $perPage, ($page - 1) * $perPage);
$totalCount = countSalesCases($cid, $filters);
$totalPages = ceil($totalCount / $perPage);

$clients = getSalesClients($cid);

$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-pencil me-2"></i>案件管理(管理者)</h1>
                <p><?= $totalCount ?>件</p>
            </div>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 1])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>CSV出力</a>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><i class="bi bi-check-circle me-1"></i><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- フィルタ -->
    <form class="sales-filters" method="get">
        <select name="year" class="form-select" onchange="this.form.submit()">
            <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?><option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option><?php endfor; ?>
        </select>
        <select name="month" class="form-select" onchange="this.form.submit()">
            <option value="">全月</option>
            <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= ($_GET['month'] ?? '') == $m ? 'selected' : '' ?>><?= $m ?>月</option><?php endfor; ?>
        </select>
        <select name="case_type" class="form-select" onchange="this.form.submit()">
            <option value="">全種別</option>
            <option value="event" <?= ($_GET['case_type'] ?? '') === 'event' ? 'selected' : '' ?>>イベント</option>
            <option value="regular" <?= ($_GET['case_type'] ?? '') === 'regular' ? 'selected' : '' ?>>常勤</option>
        </select>
        <select name="client_id" class="form-select" onchange="this.form.submit()">
            <option value="">全取引先</option>
            <?php foreach ($clients as $cl): ?><option value="<?= $cl['id'] ?>" <?= ($_GET['client_id'] ?? '') == $cl['id'] ? 'selected' : '' ?>><?= h($cl['client_name']) ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-select" onchange="this.form.submit()">
            <option value="">確定のみ</option>
            <option value="draft" <?= ($_GET['status'] ?? '') === 'draft' ? 'selected' : '' ?>>下書き</option>
            <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>キャンセル</option>
        </select>
        <input type="text" name="search" class="form-control" placeholder="検索..." value="<?= h($_GET['search'] ?? '') ?>" style="max-width:150px">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>

    <!-- 集計サマリー -->
    <div class="row g-2 mb-3">
        <div class="col-auto"><div class="sales-kpi p-2"><span class="small text-muted">売上合計: </span><span class="fw-bold" style="color:#059669"><?= number_format(array_sum(array_column($cases, 'revenue'))) ?></span></div></div>
        <div class="col-auto"><div class="sales-kpi p-2"><span class="small text-muted">粗利合計: </span><span class="fw-bold" style="color:#3b82f6"><?= number_format(array_sum(array_column($cases, 'gross_profit'))) ?></span></div></div>
        <div class="col-auto"><div class="sales-kpi p-2"><span class="small text-muted">件数: </span><span class="fw-bold"><?= $totalCount ?></span></div></div>
    </div>

    <form method="post" id="bulkForm">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" id="bulkAction" value="">

        <div class="card">
            <div class="table-responsive">
                <table class="table sales-table mb-0">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="checkAll" onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)"></th>
                            <th>ID</th>
                            <th>種別</th>
                            <th>年月</th>
                            <th>取引先</th>
                            <th>営業</th>
                            <th>区分</th>
                            <th>スタッフ</th>
                            <th>店舗</th>
                            <th class="text-end">売上</th>
                            <th class="text-end">粗利</th>
                            <th class="text-end">率</th>
                            <th>状態</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="row-check"></td>
                            <td class="small text-muted"><?= $c['id'] ?></td>
                            <td><span class="badge bg-<?= $c['case_type'] === 'event' ? 'purple' : 'info' ?>" style="<?= $c['case_type'] === 'event' ? 'background:#8b5cf6!important' : '' ?>"><?= $c['case_type'] === 'event' ? 'EV' : '常勤' ?></span></td>
                            <td class="small"><?= $c['case_year'] ?>/<?= $c['case_month'] ?></td>
                            <td class="fw-medium"><?= h($c['client_name'] ?? '') ?></td>
                            <td class="small"><?= h($c['sales_rep']) ?></td>
                            <td><span class="wt-badge wt-<?= h($c['worker_type']) ?>"><?= h($c['worker_type']) ?></span></td>
                            <td><?= h($c['worker_name']) ?></td>
                            <td class="small"><?= h($c['store_name']) ?></td>
                            <td class="amount amount-positive"><?= number_format($c['revenue']) ?></td>
                            <td class="amount <?= $c['gross_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>"><?= number_format($c['gross_profit']) ?></td>
                            <td class="amount"><?= round($c['margin'] * 100, 1) ?>%</td>
                            <td><span class="badge status-<?= $c['status'] ?>"><?= $c['status'] === 'confirmed' ? '確定' : ($c['status'] === 'draft' ? '下書' : 'Cancel') ?></span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_PATH ?>/public/<?= $c['case_type'] === 'event' ? 'sales_events' : 'sales_regular' ?>.php?year=<?= $c['case_year'] ?>&month=<?= $c['case_month'] ?>" class="btn btn-outline-secondary" title="一覧で見る"><i class="bi bi-eye"></i></a>
                                    <button type="button" class="btn btn-outline-danger" onclick="if(confirm('削除しますか？')){document.getElementById('bulkAction').value='delete';const h=document.createElement('input');h.type='hidden';h.name='id';h.value=<?= $c['id'] ?>;document.getElementById('bulkForm').append(h);document.getElementById('bulkForm').submit()}"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="if(confirm('選択した案件を削除しますか？')){document.getElementById('bulkAction').value='bulk_delete';document.getElementById('bulkForm').submit()}">
                    <i class="bi bi-trash me-1"></i>選択を削除
                </button>
            </div>
            <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= min($totalPages, 20); $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
