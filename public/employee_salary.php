<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }
requireRole('super_admin', 'company_admin');

$pageTitle = '正社員給与管理';
$extraCss  = ['sales.css'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$db = getDB();

// 正社員のみ取得（社員一覧の正社員タブと同一判定）
$empStmt = $db->prepare('SELECT id, name, name_kana, employee_number, department, employment_type, employment_subtype FROM employees WHERE company_id = ? AND is_active = 1 ORDER BY employee_number, name');
$empStmt->execute([$cid]);
$seishainList = [];
foreach ($empStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $t = $e['employment_type'] ?? '';
    $s = $e['employment_subtype'] ?? '';
    if (in_array($t, ['自社外注', '個人外注', 'アライアンス', 'アルバイト'], true)) continue;
    if ($t === '自社' && in_array($s, ['外注', 'アルバイト'], true)) continue;
    $seishainList[] = [
        'id' => (int)$e['id'],
        'name' => $e['name'],
        'kana' => $e['name_kana'] ?? '',
        'number' => $e['employee_number'] ?? '',
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.es-sec-title { font-size:.8rem; font-weight:700; color:#2563eb; border-bottom:2px solid #dbeafe; padding-bottom:3px; margin-bottom:8px; }
.es-item { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.es-item .es-label { flex:0 0 108px; font-size:.74rem; color:#374151; }
.es-item input { max-width:120px; }
.es-item .es-unit { font-size:.7rem; color:#9ca3af; flex:0 0 24px; }
.es-calc-row { display:flex; align-items:center; justify-content:space-between; background:#f9fafb; border-radius:.35rem; padding:4px 8px; margin-bottom:4px; }
.es-calc-row .es-calc-label { font-size:.76rem; font-weight:600; }
.es-calc-row .es-calc-val { font-weight:700; font-size:.9rem; }
@media (max-width: 576px) { .es-item .es-label { flex-basis:96px; font-size:.7rem; } }
</style>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-wallet2 me-2"></i>正社員給与管理</h1>
                <p>給与明細をアップロードすると、AIが自動で各項目を読み取り入力します</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="bi bi-filetype-pdf me-1"></i>PDF保存</button>
            </div>
        </div>
    </div>

    <!-- 検索バー -->
    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-auto d-flex align-items-center gap-2">
                    <span class="fw-semibold small">対象年月</span>
                    <input type="month" id="esMonth" class="form-control form-control-sm" style="width:160px" value="<?= sprintf('%04d-%02d', $year, $month) ?>">
                </div>
                <div class="col-auto d-flex align-items-center gap-2">
                    <span class="fw-semibold small">社員検索</span>
                    <select id="esEmployee" class="form-select form-select-sm" style="min-width:220px">
                        <option value="">-- 社員を選択 --</option>
                    </select>
                </div>
                <div class="col-auto">
                    <input type="text" id="esEmpFilter" class="form-control form-control-sm" placeholder="社員名で絞り込み" style="width:180px">
                </div>
            </div>
            <div class="text-muted mt-2" style="font-size:.75rem">※ 社員一覧の「正社員」に登録されているスタッフが対象です。</div>
        </div>
    </div>

    <!-- 前月との比較 -->
    <div class="card mb-3" id="esCompareCard" style="display:none">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center flex-wrap gap-4">
                <span class="fw-semibold small" id="esCompareTitle">前月との比較</span>
                <div><span class="text-muted small me-1">総支給額</span><span class="fw-bold" id="esCmpPay">-</span></div>
                <div><span class="text-muted small me-1">控除合計額</span><span class="fw-bold" id="esCmpDed">-</span></div>
                <div><span class="text-muted small me-1">差引支給額</span><span class="fw-bold" id="esCmpNet">-</span></div>
            </div>
        </div>
    </div>

    <div class="row g-3" id="esMainRow" style="display:none">
        <!-- 給与明細アップロード -->
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-cloud-arrow-up me-1" style="color:#3b82f6"></i>給与明細アップロード</div>
                <div class="card-body">
                    <div class="border rounded text-center p-4 mb-3" style="border-style:dashed !important;background:#f9fafb">
                        <i class="bi bi-cloud-arrow-up" style="font-size:2rem;color:#3b82f6"></i>
                        <div class="fw-semibold small mt-2">給与明細をアップロードしてください</div>
                        <div class="text-muted" style="font-size:.7rem">画像ファイル（JPG, PNG, PDF）に対応</div>
                        <label class="btn btn-primary btn-sm mt-2 mb-0">
                            ファイルを選択
                            <input type="file" id="esSlipFile" accept="image/jpeg,image/png,application/pdf" hidden>
                        </label>
                    </div>
                    <div id="esSlipPreviewWrap" style="display:none">
                        <div class="fw-semibold small mb-2">アップロードされた画像</div>
                        <img id="esSlipPreview" src="" alt="給与明細" class="img-fluid border rounded" style="max-height:320px;display:block;margin:0 auto">
                        <div class="text-center mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('esSlipFile').click()">再アップロード</button>
                        </div>
                    </div>
                    <div id="esOcrStatus" class="mt-3" style="display:none"></div>
                    <div id="esOcrWarn" class="mt-2" style="display:none"></div>
                    <div class="alert alert-secondary py-2 small mt-3 mb-0" style="font-size:.72rem">
                        <i class="bi bi-lightbulb me-1"></i>AIが自動で給与明細の内容を読み取りますが、誤認識する場合があります。必ず内容をご確認の上、保存してください。
                    </div>
                </div>
            </div>
        </div>

        <!-- 入力・確認 -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pencil-square me-1" style="color:#059669"></i>給与明細の入力・確認</div>
                <div class="card-body">
                    <!-- 従業員情報 -->
                    <div class="border rounded p-2 mb-3" style="background:#f9fafb">
                        <div class="fw-semibold small mb-2" style="color:#2563eb">従業員情報</div>
                        <div class="row g-1" style="font-size:.8rem">
                            <div class="col-6"><span class="text-muted me-2">会社名</span><span class="fw-semibold" id="esInfoCompany">-</span></div>
                            <div class="col-6"><span class="text-muted me-2">支給年月</span><span class="fw-semibold" id="esInfoMonth">-</span></div>
                            <div class="col-6"><span class="text-muted me-2">氏名</span><span class="fw-semibold" id="esInfoName">-</span></div>
                        </div>
                    </div>

                    <!-- 各セクション（JSで生成） -->
                    <div id="esSections"></div>

                    <!-- コメント欄 -->
                    <div class="es-sec-title">コメント欄</div>
                    <textarea id="esComment" class="form-control form-control-sm mb-3" rows="2" placeholder="コメント（会社からの備考）" style="font-size:.78rem"></textarea>

                    <!-- 差引支給額 -->
                    <div class="d-flex align-items-center justify-content-between rounded p-2 mb-3" style="background:#eff6ff">
                        <span class="fw-bold">差引支給額（手取り）</span>
                        <span class="fw-bold" id="esNetPay" style="font-size:1.25rem;color:#2563eb">0 <span class="text-muted small">円</span></span>
                    </div>

                    <div id="esSaveAlert" style="display:none"></div>
                    <div class="text-end">
                        <button type="button" class="btn btn-secondary btn-sm" id="esCancelBtn">キャンセル</button>
                        <button type="button" class="btn btn-primary btn-sm ms-1" id="esSaveBtn"><i class="bi bi-save me-1"></i>保存する</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 支給履歴・年間推移 -->
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-clock-history me-1" style="color:#f59e0b"></i>支給履歴</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" style="font-size:.78rem">
                            <thead class="table-light">
                                <tr><th>支給年月</th><th class="text-end">総支給額</th><th class="text-end">差引支給額</th><th class="text-center">操作</th></tr>
                            </thead>
                            <tbody id="esHistoryBody">
                                <tr><td colspan="4" class="text-center text-muted py-3">データがありません</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up me-1" style="color:#2563eb"></i>年間推移（差引支給額）</span>
                    <select id="esTrendYear" class="form-select form-select-sm" style="width:100px">
                        <?php for ($y = (int)date('Y') + 1; $y >= 2024; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>年</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="card-body">
                    <canvas id="esTrendChart" height="180"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- 社員未選択時 -->
    <div class="card" id="esEmptyState">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-person-badge" style="font-size:2rem"></i>
            <p class="mb-0 mt-2">上の「社員検索」から社員を選択してください</p>
        </div>
    </div>
</div>

<?php
$inlineJs  = 'var ES_API = ' . json_encode(BASE_PATH . '/public/api/employee_salary.php') . ';';
$inlineJs .= 'var esCsrf = ' . json_encode(getCsrfToken()) . ';';
$inlineJs .= 'var esEmployees = ' . json_encode($seishainList, JSON_UNESCAPED_UNICODE) . ';';
$inlineJs .= <<<'JS'

var esCurEmp = null, esYear = null, esMonth = null, esTrendChartObj = null, esSlipSelected = null;

/* ---------- 項目定義 ---------- */
// type: 'input'=入力欄 / 'calc'=自動計算表示
var ES_SECTIONS = [
    { title: '勤怠項目', items: [
        { key:'work_days',               label:'出勤日数',       type:'input', unit:'日',   step:'0.01' },
        { key:'absence_days',            label:'欠勤日数',       type:'input', unit:'日',   step:'0.01' },
        { key:'paid_overtime_hours',     label:'普通残業時間',   type:'input', unit:'時間', step:'0.01' },
        { key:'midnight_overtime_hours', label:'深夜残業時間',   type:'input', unit:'時間', step:'0.01' },
        { key:'holiday_work_hours',      label:'休日出勤時間',   type:'input', unit:'時間', step:'0.01' },
        { key:'holiday_midnight_hours',  label:'休日深夜時間',   type:'input', unit:'時間', step:'0.01' },
        { key:'late_early_hours',        label:'遅刻・早退時間', type:'input', unit:'時間', step:'0.01' },
        { key:'working_days',            label:'勤務時間',       type:'input', unit:'時間', step:'0.01' },
    ]},
    { title: '支給項目', items: [
        { key:'base_pay',                 label:'基本給',             type:'input', unit:'円' },
        { key:'position_allowance',       label:'役職手当',           type:'input', unit:'円' },
        { key:'skill_allowance',          label:'能力手当',           type:'input', unit:'円' },
        { key:'fixed_overtime_allowance', label:'固定残業手当',       type:'input', unit:'円' },
        { key:'duty_allowance',           label:'職務手当',           type:'input', unit:'円' },
        { key:'communication_fee',        label:'通信費',             type:'input', unit:'円' },
        { key:'management_incentive',     label:'管理インセンティブ', type:'input', unit:'円' },
        { key:'sales_incentive',          label:'営業インセンティブ', type:'input', unit:'円' },
        { key:'housing_allowance',        label:'家賃手当',           type:'input', unit:'円' },
        { key:'overtime_allowance',       label:'時間外手当',         type:'input', unit:'円' },
        { key:'work_deduction',           label:'勤務控除',           type:'input', unit:'円' },
        { key:'commute_nontax',           label:'通勤手当（非）',     type:'input', unit:'円' },
        { key:'commute_tax',              label:'通勤手当（課）',     type:'input', unit:'円' },
        { key:'payment_sum',              label:'支給額合計',         type:'calc' },
        { key:'total_payment',            label:'総支給額',           type:'calc' },
    ]},
    { title: '控除項目', items: [
        { key:'health_insurance',       label:'健康保険',     type:'input', unit:'円' },
        { key:'pension_insurance',      label:'厚生年金保険', type:'input', unit:'円' },
        { key:'pension_fund',           label:'厚生年金基金', type:'input', unit:'円' },
        { key:'employment_insurance',   label:'雇用保険',     type:'input', unit:'円' },
        { key:'social_insurance_total', label:'社会保険合計', type:'calc' },
        { key:'taxable_amount',         label:'課税対象額',   type:'input', unit:'円' },
        { key:'income_tax',             label:'所得税',       type:'input', unit:'円' },
        { key:'resident_tax',           label:'住民税',       type:'input', unit:'円' },
        { key:'year_end_adjustment',    label:'年末調整',     type:'input', unit:'円' },
        { key:'total_deduction',        label:'控除合計',     type:'calc' },
    ]},
    { title: '集計', items: [
        { key:'prev_carryover',     label:'前回繰越額', type:'input', unit:'円' },
        { key:'current_adjustment', label:'今回調整額', type:'input', unit:'円' },
        { key:'bank_account1',      label:'振込口座1',  type:'input', unit:'円' },
        { key:'bank_account2',      label:'振込口座2',  type:'input', unit:'円' },
        { key:'bank_account3',      label:'振込口座3',  type:'input', unit:'円' },
        { key:'cash_payment',       label:'現金支給額', type:'input', unit:'円' },
    ]},
];

var ES_FIELD_LABELS = {};
ES_SECTIONS.forEach(function(sec) { sec.items.forEach(function(it) { ES_FIELD_LABELS[it.key] = it.label; }); });

function esFmt(n) { return Math.round(n || 0).toLocaleString(); }
function esH(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ---------- セクション描画 ---------- */
function esBuildSections() {
    var wrap = document.getElementById('esSections');
    var html = '';
    ES_SECTIONS.forEach(function(sec) {
        html += '<div class="es-sec-title">' + esH(sec.title) + '</div>';
        html += '<div class="row g-0 mb-3">';
        sec.items.forEach(function(it) {
            if (it.type === 'input') {
                html += '<div class="col-12 col-md-6"><div class="es-item">';
                html += '<span class="es-label">' + esH(it.label) + '</span>';
                html += '<input type="number" min="0"' + (it.step ? ' step="' + it.step + '"' : '') + ' class="form-control form-control-sm text-end es-amount" data-field="' + it.key + '" placeholder="0">';
                html += '<span class="es-unit">' + esH(it.unit || '') + '</span>';
                html += '</div></div>';
            } else {
                html += '<div class="col-12"><div class="es-calc-row">';
                html += '<span class="es-calc-label">' + esH(it.label) + '</span>';
                html += '<span class="es-calc-val" id="esCalc_' + it.key + '">0 <span class="text-muted small">円</span></span>';
                html += '</div></div>';
            }
        });
        html += '</div>';
    });
    wrap.innerHTML = html;
    wrap.querySelectorAll('.es-amount').forEach(function(inp) { inp.addEventListener('input', esRecalc); });
}

/* ---------- 金額計算 ---------- */
function esVal(key) {
    var inp = document.querySelector('.es-amount[data-field="' + key + '"]');
    return inp ? (parseFloat(inp.value || 0) || 0) : 0;
}

function esRecalc() {
    var payKeys = ['base_pay','position_allowance','skill_allowance','fixed_overtime_allowance','duty_allowance','communication_fee','management_incentive','sales_incentive','housing_allowance','overtime_allowance','commute_nontax','commute_tax'];
    var pay = 0;
    payKeys.forEach(function(k){ pay += esVal(k); });
    pay -= esVal('work_deduction');
    var social = esVal('health_insurance') + esVal('pension_insurance') + esVal('pension_fund') + esVal('employment_insurance');
    var ded = social + esVal('income_tax') + esVal('resident_tax') + esVal('year_end_adjustment');
    function setCalc(key, v) {
        var el = document.getElementById('esCalc_' + key);
        if (el) el.innerHTML = esFmt(v) + ' <span class="text-muted small">円</span>';
    }
    setCalc('payment_sum', pay);
    setCalc('total_payment', pay);
    setCalc('social_insurance_total', social);
    setCalc('total_deduction', ded);
    document.getElementById('esNetPay').innerHTML = esFmt(pay - ded) + ' <span class="text-muted small">円</span>';
}

/* ---------- 社員セレクト ---------- */
function esBuildEmpSelect(filter) {
    var sel = document.getElementById('esEmployee');
    var cur = sel.value;
    var q = (filter || '').toLowerCase();
    var html = '<option value="">-- 社員を選択 --</option>';
    esEmployees.forEach(function(e) {
        if (q && (e.name + ' ' + (e.kana || '')).toLowerCase().indexOf(q) === -1) return;
        html += '<option value="' + e.id + '"' + (String(e.id) === cur ? ' selected' : '') + '>' + esH(e.name) + (e.number ? '（' + esH(e.number) + '）' : '') + '</option>';
    });
    sel.innerHTML = html;
}

function esGetYm() {
    var v = document.getElementById('esMonth').value;
    if (!v) return null;
    var p = v.split('-');
    return { y: parseInt(p[0], 10), m: parseInt(p[1], 10) };
}

/* ---------- データ読込 ---------- */
function esLoad() {
    var empId = document.getElementById('esEmployee').value;
    var ym = esGetYm();
    if (!empId || !ym) {
        document.getElementById('esMainRow').style.display = 'none';
        document.getElementById('esCompareCard').style.display = 'none';
        document.getElementById('esEmptyState').style.display = '';
        return;
    }
    esCurEmp = parseInt(empId, 10); esYear = ym.y; esMonth = ym.m;
    var trendYear = document.getElementById('esTrendYear').value || ym.y;
    fetch(ES_API + '?action=data&employee_id=' + empId + '&year=' + ym.y + '&month=' + ym.m + '&trend_year=' + trendYear)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.error) { alert(d.error); return; }
            document.getElementById('esEmptyState').style.display = 'none';
            document.getElementById('esMainRow').style.display = '';
            esRenderInfo(d.employee);
            esRenderForm(d.detail);
            esRenderCompare(d.totals, d.prev_totals);
            esRenderHistory(d.history);
            esRenderTrend(d.trend_year, d.trend);
            esRenderSlip(d.has_slip);
            esRecalc();
        })
        .catch(function(){ alert('データの取得に失敗しました'); });
}

function esRenderInfo(emp) {
    document.getElementById('esInfoCompany').textContent = emp.company_name || '-';
    document.getElementById('esInfoName').textContent    = emp.name || '-';
    document.getElementById('esInfoMonth').textContent   = esYear + '年' + String(esMonth).padStart(2, '0') + '月';
}

function esRenderForm(detail) {
    document.querySelectorAll('.es-amount').forEach(function(inp) {
        var v = detail ? (detail[inp.dataset.field] || 0) : 0;
        inp.value = v > 0 ? v : '';
        inp.classList.remove('border-warning');
    });
    document.getElementById('esComment').value = detail ? (detail.comment || '') : '';
    esSlipSelected = null;
    document.getElementById('esSlipFile').value = '';
    document.getElementById('esOcrStatus').style.display = 'none';
    document.getElementById('esOcrWarn').style.display = 'none';
}

function esRenderCompare(cur, prev) {
    var card = document.getElementById('esCompareCard');
    if (!cur || !prev) { card.style.display = 'none'; return; }
    var pv = new Date(esYear, esMonth - 2, 1);
    document.getElementById('esCompareTitle').textContent = '前月との比較（' + pv.getFullYear() + '年' + String(pv.getMonth() + 1).padStart(2, '0') + '月比）';
    function diff(el, a, b, invert) {
        var d = a - b;
        var up = d > 0;
        var good = invert ? d < 0 : d > 0;
        el.textContent = (d > 0 ? '+' : '') + esFmt(d) + '円' + (d !== 0 ? (up ? ' ↑' : ' ↓') : '');
        el.style.color = d === 0 ? '#6b7280' : (good ? '#dc2626' : '#2563eb');
    }
    diff(document.getElementById('esCmpPay'), cur.total_payment,  prev.total_payment,  false);
    diff(document.getElementById('esCmpDed'), cur.total_deduction, prev.total_deduction, true);
    diff(document.getElementById('esCmpNet'), cur.net_payment,    prev.net_payment,    false);
    card.style.display = '';
}

function esRenderHistory(history) {
    var tbody = document.getElementById('esHistoryBody');
    if (!history || !history.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">データがありません</td></tr>';
        return;
    }
    var html = '';
    history.forEach(function(h) {
        var isCur = h.pay_year === esYear && h.pay_month === esMonth;
        html += '<tr>';
        html += '<td>' + h.pay_year + '年' + String(h.pay_month).padStart(2, '0') + '月' + (isCur ? '<span class="badge bg-primary ms-1" style="font-size:.6rem">今回</span>' : '') + '</td>';
        html += '<td class="text-end">' + esFmt(h.total_payment) + '円</td>';
        html += '<td class="text-end">' + esFmt(h.net_payment) + '円</td>';
        html += '<td class="text-center"><button class="btn btn-link btn-sm p-0" title="この月を表示" onclick="esJumpTo(' + h.pay_year + ',' + h.pay_month + ')"><i class="bi bi-eye"></i></button></td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;
}

function esJumpTo(y, m) {
    document.getElementById('esMonth').value = y + '-' + String(m).padStart(2, '0');
    esLoad();
}

function esRenderTrend(trendYear, trend) {
    var ctx = document.getElementById('esTrendChart');
    if (!ctx || typeof Chart === 'undefined') return;
    var labels = [];
    for (var i = 1; i <= 12; i++) labels.push(i + '月');
    if (esTrendChartObj) esTrendChartObj.destroy();
    esTrendChartObj = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: '差引支給額',
                data: trend,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,.08)',
                tension: 0,
                spanGaps: true,
                pointRadius: 3,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c){ return esFmt(c.parsed.y) + '円'; } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return esFmt(v); } } } },
        },
    });
}

