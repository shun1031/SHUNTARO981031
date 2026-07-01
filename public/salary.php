<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

requireRole('super_admin', 'company_admin');

$pageTitle = '給与管理';
$extraCss  = ['sales.css'];

// デフォルト支払予定月 = 翌月
$todayDt    = new DateTime();
$todayDt->modify('+1 month');
$defPayYear  = (int)$todayDt->format('Y');
$defPayMonth = (int)$todayDt->format('n');

$payYear  = (int)($_GET['pay_year']  ?? $defPayYear);
$payMonth = (int)($_GET['pay_month'] ?? $defPayMonth);

// 稼働月・インセンティブ月
$workDt = new DateTime("{$payYear}-{$payMonth}-01");
$workDt->modify('-1 month');
$workYear  = (int)$workDt->format('Y');
$workMonth = (int)$workDt->format('n');

$incDt = clone $workDt;
$incDt->modify('-1 month');
$incYear  = (int)$incDt->format('Y');
$incMonth = (int)$incDt->format('n');

// 月カード用: 表示範囲（前2ヶ月〜後4ヶ月）
$cardMonths = [];
$cursor = new DateTime("{$payYear}-{$payMonth}-01");
$cursor->modify('-2 months');
for ($i = 0; $i < 8; $i++) {
    $cy = (int)$cursor->format('Y');
    $cm = (int)$cursor->format('n');
    $wy = (int)$cursor->modify('-1 month')->format('Y');
    $wm = (int)$cursor->format('n');
    $cursor->modify('+1 month'); // restore
    $iy = (int)$cursor->modify('-2 months')->format('Y');
    $im = (int)$cursor->format('n');
    $cursor->modify('+2 months'); // restore

    $cardMonths[] = [
        'pay_year' => $cy, 'pay_month' => $cm,
        'work_year' => $wy, 'work_month' => $wm,
        'inc_year' => $iy,  'inc_month' => $im,
        'selected' => ($cy === $payYear && $cm === $payMonth),
    ];
    $cursor->modify('+1 month');
}

// フィルタオプション
$db      = getDB();
$clients = getSalesClients($cid);

