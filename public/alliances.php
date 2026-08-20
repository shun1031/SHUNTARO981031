<?php
/**
 * 取引先一覧 ―「外注先」タブ
 *
 * 取引先（clients.php）と対になる画面。外注先マスタの正式名称・表記名・連絡先と、
 * 「同じ会社の取引先」の紐づけをここで編集する。
 * 契約書の管理は取引先だけなので、この画面には無い。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
// 営業マン用画面: 管理者または営業担当のみ閲覧可（URL直打ちでも弾く）
requireSalesPageView();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '取引先一覧';
$extraCss  = ['sales.css'];

$csrf = getCsrfToken();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-people me-2"></i>取引先一覧</h1>
                <p>登録されている取引先・外注先の情報を一覧で確認できます。</p>
            </div>
            <?php if (isAdmin()): /* 外注先の追加は管理者のみ */ ?>
            <button type="button" class="btn btn-primary" onclick="alOpenForm()">
                <i class="bi bi-plus-lg me-1"></i>外注先を追加
            </button>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link" href="<?= BASE_PATH ?>/public/clients.php">
                <i class="bi bi-building me-1"></i>取引先
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="<?= BASE_PATH ?>/public/alliances.php">
                <i class="bi bi-people me-1"></i>外注先
            </a>
        </li>
    </ul>


    <div class="card mb-3 tr-fybar">
        <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">年度</span>
                <div class="btn-group btn-group-sm" role="group" id="alFyBtns"></div>
            </div>
            <div class="tr-summary small" id="alSummary"></div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <!-- 検索欄 -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="input-group input-group-sm" style="max-width:420px">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="alSearch" class="form-control border-start-0 ps-0"
                           placeholder="正式名称・表記名・担当者名で検索" oninput="alSearchInput()">
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-primary" id="alShowActive" onclick="alSetShow('active')">登録中</button>
                    <button type="button" class="btn btn-outline-secondary" id="alShowDeleted" onclick="alSetShow('deleted')">
                        削除済み <span class="badge bg-secondary ms-1" id="alDeletedBadge">0</span>
                    </button>
                </div>
            </div>

            <div class="alert alert-warning py-2 px-3 d-none align-items-center justify-content-between flex-wrap gap-2"
                 id="alRestoreBar" style="font-size:.82rem">
                <div>削除済みの外注先です。案件画面の外注先プルダウンには表示されません。</div>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-warning btn-sm" onclick="alRestoreAll()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>すべて元に戻す
                </button>
                <?php endif; ?>
            </div>

            <!-- 一覧表 -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 cl-table">
                    <thead class="table-light">
                        <tr>
                            <th>正式名称</th>
                            <th>表記名 <span class="text-muted fw-normal" style="font-size:.7rem">（アプリ内表示名）</span></th>
                            <th>種別</th>
                            <th>同じ会社の取引先</th>
                            <th>担当者名</th>
                            <th>メールアドレス</th>
                            <th>電話番号</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="alTbody">
                        <tr><td colspan="8" class="text-center text-muted py-4">
                            <span class="spinner-border spinner-border-sm me-2"></span>読み込み中...
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <div class="text-muted small" id="alCount"></div>
                <nav><ul class="pagination pagination-sm mb-0" id="alPager"></ul></nav>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2" role="alert" style="font-size:.82rem">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            外注先は「人を出してもらう先（お金を払う先）」です。<br>
            同じ会社が取引先にも登録されている場合は「同じ会社の取引先」を選んでください。
            戦略会議の会社数で1社としてまとめて数えられます。
        </div>
    </div>
</div>

