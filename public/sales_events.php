<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'イベント案件';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

// 月が URL にない場合は当月にリダイレクト（全月表示防止）
if (!isset($_GET['month']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $defaultYear  = (int)($_GET['year'] ?? date('Y'));
    $defaultMonth = (int)date('n');
    redirect(BASE_PATH . '/public/sales_events.php?year=' . $defaultYear . '&month=' . $defaultMonth);
}

$db = getDB();

// CSV出力
if (isset($_GET['export'])) {
    $csvEmpFilter = getEmployeeNameFilter();
    $filters = ['case_type' => 'event', 'year' => $_GET['year'] ?? '', 'month' => $_GET['month'] ?? '', 'client_id' => $_GET['client_id'] ?? '', 'status' => $_GET['status'] ?? '', 'employee_name' => $csvEmpFilter ?? ''];
    exportSalesCasesCsv($cid, $filters);
    exit;
}

// AJAX: 稼働数・金額更新（JSON返却）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_days' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $newDays   = max(0, (int)($_POST['new_days']   ?? 0));
    $newRev    = (int)($_POST['new_rev']    ?? 0);
    $newProfit = (int)($_POST['new_profit'] ?? 0);
    $newCost   = $newRev - $newProfit;
    $newMargin = $newRev > 0 ? round($newProfit / $newRev, 4) : 0;
    $db->prepare("UPDATE sales_cases SET days_worked=?,revenue=?,cost=?,gross_profit=?,margin=? WHERE id=? AND company_id=?")
       ->execute([$newDays, $newRev, $newCost, $newProfit, $newMargin, $id, $cid]);
    echo json_encode(['ok' => true, 'revenue' => $newRev, 'profit' => $newProfit]);
    exit;
}

