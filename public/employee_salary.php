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
$empStmt = $db->prepare('SELECT id, name, name_kana, employee_number, department, employment_type, employment_subtype, hire_date FROM employees WHERE company_id = ? AND is_active = 1 ORDER BY employee_number, name');
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
        'department' => $e['department'] ?? '',
        'employment_type' => $e['employment_type'] ?: '正社員',
        'hire_date' => $e['hire_date'] ?? '',
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-wallet2 me-2"></i>正社員給与管理</h1>
                <p>給与明細をアップロードして各項目を入力・保存できます</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="bi bi-filetype-pdf me-1"></i>PDF保存</button>
                <a href="<?= BASE_PATH ?>/public/salary.php" class="btn btn-outline-secondary btn-sm">給与一覧に戻る</a>
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
                        <div class="fw-semibold small mb-2" style="color:#2563eb">従業員情報 <span class="text-muted fw-normal" style="font-size:.68rem">（社員マスターと自動紐付け）</span></div>
                        <table class="table table-sm table-borderless mb-0" style="font-size:.8rem">
                            <tr><td class="text-muted" style="width:80px">社員名</td><td class="fw-semibold" id="esInfoName">-</td></tr>
                            <tr><td class="text-muted">社員番号</td><td id="esInfoNumber">-</td></tr>
                            <tr><td class="text-muted">所属</td><td id="esInfoDept">-</td></tr>
                            <tr><td class="text-muted">雇用区分</td><td id="esInfoType">-</td></tr>
                            <tr><td class="text-muted">入社日</td><td id="esInfoHire">-</td></tr>
                            <tr><td class="text-muted">支給年月</td><td class="fw-semibold" id="esInfoMonth">-</td></tr>
                        </table>
                    </div>

                    <!-- 支給項目 -->
                    <div class="fw-semibold small mb-2" style="color:#2563eb">支給項目</div>
                    <div class="mb-3">
                        <?php
                        $payItems = [
                            'base_pay' => '基本給', 'position_allowance' => '役職手当',
                            'overtime_allowance' => '残業手当', 'commute_allowance' => '通勤手当',
                            'other_allowance' => 'その他手当',
                        ];
                        foreach ($payItems as $key => $label): ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="small" style="width:90px"><?= $label ?></span>
                            <input type="number" min="0" class="form-control form-control-sm text-end es-amount es-pay" data-field="<?= $key ?>" placeholder="0" style="max-width:140px">
                            <span class="text-muted small">円</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex align-items-center justify-content-between border-top pt-2 mt-2">
                            <span class="fw-semibold small">総支給額</span>
                            <span class="fw-bold" id="esTotalPay" style="font-size:1.05rem">0 <span class="text-muted small">円</span></span>
                        </div>
                    </div>

                    <!-- 控除項目 -->
                    <div class="fw-semibold small mb-2" style="color:#2563eb">控除項目</div>
                    <div class="mb-3">
                        <?php
                        $dedItems = [
                            'health_insurance' => '健康保険', 'pension' => '厚生年金',
                            'employment_insurance' => '雇用保険', 'income_tax' => '所得税',
                            'resident_tax' => '住民税', 'other_deduction' => 'その他控除',
                        ];
                        foreach ($dedItems as $key => $label): ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="small" style="width:90px"><?= $label ?></span>
                            <input type="number" min="0" class="form-control form-control-sm text-end es-amount es-ded" data-field="<?= $key ?>" placeholder="0" style="max-width:140px">
                            <span class="text-muted small">円</span>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex align-items-center justify-content-between border-top pt-2 mt-2">
                            <span class="fw-semibold small">控除合計額</span>
                            <span class="fw-bold" id="esTotalDed" style="font-size:1.05rem">0 <span class="text-muted small">円</span></span>
                        </div>
                    </div>

                    <!-- 差引支給額 -->
                    <div class="d-flex align-items-center justify-content-between rounded p-2 mb-3" style="background:#eff6ff">
                        <span class="fw-bold">差引支給額</span>
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

var esCurEmp = null, esYear = null, esMonth = null, esTrendChart = null, esSlipSelected = null;

function esFmt(n) { return parseInt(n || 0).toLocaleString(); }
function esH(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

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

/* ---------- 対象年月 ---------- */
function esGetYm() {
    var v = document.getElementById('esMonth').value; // yyyy-mm
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
            esRenderForm(d.salary);
            esRenderCompare(d.totals, d.prev_totals);
            esRenderHistory(d.history);
            esRenderTrend(d.trend_year, d.trend);
            esRenderSlip(d.salary);
            esRecalc();
        })
        .catch(function(){ alert('データの取得に失敗しました'); });
}

function esRenderInfo(emp) {
    document.getElementById('esInfoName').textContent   = emp.name || '-';
    document.getElementById('esInfoNumber').textContent = emp.employee_number || '-';
    document.getElementById('esInfoDept').textContent   = emp.department || '-';
    document.getElementById('esInfoType').textContent   = emp.employment_type || '正社員';
    document.getElementById('esInfoHire').textContent   = emp.hire_date || '-';
    document.getElementById('esInfoMonth').textContent  = esYear + '年' + String(esMonth).padStart(2, '0') + '月';
}

