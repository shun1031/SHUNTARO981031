<?php
require_once __DIR__ . '/../config/config.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

if (!isset($caseTypeFilter)) $caseTypeFilter = '';
$_dashTitles = ['' => '総合ダッシュボード', 'regular' => '常勤ダッシュボード', 'event' => 'イベントダッシュボード'];
$pageTitle = $_dashTitles[$caseTypeFilter] ?? '総合ダッシュボード';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year = (int)($_GET['year'] ?? date('Y'));
$empFilter = getEmployeeNameFilter();
$salesRep = $empFilter ?? ($_GET['sales_rep'] ?? '');

// 月が未指定の場合、データがある最新月にフォールバック
if (isset($_GET['month'])) {
    $month = (int)$_GET['month'];
} else {
    $month = (int)date('n');
    $_latestSql = "SELECT MAX(case_month) FROM sales_cases WHERE company_id = ? AND case_year = ? AND status = 'confirmed'" . ($caseTypeFilter ? " AND case_type = ?" : "");
    $latestStmt = getDB()->prepare($_latestSql);
    $latestStmt->execute($caseTypeFilter ? [$cid, $year, $caseTypeFilter] : [$cid, $year]);
    $latestMonth = $latestStmt->fetchColumn();
    if ($latestMonth && (int)$latestMonth < $month) {
        $month = (int)$latestMonth;
    }
}

$salesReps = getSalesReps($cid, $year);

// AJAX: 月別売上目標の保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fy_target') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'csrf']); exit; }
    $ty = (int)($_POST['t_year'] ?? 0);
    $tm = (int)($_POST['t_month'] ?? 0);
    $tv = (int)str_replace([',', '¥', ' ', '　'], '', $_POST['t_value'] ?? '0');
    if ($ty && $tm) { upsertSalesTarget($cid, $ty, $tm, 'total', max(0, $tv)); }
    echo json_encode(['ok' => true]);
    exit;
}

