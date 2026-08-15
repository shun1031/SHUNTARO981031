<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
// 営業マン用画面: 管理者または営業担当のみ閲覧可（URL直打ちでも弾く）
requireSalesPageView();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }
requireRole('super_admin', 'company_admin');

$pageTitle = '案件店舗管理';
$extraCss  = ['sales.css'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid cs-page">
    <!-- ヘッダー -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <h1 class="mb-0"><i class="bi bi-shop me-2"></i>案件店舗管理</h1>
                    <div class="btn-group btn-group-sm cs-type-group" role="group">
                        <button type="button" class="btn btn-success" id="csTypeRegular" onclick="csSetCaseType('regular')">常勤</button>
                        <button type="button" class="btn btn-outline-secondary" id="csTypeEvent" onclick="csSetCaseType('event')">イベント</button>
                    </div>
                    <div class="btn-group btn-group-sm cs-div-group" role="group">
                        <button type="button" class="btn btn-primary" id="csDivFirst" onclick="csSetDivision('first')">1次案件</button>
                        <button type="button" class="btn btn-outline-secondary" id="csDivOther" onclick="csSetDivision('other')">その他案件</button>
                    </div>
                </div>
                <p class="mb-0 mt-1">案件種別と区分を選択して、該当する案件のみを表示します。稼働状況は表示中の月を基準に判定します。</p>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted" style="font-size:.72rem">最終更新：<span id="csUpdatedAt">-</span></span>
                    <button type="button" class="btn btn-link p-0 text-primary" onclick="csLoad()" title="最新の情報に更新">
                        <i class="bi bi-arrow-clockwise" style="font-size:1rem"></i>
                    </button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm px-2" onclick="csChangeMonth(-1)">‹</button>
                        <span class="fw-bold px-1" id="csMonthLabel" style="min-width:104px;text-align:center;font-size:.85rem">-</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm px-2" onclick="csChangeMonth(1)">›</button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="csPrint()">
                        <i class="bi bi-printer me-1"></i>印刷
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="csExportPdf()">
                        <i class="bi bi-file-earmark-pdf me-1"></i>PDF出力
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 集計カード -->
    <div class="row g-2 mb-3" id="csSummary">
        <div class="col-6 col-md-4 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
                <div class="cs-ico" style="background:#eef2ff;color:#4f46e5"><i class="bi bi-building"></i></div>
                <div><div class="cs-ico-label">クライアント数</div>
                     <div class="cs-ico-val"><span id="csClientCount">0</span><small>社</small></div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
                <div class="cs-ico" style="background:#dcfce7;color:#16a34a"><i class="bi bi-shop"></i></div>
                <div><div class="cs-ico-label">店舗数（選択中の区分）</div>
                     <div class="cs-ico-val"><span id="csStoreCount">0</span><small>店舗</small></div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
                <div class="cs-ico" style="background:#dbeafe;color:#2563eb"><i class="bi bi-people"></i></div>
                <div><div class="cs-ico-label">稼働者数</div>
                     <div class="cs-ico-val"><span id="csWorkerCount">0</span><small>名</small></div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-6 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
                <div class="cs-ico" style="background:#fef3c7;color:#d97706"><i class="bi bi-currency-yen"></i></div>
                <div><div class="cs-ico-label"><span id="csAvgPriceLabel">平均単価（月額）</span></div>
                     <div class="cs-ico-val" id="csAvgPrice">¥0</div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-6 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-2 py-3">
                <div class="cs-ico" style="background:#fee2e2;color:#dc2626"><i class="bi bi-check-circle"></i></div>
                <div><div class="cs-ico-label">稼働中店舗数</div>
                     <div class="cs-ico-val"><span id="csActiveCount">0</span><small>店舗</small></div></div>
            </div></div>
        </div>
    </div>

    <!-- 検索・絞り込み -->
    <div class="card mb-3 cs-filter-card">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="input-group input-group-sm" style="max-width:290px">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="csSearch" class="form-control border-start-0 ps-0"
                           placeholder="クライアント名・店舗名で検索" oninput="csFilterInput()">
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small fw-medium">ステータスで絞り込み</label>
                    <select id="csStatus" class="form-select form-select-sm" style="width:auto;min-width:130px" onchange="csLoad()">
                        <option value="">すべて</option>
                        <option value="稼働中">稼働中</option>
                        <option value="調整中">調整中</option>
                        <option value="準備中">準備中</option>
                        <option value="稼働終了">稼働終了</option>
                    </select>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="csActiveOnly" onchange="csLoad()">
                    <label class="form-check-label small" for="csActiveOnly">稼働中の店舗のみ表示</label>
                </div>
            </div>
        </div>
    </div>

    <!-- 一覧 -->
    <div class="card cs-list-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 cs-table">
                    <thead>
                        <tr>
                            <th style="width:290px">クライアント / 店舗数</th>
                            <th style="width:200px">稼働者名</th>
                            <th style="width:220px">店舗名</th>
                            <th style="width:120px">単価</th>
                            <th style="width:120px">ステータス</th>
                            <th>備考</th>
                        </tr>
                    </thead>
                    <tbody id="csTbody">
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            <span class="spinner-border spinner-border-sm me-2"></span>読み込み中...
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.cs-ico { width:38px;height:38px;border-radius:.6rem;display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0 }
.cs-ico-label { font-size:.68rem;color:#94a3b8;line-height:1.3 }
.cs-ico-val { font-size:1.25rem;font-weight:700;color:#1e293b;line-height:1.2 }
.cs-ico-val small { font-size:.68rem;font-weight:500;color:#64748b;margin-left:2px }

/* 濃い青のヘッダー行 */
.cs-table > thead > tr > th {
    background:#1e3a8a;color:#fff;font-size:.78rem;font-weight:600;
    border:none;padding:.7rem .75rem;white-space:nowrap;
}
.cs-table > tbody > tr > td { font-size:.82rem;padding:.6rem .75rem;vertical-align:middle;border-color:#eef2f7 }
.cs-table .cs-client-row { cursor:pointer;background:#fff }
.cs-table .cs-client-row:hover { background:#f8fafc }
.cs-table .cs-client-name { font-weight:700;color:#1e293b;font-size:.88rem }
.cs-store-badge { background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:.1rem .5rem;font-size:.68rem;font-weight:600;margin-left:.4rem }
.cs-caret { display:inline-block;width:18px;color:#64748b;transition:transform .15s }
.cs-caret.open { transform:rotate(90deg) }
.cs-detail-row > td { background:#fcfdff }
.cs-detail-row .cs-client-cell { background:#f8fafc;border-right:1px solid #eef2f7 }
.cs-status { display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600;white-space:nowrap }
.cs-status-active  { background:#dcfce7;color:#15803d }
.cs-status-adjust  { background:#ffedd5;color:#c2410c }
.cs-status-prepare { background:#dbeafe;color:#1d4ed8 }
.cs-status-ended   { background:#f1f5f9;color:#64748b }
.cs-sum-item { font-size:.78rem;color:#475569 }
.cs-sum-item i { color:#94a3b8;margin-right:.25rem }
.cs-active-note { font-size:.78rem;color:#16a34a;font-weight:600 }

/* 印刷・PDF出力用 */
@media print {
    .sidebar, .app-header, .cs-filter-card, .page-header .btn,
    .page-header .btn-group, #csMonthLabel, .btn-link { display:none !important }
    .cs-page { padding:0 !important }
    .card { border:1px solid #ddd !important;box-shadow:none !important }
    .cs-table > thead > tr > th { background:#1e3a8a !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact }
    .cs-status { -webkit-print-color-adjust:exact;print-color-adjust:exact }
    .table-responsive { overflow:visible !important }
}
</style>

<?php
$csApi = json_encode(BASE_PATH . '/public/api/case_stores.php');
$inlineJs = <<<CSJS
var CS_API = {$csApi};
CSJS;
$inlineJs .= <<<'CSJS2'

var csDivision = 'first', csCaseType = 'regular', csYear = 0, csMonth = 0, csTimer = null;
var csOpen = {};          // クライアントごとの開閉状態
var csLastData = null;

function csEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function csYen(n) { return '¥' + (parseInt(n) || 0).toLocaleString(); }

function csStatusClass(s) {
    if (s === '稼働中')   return 'cs-status-active';
    if (s === '調整中')   return 'cs-status-adjust';
    if (s === '準備中')   return 'cs-status-prepare';
    return 'cs-status-ended';
}

function csSetDivision(d) {
    if (csDivision === d) return;
    csDivision = d;
    document.getElementById('csDivFirst').className = 'btn ' + (d === 'first' ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('csDivOther').className = 'btn ' + (d === 'other' ? 'btn-primary' : 'btn-outline-secondary');
    csOpen = {};
    csLoad();
}

// 常勤 / イベントの切替（案件種別で完全に分けて表示・集計する）
function csSetCaseType(t) {
    if (csCaseType === t) return;
    csCaseType = t;
    document.getElementById('csTypeRegular').className = 'btn ' + (t === 'regular' ? 'btn-success' : 'btn-outline-secondary');
    document.getElementById('csTypeEvent').className   = 'btn ' + (t === 'event'   ? 'btn-success' : 'btn-outline-secondary');
    csOpen = {};
    // 種別ごとにデータのある月が異なるため、最新月を再取得させる
    csYear = 0; csMonth = 0;
    csLoad();
}

function csChangeMonth(delta) {
    var m = csMonth + delta, y = csYear;
    if (m < 1)  { m = 12; y--; }
    if (m > 12) { m = 1;  y++; }
    csYear = y; csMonth = m;
    csLoad();
}

function csToggle(name) {
    csOpen[name] = !csOpen[name];
    csRender(csLastData);
}

// クライアント行のクリックで開閉（tbodyに1つだけ登録し、再描画後も有効）
function csBindToggle() {
    var tb = document.getElementById('csTbody');
    if (!tb || tb.dataset.csBound === '1') return;
    tb.dataset.csBound = '1';
    tb.addEventListener('click', function (e) {
        var row = e.target.closest('.cs-client-row');
        if (!row || !row.dataset.client) return;
        csToggle(row.dataset.client);
    });
}

function csRender(d) {
    if (!d) return;
    csLastData = d;
    csYear = d.year; csMonth = d.month;
    document.getElementById('csMonthLabel').textContent = d.year + '年' + d.month + '月';
    document.getElementById('csUpdatedAt').textContent  = d.updated_at;

    var s = d.summary;
    document.getElementById('csClientCount').textContent = s.client_count;
    document.getElementById('csStoreCount').textContent  = s.store_count;
    document.getElementById('csWorkerCount').textContent = s.worker_count;
    document.getElementById('csAvgPrice').textContent    = csYen(s.avg_price);
    document.getElementById('csActiveCount').textContent = s.active_store_count;
    // 常勤は月額、イベントは日額のため単位を明示
    document.getElementById('csAvgPriceLabel').textContent =
        (d.case_type === 'event') ? '平均単価（日額）' : '平均単価（月額）';

    var tb = document.getElementById('csTbody');
    if (!d.clients.length) {
        tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">該当する案件がありません</td></tr>';
        return;
    }

    var html = '';
    d.clients.forEach(function (c) {
        var open = !!csOpen[c.name];
        // クライアント行（折りたたみ時はサマリーを表示）
        // 名前は data 属性で受け渡す（記号を含む社名でも壊れない）
        html += '<tr class="cs-client-row" data-client="' + csEsc(c.name) + '">';
        html += '<td><span class="cs-caret' + (open ? ' open' : '') + '"><i class="bi bi-chevron-right"></i></span>'
             +  '<span class="cs-client-name">' + csEsc(c.name) + '</span>'
             +  '<span class="cs-store-badge">' + c.store_count + '店舗</span></td>';
        if (open) {
            html += '<td colspan="5"></td>';
        } else {
            html += '<td class="cs-sum-item"><i class="bi bi-person"></i>稼働者数： ' + c.worker_count + '名</td>';
            html += '<td class="cs-sum-item"><i class="bi bi-currency-yen"></i>平均単価： ' + csYen(c.avg_price) + '</td>';
            html += '<td></td><td></td>';
            html += '<td class="cs-active-note">稼働中： ' + c.active_store_count + '店舗</td>';
        }
        html += '</tr>';

        if (!open) return;

        // 展開時: 店舗 → 稼働者
        var first = true;
        c.stores.forEach(function (st) {
            st.workers.forEach(function (w) {
                html += '<tr class="cs-detail-row">';
                html += '<td class="cs-client-cell">' + (first ? '' : '') + '</td>';
                first = false;
                html += '<td><i class="bi bi-person text-muted me-1"></i>'
                     +  (w.worker_name ? csEsc(w.worker_name) : '<span class="text-muted">稼働者未設定</span>') + '</td>';
                html += '<td>' + csEsc(w.store_name) + '</td>';
                html += '<td>' + csYen(w.unit_price) + '</td>';
                html += '<td><span class="cs-status ' + csStatusClass(w.status) + '">' + csEsc(w.status) + '</span></td>';
                html += '<td class="text-muted">' + csEsc(w.note) + '</td>';
                html += '</tr>';
            });
        });
    });
    tb.innerHTML = html;
}

// 一覧の取得（全画面リロードなし）
function csLoad() {
    var p = new URLSearchParams();
    p.set('division', csDivision);
    p.set('case_type', csCaseType);
    if (csYear && csMonth) { p.set('year', csYear); p.set('month', csMonth); }
    var q = document.getElementById('csSearch').value.trim();
    if (q) p.set('q', q);
    var st = document.getElementById('csStatus').value;
    if (st) p.set('status', st);
    if (document.getElementById('csActiveOnly').checked) p.set('active_only', '1');

    fetch(CS_API + '?' + p.toString(), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error('failed');
            csRender(d);
        })
        .catch(function () {
            document.getElementById('csTbody').innerHTML =
                '<tr><td colspan="6" class="text-center text-danger py-4">読み込みに失敗しました</td></tr>';
        });
}

function csFilterInput() {
    clearTimeout(csTimer);
    csTimer = setTimeout(csLoad, 250);
}

// 印刷: 現在選択中の区分の表示内容をそのまま印刷
function csPrint() {
    csExpandAllForOutput(function () { window.print(); });
}

// PDF出力: 印刷ダイアログから「PDFとして保存」で出力（外部ライブラリ不要）
function csExportPdf() {
    csExpandAllForOutput(function () { window.print(); });
}

// 出力前に全クライアントを展開して明細まで印刷できるようにする
function csExpandAllForOutput(cb) {
    if (!csLastData) { cb(); return; }
    var prev = csOpen;
    csOpen = {};
    csLastData.clients.forEach(function (c) { csOpen[c.name] = true; });
    csRender(csLastData);
    setTimeout(function () {
        cb();
        // 印刷後に元の開閉状態へ戻す
        setTimeout(function () { csOpen = prev; csRender(csLastData); }, 300);
    }, 60);
}

// 案件の追加・編集は別画面で行われるため、戻ってきたタイミングで最新化
document.addEventListener('visibilitychange', function () { if (!document.hidden) csLoad(); });
window.addEventListener('focus', csLoad);
setInterval(csLoad, 60000);

document.addEventListener('DOMContentLoaded', function () {
    csBindToggle();
    csLoad();
});
CSJS2;
require_once __DIR__ . '/../includes/footer.php';
?>