function esRenderSlip(hasSlip) {
    var wrap = document.getElementById('esSlipPreviewWrap');
    var img  = document.getElementById('esSlipPreview');
    if (hasSlip) {
        img.src = ES_API + '?action=slip&employee_id=' + esCurEmp + '&year=' + esYear + '&month=' + esMonth + '&t=' + Date.now();
        wrap.style.display = '';
    } else {
        img.src = '';
        wrap.style.display = 'none';
    }
}

/* ---------- AI読み取り ---------- */
function esRunOcr(file) {
    var status = document.getElementById('esOcrStatus');
    var warn   = document.getElementById('esOcrWarn');
    warn.style.display = 'none';
    status.className = 'alert alert-info py-2 small mb-0';
    status.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>AIが給与明細を読み取り中...';
    status.style.display = '';

    var fd = new FormData();
    fd.append('action', 'ocr');
    fd.append('csrf', esCsrf);
    fd.append('slip', file);
    fetch(ES_API, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (!res.success) {
                status.className = 'alert alert-warning py-2 small mb-0';
                status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + esH(res.error || 'AI読み取りに失敗しました');
                return;
            }
            document.querySelectorAll('.es-amount').forEach(function(inp) {
                var v = res.fields[inp.dataset.field];
                if (v !== undefined) inp.value = v > 0 ? v : '';
                inp.classList.remove('border-warning');
            });
            if (res.comment) document.getElementById('esComment').value = res.comment;
            esRecalc();
            status.className = 'alert alert-success py-2 small mb-0';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>自動読み取り完了 — 内容を確認してください';
            var unc = res.uncertain_fields || [];
            if (unc.length) {
                var items = '';
                unc.forEach(function(k) {
                    if (!ES_FIELD_LABELS[k]) return;
                    items += '<li>' + esH(ES_FIELD_LABELS[k]) + '：金額の確認が必要です</li>';
                    var inp = document.querySelector('.es-amount[data-field="' + k + '"]');
                    if (inp) inp.classList.add('border-warning');
                });
                if (items) {
                    warn.className = 'alert alert-warning py-2 small mb-0';
                    warn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>確認が必要な項目<ul class="mb-0 mt-1">' + items + '</ul>';
                    warn.style.display = '';
                }
            }
        })
        .catch(function() {
            status.className = 'alert alert-warning py-2 small mb-0';
            status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>AI読み取りの通信に失敗しました。手入力してください。';
        });
}

