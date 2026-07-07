<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }
requireRole('super_admin', 'company_admin');

$pageTitle = '販管費管理';
$extraCss = ['sales.css'];

$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$summary = getSgaSummary($cid, $year, $month);
$expenses = getSgaExpenses($cid, $year, $month);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-receipt-cutoff me-2"></i>販管費管理</h1>
                <p><span id="sgaMonthLabel"><?= $year ?>年<?= $month ?>月</span>の販管費状況</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem" onclick="sgaChangeMonth(-1)">‹</button>
                    <span class="fw-bold px-2" id="sgaMonthLabel2" style="min-width:120px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem" onclick="sgaChangeMonth(1)">›</button>
                </div>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sgaModal" onclick="sgaResetForm()">
                    <i class="bi bi-plus-lg me-1"></i>新規登録
                </button>
            </div>
        </div>
    </div>

    <!-- KPIカード -->
    <div class="row g-2 mb-4">
        <div class="col-6 col-md-3">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#059669" id="sgaKpiRevenue"><?= number_format($summary['revenue']) ?></div>
                <div class="kpi-label">売上</div>
                <div class="kpi-sub" id="sgaSubRevMonth"><?= $year ?>年<?= $month ?>月</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#3b82f6" id="sgaKpiProfit"><?= number_format($summary['profit']) ?></div>
                <div class="kpi-label">粗利</div>
                <div class="kpi-sub">粗利率: <span id="sgaKpiMargin"><?= $summary['margin'] ?></span>%</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#f59e0b" id="sgaKpiTotal"><?= number_format($summary['sga_total']) ?></div>
                <div class="kpi-label">販管費</div>
                <div class="kpi-sub" id="sgaSubSgaMonth"><?= $year ?>年<?= $month ?>月 合計</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi">
                <div class="kpi-value" id="sgaKpiOperating" style="color:<?= $summary['operating_income'] >= 0 ? '#8b5cf6' : '#dc2626' ?>"><?= number_format($summary['operating_income']) ?></div>
                <div class="kpi-label">営業利益</div>
                <div class="kpi-sub">営業利益率: <span id="sgaKpiOperatingMargin"><?= $summary['operating_margin'] ?></span>%</div>
            </div>
        </div>
    </div>

    <!-- 販管費一覧 -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-1" style="color:#f59e0b"></i>販管費一覧</span>
        </div>
        <div class="table-responsive">
            <table class="table sales-table mb-0">
                <thead>
                    <tr>
                        <th>費目</th>
                        <th>内容</th>
                        <th class="text-end">金額</th>
                        <th class="text-center">編集</th>
                        <th class="text-center">削除</th>
                    </tr>
                </thead>
                <tbody id="sgaTbody">
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-2"></div>読み込み中...
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap gap-4 py-3" style="background:#f9fafb">
            <div>
                <span class="text-muted small me-1">粗利</span>
                <span class="fw-bold" style="color:#3b82f6" id="sgaFooterProfit"><?= number_format($summary['profit']) ?></span>
                <span class="text-muted small ms-1">円</span>
            </div>
            <div>
                <span class="text-muted small me-1">販管費合計</span>
                <span class="fw-bold" style="color:#f59e0b" id="sgaFooterSga"><?= number_format($summary['sga_total']) ?></span>
                <span class="text-muted small ms-1">円</span>
            </div>
            <div>
                <span class="text-muted small me-1">営業利益</span>
                <span class="fw-bold" id="sgaFooterOp" style="color:<?= $summary['operating_income'] >= 0 ? '#8b5cf6' : '#dc2626' ?>"><?= number_format($summary['operating_income']) ?></span>
                <span class="text-muted small ms-1">円</span>
            </div>
        </div>
    </div>
</div>