// POST: 案件追加/更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $_clientId = ($_POST['client_id'] ?? '') ?: null;
        $_clientNameInput = trim($_POST['client_name_input'] ?? '');
        if (!$_clientId && $_clientNameInput) {
            $_cdb = getDB();
            $_cs = $_cdb->prepare('SELECT id FROM sales_clients WHERE company_id = ? AND client_name = ? LIMIT 1');
            $_cs->execute([$cid, $_clientNameInput]);
            $_existingCid = $_cs->fetchColumn();
            $_clientId = $_existingCid ? (int)$_existingCid : createSalesClient($cid, ['client_name' => $_clientNameInput]);
        }
        $data = [
            'case_type'      => 'event',
            'client_id'      => $_clientId,
            'start_date'     => $_POST['start_date'] ?? '',
            'end_date'       => $_POST['end_date'] ?? '',
            'sales_rep'      => trim($_POST['sales_rep'] ?? ''),
            'manager'        => trim($_POST['manager_name'] ?? ''),
            'recruiter'      => trim($_POST['recruiter_name'] ?? ''),
            'worker_type'    => $_POST['worker_type'] ?? '正社員',
            'worker_name'    => trim($_POST['worker_name'] ?? ''),
            'alliance_id'    => ($_POST['alliance_id'] ?? '') ?: null,
            'carrier'        => trim($_POST['carrier'] ?? ''),
            'trade_name'     => trim($_POST['trade_name'] ?? ''),
            'area_id'        => ($_POST['area_id'] ?? '') ?: null,
            'store_name'     => trim($_POST['store_name'] ?? ''),
            'unit_price_in'  => (int)($_POST['unit_price_in'] ?? 0),
            'unit_price_out' => (int)($_POST['unit_price_out'] ?? 0),
            'days_worked'    => (int)($_POST['days_worked'] ?? 0),
            'status'         => $_POST['status'] ?? 'confirmed',
            'note'           => trim($_POST['notes'] ?? ''),
        ];
        $_crY = (int)($_GET['year']  ?? date('Y'));
        $_crM = (int)($_GET['month'] ?? date('n'));
        $_crBase = BASE_PATH . '/public/sales_events.php?year=' . $_crY . '&month=' . $_crM;
        if ($action === 'create') {
            $newCaseId = createSalesCase($cid, $data);
            $_planId = (int)($_POST['plan_id'] ?? 0);
            if ($_planId && $newCaseId) {
                try {
                    getDB()->prepare("UPDATE event_plans SET status='confirmed', linked_case_id=? WHERE id=? AND company_id=? AND status='pending'")
                           ->execute([$newCaseId, $_planId, $cid]);
                    getDB()->prepare("UPDATE sales_cases SET plan_id=? WHERE id=? AND company_id=?")
                           ->execute([$_planId, $newCaseId, $cid]);
                } catch (Exception $_e) {
                    error_log('Plan linking error: ' . $_e->getMessage());
                }
            }
            redirect($_crBase . '&msg=' . urlencode('案件を追加しました'));
        } else {
            $id = (int)$_POST['id'];
            updateSalesCase($id, $cid, $data);
            redirect($_crBase . '&msg=' . urlencode('案件を更新しました'));
        }
    }
    $_backYear  = (int)($_GET['year']  ?? date('Y'));
    $_backMonth = (int)($_GET['month'] ?? date('n'));
    $_backBase  = BASE_PATH . '/public/sales_events.php?year=' . $_backYear . '&month=' . $_backMonth;
    if ($action === 'cancel') {
        cancelSalesCase((int)$_POST['id'], $cid);
        redirect($_backBase . '&msg=' . urlencode('案件をキャンセルしました'));
    }
    if ($action === 'delete') {
        deleteSalesCase((int)$_POST['id'], $cid);
        redirect($_backBase . '&msg=' . urlencode('案件を削除しました'));
    }
    if ($action === 'copy_prev_month') {
        $curYear  = (int)($_POST['copy_year']  ?? date('Y'));
        $curMonth = (int)($_POST['copy_month'] ?? date('n'));
        if ($curMonth === 1) { $prevYear = $curYear - 1; $prevMonth = 12; }
        else { $prevYear = $curYear; $prevMonth = $curMonth - 1; }
        $prevStmt = $db->prepare("SELECT * FROM sales_cases WHERE company_id=? AND case_year=? AND case_month=? AND case_type='event' AND status!='cancelled'");
        $prevStmt->execute([$cid, $prevYear, $prevMonth]);
        $copied = 0;
        foreach ($prevStmt->fetchAll() as $pc) {
            createSalesCase($cid, [
                'case_type' => 'event', 'client_id' => $pc['client_id'],
                'start_date' => $pc['start_date'], 'end_date' => $pc['end_date'],
                'sales_rep' => $pc['sales_rep'], 'manager' => $pc['manager'],
                'recruiter' => $pc['recruiter'], 'worker_type' => $pc['worker_type'],
                'worker_name' => $pc['worker_name'], 'alliance_id' => $pc['alliance_id'],
                'carrier' => $pc['carrier'], 'trade_name' => $pc['trade_name'] ?? '',
                'area_id' => $pc['area_id'],
                'store_name' => $pc['store_name'], 'unit_price_in' => $pc['unit_price_in'],
                'unit_price_out' => $pc['unit_price_out'], 'days_worked' => $pc['days_worked'],
                'status' => $pc['status'], 'note' => $pc['note'],
            ]);
            $copied++;
        }
        redirect(BASE_PATH . '/public/sales_events.php?year=' . $curYear . '&month=' . $curMonth . '&msg=' . urlencode($copied . '件コピーしました'));
    }
}

$msg = $_GET['msg'] ?? '';

// 予定案件リスト（フォームのプルダウン用）
$_pendingPlans = [];
if (isAdmin()) {
    $_pp = getDB()->prepare("SELECT * FROM event_plans WHERE company_id=? AND status='pending' ORDER BY work_date DESC LIMIT 100");
    $_pp->execute([$cid]);
    $_pendingPlans = $_pp->fetchAll();
}

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

// 屋号・店舗名の選択肢（過去のDB値）
$_tns = $db->prepare("SELECT DISTINCT trade_name FROM sales_cases WHERE company_id=? AND case_type='event' AND trade_name IS NOT NULL AND trade_name != '' ORDER BY trade_name");
$_tns->execute([$cid]);
$distinctTradeNames = $_tns->fetchAll(PDO::FETCH_COLUMN);
$_sns = $db->prepare("SELECT DISTINCT store_name FROM sales_cases WHERE company_id=? AND case_type='event' AND store_name IS NOT NULL AND store_name != '' ORDER BY store_name");
$_sns->execute([$cid]);
$distinctStoreNames = $_sns->fetchAll(PDO::FETCH_COLUMN);