// 店舗一覧（常勤・自社外注のみ）
$storeStmt = $db->prepare("SELECT DISTINCT store_name FROM sales_cases
    WHERE company_id=? AND case_type='regular' AND worker_type='自社外注'
      AND store_name != '' ORDER BY store_name");
$storeStmt->execute([$cid]);
$stores = array_column($storeStmt->fetchAll(), 'store_name');

// 営業担当一覧
$repStmt = $db->prepare("SELECT DISTINCT sales_rep FROM sales_cases
    WHERE company_id=? AND case_type='regular' AND worker_type='自社外注'
      AND sales_rep != '' ORDER BY sales_rep");
$repStmt->execute([$cid]);
$salesReps = array_column($repStmt->fetchAll(), 'sales_rep');

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.salary-month-scroller{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin}
.salary-month-scroller::-webkit-scrollbar{height:4px}
.salary-month-scroller::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:2px}
.salary-month-card{flex:0 0 170px;border:2px solid #e5e7eb;border-radius:10px;padding:12px 14px;cursor:pointer;background:#fff;transition:all .18s;white-space:nowrap}
.salary-month-card:hover{border-color:#93c5fd;background:#eff6ff}
.salary-month-card.selected{border-color:#2563eb;background:#eff6ff}
.salary-month-card .card-title{font-size:.78rem;font-weight:700;color:#1d4ed8;margin-bottom:6px;line-height:1.3}
.salary-month-card .card-row{font-size:.72rem;color:#6b7280;line-height:1.6}
.salary-month-card .card-row span{color:#374151;font-weight:500}

.info-box{border-radius:10px;padding:14px 18px;margin-bottom:6px}
.info-box-blue{background:#eff6ff;border:1px solid #bfdbfe}
.info-box-purple{background:#f5f3ff;border:1px solid #ddd6fe}
.info-box .title{font-size:.82rem;font-weight:700;margin-bottom:4px}
.info-box .body{font-size:.76rem;color:#6b7280;line-height:1.7}
.info-box .example{font-size:.72rem;color:#d97706;margin-top:4px}

.salary-table th{background:#f1f5f9;font-size:.8rem;font-weight:600;padding:10px 12px;white-space:nowrap;vertical-align:middle}
.salary-table td{font-size:.85rem;padding:10px 12px;vertical-align:middle}
.salary-table tbody tr:hover{background:#f8fafc}
.salary-table .amount-blue{color:#2563eb;font-weight:700}
.salary-table .amount-orange{color:#d97706;font-weight:600}
.salary-table tfoot td{font-weight:700;background:#f0fdf4;border-top:2px solid #d1fae5}

.summary-cards{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.summary-card{flex:1 1 150px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;text-align:center}
.summary-card .s-label{font-size:.72rem;color:#6b7280;margin-bottom:4px}
.summary-card .s-value{font-size:1.05rem;font-weight:700;color:#1e293b}
.summary-card.highlight .s-value{color:#2563eb}

.detail-table th{background:#f8fafc;font-size:.78rem;font-weight:600;padding:8px 10px}
.detail-table td{font-size:.82rem;padding:8px 10px}

@media(max-width:768px){
  .salary-month-card{flex:0 0 150px}
  .salary-table th,.salary-table td{font-size:.75rem;padding:7px 8px}
  .summary-card{flex:1 1 120px}
}
</style>

<div class="container-fluid">
    <!-- ページヘッダー -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-cash-stack me-2"></i>給与管理</h1>
                <p class="mb-0" style="font-size:.85rem;color:#6b7280">常勤案件でスタッフ区分が「自社外注」のスタッフの給与一覧です。</p>
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#infoPanel">
                <i class="bi bi-info-circle me-1"></i>給与の計算・支払タイミングについて
            </button>
        </div>
    </div>

    <!-- 計算ルール説明 -->
    <div class="collapse mb-3" id="infoPanel">
        <div class="card card-body p-3">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="info-box info-box-blue">
                        <div class="title" style="color:#1d4ed8">常勤案件売上（7割）</div>
                        <div class="body">スタッフが稼働した翌月末の給与に反映します。<br>
                        例）7月稼働 → 8月末の給与に反映</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-box info-box-purple">
                        <div class="title" style="color:#7c3aed">インセンティブ費用</div>
                        <div class="body">担当者別売上のインセンティブ費用は、対象月の<strong style="color:#d97706">2ヶ月後末</strong>の給与に反映します。<br>
                        例）6月分 → <span style="color:#d97706">8月末の給与に反映</span></div>
                    </div>
                </div>
            </div>
            <div class="text-muted" style="font-size:.8rem">
                総支給額 ＝ 常勤案件売上（7割）【前月稼働分】＋ インセンティブ費用【2ヶ月前の月分】
            </div>
        </div>
    </div>

    <!-- 支払予定月カード -->
    <div class="mb-3">
        <div class="fw-medium mb-2" style="font-size:.85rem">支払予定月を選択してください</div>
        <div class="salary-month-scroller" id="monthScroller">
            <?php foreach ($cardMonths as $c): ?>
            <div class="salary-month-card <?= $c['selected'] ? 'selected' : '' ?>"
                 data-pay-year="<?= $c['pay_year'] ?>" data-pay-month="<?= $c['pay_month'] ?>"
                 onclick="selectPayMonth(<?= $c['pay_year'] ?>, <?= $c['pay_month'] ?>)">
                <div class="card-title"><?= $c['pay_year'] ?>年<?= $c['pay_month'] ?>月末 支払い予定</div>
                <div class="card-row">常勤売上：<span><?= $c['work_year'] ?>年<?= $c['work_month'] ?>月稼働分</span></div>
                <div class="card-row">インセンティブ：<span><?= $c['inc_year'] ?>年<?= $c['inc_month'] ?>月分</span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 検索フィルタ -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-auto">
                    <label class="form-label form-label-sm mb-1">取引先</label>
                    <select id="f_client" class="form-select form-select-sm" style="min-width:110px">
                        <option value="">すべて</option>
                        <?php foreach ($clients as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= h($cl['client_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label form-label-sm mb-1">店舗</label>
                    <select id="f_store" class="form-select form-select-sm" style="min-width:100px">
                        <option value="">すべて</option>
                        <?php foreach ($stores as $s): ?>
                        <option value="<?= h($s) ?>"><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label form-label-sm mb-1">営業担当</label>
                    <select id="f_rep" class="form-select form-select-sm" style="min-width:100px">
                        <option value="">すべて</option>
                        <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= h($rep) ?>"><?= h($rep) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label form-label-sm mb-1">スタッフ名</label>
                    <input type="text" id="f_worker" class="form-control form-control-sm" placeholder="名前で絞込" style="min-width:110px">
                </div>
                <div class="col-auto ms-auto d-flex gap-2 align-items-end">
                    <button class="btn btn-primary btn-sm" onclick="loadSalaryData()">
                        <i class="bi bi-search me-1"></i>検索
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-1"></i>エクスポート
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="doExport('csv');return false"><i class="bi bi-filetype-csv me-2"></i>CSV</a></li>
                            <li><a class="dropdown-item" href="#" onclick="doExport('excel_xml');return false"><i class="bi bi-file-earmark-excel me-2"></i>Excel</a></li>
                            <li><a class="dropdown-item" href="#" onclick="doPdfPrint();return false"><i class="bi bi-printer me-2"></i>PDF（印刷）</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 給与一覧テーブル -->
    <div class="card" id="salaryTableCard">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <span class="fw-medium" id="tableTitle">給与一覧（<?= $payYear ?>年<?= $payMonth ?>月末 支払い予定）</span>
            <span class="text-muted" style="font-size:.8rem" id="workMonthLabel">
                常勤：<?= $workYear ?>年<?= $workMonth ?>月稼働分 ／ インセンティブ：<?= $incYear ?>年<?= $incMonth ?>月分
            </span>
        </div>
        <div class="table-responsive">
            <table class="table salary-table mb-0" id="salaryTable">
                <thead>
                    <tr>
                        <th>スタッフ名</th>
                        <th class="text-center">案件数</th>
                        <th class="text-end">
                            常勤案件売上（7割）
                            <div class="fw-normal text-muted" id="thWorkMonth" style="font-size:.7rem">(<?= $workYear ?>年<?= $workMonth ?>月稼働分)</div>
                        </th>
                        <th class="text-end">
                            インセンティブ費用
                            <div class="fw-normal text-muted" id="thIncMonth" style="font-size:.7rem">(<?= $incYear ?>年<?= $incMonth ?>月分)</div>
                        </th>
                        <th class="text-end">総支給額</th>
                        <th class="text-center">詳細</th>
                    </tr>
                </thead>
                <tbody id="salaryTbody">
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-2"></div>読み込み中...
                    </td></tr>
                </tbody>
                <tfoot id="salaryTfoot" style="display:none">
                    <tr>
                        <td colspan="2" class="text-end">合計</td>
                        <td class="text-end" id="tf_regular">¥0</td>
                        <td class="text-end amount-orange" id="tf_incentive">¥0</td>
                        <td class="text-end amount-blue" id="tf_total">¥0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ページネーション -->
    <div id="paginationArea" class="mt-2"></div>

    <!-- 集計カード -->
    <div class="summary-cards" id="summaryCards" style="display:none">
        <div class="summary-card">
            <div class="s-label">対象スタッフ数</div>
            <div class="s-value" id="sum_staff">0<span style="font-size:.8rem;color:#6b7280">人</span></div>
        </div>
        <div class="summary-card">
            <div class="s-label">案件数合計</div>
            <div class="s-value" id="sum_cases">0<span style="font-size:.8rem;color:#6b7280">件</span></div>
        </div>
        <div class="summary-card">
            <div class="s-label">常勤案件売上（7割）合計</div>
            <div class="s-value" id="sum_regular">¥0</div>
        </div>
        <div class="summary-card">
            <div class="s-label">インセンティブ費用合計</div>
            <div class="s-value" style="color:#d97706" id="sum_incentive">¥0</div>
        </div>
        <div class="summary-card highlight">
            <div class="s-label">総支給額合計</div>
            <div class="s-value" id="sum_total">¥0</div>
        </div>
    </div>

    <p class="text-muted mt-3" style="font-size:.75rem">
        ※ 表示されているのは、スタッフ区分が「自社外注」の常勤案件に所属するスタッフのみです。
    </p>
</div>

<!-- 詳細モーダル -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="detailModalTitle">給与明細</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailModalBody"></div>
        </div>
    </div>
</div>

<?php
$csrf = getCsrfToken();
$inlineJs = 'var SALARY_API = ' . json_encode(BASE_PATH . '/public/api/salary.php') . ';';
$inlineJs .= 'var initPayYear = ' . $payYear . '; var initPayMonth = ' . $payMonth . ';';
$inlineJs .= <<<'JS'

var currentPayYear  = initPayYear;
var currentPayMonth = initPayMonth;
var allStaff = [];
var currentPage = 1;
var perPage = 20;

function yen(n) {
    return '¥' + parseInt(n || 0).toLocaleString();
}

function selectPayMonth(y, m) {
    currentPayYear  = y;
    currentPayMonth = m;
    currentPage = 1;

    document.querySelectorAll('.salary-month-card').forEach(function(card) {
        var isSelected = parseInt(card.dataset.payYear) === y && parseInt(card.dataset.payMonth) === m;
        card.classList.toggle('selected', isSelected);
        if (isSelected) card.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
    });

    loadSalaryData();
}

function loadSalaryData() {
    var tbody = document.getElementById('salaryTbody');
    var tfoot = document.getElementById('salaryTfoot');
    var sumCards = document.getElementById('summaryCards');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>読み込み中...</td></tr>';
    tfoot.style.display = 'none';
    sumCards.style.display = 'none';

    var params = new URLSearchParams({
        pay_year:    currentPayYear,
        pay_month:   currentPayMonth,
        client_id:   document.getElementById('f_client').value,
        store_name:  document.getElementById('f_store').value,
        sales_rep:   document.getElementById('f_rep').value,
        worker_name: document.getElementById('f_worker').value,
    });

    fetch(SALARY_API + '?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) { renderSalaryData(data); })
        .catch(function(e) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">データ取得エラー: ' + e.message + '</td></tr>';
        });
}

function renderSalaryData(data) {
    allStaff = data.staff;
    currentPage = 1;

    // ヘッダー更新
    document.getElementById('tableTitle').textContent =
        '給与一覧（' + data.pay_year + '年' + data.pay_month + '月末 支払い予定）';
    document.getElementById('workMonthLabel').textContent =
        '常勤：' + data.work_year + '年' + data.work_month + '月稼働分　／　インセンティブ：' + data.inc_year + '年' + data.inc_month + '月分';
    document.getElementById('thWorkMonth').textContent =
        '(' + data.work_year + '年' + data.work_month + '月稼働分)';
    document.getElementById('thIncMonth').textContent =
        '(' + data.inc_year + '年' + data.inc_month + '月分)';

    renderPage(data.summary, data.work_year, data.work_month, data.inc_year, data.inc_month,
               data.pay_year, data.pay_month);
}

function renderPage(summary, wy, wm, iy, im, py, pm) {
    var tbody = document.getElementById('salaryTbody');
    var start = (currentPage - 1) * perPage;
    var pageStaff = allStaff.slice(start, start + perPage);

    if (pageStaff.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">データがありません</td></tr>';
        document.getElementById('salaryTfoot').style.display = 'none';
        document.getElementById('summaryCards').style.display = 'none';
        document.getElementById('paginationArea').innerHTML = '';
        return;
    }

    var html = '';
    pageStaff.forEach(function(s, i) {
        var rank = start + i + 1;
        html += '<tr>';
        html += '<td class="fw-medium">' + h(s.worker_name) + '</td>';
        html += '<td class="text-center">' + s.case_count + '件</td>';
        html += '<td class="text-end">' + yen(s.regular_salary) + '</td>';
        html += '<td class="text-end amount-orange">' + (s.incentive > 0 ? yen(s.incentive) : '<span class="text-muted">¥0</span>') + '</td>';
        html += '<td class="text-end amount-blue">' + yen(s.total) + '</td>';
        html += '<td class="text-center"><button class="btn btn-sm btn-outline-primary" onclick="showDetail(' + rank + ')">詳細を見る</button></td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;

    // tfoot
    var tfoot = document.getElementById('salaryTfoot');
    tfoot.style.display = '';
    document.getElementById('tf_regular').textContent   = yen(summary.regular_total);
    document.getElementById('tf_incentive').textContent = yen(summary.incentive_total);
    document.getElementById('tf_total').textContent     = yen(summary.grand_total);

    // 集計カード
    var cards = document.getElementById('summaryCards');
    cards.style.display = 'flex';
    document.getElementById('sum_staff').innerHTML      = summary.staff_count + '<span style="font-size:.8rem;color:#6b7280">人</span>';
    document.getElementById('sum_cases').innerHTML      = summary.case_count  + '<span style="font-size:.8rem;color:#6b7280">件</span>';
    document.getElementById('sum_regular').textContent  = yen(summary.regular_total);
    document.getElementById('sum_incentive').textContent= yen(summary.incentive_total);
    document.getElementById('sum_total').textContent    = yen(summary.grand_total);

    // ページネーション
    var totalPages = Math.ceil(allStaff.length / perPage);
    var pagHtml = '';
    if (totalPages > 1) {
        pagHtml = '<nav><ul class="pagination pagination-sm justify-content-center mt-2">';
        for (var p = 1; p <= totalPages; p++) {
            pagHtml += '<li class="page-item' + (p === currentPage ? ' active' : '') + '">';
            pagHtml += '<a class="page-link" href="#" onclick="gotoPage(' + p + ', arguments[0])">' + p + '</a></li>';
        }
        pagHtml += '</ul></nav>';
    }
    document.getElementById('paginationArea').innerHTML = pagHtml;
}

function gotoPage(p, e) {
    if (e) e.preventDefault();
    currentPage = p;
    // re-render with stored summary (need to track it)
    var summaryEl = {
        staff_count:     allStaff.length,
        case_count:      allStaff.reduce(function(a,s){return a+s.case_count;},0),
        regular_total:   allStaff.reduce(function(a,s){return a+s.regular_salary;},0),
        incentive_total: allStaff.reduce(function(a,s){return a+s.incentive;},0),
        grand_total:     allStaff.reduce(function(a,s){return a+s.total;},0),
    };
    var wl = document.getElementById('thWorkMonth').textContent.replace(/[()年月稼働分]/g,'').split('年');
    var il = document.getElementById('thIncMonth').textContent.replace(/[()年月分]/g,'').split('年');
    renderPage(summaryEl, parseInt(wl[0]), parseInt(wl[1]), parseInt(il[0]), parseInt(il[1]),
               currentPayYear, currentPayMonth);
}

function h(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showDetail(rank) {
    var s = allStaff[rank - 1];
    if (!s) return;
    var wl = document.getElementById('thWorkMonth').textContent.replace(/[()]/g,'').trim();
    var il = document.getElementById('thIncMonth').textContent.replace(/[()]/g,'').trim();
    var title = document.getElementById('tableTitle').textContent;

    var html = '<div class="row g-3 mb-3">';
    html += '<div class="col-4"><div class="text-muted small">支払予定月</div><div class="fw-medium">' + currentPayYear + '年' + currentPayMonth + '月末</div></div>';
    html += '<div class="col-4"><div class="text-muted small">対象稼働月</div><div class="fw-medium">' + wl + '</div></div>';
    html += '<div class="col-4"><div class="text-muted small">対象インセンティブ月</div><div class="fw-medium">' + il + '</div></div>';
    html += '</div>';

    // 案件一覧
    html += '<h6 class="fw-bold mb-2">案件一覧</h6>';
    html += '<div class="table-responsive mb-3"><table class="table table-sm detail-table mb-0"><thead><tr>';
    html += '<th>取引先</th><th>店舗</th><th>キャリア</th><th>営業担当</th>';
    html += '<th class="text-end">案件売上</th><th class="text-end">7割金額</th>';
    html += '</tr></thead><tbody>';
    var caseTotal = 0;
    s.cases.forEach(function(c) {
        caseTotal += c.salary70;
        html += '<tr><td>' + h(c.client_name) + '</td><td>' + h(c.store_name) + '</td>';
        html += '<td>' + h(c.carrier) + '</td><td>' + h(c.sales_rep) + '</td>';
        html += '<td class="text-end">' + yen(c.revenue) + '</td>';
        html += '<td class="text-end fw-medium" style="color:#0369a1">' + yen(c.salary70) + '</td></tr>';
    });
    html += '<tr class="fw-bold" style="background:#f0fdf4">';
    html += '<td colspan="4" class="text-end">小計</td>';
    html += '<td></td><td class="text-end" style="color:#0369a1">' + yen(caseTotal) + '</td>';
    html += '</tr></tbody></table></div>';

    // インセンティブ内訳
    html += '<h6 class="fw-bold mb-2">インセンティブ内訳</h6>';
    html += '<div class="table-responsive mb-3"><table class="table table-sm detail-table mb-0"><tbody>';
    html += '<tr><td>担当者別売上 粗利（分割後）</td><td class="text-end">' + yen(s.incentive_detail.split_profit) + '</td></tr>';
    html += '<tr><td>インセンティブ率</td><td class="text-end">' + (s.incentive_detail.rate * 100).toFixed(0) + '%</td></tr>';
    html += '<tr class="fw-bold" style="background:#fffbeb"><td style="color:#d97706">インセンティブ費用</td>';
    html += '<td class="text-end" style="color:#d97706">' + yen(s.incentive_detail.amount) + '</td></tr>';
    html += '</tbody></table></div>';

    // 給与合計
    html += '<div class="p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe">';
    html += '<div class="d-flex justify-content-between align-items-center">';
    html += '<span class="fw-bold">給与合計</span>';
    html += '<span class="fw-bold fs-5" style="color:#1d4ed8">' + yen(s.total) + '</span></div>';
    html += '<div class="d-flex justify-content-end gap-4 mt-1" style="font-size:.8rem;color:#6b7280">';
    html += '<span>常勤：' + yen(s.regular_salary) + '</span>';
    html += '<span>インセンティブ：' + yen(s.incentive) + '</span></div></div>';

    document.getElementById('detailModalTitle').textContent = s.worker_name + ' さんの給与明細';
    document.getElementById('detailModalBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function buildExportParams() {
    return new URLSearchParams({
        pay_year:    currentPayYear,
        pay_month:   currentPayMonth,
        client_id:   document.getElementById('f_client').value,
        store_name:  document.getElementById('f_store').value,
        sales_rep:   document.getElementById('f_rep').value,
        worker_name: document.getElementById('f_worker').value,
    });
}

function doExport(type) {
    var params = buildExportParams();
    params.set('export', type);
    window.location.href = SALARY_API + '?' + params.toString();
}

function doPdfPrint() {
    var params = buildExportParams();
    var printUrl = SALARY_API.replace('/api/salary.php', '/salary_print.php') + '?' + params.toString();
    window.open(printUrl, '_blank');
}

// 検索フィールドでEnterキー
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('f_worker').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') loadSalaryData();
    });
    // 初期ロード
    loadSalaryData();
    // 選択中カードにスクロール
    var sel = document.querySelector('.salary-month-card.selected');
    if (sel) setTimeout(function() {
        sel.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
    }, 100);
});
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
