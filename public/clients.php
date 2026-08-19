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

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link active" href="<?= BASE_PATH ?>/public/clients.php">
                <i class="bi bi-building me-1"></i>取引先
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="<?= BASE_PATH ?>/public/alliances.php">
                <i class="bi bi-people me-1"></i>外注先
            </a>
        </li>
    </ul>

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
                            <th style="width:150px">契約書格納</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody id="clTbody">
                        <tr><td colspan="7" class="text-center text-muted py-4">
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
</style>

<?php
$clApi = json_encode(BASE_PATH . '/public/api/clients.php');
$clIsAdmin = isAdmin() ? 'true' : 'false';
$inlineJs = <<<CLJS
var CL_API  = {$clApi};
var CL_CSRF = '{$csrf}';
var CL_CAN_EDIT = {$clIsAdmin};   // 編集・削除・復元は管理者のみ
CLJS;
$inlineJs .= <<<'CLJS2'

var clPage = 1, clQuery = '', clTimer = null, clModalBs = null, clShow = 'active';

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

function clRender(d) {
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
        tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">'
                     + (clQuery ? '該当する取引先が見つかりません' : '取引先が登録されていません') + '</td></tr>';
    } else {
        var html = '';
        d.clients.forEach(function (c) {
            var contract = c.has_contract
                ? '<a class="cl-contract-link" href="' + clEsc(c.contract_link) + '" target="_blank" rel="noopener noreferrer" title="'
                  + clEsc(c.contract_file_name || '契約書をGoogleドライブで開く') + '">' + CL_DRIVE_ICON + '契約書を開く</a>'
                : '<span class="cl-contract-none">無し</span>';
            html += '<tr>'
                 +  '<td>' + clEsc(c.client_name) + '</td>'
                 +  '<td>' + clEsc(c.display_name) + '</td>'
                 +  '<td>' + (c.contact_person ? clEsc(c.contact_person) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (c.email ? clEsc(c.email) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + (c.phone ? clEsc(c.phone) : '<span class="text-muted">-</span>') + '</td>'
                 +  '<td>' + contract + '</td>'
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
    var url = CL_API + '?page=' + clPage + '&show=' + clShow
            + (clQuery ? '&q=' + encodeURIComponent(clQuery) : '');
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error(d && d.error ? d.error : 'failed');
            clRender(d);
        })
        .catch(function () {
            document.getElementById('clTbody').innerHTML =
                '<tr><td colspan="7" class="text-center text-danger py-4">読み込みに失敗しました</td></tr>';
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

function clDelete() {
    var id = document.getElementById('clId').value;
    if (!id) return;
    if (!confirm('この取引先を一覧から削除しますか？\n（過去の案件データはそのまま残ります）')) return;
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf', CL_CSRF);
    fd.append('id', id);
    clPost(fd, function () { clLoad(); });
}

document.addEventListener('DOMContentLoaded', clLoad);
CLJS2;
require_once __DIR__ . '/../includes/footer.php';
?>