// KPI集計（フィルター全条件を反映）
$kpiWhere = "company_id = ? AND case_year = ? AND case_type = 'event' AND status != 'cancelled'";
$kpiParams = [$cid, $year];
if ($month) { $kpiWhere .= ' AND case_month = ?'; $kpiParams[] = (int)$month; }
if (!empty($filters['client_id'])) { $kpiWhere .= ' AND client_id = ?'; $kpiParams[] = (int)$filters['client_id']; }
if (!empty($filters['worker_type'])) { $kpiWhere .= ' AND worker_type = ?'; $kpiParams[] = $filters['worker_type']; }
if (!empty($filters['search'])) {
    $kpiWhere .= ' AND (worker_name LIKE ? OR store_name LIKE ? OR sales_rep LIKE ?)';
    $_ks = '%' . $filters['search'] . '%';
    $kpiParams[] = $_ks; $kpiParams[] = $_ks; $kpiParams[] = $_ks;
}
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

// テーブルHTML事前レンダリング（AJAX対応）
ob_start();
if (empty($cases)) { echo '<tr><td colspan="11" class="text-center text-muted py-4">案件がありません</td></tr>'; }
else { foreach ($cases as $c):
    $splitTo = $c['manager'] ?: ($c['recruiter'] ?: '直営業');
    $repRev  = (int)floor($c['revenue'] / 2);
    $otRev   = $c['revenue'] - $repRev;
    $repPro  = (int)floor($c['gross_profit'] / 2);
    $otPro   = $c['gross_profit'] - $repPro;
?><tr id="row_<?= $c['id'] ?>" data-rev="<?= (int)$c['revenue'] ?>" data-profit="<?= (int)$c['gross_profit'] ?>" data-price-in="<?= (int)$c['unit_price_in'] ?>" data-price-out="<?= (int)$c['unit_price_out'] ?>" class="<?= $c['status'] === 'cancelled' ? 'table-secondary' : '' ?>">
    <td class="fw-medium"><?= h($c['client_name'] ?? '') ?></td>
    <td class="small"><?= h($c['sales_rep']) ?></td>
    <td class="small"><?= h($c['alliance_name'] ?? '') ?></td>
    <td class="fw-medium"><?= h($c['worker_name']) ?></td>
    <td class="small"><?= h($c['carrier'] ?? '') ?></td>
    <td class="small"><?= h($c['trade_name'] ?? '') ?></td>
    <td class="small"><?= h($c['store_name'] ?? '') ?></td>
    <td class="amount amount-positive" style="vertical-align:top">
        <span id="rev_<?= $c['id'] ?>"><?= number_format($c['revenue']) ?></span>
        <?php if ($c['sales_rep']): ?><div id="rev_split_<?= $c['id'] ?>" data-rep="<?= h($c['sales_rep']) ?>" data-split="<?= h($splitTo) ?>" style="font-size:.68rem;color:#6b7280;line-height:1.5;margin-top:2px"><?= h($c['sales_rep']) ?> <?= number_format($repRev) ?><br><?= h($splitTo) ?> <?= number_format($otRev) ?></div><?php endif; ?>
    </td>
    <td class="amount <?= $c['gross_profit'] >= 0 ? 'amount-positive' : 'amount-negative' ?>" style="vertical-align:top">
        <span id="profit_<?= $c['id'] ?>"><?= number_format($c['gross_profit']) ?></span>
        <?php if ($c['sales_rep']): ?><div id="profit_split_<?= $c['id'] ?>" data-rep="<?= h($c['sales_rep']) ?>" data-split="<?= h($splitTo) ?>" style="font-size:.68rem;color:#6b7280;line-height:1.5;margin-top:2px"><?= h($c['sales_rep']) ?> <?= number_format($repPro) ?><br><?= h($splitTo) ?> <?= number_format($otPro) ?></div><?php endif; ?>
    </td>
    <td class="text-center" style="white-space:nowrap">
        <div class="d-flex align-items-center justify-content-center gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm px-2" onclick="adjustDays(<?= $c['id'] ?>,-1)">−</button>
            <input type="number" id="spin_<?= $c['id'] ?>" value="<?= (int)$c['days_worked'] ?>" min="0" class="form-control form-control-sm p-1 text-center" style="width:52px">
            <button type="button" class="btn btn-outline-secondary btn-sm px-2" onclick="adjustDays(<?= $c['id'] ?>,1)">＋</button>
        </div>
    </td>
    <td style="white-space:nowrap">
        <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-sm btn-outline-primary" onclick='editCase(<?= json_encode($c) ?>)' title="編集"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $c['id'] ?>)" title="削除"><i class="bi bi-trash"></i></button>
            <button class="btn btn-sm btn-outline-success" onclick="applyDays(<?= $c['id'] ?>)" title="金額反映"><i class="bi bi-arrow-repeat"></i></button>
        </div>
    </td>
</tr><?php endforeach; }
$tbodyHtml = ob_get_clean();

