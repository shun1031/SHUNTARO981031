<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '原価管理';
$extraCss  = ['sales.css'];
$extraJs   = ['sales.js'];

$db = getDB();

// フィルター
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// ----------------------------------------------------------------
// メインクエリ: アライアンス別集計
// ----------------------------------------------------------------
$baseWhere = "sc.company_id = ? AND sc.worker_type = 'アライアンス' AND sc.alliance_id IS NOT NULL AND sc.status != 'cancelled'";
$baseParams = [$cid];
$baseWhere .= ' AND sc.case_year = ? AND sc.case_month = ?';
$baseParams[] = $year;
$baseParams[] = $month;

$listSql = "
SELECT
    a.id   AS alliance_id,
    a.alliance_name,
    SUM(CASE WHEN sc.case_type = 'regular' THEN 1 ELSE 0 END) AS regular_count,
    COALESCE(SUM(CASE WHEN sc.case_type = 'regular' THEN sc.cost ELSE 0 END), 0) AS regular_cost,
    SUM(CASE WHEN sc.case_type = 'event' THEN 1 ELSE 0 END)   AS event_count,
    COALESCE(SUM(CASE WHEN sc.case_type = 'event' THEN sc.cost ELSE 0 END), 0)   AS event_cost,
    COUNT(*) AS total_count,
    COALESCE(SUM(sc.cost), 0) AS total_cost
FROM sales_alliances a
INNER JOIN sales_cases sc ON sc.alliance_id = a.id AND $baseWhere
WHERE a.company_id = ?
GROUP BY a.id, a.alliance_name
ORDER BY total_cost DESC
";
$listStmt = $db->prepare($listSql);
$listStmt->execute(array_merge($baseParams, [$cid]));
$rows = $listStmt->fetchAll();

// KPI集計
$kpiTotalCost   = array_sum(array_column($rows, 'total_cost'));
$kpiRegularCost = array_sum(array_column($rows, 'regular_cost'));
$kpiEventCost   = array_sum(array_column($rows, 'event_cost'));
$kpiTotalCount  = array_sum(array_column($rows, 'total_count'));

// ----------------------------------------------------------------
// CSV出力
// ----------------------------------------------------------------
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cost_' . $year . sprintf('%02d', $month) . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM
    $cols = ['会社名','常勤件数','常勤原価','イベント件数','イベント原価','合計件数','合計原価'];
    echo implode(',', $cols) . "\n";
    foreach ($rows as $r) {
        echo implode(',', [
            '"' . str_replace('"','""',$r['alliance_name']) . '"',
            $r['regular_count'], $r['regular_cost'],
            $r['event_count'],   $r['event_cost'],
            $r['total_count'],   $r['total_cost'],
        ]) . "\n";
    }
    exit;
}

// ----------------------------------------------------------------
// テーブルHTML事前レンダリング
// ----------------------------------------------------------------
ob_start();
if (empty($rows)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">対象データがありません</td></tr>';
} else {
    foreach ($rows as $i => $r):
?>
<tr>
    <td class="text-muted" style="width:36px"><?= $i + 1 ?></td>
    <td>
        <a href="<?= BASE_PATH ?>/public/sales_cost_detail.php?alliance_id=<?= $r['alliance_id'] ?>&year=<?= $year ?>&month=<?= $month ?>"
           class="fw-medium text-decoration-none" style="color:#2563eb">
            <?= h($r['alliance_name']) ?>
        </a>
    </td>
    <td class="text-center"><?= $r['regular_count'] ?>件</td>
    <td class="text-end amount-positive"><?= number_format($r['regular_cost']) ?></td>
    <td class="text-center"><?= $r['event_count'] ?>件</td>
    <td class="text-end" style="color:#f59e0b"><?= number_format($r['event_cost']) ?></td>
    <td class="text-center fw-medium"><?= $r['total_count'] ?>件</td>
    <td class="text-end fw-bold" style="color:#059669"><?= number_format($r['total_cost']) ?></td>
</tr>
<?php endforeach; }
$tbodyHtml = ob_get_clean();

