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

// 初期表示の対象年月は「前月」。
// 当月分の案件は月末にまとめて登録する運用のため、当月だと数字が入らないことがある
$_prev      = (new DateTimeImmutable('today'))->modify('first day of last month');
$initYear   = (int)$_prev->format('Y');
$initMonth  = (int)$_prev->format('n');

// 商談報告フォームの選択候補
// ※会社名は手入力。営業担当者だけは案件フォームと同じ基準にして表記ゆれを防ぐ
$repCandidates = getSalesRepCandidates($cid);   // 社員一覧で「営業担当」にチェックがある人
$negStatuses   = ['取引開始', '取引候補', '温度感低め', '合わない', '倒産', 'その他'];

// 商談報告の対象年月は当月を既定にする（過去にさかのぼって入力もできる）
$_today    = new DateTimeImmutable('today');
$curYear   = (int)$_today->format('Y');
$curMonth  = (int)$_today->format('n');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid sm-wrap">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-people-fill me-2"></i>戦略会議</h1>
                <?php /* 取引企業数の進捗。目標社数はクリックで編集できる（この画面は管理者専用） */ ?>
                <p class="sm-goal" id="smGoal">
                    <span class="sm-goal-count" id="smGoalCount">-</span><span class="sm-goal-sep">社 /</span>
                    <span class="sm-goal-target" id="smGoalTarget" role="button" tabindex="0"
                          title="クリックして目標企業数を変更">-</span><span class="sm-goal-sep">社</span>
                    <input type="number" class="sm-goal-input d-none" id="smGoalInput" min="1" max="100000">
                    <span class="sm-goal-note" id="smGoalNote"></span>
                </p>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <!-- 商談報告の登録（この画面は管理者専用のため、登録も管理者のみ） -->
                <button type="button" class="sm-neg-add" id="smNegAdd">
                    <i class="bi bi-plus-lg me-1"></i>商談報告
                </button>
                <!-- 対象月の送り。「月別」にしているパネルに適用する -->
                <div class="sm-monthnav" id="smMonthNav">
                    <button type="button" class="sm-monthnav-btn" data-delta="-1" title="前の月">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="sm-monthnav-label" id="smMonthLabel"><?= $initYear ?>年<?= $initMonth ?>月</span>
                    <button type="button" class="sm-monthnav-btn" data-delta="1" title="次の月">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <!-- 区分の切替。担当企業一覧の絞り込みにだけ使う（営業マンカードの数値は変わらない） -->
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

    <?php /* 会社数の年間推移。9月〜翌8月の順で表示する */ ?>
    <div class="sm-panel sm-trend2-panel">
        <div class="sm-subpanel-head">
            <h2 class="sm-subpanel-title"><i class="bi bi-graph-up-arrow me-1"></i>会社数の年間推移</h2>
            <span class="sm-head-note" id="smT2Label"></span>
        </div>
        <div class="sm-t2-chart-box">
            <canvas id="smT2Chart" class="sm-t2-canvas"></canvas>
        </div>
        <div class="sm-t2-table-wrap">
            <table class="sm-t2-table">
                <thead id="smT2Thead"></thead>
                <tbody id="smT2Tbody"></tbody>
            </table>
        </div>
    </div>

    <?php /* 商談報告の一覧 */ ?>
    <div class="sm-panel sm-neg-panel">
        <div class="sm-subpanel-head">
            <h2 class="sm-subpanel-title"><i class="bi bi-chat-left-text me-1"></i>商談報告</h2>
            <span class="sm-head-note" id="smNegCount"></span>
            <button type="button" class="sm-neg-toggle" id="smNegToggle" aria-expanded="false">
                <i class="bi bi-chevron-down"></i><span>一覧を開く</span>
            </button>
        </div>
        <div class="sm-neg-body d-none" id="smNegBody">
            <div id="smNegList"></div>
        </div>
    </div>

    <div class="sm-layout">

        <!-- ================= 左: 営業マン一覧 ================= -->
        <?php /* 画面上部のタイトルと説明が重複しないよう、ここは一覧の見出しだけにする */ ?>
        <div class="sm-panel sm-panel-reps">
            <div class="sm-subpanel-head">
                <h2 class="sm-subpanel-title"><i class="bi bi-people-fill me-1"></i>営業マン一覧</h2>
                <div class="sm-period-switch ms-auto" id="smRepPeriod">
                    <button type="button" class="active" data-period="month">月別</button>
                    <button type="button" data-period="fy">年度</button>
                </div>
                <span class="sm-head-note" id="smRepPeriodNote"><?= $initYear ?>年<?= $initMonth ?>月</span>
            </div>
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
                    <div class="sm-period-switch ms-auto" id="smCompPeriod">
                        <button type="button" class="active" data-period="month">月別</button>
                        <button type="button" data-period="fy">年度</button>
                    </div>
                    <span class="sm-head-note" id="smCompPeriodNote"><?= $initYear ?>年<?= $initMonth ?>月</span>
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
                    <div class="sm-chart-box is-empty">
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

