<?php
/**
 * 日報管理 KPI・一覧 API
 * GET ?employee=&year=&month=&filter_type=employee|work_type|carrier&filter_value=
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }

$db        = getDB();
$empFilter = getEmployeeNameFilter();
session_write_close(); // セッションロック解放（長時間クエリ中に他リクエストをブロックしないため）
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));

// フィルター種別: employee / work_type / carrier
$filterType  = $_GET['filter_type']  ?? 'employee';
$filterValue = $_GET['filter_value'] ?? ($_GET['employee'] ?? '');
$employee    = '';

// 一般社員は自分のみ強制
if ($empFilter !== null) {
    $filterType  = 'employee';
    $filterValue = $empFilter;
}
if ($filterType === 'employee') {
    $employee = $filterValue;
}

// ─── 業務形態設定（BIZ_CONFIG）────────────────────────────────────────────
$BIZ_CONFIG = [
    '光AD' => [
        'require_budget' => true,
        'catch_label'    => '来店組数',
        'seated_label'   => '接客組数',
        'budget_items'   => ['固定合計','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード'],
        'personal_items' => ['固定合計','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード'],
        'primary_kpi'    => '固定合計',
    ],
    '業務委託' => [
        'require_budget' => false,
        'catch_label'    => 'キャッチ数',
        'seated_label'   => '着座数',
        'budget_items'   => [],
        'personal_items' => ['MNP','アップ','ダウン','機変','転用','事変','1→10','光新規','Air新規','Air機変','でんき','カード','セレクション単価'],
        'primary_kpi'    => null,
    ],
];
$BIZ_ALIASES = ['ショップ' => '光AD', 'ショップ以外' => '業務委託'];

function normBiz(string $wt, array $aliases): string {
    return $aliases[$wt] ?? $wt;
}

// ─── キャリア別項目定義（後方互換維持）───────────────────────────────────
$CARRIER_ITEMS = [
    'SB,YM'    => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','Air新規','Airキヘン','SBでんき','PayPayカード'],
    'au,UQ'    => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','auでんき','au PAYカード'],
    'ドコモ'   => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','ドコモでんき','dカード'],
    '楽天'     => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','楽天でんき','楽天カード'],
    'コミュファ'=> ['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G'],
    'CATV'     => ['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','電力系→1G','電力系→10G','その他光→1G','その他光→10G'],
];

function getJsonVal(string $json, string $label): int {
    if (!$json) return 0;
    $d = json_decode($json, true);
    return isset($d[$label]) ? (int)$d[$label] : 0;
}

function fetchKpi(PDO $db, int $cid, string $emp, string $startDate, string $endDate, string $filterType = 'employee', string $filterValue = ''): array {
    $sql = "SELECT COALESCE(SUM(catch_count),0) AS catch_count,
        COALESCE(SUM(event_seated),0) AS event_seated,
        COALESCE(SUM(event_proposals),0) AS event_proposals,
        COALESCE(SUM(event_negotiations),0) AS event_negotiations,
        COALESCE(SUM(event_contracts),0) AS event_contracts,
        COUNT(*) AS report_count
    FROM sales_daily_reports WHERE company_id=? AND work_date BETWEEN ? AND ?";
    $params = [$cid, $startDate, $endDate];
    if ($emp) {
        $sql .= " AND employee_name=?"; $params[] = $emp;
    } elseif ($filterType === 'work_type' && $filterValue) {
        $sql .= " AND (work_type=? OR (work_type IN ('ショップ') AND ?='光AD') OR (work_type IN ('ショップ以外') AND ?='業務委託'))";
        $params[] = $filterValue; $params[] = $filterValue; $params[] = $filterValue;
    } elseif ($filterType === 'carrier' && $filterValue) {
        $sql .= " AND carrier=?"; $params[] = $filterValue;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['catch_count'=>0,'event_seated'=>0,'event_proposals'=>0,'event_negotiations'=>0,'event_contracts'=>0,'report_count'=>0];
}

// 月の範囲
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// 前月
$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY--; }
$prevStart = sprintf('%04d-%02d-01', $prevY, $prevM);
$prevEnd   = date('Y-m-t', strtotime($prevStart));

// 直近1週間
$weekEnd   = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('-6 days'));

// goal/budget カラム自動追加
foreach (['goal_type VARCHAR(10) DEFAULT NULL', 'goal_value INT DEFAULT NULL'] as $_colDef) {
    try { $db->exec("ALTER TABLE sales_daily_reports ADD COLUMN {$_colDef}"); } catch (PDOException $e) {}
}
try {
    $db->exec("CREATE TABLE IF NOT EXISTS store_monthly_budgets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_name VARCHAR(100) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, budget_detail TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smb (company_id, employee_name, year, month), INDEX idx_smb_emp (company_id, employee_name, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

$kpiMonth = fetchKpi($db, $cid, $employee, $monthStart, $monthEnd, $filterType, $filterValue);
$kpiPrev  = fetchKpi($db, $cid, $employee, $prevStart,  $prevEnd,  $filterType, $filterValue);
$kpiWeek  = fetchKpi($db, $cid, $employee, $weekStart,  $weekEnd,  $filterType, $filterValue);

// ─── 日報一覧クエリ構築 ────────────────────────────────────────────────
$listSql = "SELECT id, work_date, employee_name, location, carrier,
    catch_count, event_seated, event_proposals, event_negotiations, event_contracts,
    event_acquisition_detail, personal_acquisition_detail,
    shop_acquisition_detail, shop_fixed_check_detail, work_type,
    goal_type, goal_value
    FROM sales_daily_reports
    WHERE company_id=? AND work_date BETWEEN ? AND ?";
$listParams = [$cid, $monthStart, $monthEnd];

if ($employee) {
    $listSql .= " AND employee_name=?"; $listParams[] = $employee;
} elseif ($filterType === 'work_type' && $filterValue) {
    if ($filterValue === '光AD')   { $listSql .= " AND work_type IN ('光AD','ショップ')"; }
    elseif ($filterValue === '業務委託') { $listSql .= " AND work_type IN ('業務委託','ショップ以外')"; }
    else { $listSql .= " AND work_type=?"; $listParams[] = $filterValue; }
} elseif ($filterType === 'carrier' && $filterValue) {
    $listSql .= " AND carrier=?"; $listParams[] = $filterValue;
}
$listSql .= " ORDER BY work_date DESC";

$listStmt = $db->prepare($listSql);
$listStmt->execute($listParams);
$rawReports = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── 一覧データ成形 ────────────────────────────────────────────────────
$reports = [];
foreach ($rawReports as $r) {
    $carrier  = $r['carrier'] ?? '';
    $wt       = normBiz($r['work_type'] ?? '', $BIZ_ALIASES);
    $bizConf  = $BIZ_CONFIG[$wt] ?? null;
    $bizItems = $bizConf ? $bizConf['personal_items'] : [];
    $perJson  = $r['personal_acquisition_detail'] ?? '';

    $acqData = [];
    foreach ($bizItems as $label) {
        $person = getJsonVal($perJson, $label);
        $acqData[$label] = ['person' => $person];
    }

    // キャリア別（後方互換）
    $items    = $CARRIER_ITEMS[$carrier] ?? [];
    $evtJson  = $r['event_acquisition_detail']  ?? '';
    $shopJson = $r['shop_acquisition_detail'] ?? '';
    $shopPerJson = $r['shop_fixed_check_detail'] ?? '';
    foreach ($items as $label) {
        if (!isset($acqData[$label])) {
            $total  = getJsonVal($evtJson ?: $shopJson, $label);
            $person = getJsonVal($perJson ?: $shopPerJson, $label);
            $acqData[$label] = ['person' => $person, 'total' => $total];
        }
    }

    $contracts = (int)($r['event_contracts'] ?? 0);
    $reports[] = [
        'id'              => $r['id'],
        'work_date'       => $r['work_date'],
        'employee'        => $r['employee_name'],
        'location'        => $r['location'] ?? '',
        'carrier'         => $carrier,
        'work_type'       => $r['work_type'] ?? '',
        'biz_type'        => $wt,
        'carrier_items'   => $items,
        'biz_items'       => $bizItems,
        'catch'           => (int)($r['catch_count'] ?? 0),
        'seated'          => (int)($r['event_seated'] ?? 0),
        'proposals'       => (int)($r['event_proposals'] ?? 0),
        'negotiations'    => (int)($r['event_negotiations'] ?? 0),
        'contracts'       => $contracts,
        'acq'             => $acqData,
    ];
}

// ─── 全社員レポート（全体集計用） ────────────────────────────────────────
$allListSql = "SELECT carrier, work_type, event_acquisition_detail, personal_acquisition_detail,
    catch_count, event_seated, event_proposals, event_negotiations, event_contracts
    FROM sales_daily_reports WHERE company_id=? AND work_date BETWEEN ? AND ?";
$allListStmt = $db->prepare($allListSql);
$allListStmt->execute([$cid, $monthStart, $monthEnd]);
$allRawReports = $allListStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── 全社員KPI ─────────────────────────────────────────────────────────
$kpiAll = fetchKpi($db, $cid, '', $monthStart, $monthEnd);

// ─── 業務形態自動検出 ──────────────────────────────────────────────────
$bizCount = [];
foreach ($rawReports as $r) {
    $wt = normBiz($r['work_type'] ?? '', $BIZ_ALIASES);
    if ($wt) $bizCount[$wt] = ($bizCount[$wt] ?? 0) + 1;
}
arsort($bizCount);
$autoBizType = $bizCount ? (string)key($bizCount) : null;
$autoBizConf = $autoBizType ? ($BIZ_CONFIG[$autoBizType] ?? null) : null;

// ─── キャリア自動検出 ─────────────────────────────────────────────────
$carrierFreq = [];
foreach ($rawReports as $r) {
    $c = $r['carrier'] ?? '';
    if ($c) $carrierFreq[$c] = ($carrierFreq[$c] ?? 0) + 1;
}
arsort($carrierFreq);
$autoCarrier = $carrierFreq ? (string)key($carrierFreq) : null;

// ─── 業務形態別アイテムKPI集計 ────────────────────────────────────────
// personal_acquisition_detail を集計し、予算と照合
$bizItemKpi = [];
if ($autoBizConf) {
    $bizItems = $autoBizConf['personal_items'];
    foreach ($bizItems as $label) {
        $bizItemKpi[$label] = ['actual' => 0, 'budget' => null];
    }
    foreach ($rawReports as $r) {
        $perData = json_decode($r['personal_acquisition_detail'] ?? '{}', true) ?: [];
        foreach ($bizItems as $label) {
            $bizItemKpi[$label]['actual'] += (int)($perData[$label] ?? 0);
        }
    }
    // 予算取得（社員指定時: その社員の予算、グループ時: 平均）
    if ($employee && $autoBizConf['require_budget']) {
        $bStmt = $db->prepare("SELECT budget_detail FROM store_monthly_budgets WHERE company_id=? AND employee_name=? AND year=? AND month=? ORDER BY id DESC LIMIT 1");
        $bStmt->execute([$cid, $employee, $year, $month]);
        $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
        if ($bRow) {
            $bDetail = json_decode($bRow['budget_detail'] ?? '{}', true) ?: [];
            foreach ($bizItems as $label) {
                $bizItemKpi[$label]['budget'] = isset($bDetail[$label]) ? (int)$bDetail[$label] : 0;
            }
        }
    }
}

// ─── キャリア別アイテムKPI集計（後方互換） ────────────────────────────
$carrierItemKpi = [];
foreach ($CARRIER_ITEMS as $carrier => $items) {
    foreach ($items as $label) {
        $carrierItemKpi[$carrier][$label] = ['total' => 0, 'personal' => 0];
    }
}
foreach ($allRawReports as $r) {
    $c = $r['carrier'] ?? '';
    if (!isset($CARRIER_ITEMS[$c])) continue;
    $evtData = json_decode($r['event_acquisition_detail'] ?? '{}', true) ?: [];
    foreach ($CARRIER_ITEMS[$c] as $label) {
        $carrierItemKpi[$c][$label]['total'] += (int)($evtData[$label] ?? 0);
    }
}
foreach ($rawReports as $r) {
    $c = $r['carrier'] ?? '';
    if (!isset($CARRIER_ITEMS[$c])) continue;
    $perData = json_decode($r['personal_acquisition_detail'] ?? '{}', true) ?: [];
    foreach ($CARRIER_ITEMS[$c] as $label) {
        $carrierItemKpi[$c][$label]['personal'] += (int)($perData[$label] ?? 0);
    }
}

// ─── 商材別年間推移（選択社員の個人獲得、autoCarrier基準） ──────────────
$itemAnnualTrend = [];
if ($employee && $autoCarrier && isset($CARRIER_ITEMS[$autoCarrier])) {
    $yearStart = sprintf('%04d-01-01', $year);
    $yearEnd   = sprintf('%04d-12-31', $year);
    $iaSql = "SELECT MONTH(work_date) AS m, personal_acquisition_detail
              FROM sales_daily_reports
              WHERE company_id=? AND employee_name=? AND carrier=? AND work_date BETWEEN ? AND ?";
    $iaStmt = $db->prepare($iaSql);
    $iaStmt->execute([$cid, $employee, $autoCarrier, $yearStart, $yearEnd]);
    $iaRows = $iaStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($CARRIER_ITEMS[$autoCarrier] as $itemLabel) {
        $monthData = [];
        for ($m = 1; $m <= 12; $m++) { $monthData[$m] = 0; }
        foreach ($iaRows as $ir) {
            $im = (int)$ir['m'];
            $perD = json_decode($ir['personal_acquisition_detail'] ?? '{}', true) ?: [];
            $monthData[$im] += (int)($perD[$itemLabel] ?? 0);
        }
        $arr = [];
        for ($m = 1; $m <= 12; $m++) {
            $arr[] = ['month' => $m, 'value' => $monthData[$m]];
        }
        $itemAnnualTrend[$itemLabel] = $arr;
    }
}

// ─── 目標達成率ランキング（自社外注のみ） ──────────────────────────────
$ranking = [];
try {
    $rankSql = "
        SELECT sub.employee_name, sub.location, sub.contracts, sub.goal_cnt
        FROM (
            SELECT r.employee_name, MAX(r.location) AS location,
                   COALESCE(SUM(r.event_contracts), 0) AS contracts,
                   COALESCE(SUM(CASE WHEN r.goal_type='件数' AND r.goal_value > 0 THEN r.goal_value ELSE 0 END), 0) AS goal_cnt
            FROM sales_daily_reports r
            INNER JOIN employees e ON e.company_id = r.company_id AND e.name = r.employee_name
            WHERE r.company_id = ? AND r.work_date BETWEEN ? AND ?
              AND e.employment_type = '自社' AND e.employment_subtype = '外注' AND e.is_active = 1
            GROUP BY r.employee_name
        ) AS sub
        ORDER BY (CASE WHEN sub.goal_cnt > 0 THEN sub.contracts / sub.goal_cnt ELSE -1 END) DESC";
    $rankStmt = $db->prepare($rankSql);
    $rankStmt->execute([$cid, $monthStart, $monthEnd]);
    $rankRows = $rankStmt->fetchAll(PDO::FETCH_ASSOC);

    $prevRankSql = "
        SELECT sub.employee_name FROM (
            SELECT r.employee_name,
                   COALESCE(SUM(r.event_contracts), 0) AS contracts,
                   COALESCE(SUM(CASE WHEN r.goal_type='件数' AND r.goal_value > 0 THEN r.goal_value ELSE 0 END), 0) AS goal_cnt
            FROM sales_daily_reports r
            INNER JOIN employees e ON e.company_id = r.company_id AND e.name = r.employee_name
            WHERE r.company_id = ? AND r.work_date BETWEEN ? AND ?
              AND e.employment_type = '自社' AND e.employment_subtype = '外注' AND e.is_active = 1
            GROUP BY r.employee_name
        ) AS sub
        ORDER BY (CASE WHEN sub.goal_cnt > 0 THEN sub.contracts / sub.goal_cnt ELSE -1 END) DESC";
    $prevRankStmt = $db->prepare($prevRankSql);
    $prevRankStmt->execute([$cid, $prevStart, $prevEnd]);
    $prevRankRows = $prevRankStmt->fetchAll(PDO::FETCH_ASSOC);
    $prevRankMap = [];
    foreach ($prevRankRows as $pi => $pr) { $prevRankMap[$pr['employee_name']] = $pi + 1; }

    foreach ($rankRows as $ri => $rr) {
        $rGoal = (int)$rr['goal_cnt'];
        $rContracts = (int)$rr['contracts'];
        $rRate = $rGoal > 0 ? round($rContracts / $rGoal * 100, 1) : null;
        $ranking[] = [
            'rank'             => $ri + 1,
            'employee_name'    => $rr['employee_name'],
            'location'         => $rr['location'] ?? '',
            'contracts'        => $rContracts,
            'goal'             => $rGoal,
            'achievement_rate' => $rRate,
            'prev_rank'        => $prevRankMap[$rr['employee_name']] ?? null,
        ];
    }
} catch (PDOException $e) {}

// ─── 年間推移（月別: 個人獲得実績 + 予算達成率）年度: year Y = Sep(Y-1)〜Aug(Y) ──────
// 12回ループ×2クエリ(計24) → 2クエリに削減
$fyStart = sprintf('%04d-09-01', $year - 1);
$fyEnd   = sprintf('%04d-08-31', $year);

// 1クエリ: 年度全月の実績をまとめて取得
$tSql = "SELECT YEAR(work_date) AS yr, MONTH(work_date) AS mo,
    COALESCE(SUM(event_contracts),0) AS contracts,
    GROUP_CONCAT(personal_acquisition_detail SEPARATOR '|||') AS per_jsons
FROM sales_daily_reports WHERE company_id=? AND work_date BETWEEN ? AND ?";
$tp = [$cid, $fyStart, $fyEnd];
if ($employee) { $tSql .= " AND employee_name=?"; $tp[] = $employee; }
elseif ($filterType === 'work_type' && $filterValue) {
    if ($filterValue === '光AD') { $tSql .= " AND work_type IN ('光AD','ショップ')"; }
    elseif ($filterValue === '業務委託') { $tSql .= " AND work_type IN ('業務委託','ショップ以外')"; }
} elseif ($filterType === 'carrier' && $filterValue) {
    $tSql .= " AND carrier=?"; $tp[] = $filterValue;
}
$tSql .= " GROUP BY YEAR(work_date), MONTH(work_date)";
$tStmt = $db->prepare($tSql);
$tStmt->execute($tp);
$monthlyActuals = [];
foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthlyActuals[sprintf('%04d-%02d', (int)$row['yr'], (int)$row['mo'])] = $row;
}

// 1クエリ: 年度全月の予算をまとめて取得（社員フィルター時のみ）
$budgetByMonth = [];
if ($employee && $autoBizConf && $autoBizConf['require_budget']) {
    $bSql = "SELECT year, month, budget_detail FROM store_monthly_budgets
        WHERE company_id=? AND employee_name=?
        AND ((year=? AND month>=9) OR (year=? AND month<=8))
        ORDER BY id ASC";
    $bStmt = $db->prepare($bSql);
    $bStmt->execute([$cid, $employee, $year - 1, $year]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
        // id昇順で上書き → 最新行が残る
        $budgetByMonth[sprintf('%04d-%02d', (int)$bRow['year'], (int)$bRow['month'])] = $bRow['budget_detail'];
    }
}

// メモリ上で12ヶ月分を組み立て
$annualTrend = [];
$fyRates     = [];
for ($mi = 0; $mi < 12; $mi++) {
    $m   = ($mi + 8) % 12 + 1;
    $my  = ($m >= 9) ? ($year - 1) : $year;
    $key = sprintf('%04d-%02d', $my, $m);

    $tRow      = $monthlyActuals[$key] ?? ['contracts' => 0, 'per_jsons' => ''];
    $contracts = (int)($tRow['contracts'] ?? 0);

    $budgetAchRate = null;
    $actualPrimary = 0;
    $budgetPrimary = 0;
    if ($autoBizConf && $autoBizConf['require_budget'] && $autoBizConf['primary_kpi']) {
        $primaryKpi = $autoBizConf['primary_kpi'];
        foreach (array_filter(explode('|||', $tRow['per_jsons'] ?? '')) as $j) {
            $jd = json_decode($j, true) ?: [];
            $actualPrimary += (int)($jd[$primaryKpi] ?? 0);
        }
        if (isset($budgetByMonth[$key])) {
            $bD = json_decode($budgetByMonth[$key] ?? '{}', true) ?: [];
            $budgetPrimary = (int)($bD[$primaryKpi] ?? 0);
            if ($budgetPrimary > 0) {
                $budgetAchRate = round($actualPrimary / $budgetPrimary * 100, 1);
            }
        }
    }
    if ($budgetAchRate !== null) $fyRates[] = $budgetAchRate;

    $annualTrend[] = [
        'month'                  => $m,
        'personal'               => $contracts,
        'actual_primary'         => $actualPrimary,
        'budget_primary'         => $budgetPrimary,
        'achievement_rate'       => null,
        'budget_achievement_rate'=> $budgetAchRate,
    ];
}
$fyAvgAchRate = count($fyRates) > 0 ? round(array_sum($fyRates) / count($fyRates), 1) : null;

// ─── グループ平均達成率（業務形態/キャリアフィルター時） ──────────────────
$groupAvgAchRate = null;
if ($filterType !== 'employee' && $filterValue && $autoBizConf && $autoBizConf['require_budget']) {
    $primaryKpi = $autoBizConf['primary_kpi'];
    // 月の実績合計 / 月の予算合計
    $gActual = 0;
    foreach ($rawReports as $r) {
        $perData = json_decode($r['personal_acquisition_detail'] ?? '{}', true) ?: [];
        $gActual += (int)($perData[$primaryKpi] ?? 0);
    }
    // 各社員の予算を合計
    $empNames = array_unique(array_column($rawReports, 'employee_name'));
    $gBudget = 0;
    foreach ($empNames as $en) {
        $bSt = $db->prepare("SELECT budget_detail FROM store_monthly_budgets WHERE company_id=? AND employee_name=? AND year=? AND month=?");
        $bSt->execute([$cid, $en, $year, $month]);
        $bR = $bSt->fetch(PDO::FETCH_ASSOC);
        if ($bR) {
            $bD = json_decode($bR['budget_detail'] ?? '{}', true) ?: [];
            $gBudget += (int)($bD[$primaryKpi] ?? 0);
        }
    }
    if ($gBudget > 0) {
        $groupAvgAchRate = round($gActual / $gBudget * 100, 1);
    }
}

// ─── 日報提出済み社員一覧 ─────────────────────────────────────────────
$empSql  = "SELECT DISTINCT employee_name FROM sales_daily_reports WHERE company_id=? ORDER BY employee_name";
$empStmt = $db->prepare($empSql);
$empStmt->execute([$cid]);
$employees = $empStmt->fetchAll(PDO::FETCH_COLUMN);

// ─── レスポンス ───────────────────────────────────────────────────────
echo json_encode([
    'kpi_month'              => $kpiMonth,
    'kpi_all'                => $kpiAll,
    'kpi_prev'               => $kpiPrev,
    'kpi_week'               => $kpiWeek,
    'reports'                => $reports,
    'employees'              => $employees,
    'employee'               => $employee,
    'filter_type'            => $filterType,
    'filter_value'           => $filterValue,
    'year'                   => $year,
    'month'                  => $month,
    'biz_config'             => $BIZ_CONFIG,
    'auto_biz_type'          => $autoBizType,
    'biz_item_kpi'           => $bizItemKpi,
    'carrier_items'          => $CARRIER_ITEMS,
    'carrier_item_kpi'       => $carrierItemKpi,
    'annual_trend'           => $annualTrend,
    'fy_avg_achievement_rate'=> $fyAvgAchRate,
    'auto_carrier'           => $autoCarrier,
    'item_annual_trend'      => $itemAnnualTrend,
    'ranking'                => $ranking,
    'group_avg_ach_rate'     => $groupAvgAchRate,
], JSON_UNESCAPED_UNICODE);