/* ---------- 保存 ---------- */
function esSave() {
    if (!esCurEmp) return;
    var btn = document.getElementById('esSaveBtn');
    var alertEl = document.getElementById('esSaveAlert');
    btn.disabled = true; btn.innerHTML = '保存中...';
    alertEl.style.display = 'none';
    var detail = {};
    document.querySelectorAll('.es-amount').forEach(function(inp) {
        detail[inp.dataset.field] = inp.value !== '' ? inp.value : '0';
    });
    detail.comment = document.getElementById('esComment').value;
    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('csrf', esCsrf);
    fd.append('employee_id', esCurEmp);
    fd.append('year', esYear);
    fd.append('month', esMonth);
    fd.append('detail', JSON.stringify(detail));
    if (esSlipSelected) fd.append('slip', esSlipSelected);
    fetch(ES_API, { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-save me-1"></i>保存する';
            if (res.success) {
                alertEl.className = 'alert alert-success py-1 px-2 small';
                alertEl.textContent = '保存しました';
                alertEl.style.display = '';
                setTimeout(function(){ alertEl.style.display = 'none'; }, 3000);
                esLoad();
            } else {
                alertEl.className = 'alert alert-danger py-1 px-2 small';
                alertEl.textContent = '保存失敗: ' + (res.error || '不明なエラー');
                alertEl.style.display = '';
            }
        })
        .catch(function() {
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-save me-1"></i>保存する';
            alertEl.className = 'alert alert-danger py-1 px-2 small';
            alertEl.textContent = '通信エラーが発生しました';
            alertEl.style.display = '';
        });
}