// ----------------------------------------------------------------
// AJAX応答
// ----------------------------------------------------------------
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'kpi' => [
            'total_cost'   => number_format($kpiTotalCost),
            'regular_cost' => number_format($kpiRegularCost),
            'event_cost'   => number_format($kpiEventCost),
            'total_count'  => $kpiTotalCount,
        ],
        'tbody' => $tbodyHtml,
        'total' => count($rows),
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-building me-2"></i>原価管理</h1>
                <p>アライアンス会社ごとの原価を常勤・イベント案件ごとに集計・確認できます。</p>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <!-- 年月フィルター -->
                <form id="costFilterForm" class="d-flex gap-2 align-items-center">
                    <select name="year" class="form-select form-select-sm" style="width:90px">
                        <?php for ($y = (int)date('Y') + 1; $y >= 2022; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>年</option>
                        <?php endfor; ?>
                    </select>
                    <select name="month" class="form-select form-select-sm" style="width:74px">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $m ?>月</option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                </form>
                <button class="btn btn-sm btn-outline-secondary" onclick="exportCsv()">
                    <i class="bi bi-download me-1"></i>CSV出力
                </button>
            </div>
        </div>
    </div>

    <!-- KPIサマリー -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #059669">
                <div class="kpi-label">合計原価</div>
                <div class="kpi-value" id="kpi_total_cost" style="color:#059669; font-size:1.6rem"><?= number_format($kpiTotalCost) ?></div>
                <div class="kpi-sub" id="kpi_total_count" style="color:#6b7280">合計 <?= $kpiTotalCount ?>件</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #3b82f6">
                <div class="kpi-label"><i class="bi bi-person-workspace me-1" style="color:#3b82f6"></i>常勤案件原価</div>
                <div class="kpi-value" id="kpi_regular_cost" style="color:#3b82f6; font-size:1.6rem"><?= number_format($kpiRegularCost) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #f59e0b">
                <div class="kpi-label"><i class="bi bi-calendar-event me-1" style="color:#f59e0b"></i>イベント案件原価</div>
                <div class="kpi-value" id="kpi_event_cost" style="color:#f59e0b; font-size:1.6rem"><?= number_format($kpiEventCost) ?></div>
            </div>
        </div>
    </div>

    <!-- 一覧 -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-2">
            <span class="fw-medium"><i class="bi bi-table me-1"></i>アライアンス別原価一覧</span>
            <small class="text-muted" id="listCount"><?= count($rows) ?>社</small>
        </div>
        <div class="table-responsive">
            <table class="table sales-table mb-0">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>会社名</th>
                        <th class="text-center">常勤件数</th>
                        <th class="text-end">常勤原価 (¥)</th>
                        <th class="text-center">イベント件数</th>
                        <th class="text-end">イベント原価 (¥)</th>
                        <th class="text-center">合計件数</th>
                        <th class="text-end">合計原価 (¥)</th>
                    </tr>
                </thead>
                <tbody id="costTableBody"><?= $tbodyHtml ?></tbody>
                <?php if (!empty($rows)): ?>
                <tfoot>
                    <tr class="fw-bold" style="background:#f0fdf4">
                        <td colspan="2" class="text-end">合計</td>
                        <td class="text-center"><?= array_sum(array_column($rows, 'regular_count')) ?>件</td>
                        <td class="text-end amount-positive" id="tfoot_regular"><?= number_format($kpiRegularCost) ?></td>
                        <td class="text-center"><?= array_sum(array_column($rows, 'event_count')) ?>件</td>
                        <td class="text-end" id="tfoot_event" style="color:#f59e0b"><?= number_format($kpiEventCost) ?></td>
                        <td class="text-center"><?= $kpiTotalCount ?>件</td>
                        <td class="text-end" id="tfoot_total" style="color:#059669"><?= number_format($kpiTotalCost) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php
$inlineJs = 'var costYear = ' . $year . '; var costMonth = ' . $month . ';';
$inlineJs .= <<<'JS'

// フィルター非同期更新
(function() {
    var form = document.getElementById('costFilterForm');
    if (!form) return;
    function fetchData() {
        var year  = form.querySelector('[name="year"]').value;
        var month = form.querySelector('[name="month"]').value;
        costYear  = parseInt(year);
        costMonth = parseInt(month);
        fetch(window.location.pathname + '?ajax=1&year=' + year + '&month=' + month)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var e = function(id) { return document.getElementById(id); };
                if (e('kpi_total_cost'))   e('kpi_total_cost').textContent   = d.kpi.total_cost;
                if (e('kpi_regular_cost')) e('kpi_regular_cost').textContent = d.kpi.regular_cost;
                if (e('kpi_event_cost'))   e('kpi_event_cost').textContent   = d.kpi.event_cost;
                if (e('kpi_total_count'))  e('kpi_total_count').textContent  = '合計 ' + d.kpi.total_count + '件';
                if (e('listCount'))        e('listCount').textContent        = d.total + '社';
                if (e('costTableBody'))    e('costTableBody').innerHTML      = d.tbody;
            });
    }
    form.addEventListener('submit', function(ev) { ev.preventDefault(); fetchData(); });
    form.querySelectorAll('select').forEach(function(sel) {
        sel.addEventListener('change', fetchData);
    });
})();

// CSV出力（ページリロードなし）
function exportCsv() {
    window.open(window.location.pathname + '?export=1&year=' + costYear + '&month=' + costMonth, '_blank');
}
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
