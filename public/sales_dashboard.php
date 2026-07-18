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
    // 総合ダッシュボードは入力不可（常勤+イベントの合計を自動表示）
    if (!$caseTypeFilter) { echo json_encode(['ok' => true, 'readonly' => true]); exit; }
    $ty = (int)($_POST['t_year'] ?? 0);
    $tm = (int)($_POST['t_month'] ?? 0);
    $tv = (int)str_replace([',', '¥', ' ', '　'], '', $_POST['t_value'] ?? '0');
    if ($ty && $tm) { upsertSalesTarget($cid, $ty, $tm, $caseTypeFilter, max(0, $tv)); }
    echo json_encode(['ok' => true]);
    exit;
}

// AJAX: 月別枠数目標の保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_frame_target') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'csrf']); exit; }
    if (!$caseTypeFilter) { echo json_encode(['ok' => true, 'readonly' => true]); exit; }
    $ty = (int)($_POST['t_year']  ?? 0);
    $tm = (int)($_POST['t_month'] ?? 0);
    $tv = max(0, (int)str_replace([',', '¥', ' ', '　'], '', $_POST['t_value'] ?? '0'));
    if ($ty && $tm) {
        $db = getDB();
        try { $db->exec("CREATE TABLE IF NOT EXISTS sales_frame_targets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, case_type VARCHAR(20) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, target_first_frame INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sft (company_id, case_type, year, month), INDEX idx_sft_company (company_id, case_type, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
        $db->prepare("INSERT INTO sales_frame_targets (company_id, case_type, year, month, target_first_frame) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE target_first_frame=VALUES(target_first_frame), updated_at=NOW()")->execute([$cid, $caseTypeFilter, $ty, $tm, $tv]);
    }
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
// 前年同月売上（総合ダッシュボードの月別売上テーブル用: 前年度 Sep(Y-2)〜Aug(Y-1)）
$fyPrevRevMap = [];
if (!$caseTypeFilter) {
    $fyPrevStmt = $fyDb->prepare("
        SELECT case_year, case_month, COALESCE(SUM(revenue),0) AS rev
        FROM sales_cases
        WHERE company_id = ? AND status != '終了'
          AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
        GROUP BY case_year, case_month
    ");
    $fyPrevStmt->execute([$cid, $year-2, $year-1]);
    foreach ($fyPrevStmt->fetchAll() as $r) {
        $fyPrevRevMap[$r['case_year']][$r['case_month']] = (int)$r['rev'];
    }
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
// 月別目標（常勤/イベントは各タイプ、総合はregular+eventの合計）
$fyTgtMap = [];
$_tgtType = $caseTypeFilter ?: null;
foreach (getSalesTargets($cid, $year-1) as $m => $types) {
    $fyTgtMap[$year-1][$m] = $_tgtType
        ? (int)($types[$_tgtType]['revenue_target'] ?? 0)
        : (int)($types['regular']['revenue_target'] ?? 0) + (int)($types['event']['revenue_target'] ?? 0);
}
foreach (getSalesTargets($cid, $year) as $m => $types) {
    $fyTgtMap[$year][$m] = $_tgtType
        ? (int)($types[$_tgtType]['revenue_target'] ?? 0)
        : (int)($types['regular']['revenue_target'] ?? 0) + (int)($types['event']['revenue_target'] ?? 0);
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
$inhouseTypes  = ['正社員', 'アルバイト', '自社外注', '個人外注'];
$allianceTypes = ['アライアンス'];
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

// 常勤・イベント別 自社/アライアンス 案件数（sales_casesから取得）
$_caseDetailSql = "
    SELECT case_type,
           SUM(CASE WHEN worker_type IN ('正社員','自社外注','アルバイト','個人外注') THEN 1 ELSE 0 END) AS inhouse,
           SUM(CASE WHEN worker_type IN ('アライアンス') THEN 1 ELSE 0 END) AS alliance
    FROM sales_cases
    WHERE company_id = ? AND case_year = ? AND case_month = ? AND status = 'confirmed'
    GROUP BY case_type
";
$_cdStmt = $db->prepare($_caseDetailSql);
$_cdStmt->execute([$cid, $year, $month]);
$_caseDetail = [];
foreach ($_cdStmt->fetchAll() as $_r) {
    $_caseDetail[$_r['case_type']] = ['inhouse' => (int)$_r['inhouse'], 'alliance' => (int)$_r['alliance']];
}
$regularInhouse  = $_caseDetail['regular']['inhouse']  ?? 0;
$regularAlliance = $_caseDetail['regular']['alliance'] ?? 0;
$eventInhouse    = $_caseDetail['event']['inhouse']    ?? 0;
$eventAlliance   = $_caseDetail['event']['alliance']   ?? 0;

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
// 営業マン別売上（当月）: 担当者別売上レポートと同じ50%分割で集計
$_repFySql = "
    SELECT name, SUM(revenue) AS revenue, SUM(profit) AS profit
    FROM (
        SELECT sales_rep AS name,
               FLOOR(revenue/2) AS revenue,
               FLOOR(gross_profit/2) AS profit
        FROM sales_cases
        WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
          AND case_year = ? AND case_month = ?
          $_ctf2
        UNION ALL
        SELECT CASE WHEN COALESCE(manager,'') NOT IN ('','該当者なし') THEN manager
                    WHEN COALESCE(recruiter,'') NOT IN ('','該当者なし') THEN recruiter
                    ELSE '直営業' END AS name,
               revenue - FLOOR(revenue/2) AS revenue,
               gross_profit - FLOOR(gross_profit/2) AS profit
        FROM sales_cases
        WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
          AND case_year = ? AND case_month = ?
          $_ctf2
    ) t
    WHERE name NOT IN ('直営業','','該当者なし')
    GROUP BY name ORDER BY revenue DESC";
$_s = $_sDb->prepare($_repFySql);
$_s->execute(array_merge([$cid, $year, $month], $_ctp, [$cid, $year, $month], $_ctp));
$repFyRows = $_s->fetchAll();
// 山根脩平を末尾に固定追加（売上0でも必ず表示）
$_yamaneFound = false;
foreach ($repFyRows as $_r) { if ($_r['name'] === '山根脩平') { $_yamaneFound = true; break; } }
if (!$_yamaneFound) { $repFyRows[] = ['name' => '山根脩平', 'revenue' => 0, 'profit' => 0]; }
// 直営業の月間売上を取得して最後尾に追加
$_directSql = "
    SELECT SUM(revenue - FLOOR(revenue/2)) AS revenue,
           SUM(gross_profit - FLOOR(gross_profit/2)) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
      AND case_year = ? AND case_month = ?
      AND COALESCE(manager,'') IN ('','該当者なし')
      AND COALESCE(recruiter,'') IN ('','該当者なし')
      $_ctf2";
$_ds = $_sDb->prepare($_directSql);
$_ds->execute(array_merge([$cid, $year, $month], $_ctp));
$_dr = $_ds->fetch();
$repFyRows[] = ['name' => '直営業', 'revenue' => (int)($_dr['revenue'] ?? 0), 'profit' => (int)($_dr['profit'] ?? 0)];
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

// 月別枠数目標（常勤/イベントのみ）
$frameTargetMap = [];
$frameActualMap = [];
$frameTotalMap  = [];
if ($caseTypeFilter) {
    try {
        $_ftStmt = $_sDb->prepare("SELECT year, month, target_first_frame FROM sales_frame_targets WHERE company_id=? AND case_type=? AND ((year=? AND month>=9) OR (year=? AND month<=8))");
        $_ftStmt->execute([$cid, $caseTypeFilter, $year-1, $year]);
        foreach ($_ftStmt->fetchAll() as $_r) {
            $frameTargetMap[(int)$_r['year']][(int)$_r['month']] = (int)$_r['target_first_frame'];
        }
        // 1次枠実績（case_divisionが'1次'の件数）
        $_faStmt = $_sDb->prepare("SELECT case_year, case_month, COUNT(*) AS cnt FROM sales_cases WHERE company_id=? AND case_type=? AND case_division='1次' AND status != 'cancelled' AND ((case_year=? AND case_month>=9) OR (case_year=? AND case_month<=8)) GROUP BY case_year, case_month");
        $_faStmt->execute([$cid, $caseTypeFilter, $year-1, $year]);
        foreach ($_faStmt->fetchAll() as $_r) {
            $frameActualMap[(int)$_r['case_year']][(int)$_r['case_month']] = (int)$_r['cnt'];
        }
        // 合計枠実績（全件数）
        $_fTotalStmt = $_sDb->prepare("SELECT case_year, case_month, COUNT(*) AS cnt FROM sales_cases WHERE company_id=? AND case_type=? AND status != 'cancelled' AND ((case_year=? AND case_month>=9) OR (case_year=? AND case_month<=8)) GROUP BY case_year, case_month");
        $_fTotalStmt->execute([$cid, $caseTypeFilter, $year-1, $year]);
        foreach ($_fTotalStmt->fetchAll() as $_r) {
            $frameTotalMap[(int)$_r['case_year']][(int)$_r['case_month']] = (int)$_r['cnt'];
        }
    } catch (PDOException $_e) {
        $frameTargetMap = []; $frameActualMap = []; $frameTotalMap = [];
    }
}

// 年度月別販管費合計（営業利益表示用）
$_sgaFyStmt = $_sDb->prepare("
    SELECT target_year, target_month, COALESCE(SUM(amount),0) AS sga_total
    FROM sga_expenses
    WHERE company_id = ?
      AND ((target_year = ? AND target_month >= 9) OR (target_year = ? AND target_month <= 8))
    GROUP BY target_year, target_month
");
$_sgaFyStmt->execute([$cid, $year-1, $year]);
$sgaFyMap = [];
foreach ($_sgaFyStmt->fetchAll() as $_r) {
    $sgaFyMap[$_r['target_year']][$_r['target_month']] = (int)$_r['sga_total'];
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.fy-monthly-table > :not(caption) > * > * { padding: .18rem .35rem !important; white-space: nowrap !important; vertical-align: middle !important; font-size: .72rem !important; }
.fy-monthly-table thead > * > * { font-size: .68rem !important; text-align: center !important; }
.fy-monthly-table tbody > * > td:first-child { text-align: left !important; }
.fy-monthly-table .fy-tgt-input { height: 22px !important; font-size: .7rem !important; padding: .1rem .25rem !important; }
</style>

<div class="container-fluid">
    <?php
    $prevM = $month - 1; $prevY = $year;
    if ($prevM < 1) { $prevM = 12; $prevY = $year - 1; }
    $nextM = $month + 1; $nextY = $year;
    if ($nextM > 12) { $nextM = 1; $nextY = $year + 1; }
    ?>
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-graph-up-arrow me-2"></i><?= h($pageTitle) ?></h1>
                <p><?= $year ?>年<?= $month ?>月の売上状況</p>
            </div>
            <div class="d-flex align-items-center gap-1">
                <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:120px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <?php
    // 月合計KPI用
    $monthTarget = $fyTgtMap[$year][$month] ?? 0;
    $monthAch = $monthTarget > 0 ? round($kpis['revenue'] / $monthTarget * 100, 1) : 0;
    $monthAchColor = $monthAch >= 100 ? '#059669' : ($monthAch >= 80 ? '#3b82f6' : ($monthAch >= 50 ? '#f59e0b' : '#ef4444'));
    ?>
    <!-- KPIカード -->
    <div class="d-flex justify-content-end mb-2">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary active" id="btnKpiTaxExcl" onclick="setKpiTaxMode(false)" style="font-size:.7rem;padding:2px 8px">税抜</button>
            <button type="button" class="btn btn-outline-secondary" id="btnKpiTaxIncl" onclick="setKpiTaxMode(true)" style="font-size:.7rem;padding:2px 8px">税込</button>
        </div>
    </div>
    <div class="row g-2 mb-4">
        <!-- 売上目標（月） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#6366f1" data-kpi-tax data-raw="<?= $monthTarget ?>"><?= number_format($monthTarget) ?></div>
                <div class="kpi-label">売上目標</div>
                <div class="kpi-sub"><?= $year ?>年<?= $month ?>月</div>
            </div>
        </div>
        <!-- 売上（月） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#059669" data-kpi-tax data-raw="<?= $kpis['revenue'] ?>"><?= number_format($kpis['revenue']) ?></div>
                <div class="kpi-label">売上</div>
                <div class="kpi-sub"><?= $year ?>年<?= $month ?>月</div>
            </div>
        </div>
        <!-- 粗利（月） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:#3b82f6" data-kpi-tax data-raw="<?= $kpis['profit'] ?>"><?= number_format($kpis['profit']) ?></div>
                <div class="kpi-label">粗利</div>
                <div class="kpi-sub">粗利率: <?= $kpis['margin'] ?>%</div>
            </div>
        </div>
        <?php if (!$caseTypeFilter):
            $_kpiSga      = $sgaFyMap[$year][$month] ?? 0;
            $_kpiOpIncome = $kpis['profit'] - $_kpiSga;
            $_kpiOpMargin = $kpis['revenue'] > 0 ? round($_kpiOpIncome / $kpis['revenue'] * 100, 1) : null;
            $_kpiOpColor  = $_kpiOpIncome >= 0 ? '#8b5cf6' : '#dc2626';
        ?>
        <!-- 営業利益（月・総合ダッシュボードのみ） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:<?= $_kpiOpColor ?>" data-kpi-tax data-raw="<?= $_kpiOpIncome ?>"><?= number_format($_kpiOpIncome) ?></div>
                <div class="kpi-label">営業利益</div>
                <div class="kpi-sub">営業利益率: <?= $_kpiOpMargin !== null ? $_kpiOpMargin . '%' : '-' ?></div>
            </div>
        </div>
        <?php endif; ?>
        <!-- 目標達成率（月） -->
        <div class="col-6 col-md">
            <div class="sales-kpi">
                <div class="kpi-value" style="color:<?= $monthAchColor ?>"><?= $monthAch ?>%</div>
                <div class="kpi-label">目標達成率</div>
                <div class="kpi-sub"><?= $monthAch >= 100 ? '達成' : ($monthAch >= 80 ? 'もう少し' : ($monthAch >= 50 ? '進行中' : '要注意')) ?></div>
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

    <!-- 売上推移チャート（全幅）→ KPI直下に移動 -->
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

    <!-- 年度月別売上テーブル→ 売上推移の下に移動 -->
    <input type="hidden" id="fycsrf" value="<?= h(getCsrfToken()) ?>">
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-1" style="color:#6366f1"></i><?= $year-1 ?>-<?= $year ?>年度 月別売上（9月〜8月）</span>
            <?php if ($caseTypeFilter): ?>
            <small class="text-muted">売上目標は直接入力で保存されます</small>
            <?php else: ?>
            <small class="text-muted">売上目標は常勤・イベントダッシュボードで入力してください</small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 fy-monthly-table" style="min-width:700px">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:56px;width:56px">項目</th>
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
                        $fyTotalSga = 0;
                        foreach ($fyMonths as $i => $fm) {
                            $rev       = $fyRevMap[$fm['y']][$fm['m']]['rev']    ?? 0;
                            $profit    = $fyRevMap[$fm['y']][$fm['m']]['profit'] ?? 0;
                            $tgt       = $fyTgtMap[$fm['y']][$fm['m']]          ?? 0;
                            $margin    = $rev > 0 ? round($profit / $rev * 100, 1) : null;
                            $ach       = ($tgt > 0) ? round($rev / $tgt * 100, 1) : null;
                            $sga_total = $sgaFyMap[$fm['y']][$fm['m']] ?? 0;
                            $op_income = $profit - $sga_total;
                            $op_margin = $rev > 0 ? round($op_income / $rev * 100, 1) : null;
                            $fyTotalSga += $sga_total;
                            $fyRowData[$i] = compact('rev','profit','tgt','margin','ach','sga_total','op_income','op_margin');
                        }
                        $fyTotalOpIncome = $fyTotalProfit - $fyTotalSga;
                        $fyTotalOpMargin = $fyTotalRev > 0 ? round($fyTotalOpIncome / $fyTotalRev * 100, 1) : null;
                        ?>
                        <!-- 売上目標（常勤/イベントは入力可、総合は常勤+イベントの合計を読み取り専用） -->
                        <tr>
                            <td class="fw-semibold fy-label" style="color:#6366f1">売上目標</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="p-0">
                                <?php if ($caseTypeFilter): ?>
                                <input type="text" class="fy-tgt-input form-control form-control-sm border-0 text-end px-1"
                                       style="min-width:60px;background:transparent"
                                       data-year="<?= $fm['y'] ?>" data-month="<?= $fm['m'] ?>"
                                       value="<?= $d['tgt'] > 0 ? number_format($d['tgt']) : '' ?>"
                                       placeholder="0">
                                <?php else: ?>
                                <div class="text-end px-1 text-muted" style="min-width:60px;font-size:.72rem;line-height:1.6"><?= $d['tgt'] > 0 ? number_format($d['tgt']) : '-' ?></div>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary" id="fyTgtTotal"><?= $fyTotalTarget > 0 ? number_format($fyTotalTarget) : '-' ?></td>
                        </tr>
                        <!-- 売上 -->
                        <tr>
                            <td class="fw-semibold fy-label" style="color:#059669">売上</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end <?= $d['rev'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $d['rev'] > 0 ? number_format($d['rev']) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary"><?= $fyTotalRev > 0 ? number_format($fyTotalRev) : '-' ?></td>
                        </tr>
                        <!-- 粗利 -->
                        <tr>
                            <td class="fw-semibold fy-label" style="color:#3b82f6">粗利</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end"><?= $d['profit'] > 0 ? number_format($d['profit']) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary"><?= $fyTotalProfit > 0 ? number_format($fyTotalProfit) : '-' ?></td>
                        </tr>
                        <?php if (!$caseTypeFilter): ?>
                        <!-- 営業利益（総合ダッシュボードのみ） -->
                        <tr>
                            <td class="fw-semibold fy-label" style="color:#8b5cf6">営業利益</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i];
                                $hasOp = $d['rev'] > 0 || $d['profit'] > 0 || $d['sga_total'] > 0;
                                $opStyle = $hasOp ? ($d['op_income'] >= 0 ? 'color:#8b5cf6' : 'color:#dc2626') : '';
                            ?>
                            <td class="text-end" style="<?= $opStyle ?>"><?= $hasOp ? number_format($d['op_income']) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary" style="<?= $fyTotalRev > 0 || $fyTotalSga > 0 ? ($fyTotalOpIncome >= 0 ? 'color:#8b5cf6' : 'color:#dc2626') : '' ?>"><?= ($fyTotalRev > 0 || $fyTotalSga > 0) ? number_format($fyTotalOpIncome) : '-' ?></td>
                        </tr>
                        <?php endif; ?>
                        <!-- 粗利率 -->
                        <tr class="table-light">
                            <td class="text-muted fy-label">粗利率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end text-muted"><?= $d['margin'] !== null ? $d['margin'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary text-muted"><?= $fyMargin > 0 ? $fyMargin . '%' : '-' ?></td>
                        </tr>
                        <?php if (!$caseTypeFilter): ?>
                        <!-- 営業利益率（総合ダッシュボードのみ） -->
                        <tr class="table-light">
                            <td class="text-muted fy-label">営業利益率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i]; ?>
                            <td class="text-end text-muted"><?= $d['op_margin'] !== null ? $d['op_margin'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary text-muted"><?= $fyTotalOpMargin !== null ? $fyTotalOpMargin . '%' : '-' ?></td>
                        </tr>
                        <?php endif; ?>
                        <!-- 達成率 -->
                        <tr>
                            <td class="fw-semibold fy-label">達成率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i];
                                $achCls = $d['ach'] === null ? 'text-muted' : ($d['ach'] >= 100 ? 'text-success' : ($d['ach'] >= 80 ? 'text-primary' : ($d['ach'] >= 50 ? 'text-warning' : 'text-danger')));
                            ?>
                            <td class="text-end <?= $achCls ?> fw-semibold"><?= $d['ach'] !== null ? $d['ach'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary <?= $fyAch >= 100 ? 'text-success' : ($fyAch >= 80 ? 'text-primary' : ($fyAch >= 50 ? 'text-warning' : ($fyAch > 0 ? 'text-danger' : 'text-muted'))) ?>"><?= $fyAch > 0 ? $fyAch . '%' : '-' ?></td>
                        </tr>
                        <?php if (!$caseTypeFilter): ?>
                        <?php
                        // 前年同月売上・前年同月比（総合ダッシュボードのみ）
                        $fyTotalPrevRev = 0;
                        foreach ($fyMonths as $fm) {
                            $fyTotalPrevRev += $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0;
                        }
                        $fyTotalYoy = ($fyTotalPrevRev > 0 && $fyTotalRev > 0) ? round($fyTotalRev / $fyTotalPrevRev * 100, 1) : null;
                        ?>
                        <!-- 前年同月売上 -->
                        <tr>
                            <td class="fw-semibold fy-label">前年同月売上</td>
                            <?php foreach ($fyMonths as $i => $fm): $prevRev = $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0; ?>
                            <td class="text-end <?= $prevRev > 0 ? '' : 'text-muted' ?>"><?= $prevRev > 0 ? number_format($prevRev) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold table-secondary"><?= $fyTotalPrevRev > 0 ? number_format($fyTotalPrevRev) : '-' ?></td>
                        </tr>
                        <!-- 前年同月比 -->
                        <tr class="table-light">
                            <td class="text-muted fy-label">前年同月比</td>
                            <?php foreach ($fyMonths as $i => $fm):
                                $d = $fyRowData[$i];
                                $prevRev = $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0;
                                $yoy = ($prevRev > 0 && $d['rev'] > 0) ? round($d['rev'] / $prevRev * 100, 1) : null;
                            ?>
                            <td class="text-end text-muted"><?= $yoy !== null ? $yoy . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary text-muted"><?= $fyTotalYoy !== null ? $fyTotalYoy . '%' : '-' ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($caseTypeFilter): ?>
    <!-- 月別枠数テーブル（常勤/イベントのみ） -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-grid-3x3-gap me-1" style="color:#8b5cf6"></i>月別枠数</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 fy-monthly-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:56px;width:56px">項目</th>
                                    <?php foreach ($fyMonths as $fm): ?>
                                    <th class="text-center"><?= $fm['m'] ?>月</th>
                                    <?php endforeach; ?>
                                    <th class="text-center table-secondary">合計</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- 目標1次枠数（手打ち） -->
                                <tr>
                                    <td class="fy-label">目標1次枠数</td>
                                    <?php
                                    $fyFrameTgtTotal = 0;
                                    foreach ($fyMonths as $fm):
                                        $ftv = $frameTargetMap[$fm['y']][$fm['m']] ?? 0;
                                        $fyFrameTgtTotal += $ftv;
                                    ?>
                                    <td class="p-0">
                                        <input type="number" min="0" class="fy-tgt-input fy-frame-tgt-input form-control form-control-sm border-0 text-center px-1"
                                            style="min-width:52px;background:transparent"
                                            value="<?= $ftv ?: '' ?>"
                                            data-year="<?= $fm['y'] ?>"
                                            data-month="<?= $fm['m'] ?>"
                                            placeholder="0">
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold" id="fyFrameTgtTotal"><?= $fyFrameTgtTotal ?></td>
                                </tr>
                                <!-- 1次枠数（自動集計） -->
                                <tr>
                                    <td class="fy-label">1次枠数</td>
                                    <?php
                                    $fyFirstFrameTotal = 0;
                                    foreach ($fyMonths as $fm):
                                        $ffv = $frameActualMap[$fm['y']][$fm['m']] ?? 0;
                                        $fyFirstFrameTotal += $ffv;
                                    ?>
                                    <td class="text-center"><?= $ffv ?: '-' ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold"><?= $fyFirstFrameTotal ?: '-' ?></td>
                                </tr>
                                <!-- 合計枠数（自動集計） -->
                                <tr>
                                    <td class="fy-label">合計枠数</td>
                                    <?php
                                    $fyTotalFrameTotal = 0;
                                    foreach ($fyMonths as $fm):
                                        $ftotal = $frameTotalMap[$fm['y']][$fm['m']] ?? 0;
                                        $fyTotalFrameTotal += $ftotal;
                                    ?>
                                    <td class="text-center"><?= $ftotal ?: '-' ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold"><?= $fyTotalFrameTotal ?: '-' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 集計カード 上段: キャリア別売上（左）+ 営業マン別売上（右） -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-phone me-1" style="color:#06b6d4"></i>キャリア別売上 <small class="text-muted ms-1">TOP3</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'carrierPieWrap','carrierFyTableWrap')" data-pie="0">詳細</button>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($carrierFyRows) > 3): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'carrierFyTable')" data-expanded="0">▼</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="carrierFyTableWrap">
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
                    <div id="carrierPieWrap" style="display:none" class="p-3" data-cardkey="carrier">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('carrier',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('carrier',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="carrierPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="carrierPieChart"></canvas></div>
                            </div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0" style="font-size:.72rem">
                                        <thead class="table-light"><tr><th>順位</th><th>キャリア</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="carrierPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePieView(this,'carrierPieWrap','carrierFyTableWrap')">
                                <i class="bi bi-list-ul me-1"></i>一覧へ戻る
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-badge me-1" style="color:#f59e0b"></i>営業マン別売上 <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月 TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'repPieWrap','repFyTableWrap')" data-pie="0">詳細</button>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($repFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'repFyTable')" data-expanded="0">▼</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="repFyTableWrap">
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
                    <div id="repPieWrap" style="display:none" class="p-3" data-cardkey="rep">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('rep',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('rep',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="repPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="repPieChart"></canvas></div>
                            </div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0" style="font-size:.72rem">
                                        <thead class="table-light"><tr><th>順位</th><th>営業マン</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="repPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePieView(this,'repPieWrap','repFyTableWrap')">
                                <i class="bi bi-list-ul me-1"></i>一覧へ戻る
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 集計カード 下段: クライアント別売上（左）+ アライアンス別売上（右） -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-1" style="color:#6366f1"></i>クライアント別売上 <small class="text-muted ms-1">TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'clientPieWrap','clientFyTableWrap')" data-pie="0">詳細</button>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($clientFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'clientFyTable')" data-expanded="0">▼</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="clientFyTableWrap">
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
                    <div id="clientPieWrap" style="display:none" class="p-3" data-cardkey="client">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('client',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('client',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="clientPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="clientPieChart"></canvas></div>
                            </div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0" style="font-size:.72rem">
                                        <thead class="table-light"><tr><th>順位</th><th>会社名</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="clientPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePieView(this,'clientPieWrap','clientFyTableWrap')">
                                <i class="bi bi-list-ul me-1"></i>一覧へ戻る
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-1" style="color:#059669"></i>アライアンス別売上 <small class="text-muted ms-1">TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'alliancePieWrap','allianceFyTableWrap')" data-pie="0">詳細</button>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active summary-tax-excl" onclick="setSummaryTaxMode(false,this)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary summary-tax-incl" onclick="setSummaryTaxMode(true,this)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
                        <?php if (count($allianceFyRows) > 5): ?>
                        <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:2px 8px" onclick="toggleExpand(this,'allianceFyTable')" data-expanded="0">▼</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="allianceFyTableWrap">
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
                    <div id="alliancePieWrap" style="display:none" class="p-3" data-cardkey="alliance">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('alliance',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('alliance',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="alliancePieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="alliancePieChart"></canvas></div>
                            </div>
                            <div class="col-md-7">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0" style="font-size:.72rem">
                                        <thead class="table-light"><tr><th>順位</th><th>会社名</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="alliancePieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closePieView(this,'alliancePieWrap','allianceFyTableWrap')">
                                <i class="bi bi-list-ul me-1"></i>一覧へ戻る
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- スタッフ分析（全幅）→ 売上ランキング表の下に移動 -->
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
                    <div class="row g-3 align-items-start justify-content-center">
                        <!-- ① 区分別売上（既存） -->
                        <div class="col-md-4 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem;font-weight:600">区分別売上</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="workerChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:2">
                                <span style="color:#3b82f6">●</span> 自社 <strong><?= (int)$workerGrouped['自社']['case_count'] ?>件</strong>
                                &nbsp;&nbsp;
                                <span style="color:#059669">●</span> アライアンス <strong><?= (int)$workerGrouped['アライアンス']['case_count'] ?>件</strong>
                            </div>
                        </div>
                        <!-- ② 常勤人数構成 -->
                        <div class="col-md-4 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem;font-weight:600">常勤人数構成</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="regularStaffChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:2">
                                <span style="color:#3b82f6">●</span> 自社 <strong><?= $regularInhouse ?>名</strong>
                                &nbsp;&nbsp;
                                <span style="color:#059669">●</span> アライアンス <strong><?= $regularAlliance ?>名</strong>
                            </div>
                        </div>
                        <!-- ③ イベント人数構成 -->
                        <div class="col-md-4 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem;font-weight:600">イベント人数構成</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="eventStaffChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:2">
                                <span style="color:#3b82f6">●</span> 自社 <strong><?= $eventInhouse ?>名</strong>
                                &nbsp;&nbsp;
                                <span style="color:#059669">●</span> アライアンス <strong><?= $eventAlliance ?>名</strong>
                            </div>
                        </div>
                    </div>
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
// 常勤・イベント人数構成チャート（staffPieChartは削除、新2チャートに置換）
$_pieLabels = ['自社', 'アライアンス'];
$_pieColors = ['#3b82f6', '#059669'];
$inlineJs .= 'salesDrawDonutChart("regularStaffChart", ' . json_encode($_pieLabels) . ', ' . json_encode([$regularInhouse, $regularAlliance]) . ', ' . json_encode($_pieColors) . ');';
$inlineJs .= 'salesDrawDonutChart("eventStaffChart", '   . json_encode($_pieLabels) . ', ' . json_encode([$eventInhouse,   $eventAlliance])   . ', ' . json_encode($_pieColors) . ');';
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
        // [0]=目標(bar) [1]=売上(bar) [2]=粗利(bar) [3]=達成率(line・%値→変化なし)
        ds[0].data = idxs.map(i => Math.round((trendTargets[i]          || 0) * rate));
        ds[1].data = idxs.map(i => Math.round((trendRawData[i]?.revenue || 0) * rate));
        ds[2].data = idxs.map(i => Math.round((trendRawData[i]?.profit  || 0) * rate));
        // ds[3]=達成率は率なので変化なし
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

$inlineJs .= <<<'FRAMEJS'
// 月別枠数目標: 自動保存
(function() {
    const inputs = document.querySelectorAll('.fy-frame-tgt-input');
    if (!inputs.length) return;
    const csrf = document.getElementById('fycsrf') ? document.getElementById('fycsrf').value : '';
    const saveUrl = window.location.pathname;
    function recalcTgtTotal() {
        let sum = 0;
        inputs.forEach(inp => { sum += parseInt(inp.value) || 0; });
        const el = document.getElementById('fyFrameTgtTotal');
        if (el) el.textContent = sum || 0;
    }
    inputs.forEach(function(inp) {
        inp.addEventListener('change', function() {
            const yr = inp.dataset.year;
            const mo = inp.dataset.month;
            const val = Math.max(0, parseInt(inp.value) || 0);
            inp.value = val;
            recalcTgtTotal();
            const fd = new FormData();
            fd.append('action', 'save_frame_target');
            fd.append('csrf', csrf);
            fd.append('t_year', yr);
            fd.append('t_month', mo);
            fd.append('t_value', val);
            fetch(saveUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .catch(() => {});
        });
    });
})();
FRAMEJS;

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
    // 詳細ビュー（円グラフ）が表示中の場合もリアルタイム更新
    var pieWrap = scope.querySelector('[data-cardkey]');
    if (pieWrap && pieWrap.style.display !== 'none') {
        var cardkey = pieWrap.dataset.cardkey;
        if (cardkey && typeof drawRankPieCharts === 'function') {
            drawRankPieCharts(cardkey, incl);
        }
    }
}
SUMMARYJS;

$inlineJs .= <<<'EXPANDJS'
function toggleExpand(btn, tableId) {
    var table = document.getElementById(tableId);
    var extras = table.querySelectorAll('.extra-row');
    var expanded = btn.dataset.expanded === '1';
    extras.forEach(function(r) { r.style.display = expanded ? 'none' : ''; });
    btn.innerHTML = expanded ? '▼' : '▲';
    btn.dataset.expanded = expanded ? '0' : '1';
}
EXPANDJS;

$inlineJs .= 'const RANK_PIE_DATA={'
    . '"carrier":' . json_encode(array_values($carrierFyRows), JSON_UNESCAPED_UNICODE) . ','
    . '"rep":'     . json_encode(array_values($repFyRows),     JSON_UNESCAPED_UNICODE) . ','
    . '"client":'  . json_encode(array_values($clientFyRows),  JSON_UNESCAPED_UNICODE) . ','
    . '"alliance":'. json_encode(array_values($allianceFyRows),JSON_UNESCAPED_UNICODE)
    . '};';
$inlineJs .= <<<'RANKPIEJS'
const PIE_CHART_COLORS=['#3b82f6','#059669','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#a855f7'];
var _rankPieInsts={};

function togglePieView(btn,pieWrapId,tableWrapId){
    var pieWrap=document.getElementById(pieWrapId);
    var tableWrap=document.getElementById(tableWrapId);
    if(!pieWrap)return;
    var showing=btn.dataset.pie==='1';
    if(showing){
        pieWrap.style.display='none';
        if(tableWrap)tableWrap.style.display='';
        btn.dataset.pie='0';
        btn.classList.remove('btn-info');
        btn.classList.add('btn-outline-info');
    }else{
        if(tableWrap)tableWrap.style.display='none';
        pieWrap.style.display='';
        btn.dataset.pie='1';
        btn.classList.remove('btn-outline-info');
        btn.classList.add('btn-info');
        var cardkey=pieWrap.dataset.cardkey;
        var card=btn.closest?btn.closest('.card'):null;
        var taxIncl=card?card.querySelector('.summary-tax-incl.active')!==null:false;
        drawRankPieCharts(cardkey,taxIncl);
    }
}

function closePieView(backBtn,pieWrapId,tableWrapId){
    var pieWrap=document.getElementById(pieWrapId);
    var tableWrap=document.getElementById(tableWrapId);
    if(!pieWrap)return;
    pieWrap.style.display='none';
    if(tableWrap)tableWrap.style.display='';
    var card=backBtn.closest?backBtn.closest('.card'):null;
    if(card){
        var detailBtn=card.querySelector('[data-pie]');
        if(detailBtn){
            detailBtn.dataset.pie='0';
            detailBtn.classList.remove('btn-info');
            detailBtn.classList.add('btn-outline-info');
        }
    }
}

var _pieMetric={}; // cardkey → 'revenue' | 'profit'

function _pieEsc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// 売上/粗利トグル
function setPieMetric(cardkey,isProfit,btn){
    _pieMetric[cardkey]=isProfit?'profit':'revenue';
    var wrap=document.querySelector('[data-cardkey="'+cardkey+'"]');
    if(wrap){
        wrap.querySelectorAll('.pie-metric-rev').forEach(function(b){b.classList.toggle('active',!isProfit);});
        wrap.querySelectorAll('.pie-metric-profit').forEach(function(b){b.classList.toggle('active',isProfit);});
    }
    var card=(btn&&btn.closest)?btn.closest('.card'):null;
    var taxIncl=card?card.querySelector('.summary-tax-incl.active')!==null:false;
    drawRankPieCharts(cardkey,taxIncl);
}

function drawRankPieCharts(cardkey,taxIncl){
    var data=RANK_PIE_DATA[cardkey];
    if(!data||!data.length)return;
    var rate=taxIncl?1.1:1.0;
    var metric=_pieMetric[cardkey]||'revenue';
    var rows=data.map(function(r){
        return{name:r.name,revenue:Math.round((parseInt(r.revenue)||0)*rate),profit:Math.round((parseInt(r.profit)||0)*rate)};
    });

    // ランキング（全カード共通: TOP5 + 6位以下は「その他」に集約）
    var top=rows.slice(0,5), rest=rows.slice(5);
    var rankRows=top.slice();
    if(rest.length){
        var oRev=0,oPro=0;
        rest.forEach(function(r){oRev+=r.revenue;oPro+=r.profit;});
        rankRows.push({name:'その他',revenue:oRev,profit:oPro,isOther:true});
    }
    var tbody=document.getElementById(cardkey+'PieRank');
    if(tbody){
        var html='';
        rankRows.forEach(function(r,i){
            var margin=r.revenue>0?(r.profit/r.revenue*100).toFixed(1)+'%':'-';
            html+='<tr'+(r.isOther?' class="text-muted"':'')+'>';
            html+='<td>'+(r.isOther?'-':(i+1))+'</td>';
            html+='<td>'+_pieEsc(r.name)+'</td>';
            html+='<td class="text-end">'+r.revenue.toLocaleString()+'</td>';
            html+='<td class="text-end" style="color:#3b82f6">'+r.profit.toLocaleString()+'</td>';
            html+='<td class="text-end">'+margin+'</td>';
            html+='</tr>';
        });
        tbody.innerHTML=html;
    }

    // 円グラフ: クライアント/アライアンスはTOP5+その他、キャリア/営業マンは全件
    var chartRows=(cardkey==='client'||cardkey==='alliance')?rankRows:rows;
    var labels=chartRows.map(function(r){return r.name;});
    var vals  =chartRows.map(function(r){return metric==='profit'?r.profit:r.revenue;});
    var colors=chartRows.map(function(r,i){return r.isOther?'#9ca3af':PIE_CHART_COLORS[i%PIE_CHART_COLORS.length];});

    // タイトル切替
    var titleEl=document.getElementById(cardkey+'PieTitle');
    if(titleEl){
        titleEl.textContent=metric==='profit'?'粗利割合':'売上割合';
        titleEl.style.color=metric==='profit'?'#3b82f6':'#059669';
    }

    var canvas=document.getElementById(cardkey+'PieChart');
    if(!canvas)return;
    if(_rankPieInsts[cardkey]){
        _rankPieInsts[cardkey].data.labels=labels;
        _rankPieInsts[cardkey].data.datasets[0].data=vals;
        _rankPieInsts[cardkey].data.datasets[0].backgroundColor=colors;
        _rankPieInsts[cardkey].update();
        return;
    }
    _rankPieInsts[cardkey]=new Chart(canvas,{
        type:'doughnut',
        data:{labels:labels,datasets:[{data:vals,backgroundColor:colors,borderWidth:1,borderColor:'#fff'}]},
        options:{
            responsive:true,maintainAspectRatio:false,
            plugins:{
                legend:{position:'bottom',labels:{font:{size:10},padding:5,boxWidth:10,
                    generateLabels:function(chart){
                        var ds=chart.data.datasets[0];
                        var total=ds.data.reduce(function(a,b){return a+b;},0);
                        return chart.data.labels.map(function(lbl,i){
                            var pct=total>0?Math.round((ds.data[i]||0)/total*100):0;
                            return{text:lbl+' '+pct+'%',fillStyle:ds.backgroundColor[i],strokeStyle:'#fff',lineWidth:1,hidden:false,index:i};
                        });
                    }
                }},
                tooltip:{callbacks:{label:function(ctx){
                    var total=ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                    var pct=total>0?Math.round(ctx.raw/total*100):0;
                    return ctx.label+': '+ctx.raw.toLocaleString()+'円 ('+pct+'%)';
                }}}
            }
        }
    });
}
RANKPIEJS;

require_once __DIR__ . '/../includes/footer.php';
?>