<!-- ================= 商談報告の入力フォーム ================= -->
<div class="modal fade" id="smNegModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="smNegForm">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="smNegModalTitle">商談報告</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="smNegId" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">営業担当者 <span class="text-danger">*</span></label>
                        <?php /* 表記ゆれを防ぐため、案件フォームと同じく社員一覧から選ぶ */ ?>
                        <select class="form-select" id="smNegRep" required>
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($repCandidates as $_rc): ?>
                            <option value="<?= h($_rc) ?>"><?= h($_rc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">対象年月 <span class="text-danger">*</span></label>
                        <?php /* まとめて入力するときのために過去の月も指定できる */ ?>
                        <input type="month" class="form-control" id="smNegYm"
                               value="<?= sprintf('%04d-%02d', $curYear, $curMonth) ?>" required>
                        <div class="form-text" style="font-size:.7rem">この商談・ステータス変更が起きた月</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium">会社名 <span class="text-danger">*</span></label>
                        <?php /* 手入力のみ。会社数は会社名で突き合わせるため、既存の取引先と表記を揃えて入力する */ ?>
                        <input type="text" class="form-control" id="smNegClient"
                               placeholder="会社名を入力" autocomplete="off" maxlength="200" required>
                        <div class="form-text" style="font-size:.7rem">
                            同じ会社はまとめて1件で管理されます（重複登録はできません）。<br>
                            すでに取引のある会社は、取引先一覧と同じ表記で入力してください。
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">ステータス <span class="text-danger">*</span></label>
                        <select class="form-select" id="smNegStatus" required>
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($negStatuses as $_s): ?>
                            <option value="<?= h($_s) ?>"><?= h($_s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-none" id="smNegOtherWrap">
                        <label class="form-label fw-medium">その他の内容 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="smNegOther" maxlength="100">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium">備考</label>
                        <textarea class="form-control" id="smNegNote" rows="2"></textarea>
                    </div>
                </div>
                <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="smNegError" style="font-size:.82rem"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-danger btn-sm me-auto d-none" id="smNegDelete">
                    <i class="bi bi-trash me-1"></i>削除
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="submit" class="btn btn-primary btn-sm" id="smNegSubmit">保存</button>
            </div>
        </form>
    </div>
</div>

<?php
$inlineJs  = 'var smApiUrl = ' . json_encode(BASE_PATH . '/public/api/strategy_meeting.php') . ';';
$inlineJs .= 'var smCsrf = ' . json_encode($csrf) . ';';
$inlineJs .= 'var smInitYear = ' . (int)$initYear . '; var smInitMonth = ' . (int)$initMonth . ';';
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
    goalTarget:    100,  // 目標企業数
    repPeriod:     'month', // 営業マン一覧の集計期間: 'month' | 'fy'
    compPeriod:    'month', // 担当企業一覧の集計期間: 'month' | 'fy'
    year:          smInitYear,  // 「月別」のときの対象年
    month:         smInitMonth, // 「月別」のときの対象月
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

// 集計期間のパラメータ。年度のときも対象年月を送り、その月が属する年度を集計する
function smPeriodParams(period) {
    return {period: period, year: smState.year, month: smState.month};
}

// ---------- 取引企業数の合計（〇〇社 / 目標社数） ----------
// 営業マンカードの数字の単純合計ではなく、重複を除いた実企業数。
// 月別/年度の切替には連動せず、今年度で固定
function smLoadGoal() {
    return smGet({action: 'summary', year: smState.year, month: smState.month}).then(function (d) {
        if (d.error) return;
        smState.goalTarget = d.target;
        document.getElementById('smGoalCount').textContent  = Number(d.count).toLocaleString('ja-JP');
        document.getElementById('smGoalTarget').textContent = Number(d.target).toLocaleString('ja-JP');
        document.getElementById('smGoalNote').textContent   = '今年度（' + d.fy_label + '）／重複を除いた実企業数';
    }).catch(function () { /* 表示だけなので失敗しても他の集計は止めない */ });
}

// 目標社数をその場で編集する
function smOpenGoalEdit() {
    var label = document.getElementById('smGoalTarget');
    var input = document.getElementById('smGoalInput');
    input.value = smState.goalTarget || 100;
    label.classList.add('d-none');
    input.classList.remove('d-none');
    input.focus();
    input.select();
}

function smCommitGoalEdit(save) {
    var label = document.getElementById('smGoalTarget');
    var input = document.getElementById('smGoalInput');
    var note  = document.getElementById('smGoalNote');
    input.classList.add('d-none');
    label.classList.remove('d-none');
    if (!save) return;

    var v = parseInt(input.value, 10);
    if (!v || v < 1 || v > 100000) { note.textContent = '1〜100000の数値を入力してください'; return; }
    if (v === smState.goalTarget) return;

    var fd = new FormData();
    fd.append('csrf', smCsrf);
    fd.append('action', 'save_target');
    fd.append('target', v);
    fetch(smApiUrl, {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                smState.goalTarget = d.target;
                label.textContent = Number(d.target).toLocaleString('ja-JP');
            } else {
                note.textContent = d.error || '保存に失敗しました';
            }
        })
        .catch(function () { note.textContent = '通信エラーが発生しました'; });
}

// ---------- 営業マンカード ----------
function smLoadReps() {
    var box = document.getElementById('smRepList');
    var qs  = smPeriodParams(smState.repPeriod);
    qs.action = 'reps';
    return smGet(qs).then(function (d) {
        if (d.period_label) document.getElementById('smRepPeriodNote').textContent = d.period_label;
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

    var qs = smPeriodParams(smState.compPeriod);
    qs.action = 'companies';
    qs.rep = rep;
    qs.division = smState.division;
    return smGet(qs).then(function (d) {
        if (d.period_label) document.getElementById('smCompPeriodNote').textContent = d.period_label;
        if (d.error) { box.innerHTML = smEmpty('exclamation-triangle', d.error); return; }
        if (!d.companies || !d.companies.length) {
            box.innerHTML = smEmpty('inbox', (d.period_label || 'この期間') + 'の担当企業がありません');
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
                  '<span class="sm-metric-label">取引金額（' + smEsc(d.period_label) + '）</span>' +
                  '<span class="sm-metric-value">' + smYen(c.revenue) + '</span>' +
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
        // 実績が無いときはグラフ枠を詰める（大きな空白が残らないように）
        empty.parentElement.classList.add('is-empty');
        empty.innerHTML = '<i class="bi bi-bar-chart-line"></i>この区分の実績がありません';
        document.getElementById('smSummaryTitle').textContent = '最新期のサマリー';
        document.getElementById('smSumRevenue').textContent = '-';
        document.getElementById('smSumFrame').textContent   = '-';
        if (smState.chart) { smState.chart.destroy(); smState.chart = null; }
        return;
    }

    empty.style.display = 'none';
    empty.parentElement.classList.remove('is-empty');
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

// ============================================================
// 会社数の年間推移（グラフ＋表）
// 9月〜翌8月の順。グラフと表は同じデータを参照する
// ============================================================
var smT2Chart  = null;
var smT2Months = [];

function smLoadTrend2() {
    return smGet({action: 'trend_companies', year: smState.year, month: smState.month})
        .then(function (d) {
            if (d.error) return;
            smT2Months = d.months || [];
            document.getElementById('smT2Label').textContent = '年度（' + d.fy_label + '）';
            smRenderTrend2();
        })
        .catch(function () { /* 表示だけなので失敗しても他の集計は止めない */ });
}

function smRenderTrend2() {
    if (!smT2Months.length) return;
    var labels    = smT2Months.map(function (m) { return m.month + '月'; });
    var newNeg    = smT2Months.map(function (m) { return m.new_negotiations; });
    var converted = smT2Months.map(function (m) { return m.converted; });
    var company   = smT2Months.map(function (m) { return m.company_count; });
    var active    = smT2Months.map(function (m) { return m.active_count; });
    // 目標が未入力(0)の月は点を打たない（担当者別売上の目標線と同じ扱い）
    var tCompany  = smT2Months.map(function (m) { return m.target_company > 0 ? m.target_company : null; });
    var tActive   = smT2Months.map(function (m) { return m.target_active  > 0 ? m.target_active  : null; });

    if (smT2Chart) { smT2Chart.destroy(); smT2Chart = null; }
    smT2Chart = new Chart(document.getElementById('smT2Chart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                // 棒（左軸）: その月の件数
                {label: '新規商談数', type: 'bar', data: newNeg,
                 backgroundColor: '#93c5fd', yAxisID: 'y', order: 4},
                {label: '取引・候補になった数', type: 'bar', data: converted,
                 backgroundColor: '#fca5a5', yAxisID: 'y', order: 4},
                // 折れ線（右軸）: 累計の会社数
                {label: '会社数実績', type: 'line', data: company,
                 borderColor: '#2563eb', backgroundColor: '#2563eb', pointBackgroundColor: '#2563eb',
                 yAxisID: 'y2', tension: 0, borderWidth: 2, pointRadius: 3.5, order: 1},
                {label: '会社数目標', type: 'line', data: tCompany,
                 borderColor: '#2563eb', backgroundColor: 'transparent', borderDash: [6, 4],
                 yAxisID: 'y2', tension: 0, borderWidth: 2, pointRadius: 0, spanGaps: true, order: 2},
                {label: '取引有会社数実績', type: 'line', data: active,
                 borderColor: '#dc2626', backgroundColor: '#dc2626', pointBackgroundColor: '#dc2626',
                 yAxisID: 'y2', tension: 0, borderWidth: 2, pointRadius: 3.5, order: 1},
                {label: '取引有会社数目標', type: 'line', data: tActive,
                 borderColor: '#dc2626', backgroundColor: 'transparent', borderDash: [6, 4],
                 yAxisID: 'y2', tension: 0, borderWidth: 2, pointRadius: 0, spanGaps: true, order: 2},
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {position: 'top', labels: {boxWidth: 14, font: {size: 10}}},
                tooltip: {mode: 'index', intersect: false},
            },
            scales: {
                y:  {type: 'linear', position: 'left', beginAtZero: true,
                     title: {display: true, text: '（件）', font: {size: 10}, color: '#6b7280'},
                     ticks: {precision: 0, font: {size: 10}}, grid: {color: 'rgba(0,0,0,.06)'}},
                y2: {type: 'linear', position: 'right', beginAtZero: true,
                     title: {display: true, text: '（社）', font: {size: 10}, color: '#6b7280'},
                     ticks: {precision: 0, font: {size: 10}}, grid: {drawOnChartArea: false}},
                x:  {ticks: {font: {size: 10}}, grid: {display: false}},
            },
        },
    });

    smRenderTrend2Table();
}

// 表はグラフと同じ数字を使う（別々に持たない）
function smRenderTrend2Table() {
    var thead = document.getElementById('smT2Thead');
    var tbody = document.getElementById('smT2Tbody');
    var CUR   = ' class="sm-t2-cur"';

    thead.innerHTML = '<tr><th class="sm-t2-rowhead">月</th>' +
        smT2Months.map(function (m) { return '<th>' + m.month + '月</th>'; }).join('') +
        '<th class="sm-t2-cur">現在</th></tr>';

    // 「現在」列は、当月（または年度末）時点の値を出す。累計なので合計はしない
    var nowYm = smState.year * 100 + smState.month;
    var curIdx = 0;
    smT2Months.forEach(function (m, i) { if (m.ym <= nowYm) curIdx = i; });
    var cur = smT2Months[curIdx];

    var num = function (v) {
        return v > 0 ? String(v) : '<span class="text-muted">-</span>';
    };
    var pct = function (actual, target) {
        // 目標が未入力・0のときは計算しない（0で割らない）
        if (!target || target <= 0) return '<span class="text-muted">-</span>';
        var p = Math.round(actual / target * 1000) / 10;
        return '<span style="font-weight:600;color:' + (p >= 100 ? '#059669' : '#dc2626') + '">' + p + '%</span>';
    };

    var html = '';

    // 1. 会社数目標（管理者は手入力可）
    html += '<tr><td class="sm-t2-rowhead"><span class="sm-t2-line is-blue is-dashed"></span>会社数目標</td>';
    smT2Months.forEach(function (m, i) {
        html += '<td class="p-0"><input type="text" inputmode="numeric" class="sm-t2-inp"'
             + ' data-field="company" data-idx="' + i + '"'
             + ' value="' + (m.target_company > 0 ? m.target_company : '') + '"></td>';
    });
    html += '<td' + CUR + '>' + num(cur.target_company) + '</td></tr>';

    // 2. 会社数実績
    html += '<tr><td class="sm-t2-rowhead"><span class="sm-t2-line is-blue is-solid"></span>会社数実績</td>'
         + smT2Months.map(function (m) { return '<td>' + num(m.company_count) + '</td>'; }).join('')
         + '<td' + CUR + '>' + num(cur.company_count) + '</td></tr>';

    // 3. 取引有会社数目標（管理者は手入力可）
    html += '<tr><td class="sm-t2-rowhead"><span class="sm-t2-line is-red is-dashed"></span>取引有会社数目標</td>';
    smT2Months.forEach(function (m, i) {
        html += '<td class="p-0"><input type="text" inputmode="numeric" class="sm-t2-inp"'
             + ' data-field="active" data-idx="' + i + '"'
             + ' value="' + (m.target_active > 0 ? m.target_active : '') + '"></td>';
    });
    html += '<td' + CUR + '>' + num(cur.target_active) + '</td></tr>';

    // 4. 取引有会社数実績
    html += '<tr><td class="sm-t2-rowhead"><span class="sm-t2-line is-red is-solid"></span>取引有会社数実績</td>'
         + smT2Months.map(function (m) { return '<td>' + num(m.active_count) + '</td>'; }).join('')
         + '<td' + CUR + '>' + num(cur.active_count) + '</td></tr>';

    // 5. 会社数達成率
    html += '<tr><td class="sm-t2-rowhead">会社数達成率</td>'
         + smT2Months.map(function (m, i) {
               return '<td id="smT2AchvC' + i + '">' + pct(m.company_count, m.target_company) + '</td>';
           }).join('')
         + '<td' + CUR + ' id="smT2AchvCCur">' + pct(cur.company_count, cur.target_company) + '</td></tr>';

    // 6. 取引有会社数達成率
    html += '<tr><td class="sm-t2-rowhead">取引有会社数達成率</td>'
         + smT2Months.map(function (m, i) {
               return '<td id="smT2AchvA' + i + '">' + pct(m.active_count, m.target_active) + '</td>';
           }).join('')
         + '<td' + CUR + ' id="smT2AchvACur">' + pct(cur.active_count, cur.target_active) + '</td></tr>';

    tbody.innerHTML = html;

    // 目標を入力 → 達成率とグラフの点線をその場で更新 → 非同期で保存（リロードなし）
    tbody.querySelectorAll('.sm-t2-inp').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var i = parseInt(inp.dataset.idx, 10);
            var v = Math.max(0, parseInt(String(inp.value).replace(/[^0-9]/g, ''), 10) || 0);
            if (inp.dataset.field === 'company') smT2Months[i].target_company = v;
            else                                 smT2Months[i].target_active  = v;
            smT2SyncCell(i);
            smT2SyncChartTargets();
        });
        inp.addEventListener('change', function () {
            var i = parseInt(inp.dataset.idx, 10);
            var m = smT2Months[i];
            var v = inp.dataset.field === 'company' ? m.target_company : m.target_active;
            inp.value = v > 0 ? String(v) : '';
            var fd = new FormData();
            fd.append('csrf', smCsrf);
            fd.append('action', 'save_monthly_target');
            fd.append('t_year', m.year);
            fd.append('t_month', m.month);
            fd.append('field', inp.dataset.field);
            fd.append('value', v);
            fetch(smApiUrl, {method: 'POST', body: fd})
                .then(function (r) { return r.json(); })
                .then(function (d) { inp.style.background = (d && d.error) ? '#fee2e2' : 'transparent'; })
                .catch(function () { inp.style.background = '#fee2e2'; });
        });
    });
}