<!-- 登録・編集モーダル -->
<div class="modal fade" id="sgaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sgaModalTitle">販管費 新規登録</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="sga_id" value="">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">対象年月 <span class="text-danger">*</span></label>
                        <div class="d-flex gap-2">
                            <select id="sga_year" class="form-select">
                                <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                                <option value="<?= $y ?>"><?= $y ?>年</option>
                                <?php endfor; ?>
                            </select>
                            <select id="sga_month" class="form-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>"><?= $m ?>月</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">費目 <span class="text-danger">*</span></label>
                        <input type="text" id="sga_category" class="form-control" list="sgaCategoryList" maxlength="100" required>
                        <datalist id="sgaCategoryList">
                            <option value="家賃"><option value="通信費"><option value="水道光熱費">
                            <option value="広告宣伝費"><option value="消耗品費"><option value="交通費">
                            <option value="福利厚生費"><option value="その他">
                        </datalist>
                    </div>
                    <div class="col-12">
                        <label class="form-label">金額（円） <span class="text-danger">*</span></label>
                        <input type="number" id="sga_amount" class="form-control" min="0" step="1" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">備考</label>
                        <textarea id="sga_note" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary" id="sgaSubmitBtn" onclick="sgaSubmit()">登録</button>
            </div>
        </div>
    </div>
</div>

<?php
$inlineJs = 'var SGA_API = ' . json_encode(BASE_PATH . '/public/api/sga_expense.php') . ';';
$inlineJs .= 'var sgaYear = ' . $year . '; var sgaMonth = ' . $month . ';';
$inlineJs .= 'var sgaExpensesInit = ' . json_encode($expenses, JSON_UNESCAPED_UNICODE) . ';';
$inlineJs .= <<<'JS'

var sgaCurrentExpenses = [];

function sgaFmt(n) {
    return parseInt(n || 0).toLocaleString();
}
function sgaH(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function sgaRenderList(expenses) {
    sgaCurrentExpenses = expenses || [];
    var tbody = document.getElementById('sgaTbody');
    if (sgaCurrentExpenses.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">販管費が登録されていません</td></tr>';
        return;
    }
    var html = '';
    sgaCurrentExpenses.forEach(function(e, idx) {
        html += '<tr>';
        html += '<td class="fw-medium">' + sgaH(e.category) + '</td>';
        html += '<td>' + sgaH(e.note || '') + '</td>';
        html += '<td class="amount">' + sgaFmt(e.amount) + '</td>';
        html += '<td class="text-center"><button class="btn btn-sm btn-outline-primary" onclick="sgaEdit(' + idx + ')"><i class="bi bi-pencil"></i></button></td>';
        html += '<td class="text-center"><button class="btn btn-sm btn-outline-danger" onclick="sgaDelete(' + idx + ')"><i class="bi bi-trash"></i></button></td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;
}

function sgaRenderSummary(s) {
    document.getElementById('sgaKpiRevenue').textContent = sgaFmt(s.revenue);
    document.getElementById('sgaKpiProfit').textContent = sgaFmt(s.profit);
    document.getElementById('sgaKpiMargin').textContent = s.margin;
    document.getElementById('sgaKpiTotal').textContent = sgaFmt(s.sga_total);
    var opEl = document.getElementById('sgaKpiOperating');
    opEl.textContent = sgaFmt(s.operating_income);
    opEl.style.color = s.operating_income >= 0 ? '#8b5cf6' : '#dc2626';
    document.getElementById('sgaKpiOperatingMargin').textContent = s.operating_margin;
    // フッター合計行
    document.getElementById('sgaFooterProfit').textContent = sgaFmt(s.profit);
    document.getElementById('sgaFooterSga').textContent = sgaFmt(s.sga_total);
    var footerOp = document.getElementById('sgaFooterOp');
    footerOp.textContent = sgaFmt(s.operating_income);
    footerOp.style.color = s.operating_income >= 0 ? '#8b5cf6' : '#dc2626';
}

function sgaUpdateMonthLabel() {
    var label = sgaYear + '年' + sgaMonth + '月';
    document.getElementById('sgaMonthLabel').textContent = label;
    document.getElementById('sgaMonthLabel2').textContent = label;
    document.getElementById('sgaSubRevMonth').textContent = label;
    document.getElementById('sgaSubSgaMonth').textContent = label + ' 合計';
}

function sgaLoad() {
    var tbody = document.getElementById('sgaTbody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>読み込み中...</td></tr>';
    fetch(SGA_API + '?year=' + sgaYear + '&month=' + sgaMonth)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">' + sgaH(data.error) + '</td></tr>';
                return;
            }
            sgaRenderSummary(data.summary);
            sgaRenderList(data.expenses);
        })
        .catch(function(e) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">データ取得エラー: ' + sgaH(e.message) + '</td></tr>';
        });
}