function esRenderForm(salary) {
    document.querySelectorAll('.es-amount').forEach(function(inp) {
        var v = salary ? (salary[inp.dataset.field] || 0) : 0;
        inp.value = v > 0 ? v : '';
    });
    esSlipSelected = null;
    document.getElementById('esSlipFile').value = '';
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
    if (esTrendChart) esTrendChart.destroy();
    esTrendChart = new Chart(ctx, {
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

function esRenderSlip(salary) {
    var wrap = document.getElementById('esSlipPreviewWrap');
    var img  = document.getElementById('esSlipPreview');
    if (salary && salary.has_slip && String(salary.has_slip) !== '0') {
        img.src = ES_API + '?action=slip&employee_id=' + esCurEmp + '&year=' + esYear + '&month=' + esMonth + '&t=' + Date.now();
        wrap.style.display = '';
    } else {
        img.src = '';
        wrap.style.display = 'none';
    }
}

/* ---------- 金額計算 ---------- */
function esRecalc() {
    var pay = 0, ded = 0;
    document.querySelectorAll('.es-pay').forEach(function(i){ pay += parseInt(i.value || 0, 10) || 0; });
    document.querySelectorAll('.es-ded').forEach(function(i){ ded += parseInt(i.value || 0, 10) || 0; });
    document.getElementById('esTotalPay').innerHTML = esFmt(pay) + ' <span class="text-muted small">円</span>';
    document.getElementById('esTotalDed').innerHTML = esFmt(ded) + ' <span class="text-muted small">円</span>';
    document.getElementById('esNetPay').innerHTML   = esFmt(pay - ded) + ' <span class="text-muted small">円</span>';
}

/* ---------- AI読み取り ---------- */
var FIELD_LABELS = {
    base_pay:'基本給', position_allowance:'役職手当', overtime_allowance:'残業手当',
    commute_allowance:'通勤手当', other_allowance:'その他手当',
    health_insurance:'健康保険', pension:'厚生年金', employment_insurance:'雇用保険',
    income_tax:'所得税', resident_tax:'住民税', other_deduction:'その他控除',
};

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
            // 読み取り結果をフォームへ反映
            document.querySelectorAll('.es-amount').forEach(function(inp) {
                var v = res.fields[inp.dataset.field];
                if (v !== undefined) inp.value = v > 0 ? v : '';
                inp.classList.remove('border-warning');
            });
            esRecalc();
            status.className = 'alert alert-success py-2 small mb-0';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>自動読み取り完了 — 内容を確認してください';
            // 確認が必要な項目
            var unc = res.uncertain_fields || [];
            if (unc.length) {
                var items = '';
                unc.forEach(function(k) {
                    if (!FIELD_LABELS[k]) return;
                    items += '<li>' + esH(FIELD_LABELS[k]) + '：金額の確認が必要です</li>';
                    var inp = document.querySelector('.es-amount[data-field="' + k + '"]');
                    if (inp) inp.classList.add('border-warning');
                });
                if (items) {
                    warn.className = 'alert alert-warning py-2 small mb-0';
                    warn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>確認が必要な項目（' + unc.length + '件）<ul class="mb-0 mt-1">' + items + '</ul>';
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
    var fd = new FormData();
    fd.append('action', 'save');
    fd.append('csrf', esCsrf);
    fd.append('employee_id', esCurEmp);
    fd.append('year', esYear);
    fd.append('month', esMonth);
    document.querySelectorAll('.es-amount').forEach(function(inp) {
        fd.append(inp.dataset.field, inp.value !== '' ? inp.value : '0');
    });
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
    esBuildEmpSelect('');
    document.getElementById('esEmpFilter').addEventListener('input', function(){ esBuildEmpSelect(this.value); });
    document.getElementById('esEmployee').addEventListener('change', esLoad);
    document.getElementById('esMonth').addEventListener('change', esLoad);
    document.getElementById('esTrendYear').addEventListener('change', function() {
        if (!esCurEmp) return;
        fetch(ES_API + '?action=trend&employee_id=' + esCurEmp + '&year=' + esYear + '&month=' + esMonth + '&trend_year=' + this.value)
            .then(function(r){ return r.json(); })
            .then(function(d){ if (!d.error) esRenderTrend(d.trend_year, d.trend); })
            .catch(function(){});
    });
    document.querySelectorAll('.es-amount').forEach(function(inp) {
        inp.addEventListener('input', esRecalc);
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
        // AI自動読み取りを実行
        esRunOcr(f);
    });
    // 1人しかいない場合は自動選択
    if (esEmployees.length === 1) {
        document.getElementById('esEmployee').value = esEmployees[0].id;
        esLoad();
    }
});
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