// 達成率セルを1ヶ月分だけ更新する
function smT2SyncCell(i) {
    var m = smT2Months[i];
    var f = function (actual, target) {
        if (!target || target <= 0) return '<span class="text-muted">-</span>';
        var p = Math.round(actual / target * 1000) / 10;
        return '<span style="font-weight:600;color:' + (p >= 100 ? '#059669' : '#dc2626') + '">' + p + '%</span>';
    };
    var c = document.getElementById('smT2AchvC' + i);
    var a = document.getElementById('smT2AchvA' + i);
    if (c) c.innerHTML = f(m.company_count, m.target_company);
    if (a) a.innerHTML = f(m.active_count,  m.target_active);
}

// グラフの点線（目標）だけを差し替える（作り直さない）
function smT2SyncChartTargets() {
    if (!smT2Chart) return;
    var ds = smT2Chart.data.datasets;
    ds[3].data = smT2Months.map(function (m) { return m.target_company > 0 ? m.target_company : null; });
    ds[5].data = smT2Months.map(function (m) { return m.target_active  > 0 ? m.target_active  : null; });
    smT2Chart.update();
}

// ============================================================
// 商談報告（1社につき1件。ステータスを書き換えていく）
// ============================================================
var smNegModal = null;
var smNegRows  = [];