function sgaChangeMonth(delta) {
    sgaMonth += delta;
    if (sgaMonth < 1) { sgaMonth = 12; sgaYear--; }
    else if (sgaMonth > 12) { sgaMonth = 1; sgaYear++; }
    sgaUpdateMonthLabel();
    var url = new URL(window.location.href);
    url.searchParams.set('year', sgaYear);
    url.searchParams.set('month', sgaMonth);
    window.history.replaceState(null, '', url);
    sgaLoad();
}

function sgaResetForm() {
    document.getElementById('sga_id').value = '';
    document.getElementById('sga_year').value = sgaYear;
    document.getElementById('sga_month').value = sgaMonth;
    document.getElementById('sga_category').value = '';
    document.getElementById('sga_amount').value = '';
    document.getElementById('sga_note').value = '';
    document.getElementById('sgaModalTitle').textContent = '販管費 新規登録';
    document.getElementById('sgaSubmitBtn').textContent = '登録';
}

function sgaEdit(idx) {
    var e = sgaCurrentExpenses[idx];
    if (!e) return;
    document.getElementById('sga_id').value = e.id;
    document.getElementById('sga_year').value = e.target_year;
    document.getElementById('sga_month').value = e.target_month;
    document.getElementById('sga_category').value = e.category;
    document.getElementById('sga_amount').value = e.amount;
    document.getElementById('sga_note').value = e.note || '';
    document.getElementById('sgaModalTitle').textContent = '販管費 編集 #' + e.id;
    document.getElementById('sgaSubmitBtn').textContent = '更新';
    new bootstrap.Modal(document.getElementById('sgaModal')).show();
}

function sgaSubmit() {
    var id = document.getElementById('sga_id').value;
    var category = document.getElementById('sga_category').value.trim();
    var amount = parseInt(document.getElementById('sga_amount').value, 10);
    if (!category) { alert('費目を入力してください'); return; }
    if (isNaN(amount) || amount < 0) { alert('金額は0以上で入力してください'); return; }

    var payload = {
        target_year: parseInt(document.getElementById('sga_year').value, 10),
        target_month: parseInt(document.getElementById('sga_month').value, 10),
        category: category,
        amount: amount,
        note: document.getElementById('sga_note').value.trim(),
    };

    var btn = document.getElementById('sgaSubmitBtn');
    btn.disabled = true;

    var req;
    if (id) {
        payload.id = parseInt(id, 10);
        req = fetch(SGA_API, { method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
    } else {
        req = fetch(SGA_API, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
    }

    req.then(function(r) { return r.json(); })
        .then(function(res) {
            btn.disabled = false;
            if (!res.success) { alert('保存に失敗しました: ' + (res.error || '')); return; }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('sgaModal')).hide();
            sgaLoad();
        })
        .catch(function(e) {
            btn.disabled = false;
            alert('通信エラー: ' + e.message);
        });
}

function sgaDelete(idx) {
    var e = sgaCurrentExpenses[idx];
    if (!e) return;
    if (!confirm('この販管費を削除しますか？')) return;
    fetch(SGA_API + '?id=' + e.id, { method: 'DELETE' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) { alert('削除に失敗しました: ' + (res.error || '')); return; }
            sgaLoad();
        })
        .catch(function(e) { alert('通信エラー: ' + e.message); });
}

document.addEventListener('DOMContentLoaded', function() {
    sgaRenderList(sgaExpensesInit);
});
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
