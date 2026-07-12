<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '原価管理 詳細';
$extraCss  = ['sales.css'];
$extraJs   = ['sales.js'];

$db = getDB();

// パラメータ
$allianceId = (int)($_GET['alliance_id'] ?? 0);
$year       = (int)($_GET['year']  ?? date('Y'));
$month      = (int)($_GET['month'] ?? date('n'));

if (!$allianceId) { redirect(BASE_PATH . '/public/sales_cost.php'); }

// アライアンス情報
$alStmt = $db->prepare('SELECT * FROM sales_alliances WHERE id = ? AND company_id = ?');
$alStmt->execute([$allianceId, $cid]);
$alliance = $alStmt->fetch();
if (!$alliance) { redirect(BASE_PATH . '/public/sales_cost.php'); }

// 共通WHERE
$baseWhere = "sc.company_id = ? AND sc.alliance_id = ? AND sc.worker_type = 'アライアンス' AND sc.status != 'cancelled' AND sc.case_year = ? AND sc.case_month = ?";
$baseParams = [$cid, $allianceId, $year, $month];

// 常勤案件
$regStmt = $db->prepare("
    SELECT sc.*, cl.client_name
    FROM sales_cases sc
    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE $baseWhere AND sc.case_type = 'regular'
    ORDER BY sc.start_date DESC, sc.id DESC
");
$regStmt->execute($baseParams);
$regularCases = $regStmt->fetchAll();

// イベント案件
$evStmt = $db->prepare("
    SELECT sc.*, cl.client_name
    FROM sales_cases sc
    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE $baseWhere AND sc.case_type = 'event'
    ORDER BY sc.start_date DESC, sc.id DESC
");
$evStmt->execute($baseParams);
$eventCases = $evStmt->fetchAll();

// KPI
$kpiRegular = array_sum(array_column($regularCases, 'cost'));
$kpiEvent   = array_sum(array_column($eventCases, 'cost'));
$kpiTotal   = $kpiRegular + $kpiEvent;

// ----------------------------------------------------------------
// AJAX応答
// ----------------------------------------------------------------
if (!empty($_GET['ajax'])) {
    // 常勤テーブルHTML
    ob_start();
    if (empty($regularCases)) {
        echo '<tr><td colspan="5" class="text-center text-muted py-3">常勤案件なし</td></tr>';
    } else {
        foreach ($regularCases as $c) {
            $period = '';
            if ($c['start_date']) $period = date('Y/m', strtotime($c['start_date']));
            if ($c['end_date'])   $period .= ' ～ ' . date('Y/m', strtotime($c['end_date']));
            echo '<tr>';
            echo '<td class="fw-medium">' . h($c['worker_name'] ?: '-') . '</td>';
            echo '<td class="small">' . h($c['client_name'] ?? '') . '</td>';
            echo '<td class="small">' . h($c['store_name'] ?? '') . '</td>';
            echo '<td class="small text-muted">' . h($period) . '</td>';
            echo '<td class="text-end amount-positive">' . number_format($c['cost']) . '</td>';
            echo '</tr>';
        }
    }
    $regTbody = ob_get_clean();

    // イベントテーブルHTML
    ob_start();
    if (empty($eventCases)) {
        echo '<tr><td colspan="5" class="text-center text-muted py-3">イベント案件なし</td></tr>';
    } else {
        foreach ($eventCases as $c) {
            $date = $c['start_date'] ? date('Y/m/d', strtotime($c['start_date'])) : '-';
            echo '<tr>';
            echo '<td class="fw-medium">' . h($c['worker_name'] ?: '-') . '</td>';
            echo '<td class="small">' . h($c['client_name'] ?? '') . '</td>';
            echo '<td class="small">' . h($c['store_name'] ?? '') . '</td>';
            echo '<td class="small text-muted">' . h($date) . '</td>';
            echo '<td class="text-end" style="color:#f59e0b">' . number_format($c['cost']) . '</td>';
            echo '</tr>';
        }
    }
    $evTbody = ob_get_clean();

    header('Content-Type: application/json');
    echo json_encode([
        'kpi'       => [
            'total'   => number_format($kpiTotal),
            'regular' => number_format($kpiRegular),
            'event'   => number_format($kpiEvent),
        ],
        'reg_tbody' => $regTbody,
        'ev_tbody'  => $evTbody,
        'reg_count' => count($regularCases),
        'ev_count'  => count($eventCases),
        'reg_total' => number_format($kpiRegular),
        'ev_total'  => number_format($kpiEvent),
    ]);
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- ヘッダー -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb" style="font-size:.8rem">
                    <ol class="breadcrumb mb-1">
                        <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/public/sales_cost.php?year=<?= $year ?>&month=<?= $month ?>">原価管理</a></li>
                        <li class="breadcrumb-item active"><?= h($alliance['alliance_name']) ?></li>
                    </ol>
                </nav>
                <h1><i class="bi bi-building me-2"></i><?= h($alliance['alliance_name']) ?></h1>
                <p><?= $year ?>年<?= $month ?>月 / 原価詳細</p>
            </div>
            <!-- 年月フィルター -->
            <form id="detailFilterForm" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="alliance_id" value="<?= $allianceId ?>">
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
        </div>
    </div>

    <!-- KPI -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #059669">
                <div class="kpi-label">合計原価</div>
                <div class="kpi-value" id="kpi_total" style="color:#059669; font-size:1.6rem"><?= number_format($kpiTotal) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #3b82f6">
                <div class="kpi-label"><i class="bi bi-person-workspace me-1" style="color:#3b82f6"></i>常勤原価</div>
                <div class="kpi-value" id="kpi_regular" style="color:#3b82f6; font-size:1.6rem"><?= number_format($kpiRegular) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="sales-kpi" style="border-left:4px solid #f59e0b">
                <div class="kpi-label"><i class="bi bi-calendar-event me-1" style="color:#f59e0b"></i>イベント原価</div>
                <div class="kpi-value" id="kpi_event" style="color:#f59e0b; font-size:1.6rem"><?= number_format($kpiEvent) ?></div>
            </div>
        </div>
    </div>

    <!-- 常勤案件一覧 -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between py-2" style="background:#eff6ff">
            <span class="fw-medium" style="color:#1d4ed8"><i class="bi bi-person-workspace me-1"></i>常勤案件</span>
            <small class="text-muted" id="reg_count_badge"><?= count($regularCases) ?>件</small>
        </div>
        <div class="table-responsive">
            <table class="table sales-table mb-0">
                <thead>
                    <tr>
                        <th>スタッフ名</th>
                        <th>取引先</th>
                        <th>店舗</th>
                        <th>期間</th>
                        <th class="text-end">原価 (¥)</th>
                    </tr>
                </thead>
                <tbody id="reg_tbody">
                    <?php if (empty($regularCases)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">常勤案件なし</td></tr>
                    <?php else: foreach ($regularCases as $c):
                        $period = '';
                        if ($c['start_date']) $period = date('Y/m', strtotime($c['start_date']));
                        if ($c['end_date'])   $period .= ' ～ ' . date('Y/m', strtotime($c['end_date']));
                    ?>
                    <tr>
                        <td class="fw-medium"><?= h($c['worker_name'] ?: '-') ?></td>
                        <td class="small"><?= h($c['client_name'] ?? '') ?></td>
                        <td class="small"><?= h($c['store_name'] ?? '') ?></td>
                        <td class="small text-muted"><?= h($period) ?></td>
                        <td class="text-end amount-positive"><?= number_format($c['cost']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($regularCases)): ?>
                <tfoot>
                    <tr class="fw-bold" style="background:#eff6ff">
                        <td colspan="4" class="text-end">合計</td>
                        <td class="text-end amount-positive" id="reg_tfoot"><?= number_format($kpiRegular) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- イベント案件一覧 -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between py-2" style="background:#fffbeb">
            <span class="fw-medium" style="color:#92400e"><i class="bi bi-calendar-event me-1"></i>イベント案件</span>
            <small class="text-muted" id="ev_count_badge"><?= count($eventCases) ?>件</small>
        </div>
        <div class="table-responsive">
            <table class="table sales-table mb-0">
                <thead>
                    <tr>
                        <th>スタッフ名</th>
                        <th>取引先</th>
                        <th>店舗</th>
                        <th>開催日</th>
                        <th class="text-end">原価 (¥)</th>
                    </tr>
                </thead>
                <tbody id="ev_tbody">
                    <?php if (empty($eventCases)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">イベント案件なし</td></tr>
                    <?php else: foreach ($eventCases as $c):
                        $date = $c['start_date'] ? date('Y/m/d', strtotime($c['start_date'])) : '-';
                    ?>
                    <tr>
                        <td class="fw-medium"><?= h($c['worker_name'] ?: '-') ?></td>
                        <td class="small"><?= h($c['client_name'] ?? '') ?></td>
                        <td class="small"><?= h($c['store_name'] ?? '') ?></td>
                        <td class="small text-muted"><?= h($date) ?></td>
                        <td class="text-end" style="color:#f59e0b"><?= number_format($c['cost']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($eventCases)): ?>
                <tfoot>
                    <tr class="fw-bold" style="background:#fffbeb">
                        <td colspan="4" class="text-end">合計</td>
                        <td class="text-end" id="ev_tfoot" style="color:#f59e0b"><?= number_format($kpiEvent) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="text-center mb-4">
        <a href="<?= BASE_PATH ?>/public/sales_cost.php?year=<?= $year ?>&month=<?= $month ?>"
           class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>原価管理一覧へ戻る</a>
    </div>
</div>

<?php
$inlineJs = 'var detailAllianceId = ' . $allianceId . ';';
$inlineJs .= <<<'JS'

// フィルター非同期更新
(function() {
    var form = document.getElementById('detailFilterForm');
    if (!form) return;
    function fetchDetail() {
        var year  = form.querySelector('[name="year"]').value;
        var month = form.querySelector('[name="month"]').value;
        var url = window.location.pathname + '?ajax=1&alliance_id=' + detailAllianceId + '&year=' + year + '&month=' + month;
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var e = function(id) { return document.getElementById(id); };
                if (e('kpi_total'))   e('kpi_total').textContent   = d.kpi.total;
                if (e('kpi_regular')) e('kpi_regular').textContent = d.kpi.regular;
                if (e('kpi_event'))   e('kpi_event').textContent   = d.kpi.event;
                if (e('reg_tbody'))      e('reg_tbody').innerHTML      = d.reg_tbody;
                if (e('ev_tbody'))       e('ev_tbody').innerHTML       = d.ev_tbody;
                if (e('reg_count_badge')) e('reg_count_badge').textContent = d.reg_count + '件';
                if (e('ev_count_badge'))  e('ev_count_badge').textContent  = d.ev_count  + '件';
                if (e('reg_tfoot'))      e('reg_tfoot').textContent      = d.reg_total;
                if (e('ev_tfoot'))       e('ev_tfoot').textContent       = d.ev_total;
            });
    }
    form.addEventListener('submit', function(ev) { ev.preventDefault(); fetchDetail(); });
    form.querySelectorAll('select').forEach(function(sel) {
        sel.addEventListener('change', fetchDetail);
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