/* ---------- イベント ---------- */
document.addEventListener('DOMContentLoaded', function() {
    esBuildSections();
    esBuildEmpSelect('');
    document.getElementById('esEmpFilter').addEventListener('input', function(){ esBuildEmpSelect(this.value); });
    document.getElementById('esEmployee').addEventListener('change', esLoad);
    document.getElementById('esMonth').addEventListener('change', esLoad);
    document.getElementById('esTrendYear').addEventListener('change', function() {
        if (!esCurEmp) return;
        fetch(ES_API + '?action=trend&employee_id=' + esCurEmp + '&trend_year=' + this.value)
            .then(function(r){ return r.json(); })
            .then(function(d){ if (!d.error) esRenderTrend(d.trend_year, d.trend); })
            .catch(function(){});
    });
    document.getElementById('esSaveBtn').addEventListener('click', esSave);
    document.getElementById('esCancelBtn').addEventListener('click', esLoad);
    document.getElementById('esSlipFile').addEventListener('change', function() {
        var f = this.files && this.files[0];
        if (!f) return;
        esSlipSelected = f;
        var wrap = document.getElementById('esSlipPreviewWrap');
        var img  = document.getElementById('esSlipPreview');
        if (f.type === 'application/pdf') {
            img.src = '';
            wrap.style.display = '';
            img.alt = 'PDFが選択されました（保存後に表示されます）';
        } else {
            img.src = URL.createObjectURL(f);
            wrap.style.display = '';
        }
        esRunOcr(f);
    });
    if (esEmployees.length === 1) {
        document.getElementById('esEmployee').value = esEmployees[0].id;
        esLoad();
    }
});
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
