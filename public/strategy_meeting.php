<?php
/**
 * 戦略会議
 *
 * 営業マンごとの担当企業・売上状況を一覧で確認し、
 * 担当企業の詳細 → 企業ごとの年推移（期別）まで辿れる画面。
 *
 * データはすべて既存の案件データ（sales_cases）から集計する。
 * 独自の売上計算・企業マスタは持たず、既存の集計ロジックに合わせている。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }
// 支出管理・社員一覧と同じく管理者専用
requireRole('super_admin', 'company_admin');

$pageTitle = '戦略会議';
$extraCss  = ['strategy_meeting.css'];

$csrf = getCsrfToken();

// 今年度（9月始まり）。9〜12月は翌年度あつかい
$_y  = (int)date('Y');
$_m  = (int)date('n');
$fy  = $_m >= 9 ? $_y + 1 : $_y;
$fyLabel = substr((string)($fy - 1), 2) . '.9〜' . substr((string)$fy, 2) . '.8';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid sm-wrap">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-people-fill me-2"></i>戦略会議</h1>
                <p>営業マンごとの担当企業状況と売上を可視化し、戦略立案に活用します。</p>
            </div>
            <!-- 区分の切替。担当企業一覧の絞り込みにだけ使う（営業マンカードの数値は変わらない） -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted" style="font-size:.72rem">担当企業一覧の絞り込み</span>
                <div class="sm-filter" id="smFilter">
                <button type="button" class="sm-filter-btn active" data-division="">すべて</button>
                <button type="button" class="sm-filter-btn" data-division="光AD">光AD</button>
                <button type="button" class="sm-filter-btn" data-division="常勤">常勤</button>
                <button type="button" class="sm-filter-btn" data-division="イベント">イベント</button>
                </div>
            </div>
        </div>
    </div>

    <div class="sm-layout">

        <!-- ================= 左: 営業マン一覧 ================= -->
        <div class="sm-panel">
            <h2 class="sm-panel-title"><i class="bi bi-people-fill"></i>戦略会議</h2>
            <p class="sm-panel-lead">
                営業マンごとの担当企業状況と売上を可視化し、戦略立案に活用します。<br>
                クライアント数・アライアンス数・売上金額は今年度（<?= h($fyLabel) ?>）の累計です。
            </p>
            <div class="sm-rep-list" id="smRepList">
                <div class="sm-empty"><i class="bi bi-hourglass-split"></i>読み込み中...</div>
            </div>
        </div>

        <!-- ================= 右: 担当企業一覧 ＋ 年推移 ================= -->
        <div>
            <!-- 担当企業一覧 -->
            <div class="sm-panel" id="smCompanyPanel">
                <div class="sm-subpanel-head">
                    <button type="button" class="sm-back" id="smCompanyBack" title="営業マン一覧に戻る">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <h3 class="sm-subpanel-title" id="smCompanyTitle">担当企業一覧</h3>
                </div>
                <div class="sm-company-list" id="smCompanyList">
                    <div class="sm-empty">
                        <i class="bi bi-hand-index-thumb"></i>
                        営業マンカードの「＋」を押すと、担当企業が表示されます
                    </div>
                </div>
            </div>

            <!-- 年推移（期別） -->
            <div class="sm-panel" id="smTrendPanel">
                <div class="sm-subpanel-head">
                    <button type="button" class="sm-back" id="smTrendBack" title="担当企業一覧に戻る">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <h3 class="sm-subpanel-title" id="smTrendTitle">企業の年推移（期別）</h3>
                </div>

                <div class="sm-trend-head">
                    <span class="sm-trend-period">期：9月〜8月</span>
                    <div class="sm-metric-switch" id="smMetricSwitch">
                        <button type="button" class="active" data-metric="revenue">売上金額</button>
                        <button type="button" data-metric="frame">枠数</button>
                    </div>
                </div>

                <div class="sm-trend-body">
                    <div class="sm-chart-box">
                        <div id="smChartEmpty" class="sm-empty">
                            <i class="bi bi-bar-chart-line"></i>
                            企業を選ぶと年推移が表示されます
                        </div>
                        <canvas id="smTrendChart" class="sm-chart-canvas" style="display:none"></canvas>
                    </div>

                    <div>
                        <div class="sm-summary">
                            <div class="sm-summary-title" id="smSummaryTitle">最新期のサマリー</div>
                            <div class="sm-summary-row">
                                <span class="sm-summary-label">売上金額</span>
                                <span class="sm-summary-value" id="smSumRevenue">-</span>
                            </div>
                            <div class="sm-summary-row">
                                <span class="sm-summary-label">枠数（イベントはコマ数）</span>
                                <span class="sm-summary-value" id="smSumFrame">-</span>
                            </div>
                            <div class="sm-summary-row">
                                <span class="sm-summary-label">光AD / 常勤 / イベント</span>
                                <span class="sm-summary-value" id="smSumDivision">-</span>
                            </div>
                        </div>

                        <div class="sm-memo">
                            <div class="sm-memo-head">
                                <span class="sm-memo-title">メモ</span>
                                <span class="sm-memo-status" id="smMemoStatus"></span>
                            </div>
                            <textarea id="smMemo" placeholder="メモを入力できます。" disabled></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
$inlineJs  = 'var smApiUrl = ' . json_encode(BASE_PATH . '/public/api/strategy_meeting.php') . ';';
$inlineJs .= 'var smCsrf = ' . json_encode($csrf) . ';';
$inlineJs .= 'var smFyLabel = ' . json_encode($fyLabel, JSON_UNESCAPED_UNICODE) . ';';
$inlineJs .= <<<'JS'

// ============================================================
// 戦略会議: 画面制御
// 既存データを読むだけ。書き込みは企業メモの保存のみ。
// ============================================================
var smState = {
    division:      '',   // 担当企業一覧の絞り込み: '' | 光AD | 常勤 | イベント
    trendDivision: '',   // 年推移に使う区分（押した企業カードの区分）
    rep:           null, // 選択中の営業マン名
    clientId:      null, // 選択中の取引先ID
    metric:    'revenue',
    periods:   [],
    frameUnit: '枠',
    chart:     null,
    memoTimer: null
};

function smYen(n) { return '¥' + (Number(n) || 0).toLocaleString('ja-JP'); }
function smEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
function smEmpty(icon, text) {
    return '<div class="sm-empty"><i class="bi bi-' + icon + '"></i>' + smEsc(text) + '</div>';
}
// 区分は呼び出し側が必要なときだけ渡す。
// 営業マンカードは区分で絞らない（切替は担当企業一覧にだけ効かせる）
function smGet(params) {
    var q = new URLSearchParams(params);
    return fetch(smApiUrl + '?' + q.toString(), {headers: {'X-Requested-With': 'fetch'}})
        .then(function (r) { return r.json(); });
}

// ---------- 営業マンカード ----------
function smLoadReps() {
    var box = document.getElementById('smRepList');
    return smGet({action: 'reps'}).then(function (d) {
        if (d.error) { box.innerHTML = smEmpty('exclamation-triangle', d.error); return; }
        if (!d.reps || !d.reps.length) {
            box.innerHTML = smEmpty('person-x', '社員一覧で「営業担当」にチェックが入っている社員がいません');
            return;
        }
        box.innerHTML = d.reps.map(function (r) {
            var active = (r.name === smState.rep) ? ' is-active' : '';
            return '' +
            '<div class="sm-rep-row' + active + '">' +
              '<div class="sm-rep-card">' +
                '<div class="sm-rep-ident">' +
                  '<span class="sm-rep-avatar"><i class="bi bi-person-circle"></i></span>' +
                  '<span class="sm-rep-name">' + smEsc(r.name) + '</span>' +
                '</div>' +
                '<div class="sm-rep-stats">' +
                  '<div class="sm-stat">' +
                    '<span class="sm-stat-ico is-client"><i class="bi bi-building"></i></span>' +
                    '<span class="sm-stat-label">クライアント数</span>' +
                    '<span class="sm-stat-value">' + r.client_count + '社</span>' +
                  '</div>' +
                  '<div class="sm-stat">' +
                    '<span class="sm-stat-ico is-alliance"><i class="bi bi-people-fill"></i></span>' +
                    '<span class="sm-stat-label">アライアンス数</span>' +
                    '<span class="sm-stat-value">' + r.alliance_count + '社</span>' +
                  '</div>' +
                  '<div class="sm-stat">' +
                    '<span class="sm-stat-ico is-revenue"><i class="bi bi-currency-yen"></i></span>' +
                    '<span class="sm-stat-label">売上金額</span>' +
                    '<span class="sm-stat-value">' + smYen(r.revenue) + '</span>' +
                  '</div>' +
                '</div>' +
              '</div>' +
              '<div class="sm-rep-branch">' +
                '<button type="button" class="sm-branch-btn" data-rep="' + smEsc(r.name) + '" title="担当企業を表示">' +
                  '<i class="bi bi-plus-lg"></i>' +
                '</button>' +
              '</div>' +
            '</div>';
        }).join('');
    }).catch(function () {
        box.innerHTML = smEmpty('wifi-off', '通信エラーが発生しました');
    });
}

// ---------- 担当企業一覧 ----------
function smLoadCompanies(rep) {
    var box   = document.getElementById('smCompanyList');
    var title = document.getElementById('smCompanyTitle');
    smState.rep = rep;
    title.textContent = rep + 'の担当企業一覧';
    box.innerHTML = smEmpty('hourglass-split', '読み込み中...');

    return smGet({action: 'companies', rep: rep, division: smState.division}).then(function (d) {
        if (d.error) { box.innerHTML = smEmpty('exclamation-triangle', d.error); return; }
        if (!d.companies || !d.companies.length) {
            box.innerHTML = smEmpty('inbox', '今年度の担当企業がありません');
            return;
        }
        box.innerHTML = d.companies.map(function (c) {
            var active = (String(c.client_id) === String(smState.clientId)) ? ' is-active' : '';
            return '' +
            '<button type="button" class="sm-company-card' + active + '"' +
                   ' data-client-id="' + c.client_id + '" data-division="' + smEsc(c.division) + '">' +
              '<span class="sm-company-ico"><i class="bi bi-building"></i></span>' +
              '<span class="sm-company-name">' + smEsc(c.label) + '</span>' +
              '<span class="sm-company-metrics">' +
                '<span class="sm-metric">' +
                  '<span class="sm-metric-label">光AD / 常勤 / イベント</span>' +
                  '<span class="sm-metric-value">' + smEsc(c.division) + '</span>' +
                '</span>' +
                '<span class="sm-metric">' +
                  '<span class="sm-metric-label">枠数（イベントはコマ数）</span>' +
                  '<span class="sm-metric-value">' + c.frame_count + smEsc(c.frame_unit) + '</span>' +
                '</span>' +
                '<span class="sm-metric">' +
                  '<span class="sm-metric-label">取引金額（' + smEsc(d.month_label) + '）</span>' +
                  '<span class="sm-metric-value">' + smYen(c.month_revenue) + '</span>' +
                '</span>' +
              '</span>' +
              '<span class="sm-company-chevron"><i class="bi bi-chevron-right"></i></span>' +
            '</button>';
        }).join('');
    }).catch(function () {
        box.innerHTML = smEmpty('wifi-off', '通信エラーが発生しました');
    });
}

// ---------- 年推移（期別） ----------
// 年推移は「選んだ企業カード」の内容に合わせる。
// 営業マンで絞り込み、区分は押したカードの区分（光AD/常勤/イベント）を使う
function smLoadTrend(clientId, division) {
    smState.clientId = clientId;
    if (division !== undefined) smState.trendDivision = division;
    var title = document.getElementById('smTrendTitle');

    return smGet({
        action:    'trend',
        client_id: clientId,
        rep:       smState.rep || '',
        division:  smState.trendDivision || ''
    }).then(function (d) {
        if (d.error) { title.textContent = d.error; return; }
        title.textContent = d.client_name + 'の年推移（期別）';
        smState.periods   = d.periods || [];
        smState.frameUnit = d.frame_unit || '枠';
        document.getElementById('smSumDivision').textContent = d.division || '-';

        var memo = document.getElementById('smMemo');
        memo.value = d.memo || '';
        memo.disabled = false;
        document.getElementById('smMemoStatus').textContent = '';

        smRenderTrend();
    }).catch(function () {
        title.textContent = '通信エラーが発生しました';
    });
}

// 棒グラフの上に数値を描く（画像と同じ見た目にするための小さな自作プラグイン）
var smValueLabelPlugin = {
    id: 'smValueLabel',
    afterDatasetsDraw: function (chart) {
        var meta = chart.getDatasetMeta(0);
        if (!meta || meta.hidden) return;
        var ctx = chart.ctx;
        ctx.save();
        ctx.font = '600 11px "Noto Sans JP", sans-serif';
        ctx.fillStyle = '#374151';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        meta.data.forEach(function (bar, i) {
            var v = chart.data.datasets[0].data[i];
            if (v === null || v === undefined) return;
            ctx.fillText(Number(v).toLocaleString('ja-JP'), bar.x, bar.y - 14);
        });
        ctx.restore();
    }
};

function smRenderTrend() {
    var canvas = document.getElementById('smTrendChart');
    var empty  = document.getElementById('smChartEmpty');
    var periods = smState.periods;

    if (!periods.length) {
        canvas.style.display = 'none';
        empty.style.display = '';
        empty.innerHTML = '<i class="bi bi-bar-chart-line"></i>この区分の実績がありません';
        document.getElementById('smSummaryTitle').textContent = '最新期のサマリー';
        document.getElementById('smSumRevenue').textContent = '-';
        document.getElementById('smSumFrame').textContent   = '-';
        if (smState.chart) { smState.chart.destroy(); smState.chart = null; }
        return;
    }

    empty.style.display = 'none';
    canvas.style.display = '';

    var isRev  = smState.metric === 'revenue';
    var labels = periods.map(function (p) { return p.label; });
    var values = periods.map(function (p) { return isRev ? p.revenue : p.frame_count; });
    var axis   = isRev ? '売上金額（円）' : '枠数（イベントはコマ数）';

    if (smState.chart) { smState.chart.destroy(); }
    smState.chart = new Chart(canvas.getContext('2d'), {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: axis,
                    data: values,
                    backgroundColor: '#bcd6f7',
                    borderRadius: 2,
                    order: 2,
                    barPercentage: 0.62,
                    categoryPercentage: 0.7
                },
                {
                    type: 'line',
                    label: '推移',
                    data: values,
                    borderColor: '#2563eb',
                    backgroundColor: '#2563eb',
                    borderWidth: 2,
                    pointRadius: 3.5,
                    pointBackgroundColor: '#2563eb',
                    tension: 0.25,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {padding: {top: 24}},
            plugins: {
                legend: {display: false},
                tooltip: {
                    callbacks: {
                        label: function (c) {
                            return isRev
                                ? '売上金額 ' + Number(c.parsed.y).toLocaleString('ja-JP') + '円'
                                : '枠数 ' + Number(c.parsed.y).toLocaleString('ja-JP') + smState.frameUnit;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {display: true, text: axis, font: {size: 10}, color: '#6b7280'},
                    ticks: {
                        font: {size: 10},
                        color: '#6b7280',
                        callback: function (v) { return Number(v).toLocaleString('ja-JP'); }
                    },
                    grid: {color: '#f1f3f6'}
                },
                x: {
                    ticks: {font: {size: 10}, color: '#6b7280'},
                    grid: {display: false}
                }
            }
        },
        plugins: [smValueLabelPlugin]
    });

    // サマリーは最新期（一番右）を表示する
    var latest = periods[periods.length - 1];
    document.getElementById('smSummaryTitle').textContent = '最新期（' + latest.label + '）のサマリー';
    document.getElementById('smSumRevenue').textContent = smYen(latest.revenue);
    document.getElementById('smSumFrame').textContent   = latest.frame_count + smState.frameUnit;
}

// ---------- メモの保存（戦略会議専用テーブルのみ） ----------
function smSaveMemo() {
    if (!smState.clientId) return;
    var status = document.getElementById('smMemoStatus');
    var fd = new FormData();
    fd.append('csrf', smCsrf);
    fd.append('action', 'save_memo');
    fd.append('client_id', smState.clientId);
    fd.append('memo', document.getElementById('smMemo').value);
    status.textContent = '保存中...';
    fetch(smApiUrl, {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) { status.textContent = d.success ? '保存しました' : (d.error || '保存に失敗しました'); })
        .catch(function () { status.textContent = '通信エラーが発生しました'; });
}

// ---------- イベント登録 ----------
document.addEventListener('DOMContentLoaded', function () {

    // 区分の切替: 画面全体を選んだ区分で集計しなおす
    document.getElementById('smFilter').addEventListener('click', function (e) {
        var btn = e.target.closest('.sm-filter-btn');
        if (!btn) return;
        this.querySelectorAll('.sm-filter-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        smState.division = btn.dataset.division || '';
        // 区分の切替は担当企業一覧だけに効かせる。
        // 営業マンカードの数値と、開いている年推移はそのまま変えない
        if (smState.rep) smLoadCompanies(smState.rep);
    });

    // 営業マンカードの「＋」
    document.getElementById('smRepList').addEventListener('click', function (e) {
        var btn = e.target.closest('.sm-branch-btn');
        if (!btn) return;
        smState.clientId = null;
        this.querySelectorAll('.sm-rep-row').forEach(function (r) { r.classList.remove('is-active'); });
        var row = btn.closest('.sm-rep-row');
        if (row) row.classList.add('is-active');
        smLoadCompanies(btn.dataset.rep);
        document.getElementById('smCompanyPanel').scrollIntoView({behavior: 'smooth', block: 'nearest'});
    });

    // 企業カード → 年推移
    document.getElementById('smCompanyList').addEventListener('click', function (e) {
        var card = e.target.closest('.sm-company-card');
        if (!card) return;
        this.querySelectorAll('.sm-company-card').forEach(function (c) { c.classList.remove('is-active'); });
        card.classList.add('is-active');
        smLoadTrend(card.dataset.clientId, card.dataset.division || '');
        document.getElementById('smTrendPanel').scrollIntoView({behavior: 'smooth', block: 'nearest'});
    });

    // 売上金額 / 枠数 の切替
    document.getElementById('smMetricSwitch').addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        this.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        smState.metric = btn.dataset.metric;
        smRenderTrend();
    });

    // 戻る
    document.getElementById('smCompanyBack').addEventListener('click', function () {
        smState.rep = null; smState.clientId = null;
        document.getElementById('smCompanyTitle').textContent = '担当企業一覧';
        document.getElementById('smCompanyList').innerHTML =
            smEmpty('hand-index-thumb', '営業マンカードの「＋」を押すと、担当企業が表示されます');
        smLoadReps();
    });
    document.getElementById('smTrendBack').addEventListener('click', function () {
        smState.clientId = null;
        smState.trendDivision = '';
        smState.periods = [];
        document.getElementById('smTrendTitle').textContent = '企業の年推移（期別）';
        document.getElementById('smMemo').value = '';
        document.getElementById('smMemo').disabled = true;
        document.getElementById('smMemoStatus').textContent = '';
        document.getElementById('smSumDivision').textContent = '-';
        smRenderTrend();
        if (smState.rep) smLoadCompanies(smState.rep);
    });

    // メモ: 入力が止まって1.5秒後に自動保存
    document.getElementById('smMemo').addEventListener('input', function () {
        clearTimeout(smState.memoTimer);
        document.getElementById('smMemoStatus').textContent = '';
        smState.memoTimer = setTimeout(smSaveMemo, 1500);
    });

    // ウィンドウ幅を変えたときはグラフを描き直す。
    // Chart.js はコンテナが縮んでも自動では縮まないため、作り直して合わせる
    var smResizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(smResizeTimer);
        smResizeTimer = setTimeout(function () {
            if (smState.periods.length) smRenderTrend();
        }, 200);
    });

    smLoadReps();

    // 案件の追加・編集を別タブで行った場合にも追従できるよう、
    // 既存画面（案件店舗管理・シフト管理）と同じ60秒間隔で読み直す
    setInterval(function () {
        smLoadReps().then(function () {
            if (smState.rep) return smLoadCompanies(smState.rep);
        }).then(function () {
            if (smState.clientId) return smLoadTrend(smState.clientId);
        });
    }, 60000);
});
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
