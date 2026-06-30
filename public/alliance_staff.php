<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid || !isAdmin()) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = 'アライアンス人員管理';
$extraCss  = ['sales.css'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-people me-2"></i>アライアンス人員管理</h1>
                <p class="text-muted mb-0" style="font-size:.85rem">アライアンスとして登録されたスタッフの管理を行います。最終稼働日・最終稼働店舗はリアルタイムで更新されます。</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="asLoad()">
                <i class="bi bi-arrow-clockwise me-1"></i>最新の情報に更新
            </button>
        </div>
    </div>

    <!-- 検索バー -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label small mb-1 fw-medium">会社名で絞り込む</label>
                    <input type="text" id="qCompany" class="form-control form-control-sm" placeholder="会社名を入力" oninput="asSearch()">
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label small mb-1 fw-medium">スタッフ名で検索</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="qStaff" class="form-control" placeholder="スタッフ名を入力" oninput="asSearch()">
                        <button class="btn btn-outline-secondary" onclick="asSearch()"><i class="bi bi-search"></i></button>
                    </div>
                </div>
                <div class="col-md-4 col-sm-12 mt-auto">
                    <div id="asSummary" class="text-muted small"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 会社・スタッフカード一覧 -->
    <div id="asContent">
        <div class="text-center py-5 text-muted">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            読み込み中...
        </div>
    </div>
</div>

<!-- 凡例パネル（PC: 右下固定 / スマホ: 下部バー） -->
<div class="card as-legend-fixed" style="position:fixed;bottom:1.5rem;right:1.5rem;width:200px;z-index:100;box-shadow:0 4px 16px rgba(0,0,0,.12)">
    <div class="card-body p-3">
        <div class="fw-semibold mb-2" style="font-size:.8rem;color:#1e40af">ステータスの色について</div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <div style="width:14px;height:14px;border-radius:50%;background:#3b82f6;flex-shrink:0"></div>
            <span style="font-size:.75rem">常勤で稼働中</span>
        </div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <div style="width:14px;height:14px;border-radius:50%;background:#22c55e;flex-shrink:0"></div>
            <span style="font-size:.75rem">イベントで稼働中</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div style="width:14px;height:14px;border-radius:50%;background:#d1d5db;flex-shrink:0"></div>
            <span style="font-size:.75rem">稼働していない</span>
        </div>
    </div>
</div>

<style>
/* ─── スタッフカード ─────────────────────────── */
.as-company-block { margin-bottom: 1.75rem; }
.as-company-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .6rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: .5rem .5rem 0 0;
    font-weight: 700;
    font-size: .95rem;
    color: #1e293b;
}
.as-staff-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 .5rem .5rem;
    background: #fff;
}
.as-staff-card {
    width: 190px;
    flex-shrink: 0;
    padding: 1rem;
    border-radius: .5rem;
    border: 1px solid #e5e7eb;
    background: #fff;
    transition: box-shadow .15s;
    cursor: default;
}
.as-staff-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }

/* ステータス別背景色 */
.as-staff-card.status-regular {
    background: #dbeafe;
    border-color: #93c5fd;
}
.as-staff-card.status-event {
    background: #dcfce7;
    border-color: #86efac;
}
.as-staff-card.status-none {
    background: #fff;
    border-color: #e5e7eb;
}

/* バッジ */
.as-status-badge {
    display: inline-block;
    padding: .15em .6em;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 600;
    margin-bottom: .5rem;
}
.badge-regular { background: #2563eb; color: #fff; }
.badge-event   { background: #16a34a; color: #fff; }
.badge-none    { background: #9ca3af; color: #fff; }

.as-staff-name {
    font-weight: 700;
    font-size: .9rem;
    color: #1e293b;
    margin-bottom: .5rem;
}
.as-staff-info {
    font-size: .75rem;
    color: #475569;
    line-height: 1.6;
}
.as-staff-info .label { color: #94a3b8; font-size: .68rem; display: block; }
.as-staff-info .value { font-weight: 500; color: #1e293b; }

/* レスポンシブ */
@media (max-width: 575px) {
    .as-staff-card { width: calc(50% - .5rem); }
}
</style>

<script>
(function () {
    var searchTimer = null;
    var apiBase = <?= json_encode(BASE_PATH . '/public/api/alliance_staff.php') ?>;

    function asLoad(qCompany, qStaff) {
        var url = apiBase;
        var params = [];
        if (qCompany) params.push('q_company=' + encodeURIComponent(qCompany));
        if (qStaff)   params.push('q_staff='   + encodeURIComponent(qStaff));
        if (params.length) url += '?' + params.join('&');

        document.getElementById('asContent').innerHTML =
            '<div class="text-center py-5 text-muted">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>読み込み中...</div>';

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) { asRender(data); })
            .catch(function () {
                document.getElementById('asContent').innerHTML =
                    '<div class="alert alert-danger">データの取得に失敗しました。ページを再読み込みしてください。</div>';
            });
    }

    function asRender(data) {
        var companies = data.companies || [];
        var summary = data.total_companies + ' 社 / ' + data.total_staff + ' 名のスタッフを表示中';
        document.getElementById('asSummary').textContent = summary;

        if (companies.length === 0) {
            document.getElementById('asContent').innerHTML =
                '<div class="card"><div class="card-body text-center text-muted py-5">' +
                '<i class="bi bi-people fs-2 d-block mb-2"></i>' +
                'アライアンス登録スタッフが見つかりません。<br>' +
                '<small>イベント案件・常勤案件でスタッフ区分「アライアンス」を選択すると表示されます。</small>' +
                '</div></div>';
            return;
        }

        var html = '';
        companies.forEach(function (company) {
            html += '<div class="as-company-block">';
            html += '<div class="as-company-header">';
            html += '<span><i class="bi bi-building me-2 text-primary"></i>会社名：' + escHtml(company.name) + '</span>';
            html += '<span class="badge bg-secondary">' + company.staff.length + ' 名</span>';
            html += '</div>';
            html += '<div class="as-staff-grid">';

            company.staff.forEach(function (s) {
                var statusClass = 'status-' + (s.status || 'none');
                var badgeClass  = s.status === 'regular' ? 'badge-regular' :
                                  s.status === 'event'   ? 'badge-event'   : 'badge-none';
                var badgeLabel  = s.status === 'regular' ? '常勤で稼働中' :
                                  s.status === 'event'   ? 'イベントで稼働中' : '稼働していない';

                var lastDate  = s.last_work_date ? formatDate(s.last_work_date) : '—';
                var lastStore = s.last_store || '—';

                html += '<div class="as-staff-card ' + statusClass + '">';
                html += '<div><span class="as-status-badge ' + badgeClass + '">' + badgeLabel + '</span></div>';
                html += '<div class="as-staff-name">' + escHtml(s.name) + '</div>';
                html += '<div class="as-staff-info">';
                html += '<span class="label">最終稼働店舗</span>';
                html += '<span class="value">' + escHtml(lastStore) + '</span>';
                html += '<span class="label mt-1">最終稼働日</span>';
                html += '<span class="value">' + escHtml(lastDate) + '</span>';
                html += '</div>';
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';
        });

        document.getElementById('asContent').innerHTML = html;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.getFullYear() + '/' +
               String(d.getMonth() + 1).padStart(2, '0') + '/' +
               String(d.getDate()).padStart(2, '0');
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // グローバルに公開
    window.asLoad   = function () { asLoad(
        document.getElementById('qCompany').value.trim(),
        document.getElementById('qStaff').value.trim()
    ); };
    window.asSearch = function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(window.asLoad, 300);
    };

    // 初回ロード
    asLoad('', '');
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