function smNegStatusClass(status) {
    if (status === '取引開始') return 'is-active';
    if (status === '取引候補') return 'is-candidate';
    return 'is-out';
}

function smLoadNegotiations() {
    var list = document.getElementById('smNegList');
    return smGet({action: 'negotiations'}).then(function (d) {
        if (d.error) { list.innerHTML = smEmpty('exclamation-triangle', d.error); return; }
        smNegRows = d.negotiations || [];
        document.getElementById('smNegCount').textContent = smNegRows.length + '件';

        if (!smNegRows.length) {
            list.innerHTML = smEmpty('chat-left-text', '「＋商談報告」から登録できます');
            return;
        }
        list.innerHTML =
            '<div class="sm-neg-table-wrap"><table class="sm-neg-table">' +
            '<thead><tr>' +
              '<th>会社名</th><th>営業担当者</th><th>ステータス</th><th>初回登録</th><th></th>' +
            '</tr></thead><tbody>' +
            smNegRows.map(function (r) {
                var label = r.status === 'その他' && r.status_other
                    ? 'その他（' + smEsc(r.status_other) + '）' : smEsc(r.status);
                return '<tr' + (r.excluded ? ' class="is-excluded"' : '') + '>' +
                    '<td class="fw-semibold">' + smEsc(r.client_name) + '</td>' +
                    '<td>' + smEsc(r.rep_name) + '</td>' +
                    '<td><span class="sm-neg-status ' + smNegStatusClass(r.status) + '">' + label + '</span></td>' +
                    '<td class="text-nowrap">' + smEsc(r.first_label) + '</td>' +
                    '<td class="text-end"><button type="button" class="sm-neg-edit" data-id="' + r.id + '">' +
                      '<i class="bi bi-pencil"></i></button></td>' +
                '</tr>';
            }).join('') +
            '</tbody></table></div>';
    }).catch(function () {
        list.innerHTML = smEmpty('wifi-off', '通信エラーが発生しました');
    });
}

