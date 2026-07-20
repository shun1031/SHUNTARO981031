<?php
/**
 * アライアンス会社別 実績詳細 API
 * GET ?alliance_id=&year=  （年度: year Y = Sep(Y-1)〜Aug(Y)）
 * 返却: 会社名 / 年間サマリー（売上・粗利・原価・粗利率・在籍スタッフ数・平均達成率）
 *       / スタッフ別 月別達成率（日報実績×店舗予算から算出）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['super_admin', 'company_admin'], true)) {
    echo json_encode(['error' => '権限がありません']); exit;
}

$db         = getDB();
$allianceId = (int)($_GET['alliance_id'] ?? 0);
$year       = (int)($_GET['year'] ?? date('Y'));
if (!$allianceId) { echo json_encode(['error' => 'alliance_idが必要です']); exit; }

session_write_close(); // 長めの集計中に他リクエストをブロックしない

// アライアンス会社名
$aSt = $db->prepare('SELECT alliance_name FROM sales_alliances WHERE id = ? AND company_id = ?');
$aSt->execute([$allianceId, $cid]);
$allianceName = $aSt->fetchColumn();
if ($allianceName === false) { echo json_encode(['error' => 'アライアンス会社が見つかりません']); exit; }

// 年度の月リスト（9月〜翌8月）
$fyMonths = [];
for ($mi = 0; $mi < 12; $mi++) {
    $m = ($mi + 8) % 12 + 1;
    $fyMonths[] = ['y' => ($m >= 9) ? $year - 1 : $year, 'm' => $m];
}
$fyStart = sprintf('%04d-09-01', $year - 1);
$fyEnd   = sprintf('%04d-08-31', $year);

// ─── 案件集計（会社サマリー + スタッフ×月の稼働） ───
$cSt = $db->prepare("
    SELECT case_year, case_month, worker_name,
           COALESCE(SUM(revenue),0) AS rev, COALESCE(SUM(gross_profit),0) AS profit, COALESCE(SUM(cost),0) AS cost
    FROM sales_cases
    WHERE company_id = ? AND alliance_id = ? AND status = 'confirmed'
      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))
    GROUP BY case_year, case_month, worker_name
");
$cSt->execute([$cid, $allianceId, $year - 1, $year]);

$totalRev = 0; $totalProfit = 0; $totalCost = 0;
$workedMap = []; // worker_name => set of "y-m"
foreach ($cSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $totalRev    += (int)$r['rev'];
    $totalProfit += (int)$r['profit'];
    $totalCost   += (int)$r['cost'];
    $w = trim($r['worker_name'] ?? '');
    if ($w !== '') {
        $workedMap[$w][sprintf('%04d-%02d', (int)$r['case_year'], (int)$r['case_month'])] = true;
    }
}
$workers = array_keys($workedMap);
sort($workers);

// ─── 日報実績（固定合計）: スタッフ×月 ───
$actualMap = []; // name => "y-m" => actual
if ($workers) {
    $ph = implode(',', array_fill(0, count($workers), '?'));
    $dSt = $db->prepare("
        SELECT employee_name, DATE_FORMAT(work_date, '%Y-%m') AS ym,
               GROUP_CONCAT(personal_acquisition_detail SEPARATOR '|||') AS per_jsons
        FROM sales_daily_reports
        WHERE company_id = ? AND work_date BETWEEN ? AND ? AND employee_name IN ($ph)
        GROUP BY employee_name, DATE_FORMAT(work_date, '%Y-%m')
    ");
    $dSt->execute(array_merge([$cid, $fyStart, $fyEnd], $workers));
    foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $actual = 0;
        foreach (array_filter(explode('|||', $r['per_jsons'] ?? '')) as $j) {
            $jd = json_decode($j, true) ?: [];
            $actual += (int)($jd['固定合計'] ?? 0);
        }
        $actualMap[$r['employee_name']][$r['ym']] = $actual;
    }
}

// ─── 店舗予算（固定合計）: スタッフ×月 ───
$budgetMap = []; // name => "y-m" => budget
if ($workers) {
    $ph = implode(',', array_fill(0, count($workers), '?'));
    $bSt = $db->prepare("
        SELECT employee_name, year, month, budget_detail
        FROM store_monthly_budgets
        WHERE company_id = ? AND employee_name IN ($ph)
          AND ((year = ? AND month >= 9) OR (year = ? AND month <= 8))
        ORDER BY id ASC
    ");
    $bSt->execute(array_merge([$cid], $workers, [$year - 1, $year]));
    foreach ($bSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bD = json_decode($r['budget_detail'] ?? '{}', true) ?: [];
        // id昇順で上書き → 最新行が残る
        $budgetMap[$r['employee_name']][sprintf('%04d-%02d', (int)$r['year'], (int)$r['month'])] = (int)($bD['固定合計'] ?? 0);
    }
}

// ─── スタッフ×月の達成率テーブル ───
$staffRows = [];
$allRates  = [];
foreach ($workers as $w) {
    $cells = [];
    foreach ($fyMonths as $fm) {
        $key = sprintf('%04d-%02d', $fm['y'], $fm['m']);
        if (empty($workedMap[$w][$key])) {
            $cells[] = ['worked' => false, 'rate' => null];
            continue;
        }
        $actual = $actualMap[$w][$key] ?? 0;
        $budget = $budgetMap[$w][$key] ?? 0;
        $rate   = $budget > 0 ? round($actual / $budget * 100, 1) : null;
        if ($rate !== null) $allRates[] = $rate;
        $cells[] = ['worked' => true, 'rate' => $rate];
    }
    $staffRows[] = ['name' => $w, 'cells' => $cells];
}

$margin  = $totalRev > 0 ? round($totalProfit / $totalRev * 100, 1) : null;
$avgRate = count($allRates) > 0 ? round(array_sum($allRates) / count($allRates), 1) : null;

echo json_encode([
    'alliance_name' => $allianceName,
    'fy_label'      => ($year - 1) . '年9月〜' . $year . '年8月',
    'summary' => [
        'revenue'     => $totalRev,
        'profit'      => $totalProfit,
        'cost'        => $totalCost,
        'margin'      => $margin,
        'staff_count' => count($workers),
        'avg_rate'    => $avgRate,
    ],
    'months' => array_map(fn($fm) => $fm['m'], $fyMonths),
    'staff'  => $staffRows,
], JSON_UNESCAPED_UNICODE);