$_tfootRevTotal = array_sum(array_column($cases, 'revenue'));
$_tfootProTotal = array_sum(array_column($cases, 'gross_profit'));
$tfootHtml = !empty($cases) ? '<tr class="fw-bold" style="background:#f0fdf4"><td colspan="7" class="text-end">合計</td><td class="amount amount-positive" id="tfoot_revenue">' . number_format($_tfootRevTotal) . '</td><td class="amount amount-positive" id="tfoot_profit">' . number_format($_tfootProTotal) . '</td><td colspan="2"></td></tr>' : '';

// ページネーションHTML
$_pgGet = array_diff_key($_GET, ['ajax' => null]);
ob_start();
if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_pgGet, ['page' => $p])) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif;
$paginationHtml = ob_get_clean();

// AJAX応答（フィルター変更時のみ）
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'kpi'        => [
            'revenue' => number_format($monthSummary['total_revenue'] ?? 0),
            'profit'  => number_format($monthSummary['total_profit'] ?? 0),
            'count'   => (int)($monthSummary['case_count'] ?? 0),
            'margin'  => ($monthSummary['avg_margin'] ?? 0) . '%',
        ],
        'tbody'      => $tbodyHtml,
        'tfoot'      => $tfootHtml,
        'pagination' => $paginationHtml,
        'total'      => $totalCount,
    ]);
    exit;
}

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
            <?php
            $dispYear = $year; $dispMonth = (int)($month ?: date('n'));
            $prevM = $dispMonth - 1; $prevY = $dispYear;
            if ($prevM < 1) { $prevM = 12; $prevY--; }
            $nextM = $dispMonth + 1; $nextY = $dispYear;
            if ($nextM > 12) { $nextM = 1; $nextY++; }
            ?>
            <form method="post" id="copyPrevForm" style="display:none">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="copy_prev_month">
                <input type="hidden" name="copy_year" value="<?= $dispYear ?>">
                <input type="hidden" name="copy_month" value="<?= $dispMonth ?>">
            </form>
            <div class="d-flex gap-2 align-items-center">
                <div class="d-flex align-items-center gap-1">
                    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                    <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $dispYear ?>年<?= $dispMonth ?>月</span>
                    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
                </div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#caseModal" onclick="resetCaseForm()"><i class="bi bi-plus-lg me-1"></i>案件追加</button>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i><?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div id="caseFlashMsg" style="display:none" class="alert alert-dismissible fade show mb-2" role="alert"></div>

    <!-- KPIサマリー -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" id="kpi_revenue" style="color:#059669"><?= number_format($monthSummary['total_revenue'] ?? 0) ?></div><div class="kpi-label">売上</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" id="kpi_profit" style="color:#3b82f6"><?= number_format($monthSummary['total_profit'] ?? 0) ?></div><div class="kpi-label">粗利</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" id="kpi_count" style="color:#8b5cf6"><?= $monthSummary['case_count'] ?? 0 ?></div><div class="kpi-label">案件数</div></div></div>
        <div class="col-6 col-md-3"><div class="sales-kpi"><div class="kpi-value" id="kpi_margin" style="color:#f59e0b"><?= ($monthSummary['avg_margin'] ?? 0) ?>%</div><div class="kpi-label">平均粗利率</div></div></div>
    </div>

    <!-- フィルタ -->
    <form id="salesFilterForm" class="sales-filters">
        <input type="hidden" name="year" value="<?= $dispYear ?>">
        <input type="hidden" name="month" value="<?= $dispMonth ?>">
        <select name="client_id" class="form-select">
            <option value="">全取引先</option>
            <?php foreach ($clients as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= ($_GET['client_id'] ?? '') == $cl['id'] ? 'selected' : '' ?>><?= h($cl['client_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="worker_type" class="form-select">
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
                        <th>取引先</th>
                        <th>営業</th>
                        <th>外注先</th>
                        <th>スタッフ</th>
                        <th>キャリア</th>
                        <th>屋号</th>
                        <th>店舗</th>
                        <th class="text-end">売上</th>
                        <th class="text-end">粗利</th>
                        <th class="text-center">稼働数</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody><?= $tbodyHtml ?></tbody>
                <?php if (!empty($cases)): ?>
                <tfoot><?= $tfootHtml ?></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ページネーション -->
    <div id="paginationWrapper"><?= $paginationHtml ?></div>
</div>

<!-- 前月コピー確認モーダル -->
<div class="modal fade" id="copyConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">確認</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1 fw-medium">本当にコピーしますか？</p>
                <small class="text-muted">前月のイベント案件データを今月にコピーします。</small>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">いいえ</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('copyPrevForm').submit()">はい</button>
            </div>
        </div>
    </div>
</div>

<!-- 案件入力モーダル -->
<div class="modal fade" id="caseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content case-form" id="caseFormEl">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" id="form_action" value="create">
            <input type="hidden" name="id" id="form_id" value="">
            <input type="hidden" name="plan_id" id="f_plan_id" value="">
            <input type="hidden" name="case_type" value="event">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">イベント案件追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($_pendingPlans)): ?>
                <div class="mb-3 p-2 rounded" style="background:#fef3c7;border:1px solid #fde68a">
                    <label class="form-label small fw-bold" style="color:#92400e"><i class="bi bi-link-45deg me-1"></i>予定案件から選択（任意）</label>
                    <select id="f_plan_select" class="form-select form-select-sm" onchange="applyPlan(this.value)">
                        <option value="">-- 予定案件を選択して自動入力（省略可）--</option>
                        <?php foreach ($_pendingPlans as $_pp): ?>
                        <option value="<?= $_pp['id'] ?>"
                            data-client="<?= h($_pp['client_name']) ?>"
                            data-store="<?= h($_pp['store_name'] ?? '') ?>"
                            data-date="<?= h($_pp['work_date']) ?>">
                            <?= date('m/d', strtotime($_pp['work_date'])) ?> / <?= h($_pp['client_name']) ?> / <?= h($_pp['store_name'] ?? '') ?> (<?= $_pp['required_count'] ?>名)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:.68rem">選択すると日付・クライアント・店舗が自動入力されます。</div>
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-medium">取引先</label>
                        <input type="text" id="f_client_name_input" class="form-control" list="clientDatalist" placeholder="選択または直接入力" autocomplete="off">
                        <input type="hidden" name="client_id" id="f_client_id" value="">
                        <input type="hidden" name="client_name_input" id="f_client_name_hidden" value="">
                        <datalist id="clientDatalist">
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= h($cl['client_name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
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
                        <label class="form-label fw-medium">管理者</label>
                        <input type="text" name="manager_name" id="f_manager_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">採用者</label>
                        <input type="text" name="recruiter_name" id="f_recruiter_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">区分 <span class="text-danger">*</span></label>
                        <select name="case_division" id="f_case_division" class="form-select" required>
                            <option value="">-- 選択してください --</option>
                            <option value="1次">1次</option>
                            <option value="その他">その他</option>
                        </select>
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
                    <!-- キャリア -->
                    <div class="col-md-4">
                        <label class="form-label fw-medium">キャリア <span class="text-danger">*</span></label>
                        <input type="text" name="carrier" id="f_carrier" class="form-control" placeholder="選択または入力" list="carrierList" autocomplete="off">
                        <datalist id="carrierList">
                            <option value="ドコモ"><option value="au"><option value="SB"><option value="楽天"><option value="コミュファ"><option value="CATV">
                        </datalist>
                    </div>
                    <!-- 屋号 -->
                    <div class="col-md-4">
                        <label class="form-label fw-medium">屋号 <span class="text-danger">*</span></label>
                        <input type="text" name="trade_name" id="f_trade_name" class="form-control" placeholder="選択または入力" list="tradeNameList" autocomplete="off">
                        <datalist id="tradeNameList">
                            <?php foreach ($distinctTradeNames as $_tn): ?><option value="<?= h($_tn) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <!-- 店舗名 -->
                    <div class="col-md-4">
                        <label class="form-label fw-medium">店舗名 <span class="text-danger">*</span></label>
                        <input type="text" name="store_name" id="f_store_name" class="form-control" placeholder="選択または入力" list="storeNameList" autocomplete="off">
                        <datalist id="storeNameList">
                            <?php foreach ($distinctStoreNames as $_sn): ?><option value="<?= h($_sn) ?>"><?php endforeach; ?>
                        </datalist>
                        <div class="form-text text-danger">【正式名称で入力】</div>
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
                            <div class="col-3"><div class="text-muted small">売上</div><div class="fw-bold" id="calc_revenue">¥0</div></div>
                            <div class="col-3"><div class="text-muted small">原価</div><div class="fw-bold" id="calc_cost">¥0</div></div>
                            <div class="col-3"><div class="text-muted small">粗利</div><div class="fw-bold" id="calc_profit">¥0</div></div>
                            <div class="col-3"><div class="text-muted small">粗利率</div><div class="fw-bold" id="calc_margin">0.0%</div></div>
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
$_clientsJson = json_encode(array_values(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['client_name']], $clients)));
$inlineJs = 'var clientsData = ' . $_clientsJson . ';';
$inlineJs .= 'var csrfToken = ' . json_encode($csrf) . ';';
$inlineJs .= 'var pageYear = ' . (int)$dispYear . '; var pageMonth = ' . (int)$dispMonth . ';';
$inlineJs .= 'var saveCaseApiUrl = ' . json_encode(BASE_PATH . '/public/api/save_case.php') . ';';
$inlineJs .= <<<'JS'

// フィルター非同期更新
function fetchCases() {
    var form = document.getElementById('salesFilterForm');
    var params = new URLSearchParams(new FormData(form));
    params.set('ajax', '1');
    params.delete('page');
    fetch(window.location.pathname + '?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var e = function(id) { return document.getElementById(id); };
            if (e('kpi_revenue')) e('kpi_revenue').textContent = data.kpi.revenue;
            if (e('kpi_profit'))  e('kpi_profit').textContent  = data.kpi.profit;
            if (e('kpi_count'))   e('kpi_count').textContent   = data.kpi.count;
            if (e('kpi_margin'))  e('kpi_margin').textContent  = data.kpi.margin;
            var tbody = document.querySelector('.sales-table tbody');
            if (tbody) tbody.innerHTML = data.tbody;
            var tfoot = document.querySelector('.sales-table tfoot');
            if (tfoot) tfoot.innerHTML = data.tfoot;
            var pgWrap = document.getElementById('paginationWrapper');
            if (pgWrap) pgWrap.innerHTML = data.pagination;
        });
}
(function() {
    var form = document.getElementById('salesFilterForm');
    if (!form) return;
    form.addEventListener('submit', function(e) { e.preventDefault(); fetchCases(); });
    form.querySelectorAll('select').forEach(function(sel) {
        sel.addEventListener('change', fetchCases);
    });
    var searchInp = form.querySelector('input[name="search"]');
    if (searchInp) {
        var _st;
        searchInp.addEventListener('input', function() {
            clearTimeout(_st);
            _st = setTimeout(fetchCases, 400);
        });
    }
})();

// イベント案件フォーム
window.salesCalcAmounts = function() {
    var priceIn  = parseFloat(document.getElementById('unit_price_in')?.value  || 0);
    var priceOut = parseFloat(document.getElementById('unit_price_out')?.value || 0);
    var revenue = Math.round(priceIn);
    var cost    = Math.round(priceOut);
    var profit  = revenue - cost;
    var margin  = revenue > 0 ? (profit / revenue * 100).toFixed(1) : '0.0';
    var revEl = document.getElementById('calc_revenue');    if (revEl)    revEl.textContent = salesFormatYen(revenue);
    var costEl = document.getElementById('calc_cost');      if (costEl)   costEl.textContent = salesFormatYen(cost);
    var profitEl = document.getElementById('calc_profit');
    if (profitEl) { profitEl.textContent = salesFormatYen(profit); profitEl.className = profit >= 0 ? 'amount-positive' : 'amount-negative'; }
    var marginEl = document.getElementById('calc_margin');
    if (marginEl) { marginEl.textContent = margin + '%'; marginEl.className = parseFloat(margin) >= 20 ? 'amount-positive' : (parseFloat(margin) >= 0 ? '' : 'amount-negative'); }
};

// 稼働数スピン
function adjustDays(id, delta) {
    var inp = document.getElementById('spin_' + id);
    inp.value = Math.max(0, (parseInt(inp.value) || 0) + delta);
}

// フラッシュメッセージ
function showCaseFlash(type, msg) {
    var el = document.getElementById('caseFlashMsg');
    if (!el) return;
    el.className = 'alert alert-' + type + ' alert-dismissible fade show mb-2';
    el.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + ' me-1"></i>' + msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    el.style.display = '';
    if (type === 'success') setTimeout(function() { el.style.display = 'none'; }, 4000);
}

// 削除確認（AJAX）
function confirmDelete(id) {
    if (!confirm('この案件を削除しますか？')) return;
    var fd = new FormData();
    fd.append('csrf', csrfToken); fd.append('action', 'delete'); fd.append('id', id);
    fetch(saveCaseApiUrl, {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) { showCaseFlash('success', '案件を削除しました'); fetchCases(); }
            else showCaseFlash('danger', d.error || '削除に失敗しました');
        })
        .catch(function() { showCaseFlash('danger', '通信エラーが発生しました'); });
}

// フォームAJAX送信
(function() {
    var form = document.getElementById('caseFormEl');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('submitBtn');
        var origText = btn.textContent;
        btn.disabled = true; btn.textContent = '保存中...';
        fetch(saveCaseApiUrl, {method: 'POST', body: new FormData(form)})
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false; btn.textContent = origText;
                if (d.success) {
                    var modal = bootstrap.Modal.getInstance(document.getElementById('caseModal'));
                    if (modal) modal.hide();
                    showCaseFlash('success', document.getElementById('form_action').value === 'create' ? '案件を追加しました' : '案件を更新しました');
                    fetchCases();
                } else {
                    showCaseFlash('danger', d.error || '保存に失敗しました');
                }
            })
            .catch(function() { btn.disabled = false; btn.textContent = origText; showCaseFlash('danger', '通信エラーが発生しました'); });
    });
})();