function smNegShowError(msg) {
    var el = document.getElementById('smNegError');
    if (!msg) { el.classList.add('d-none'); el.textContent = ''; return; }
    el.textContent = msg;
    el.classList.remove('d-none');
}

function smNegToggleOther() {
    var isOther = document.getElementById('smNegStatus').value === 'その他';
    document.getElementById('smNegOtherWrap').classList.toggle('d-none', !isOther);
    document.getElementById('smNegOther').required = isOther;
}

function smNegOpen(row) {
    smNegShowError('');
    document.getElementById('smNegId').value     = row ? row.id : '';
    document.getElementById('smNegRep').value    = row ? (row.rep_name || '') : '';
    document.getElementById('smNegClient').value = row ? row.client_name : '';
    document.getElementById('smNegStatus').value = row ? row.status : '';
    document.getElementById('smNegOther').value  = row ? (row.status_other || '') : '';
    document.getElementById('smNegNote').value   = row ? (row.note || '') : '';
    document.getElementById('smNegModalTitle').textContent = row ? '商談報告の編集' : '商談報告';
    document.getElementById('smNegDelete').classList.toggle('d-none', !row);
    // 対象年月は既定で当月。まとめ入力のため過去の月にも変更できる
    var d = new Date();
    document.getElementById('smNegYm').value =
        d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2);
    smNegToggleOther();

    if (!smNegModal) smNegModal = new bootstrap.Modal(document.getElementById('smNegModal'));
    smNegModal.show();
}

