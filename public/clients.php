<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
// 営業マン用画面: 管理者または営業担当のみ閲覧可（URL直打ちでも弾く）
requireSalesPageView();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }
// 権限は上の requireSalesPageView() で確認済み（管理者 または 営業担当）

$pageTitle = '取引先一覧';
$extraCss  = ['sales.css'];

$csrf        = getCsrfToken();
$driveStatus = googleDriveConfigStatus();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-people me-2"></i>取引先一覧</h1>
                <p>登録されている取引先・外注先の情報および契約書の登録状況を一覧で確認できます。</p>
            </div>
            <?php if (isAdmin()): /* 取引先の追加は管理者のみ */ ?>
            <button type="button" class="btn btn-primary" onclick="clOpenForm()">
                <i class="bi bi-plus-lg me-1"></i>取引先を追加
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php
    /* タブ: 取引先（その年度に案件がある）／ パートナー候補（案件がまだ無い）／ 外注先。
       案件が発生すると自動的に「取引先」へ移るので、手で移し替える必要はない */
    $clTab = ($_GET['tab'] ?? '') === 'candidate' ? 'candidate' : 'client';
    ?>
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link<?= $clTab === 'client' ? ' active' : '' ?>" href="<?= BASE_PATH ?>/public/clients.php">
                <i class="bi bi-building me-1"></i>取引先
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $clTab === 'candidate' ? ' active' : '' ?>" href="<?= BASE_PATH ?>/public/clients.php?tab=candidate">
                <i class="bi bi-person-plus me-1"></i>パートナー候補
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= BASE_PATH ?>/public/alliances.php">
                <i class="bi bi-people me-1"></i>外注先
            </a>
        </li>
    </ul>

    <?php if ($clTab === 'candidate'): ?>
    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.82rem">
        <i class="bi bi-info-circle me-1"></i>
        <strong>この年度に案件がまだ無い取引先</strong>です。商談報告のステータスと担当者もあわせて表示しています。<br>
        案件が登録されると<strong>自動的に「取引先」タブへ移ります</strong>（手で移し替える必要はありません）。
        ここに会社を追加するには、右上の「取引先を追加」から登録してください。
    </div>
    <?php endif; ?>


    <div class="card mb-3 tr-fybar">
        <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">年度</span>
                <div class="tr-fynav" id="clFyNav">
                    <button type="button" class="tr-fynav-btn" onclick="clShiftFy(-1)" title="前の年度">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="tr-fynav-label" id="clFyLabel">-</span>
                    <button type="button" class="tr-fynav-btn" onclick="clShiftFy(1)" title="次の年度">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div class="tr-summary small" id="clSummary"></div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <!-- 検索欄 -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="input-group input-group-sm" style="max-width:420px">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="clSearch" class="form-control border-start-0 ps-0"
                           placeholder="会社名・表記名・担当者名で検索" oninput="clSearchInput()">
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-primary" id="clShowActive" onclick="clSetShow('active')">登録中</button>
                    <button type="button" class="btn btn-outline-secondary" id="clShowDeleted" onclick="clSetShow('deleted')">
                        削除済み <span class="badge bg-secondary ms-1" id="clDeletedBadge">0</span>
                    </button>
                </div>
            </div>

            <!-- 削除済み表示時の一括復元 -->
            <div class="alert alert-warning py-2 px-3 d-none align-items-center justify-content-between flex-wrap gap-2"
                 id="clRestoreBar" style="font-size:.82rem">
                <div>
                    削除済みの取引先です。案件画面の取引先プルダウンには表示されません。
                </div>
                <?php if (isAdmin()): /* 一括復元は管理者のみ */ ?>
                <button type="button" class="btn btn-warning btn-sm" onclick="clRestoreAll()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>すべて元に戻す
                </button>
                <?php endif; ?>
            </div>

            <!-- 一覧表 -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 cl-table">
                    <thead class="table-light">
                        <tr>
                            <th>会社名</th>
                            <th>表記名 <span class="text-muted fw-normal" style="font-size:.7rem">（アプリ内表示名）</span></th>
                            <th>担当者名</th>
                            <th>メールアドレス</th>
                            <th>電話番号</th>
                            <?php /* 候補タブのときだけ出す列。取引先タブでは契約書を出す */ ?>
                            <th class="cl-col-cand d-none" style="width:130px">商談ステータス</th>
                            <th class="cl-col-cand d-none" style="width:150px">担当者</th>
                            <th class="cl-col-client" style="width:150px">契約書格納</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="clTbody">
                        <tr><td colspan="9" class="text-center text-muted py-4">
                            <span class="spinner-border spinner-border-sm me-2"></span>読み込み中...
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- 件数表示・ページネーション -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <div class="text-muted small" id="clCount"></div>
                <nav><ul class="pagination pagination-sm mb-0" id="clPager"></ul></nav>
            </div>
        </div>
    </div>

    <!-- Googleドライブ連携の説明欄 -->
    <div class="alert alert-info d-flex align-items-start gap-2" role="alert" style="font-size:.82rem">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            契約書はGoogleドライブと連携しています。<br>
            契約書が登録されていない場合は「無し」と表示されます。
            <?php if (!$driveStatus['configured']): ?>
            <div class="text-muted mt-1" style="font-size:.75rem">
                現在の連携設定：<span class="fw-semibold">未設定</span>（サーバー設定後に有効化されます）
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 追加・編集モーダル -->
<div class="modal fade" id="clModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="clModalTitle">取引先を追加</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="clId">
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">会社名 <span class="text-danger">*</span></label>
                    <input type="text" id="clName" class="form-control form-control-sm" placeholder="株式会社エックス通信">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">表記名（アプリ内表示名） <span class="text-danger">*</span></label>
                    <input type="text" id="clDisplay" class="form-control form-control-sm" placeholder="エックス通信">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">担当者名</label>
                    <input type="text" id="clPerson" class="form-control form-control-sm" placeholder="山田 太郎">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">メールアドレス</label>
                    <input type="email" id="clEmail" class="form-control form-control-sm" placeholder="yamada.taro@example.co.jp">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">電話番号</label>
                    <input type="text" id="clPhone" class="form-control form-control-sm" placeholder="03-1234-5678">
                </div>
                <hr class="my-3">
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">契約書（GoogleドライブのURL または ファイルID）</label>
                    <input type="text" id="clContract" class="form-control form-control-sm" placeholder="https://drive.google.com/file/d/xxxxx/view">
                    <div class="form-text" style="font-size:.72rem">
                        未入力でも登録できます（一覧では「無し」と表示されます）。後から追加・変更できます。
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">契約書のファイル名（任意）</label>
                    <input type="text" id="clContractName" class="form-control form-control-sm" placeholder="業務委託基本契約書.pdf">
                </div>
                <div id="clFormError" class="text-danger small mt-2"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="clDeleteBtn" onclick="clDelete()" style="display:none">削除</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary btn-sm" id="clSaveBtn" onclick="clSave()">保存</button>
            </div>
        </div>
    </div>
