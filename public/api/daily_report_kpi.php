<?php
/**
 * 日報管理 KPI・一覧 API
 * GET ?employee=&year=&month=
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }

$db       = getDB();
$empFilter = getEmployeeNameFilter(); // 一般社員は自分のみ
$employee  = $_GET['employee'] ?? '';
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));

// 権限チェック: 一般社員は自分のデータのみ
if ($empFilter !== null) { $employee = $empFilter; }

// ─── キャリア別項目定義（フォームと同一）─────────────────────────────
$CARRIER_ITEMS = [
    'SB,YM'    => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','Air新規','Airキヘン','SBでんき','PayPayカード'],
    'au,UQ'    => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','auでんき','au PAYカード'],
    'ドコモ'   => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','ドコモでんき','dカード'],
    '楽天'     => ['MNP','アップ','ダウン','機変','転用','事変','1G→10G','未利用→1G光','未利用→10G光','電力系→1G','電力系→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G','ホームルーター新規','ホームルーターキヘン','楽天でんき','楽天カード'],
    'コミュファ'=> ['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','CATV→1G','CATV→10G','その他光→1G','その他光→10G'],
    'CATV'     => ['未利用→1G光','未利用→10G光','コラボ光→1G','コラボ光→10G','電力系→1G','電力系→10G','その他光→1G','その他光→10G'],
];

// ─── ヘルパー: JSON から指定ラベルの値取得 ────────────────────────────
function getJsonVal(string $json, string $label): int {
    if (!$json) return 0;
    $d = json_decode($json, true);
    return isset($d[$label]) ? (int)$d[$label] : 0;
}

// ─── KPI 集計クエリ ────────────────────────────────────────────────────
function fetchKpi(PDO $db, int $cid, string $emp, string $startDate, string $endDate): array {
    $sql = "SELECT
        COALESCE(SUM(catch_count),0)         AS catch_count,
        COALESCE(SUM(event_seated),0)        AS event_seated,
        COALESCE(SUM(event_proposals),0)     AS event_proposals,
        COALESCE(SUM(event_negotiations),0)  AS event_negotiations,
        COALESCE(SUM(event_contracts),0)     AS event_contracts,
        COUNT(*)                             AS report_count
    FROM sales_daily_reports
    WHERE company_id=? AND work_date BETWEEN ? AND ?";
    $params = [$cid, $startDate, $endDate];
    if ($emp) { $sql .= " AND employee_name=?"; $params[] = $emp; }
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

// goal_type/goal_value カラムが未存在の場合に自動追加（初回アクセス対応）
foreach (['goal_type VARCHAR(10) DEFAULT NULL', 'goal_value INT DEFAULT NULL'] as $_colDef) {
    try { $db->exec("ALTER TABLE sales_daily_reports ADD COLUMN {$_colDef}"); } catch (PDOException $e) {}
}

$kpiMonth = fetchKpi($db, $cid, $employee, $monthStart, $monthEnd);
$kpiPrev  = fetchKpi($db, $cid, $employee, $prevStart,  $prevEnd);
$kpiWeek  = fetchKpi($db, $cid, $employee, $weekStart,  $weekEnd);

// ─── 日報一覧 ─────────────────────────────────────────────────────────
$listSql = "SELECT id, work_date, employee_name, location, carrier,
    catch_count, event_seated, event_proposals, event_negotiations, event_contracts,
    event_acquisition_detail, personal_acquisition_detail,
    shop_acquisition_detail, shop_fixed_check_detail, work_type,
    goal_type, goal_value
    FROM sales_daily_reports
    WHERE company_id=? AND work_date BETWEEN ? AND ?";
$listParams = [$cid, $monthStart, $monthEnd];
if ($employee) { $listSql .= " AND employee_name=?"; $listParams[] = $employee; }
$listSql .= " ORDER BY work_date DESC";
$listStmt = $db->prepare($listSql);
$listStmt->execute($listParams);
$rawReports = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// 一覧データを成形（carrier別の items を展開）
$reports = [];
foreach ($rawReports as $r) {
    $carrier  = $r['carrier'] ?? '';
    $items    = $CARRIER_ITEMS[$carrier] ?? [];
    $evtJson  = $r['event_acquisition_detail']  ?? '';
    $perJson  = $r['personal_acquisition_detail'] ?? '';
    $shopJson = $r['shop_acquisition_detail'] ?? '';
    $shopPerJson = $r['shop_fixed_check_detail'] ?? '';

    $acqData = [];
    foreach ($items as $label) {
        $total  = getJsonVal($evtJson  ?: $shopJson, $label);
        $person = getJsonVal($perJson  ?: $shopPerJson, $label);
        $acqData[$label] = ['person' => $person, 'total' => $total];
    }

    // 達成率計算
    $goalType  = $r['goal_type'] ?? null;
    $goalValue = (int)($r['goal_value'] ?? 0);
    $contracts = (int)($r['event_contracts'] ?? 0);
    $achieveRate = null;
    if ($goalType === '件数' && $goalValue > 0) {
        $achieveRate = round($contracts / $goalValue * 100, 1);
    }

    $reports[] = [
        'id'              => $r['id'],
        'work_date'       => $r['work_date'],
        'employee'        => $r['employee_name'],
        'location'        => $r['location'] ?? '',
        'carrier'         => $carrier,
        'carrier_items'   => $items,
        'catch'           => (int)($r['catch_count'] ?? 0),
        'seated'          => (int)($r['event_seated'] ?? 0),
        'proposals'       => (int)($r['event_proposals'] ?? 0),
        'negotiations'    => (int)($r['event_negotiations'] ?? 0),
        'contracts'       => $contracts,
        'acq'             => $acqData,
        'goal_type'       => $goalType,
        'goal_value'      => $goalValue,
        'achievement_rate'=> $achieveRate,
    ];
}

// ─── キャリア別KPI集計（当月） ────────────────────────────────────────
$carrierKpi = [];
foreach ($rawReports as $r) {
    $c = $r['carrier'] ?? '';
    if (!isset($CARRIER_ITEMS[$c])) continue;
    if (!isset($carrierKpi[$c])) {
        $carrierKpi[$c] = ['total' => 0, 'personal' => 0, 'contracts' => 0, 'goal' => 0, 'reports' => 0];
    }
    $carrierKpi[$c]['reports']++;
    $carrierKpi[$c]['contracts'] += (int)($r['event_contracts'] ?? 0);
    if (($r['goal_type'] ?? '') === '件数' && ($r['goal_value'] ?? 0) > 0) {
        $carrierKpi[$c]['goal'] += (int)$r['goal_value'];
    }
    $evtData = json_decode($r['event_acquisition_detail'] ?? '{}', true) ?: [];
    $perData = json_decode($r['personal_acquisition_detail'] ?? '{}', true) ?: [];
    foreach ($CARRIER_ITEMS[$c] as $label) {
        $carrierKpi[$c]['total']    += (int)($evtData[$label] ?? 0);
        $carrierKpi[$c]['personal'] += (int)($perData[$label] ?? 0);
    }
}
foreach ($carrierKpi as $c => &$kpi) {
    $kpi['achievement_rate'] = ($kpi['goal'] > 0) ? round($kpi['contracts'] / $kpi['goal'] * 100, 1) : null;
}
unset($kpi);

// ─── 年間推移（現在の年、月別） ────────────────────────────────────────
$annualTrend = [];
for ($m = 1; $m <= 12; $m++) {
    $ms = sprintf('%04d-%02d-01', $year, $m);
    $me = date('Y-m-t', strtotime($ms));
    $tSql = "SELECT COALESCE(SUM(event_contracts),0) AS contracts,
                    COALESCE(SUM(CASE WHEN goal_type='件数' AND goal_value > 0 THEN goal_value ELSE 0 END),0) AS goal_cnt
             FROM sales_daily_reports WHERE company_id=? AND work_date BETWEEN ? AND ?";
    $tp = [$cid, $ms, $me];
    if ($employee) { $tSql .= " AND employee_name=?"; $tp[] = $employee; }
    $tStmt = $db->prepare($tSql);
    $tStmt->execute($tp);
    $row = $tStmt->fetch(PDO::FETCH_ASSOC);
    $contracts = (int)($row['contracts'] ?? 0);
    $goalCnt   = (int)($row['goal_cnt'] ?? 0);
    $annualTrend[] = [
        'month'            => $m,
        'personal'         => $contracts,
        'achievement_rate' => $goalCnt > 0 ? round($contracts / $goalCnt * 100, 1) : null,
    ];
}

// ─── 日報提出済み社員一覧 ─────────────────────────────────────────────
$empSql = "SELECT DISTINCT employee_name FROM sales_daily_reports WHERE company_id=? ORDER BY employee_name";
$empStmt = $db->prepare($empSql);
$empStmt->execute([$cid]);
$employees = $empStmt->fetchAll(PDO::FETCH_COLUMN);

// ─── レスポンス ───────────────────────────────────────────────────────
echo json_encode([
    'kpi_month'    => $kpiMonth,
    'kpi_prev'     => $kpiPrev,
    'kpi_week'     => $kpiWeek,
    'reports'      => $reports,
    'employees'    => $employees,
    'employee'     => $employee,
    'year'         => $year,
    'month'        => $month,
    'carrier_items'=> $CARRIER_ITEMS,
    'carrier_kpi'  => $carrierKpi,
    'annual_trend' => $annualTrend,
], JSON_UNESCAPED_UNICODE);