function smNegSave() {
    var ym = document.getElementById('smNegYm').value || '';
    var parts = ym.split('-');
    if (parts.length !== 2) { smNegShowError('対象年月を選択してください'); return; }

    var fd = new FormData();
    fd.append('csrf', smCsrf);
    fd.append('action', 'save_negotiation');
    fd.append('id', document.getElementById('smNegId').value || '');
    fd.append('rep_name', document.getElementById('smNegRep').value);
    fd.append('client_name', document.getElementById('smNegClient').value.trim());
    fd.append('status', document.getElementById('smNegStatus').value);
    fd.append('status_other', document.getElementById('smNegOther').value.trim());
    fd.append('note', document.getElementById('smNegNote').value.trim());
    fd.append('ym_year', parseInt(parts[0], 10));
    fd.append('ym_month', parseInt(parts[1], 10));

    var btn = document.getElementById('smNegSubmit');
    btn.disabled = true;
    fetch(smApiUrl, {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.success) { smNegShowError(d.error || '保存に失敗しました'); return; }
            smNegModal.hide();
            smLoadNegotiations();
            smLoadGoal();     // 取引企業数の進捗にも反映する
            smLoadTrend2();   // 年間推移グラフ・表にも反映する
        })
        .catch(function () { btn.disabled = false; smNegShowError('通信エラーが発生しました'); });
}