// KPI・合計行の再集計（稼働数更新後のローカル更新用）
function refreshKpi() {
    var totalRev = 0, totalProfit = 0;
    document.querySelectorAll('tbody tr[id^="row_"]').forEach(function(row) {
        if (row.classList.contains('table-secondary')) return;
        var rid = row.id.replace('row_', '');
        totalRev    += parseInt((document.getElementById('rev_' + rid)?.textContent    || '0').replace(/,/g, '')) || 0;
        totalProfit += parseInt((document.getElementById('profit_' + rid)?.textContent || '0').replace(/,/g, '')) || 0;
    });
    var margin = totalRev > 0 ? (totalProfit / totalRev * 100).toFixed(1) : '0.0';
    var e = function(id) { return document.getElementById(id); };
    if (e('kpi_revenue'))   e('kpi_revenue').textContent   = totalRev.toLocaleString();
    if (e('kpi_profit'))    e('kpi_profit').textContent    = totalProfit.toLocaleString();
    if (e('kpi_margin'))    e('kpi_margin').textContent    = margin + '%';
    if (e('tfoot_revenue')) e('tfoot_revenue').textContent = totalRev.toLocaleString();
    if (e('tfoot_profit'))  e('tfoot_profit').textContent  = totalProfit.toLocaleString();
}