</div>

<style>
.cl-table th { font-size: .78rem; color: #475569; font-weight: 600; white-space: nowrap; }
.cl-table td { font-size: .82rem; color: #1e293b; }
.cl-table tbody tr td { padding-top: .7rem; padding-bottom: .7rem; }
.cl-contract-link { font-size: .8rem; text-decoration: none; white-space: nowrap; }
.cl-contract-link:hover { text-decoration: underline; }
.cl-contract-none {
    display: inline-block; padding: .1rem .55rem; border-radius: .25rem;
    background: #f1f5f9; color: #94a3b8; font-size: .75rem;
}
.cl-drive-icon { width: 16px; height: 16px; vertical-align: -3px; margin-right: .3rem; }
/* 取引先・外注先の両方に登録がある会社の目印 */
.cl-also-badge {
    display: inline-block; margin-left: .4rem; padding: .05rem .4rem;
    border: 1px solid #c7d2fe; border-radius: .7rem; background: #eef2ff;
    color: #4338ca; font-size: .68rem; text-decoration: none; white-space: nowrap;
}
.cl-also-badge:hover { background: #e0e7ff; color: #3730a3; }
/* 年度バー。削除済み表示中は年度で絞っていないので薄く見せる */
.tr-fybar .tr-summary { white-space: nowrap; }
.tr-fybar-off { opacity: .45; }
/* 年度の切替（戦略会議の月送りと同じ見た目） */
.tr-fynav {
    display: inline-flex; align-items: center; gap: .15rem;
    border: 1px solid #e2e8f0; border-radius: 8px; padding: .1rem .2rem; background: #fff;
}
.tr-fynav-btn {
    border: none; background: transparent; color: #2563eb;
    font-size: .8rem; line-height: 1; padding: .3rem .45rem; border-radius: 5px;
}
.tr-fynav-btn:hover:not(:disabled) { background: #eff6ff; }
.tr-fynav-btn:disabled { color: #cbd5e1; cursor: default; }
.tr-fynav-label {
    font-size: .8rem; font-weight: 700; min-width: 170px; text-align: center; white-space: nowrap;
}
</style>

<?php
$clApi = json_encode(BASE_PATH . '/public/api/clients.php');
$clAllianceUrl = json_encode(BASE_PATH . '/public/alliances.php');
$clIsAdmin = isAdmin() ? 'true' : 'false';
$inlineJs = <<<CLJS
var CL_API  = {$clApi};
var CL_ALLIANCE_URL = {$clAllianceUrl};
var CL_CSRF = '{$csrf}';
var CL_CAN_EDIT = {$clIsAdmin};   // 編集・削除・復元は管理者のみ
CLJS;
$inlineJs .= <<<'CLJS2'

var clPage = 1, clQuery = '', clTimer = null, clModalBs = null, clShow = 'active';
// 表示中のタブ: 'client'=取引先（案件がある） / 'candidate'=パートナー候補（案件がまだ無い）
var clTab = (new URLSearchParams(location.search).get('tab') === 'candidate') ? 'candidate' : 'client';
var clFy = 0;       // 表示中の年度（0ならサーバーが決めた年度に従う）
var clFyList = [];  // 選べる年度（古い順）

// URLの ?fy= と ?q= を読む（外注先タブからの移動やタブ切替で引き継ぐ）
(function () {
    var p = new URLSearchParams(window.location.search);
    if (p.get('fy')) clFy = parseInt(p.get('fy'), 10) || 0;
    if (p.get('q'))  clQuery = p.get('q');
})();

// 外注先タブのバッジから来たときは、その会社で検索した状態で開く
(function () {
    var m = /[?&]q=([^&]*)/.exec(window.location.search);
    if (!m) return;
    clQuery = decodeURIComponent(m[1].replace(/\+/g, ' '));
})();

function clEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Googleドライブのアイコン（外部リソースに依存しないインラインSVG）
var CL_DRIVE_ICON =
    '<svg class="cl-drive-icon" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
    '<path fill="#0066da" d="M6.6 66.85l3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8H0c0 1.55.4 3.1 1.2 4.5z"/>' +
    '<path fill="#00ac47" d="M43.65 25L29.9 1.2c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44A9.06 9.06 0 000 53h27.5z"/>' +
    '<path fill="#ea4335" d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H59.798l5.852 11.5z"/>' +
    '<path fill="#00832d" d="M43.65 25L57.4 1.2C56.05.4 54.5 0 52.9 0H34.4c-1.6 0-3.15.45-4.5 1.2z"/>' +
    '<path fill="#2684fc" d="M59.8 53H27.5L13.75 76.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"/>' +
    '<path fill="#ffba00" d="M73.4 26.5l-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25L59.8 53h27.45c0-1.55-.4-3.1-1.2-4.5z"/>' +
    '</svg>';

var clRowMap = {};   // id → 取引先データ（編集フォームの復元用）

// 年度ボタンと「合計◯社」を描き直す
function clRenderFy(d) {
    clFy = d.fy;
    // ほかのタブへ移動しても同じ年度を見られるようにリンクを更新する
    var link = document.querySelector('.nav-tabs a[href*="alliances.php"]');
    if (link) link.href = link.href.split('?')[0] + '?fy=' + d.fy;
    document.querySelectorAll('.nav-tabs a[href*="clients.php"]').forEach(function (a) {
        var base = a.href.split('?')[0];
        var isCand = a.getAttribute('href').indexOf('tab=candidate') >= 0;
        a.href = base + '?fy=' + d.fy + (isCand ? '&tab=candidate' : '');
    });
    clFyList = (d.fy_options || []).map(function (o) { return o.fy; }).sort(function (a, b) { return a - b; });
    document.getElementById('clFyLabel').textContent = (d.fy - 1) + '年9月〜' + d.fy + '年8月';
    // 端まで来たら矢印を押せなくする
    var i = clFyList.indexOf(d.fy);
    var btns = document.querySelectorAll('#clFyNav .tr-fynav-btn');
    if (btns.length === 2) {
        btns[0].disabled = (i <= 0);
        btns[1].disabled = (i < 0 || i >= clFyList.length - 1);
    }

    // 「削除済み」表示中は年度で絞っていないので、年度バーを控えめにする
    var bar = document.querySelector('.tr-fybar');
    if (bar) bar.classList.toggle('tr-fybar-off', d.show === 'deleted');

    var sm = d.summary || {};
    document.getElementById('clSummary').innerHTML =
        '<span class="text-muted">取引先</span> <span class="fw-semibold">' + (sm.clients || 0) + '</span>社'
      + ' <span class="text-muted mx-1">/</span>'
      + ' <span class="text-muted">外注先</span> <span class="fw-semibold">' + (sm.alliances || 0) + '</span>社'
      + ' <span class="text-muted mx-1">→</span>'
      + ' <span class="fw-bold text-primary">合計 ' + (sm.total || 0) + '社</span>'
      + ' <span class="text-muted">（重複を除く）</span>';
}

// 前後の年度へ移動する。画面は再読み込みせず、一覧だけ差し替える
function clShiftFy(delta) {
    var i = clFyList.indexOf(clFy);
    if (i < 0) return;
    var next = clFyList[i + delta];
    if (next === undefined) return;
    clSetFy(next);
}

// 年度を切り替える。画面は再読み込みせず、一覧だけ差し替える
function clSetFy(fy) {
    if (clFy === fy) return;
    clFy = fy;
    clPage = 1;
    clLoad();
}

// パートナー候補タブ用: 商談報告のステータス
function clNegStatus(c) {
    if (!c.neg_status) return '<span class="text-muted" title="商談報告がまだありません">未登録</span>';
    var cls = c.neg_status === '取引開始' ? 'bg-success'
            : (c.neg_status === '取引候補' ? 'bg-primary' : 'bg-secondary');
    return '<span class="badge ' + cls + '">' + clEsc(c.neg_status) + '</span>';
}
// パートナー候補タブ用: 担当者（1社に何人でも）
function clNegReps(c) {
    if (!c.neg_reps || !c.neg_reps.length) return '<span class="text-muted">-</span>';
    return c.neg_reps.map(clEsc).join('、');
}

// 表の列をタブに合わせて出し分ける
function clSyncColumns() {
    var cand = (clTab === 'candidate');
    document.querySelectorAll('.cl-col-cand').forEach(function (el) { el.classList.toggle('d-none', !cand); });
    document.querySelectorAll('.cl-col-client').forEach(function (el) { el.classList.toggle('d-none', cand); });
}

function clRender(d) {
    clRenderFy(d);
    clSyncColumns();
    var tb = document.getElementById('clTbody');
    clRowMap = {};
    d.clients.forEach(function (c) { clRowMap[c.id] = c; });

    // 表示切替の状態を反映
    document.getElementById('clShowActive').className  = 'btn ' + (d.show === 'active'  ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('clShowDeleted').className = 'btn ' + (d.show === 'deleted' ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('clDeletedBadge').textContent = d.deleted_count;
    var bar = document.getElementById('clRestoreBar');
    if (d.show === 'deleted') { bar.classList.remove('d-none'); bar.classList.add('d-flex'); }
    else { bar.classList.add('d-none'); bar.classList.remove('d-flex'); }
    if (!d.clients.length) {
        tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">'
                     + (clQuery ? '該当する取引先が見つかりません' : '取引先が登録されていません') + '</td></tr>';
    } else {
        var html = '';
        d.clients.forEach(function (c) {
            var contract = c.has_contract
                ? '<a class="cl-contract-link" href="' + clEsc(c.contract_link) + '" target="_blank" rel="noopener noreferrer" title="'
                  + clEsc(c.contract_file_name || '契約書をGoogleドライブで開く') + '">' + CL_DRIVE_ICON + '契約書を開く</a>'
                : '<span class="cl-contract-none">無し</span>';
            // 外注先にも同じ会社が登録されている場合の目印。押すと外注先タブへ移動する
            var alsoBadge = c.also_alliance
                ? ' <a href="' + CL_ALLIANCE_URL + '?fy=' + clFy + '&q=' + encodeURIComponent(c.display_name || c.client_name)
                  + '" class="cl-also-badge" title="外注先タブでこの会社を開く">外注先にもあり</a>'
                : '';
            html += '<tr>'
                 +  '<td>' + clEsc(c.client_name) + alsoBadge + '</td>'
                 +  '<td>' + clEsc(c.display_name) + '</td>'
                 +  '<td>' + (c.contact_person ? clEsc(c.contact_person) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (c.email ? clEsc(c.email) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (c.phone ? clEsc(c.phone) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td class="cl-col-cand' + (clTab === 'candidate' ? '' : ' d-none') + '">' + clNegStatus(c) + '</td>'
                 +  '<td class="cl-col-cand' + (clTab === 'candidate' ? '' : ' d-none') + '">' + clNegReps(c) + '</td>'
                 +  '<td class="cl-col-client' + (clTab === 'candidate' ? ' d-none' : '') + '">' + contract + '</td>'
                 +  '<td class="text-end">'
                 +  (!CL_CAN_EDIT ? ''
                     : clShow === 'deleted'
                        ? '<button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 cl-restore-btn" data-id="' + c.id + '" style="font-size:.72rem">元に戻す</button>'
                        : '<button type="button" class="btn btn-link p-0 text-secondary cl-edit-btn" data-id="' + c.id + '" title="編集"><i class="bi bi-pencil"></i></button>')
                 +  '</td></tr>';
        });
        tb.innerHTML = html;
    }

    document.getElementById('clCount').textContent =
        d.total > 0 ? ('全' + d.total + '件中 ' + d.from + '〜' + d.to + '件を表示') : '全0件';

    var pager = document.getElementById('clPager');
    var p = '';
    p += '<li class="page-item' + (d.page <= 1 ? ' disabled' : '') + '">'
       + '<a class="page-link" href="#" onclick="clGoPage(' + (d.page - 1) + ');return false;">‹</a></li>';
    for (var i = 1; i <= d.total_page; i++) {
        p += '<li class="page-item' + (i === d.page ? ' active' : '') + '">'
           + '<a class="page-link" href="#" onclick="clGoPage(' + i + ');return false;">' + i + '</a></li>';
    }
    p += '<li class="page-item' + (d.page >= d.total_page ? ' disabled' : '') + '">'
       + '<a class="page-link" href="#" onclick="clGoPage(' + (d.page + 1) + ');return false;">›</a></li>';
    pager.innerHTML = p;
}

// 一覧の取得（画面全体はリロードしない）
function clLoad() {
    var url = CL_API + '?page=' + clPage + '&show=' + clShow + '&tab=' + clTab
            + (clFy ? '&fy=' + clFy : '')
            + (clQuery ? '&q=' + encodeURIComponent(clQuery) : '');
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error(d && d.error ? d.error : 'failed');
            clRender(d);
        })
        .catch(function () {
            document.getElementById('clTbody').innerHTML =
                '<tr><td colspan="9" class="text-center text-danger py-4">読み込みに失敗しました</td></tr>';
        });
}

function clGoPage(p) {
    if (p < 1) return;
    clPage = p;
    clLoad();
}

// 登録中 / 削除済み の切替
function clSetShow(s) {
    if (clShow === s) return;
    clShow = s;
    clPage = 1;
    clLoad();
}

// 削除済みの取引先を元に戻す
function clRestore(id) {
    var fd = new FormData();
    fd.append('action', 'restore');
    fd.append('csrf', CL_CSRF);
    fd.append('id', id || 0);
    fetch(CL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.error) || '復元に失敗しました'); return; }
            clLoad();
        })
        .catch(function () { alert('通信エラーが発生しました'); });
}

function clRestoreAll() {
    if (!confirm('削除済みの取引先をすべて元に戻しますか？')) return;
    clRestore(0);
}

// 行内ボタン（編集・元に戻す）のクリック
document.addEventListener('DOMContentLoaded', function () {
    var tb = document.getElementById('clTbody');
    if (!tb) return;
    tb.addEventListener('click', function (e) {
        var ed = e.target.closest('.cl-edit-btn');
        if (ed) { clOpenForm(clRowMap[ed.dataset.id]); return; }
        var rs = e.target.closest('.cl-restore-btn');
        if (rs) { clRestore(rs.dataset.id); }
    });
});

function clSearchInput() {
    clearTimeout(clTimer);
    clTimer = setTimeout(function () {
        clQuery = document.getElementById('clSearch').value.trim();
        clPage = 1;
        clLoad();
    }, 250);
}

function clOpenForm(c) {
    document.getElementById('clFormError').textContent = '';
    document.getElementById('clModalTitle').textContent = c ? '取引先を編集' : '取引先を追加';
    document.getElementById('clId').value           = c ? c.id : '';
    document.getElementById('clName').value         = c ? c.client_name : '';
    document.getElementById('clDisplay').value      = c ? c.display_name : '';
    document.getElementById('clPerson').value       = c ? c.contact_person : '';
    document.getElementById('clEmail').value        = c ? c.email : '';
    document.getElementById('clPhone').value        = c ? c.phone : '';
    document.getElementById('clContract').value     = c ? (c.contract_file_id || c.contract_url || '') : '';
    document.getElementById('clContractName').value = c ? (c.contract_file_name || '') : '';
    document.getElementById('clDeleteBtn').style.display = c ? '' : 'none';
    if (!clModalBs) clModalBs = new bootstrap.Modal(document.getElementById('clModal'));
    clModalBs.show();
}

function clPost(fd, onOk) {
    var btn = document.getElementById('clSaveBtn');
    btn.disabled = true;
    fetch(CL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { document.getElementById('clFormError').textContent = (d && d.error) || '保存に失敗しました'; return; }
            if (clModalBs) clModalBs.hide();
            onOk(d);
        })
        .catch(function () { document.getElementById('clFormError').textContent = '通信エラーが発生しました'; })
        .then(function () { btn.disabled = false; });
}

function clSave() {
    var id = document.getElementById('clId').value;
    var fd = new FormData();
    fd.append('action', id ? 'update' : 'create');
    fd.append('csrf', CL_CSRF);
    if (id) fd.append('id', id);
    fd.append('client_name',        document.getElementById('clName').value.trim());
    fd.append('display_name',       document.getElementById('clDisplay').value.trim());
    fd.append('contact_person',     document.getElementById('clPerson').value.trim());
    fd.append('email',              document.getElementById('clEmail').value.trim());
    fd.append('phone',              document.getElementById('clPhone').value.trim());
    fd.append('contract_input',     document.getElementById('clContract').value.trim());
    fd.append('contract_file_name', document.getElementById('clContractName').value.trim());
    document.getElementById('clFormError').textContent = '';
    clPost(fd, function () { clLoad(); });   // 一覧のみ差し替え（全画面リロードなし）
}

function clDelete(force) {
    var id = document.getElementById('clId').value;
    if (!id) return;
    if (!force && !confirm('この取引先を一覧から削除しますか？\n（過去の案件データはそのまま残ります）')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf', CL_CSRF);
    fd.append('id', id);
    if (force) fd.append('force', '1');

    document.getElementById('clSaveBtn').disabled = true;
    fetch(CL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            document.getElementById('clSaveBtn').disabled = false;
            // 案件で使われているときは、もう一度だけ確認してから削除する
            if (d && d.confirm) {
                if (confirm(d.error)) clDelete(true);
                return;
            }
            if (!d || !d.ok) {
                document.getElementById('clFormError').textContent = (d && d.error) || '削除に失敗しました';
                return;
            }
            if (clModalBs) clModalBs.hide();
            clLoad();
        })
        .catch(function () {
            document.getElementById('clSaveBtn').disabled = false;
            document.getElementById('clFormError').textContent = '通信エラーが発生しました';
        });
}

document.addEventListener('DOMContentLoaded', function () {
    if (clQuery) document.getElementById('clSearch').value = clQuery;
    clLoad();
});
CLJS2;
require_once __DIR__ . '/../includes/footer.php';
?>