function smNegDelete() {
    var id = document.getElementById('smNegId').value;
    if (!id) return;
    if (!confirm('この商談報告を削除しますか？')) return;
    var fd = new FormData();
    fd.append('csrf', smCsrf);
    fd.append('action', 'delete_negotiation');
    fd.append('id', id);
    fetch(smApiUrl, {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { smNegShowError(d.error || '削除に失敗しました'); return; }
            smNegModal.hide();
            smLoadNegotiations();
            smLoadGoal();
            smLoadTrend2();
        })
        .catch(function () { smNegShowError('通信エラーが発生しました'); });
}

// ---------- 集計期間（月別 / 年度）の切替 ----------
// 押されたパネルだけを取り直す。全画面のリロードはしない
function smBindPeriodSwitch(elId, stateKey, reload) {
    document.getElementById(elId).addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn || btn.dataset.period === smState[stateKey]) return;
        this.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        smState[stateKey] = btn.dataset.period;
        smUpdateMonthNav();
        reload();
    });
}

// 月送りは「月別」にしているパネルがあるときだけ操作できる
function smUpdateMonthNav() {
    var nav = document.getElementById('smMonthNav');
    var useMonth = (smState.repPeriod === 'month' || smState.compPeriod === 'month');
    nav.classList.toggle('is-disabled', !useMonth);
    document.getElementById('smMonthLabel').textContent = smState.year + '年' + smState.month + '月';
}