// 担当者別内訳を更新
function updateSplit(id, rev, profit) {
    var rEl = document.getElementById('rev_split_' + id);
    var pEl = document.getElementById('profit_split_' + id);
    if (rEl) {
        var repRev = Math.floor(rev / 2);
        rEl.innerHTML = rEl.dataset.rep + ' ' + repRev.toLocaleString() + '<br>' + rEl.dataset.split + ' ' + (rev - repRev).toLocaleString();
    }
    if (pEl) {
        var repPro = Math.floor(profit / 2);
        pEl.innerHTML = pEl.dataset.rep + ' ' + repPro.toLocaleString() + '<br>' + pEl.dataset.split + ' ' + (profit - repPro).toLocaleString();
    }
}

// 金額反映（イベント: 請求単価×稼働数、支払単価×稼働数）
function applyDays(id) {
    var row = document.getElementById('row_' + id);
    var priceIn  = parseInt(row.dataset.priceIn)  || 0;
    var priceOut = parseInt(row.dataset.priceOut) || 0;
    var newDays  = parseInt(document.getElementById('spin_' + id).value) || 0;
    var newRev    = priceIn  * newDays;
    var newCost   = priceOut * newDays;
    var newProfit = newRev - newCost;
    var fd = new FormData();
    fd.append('csrf', csrfToken); fd.append('action', 'update_days'); fd.append('id', id);
    fd.append('new_days', newDays); fd.append('new_rev', newRev); fd.append('new_profit', newProfit);
    fetch(window.location.pathname + window.location.search, {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                document.getElementById('rev_' + id).textContent = d.revenue.toLocaleString();
                document.getElementById('profit_' + id).textContent = d.profit.toLocaleString();
                updateSplit(id, d.revenue, d.profit);
                row.dataset.rev = d.revenue; row.dataset.profit = d.profit;
                refreshKpi();
            }
        });
}