// ── 年度データ（9月始まり）: year Y = Sep(Y-1)〜Aug(Y) ──
$fyMonths = [
    ['y' => $year-1, 'm' => 9],  ['y' => $year-1, 'm' => 10],
    ['y' => $year-1, 'm' => 11], ['y' => $year-1, 'm' => 12],
    ['y' => $year,   'm' => 1],  ['y' => $year,   'm' => 2],
    ['y' => $year,   'm' => 3],  ['y' => $year,   'm' => 4],
    ['y' => $year,   'm' => 5],  ['y' => $year,   'm' => 6],
    ['y' => $year,   'm' => 7],  ['y' => $year,   'm' => 8],
];
// 月別売上・粗利
$fyDb = getDB();
$_fyTypeWhere = $caseTypeFilter ? "AND case_type = ?" : "";
$fyCasesStmt = $fyDb->prepare("
    SELECT case_year, case_month, COALESCE(SUM(revenue),0) AS rev, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status != '終了'
      $_fyTypeWhere
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
    GROUP BY case_year, case_month
");
$_fyParams = $caseTypeFilter ? [$cid, $caseTypeFilter, $year-1, $year] : [$cid, $year-1, $year];
$fyCasesStmt->execute($_fyParams);
$fyRevMap = [];
foreach ($fyCasesStmt->fetchAll() as $r) {
    $fyRevMap[$r['case_year']][$r['case_month']] = ['rev' => (int)$r['rev'], 'profit' => (int)$r['profit']];
}
// 月別売上・粗利（常勤/イベント別）
$fyTypeStmt = $fyDb->prepare("
    SELECT case_year, case_month, case_type,
           COALESCE(SUM(revenue),0) AS rev, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status != '終了'
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
    GROUP BY case_year, case_month, case_type
");
$fyTypeStmt->execute([$cid, $year-1, $year]);
$fyTypeRevMap = [];
foreach ($fyTypeStmt->fetchAll() as $r) {
    $fyTypeRevMap[$r['case_year']][$r['case_month']][$r['case_type']] = [
        'rev' => (int)$r['rev'], 'profit' => (int)$r['profit'],
    ];
}
// 月別目標
$fyTgtMap = [];
foreach (getSalesTargets($cid, $year-1) as $m => $types) {
    $fyTgtMap[$year-1][$m] = (int)($types['total']['revenue_target'] ?? 0);
}
foreach (getSalesTargets($cid, $year) as $m => $types) {
    $fyTgtMap[$year][$m] = (int)($types['total']['revenue_target'] ?? 0);
}
// 年度合計
$fyTotalRev = 0; $fyTotalProfit = 0; $fyTotalTarget = 0;
foreach ($fyMonths as $fm) {
    $fyTotalRev    += $fyRevMap[$fm['y']][$fm['m']]['rev']    ?? 0;
    $fyTotalProfit += $fyRevMap[$fm['y']][$fm['m']]['profit'] ?? 0;
    $fyTotalTarget += $fyTgtMap[$fm['y']][$fm['m']]          ?? 0;
}
$fyMargin = $fyTotalRev > 0 ? round($fyTotalProfit / $fyTotalRev * 100, 1) : 0;
$fyAch    = $fyTotalTarget > 0 ? round($fyTotalRev / $fyTotalTarget * 100, 1) : 0;
$fyAchColor = $fyAch >= 100 ? '#059669' : ($fyAch >= 80 ? '#3b82f6' : ($fyAch >= 50 ? '#f59e0b' : '#ef4444'));

$kpis = getSalesDashboardKPIsFiltered($cid, $year, $month, $salesRep, $caseTypeFilter);
$trend = getSalesRevenueTrendFiltered($cid, $year, $salesRep);
$clientTop = getSalesRevenueByClientFiltered($cid, $year, $month, $salesRep);
$workerBreakdown = getSalesWorkerBreakdownFiltered($cid, $year, $month, $salesRep, $caseTypeFilter);

// スタッフ区分別売上を「自社」「アライアンス」の2グループに集約
$inhouseTypes  = ['正社員', 'アルバイト', '自社外注'];
$allianceTypes = ['アライアンス', '個人外注'];
$workerGrouped = [
    '自社'       => ['revenue' => 0, 'profit' => 0, 'case_count' => 0],
    'アライアンス' => ['revenue' => 0, 'profit' => 0, 'case_count' => 0],
];
foreach ($workerBreakdown as $wb) {
    if (in_array($wb['worker_type'], $inhouseTypes)) {
        $workerGrouped['自社']['revenue']    += (int)$wb['revenue'];
        $workerGrouped['自社']['profit']     += (int)$wb['profit'];
        $workerGrouped['自社']['case_count'] += (int)$wb['case_count'];
    } elseif (in_array($wb['worker_type'], $allianceTypes)) {
        $workerGrouped['アライアンス']['revenue']    += (int)$wb['revenue'];
        $workerGrouped['アライアンス']['profit']     += (int)$wb['profit'];
        $workerGrouped['アライアンス']['case_count'] += (int)$wb['case_count'];
    }
}
// 案件ベース比率・粗利率（常勤/イベントダッシュボード用）
$wTotal        = $workerGrouped['自社']['case_count'] + $workerGrouped['アライアンス']['case_count'];
$wInhouseRate  = $wTotal > 0 ? round($workerGrouped['自社']['case_count']       / $wTotal * 100, 1) : 0;
$wAlliRate     = $wTotal > 0 ? round($workerGrouped['アライアンス']['case_count'] / $wTotal * 100, 1) : 0;
$wInhouseMargin = $workerGrouped['自社']['revenue'] > 0
    ? round($workerGrouped['自社']['profit'] / $workerGrouped['自社']['revenue'] * 100, 1) : 0;
$wAlliMargin    = $workerGrouped['アライアンス']['revenue'] > 0
    ? round($workerGrouped['アライアンス']['profit'] / $workerGrouped['アライアンス']['revenue'] * 100, 1) : 0;

// スタッフ人数分析（今月・前年同月）
function calcStaffStats(array $breakdown): array {
    $total = 0; $outsource = 0; $regular = 0; $event = 0; $inhouse = 0;
    foreach ($breakdown as $wb) {
        $cnt = (int)$wb['case_count'];
        $total += $cnt;
        if (in_array($wb['worker_type'], ['アライアンス','個人外注','自社外注'])) $outsource += $cnt;
        else $inhouse += $cnt;
    }
    // 常勤/イベント比率はworker_typeではなくcase_typeから取得できないためkpisから
    return [
        'total'       => $total,
        'outsource'   => $outsource,
        'inhouse'     => $inhouse,
        'outsource_rate' => $total > 0 ? round($outsource / $total * 100, 1) : 0,
        'inhouse_rate'   => $total > 0 ? round($inhouse  / $total * 100, 1) : 0,
    ];
}
$staffCur  = calcStaffStats($workerBreakdown);
$staffCur['regular']      = $kpis['regular_count'];
$staffCur['event']        = $kpis['event_count'];
$staffCur['regular_rate'] = $staffCur['total'] > 0 ? round($kpis['regular_count'] / $staffCur['total'] * 100, 1) : 0;
$staffCur['event_rate']   = $staffCur['total'] > 0 ? round($kpis['event_count']   / $staffCur['total'] * 100, 1) : 0;

$workerBreakdownYoy = getSalesWorkerBreakdownFiltered($cid, $year - 1, $month, $salesRep, $caseTypeFilter);
$kpisYoy   = getSalesDashboardKPIsFiltered($cid, $year - 1, $month, $salesRep, $caseTypeFilter);
$staffYoy  = calcStaffStats($workerBreakdownYoy);
$staffYoy['regular']      = $kpisYoy['regular_count'];
$staffYoy['event']        = $kpisYoy['event_count'];
$staffYoy['regular_rate'] = $staffYoy['total'] > 0 ? round($kpisYoy['regular_count'] / $staffYoy['total'] * 100, 1) : 0;
$staffYoy['event_rate']   = $staffYoy['total'] > 0 ? round($kpisYoy['event_count']   / $staffYoy['total'] * 100, 1) : 0;

// 社員テーブルからスタッフ人数を取得
$db = getDB();
$empStatsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(employment_type = '自社') AS inhouse,
        SUM(employment_type = 'アライアンス') AS alliance,
        SUM(work_style = '常勤') AS regular,
        SUM(work_style = 'イベント') AS event
    FROM employees
    WHERE company_id = ?
      AND is_active = 1
      AND (retirement_date IS NULL OR retirement_date >= ?)
";
// 今月末時点で在籍
$curLastDay = date('Y-m-t', mktime(0,0,0,$month,1,$year));
$empStatsStmt = $db->prepare($empStatsSql);
$empStatsStmt->execute([$cid, $curLastDay]);
$empStats = $empStatsStmt->fetch(PDO::FETCH_ASSOC);

// 前年同月末時点で在籍
$yoyLastDay = date('Y-m-t', mktime(0,0,0,$month,1,$year-1));
$empStatsYoyStmt = $db->prepare($empStatsSql);
$empStatsYoyStmt->execute([$cid, $yoyLastDay]);
$empStatsYoy = $empStatsYoyStmt->fetch(PDO::FETCH_ASSOC);

function buildEmpStats(array $row): array {
    $t = (int)($row['total'] ?? 0);
    $i = (int)($row['inhouse'] ?? 0);
    $a = (int)($row['alliance'] ?? 0);
    $r = (int)($row['regular'] ?? 0);
    $e = (int)($row['event'] ?? 0);
    return [
        'total'         => $t,
        'inhouse'       => $i,
        'alliance'      => $a,
        'regular'       => $r,
        'event'         => $e,
        'inhouse_rate'  => $t > 0 ? round($i / $t * 100, 1) : 0,
        'alliance_rate' => $t > 0 ? round($a / $t * 100, 1) : 0,
        'regular_rate'  => $t > 0 ? round($r / $t * 100, 1) : 0,
        'event_rate'    => $t > 0 ? round($e / $t * 100, 1) : 0,
    ];
}
$empStats    = buildEmpStats($empStats);
$empStatsYoy = buildEmpStats($empStatsYoy);

$fmtYoy = function($cur, $prev, $unit = '') {
    if ($prev <= 0) return '<span class="text-muted small">前年データなし</span>';
    $diff = $cur - $prev;
    $pct  = round($diff / $prev * 100, 1);
    $cls  = $diff >= 0 ? 'text-success' : 'text-danger';
    $icon = $diff >= 0 ? 'arrow-up' : 'arrow-down';
    return '<span class="'.$cls.' small"><i class="bi bi-'.$icon.'"></i> '.($pct >= 0 ? '+' : '').$pct.'% (前年'.$prev.$unit.')</span>';
};

// 達成率バークラス
$achRate = $kpis['achievement_rate'];
$barClass = $achRate >= 100 ? 'over' : ($achRate >= 80 ? 'good' : ($achRate >= 50 ? 'low' : 'danger'));

// 集計カード用データ（年度）
$_sDb = getDB();
$_ctf  = $caseTypeFilter ? " AND sc.case_type = ?" : "";
$_ctf2 = $caseTypeFilter ? " AND case_type = ?"    : "";
$_ctp  = $caseTypeFilter ? [$caseTypeFilter] : [];
// クライアント別売上（年度）
$_clientFySql = "
    SELECT cl.client_name AS name, COALESCE(SUM(sc.revenue),0) AS revenue, COALESCE(SUM(sc.gross_profit),0) AS profit
    FROM sales_cases sc
    JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND ((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))
      $_ctf
    GROUP BY cl.id, cl.client_name ORDER BY revenue DESC";
$_s = $_sDb->prepare($_clientFySql);
$_s->execute(array_merge([$cid, $year-1, $year], $_ctp));
$clientFyRows = $_s->fetchAll();
// アライアンス別売上（年度）
$_allianceFySql = "
    SELECT al.alliance_name AS name, COALESCE(SUM(sc.revenue),0) AS revenue, COALESCE(SUM(sc.gross_profit),0) AS profit
    FROM sales_cases sc
    JOIN sales_alliances al ON sc.alliance_id = al.id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND ((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))
      $_ctf
    GROUP BY al.id, al.alliance_name ORDER BY revenue DESC";
$_s = $_sDb->prepare($_allianceFySql);
$_s->execute(array_merge([$cid, $year-1, $year], $_ctp));
$allianceFyRows = $_s->fetchAll();
// 営業マン別売上（年度）
$_repFySql = "
    SELECT sales_rep AS name, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
      $_ctf2
    GROUP BY sales_rep ORDER BY revenue DESC";
$_s = $_sDb->prepare($_repFySql);
$_s->execute(array_merge([$cid, $year-1, $year], $_ctp));
$repFyRows = $_s->fetchAll();
// キャリア別売上（年度）
$_carrierFySql = "
    SELECT carrier AS name, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status = 'confirmed' AND carrier IS NOT NULL AND carrier != ''
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
      $_ctf2
    GROUP BY carrier ORDER BY revenue DESC";
$_s = $_sDb->prepare($_carrierFySql);
$_s->execute(array_merge([$cid, $year-1, $year], $_ctp));
$carrierFyRows = $_s->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-graph-up-arrow me-2"></i><?= h($pageTitle) ?></h1>
                <p><?= $year ?>年<?= $month ?>月の売上状況<?= $salesRep ? ' — ' . h($salesRep) : '' ?></p>
            </div>
            <div class="d-flex gap-2">
                <select onchange="location.href='?year='+this.value+'&month=<?= $month ?>&sales_rep=<?= urlencode($salesRep) ?>'" class="form-select form-select-sm" style="width:100px">
                    <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                    <?php endfor; ?>
                </select>
                <select onchange="location.href='?year=<?= $year ?>&month='+this.value+'&sales_rep=<?= urlencode($salesRep) ?>'" class="form-select form-select-sm" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
                <?php if (isAdmin()): ?>
                <select onchange="location.href='?year=<?= $year ?>&month=<?= $month ?>&sales_rep='+encodeURIComponent(this.value)" class="form-select form-select-sm" style="width:140px">
                    <option value="">全担当者</option>
                    <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= h($rep) ?>" <?= $salesRep === $rep ? 'selected' : '' ?>><?= h($rep) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KPIカード -->
    <div class="d-flex justify-content-end mb-2">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary active" id="btnKpiTaxExcl" onclick="setKpiTaxMode(false)" style="font-size:.7rem;padding:2px 8px">税抜</button>
            <button type="button" class="btn btn-outline-secondary" id="btnKpiTaxIncl" onclick="setKpiTaxMode(true)" style="font-size:.7rem;padding:2px 8px">税込</button>
        </div>
    </div>
    <div class="row g-2 mb-4">
        <!-- 売上目標（年度合計） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#6366f1"><?= number_format($fyTotalTarget) ?></div>
                <div class="kpi-label">売上目標</div>
                <div class="kpi-sub">年度合計</div>
            </div>
        </div>
        <!-- 売上（年度合計） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#059669"><?= number_format($fyTotalRev) ?></div>
                <div class="kpi-label">売上</div>
                <div class="kpi-sub">年度合計</div>
            </div>
        </div>
        <!-- 粗利（年度合計） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#3b82f6"><?= number_format($fyTotalProfit) ?></div>
                <div class="kpi-label">粗利</div>
                <div class="kpi-sub">粗利率: <?= $fyMargin ?>%</div>
            </div>
        </div>
        <!-- 目標達成率（年度） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:<?= $fyAchColor ?>"><?= $fyAch ?>%</div>
                <div class="kpi-label">目標達成率</div>
                <div class="kpi-sub"><?= $fyAch >= 100 ? '達成' : ($fyAch >= 80 ? 'もう少し' : ($fyAch >= 50 ? '進行中' : '要注意')) ?></div>
            </div>
        </div>
        <!-- 前年同月比 -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value"><?= $kpis['yoy_change'] >= 0 ? '+' : '' ?><?= $kpis['yoy_change'] ?>%</div>
                <div class="kpi-label">前年同月比（売上）</div>
                <span class="kpi-badge <?= $kpis['yoy_change'] >= 0 ? 'kpi-up' : 'kpi-down' ?>">
                    <i class="bi bi-arrow-<?= $kpis['yoy_change'] >= 0 ? 'up' : 'down' ?>"></i>
                    前年 <span data-kpi-tax data-raw="<?= $kpis['prev_year_revenue'] ?>"><?= number_format($kpis['prev_year_revenue']) ?></span>
                </span>
            </div>
        </div>
    </div>

    <!-- 年度月別売上テーブル -->
    <input type="hidden" id="fycsrf" value="<?= h(getCsrfToken()) ?>">
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-1" style="color:#6366f1"></i><?= $year-1 ?>-<?= $year ?>年度 月別売上（9月〜8月）</span>
            <small class="text-muted">売上目標は直接入力で保存されます</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:.8rem;min-width:900px">
                    <thead class="table-light">
                        <tr>
                            <th style="width:6em">項目</th>
                            <?php foreach ($fyMonths as $fm): ?>
                            <th class="text-center"><?= $fm['m'] ?>月</th>
                            <?php endforeach; ?>
                            <th class="text-center table-secondary fw-bold">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // 月別データを準備
                        $fyRowData = [];
                        $prevRev = null;
                        foreach ($fyMonths as $i => $fm) {
                            $rev    = $fyRevMap[$fm['y']][$fm['m']]['rev']    ?? 0;
                            $profit = $fyRevMap[$fm['y']][$fm['m']]['profit'] ?? 0;
                            $tgt    = $fyTgtMap[$fm['y']][$fm['m']]          ?? 0;
                            $margin = $rev > 0 ? round($profit / $rev * 100, 1) : null;
                            $mom    = ($prevRev !== null && $prevRev > 0) ? round(($rev - $prevRev) / $prevRev * 100, 1) : null;
                            $ach    = ($tgt > 0) ? round($rev / $tgt * 100, 1) : null;
                            $fyRowData[$i] = compact('rev','profit','tgt','margin','mom','ach');
                            $prevRev = $rev;
                        }
                        ?>
                        <!-- 売上目標（手入力） -->
                        <tr>
                            <td class="fw-semibold" style="color:#6366f1">売上目標</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="p-0">
                                <input type="text" class="fy-tgt-input form-control form-control-sm border-0 text-end px-1"
                                       style="min-width:72px;background:transparent"
                                       data-year="<?= $fm['y'] ?>" data-month="<?= $fm['m'] ?>"
                                       value="<?= $d['tgt'] > 0 ? number_format($d['tgt']) : '' ?>"
                                       placeholder="0">
                            </td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary" id="fyTgtTotal"><?= $fyTotalTarget > 0 ? number_format($fyTotalTarget) : '-' ?></td>
                        </tr>
                        <!-- 売上 -->
                        <tr>
                            <td class="fw-semibold" style="color:#059669">売上</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end <?= $d['rev'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $d['rev'] > 0 ? number_format($d['rev']) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary"><?= $fyTotalRev > 0 ? number_format($fyTotalRev) : '-' ?></td>
                        </tr>
                        <!-- 粗利 -->
                        <tr>
                            <td class="fw-semibold" style="color:#3b82f6">粗利</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end"><?= $d['profit'] > 0 ? number_format($d['profit']) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary"><?= $fyTotalProfit > 0 ? number_format($fyTotalProfit) : '-' ?></td>
                        </tr>
                        <!-- 粗利率 -->
                        <tr class="table-light">
                            <td class="text-muted">粗利率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end text-muted"><?= $d['margin'] !== null ? $d['margin'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary text-muted"><?= $fyMargin > 0 ? $fyMargin . '%' : '-' ?></td>
                        </tr>
                        <!-- 売上前月比 -->
                        <tr class="table-light">
                            <td class="text-muted">前月比</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end <?= $d['mom'] === null ? 'text-muted' : ($d['mom'] >= 0 ? 'text-success' : 'text-danger') ?>">
                                <?= $d['mom'] !== null ? ($d['mom'] >= 0 ? '+' : '') . $d['mom'] . '%' : '-' ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary text-muted">-</td>
                        </tr>
                        <!-- 達成率 -->
                        <tr>
                            <td class="fw-semibold">達成率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i];
                                $achCls = $d['ach'] === null ? 'text-muted' : ($d['ach'] >= 100 ? 'text-success' : ($d['ach'] >= 80 ? 'text-primary' : ($d['ach'] >= 50 ? 'text-warning' : 'text-danger')));
                            ?>
                            <td class="text-end <?= $achCls ?> fw-semibold"><?= $d['ach'] !== null ? $d['ach'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary <?= $fyAch >= 100 ? 'text-success' : ($fyAch >= 80 ? 'text-primary' : ($fyAch >= 50 ? 'text-warning' : ($fyAch > 0 ? 'text-danger' : 'text-muted'))) ?>"><?= $fyAch > 0 ? $fyAch . '%' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 売上推移チャート（全幅） -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up me-1" style="color:#059669"></i><?= $year-1 ?>年度 売上推移（9月〜8月）</span>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active" id="btnTrendTaxExcl" onclick="setTrendTaxMode(false)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTrendTaxIncl" onclick="setTrendTaxMode(true)" style="font-size:.7rem;padding:2px 8px">税込</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="sales-chart-wrap">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- スタッフ分析（全幅） -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-1" style="color:#3b82f6"></i>スタッフ分析</span>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active" id="btnTaxExcl" onclick="setTaxMode(false)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTaxIncl" onclick="setTaxMode(true)" style="font-size:.7rem;padding:2px 8px">税込</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-0 align-items-center justify-content-center">
                        <!-- ドーナツ: 売上構成 -->
                        <div class="col-md-4 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem">区分別売上</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="workerChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:1.9">
                                <span style="color:#3b82f6">●</span> 自社 <strong><?= (int)$workerGrouped['自社']['case_count'] ?>件</strong>
                                &nbsp;&nbsp;
                                <span style="color:#059669">●</span> アライアンス <strong><?= (int)$workerGrouped['アライアンス']['case_count'] ?>件</strong>
                            </div>
                        </div>
                        <!-- ドーナツ: 人数構成 -->
                        <div class="col-md-4 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem">人数構成</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="staffPieChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:1.9">
                                <span style="color:#3b82f6">●</span> 自社 <strong><?= (int)$empStats['inhouse'] ?>名</strong>
                                &nbsp;&nbsp;
                                <span style="color:#059669">●</span> アライアンス <strong><?= (int)$empStats['alliance'] ?>名</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 集計カード: キャリア別売上（TOP3）+ クライアント別売上（TOP5） -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-phone me-1" style="color:#06b6d4"></i>キャリア別売上 <small class="text-muted ms-1">TOP3</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($carrierFyRows) > 3): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'carrierFyTable')" data-expanded="0">全て表示</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="carrierFyTable">
                        <thead class="table-light"><tr><th style="padding-left:.75rem">キャリア</th><th class="text-end">売上</th><th class="text-end" style="padding-right:.75rem">粗利</th></tr></thead>
                        <tbody>
                            <?php if ($carrierFyRows): ?>
                            <?php foreach ($carrierFyRows as $i => $row): ?>
                            <tr <?= $i >= 3 ? 'class="extra-row" style="display:none"' : '' ?>>
                                <td style="padding-left:.75rem"><?= h($row['name']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)$row['revenue'] ?>"><?= number_format($row['revenue']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)($row['profit'] ?? 0) ?>" style="padding-right:.75rem;color:#3b82f6"><?= number_format($row['profit'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted small p-3">データなし</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-1" style="color:#6366f1"></i>クライアント別売上 <small class="text-muted ms-1">TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($clientFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'clientFyTable')" data-expanded="0">全て表示</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="clientFyTable">
                        <thead class="table-light"><tr><th style="padding-left:.75rem">会社名</th><th class="text-end">売上</th><th class="text-end" style="padding-right:.75rem">粗利</th></tr></thead>
                        <tbody>
                            <?php if ($clientFyRows): ?>
                            <?php foreach ($clientFyRows as $i => $row): ?>
                            <tr <?= $i >= 5 ? 'class="extra-row" style="display:none"' : '' ?>>
                                <td style="padding-left:.75rem"><?= h($row['name']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)$row['revenue'] ?>"><?= number_format($row['revenue']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)($row['profit'] ?? 0) ?>" style="padding-right:.75rem;color:#3b82f6"><?= number_format($row['profit'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted small p-3">データなし</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 集計カード: アライアンス別売上（TOP5）+ 営業マン別売上（TOP5） -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-1" style="color:#059669"></i>アライアンス別売上 <small class="text-muted ms-1">TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($allianceFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'allianceFyTable')" data-expanded="0">全て表示</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="allianceFyTable">
                        <thead class="table-light"><tr><th style="padding-left:.75rem">会社名</th><th class="text-end">売上</th><th class="text-end" style="padding-right:.75rem">粗利</th></tr></thead>
                        <tbody>
                            <?php if ($allianceFyRows): ?>
                            <?php foreach ($allianceFyRows as $i => $row): ?>
                            <tr <?= $i >= 5 ? 'class="extra-row" style="display:none"' : '' ?>>
                                <td style="padding-left:.75rem"><?= h($row['name']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)$row['revenue'] ?>"><?= number_format($row['revenue']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)($row['profit'] ?? 0) ?>" style="padding-right:.75rem;color:#3b82f6"><?= number_format($row['profit'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted small p-3">データなし</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-badge me-1" style="color:#f59e0b"></i>営業マン別売上 <small class="text-muted ms-1">TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($repFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'repFyTable')" data-expanded="0">全て表示</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0" id="repFyTable">
                        <thead class="table-light"><tr><th style="padding-left:.75rem">氏名</th><th class="text-end">売上</th><th class="text-end" style="padding-right:.75rem">粗利</th></tr></thead>
                        <tbody>
                            <?php if ($repFyRows): ?>
                            <?php foreach ($repFyRows as $i => $row): ?>
                            <tr <?= $i >= 5 ? 'class="extra-row" style="display:none"' : '' ?>>
                                <td style="padding-left:.75rem"><?= h($row['name']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)$row['revenue'] ?>"><?= number_format($row['revenue']) ?></td>
                                <td class="text-end summary-tax-val" data-raw="<?= (int)($row['profit'] ?? 0) ?>" style="padding-right:.75rem;color:#3b82f6"><?= number_format($row['profit'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted small p-3">データなし</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 交通費提出フォームは交通費ページに移動しました -->
    <div class="row g-4 mb-4" style="display:none!important">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-car-front me-1" style="color:#f59e0b"></i>交通費提出フォーム</span>
                    <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#transportForm">
                        <i class="bi bi-chevron-down"></i> 開く
                    </button>
                </div>
                <div class="collapse" id="transportForm">
                    <div class="card-body">
                        <form id="transportSubmitForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= h(getCsrfToken()) ?>">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">氏名 <span class="text-danger">*</span></label>
                                    <?php if ($empFilter): ?>
                                    <input type="text" class="form-control" name="employee_name" value="<?= h($empFilter) ?>" readonly>
                                    <?php else: ?>
                                    <select name="employee_name" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($salesReps as $rep): ?>
                                        <option value="<?= h($rep) ?>"><?= h($rep) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">対象年月 <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-2">
                                        <select name="target_year" class="form-select">
                                            <?php for ($y = date('Y') + 1; $y >= 2025; $y--): ?>
                                            <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?>年</option>
                                            <?php endfor; ?>
                                        </select>
                                        <select name="target_month" class="form-select">
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?>月</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- ルート① -->
                            <div class="border rounded p-3 mb-3">
                                <h6 class="mb-3"><i class="bi bi-1-circle me-1"></i>交通費① <span class="text-danger">* 必須</span></h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">エビデンス（画像/PDF） <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" name="evidence_1" accept="image/*,.pdf" required>
                                        <div class="form-text">JPEG/PNG/GIF/WebP/PDF（10MB以下）</div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">片道距離(km)</label>
                                        <input type="number" class="form-control tc-distance" name="distance_km_1" step="0.1" min="0" data-route="1">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">稼働日数 <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control tc-days" name="work_days_1" min="1" max="31" required data-route="1">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">金額（円）<span class="text-danger">*</span></label>
                                        <input type="number" class="form-control tc-cost" name="cost_1" min="0" required data-route="1">
                                    </div>
                                </div>
                            </div>

                            <!-- ルート② -->
                            <div class="border rounded p-3 mb-3">
                                <h6 class="mb-3"><i class="bi bi-2-circle me-1"></i>交通費②（任意）</h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">エビデンス（画像/PDF）</label>
                                        <input type="file" class="form-control" name="evidence_2" accept="image/*,.pdf">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">片道距離(km)</label>
                                        <input type="number" class="form-control tc-distance" name="distance_km_2" step="0.1" min="0" data-route="2">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">稼働日数</label>
                                        <input type="number" class="form-control tc-days" name="work_days_2" min="0" max="31" data-route="2">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">金額（円）</label>
                                        <input type="number" class="form-control tc-cost" name="cost_2" min="0" data-route="2">
                                    </div>
                                </div>
                            </div>

                            <!-- ルート③ -->
                            <div class="border rounded p-3 mb-3">
                                <h6 class="mb-3"><i class="bi bi-3-circle me-1"></i>交通費③（任意）</h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">エビデンス（画像/PDF）</label>
                                        <input type="file" class="form-control" name="evidence_3" accept="image/*,.pdf">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">片道距離(km)</label>
                                        <input type="number" class="form-control tc-distance" name="distance_km_3" step="0.1" min="0" data-route="3">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">稼働日数</label>
                                        <input type="number" class="form-control tc-days" name="work_days_3" min="0" max="31" data-route="3">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">金額（円）</label>
                                        <input type="number" class="form-control tc-cost" name="cost_3" min="0" data-route="3">
                                    </div>
                                </div>
                            </div>

                            <!-- 高速代 & 合計 -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label">高速代（円）</label>
                                    <input type="number" class="form-control tc-cost" name="highway_cost" min="0" value="0" data-route="hw">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">合計金額</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control fw-bold" id="tcTotalDisplay" readonly value="0">
                                        <span class="input-group-text">円</span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-warning" id="transportSubmitBtn">
                                    <i class="bi bi-send me-1"></i>交通費を提出
                                </button>
                                <div id="transportSubmitMsg" class="align-self-center"></div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
// チャート用データ（年度順: 9月→8月 = インデックス1〜12）
$trendData    = [];
$trendTargets = [];
foreach ($fyMonths as $i => $fm) {
    $idx    = $i + 1;
    $rev    = $fyRevMap[$fm['y']][$fm['m']]['rev']    ?? 0;
    $profit = $fyRevMap[$fm['y']][$fm['m']]['profit'] ?? 0;
    $tgt    = $fyTgtMap[$fm['y']][$fm['m']] ?? 0;
    $trendData[$idx] = [
        'revenue'        => $rev,
        'profit'         => $profit,
        'regular_rev'    => $fyTypeRevMap[$fm['y']][$fm['m']]['regular']['rev']    ?? 0,
        'regular_profit' => $fyTypeRevMap[$fm['y']][$fm['m']]['regular']['profit'] ?? 0,
        'event_rev'      => $fyTypeRevMap[$fm['y']][$fm['m']]['event']['rev']      ?? 0,
        'event_profit'   => $fyTypeRevMap[$fm['y']][$fm['m']]['event']['profit']   ?? 0,
        'ach'            => $tgt > 0 ? round($rev / $tgt * 100, 1) : null,
    ];
    $trendTargets[$idx] = $tgt;
}
$fyChartLabels = ['9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月','8月'];

$wLabels = array_keys($workerGrouped);
$wValues = array_map(fn($g) => $g['revenue'], array_values($workerGrouped));
$wColors = ['#3b82f6', '#059669'];

// スタッフ人数円グラフデータ（社員テーブルベース）
$staffPieLabels = ['自社', 'アライアンス'];
$staffPieValues = [$empStats['inhouse'], $empStats['alliance']];
$staffPieColors = ['#3b82f6', '#059669'];

$rawWorkerValues = json_encode($wValues);

$inlineJs = 'const trendRawData = ' . json_encode($trendData) . ';';
$inlineJs .= 'const trendTargets = ' . json_encode($trendTargets) . ';';
$inlineJs .= 'const fyChartLabels = ' . json_encode($fyChartLabels) . ';';
$inlineJs .= 'let trendChartInstance = salesDrawTrendChart("trendChart", trendRawData, trendTargets, fyChartLabels);';
$inlineJs .= 'const workerRawValues = ' . $rawWorkerValues . ';';
$inlineJs .= 'let workerChartInstance = salesDrawDonutChart("workerChart", ' . json_encode($wLabels) . ', workerRawValues, ' . json_encode($wColors) . ');';
$inlineJs .= 'salesDrawDonutChart("staffPieChart", ' . json_encode($staffPieLabels) . ', ' . json_encode($staffPieValues) . ', ' . json_encode($staffPieColors) . ');';
$inlineJs .= <<<'TAXJS'

let taxIncluded = false;
function setTaxMode(incl) {
    taxIncluded = incl;
    document.getElementById('btnTaxIncl').classList.toggle('active', incl);
    document.getElementById('btnTaxExcl').classList.toggle('active', !incl);
    const rate = incl ? 1.1 : 1.0;
    document.querySelectorAll('.worker-revenue').forEach(el => {
        const raw = parseInt(el.dataset.raw) || 0;
        el.textContent = Math.round(raw * rate).toLocaleString() + '円';
    });
    const newVals = workerRawValues.map(v => Math.round(v * rate));
    if (workerChartInstance && workerChartInstance.data) {
        workerChartInstance.data.datasets[0].data = newVals;
        workerChartInstance.update();
    }
}

let trendTaxIncluded = false;
function setTrendTaxMode(incl) {
    trendTaxIncluded = incl;
    document.getElementById('btnTrendTaxIncl').classList.toggle('active', incl);
    document.getElementById('btnTrendTaxExcl').classList.toggle('active', !incl);
    const rate = incl ? 1.1 : 1.0;
    if (trendChartInstance && trendChartInstance.data) {
        const idxs = Array.from({length: 12}, (_, i) => i + 1);
        const ds = trendChartInstance.data.datasets;
        // [0]=達成率(bar・%値) → 率なので変化なし（そのまま保持）
        // [1]=目標 [2]=売上 [3]=粗利 [4]=常勤売上 [5]=常勤粗利 [6]=イベント売上 [7]=イベント粗利
        ds[1].data = idxs.map(i => Math.round((trendTargets[i]              || 0) * rate));
        ds[2].data = idxs.map(i => Math.round((trendRawData[i]?.revenue     || 0) * rate));
        ds[3].data = idxs.map(i => Math.round((trendRawData[i]?.profit      || 0) * rate));
        if (ds[4]) ds[4].data = idxs.map(i => Math.round((trendRawData[i]?.regular_rev    || 0) * rate));
        if (ds[5]) ds[5].data = idxs.map(i => Math.round((trendRawData[i]?.regular_profit || 0) * rate));
        if (ds[6]) ds[6].data = idxs.map(i => Math.round((trendRawData[i]?.event_rev      || 0) * rate));
        if (ds[7]) ds[7].data = idxs.map(i => Math.round((trendRawData[i]?.event_profit   || 0) * rate));
        trendChartInstance.update();
    }
}

let kpiTaxIncluded = false;
function setKpiTaxMode(incl) {
    kpiTaxIncluded = incl;
    document.getElementById('btnKpiTaxIncl').classList.toggle('active', incl);
    document.getElementById('btnKpiTaxExcl').classList.toggle('active', !incl);
    const rate = incl ? 1.1 : 1.0;
    document.querySelectorAll('[data-kpi-tax]').forEach(el => {
        const raw = parseInt(el.dataset.raw) || 0;
        el.textContent = Math.round(raw * rate).toLocaleString();
    });
}
TAXJS;

$inlineJs .= <<<'JSEOF2'
(function() { return; /* 日報フォーム削除済み */
    const carriersByLocation = {
        '家電量販店': [
            {value:'SBモバイル', label:'SBモバイルスタッフ'},
            {value:'SB固定', label:'SB固定スタッフ'},
            {value:'auモバイル', label:'auモバイルスタッフ'},
            {value:'au固定', label:'au固定スタッフ'},
            {value:'docomoモバイル', label:'docomoモバイルスタッフ'}
        ],
        'ショップ': [
            {value:'SBモバイル', label:'SBモバイルスタッフ'},
            {value:'SB固定', label:'SB固定スタッフ'},
            {value:'auモバイル', label:'auモバイルスタッフ'},
            {value:'au固定', label:'au固定スタッフ'},
            {value:'格安SIM', label:'格安SIM'}
        ]
    };

    // フィールド定義: [DBカラム名, ラベル]
    const F = {
        contacts:       ['contacts','接点数'],
        consultations:  ['consultations','接客数'],
        seated:         ['seated','着座数'],
        sb_mnp:         ['sb_mnp','SB MNP'],
        sb_new:         ['sb_new','SB純新規'],
        sb_change:      ['sb_change','SB機種変更'],
        sb_upgrade:     ['sb_upgrade','アップグレード'],
        ym_mnp:         ['ym_mnp','YM MNP'],
        ym_new:         ['ym_new','YM純新規'],
        ym_change:      ['ym_change','YM機種変更'],
        ym_downgrade:   ['ym_downgrade','ダウングレード'],
        sb_hikari:      ['sb_hikari','SB光'],
        sb_air:         ['sb_air','Air'],
        ouchi_denwa:    ['ouchi_denwa','おうちのでんわ'],
        paypay_card:    ['paypay_card','PayPayカード'],
        ouchi_denki:    ['ouchi_denki','おうちでんき'],
        selection_amount: ['selection_amount','セレクション金額'],
        acquisition_points: ['acquisition_points','獲得ポイント'],
        mobile_external:   ['mobile_external','モバイル対外'],
        mobile_change_count: ['mobile_change_count','モバイル機種変更'],
        sb_hikari_new: ['sb_hikari_new','SB光新規'],
        sb_hikari_provider_change: ['sb_hikari_provider_change','SB光事業者変更'],
        sb_hikari_transfer: ['sb_hikari_transfer','SB光転用'],
        air_new:        ['air_new','Air新規'],
        air_change:     ['air_change','Air機種変更'],
        au_mnp:         ['au_mnp','au MNP'],
        au_new:         ['au_new','au純新規'],
        au_change:      ['au_change','au機種変更'],
        au_upgrade:     ['au_upgrade','アップグレード(au)'],
        uq_mnp:         ['uq_mnp','UQ MNP'],
        uq_new:         ['uq_new','UQ純新規'],
        uq_change:      ['uq_change','UQ機種変更'],
        uq_downgrade:   ['uq_downgrade','ダウングレード(UQ)'],
        biglobe_hikari: ['biglobe_hikari','BIGLOBE光'],
        commufa_hikari: ['commufa_hikari','コミュファ光'],
        aupay_card:     ['aupay_card','auPAYカード'],
        au_denki:       ['au_denki','auでんき'],
        au_smartpass:   ['au_smartpass','auスマートパス'],
        fixed_new:      ['fixed_new','固定新規'],
        fixed_new_hikari: ['fixed_new','光回線新規'],
        fixed_new_kotei: ['fixed_new','固定'],
        fixed_provider_change: ['fixed_provider_change','光回線事業者変更'],
        fixed_transfer: ['fixed_transfer','光回線転用'],
        home_router_new:    ['home_router_new','ホームルーター新規'],
        home_router_change: ['home_router_change','ホームルーター機種変更'],
        visit_groups:       ['visit_groups','来店組数'],
        consultation_groups: ['consultation_groups','接客組数'],
        mobile_acquisitions: ['mobile_acquisitions','モバイル獲得数'],
        setup_support:  ['setup_support','設定サポート'],
        sim_mnp:        ['sim_mnp','MNP'],
        sim_new:        ['sim_new','純新規'],
        sim_change:     ['sim_change','機種変更'],
        sim_fixed:      ['sim_fixed','固定回線'],
        sim_router:     ['sim_router','ホームルーター']
    };

    // 各パスのフィールドグループ定義
    const pathFields = {
        '家電量販店_SBモバイル': [
            {title:'接客', color:'#6b7280', fields:['contacts','consultations','seated']},
            {title:'SBモバイル', color:'#3b82f6', fields:['sb_mnp','sb_new','sb_change','sb_upgrade']},
            {title:'Y!mobile', color:'#eab308', fields:['ym_mnp','ym_new','ym_change','ym_downgrade']},
            {title:'固定/その他', color:'#059669', fields:['sb_hikari','sb_air','ouchi_denwa','paypay_card','ouchi_denki','selection_amount','acquisition_points']}
        ],
        '家電量販店_SB固定': [
            {title:'接客', color:'#6b7280', fields:['contacts','consultations','seated']},
            {title:'モバイル', color:'#3b82f6', fields:['mobile_external','mobile_change_count']},
            {title:'SB光', color:'#059669', fields:['sb_hikari_new','sb_hikari_provider_change','sb_hikari_transfer']},
            {title:'Air/その他', color:'#8b5cf6', fields:['air_new','air_change','ouchi_denwa','paypay_card','ouchi_denki','acquisition_points']}
        ],
        '家電量販店_auモバイル': [
            {title:'接客', color:'#6b7280', fields:['contacts','consultations','seated']},
            {title:'au', color:'#f97316', fields:['au_mnp','au_new','au_change','au_upgrade']},
            {title:'UQ mobile', color:'#a855f7', fields:['uq_mnp','uq_new','uq_change','uq_downgrade']},
            {title:'固定/その他', color:'#059669', fields:['biglobe_hikari','commufa_hikari','aupay_card','au_denki','au_smartpass']}
        ],
        '家電量販店_au固定': [
            {title:'接客', color:'#6b7280', fields:['contacts','consultations','seated']},
            {title:'モバイル', color:'#f97316', fields:['mobile_external','mobile_change_count']},
            {title:'固定', color:'#059669', fields:['fixed_new','home_router_new','home_router_change']},
            {title:'その他', color:'#8b5cf6', fields:['au_denki']}
        ],
        '家電量販店_docomoモバイル': [
            {title:'接客', color:'#6b7280', fields:['contacts','consultations','seated']},
            {title:'モバイル', color:'#e11d48', fields:['mobile_external','mobile_change_count']},
            {title:'固定', color:'#059669', fields:['fixed_new','home_router_change']}
        ],
        'ショップ_SBモバイル': [
            {title:'接客', color:'#6b7280', fields:['consultations']},
            {title:'SBモバイル', color:'#3b82f6', fields:['sb_mnp','sb_new','sb_change','sb_upgrade']},
            {title:'Y!mobile', color:'#eab308', fields:['ym_mnp','ym_new','ym_change','ym_downgrade']},
            {title:'固定/その他', color:'#059669', fields:['sb_hikari','sb_air','ouchi_denwa','paypay_card','ouchi_denki','selection_amount','setup_support','acquisition_points']}
        ],
        'ショップ_SB固定': [
            {title:'来店', color:'#6b7280', fields:['visit_groups','consultation_groups','mobile_acquisitions']},
            {title:'SB光', color:'#059669', fields:['sb_hikari_new','sb_hikari_provider_change','sb_hikari_transfer']},
            {title:'Air/その他', color:'#8b5cf6', fields:['air_new','air_change','ouchi_denwa','paypay_card','ouchi_denki','acquisition_points']}
        ],
        'ショップ_auモバイル': [
            {title:'接客', color:'#6b7280', fields:['consultations']},
            {title:'au', color:'#f97316', fields:['au_mnp','au_new','au_change','au_upgrade']},
            {title:'UQ mobile', color:'#a855f7', fields:['uq_mnp','uq_new','uq_change','uq_downgrade']},
            {title:'固定/その他', color:'#059669', fields:['fixed_new_kotei','aupay_card','au_denki','au_smartpass']}
        ],
        'ショップ_au固定': [
            {title:'来店', color:'#6b7280', fields:['visit_groups','consultation_groups','mobile_acquisitions']},
            {title:'光回線', color:'#059669', fields:['fixed_new_hikari','fixed_provider_change','fixed_transfer']},
            {title:'ルーター/その他', color:'#8b5cf6', fields:['home_router_new','home_router_change','au_denki','au_smartpass']}
        ],
        'ショップ_格安SIM': [
            {title:'接客', color:'#6b7280', fields:['consultations']},
            {title:'獲得', color:'#059669', fields:['sim_mnp','sim_new','sim_change','sim_fixed','sim_router']}
        ]
    };

    const locSelect = document.getElementById('drLocationType');
    const carrierSelect = document.getElementById('drCarrier');
    const dynamicArea = document.getElementById('drDynamicFields');
    const submitBtn = document.getElementById('drSubmitBtn');

    locSelect.addEventListener('change', function() {
        const loc = this.value;
        carrierSelect.innerHTML = '<option value="">選択してください</option>';
        carrierSelect.disabled = !loc;
        dynamicArea.innerHTML = '';
        submitBtn.disabled = true;
        if (loc && carriersByLocation[loc]) {
            carriersByLocation[loc].forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.value;
                opt.textContent = c.label;
                carrierSelect.appendChild(opt);
            });
        }
    });

    carrierSelect.addEventListener('change', function() {
        const loc = locSelect.value;
        const carrier = this.value;
        const key = loc + '_' + carrier;
        dynamicArea.innerHTML = '';
        submitBtn.disabled = !carrier;
        if (!pathFields[key]) return;

        pathFields[key].forEach(group => {
            const section = document.createElement('div');
            section.className = 'border rounded p-3 mb-3';
            let html = '<h6 class="mb-3" style="color:' + group.color + '"><i class="bi bi-circle-fill me-1" style="font-size:0.6em"></i>' + group.title + '</h6>';
            html += '<div class="row g-2">';
            group.fields.forEach(fKey => {
                const [dbCol, label] = F[fKey];
                html += '<div class="col-6 col-md-3 col-lg-2">';
                html += '<label class="form-label small">' + label + '</label>';
                html += '<input type="number" class="form-control form-control-sm dr-field" name="' + dbCol + '" min="0" value="0">';
                html += '</div>';
            });
            html += '</div>';
            section.innerHTML = html;
            dynamicArea.appendChild(section);
        });
    });

    // フォーム送信
    document.getElementById('drSubmitForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('drSubmitBtn');
        const msg = document.getElementById('drSubmitMsg');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>送信中...';
        msg.innerHTML = '';

        // フォームデータをJSONに変換
        const fd = new FormData(this);
        const payload = {};
        fd.forEach((v, k) => {
            if (k === 'csrf') return;
            payload[k] = v;
        });
        payload.csrf = fd.get('csrf');

        try {
            const res = await fetch(BASE_PATH + '/public/api/sales_report.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': payload.csrf},
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.ok) {
                msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>日報を提出しました</span>';
                // フォームリセット
                dynamicArea.querySelectorAll('.dr-field').forEach(f => f.value = '0');
                this.querySelector('[name="note"]').value = '';
                this.querySelector('[name="location"]').value = '';
            } else {
                msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>' + (data.error || '送信に失敗しました') + '</span>';
            }
        } catch (err) {
            msg.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>通信エラーが発生しました</span>';
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i>日報を提出';
    });
})();
JSEOF2;

$inlineJs .= <<<'FYJS'
// 年度月別目標 入力保存
(function() {
    var csrf = document.getElementById('fycsrf') ? document.getElementById('fycsrf').value : '';
    document.querySelectorAll('.fy-tgt-input').forEach(function(el) {
        el.addEventListener('focus', function() { this.select(); });
        el.addEventListener('blur', function() {
            var raw = this.value.replace(/[^0-9]/g, '') || '0';
            var val = parseInt(raw);
            var yr  = this.dataset.year;
            var mo  = this.dataset.month;
            var fd  = new FormData();
            fd.append('action', 'save_fy_target');
            fd.append('csrf', csrf);
            fd.append('t_year', yr);
            fd.append('t_month', mo);
            fd.append('t_value', val);
            var self = this;
            fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok) {
                        self.value = val > 0 ? val.toLocaleString() : '';
                        self.style.background = '#d1fae5';
                        setTimeout(function(){ self.style.background = 'transparent'; }, 800);
                        recalcFyTgtTotal();
                    }
                });
        });
    });
    function recalcFyTgtTotal() {
        var total = 0;
        document.querySelectorAll('.fy-tgt-input').forEach(function(el) {
            total += parseInt(el.value.replace(/[^0-9]/g, '') || '0');
        });
        var el = document.getElementById('fyTgtTotal');
        if (el) el.textContent = total > 0 ? total.toLocaleString() : '-';
    }
})();
FYJS;

$inlineJs .= <<<'SUMMARYJS'
let summaryTaxIncluded = false;
function setSummaryTaxMode(incl, btn) {
    var scope = (btn && btn.closest) ? btn.closest('.card') : document;
    if (!scope) scope = document;
    scope.querySelectorAll('.summary-tax-excl').forEach(function(b) { b.classList.toggle('active', !incl); });
    scope.querySelectorAll('.summary-tax-incl').forEach(function(b) { b.classList.toggle('active', incl); });
    var rate = incl ? 1.1 : 1.0;
    scope.querySelectorAll('.summary-tax-val').forEach(function(el) {
        var raw = parseInt(el.dataset.raw) || 0;
        el.textContent = Math.round(raw * rate).toLocaleString();
    });
}
SUMMARYJS;

$inlineJs .= <<<'EXPANDJS'
function toggleExpand(btn, tableId) {
    var table = document.getElementById(tableId);
    var extras = table.querySelectorAll('.extra-row');
    var expanded = btn.dataset.expanded === '1';
    extras.forEach(function(r) { r.style.display = expanded ? 'none' : ''; });
    btn.textContent = expanded ? '全て表示' : '閉じる';
    btn.dataset.expanded = expanded ? '0' : '1';
}
EXPANDJS;

require_once __DIR__ . '/../includes/footer.php';
?>