// 月を変えたときは「月別」にしているパネルだけを取り直す。
// 年間推移は年度が変わることがあるので毎回取り直す
function smReloadForMonth() {
    if (smState.repPeriod === 'month') smLoadReps();
    if (smState.compPeriod === 'month' && smState.rep) smLoadCompanies(smState.rep);
    smLoadTrend2();
}

// ---------- イベント登録 ----------
document.addEventListener('DOMContentLoaded', function () {

    smBindPeriodSwitch('smRepPeriod', 'repPeriod', function () { smLoadReps(); });
    smBindPeriodSwitch('smCompPeriod', 'compPeriod', function () {
        if (smState.rep) smLoadCompanies(smState.rep);
    });

    // 月送り（前の月 / 次の月）
    document.getElementById('smMonthNav').addEventListener('click', function (e) {
        if (this.classList.contains('is-disabled')) return;
        var btn = e.target.closest('.sm-monthnav-btn');
        if (!btn) return;
        var m = smState.month + parseInt(btn.dataset.delta, 10);
        var y = smState.year;
        if (m < 1)  { m = 12; y -= 1; }
        if (m > 12) { m = 1;  y += 1; }
        smState.year = y; smState.month = m;
        smUpdateMonthNav();
        smReloadForMonth();
    });

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
            if (smT2Months.length) smRenderTrend2();
        }, 200);
    });

    // 目標企業数のその場編集
    document.getElementById('smGoalTarget').addEventListener('click', smOpenGoalEdit);
    document.getElementById('smGoalTarget').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); smOpenGoalEdit(); }
    });
    document.getElementById('smGoalInput').addEventListener('blur', function () { smCommitGoalEdit(true); });
    document.getElementById('smGoalInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter')  { e.preventDefault(); this.blur(); }
        if (e.key === 'Escape') { smCommitGoalEdit(false); }
    });

    // 商談報告
    document.getElementById('smNegAdd').addEventListener('click', function () { smNegOpen(null); });
    document.getElementById('smNegStatus').addEventListener('change', smNegToggleOther);
    document.getElementById('smNegForm').addEventListener('submit', function (e) {
        e.preventDefault();
        smNegSave();
    });
    document.getElementById('smNegDelete').addEventListener('click', smNegDelete);
    document.getElementById('smNegList').addEventListener('click', function (e) {
        var btn = e.target.closest('.sm-neg-edit');
        if (!btn) return;
        var row = smNegRows.filter(function (r) { return String(r.id) === String(btn.dataset.id); })[0];
        if (row) smNegOpen(row);
    });
    document.getElementById('smNegToggle').addEventListener('click', function () {
        var body = document.getElementById('smNegBody');
        var open = body.classList.toggle('d-none') === false;
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
        this.querySelector('span').textContent = open ? '一覧を閉じる' : '一覧を開く';
        this.querySelector('i').className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
    });

    smUpdateMonthNav();
    smLoadGoal();
    smLoadTrend2();
    smLoadNegotiations();
    smLoadReps();

    // 案件の追加・編集を別タブで行った場合にも追従できるよう、
    // 既存画面（案件店舗管理・シフト管理）と同じ60秒間隔で読み直す
    setInterval(function () {
        smLoadGoal();
        smLoadTrend2();
        smLoadNegotiations();
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