<!-- 追加・編集モーダル -->
<div class="modal fade" id="alModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="alModalTitle">外注先を追加</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="alId">
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">正式名称 <span class="text-danger">*</span></label>
                    <input type="text" id="alName" class="form-control form-control-sm" placeholder="株式会社エックス通信">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">表記名（アプリ内表示名） <span class="text-danger">*</span></label>
                    <input type="text" id="alDisplay" class="form-control form-control-sm" placeholder="エックス通信">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">種別</label>
                    <select id="alType" class="form-select form-select-sm"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">同じ会社の取引先</label>
                    <select id="alClient" class="form-select form-select-sm"></select>
                    <div class="form-text" style="font-size:.72rem">
                        取引先一覧にも同じ会社がある場合に選びます。戦略会議の会社数で1社として数えるために使います。
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">担当者名</label>
                    <input type="text" id="alPerson" class="form-control form-control-sm" placeholder="山田 太郎">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">メールアドレス</label>
                    <input type="email" id="alEmail" class="form-control form-control-sm" placeholder="yamada.taro@example.co.jp">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-medium mb-1">電話番号</label>
                    <input type="text" id="alPhone" class="form-control form-control-sm" placeholder="03-1234-5678">
                </div>
                <div id="alFormError" class="text-danger small mt-2" style="white-space:pre-line"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="alDeleteBtn" onclick="alDelete()" style="display:none">削除</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary btn-sm" id="alSaveBtn" onclick="alSave()">保存</button>
            </div>
        </div>
    </div>
</div>