// 取引先 datalist 選択/入力ハンドラ
(function() {
    var inp = document.getElementById('f_client_name_input');
    if (!inp) return;
    inp.addEventListener('input', function() {
        var name = this.value.trim();
        var match = clientsData.find(function(c) { return c.name === name; });
        document.getElementById('f_client_id').value = match ? match.id : '';
        document.getElementById('f_client_name_hidden').value = name;
    });
})();

function toggleAllianceGroup() {
    var wt = document.getElementById('worker_type').value;
    var ag = document.getElementById('alliance_group');
    if (ag) ag.style.display = wt === 'アライアンス' ? 'block' : 'none';
}

// 予定案件選択時の自動入力
function applyPlan(planId) {
    var sel = document.getElementById('f_plan_select');
    if (!sel || !planId) { document.getElementById('f_plan_id').value = ''; return; }
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('f_plan_id').value = planId;
    var client = opt.dataset.client || '';
    var store  = opt.dataset.store  || '';
    var date   = opt.dataset.date   || '';
    if (client) { document.getElementById('f_client_name_input').value = client; document.getElementById('f_client_name_hidden').value = client; document.getElementById('f_client_id').value = ''; }
    if (store)  { document.getElementById('f_store_name').value = store; }
    if (date)   document.getElementById('f_start_date').value = date.substring(0,7);
}

