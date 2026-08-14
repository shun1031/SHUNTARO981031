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
    // frame_type: first=目標1次 / second=目標二次以降
    $frameCol = ($_POST['frame_type'] ?? 'first') === 'second' ? 'target_second_frame' : 'target_first_frame';
    if ($ty && $tm) {
        $db = getDB();
        try { $db->exec("CREATE TABLE IF NOT EXISTS sales_frame_targets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, case_type VARCHAR(20) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, target_first_frame INT NOT NULL DEFAULT 0, target_second_frame INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sft (company_id, case_type, year, month), INDEX idx_sft_company (company_id, case_type, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
        try { $db->exec("ALTER TABLE sales_frame_targets ADD COLUMN target_second_frame INT NOT NULL DEFAULT 0 AFTER target_first_frame"); } catch (PDOException $e) {}
        $db->prepare("INSERT INTO sales_frame_targets (company_id, case_type, year, month, `{$frameCol}`) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE `{$frameCol}`=VALUES(`{$frameCol}`), updated_at=NOW()")->execute([$cid, $caseTypeFilter, $ty, $tm, $tv]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── 年度データ（9月始まり）: year Y = Sep(Y-1)〜Aug(Y) ──
// 売上推移チャート・月別売上テーブル・月別枠数は、?fy= で年度を明示指定できる。
// 未指定のときは月ナビの年月から年度を判定する（9〜12月は翌年度になる）
$_fyDefault = $month >= 9 ? $year + 1 : $year;
$fyYear = (int)($_GET['fy'] ?? $_fyDefault);
if ($fyYear < 2000 || $fyYear > 2100) $fyYear = $_fyDefault;
// 年度切替のAJAX（該当2セクションのみを描画して返す）
$FY_ONLY = ($_GET['fy_only'] ?? '') === '1';
if ($FY_ONLY) { ob_start(); } // ヘッダー等の出力を捨てるための外側バッファ

$fyMonths = [
    ['y' => $fyYear-1, 'm' => 9],  ['y' => $fyYear-1, 'm' => 10],
    ['y' => $fyYear-1, 'm' => 11], ['y' => $fyYear-1, 'm' => 12],
    ['y' => $fyYear,   'm' => 1],  ['y' => $fyYear,   'm' => 2],
    ['y' => $fyYear,   'm' => 3],  ['y' => $fyYear,   'm' => 4],
    ['y' => $fyYear,   'm' => 5],  ['y' => $fyYear,   'm' => 6],
    ['y' => $fyYear,   'm' => 7],  ['y' => $fyYear,   'm' => 8],
];
// 月別枠数テーブルも年度切替に連動させる（売上推移・年度月別売上と同じ年度）
$fyMonthsFrame = $fyMonths;
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
$_fyParams = $caseTypeFilter ? [$cid, $caseTypeFilter, $fyYear-1, $fyYear] : [$cid, $fyYear-1, $fyYear];
$fyCasesStmt->execute($_fyParams);
$fyRevMap = [];
foreach ($fyCasesStmt->fetchAll() as $r) {
    $fyRevMap[$r['case_year']][$r['case_month']] = ['rev' => (int)$r['rev'], 'profit' => (int)$r['profit']];
}
// 案件データが無い月は、手入力の実績（売上・粗利）で補完する
// （総合は常勤+イベントの合計。案件がある月は案件データを優先）
$manualActualMap = [];
try {
    $_maStmt = $fyDb->prepare("
        SELECT year, month, SUM(revenue) AS rev, SUM(profit) AS profit
        FROM sales_prev_year_revenues
        WHERE company_id = ?" . ($caseTypeFilter ? " AND case_type = ?" : "") . "
          AND ((year = ? AND month >= 9) OR (year = ? AND month <= 8))
        GROUP BY year, month
    ");
    $_maStmt->execute($caseTypeFilter ? [$cid, $caseTypeFilter, $fyYear-1, $fyYear] : [$cid, $fyYear-1, $fyYear]);
    foreach ($_maStmt->fetchAll() as $r) {
        $_y = (int)$r['year']; $_m = (int)$r['month'];
        $manualActualMap[$_y][$_m] = ['rev' => (int)$r['rev'], 'profit' => (int)$r['profit']];
        if (empty($fyRevMap[$_y][$_m]['rev'])) {
            $fyRevMap[$_y][$_m] = ['rev' => (int)$r['rev'], 'profit' => (int)$r['profit']];
        }
    }
} catch (PDOException $e) { /* カラム未追加時は無視 */ }
// 前年同月売上（前年度 Sep(Y-2)〜Aug(Y-1)）
// 各ダッシュボードで表示中の売上と同じ絞り込み（常勤/イベント/総合）を適用し整合させる
$fyPrevRevMap = [];
$fyPrevStmt = $fyDb->prepare("
    SELECT case_year, case_month, COALESCE(SUM(revenue),0) AS rev
    FROM sales_cases
    WHERE company_id = ? AND status != '終了'
      $_fyTypeWhere
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
    GROUP BY case_year, case_month
");
$_fyPrevParams = $caseTypeFilter ? [$cid, $caseTypeFilter, $fyYear-2, $fyYear-1] : [$cid, $fyYear-2, $fyYear-1];
$fyPrevStmt->execute($_fyPrevParams);
foreach ($fyPrevStmt->fetchAll() as $r) {
    $fyPrevRevMap[$r['case_year']][$r['case_month']] = (int)$r['rev'];
}
// 案件データが無い過去月は、手入力の前年実績で補完する
// （総合は常勤+イベントの合計。案件がある月は案件データを優先）
try {
    $_pyStmt = $fyDb->prepare("
        SELECT year, month, SUM(revenue) AS rev
        FROM sales_prev_year_revenues
        WHERE company_id = ?" . ($caseTypeFilter ? " AND case_type = ?" : "") . "
          AND ((year = ? AND month >= 9) OR (year = ? AND month <= 8))
        GROUP BY year, month
    ");
    $_pyStmt->execute($caseTypeFilter ? [$cid, $caseTypeFilter, $fyYear-2, $fyYear-1] : [$cid, $fyYear-2, $fyYear-1]);
    foreach ($_pyStmt->fetchAll() as $r) {
        $_y = (int)$r['year']; $_m = (int)$r['month'];
        if (empty($fyPrevRevMap[$_y][$_m])) { $fyPrevRevMap[$_y][$_m] = (int)$r['rev']; }
    }
} catch (PDOException $e) { /* テーブル未作成時は無視 */ }
// 月別売上・粗利（常勤/イベント別）
$fyTypeStmt = $fyDb->prepare("
    SELECT case_year, case_month, case_type,
           COALESCE(SUM(revenue),0) AS rev, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status != '終了'
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
    GROUP BY case_year, case_month, case_type
");
$fyTypeStmt->execute([$cid, $fyYear-1, $fyYear]);
$fyTypeRevMap = [];
foreach ($fyTypeStmt->fetchAll() as $r) {
    $fyTypeRevMap[$r['case_year']][$r['case_month']][$r['case_type']] = [
        'rev' => (int)$r['rev'], 'profit' => (int)$r['profit'],
    ];
}
// 常勤/イベント別も、案件データが無い月は手入力の実績で補完（グラフの内訳用）
try {
    $_mtStmt = $fyDb->prepare("
        SELECT year, month, case_type, revenue, profit
        FROM sales_prev_year_revenues
        WHERE company_id = ? AND ((year = ? AND month >= 9) OR (year = ? AND month <= 8))
    ");
    $_mtStmt->execute([$cid, $fyYear-1, $fyYear]);
    foreach ($_mtStmt->fetchAll() as $r) {
        $_y = (int)$r['year']; $_m = (int)$r['month']; $_t = $r['case_type'];
        if (empty($fyTypeRevMap[$_y][$_m][$_t]['rev'])) {
            $fyTypeRevMap[$_y][$_m][$_t] = ['rev' => (int)$r['revenue'], 'profit' => (int)$r['profit']];
        }
    }
} catch (PDOException $e) { /* カラム未追加時は無視 */ }
// 月別目標（常勤/イベントは各タイプ、総合はregular+eventの合計）
$fyTgtMap = [];
$_tgtType = $caseTypeFilter ?: null;
foreach (getSalesTargets($cid, $fyYear-1) as $m => $types) {
    $fyTgtMap[$fyYear-1][$m] = $_tgtType
        ? (int)($types[$_tgtType]['revenue_target'] ?? 0)
        : (int)($types['regular']['revenue_target'] ?? 0) + (int)($types['event']['revenue_target'] ?? 0);
}
foreach (getSalesTargets($cid, $fyYear) as $m => $types) {
    $fyTgtMap[$fyYear][$m] = $_tgtType
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

// 前年同月比の基準値 = 今期の売上目標合計 ÷ 前期の売上目標合計
// （前期の目標が未入力なら算出せず、青は使わない）
$fyPrevTotalTarget = 0;
foreach ([$fyYear-2, $fyYear-1] as $_ty) {
    foreach (getSalesTargets($cid, $_ty) as $m => $types) {
        // 前年度の範囲（Sep(fyYear-2)〜Aug(fyYear-1)）だけを合算する
        if ($_ty === $fyYear-2 && $m < 9) continue;
        if ($_ty === $fyYear-1 && $m > 8) continue;
        $fyPrevTotalTarget += $_tgtType
            ? (int)($types[$_tgtType]['revenue_target'] ?? 0)
            : (int)($types['regular']['revenue_target'] ?? 0) + (int)($types['event']['revenue_target'] ?? 0);
    }
}
$fyYoyBase = ($fyTotalTarget > 0 && $fyPrevTotalTarget > 0)
    ? round($fyTotalTarget / $fyPrevTotalTarget * 100, 1) : null;

/** 前年同月比の色: 基準値以上=青 / 100%以上=緑 / 100%未満=赤 */
function fyYoyClass(?float $yoy, ?float $base): string {
    if ($yoy === null) return 'text-muted';
    if ($base !== null && $yoy >= $base) return 'text-primary';
    return $yoy >= 100 ? 'text-success' : 'text-danger';
}
// チャート用データ（年度順: 9月→8月 = インデックス1〜12）※年度切替AJAXでも使うため先に算出
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

$fyMargin = $fyTotalRev > 0 ? round($fyTotalProfit / $fyTotalRev * 100, 1) : 0;
$fyAch    = $fyTotalTarget > 0 ? round($fyTotalRev / $fyTotalTarget * 100, 1) : 0;
$fyAchColor = $fyAch >= 100 ? '#3b82f6' : '#ef4444';

$kpis = getSalesDashboardKPIsFiltered($cid, $year, $month, $salesRep, $caseTypeFilter);
// 前年同月に案件データが無い場合は、手入力の前年実績でKPIカードを補完する
if (empty($kpis['prev_year_revenue'])) {
    try {
        $_kStmt = getDB()->prepare("SELECT COALESCE(SUM(revenue),0) FROM sales_prev_year_revenues
            WHERE company_id = ?" . ($caseTypeFilter ? " AND case_type = ?" : "") . " AND year = ? AND month = ?");
        $_kStmt->execute($caseTypeFilter ? [$cid, $caseTypeFilter, $year-1, $month] : [$cid, $year-1, $month]);
        $_prevYearRev = (int)$_kStmt->fetchColumn();
        if ($_prevYearRev > 0) {
            $kpis['prev_year_revenue'] = $_prevYearRev;
            $kpis['yoy_change'] = round(((int)$kpis['revenue'] - $_prevYearRev) / $_prevYearRev * 100, 1);
        }
    } catch (PDOException $e) { /* テーブル未作成時は無視 */ }
}
// 表示中の月に案件データが無い場合は、手入力の実績でKPIカードを補完する
if (empty($kpis['revenue']) && isset($manualActualMap[$year][$month])) {
    $_ma = $manualActualMap[$year][$month];
    if ($_ma['rev'] > 0) {
        $kpis['revenue'] = $_ma['rev'];
        $kpis['profit']  = $_ma['profit'];
        $kpis['margin']  = round($_ma['profit'] / $_ma['rev'] * 100, 1);
        if (!empty($kpis['prev_year_revenue'])) {
            $kpis['yoy_change'] = round(($_ma['rev'] - $kpis['prev_year_revenue']) / $kpis['prev_year_revenue'] * 100, 1);
        }
    }
}
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

// 案件区分別売上（年度累計 9月〜翌8月・売上金額ベース）
// 左: 1次 / 2次以降   右: 1次の内訳（キャリア常勤・代理店常勤・イベント）
// ※常勤の1次で予算区分が未入力のものは「未設定」に集約し、内訳の合計が1次合計と一致するようにする
$divRev = ['first' => 0, 'second' => 0, 'carrier' => 0, 'agency' => 0, 'event' => 0, 'unset' => 0];
$divCnt = $divRev;
if (!$caseTypeFilter) {
    try {
        $_dvStmt = $db->prepare("
            SELECT case_division, case_type, budget_division,
                   COALESCE(SUM(revenue),0) AS rev, COUNT(*) AS cnt
            FROM sales_cases
            WHERE company_id = ? AND status = 'confirmed'
              AND case_division IN ('1次','2次以降')
              AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
            GROUP BY case_division, case_type, budget_division
        ");
        $_dvStmt->execute([$cid, $fyYear - 1, $fyYear]);
        foreach ($_dvStmt->fetchAll() as $_r) {
            $_rev = (int)$_r['rev'];
            $_cnt = (int)$_r['cnt'];
            if ($_r['case_division'] !== '1次') {
                $divRev['second'] += $_rev; $divCnt['second'] += $_cnt;
                continue;
            }
            $divRev['first'] += $_rev; $divCnt['first'] += $_cnt;
            if ($_r['case_type'] === 'event')                  $_key = 'event';
            elseif ($_r['budget_division'] === 'キャリア予算')  $_key = 'carrier';
            elseif ($_r['budget_division'] === '代理店予算')    $_key = 'agency';
            else                                               $_key = 'unset';
            $divRev[$_key] += $_rev; $divCnt[$_key] += $_cnt;
        }
    } catch (PDOException $_e) { /* budget_division 未追加の環境では集計しない */ }
}
// 構成比（売上金額ベース）
$_divPct = function (int $part, int $total): float {
    return $total > 0 ? round($part / $total * 100, 1) : 0.0;
};
$divFirstTotal = $divRev['first'] + $divRev['second'];

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
// クライアント別売上（当月。営業マン別売上と期間を統一）
$_clientFySql = "
    SELECT COALESCE(NULLIF(TRIM(cl.display_name),''), cl.client_name) AS name, COALESCE(SUM(sc.revenue),0) AS revenue, COALESCE(SUM(sc.gross_profit),0) AS profit
    FROM sales_cases sc
    JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_year = ? AND sc.case_month = ?
      $_ctf
    GROUP BY cl.id, name ORDER BY revenue DESC";
$_s = $_sDb->prepare($_clientFySql);
$_s->execute(array_merge([$cid, $year, $month], $_ctp));
$clientFyRows = $_s->fetchAll();
// アライアンス別売上（年度）
// アライアンス別売上（当月。営業マン別売上と期間を統一）
// ※会社名クリックで開く実績詳細は従来どおり年度（9月〜翌8月）集計のまま
$_allianceFySql = "
    SELECT al.id AS alliance_id, al.alliance_name AS name, COALESCE(SUM(sc.revenue),0) AS revenue, COALESCE(SUM(sc.gross_profit),0) AS profit
    FROM sales_cases sc
    JOIN sales_alliances al ON sc.alliance_id = al.id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_year = ? AND sc.case_month = ?
      $_ctf
    GROUP BY al.id, al.alliance_name ORDER BY revenue DESC";
$_s = $_sDb->prepare($_allianceFySql);
$_s->execute(array_merge([$cid, $year, $month], $_ctp));
$allianceFyRows = $_s->fetchAll();
// 営業マン別売上（当月）: 担当者別売上レポートと同じ50%分割で集計
// ※粗利0円稼働者（zero_profit_flag=1）の案件は売上は50/50のまま、粗利のみ直営業100%
// ※2026年7月イベント案件の 近藤航 のみ粗利0円稼働者扱い（一度きりのデータ対応。他の年月・他スタッフには影響しない）
$_zpCond = "(worker_name IN (SELECT em.name FROM employees em WHERE em.company_id = sales_cases.company_id AND em.is_active = 1 AND em.zero_profit_flag = 1)"
         . " OR (worker_name = '近藤航' AND case_year = 2026 AND case_month = 7 AND case_type = 'event'))";
$_repFySql = "
    SELECT name, SUM(revenue) AS revenue, SUM(profit) AS profit
    FROM (
        SELECT sales_rep AS name,
               FLOOR(revenue/2) AS revenue,
               CASE WHEN $_zpCond THEN 0 ELSE FLOOR(gross_profit/2) END AS profit
        FROM sales_cases
        WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
          AND case_year = ? AND case_month = ?
          $_ctf2
        UNION ALL
        SELECT CASE WHEN COALESCE(manager,'') NOT IN ('','該当者なし') THEN manager
                    WHEN COALESCE(recruiter,'') NOT IN ('','該当者なし') THEN recruiter
                    ELSE '直営業' END AS name,
               revenue - FLOOR(revenue/2) AS revenue,
               CASE WHEN $_zpCond THEN 0 ELSE gross_profit - FLOOR(gross_profit/2) END AS profit
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
// 営業担当は売上0でも必ず表示する（社員一覧で「営業担当」にチェックした在籍中の正社員・自社外注）
// ※旧: 山根脩平をベタ書き。名簿で管理できるようになったため置き換え
$_repNames = array_column($repFyRows, 'name');
foreach (getSalesRepCandidates($cid) as $_rc) {
    if (!in_array($_rc, $_repNames, true)) {
        $repFyRows[] = ['name' => $_rc, 'revenue' => 0, 'profit' => 0];
    }
}
// 直営業の月間売上を取得して最後尾に追加
// 売上: 紹介元なしの場合に半分（従来どおり）
// 粗利: 粗利0円稼働者の案件は全額直営業、それ以外は紹介元なしの場合に半分
$_directSql = "
    SELECT SUM(CASE WHEN COALESCE(manager,'') IN ('','該当者なし') AND COALESCE(recruiter,'') IN ('','該当者なし') THEN revenue - FLOOR(revenue/2)
                    ELSE 0 END) AS revenue,
           SUM(CASE WHEN $_zpCond THEN gross_profit
                    WHEN COALESCE(manager,'') IN ('','該当者なし') AND COALESCE(recruiter,'') IN ('','該当者なし') THEN gross_profit - FLOOR(gross_profit/2)
                    ELSE 0 END) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status = 'confirmed' AND sales_rep != ''
      AND case_year = ? AND case_month = ?
      $_ctf2";
$_ds = $_sDb->prepare($_directSql);
$_ds->execute(array_merge([$cid, $year, $month], $_ctp));
$_dr = $_ds->fetch();
$repFyRows[] = ['name' => '直営業', 'revenue' => (int)($_dr['revenue'] ?? 0), 'profit' => (int)($_dr['profit'] ?? 0)];
// キャリア別売上（年度）
// キャリア別売上（当月。営業マン別売上と期間を統一）
$_carrierFySql = "
    SELECT carrier AS name, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(gross_profit),0) AS profit
    FROM sales_cases
    WHERE company_id = ? AND status = 'confirmed' AND carrier IS NOT NULL AND carrier != ''
      AND case_year = ? AND case_month = ?
      $_ctf2
    GROUP BY carrier ORDER BY revenue DESC";
$_s = $_sDb->prepare($_carrierFySql);
$_s->execute(array_merge([$cid, $year, $month], $_ctp));
$carrierFyRows = $_s->fetchAll();

// 月別枠数目標・実績（常勤/イベントのみ。区分ごとに集計、相互に混在しない）
$frameTargetMap  = []; // 目標1次
$frameTarget2Map = []; // 目標二次以降
$frameActualMap  = []; // 1次実績（case_division='1次'）
$frameActual2Map = []; // 二次以降実績（case_division='二次以降'）
if ($caseTypeFilter) {
    try {
        try { $_sDb->exec("ALTER TABLE sales_frame_targets ADD COLUMN target_second_frame INT NOT NULL DEFAULT 0 AFTER target_first_frame"); } catch (PDOException $e) {}
        $_ftStmt = $_sDb->prepare("SELECT year, month, target_first_frame, target_second_frame FROM sales_frame_targets WHERE company_id=? AND case_type=? AND ((year=? AND month>=9) OR (year=? AND month<=8))");
        $_ftStmt->execute([$cid, $caseTypeFilter, $fyYear-1, $fyYear]);
        foreach ($_ftStmt->fetchAll() as $_r) {
            $frameTargetMap[(int)$_r['year']][(int)$_r['month']]  = (int)$_r['target_first_frame'];
            $frameTarget2Map[(int)$_r['year']][(int)$_r['month']] = (int)$_r['target_second_frame'];
        }
        // 区分別 実績件数（1次 / 二次以降）
        $_faStmt = $_sDb->prepare("SELECT case_year, case_month, case_division, COUNT(*) AS cnt FROM sales_cases WHERE company_id=? AND case_type=? AND case_division IN ('1次','2次以降') AND status != 'cancelled' AND ((case_year=? AND case_month>=9) OR (case_year=? AND case_month<=8)) GROUP BY case_year, case_month, case_division");
        $_faStmt->execute([$cid, $caseTypeFilter, $fyYear-1, $fyYear]);
        foreach ($_faStmt->fetchAll() as $_r) {
            if ($_r['case_division'] === '1次') {
                $frameActualMap[(int)$_r['case_year']][(int)$_r['case_month']] = (int)$_r['cnt'];
            } else {
                $frameActual2Map[(int)$_r['case_year']][(int)$_r['case_month']] = (int)$_r['cnt'];
            }
        }
    } catch (PDOException $_e) {
        $frameTargetMap = []; $frameTarget2Map = []; $frameActualMap = []; $frameActual2Map = [];
    }
}

// 年度月別販管費合計（営業利益表示用）※区分=販管費(sga)のみ。原価(cost)は除外
$_sgaFyStmt = $_sDb->prepare("
    SELECT target_year, target_month, COALESCE(SUM(amount),0) AS sga_total
    FROM sga_expenses
    WHERE company_id = ? AND expense_type = 'sga'
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
/* 月別枠数テーブル: 項目名がはみ出さないよう1列目を固定幅で確保 */
.frame-count-table td.fy-label, .frame-count-table thead th:first-child { min-width: 118px !important; width: 118px !important; }
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
    // 年度切替（$fy）の影響を受けないよう、表示中の月の目標は $year から取得
    $monthTarget = $fyTgtMap[$year][$month] ?? null;
    if ($monthTarget === null) {
        $_curTypes   = getSalesTargets($cid, $year)[$month] ?? [];
        $monthTarget = $_tgtType
            ? (int)($_curTypes[$_tgtType]['revenue_target'] ?? 0)
            : (int)($_curTypes['regular']['revenue_target'] ?? 0) + (int)($_curTypes['event']['revenue_target'] ?? 0);
    }
    $monthAch = $monthTarget > 0 ? round($kpis['revenue'] / $monthTarget * 100, 1) : 0;
    // 達成率は2色: 100%以上=青 / 100%未満=赤
    $monthAchColor = $monthAch >= 100 ? '#3b82f6' : '#ef4444';
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

    <div id="fySectionsWrap">
    <?php ob_start(); // ▼ 年度切替の対象範囲（売上推移チャート＋年度月別売上テーブル） ?>
    <!-- 売上推移チャート（全幅）→ KPI直下に移動 -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up me-1" style="color:#059669"></i><?= $fyYear-1 ?>年度 売上推移（9月〜8月）</span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem" onclick="setFyYear(<?= $fyYear-1 ?>)" title="前年度">◀</button>
                            <span class="fw-semibold text-nowrap" style="font-size:.75rem;min-width:150px;text-align:center"><?= $fyYear-1 ?>年9月〜<?= $fyYear ?>年8月</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem" onclick="setFyYear(<?= $fyYear+1 ?>)" title="翌年度">▶</button>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active" id="btnTrendTaxExcl" onclick="setTrendTaxMode(false)" style="font-size:.7rem;padding:2px 8px">税抜</button>
                            <button type="button" class="btn btn-outline-secondary" id="btnTrendTaxIncl" onclick="setTrendTaxMode(true)" style="font-size:.7rem;padding:2px 8px">税込</button>
                        </div>
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
            <span><i class="bi bi-table me-1" style="color:#6366f1"></i><?= $fyYear-1 ?>-<?= $fyYear ?>年度 月別売上（9月〜8月）</span>
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
                            <td class="fy-label">売上達成率</td>
                            <?php foreach ($fyMonths as $i => $fm): $d = $fyRowData[$i];
                                $achCls = $d['ach'] === null ? 'text-muted' : ($d['ach'] >= 100 ? 'text-primary' : 'text-danger');
                            ?>
                            <td class="text-end <?= $achCls ?>"><?= $d['ach'] !== null ? $d['ach'] . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary <?= $fyAch <= 0 ? 'text-muted' : ($fyAch >= 100 ? 'text-primary' : 'text-danger') ?>"><?= $fyAch > 0 ? $fyAch . '%' : '-' ?></td>
                        </tr>
                        <?php
                        // 前年同月売上・前年同月比（全ダッシュボード。売上と同じ絞り込みで整合）
                        $fyTotalPrevRev = 0;
                        foreach ($fyMonths as $fm) {
                            $fyTotalPrevRev += $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0;
                        }
                        $fyTotalYoy = ($fyTotalPrevRev > 0 && $fyTotalRev > 0) ? round($fyTotalRev / $fyTotalPrevRev * 100, 1) : null;
                        ?>
                        <!-- 前年同月売上 -->
                        <tr>
                            <td class="fy-label">前年同月売上</td>
                            <?php foreach ($fyMonths as $i => $fm): $prevRev = $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0; ?>
                            <td class="text-end <?= $prevRev > 0 ? '' : 'text-muted' ?>"><?= $prevRev > 0 ? number_format($prevRev) : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary"><?= $fyTotalPrevRev > 0 ? number_format($fyTotalPrevRev) : '-' ?></td>
                        </tr>
                        <!-- 前年同月比 -->
                        <tr class="table-light">
                            <td class="text-muted fy-label">前年同月比</td>
                            <?php foreach ($fyMonths as $i => $fm):
                                $d = $fyRowData[$i];
                                $prevRev = $fyPrevRevMap[$fm['y']-1][$fm['m']] ?? 0;
                                $yoy = ($prevRev > 0 && $d['rev'] > 0) ? round($d['rev'] / $prevRev * 100, 1) : null;
                            ?>
                            <td class="text-end <?= fyYoyClass($yoy, $fyYoyBase) ?>"><?= $yoy !== null ? $yoy . '%' : '-' ?></td>
                            <?php endforeach; ?>
                            <td class="text-end table-secondary <?= fyYoyClass($fyTotalYoy, $fyYoyBase) ?>"><?= $fyTotalYoy !== null ? $fyTotalYoy . '%' : '-' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($caseTypeFilter): ?>
    <!-- 月別枠数テーブル（常勤/イベントのみ。年度切替に連動） -->
    <?php
    // 項目名の単位: 常勤は「枠数」、イベントは「コマ数」
    $frameUnit = $caseTypeFilter === 'event' ? 'コマ数' : '枠数';
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-grid-3x3-gap me-1" style="color:#8b5cf6"></i>月別<?= $frameUnit ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 fy-monthly-table frame-count-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:118px;width:118px">項目</th>
                                    <?php foreach ($fyMonthsFrame as $fm): ?>
                                    <th class="text-center"><?= $fm['m'] ?>月</th>
                                    <?php endforeach; ?>
                                    <th class="text-center table-secondary">合計</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- 目標1次枠数（手打ち） -->
                                <tr>
                                    <td class="fy-label fw-semibold" style="color:#6366f1">目標1次<?= $frameUnit ?></td>
                                    <?php
                                    $fyFrameTgtTotal = 0;
                                    foreach ($fyMonthsFrame as $fm):
                                        $ftv = $frameTargetMap[$fm['y']][$fm['m']] ?? 0;
                                        $fyFrameTgtTotal += $ftv;
                                    ?>
                                    <td class="p-0">
                                        <input type="number" min="0" class="fy-tgt-input fy-frame-tgt-input form-control form-control-sm border-0 text-center px-1"
                                            style="min-width:52px;background:transparent"
                                            value="<?= $ftv ?: '' ?>"
                                            data-frame="first"
                                            data-year="<?= $fm['y'] ?>"
                                            data-month="<?= $fm['m'] ?>"
                                            placeholder="0">
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold" id="fyFrameTgtTotal"><?= $fyFrameTgtTotal ?></td>
                                </tr>
                                <!-- 1次枠数（自動集計） -->
                                <tr>
                                    <td class="fy-label fw-semibold" style="color:#059669">1次<?= $frameUnit ?></td>
                                    <?php
                                    $fyFirstFrameTotal = 0;
                                    foreach ($fyMonthsFrame as $fm):
                                        $ffv = $frameActualMap[$fm['y']][$fm['m']] ?? 0;
                                        $fyFirstFrameTotal += $ffv;
                                    ?>
                                    <td class="text-center fy-first-actual" data-val="<?= $ffv ?>" style="color:<?= $ffv ? "#059669" : "" ?>"><?= $ffv ?: "-" ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold"><?= $fyFirstFrameTotal ?: '-' ?></td>
                                </tr>
                                <!-- 目標2次以降枠数（手打ち） -->
                                <tr>
                                    <td class="fy-label fw-semibold" style="color:#6366f1">目標2次以降<?= $frameUnit ?></td>
                                    <?php
                                    $fyFrameTgt2Total = 0;
                                    foreach ($fyMonthsFrame as $fm):
                                        $ftv2 = $frameTarget2Map[$fm['y']][$fm['m']] ?? 0;
                                        $fyFrameTgt2Total += $ftv2;
                                    ?>
                                    <td class="p-0">
                                        <input type="number" min="0" class="fy-tgt-input fy-frame-tgt-input form-control form-control-sm border-0 text-center px-1"
                                            style="min-width:52px;background:transparent"
                                            value="<?= $ftv2 ?: '' ?>"
                                            data-frame="second"
                                            data-year="<?= $fm['y'] ?>"
                                            data-month="<?= $fm['m'] ?>"
                                            placeholder="0">
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold" id="fyFrameTgt2Total"><?= $fyFrameTgt2Total ?></td>
                                </tr>
                                <!-- 2次以降枠数（自動集計） -->
                                <tr>
                                    <td class="fy-label fw-semibold" style="color:#059669">2次以降<?= $frameUnit ?></td>
                                    <?php
                                    $fySecondFrameTotal = 0;
                                    foreach ($fyMonthsFrame as $fm):
                                        $sfv = $frameActual2Map[$fm['y']][$fm['m']] ?? 0;
                                        $fySecondFrameTotal += $sfv;
                                    ?>
                                    <td class="text-center fy-second-actual" data-val="<?= $sfv ?>" style="color:<?= $sfv ? "#059669" : "" ?>"><?= $sfv ?: "-" ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold"><?= $fySecondFrameTotal ?: '-' ?></td>
                                </tr>
                                <!-- 合計枠数（1次＋二次以降を自動計算） -->
                                <tr>
                                    <td class="fy-label fw-bold" style="color:#3b82f6">合計<?= $frameUnit ?></td>
                                    <?php
                                    $fyTotalFrameTotal = 0;
                                    foreach ($fyMonthsFrame as $fm):
                                        $ftotal = ($frameActualMap[$fm['y']][$fm['m']] ?? 0) + ($frameActual2Map[$fm['y']][$fm['m']] ?? 0);
                                        $fyTotalFrameTotal += $ftotal;
                                    ?>
                                    <td class="text-center fw-semibold fy-total-frame" style="color:<?= $ftotal ? "#3b82f6" : "" ?>"><?= $ftotal ?: "-" ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-center table-secondary fw-semibold" id="fyTotalFrameSum"><?= $fyTotalFrameTotal ?: '-' ?></td>
                                </tr>
                                <?php
                                // 進捗（累計）: 実績がある月のみ、前月までの進捗に「実績−目標」を足していく
                                // 実績が無い月は「-」とし、累計にも含めない
                                $_renderFrameProgress = function(string $label, string $cls, array $tgtMap, array $actMap) use ($fyMonthsFrame) {
                                    $cum = 0; $last = null;
                                    $cells = '';
                                    foreach ($fyMonthsFrame as $fm) {
                                        $act = $actMap[$fm['y']][$fm['m']] ?? 0;
                                        if ($act === 0) { $cells .= '<td class="text-center text-muted ' . $cls . '">-</td>'; continue; }
                                        $cum += $act - ($tgtMap[$fm['y']][$fm['m']] ?? 0);
                                        $last = $cum;
                                        $color = $cum >= 0 ? '#059669' : '#dc2626';
                                        $cells .= '<td class="text-center ' . $cls . '" style="color:' . $color . '">'
                                                . ($cum > 0 ? '+' : '') . $cum . '</td>';
                                    }
                                    $sumColor = $last === null ? '' : ' style="color:' . ($last >= 0 ? '#059669' : '#dc2626') . '"';
                                    $sumText  = $last === null ? '-' : (($last > 0 ? '+' : '') . $last);
                                    echo '<tr class="fy-prog-row"><td class="fy-label">' . $label . '</td>' . $cells
                                       . '<td class="text-center table-secondary"' . $sumColor . '>' . $sumText . '</td></tr>';
                                };
                                $_renderFrameProgress('1次進捗',     'fy-prog-first',  $frameTargetMap,  $frameActualMap);
                                $_renderFrameProgress('2次以降進捗', 'fy-prog-second', $frameTarget2Map, $frameActual2Map);
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php
    // ▲ 年度切替の対象範囲ここまで（売上推移チャート／年度月別売上／月別枠数）
    $fySectionsHtml = ob_get_clean();
    echo $fySectionsHtml;
    if ($FY_ONLY) {
        // ヘッダー等の出力を破棄し、該当セクションのHTMLとチャートデータのみ返す
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'html'    => $fySectionsHtml,
            'trend'   => $trendData,
            'targets' => $trendTargets,
            'fy_year' => $fyYear,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ?>
    </div><!-- /#fySectionsWrap -->

    <style>
    /* 詳細ビューのランキング表: 横スクロールなしで収まるようコンパクト表示 */
    .pie-rank-table th, .pie-rank-table td { font-size:.68rem; padding:.28rem .35rem; white-space:nowrap; }

    /* 集計カードの「詳細」ボタン専用の配色
       共通CSSの .btn-primary は黒系(--n900)のため、ここでは青(--a600)を明示する。
       円グラフ表示中=青背景・白文字 / 表を表示中=白背景・青文字 */
    .btn-detail-toggle.btn-primary {
        background: #2563eb !important;
        border-color: #2563eb !important;
        color: #fff !important;
    }
    .btn-detail-toggle.btn-primary:hover {
        background: #1d4ed8 !important;
        border-color: #1d4ed8 !important;
    }
    .btn-detail-toggle.btn-outline-primary {
        background: #fff !important;
        border-color: #2563eb !important;
        color: #2563eb !important;
    }
    .btn-detail-toggle.btn-outline-primary:hover {
        background: #eff6ff !important;
    }
    </style>

    <!-- 集計カード 上段: キャリア別売上（左）+ 営業マン別売上（右） -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-phone me-1" style="color:#06b6d4"></i>キャリア別売上 <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月 TOP3</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm btn-detail-toggle" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'carrierPieWrap','carrierFyTableWrap')" data-pie="1">詳細</button>
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
                    <div id="carrierFyTableWrap" style="display:none">
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
                    <div id="carrierPieWrap" class="p-3" data-cardkey="carrier">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('carrier',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('carrier',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="carrierPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="carrierPieChart"></canvas></div>
                            </div>
                            <div class="col-md-8">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 pie-rank-table">
                                        <thead class="table-light"><tr><th>順位</th><th>キャリア</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="carrierPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-badge me-1" style="color:#f59e0b"></i>営業マン別売上 <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm btn-detail-toggle" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'repPieWrap','repFyTableWrap')" data-pie="1">詳細</button>
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
                    <div id="repFyTableWrap" style="display:none">
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
                    <div id="repPieWrap" class="p-3" data-cardkey="rep">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('rep',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('rep',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="repPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="repPieChart"></canvas></div>
                            </div>
                            <div class="col-md-8">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 pie-rank-table">
                                        <thead class="table-light"><tr><th>順位</th><th>営業マン</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="repPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
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
                    <span><i class="bi bi-building me-1" style="color:#6366f1"></i>クライアント別売上 <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月 TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm btn-detail-toggle" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'clientPieWrap','clientFyTableWrap')" data-pie="1">詳細</button>
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
                    <div id="clientFyTableWrap" style="display:none">
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
                    <div id="clientPieWrap" class="p-3" data-cardkey="client">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('client',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('client',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="clientPieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="clientPieChart"></canvas></div>
                            </div>
                            <div class="col-md-8">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 pie-rank-table">
                                        <thead class="table-light"><tr><th>順位</th><th>会社名</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="clientPieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-1" style="color:#059669"></i>アライアンス別売上 <small class="text-muted ms-1"><?= $year ?>年<?= $month ?>月 TOP5</small></span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm btn-detail-toggle" style="font-size:.7rem;padding:2px 8px" onclick="togglePieView(this,'alliancePieWrap','allianceFyTableWrap')" data-pie="1">詳細</button>
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
                    <div id="allianceFyTableWrap" style="display:none">
                        <table class="table table-sm mb-0" id="allianceFyTable">
                            <thead class="table-light"><tr><th style="padding-left:.75rem">会社名</th><th class="text-end">売上</th><th class="text-end" style="padding-right:.75rem">粗利</th></tr></thead>
                            <tbody>
                                <?php if ($allianceFyRows): ?>
                                <?php foreach ($allianceFyRows as $i => $row): ?>
                                <tr <?= $i >= 5 ? 'class="extra-row" style="display:none"' : '' ?>>
                                    <td style="padding-left:.75rem"><a href="#" class="alliance-detail-link text-decoration-none" data-aid="<?= (int)$row['alliance_id'] ?>" onclick="openAllianceDetail(event,this)"><?= h($row['name']) ?></a></td>
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
                    <div id="alliancePieWrap" class="p-3" data-cardkey="alliance">
                        <div class="d-flex justify-content-center mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active pie-metric-rev" onclick="setPieMetric('alliance',false,this)" style="font-size:.7rem;padding:2px 8px">売上</button>
                                <button type="button" class="btn btn-outline-secondary pie-metric-profit" onclick="setPieMetric('alliance',true,this)" style="font-size:.7rem;padding:2px 8px">粗利</button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="fw-bold small mb-1" style="color:#059669" id="alliancePieTitle">売上割合</div>
                                <div style="position:relative;height:180px"><canvas id="alliancePieChart"></canvas></div>
                            </div>
                            <div class="col-md-8">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0 pie-rank-table">
                                        <thead class="table-light"><tr><th>順位</th><th>会社名</th><th class="text-end">売上</th><th class="text-end">粗利</th><th class="text-end">粗利率</th></tr></thead>
                                        <tbody id="alliancePieRank"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$caseTypeFilter): ?>
    <!-- スタッフ分析（総合ダッシュボードのみ表示） -->
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

    <!-- 案件区分分析（総合ダッシュボードのみ表示・年度累計・売上金額ベース） -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pie-chart me-1" style="color:#3b82f6"></i>案件区分分析
                        <small class="text-muted ms-1"><?= $fyYear-1 ?>-<?= $fyYear ?>年度累計</small></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-start justify-content-center">
                        <!-- ① 1次 / 2次以降 -->
                        <div class="col-md-6 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem;font-weight:600">区分別売上（1次・2次以降）</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="caseDivisionChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:1.9">
                                <div><span style="color:#3b82f6">●</span> 1次 <strong><?= number_format($divRev['first']) ?>円</strong>
                                    （<?= $_divPct($divRev['first'], $divFirstTotal) ?>%）<span class="text-muted"><?= $divCnt['first'] ?>件</span></div>
                                <div><span style="color:#059669">●</span> 2次以降 <strong><?= number_format($divRev['second']) ?>円</strong>
                                    （<?= $_divPct($divRev['second'], $divFirstTotal) ?>%）<span class="text-muted"><?= $divCnt['second'] ?>件</span></div>
                            </div>
                        </div>
                        <!-- ② 1次の内訳 -->
                        <div class="col-md-6 text-center">
                            <div class="text-muted small mb-1" style="font-size:.72rem;font-weight:600">1次の内訳</div>
                            <div class="sales-chart-wrap" style="height:130px"><canvas id="firstBudgetChart"></canvas></div>
                            <div style="font-size:.75rem;margin-top:6px;line-height:1.9">
                                <div><span style="color:#3b82f6">●</span> キャリア常勤 <strong><?= number_format($divRev['carrier']) ?>円</strong>
                                    （<?= $_divPct($divRev['carrier'], $divRev['first']) ?>%）<span class="text-muted"><?= $divCnt['carrier'] ?>件</span></div>
                                <div><span style="color:#059669">●</span> 代理店常勤 <strong><?= number_format($divRev['agency']) ?>円</strong>
                                    （<?= $_divPct($divRev['agency'], $divRev['first']) ?>%）<span class="text-muted"><?= $divCnt['agency'] ?>件</span></div>
                                <div><span style="color:#f59e0b">●</span> イベント <strong><?= number_format($divRev['event']) ?>円</strong>
                                    （<?= $_divPct($divRev['event'], $divRev['first']) ?>%）<span class="text-muted"><?= $divCnt['event'] ?>件</span></div>
                                <?php if ($divRev['unset'] > 0 || $divCnt['unset'] > 0): ?>
                                <!-- 予算区分が未入力の常勤1次。入力が済めば自動的に消える -->
                                <div><span style="color:#9ca3af">●</span> 未設定 <strong><?= number_format($divRev['unset']) ?>円</strong>
                                    （<?= $_divPct($divRev['unset'], $divRev['first']) ?>%）<span class="text-muted"><?= $divCnt['unset'] ?>件</span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

<!-- アライアンス会社別 実績詳細モーダル -->
<div class="modal fade" id="allianceDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" style="font-size:.9rem"><i class="bi bi-building me-1"></i><span id="adTitle">実績詳細</span> <small class="text-muted ms-2" id="adFyLabel"></small></h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="adLoading" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>読み込み中...</div>
                <div id="adContent" style="display:none">
                    <!-- サマリー -->
                    <div class="row g-2 mb-3" style="font-size:.8rem">
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">年間売上</div><div class="fw-bold" style="color:#059669" id="adRevenue">-</div></div></div>
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">年間粗利</div><div class="fw-bold" style="color:#3b82f6" id="adProfit">-</div></div></div>
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">原価（支払額）</div><div class="fw-bold" style="color:#f59e0b" id="adCost">-</div></div></div>
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">粗利率</div><div class="fw-bold" id="adMargin">-</div></div></div>
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">在籍スタッフ数</div><div class="fw-bold" style="color:#8b5cf6" id="adStaffCount">-</div></div></div>
                        <div class="col-6 col-md-2"><div class="border rounded text-center p-2"><div class="text-muted" style="font-size:.65rem">年間平均達成率</div><div class="fw-bold" id="adAvgRate">-</div></div></div>
                    </div>
                    <!-- スタッフ×月 達成率 -->
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0" style="font-size:.72rem">
                            <thead class="table-light" id="adTableHead"></thead>
                            <tbody id="adTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-muted mt-2" style="font-size:.65rem">※ 達成率は日報の実績（固定合計）÷店舗予算で自動計算。「-」は未稼働または予算未設定の月です。</div>
                </div>
                <div id="adError" class="alert alert-danger small py-2" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

<?php
$fyChartLabels = ['9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月','8月'];

$wLabels = array_keys($workerGrouped);
$wValues = array_map(fn($g) => $g['revenue'], array_values($workerGrouped));
$wColors = ['#3b82f6', '#059669'];

// スタッフ人数円グラフデータ（社員テーブルベース）
$staffPieLabels = ['自社', 'アライアンス'];
$staffPieValues = [$empStats['inhouse'], $empStats['alliance']];
$staffPieColors = ['#3b82f6', '#059669'];

$rawWorkerValues = json_encode($wValues);

$inlineJs = 'let trendRawData = ' . json_encode($trendData) . ';';
$inlineJs .= 'let trendTargets = ' . json_encode($trendTargets) . ';';
$inlineJs .= 'let fyCurrentYear = ' . (int)$fyYear . ';';
$inlineJs .= 'const fyChartLabels = ' . json_encode($fyChartLabels) . ';';
$inlineJs .= 'let trendChartInstance = salesDrawTrendChart("trendChart", trendRawData, trendTargets, fyChartLabels);';
$inlineJs .= 'const workerRawValues = ' . $rawWorkerValues . ';';
$inlineJs .= 'let workerChartInstance = salesDrawDonutChart("workerChart", ' . json_encode($wLabels) . ', workerRawValues, ' . json_encode($wColors) . ');';
// 常勤・イベント人数構成チャート（staffPieChartは削除、新2チャートに置換）
$_pieLabels = ['自社', 'アライアンス'];
$_pieColors = ['#3b82f6', '#059669'];
$inlineJs .= 'salesDrawDonutChart("regularStaffChart", ' . json_encode($_pieLabels) . ', ' . json_encode([$regularInhouse, $regularAlliance]) . ', ' . json_encode($_pieColors) . ');';
$inlineJs .= 'salesDrawDonutChart("eventStaffChart", '   . json_encode($_pieLabels) . ', ' . json_encode([$eventInhouse,   $eventAlliance])   . ', ' . json_encode($_pieColors) . ');';
// 案件区分分析（総合ダッシュボードのみ・年度累計・売上金額ベース）
if (!$caseTypeFilter) {
    $_divLabels  = ['1次', '2次以降'];
    $_divColors  = ['#3b82f6', '#059669'];
    $inlineJs .= 'salesDrawDonutChart("caseDivisionChart", ' . json_encode($_divLabels, JSON_UNESCAPED_UNICODE) . ', '
               . json_encode([$divRev['first'], $divRev['second']]) . ', ' . json_encode($_divColors) . ');';
    // 予算区分が未入力の常勤1次がある間だけ「未設定」を表示する
    $_bdLabels = ['キャリア常勤', '代理店常勤', 'イベント'];
    $_bdValues = [$divRev['carrier'], $divRev['agency'], $divRev['event']];
    $_bdColors = ['#3b82f6', '#059669', '#f59e0b'];
    if ($divRev['unset'] > 0 || $divCnt['unset'] > 0) {
        $_bdLabels[] = '未設定'; $_bdValues[] = $divRev['unset']; $_bdColors[] = '#9ca3af';
    }
    $inlineJs .= 'salesDrawDonutChart("firstBudgetChart", ' . json_encode($_bdLabels, JSON_UNESCAPED_UNICODE) . ', '
               . json_encode($_bdValues) . ', ' . json_encode($_bdColors) . ');';
}
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
// 月別枠数目標: 自動保存（1次 / 2次以降を区別）
// 年度切替でテーブルが差し替わっても効くよう、documentへの委譲で受ける
function recalcFrameTgtTotal() {
    let sum1 = 0, sum2 = 0;
    document.querySelectorAll('.fy-frame-tgt-input').forEach(inp => {
        const v = parseInt(inp.value) || 0;
        if (inp.dataset.frame === 'second') sum2 += v; else sum1 += v;
    });
    const el1 = document.getElementById('fyFrameTgtTotal');  if (el1) el1.textContent = sum1 || 0;
    const el2 = document.getElementById('fyFrameTgt2Total'); if (el2) el2.textContent = sum2 || 0;
    recalcFrameProgress();
}

// 進捗（累計）の再計算: 目標を手入力したその場で反映する
function recalcFrameProgress() {
    [['first', '.fy-first-actual',  '.fy-prog-first'],
     ['second', '.fy-second-actual', '.fy-prog-second']].forEach(function(set) {
        const frame   = set[0];
        const actuals = document.querySelectorAll(set[1]);
        const cells   = document.querySelectorAll(set[2]);
        if (!actuals.length || !cells.length) return;
        const targets = Array.from(document.querySelectorAll('.fy-frame-tgt-input'))
                             .filter(i => (i.dataset.frame || 'first') === frame);
        let cum = 0, last = null;
        actuals.forEach(function(actEl, i) {
            const act  = parseInt(actEl.dataset.val) || 0;
            const cell = cells[i];
            if (!cell) return;
            if (act === 0) {
                cell.textContent = '-';
                cell.className = 'text-center text-muted ' + set[2].slice(1);
                cell.style.color = '';
                return;
            }
            const tgt = targets[i] ? (parseInt(targets[i].value) || 0) : 0;
            cum += act - tgt;
            last = cum;
            cell.textContent = (cum > 0 ? '+' : '') + cum;
            cell.className = 'text-center ' + set[2].slice(1);
            cell.style.color = cum >= 0 ? '#059669' : '#dc2626';
        });
        // 合計欄は最終月の累計と一致する
        const sumCell = cells[cells.length - 1] ? cells[cells.length - 1].parentElement.lastElementChild : null;
        if (sumCell) {
            sumCell.textContent = last === null ? '-' : ((last > 0 ? '+' : '') + last);
            sumCell.style.color = last === null ? '' : (last >= 0 ? '#059669' : '#dc2626');
        }
    });
}
document.addEventListener('change', function(e) {
    const inp = e.target.closest ? e.target.closest('.fy-frame-tgt-input') : null;
    if (!inp) return;
    // 保存先の年月は入力欄の data 属性から取るため、年度切替後も正しい年度に保存される
    const yr    = inp.dataset.year;
    const mo    = inp.dataset.month;
    const frame = inp.dataset.frame || 'first';
    const val   = Math.max(0, parseInt(inp.value) || 0);
    inp.value = val;
    recalcFrameTgtTotal();
    const csrfEl = document.getElementById('fycsrf');
    const fd = new FormData();
    fd.append('action', 'save_frame_target');
    fd.append('csrf', csrfEl ? csrfEl.value : '');
    fd.append('frame_type', frame);
    fd.append('t_year', yr);
    fd.append('t_month', mo);
    fd.append('t_value', val);
    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => {});
});
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
// 年度月別「売上目標」入力保存（枠数目標 .fy-frame-tgt-input は対象外）
function bindFyTargetInputs() {
    var csrf = document.getElementById('fycsrf') ? document.getElementById('fycsrf').value : '';
    document.querySelectorAll('.fy-tgt-input:not(.fy-frame-tgt-input)').forEach(function(el) {
        if (el.dataset.fyBound === '1') return;
        el.dataset.fyBound = '1';
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
}
function recalcFyTgtTotal() {
    var total = 0;
    document.querySelectorAll('.fy-tgt-input:not(.fy-frame-tgt-input)').forEach(function(el) {
        total += parseInt(el.value.replace(/[^0-9]/g, '') || '0');
    });
    var el = document.getElementById('fyTgtTotal');
    if (el) el.textContent = total > 0 ? total.toLocaleString() : '-';
}
bindFyTargetInputs();

// 年度切替（売上推移チャート・年度月別売上テーブルのみ。画面全体はリロードしない）
var _fyLoading = false;
function setFyYear(newFy) {
    if (_fyLoading || !newFy) return;
    _fyLoading = true;
    var wrap = document.getElementById('fySectionsWrap');
    if (wrap) wrap.style.opacity = '.5';
    var params = new URLSearchParams(window.location.search);
    params.set('fy', newFy);
    params.set('fy_only', '1');
    fetch(window.location.pathname + '?' + params.toString(), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) throw new Error('failed');
            fyCurrentYear = d.fy_year;
            trendRawData  = d.trend;
            trendTargets  = d.targets;
            if (wrap) wrap.innerHTML = d.html;
            // チャートを新しい年度で再描画（税抜/税込の選択状態は維持）
            if (trendChartInstance && trendChartInstance.destroy) trendChartInstance.destroy();
            trendChartInstance = salesDrawTrendChart('trendChart', trendRawData, trendTargets, fyChartLabels);
            if (typeof trendTaxIncluded !== 'undefined' && trendTaxIncluded) setTrendTaxMode(true);
            bindFyTargetInputs();
            // URLを更新（リロードはしない）
            var url = new URLSearchParams(window.location.search);
            url.set('fy', newFy);
            history.replaceState(null, '', window.location.pathname + '?' + url.toString());
        })
        .catch(function() { alert('年度データの取得に失敗しました'); })
        .then(function() {
            _fyLoading = false;
            if (wrap) wrap.style.opacity = '1';
        });
}
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
        // 表を表示 → 白背景・青文字
        pieWrap.style.display='none';
        if(tableWrap)tableWrap.style.display='';
        btn.dataset.pie='0';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-outline-primary');
    }else{
        // 円グラフを表示 → 青背景・白文字
        if(tableWrap)tableWrap.style.display='none';
        pieWrap.style.display='';
        btn.dataset.pie='1';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-primary');
        var cardkey=pieWrap.dataset.cardkey;
        var card=btn.closest?btn.closest('.card'):null;
        var taxIncl=card?card.querySelector('.summary-tax-incl.active')!==null:false;
        drawRankPieCharts(cardkey,taxIncl);
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

    // ランキング: 営業マンは全員を個別表示（直営業は順位なしの別行）、他カードはTOP5 + 6位以下は「その他」に集約
    var rankRows;
    if(cardkey==='rep'){
        rankRows=rows.map(function(r){
            return{name:r.name,revenue:r.revenue,profit:r.profit,isDirect:r.name==='直営業'};
        });
    }else{
        var top=rows.slice(0,5), rest=rows.slice(5);
        rankRows=top.slice();
        if(rest.length){
            var oRev=0,oPro=0;
            rest.forEach(function(r){oRev+=r.revenue;oPro+=r.profit;});
            rankRows.push({name:'その他',revenue:oRev,profit:oPro,isOther:true});
        }
    }
    var tbody=document.getElementById(cardkey+'PieRank');
    if(tbody){
        var html='';
        var rankNo=0;
        rankRows.forEach(function(r){
            var noRank=r.isOther||r.isDirect;
            if(!noRank)rankNo++;
            var margin=r.revenue>0?(r.profit/r.revenue*100).toFixed(1)+'%':'-';
            html+='<tr'+(r.isOther?' class="text-muted"':'')+'>';
            html+='<td>'+(noRank?'-':rankNo)+'</td>';
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

// 初期表示は円グラフ（各カードのチャートをページ表示時に描画）
document.addEventListener('DOMContentLoaded', function(){
    ['carrier','rep','client','alliance'].forEach(function(cardkey){
        var wrap=document.querySelector('[data-cardkey="'+cardkey+'"]');
        if(!wrap || wrap.style.display==='none')return;
        var card=wrap.closest?wrap.closest('.card'):null;
        var taxIncl=card?card.querySelector('.summary-tax-incl.active')!==null:false;
        drawRankPieCharts(cardkey,taxIncl);
    });
});
RANKPIEJS;

$inlineJs .= 'var ALLIANCE_DETAIL_API = ' . json_encode(BASE_PATH . '/public/api/alliance_detail.php') . ';';
$inlineJs .= 'var adYear = ' . (int)$year . ';';
$inlineJs .= 'var adCaseType = ' . json_encode($caseTypeFilter) . ';';
$inlineJs .= <<<'ALLIANCEJS'

// アライアンス会社名クリック → 実績詳細モーダル
function openAllianceDetail(ev, link) {
    ev.preventDefault();
    var aid = link.dataset.aid;
    if (!aid) return;
    var modal = new bootstrap.Modal(document.getElementById('allianceDetailModal'));
    document.getElementById('adTitle').textContent = link.textContent + '　実績詳細';
    document.getElementById('adFyLabel').textContent = '';
    document.getElementById('adLoading').style.display = '';
    document.getElementById('adContent').style.display = 'none';
    document.getElementById('adError').style.display = 'none';
    modal.show();

    fetch(ALLIANCE_DETAIL_API + '?alliance_id=' + aid + '&year=' + adYear + '&case_type=' + encodeURIComponent(adCaseType))
        .then(function(r){ return r.json(); })
        .then(function(d) {
            document.getElementById('adLoading').style.display = 'none';
            if (d.error) {
                var er = document.getElementById('adError');
                er.textContent = d.error;
                er.style.display = '';
                return;
            }
            document.getElementById('adTitle').textContent = d.alliance_name + '　実績詳細';
            document.getElementById('adFyLabel').textContent = d.fy_label;
            var s = d.summary;
            document.getElementById('adRevenue').textContent    = s.revenue.toLocaleString() + '円';
            document.getElementById('adProfit').textContent     = s.profit.toLocaleString() + '円';
            document.getElementById('adCost').textContent       = s.cost.toLocaleString() + '円';
            document.getElementById('adMargin').textContent     = s.margin !== null ? s.margin + '%' : '-';
            document.getElementById('adStaffCount').textContent = s.staff_count + '名';
            var avgEl = document.getElementById('adAvgRate');
            avgEl.textContent = s.avg_rate !== null ? s.avg_rate + '%' : '-';
            avgEl.style.color = s.avg_rate === null ? '' : (s.avg_rate >= 100 ? '#059669' : (s.avg_rate >= 70 ? '#d97706' : '#ef4444'));

            // ヘッダー（スタッフ名 + 9月〜8月）
            var head = '<tr><th style="min-width:90px;white-space:nowrap">スタッフ名</th>';
            d.months.forEach(function(m){ head += '<th class="text-center" style="min-width:52px">' + m + '月</th>'; });
            head += '</tr>';
            document.getElementById('adTableHead').innerHTML = head;

            // 明細
            var body = '';
            if (!d.staff.length) {
                body = '<tr><td colspan="13" class="text-center text-muted py-3">この年度に稼働したスタッフがいません</td></tr>';
            } else {
                d.staff.forEach(function(st) {
                    body += '<tr><td class="fw-medium" style="white-space:nowrap">' + _pieEsc(st.name) + '</td>';
                    st.cells.forEach(function(c) {
                        if (!c.worked) {
                            body += '<td class="text-center text-muted">-</td>';
                        } else if (c.rate === null) {
                            body += '<td class="text-center text-muted" title="予算未設定">-</td>';
                        } else {
                            var col = c.rate >= 100 ? '#059669' : (c.rate >= 70 ? '#d97706' : '#ef4444');
                            body += '<td class="text-center fw-semibold" style="color:' + col + '">' + c.rate + '%</td>';
                        }
                    });
                    body += '</tr>';
                });
            }
            document.getElementById('adTableBody').innerHTML = body;
            document.getElementById('adContent').style.display = '';
        })
        .catch(function() {
            document.getElementById('adLoading').style.display = 'none';
            var er = document.getElementById('adError');
            er.textContent = '通信エラーが発生しました';
            er.style.display = '';
        });
}
ALLIANCEJS;

require_once __DIR__ . '/../includes/footer.php';
?>
