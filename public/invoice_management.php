<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$db   = getDB();
$user = getCurrentUser();
$userName = $user['display_name'] ?? '不明';

// テーブル初期化（初回アクセス時のみ実行）
$db->exec("CREATE TABLE IF NOT EXISTS invoice_checks (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    bms_company_id  INT NOT NULL,
    company_type    VARCHAR(30) NOT NULL,
    ref_id          INT NOT NULL DEFAULT 0,
    ref_name        VARCHAR(100) NOT NULL DEFAULT '',
    check_year      SMALLINT NOT NULL,
    check_month     TINYINT NOT NULL,
    check_create    TINYINT(1) NOT NULL DEFAULT 0,
    check_staff1    TINYINT(1) NOT NULL DEFAULT 0,
    check_staff2    TINYINT(1) NOT NULL DEFAULT 0,
    final_check     TINYINT(1) NOT NULL DEFAULT 0,
    updated_by      VARCHAR(100) DEFAULT NULL,
    updated_at      DATETIME DEFAULT NULL,
    UNIQUE KEY uq_ic (bms_company_id, company_type, ref_id, ref_name, check_year, check_month),
    INDEX idx_ic_company (bms_company_id, company_type, check_year, check_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ----------------------------------------------------------------
// AJAX: チェック保存
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_check') {
    header('Content-Type: application/json');

    $allowedTypes  = ['regular_client','event_client','alliance','outsource','inhouse_outsource'];
    $allowedFields = ['check_create','check_staff1','check_staff2','final_check'];

    $companyType = $_POST['company_type'] ?? '';
    $refId       = (int)($_POST['ref_id'] ?? 0);
    $refName     = trim($_POST['ref_name'] ?? '');
    $checkYear   = (int)($_POST['check_year']  ?? date('Y'));
    $checkMonth  = (int)($_POST['check_month'] ?? date('n'));
    $field       = $_POST['field'] ?? '';
    $value       = (int)(bool)($_POST['value'] ?? 0);

    if (!in_array($companyType, $allowedTypes, true) || !in_array($field, $allowedFields, true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid params']); exit;
    }

    $now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $sql = "INSERT INTO invoice_checks
        (bms_company_id, company_type, ref_id, ref_name, check_year, check_month, `{$field}`, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `{$field}` = VALUES(`{$field}`),
            updated_by  = VALUES(updated_by),
            updated_at  = VALUES(updated_at)";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$cid, $companyType, $refId, $refName, $checkYear, $checkMonth, $value, $userName, $now]);

        // 全チェックが外れた場合は更新情報をクリア（誰もチェックしていない状態に戻す）
        $chkStmt = $db->prepare("SELECT (check_create + check_staff1 + check_staff2 + final_check) AS total
            FROM invoice_checks
            WHERE bms_company_id=? AND company_type=? AND ref_id=? AND ref_name=? AND check_year=? AND check_month=?");
        $chkStmt->execute([$cid, $companyType, $refId, $refName, $checkYear, $checkMonth]);
        $total = (int)$chkStmt->fetchColumn();

        if ($total === 0) {
            $db->prepare("UPDATE invoice_checks SET updated_by=NULL, updated_at=NULL
                WHERE bms_company_id=? AND company_type=? AND ref_id=? AND ref_name=? AND check_year=? AND check_month=?")
               ->execute([$cid, $companyType, $refId, $refName, $checkYear, $checkMonth]);
            echo json_encode(['ok' => true, 'updated_by' => '', 'updated_at' => '']);
        } else {
            echo json_encode(['ok' => true, 'updated_by' => $userName, 'updated_at' => $now]);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------
// パラメータ
// ----------------------------------------------------------------
$pageTitle = '請求書管理';
$extraCss  = ['sales.css'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$tabs = [
    'regular_client'    => ['label' => '常勤クライアント',    'icon' => 'bi-person-workspace'],
    'event_client'      => ['label' => 'イベントクライアント', 'icon' => 'bi-calendar-event'],
    'alliance'          => ['label' => 'アライアンス',        'icon' => 'bi-people'],
    'outsource'         => ['label' => '外注',               'icon' => 'bi-person-badge'],
    'inhouse_outsource' => ['label' => '自社外注',            'icon' => 'bi-building-gear'],
];

$activeTab = $_GET['tab'] ?? 'regular_client';
if (!array_key_exists($activeTab, $tabs)) $activeTab = 'regular_client';

// ----------------------------------------------------------------
// 会社リスト取得（各タブの会社名はsales_casesから自動取得）
// ----------------------------------------------------------------
$companies = [];

switch ($activeTab) {
    case 'regular_client':
        $stmt = $db->prepare("
            SELECT DISTINCT cl.id AS ref_id, cl.client_name AS company_name
            FROM sales_clients cl
            INNER JOIN sales_cases sc ON sc.client_id = cl.id
            WHERE sc.company_id = ? AND sc.case_type = 'regular'
              AND sc.status != 'cancelled' AND sc.case_year = ? AND sc.case_month = ?
            ORDER BY cl.client_name
        ");
        $stmt->execute([$cid, $year, $month]);
        $companies = $stmt->fetchAll();
        break;

    case 'event_client':
        $stmt = $db->prepare("
            SELECT DISTINCT cl.id AS ref_id, cl.client_name AS company_name
            FROM sales_clients cl
            INNER JOIN sales_cases sc ON sc.client_id = cl.id
            WHERE sc.company_id = ? AND sc.case_type = 'event'
              AND sc.status != 'cancelled' AND sc.case_year = ? AND sc.case_month = ?
            ORDER BY cl.client_name
        ");
        $stmt->execute([$cid, $year, $month]);
        $companies = $stmt->fetchAll();
        break;

    case 'alliance':
        $stmt = $db->prepare("
            SELECT DISTINCT a.id AS ref_id, a.alliance_name AS company_name
            FROM sales_alliances a
            INNER JOIN sales_cases sc ON sc.alliance_id = a.id
            WHERE sc.company_id = ? AND sc.worker_type = 'アライアンス'
              AND sc.status != 'cancelled' AND sc.case_year = ? AND sc.case_month = ?
            ORDER BY a.alliance_name
        ");
        $stmt->execute([$cid, $year, $month]);
        $companies = $stmt->fetchAll();
        break;

    case 'outsource':
        $stmt = $db->prepare("
            SELECT DISTINCT worker_name AS company_name
            FROM sales_cases
            WHERE company_id = ? AND worker_type = '個人外注'
              AND status != 'cancelled' AND case_year = ? AND case_month = ?
              AND worker_name IS NOT NULL AND worker_name != ''
            ORDER BY worker_name
        ");
        $stmt->execute([$cid, $year, $month]);
        foreach ($stmt->fetchAll() as $r) {
            $companies[] = ['ref_id' => 0, 'company_name' => $r['company_name']];
        }
        break;

    case 'inhouse_outsource':
        $stmt = $db->prepare("
            SELECT DISTINCT worker_name AS company_name
            FROM sales_cases
            WHERE company_id = ? AND worker_type = '自社外注'
              AND status != 'cancelled' AND case_year = ? AND case_month = ?
              AND worker_name IS NOT NULL AND worker_name != ''
            ORDER BY worker_name
        ");
        $stmt->execute([$cid, $year, $month]);
        foreach ($stmt->fetchAll() as $r) {
            $companies[] = ['ref_id' => 0, 'company_name' => $r['company_name']];
        }
        break;
}

// ----------------------------------------------------------------
// チェックデータ取得
// ----------------------------------------------------------------
$checkStmt = $db->prepare("
    SELECT ref_id, ref_name, check_create, check_staff1, check_staff2, final_check, updated_by, updated_at
    FROM invoice_checks
    WHERE bms_company_id = ? AND company_type = ? AND check_year = ? AND check_month = ?
");
$checkStmt->execute([$cid, $activeTab, $year, $month]);
$checkMap = [];
foreach ($checkStmt->fetchAll() as $r) {
    $key = $r['ref_id'] > 0 ? 'id:' . $r['ref_id'] : 'name:' . $r['ref_name'];
    $checkMap[$key] = $r;
}

function icGetCheck(array $map, int $refId, string $name): ?array {
    return $refId > 0 ? ($map['id:' . $refId] ?? null) : ($map['name:' . $name] ?? null);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">

    <!-- ページヘッダー -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-receipt me-2"></i>請求書管理</h1>
                <p>取引先・アライアンス・外注ごとの請求書チェック状況を管理します。</p>
            </div>
            <!-- 年月フィルター -->
            <form method="get" id="ymForm" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <select name="year" class="form-select form-select-sm" style="width:90px" onchange="document.getElementById('ymForm').submit()">
                    <?php for ($y = (int)date('Y') + 1; $y >= 2022; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select name="month" class="form-select form-select-sm" style="width:74px" onchange="document.getElementById('ymForm').submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- タブ -->
    <ul class="nav nav-tabs mb-3">
        <?php foreach ($tabs as $tabKey => $info): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tabKey === $activeTab ? 'active' : '' ?>"
               href="?tab=<?= $tabKey ?>&year=<?= $year ?>&month=<?= $month ?>">
                <i class="bi <?= $info['icon'] ?> me-1"></i><?= $info['label'] ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- サマリーKPI -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="sales-kpi py-2" style="border-left:4px solid #059669">
                <div class="kpi-label" style="font-size:.75rem">チェック済み</div>
                <div class="kpi-value" id="sum_complete" style="color:#059669;font-size:1.5rem">0</div>
                <div class="kpi-sub text-muted" style="font-size:.7rem">件</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi py-2" style="border-left:4px solid #ef4444">
                <div class="kpi-label" style="font-size:.75rem">未チェック</div>
                <div class="kpi-value" id="sum_incomplete" style="color:#ef4444;font-size:1.5rem">0</div>
                <div class="kpi-sub text-muted" style="font-size:.7rem">件</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi py-2" style="border-left:4px solid #6b7280">
                <div class="kpi-label" style="font-size:.75rem">全体</div>
                <div class="kpi-value" id="sum_total" style="color:#6b7280;font-size:1.5rem">0</div>
                <div class="kpi-sub text-muted" style="font-size:.7rem">件</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="sales-kpi py-2" style="border-left:4px solid #3b82f6">
                <div class="kpi-label" style="font-size:.75rem">完了率</div>
                <div class="kpi-value" id="sum_rate" style="color:#3b82f6;font-size:1.5rem">0.0</div>
                <div class="kpi-sub text-muted" style="font-size:.7rem">%</div>
            </div>
        </div>
    </div>

    <!-- フィルターボタン -->
    <div class="d-flex justify-content-end mb-2">
        <button type="button" id="filterBtn" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-funnel me-1"></i>未完了のみ表示: OFF
        </button>
    </div>

    <!-- 一覧テーブル -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-medium">
                <i class="bi <?= $tabs[$activeTab]['icon'] ?> me-1"></i>
                <?= $tabs[$activeTab]['label'] ?>一覧
                <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月</small>
            </span>
            <small class="text-muted" id="listCount"><?= count($companies) ?>社</small>
        </div>
        <div class="table-responsive">
            <table class="table sales-table mb-0" id="checkTable">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>会社名</th>
                        <th class="text-center" style="min-width:62px">作成</th>
                        <th class="text-center" style="min-width:80px">
                            チェック<br><small class="fw-normal text-muted">（竹内）</small>
                        </th>
                        <th class="text-center" style="min-width:80px">
                            チェック<br><small class="fw-normal text-muted">（近藤）</small>
                        </th>
                        <th class="text-center" style="min-width:72px">
                            最終<br><small class="fw-normal text-muted">チェック</small>
                        </th>
                        <th style="min-width:130px;font-size:.75rem" class="text-muted">更新情報</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($companies)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-inbox d-block mb-2" style="font-size:2rem"></i>
                        <?= $year ?>年<?= $month ?>月の<?= $tabs[$activeTab]['label'] ?>データがありません
                    </td>
                </tr>
                <?php else: foreach ($companies as $i => $co):
                    $refId   = (int)$co['ref_id'];
                    $refName = $refId > 0 ? '' : $co['company_name'];
                    $chk = icGetCheck($checkMap, $refId, $co['company_name']);
                    $cc  = (int)($chk['check_create'] ?? 0);
                    $cs1 = (int)($chk['check_staff1'] ?? 0);
                    $cs2 = (int)($chk['check_staff2'] ?? 0);
                    $cf  = (int)($chk['final_check']  ?? 0);
                    $updBy = h($chk['updated_by'] ?? '');
                    $updAt = mb_substr($chk['updated_at'] ?? '', 0, 16);
                    $complete = $cc && $cs1 && $cs2 && $cf;
                ?>
                <tr data-ref-id="<?= $refId ?>" data-ref-name="<?= h($refName) ?>"
                    style="<?= $complete ? 'background:#f0fdf4' : '' ?>">
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td class="fw-medium"><?= h($co['company_name']) ?></td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input invoice-check"
                               data-field="check_create" <?= $cc ? 'checked' : '' ?>>
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input invoice-check"
                               data-field="check_staff1" <?= $cs1 ? 'checked' : '' ?>>
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input invoice-check"
                               data-field="check_staff2" <?= $cs2 ? 'checked' : '' ?>>
                    </td>
                    <td class="text-center">
                        <input type="checkbox" class="form-check-input invoice-check"
                               data-field="final_check" <?= $cf ? 'checked' : '' ?>
                               style="accent-color:#059669">
                    </td>
                    <td class="update-info" style="font-size:.7rem;line-height:1.4;color:#6b7280">
                        <?php if ($updBy): ?>
                        <?= $updBy ?><br><?= h($updAt) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php
$inlineJs  = 'var invYear=' . $year . ';var invMonth=' . $month . ';var invTab=' . json_encode($activeTab) . ';';
$inlineJs .= <<<'JS'

var filterActive = false;

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.invoice-check').forEach(function(cb) {
        cb.addEventListener('change', function() { saveCheck(this); });
    });

    var filterBtn = document.getElementById('filterBtn');
    if (filterBtn) {
        filterBtn.addEventListener('click', function() {
            filterActive = !filterActive;
            this.className = 'btn btn-sm ' + (filterActive ? 'btn-warning' : 'btn-outline-secondary');
            this.innerHTML = '<i class="bi bi-funnel me-1"></i>未完了のみ表示: ' + (filterActive ? 'ON' : 'OFF');
            applyFilter();
        });
    }

    updateSummary();
});

function saveCheck(cb) {
    var row    = cb.closest('tr');
    var refId  = row.dataset.refId  || '0';
    var refName= row.dataset.refName|| '';
    var field  = cb.dataset.field;
    var value  = cb.checked ? 1 : 0;
    var td     = cb.closest('td');

    td.style.opacity = '0.4';

    var body = new URLSearchParams({
        action:       'save_check',
        company_type: invTab,
        ref_id:       refId,
        ref_name:     refName,
        check_year:   invYear,
        check_month:  invMonth,
        field:        field,
        value:        value
    });

    fetch(window.location.pathname, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            td.style.opacity = '1';
            if (d.ok) {
                var updCell = row.querySelector('.update-info');
                if (updCell) {
                    if (d.updated_by) {
                        updCell.innerHTML = escHtml(d.updated_by) + '<br>' + escHtml(d.updated_at.substr(0, 16));
                    } else {
                        updCell.innerHTML = '';
                    }
                }
                var done = rowComplete(row);
                row.style.background = done ? '#f0fdf4' : '';
                updateSummary();
                if (filterActive) applyFilter();
            } else {
                cb.checked = !cb.checked;
                alert('保存に失敗しました。再度お試しください。');
            }
        })
        .catch(function() {
            td.style.opacity = '1';
            cb.checked = !cb.checked;
            alert('通信エラーが発生しました。');
        });
}

function rowComplete(row) {
    var cbs = row.querySelectorAll('.invoice-check');
    return cbs.length > 0 && Array.from(cbs).every(function(c) { return c.checked; });
}

function updateSummary() {
    var rows     = Array.from(document.querySelectorAll('#checkTable tbody tr[data-ref-id]'));
    var total    = rows.length;
    var complete = rows.filter(rowComplete).length;
    var rate     = total > 0 ? (complete / total * 100).toFixed(1) : '0.0';
    var e = function(id) { return document.getElementById(id); };
    if (e('sum_complete'))   e('sum_complete').textContent   = complete;
    if (e('sum_incomplete')) e('sum_incomplete').textContent = total - complete;
    if (e('sum_total'))      e('sum_total').textContent      = total;
    if (e('sum_rate'))       e('sum_rate').textContent       = rate;
}

function applyFilter() {
    document.querySelectorAll('#checkTable tbody tr[data-ref-id]').forEach(function(row) {
        row.style.display = (filterActive && rowComplete(row)) ? 'none' : '';
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