function resetCaseForm() {
    document.getElementById('form_action').value = 'create';
    document.getElementById('form_id').value = '';
    document.getElementById('f_plan_id').value = '';
    document.getElementById('modalTitle').textContent = 'イベント案件追加';
    document.getElementById('submitBtn').textContent = '追加';
    var ps = document.getElementById('f_plan_select'); if (ps) ps.value = '';
    document.getElementById('f_client_name_input').value = '';
    document.getElementById('f_client_id').value = '';
    document.getElementById('f_client_name_hidden').value = '';
    document.getElementById('f_start_date').value = '';
    document.getElementById('f_end_date').value = '';
    document.getElementById('f_sales_rep').value = '';
    document.getElementById('f_manager_name').value = '';
    document.getElementById('f_recruiter_name').value = '';
    document.getElementById('f_case_division').value = '';
    document.getElementById('worker_type').value = '正社員';
    document.getElementById('f_alliance_id').value = '';
    document.getElementById('f_worker_name').value = '';
    document.getElementById('f_carrier').value = '';
    document.getElementById('f_trade_name').value = '';
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
    document.getElementById('f_client_name_input').value = c.client_name || '';
    document.getElementById('f_client_id').value = c.client_id || '';
    document.getElementById('f_client_name_hidden').value = c.client_name || '';
    document.getElementById('f_start_date').value = c.start_date || '';
    document.getElementById('f_end_date').value = c.end_date || '';
    document.getElementById('f_sales_rep').value = c.sales_rep || '';
    document.getElementById('f_manager_name').value = c.manager || '';
    document.getElementById('f_recruiter_name').value = c.recruiter || '';
    document.getElementById('f_case_division').value = c.case_division || '';
    document.getElementById('worker_type').value = c.worker_type || '正社員';
    document.getElementById('f_alliance_id').value = c.alliance_id || '';
    document.getElementById('f_worker_name').value = c.worker_name || '';
    document.getElementById('f_carrier').value = c.carrier || '';
    document.getElementById('f_trade_name').value = c.trade_name || '';
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