<style>
.cl-table th { font-size: .78rem; color: #475569; font-weight: 600; white-space: nowrap; }
.cl-table td { font-size: .82rem; color: #1e293b; }
.cl-table tbody tr td { padding-top: .7rem; padding-bottom: .7rem; }
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
</style>

<?php
$alApi       = json_encode(BASE_PATH . '/public/api/alliances.php');
$alClientUrl = json_encode(BASE_PATH . '/public/clients.php');
$alIsAdmin = isAdmin() ? 'true' : 'false';
$inlineJs = <<<ALJS
var AL_API  = {$alApi};
var AL_CLIENT_URL = {$alClientUrl};
var AL_CSRF = '{$csrf}';
var AL_CAN_EDIT = {$alIsAdmin};   // 編集・削除・復元は管理者のみ
ALJS;
$inlineJs .= <<<'ALJS2'

var alPage = 1, alQuery = '', alTimer = null, alModalBs = null, alShow = 'active';
var alFy = 0;   // 表示中の年度（0ならサーバーが決めた年度に従う）

// URLの ?fy= と ?q= を読む（取引先タブからの移動やタブ切替で引き継ぐ）
(function () {
    var p = new URLSearchParams(window.location.search);
    if (p.get('fy')) alFy = parseInt(p.get('fy'), 10) || 0;
    if (p.get('q'))  alQuery = p.get('q');
})();

// 取引先タブのバッジから来たときは、その会社で検索した状態で開く
(function () {
    var m = /[?&]q=([^&]*)/.exec(window.location.search);
    if (!m) return;
    alQuery = decodeURIComponent(m[1].replace(/\+/g, ' '));
})();
var alRowMap = {}, alClientOptions = [], alTypes = [];

function alEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// 年度ボタンと「合計◯社」を描き直す
function alRenderFy(d) {
    alFy = d.fy;
    // もう片方のタブへ移動しても同じ年度を見られるようにリンクを更新する
    var link = document.querySelector('.nav-tabs a[href*="clients.php"]');
    if (link) link.href = link.href.split('?')[0] + '?fy=' + d.fy;
    var box = document.getElementById('alFyBtns');
    box.innerHTML = (d.fy_options || []).map(function (o) {
        return '<button type="button" class="btn ' + (o.fy === d.fy ? 'btn-primary' : 'btn-outline-secondary')
             + '" onclick="alSetFy(' + o.fy + ')">' + o.label + '</button>';
    }).join('');

    // 「削除済み」表示中は年度で絞っていないので、年度バーを控えめにする
    var bar = document.querySelector('.tr-fybar');
    if (bar) bar.classList.toggle('tr-fybar-off', d.show === 'deleted');

    var sm = d.summary || {};
    document.getElementById('alSummary').innerHTML =
        '<span class="text-muted">取引先</span> <span class="fw-semibold">' + (sm.clients || 0) + '</span>社'
      + ' <span class="text-muted mx-1">/</span>'
      + ' <span class="text-muted">外注先</span> <span class="fw-semibold">' + (sm.alliances || 0) + '</span>社'
      + ' <span class="text-muted mx-1">→</span>'
      + ' <span class="fw-bold text-primary">合計 ' + (sm.total || 0) + '社</span>'
      + ' <span class="text-muted">（重複を除く）</span>';
}

// 年度を切り替える。画面は再読み込みせず、一覧だけ差し替える
function alSetFy(fy) {
    if (alFy === fy) return;
    alFy = fy;
    alPage = 1;
    alLoad();
}

function alRender(d) {
    alRenderFy(d);
    var tb = document.getElementById('alTbody');
    alRowMap = {};
    d.alliances.forEach(function (a) { alRowMap[a.id] = a; });
    alClientOptions = d.client_options || [];
    alTypes = d.types || [];

    document.getElementById('alShowActive').className  = 'btn ' + (d.show === 'active'  ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('alShowDeleted').className = 'btn ' + (d.show === 'deleted' ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('alDeletedBadge').textContent = d.deleted_count;
    var bar = document.getElementById('alRestoreBar');
    if (d.show === 'deleted') { bar.classList.remove('d-none'); bar.classList.add('d-flex'); }
    else { bar.classList.add('d-none'); bar.classList.remove('d-flex'); }

    if (!d.alliances.length) {
        tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">'
                     + (alQuery ? '該当する外注先が見つかりません' : '外注先が登録されていません') + '</td></tr>';
    } else {
        var html = '';
        d.alliances.forEach(function (a) {
            // 取引先にも同じ会社が登録されている場合の目印。押すと取引先タブへ移動する
            var alsoBadge = a.also_client
                ? ' <a href="' + AL_CLIENT_URL + '?fy=' + alFy + '&q=' + encodeURIComponent(a.display_name || a.alliance_name)
                  + '" class="cl-also-badge" title="取引先タブでこの会社を開く">取引先にもあり</a>'
                : '';
            html += '<tr>'
                 +  '<td>' + alEsc(a.alliance_name) + alsoBadge + '</td>'
                 +  '<td>' + alEsc(a.display_name) + '</td>'
                 +  '<td><span class="badge bg-light text-dark border">' + alEsc(a.alliance_type) + '</span></td>'
                 +  '<td>' + (a.client_label
                        ? '<i class="bi bi-link-45deg text-primary"></i> ' + alEsc(a.client_label)
                        : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (a.contact_person ? alEsc(a.contact_person) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (a.email ? alEsc(a.email) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (a.phone ? alEsc(a.phone) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td class="text-end">'
                 +  (!AL_CAN_EDIT ? ''
                     : alShow === 'deleted'
                        ? '<button type="button" class="btn btn-outline-warning btn-sm py-0 px-2 al-restore-btn" data-id="' + a.id + '" style="font-size:.72rem">元に戻す</button>'
                        : '<button type="button" class="btn btn-link p-0 text-secondary al-edit-btn" data-id="' + a.id + '" title="編集"><i class="bi bi-pencil"></i></button>')
                 +  '</td></tr>';
        });
        tb.innerHTML = html;
    }

    document.getElementById('alCount').textContent =
        d.total > 0 ? ('全' + d.total + '件中 ' + d.from + '〜' + d.to + '件を表示') : '全0件';

    var pager = document.getElementById('alPager');
    var p = '';
    p += '<li class="page-item' + (d.page <= 1 ? ' disabled' : '') + '">'
       + '<a class="page-link" href="#" onclick="alGoPage(' + (d.page - 1) + ');return false;">‹</a></li>';
    for (var i = 1; i <= d.total_page; i++) {
        p += '<li class="page-item' + (i === d.page ? ' active' : '') + '">'
           + '<a class="page-link" href="#" onclick="alGoPage(' + i + ');return false;">' + i + '</a></li>';
    }
    p += '<li class="page-item' + (d.page >= d.total_page ? ' disabled' : '') + '">'
       + '<a class="page-link" href="#" onclick="alGoPage(' + (d.page + 1) + ');return false;">›</a></li>';
    pager.innerHTML = p;
}

function alLoad() {
    var url = AL_API + '?page=' + alPage + '&show=' + alShow
            + (alFy ? '&fy=' + alFy : '')
            + (alQuery ? '&q=' + encodeURIComponent(alQuery) : '');
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error(d && d.error ? d.error : 'failed');
            alRender(d);
        })
        .catch(function () {
            document.getElementById('alTbody').innerHTML =
                '<tr><td colspan="8" class="text-center text-danger py-4">読み込みに失敗しました</td></tr>';
        });
}

function alGoPage(p) { if (p < 1) return; alPage = p; alLoad(); }

function alSetShow(s) { if (alShow === s) return; alShow = s; alPage = 1; alLoad(); }

function alRestore(id) {
    var fd = new FormData();
    fd.append('action', 'restore');
    fd.append('csrf', AL_CSRF);
    fd.append('id', id || 0);
    fetch(AL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.error) || '復元に失敗しました'); return; }
            alLoad();
        })
        .catch(function () { alert('通信エラーが発生しました'); });
}

function alRestoreAll() {
    if (!confirm('削除済みの外注先をすべて元に戻しますか？')) return;
    alRestore(0);
}

document.addEventListener('DOMContentLoaded', function () {
    var tb = document.getElementById('alTbody');
    if (!tb) return;
    tb.addEventListener('click', function (e) {
        var ed = e.target.closest('.al-edit-btn');
        if (ed) { alOpenForm(alRowMap[ed.dataset.id]); return; }
        var rs = e.target.closest('.al-restore-btn');
        if (rs) { alRestore(rs.dataset.id); }
    });
});

function alSearchInput() {
    clearTimeout(alTimer);
    alTimer = setTimeout(function () {
        alQuery = document.getElementById('alSearch').value.trim();
        alPage = 1;
        alLoad();
    }, 250);
}

function alOpenForm(a) {
    document.getElementById('alFormError').textContent = '';
    document.getElementById('alModalTitle').textContent = a ? '外注先を編集' : '外注先を追加';

    // 種別・同じ会社の取引先の選択肢を組み立てる
    var typeSel = document.getElementById('alType');
    typeSel.innerHTML = alTypes.map(function (t) {
        return '<option value="' + alEsc(t) + '">' + alEsc(t) + '</option>';
    }).join('');
    var clSel = document.getElementById('alClient');
    clSel.innerHTML = '<option value="">-- 紐づけなし --</option>'
        + alClientOptions.map(function (c) {
            return '<option value="' + c.id + '">' + alEsc(c.label) + '</option>';
        }).join('');

    document.getElementById('alId').value      = a ? a.id : '';
    document.getElementById('alName').value    = a ? a.alliance_name : '';
    document.getElementById('alDisplay').value = a ? a.display_name : '';
    typeSel.value                              = a ? a.alliance_type : (alTypes[0] || 'アライアンス');
    clSel.value                                = a && a.client_id ? a.client_id : '';
    document.getElementById('alPerson').value  = a ? a.contact_person : '';
    document.getElementById('alEmail').value   = a ? a.email : '';
    document.getElementById('alPhone').value   = a ? a.phone : '';
    document.getElementById('alDeleteBtn').style.display = a ? '' : 'none';
    if (!alModalBs) alModalBs = new bootstrap.Modal(document.getElementById('alModal'));
    alModalBs.show();
}

function alPost(fd, onOk) {
    var btn = document.getElementById('alSaveBtn');
    btn.disabled = true;
    fetch(AL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { document.getElementById('alFormError').textContent = (d && d.error) || '保存に失敗しました'; return; }
            if (alModalBs) alModalBs.hide();
            onOk(d);
        })
        .catch(function () { document.getElementById('alFormError').textContent = '通信エラーが発生しました'; })
        .then(function () { btn.disabled = false; });
}

function alSave() {
    var id = document.getElementById('alId').value;
    var fd = new FormData();
    fd.append('action', id ? 'update' : 'create');
    fd.append('csrf', AL_CSRF);
    if (id) fd.append('id', id);
    fd.append('alliance_name',  document.getElementById('alName').value.trim());
    fd.append('display_name',   document.getElementById('alDisplay').value.trim());
    fd.append('alliance_type',  document.getElementById('alType').value);
    fd.append('client_id',      document.getElementById('alClient').value);
    fd.append('contact_person', document.getElementById('alPerson').value.trim());
    fd.append('email',          document.getElementById('alEmail').value.trim());
    fd.append('phone',          document.getElementById('alPhone').value.trim());
    document.getElementById('alFormError').textContent = '';
    alPost(fd, function () { alLoad(); });
}

function alDelete() {
    var id = document.getElementById('alId').value;
    if (!id) return;
    if (!confirm('この外注先を一覧から削除しますか？\n（過去の案件データはそのまま残ります）')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf', AL_CSRF);
    fd.append('id', id);
    alPost(fd, function () { alLoad(); });
}

document.addEventListener('DOMContentLoaded', function () {
    if (alQuery) document.getElementById('alSearch').value = alQuery;
    alLoad();
});
ALJS2;
require_once __DIR__ . '/../includes/footer.php';
?>
