<?php
// ============================================================
// 売上管理: 集計/ダッシュボード/目標/担当者
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ----------------------------------------------------------------
// 集計
// ----------------------------------------------------------------

function getSalesMonthlySummary(int $companyId, int $year, int $month, ?string $caseType = null): array {
    $db = getDB();
    $where = "sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year, $month];
    if ($caseType) { $where .= ' AND sc.case_type = ?'; $params[] = $caseType; }

    $stmt = $db->prepare("SELECT
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as total_revenue,
        COALESCE(SUM(sc.cost),0) as total_cost,
        COALESCE(SUM(sc.gross_profit),0) as total_profit,
        CASE WHEN SUM(sc.revenue) > 0 THEN ROUND(SUM(sc.gross_profit)/SUM(sc.revenue)*100, 1) ELSE 0 END as avg_margin,
        COALESCE(SUM(sc.days_worked),0) as total_days,
        CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(sc.revenue)/COUNT(*)) ELSE 0 END as avg_revenue
    FROM sales_cases sc WHERE $where");
    $stmt->execute($params);
    return $stmt->fetch();
}

function getSalesAnnualSummary(int $companyId, int $year, ?string $employeeName = null): array {
    $db = getDB();
    $where = "sc.company_id = ? AND sc.case_year = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year];
    if ($employeeName !== null) { $where .= " AND (sc.sales_rep = ? OR sc.worker_name = ?)"; $params[] = $employeeName; $params[] = $employeeName; }
    $stmt = $db->prepare("SELECT
        sc.case_month,
        sc.case_type,
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as total_revenue,
        COALESCE(SUM(sc.cost),0) as total_cost,
        COALESCE(SUM(sc.gross_profit),0) as total_profit
    FROM sales_cases sc
    WHERE $where
    GROUP BY sc.case_month, sc.case_type
    ORDER BY sc.case_month, sc.case_type");
    $stmt->execute($params);

    $result = [];
    for ($m = 1; $m <= 12; $m++) {
        $result[$m] = [
            'total' => ['case_count' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0],
            'event' => ['case_count' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0],
            'regular' => ['case_count' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0],
        ];
    }
    foreach ($stmt->fetchAll() as $row) {
        $m = (int)$row['case_month'];
        $t = $row['case_type'];
        $result[$m][$t] = [
            'case_count' => (int)$row['case_count'],
            'revenue' => (int)$row['total_revenue'],
            'cost' => (int)$row['total_cost'],
            'profit' => (int)$row['total_profit'],
        ];
        $result[$m]['total']['case_count'] += (int)$row['case_count'];
        $result[$m]['total']['revenue'] += (int)$row['total_revenue'];
        $result[$m]['total']['cost'] += (int)$row['total_cost'];
        $result[$m]['total']['profit'] += (int)$row['total_profit'];
    }
    return $result;
}

function getSalesClientReport(int $companyId, int $year, ?string $employeeName = null): array {
    $db = getDB();
    $where = "sc.company_id = ? AND sc.case_year = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year];
    if ($employeeName !== null) { $where .= " AND (sc.sales_rep = ? OR sc.worker_name = ?)"; $params[] = $employeeName; $params[] = $employeeName; }
    $stmt = $db->prepare("SELECT
        cl.id as client_id, cl.client_name,
        sc.case_month,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.gross_profit),0) as profit,
        COUNT(*) as case_count
    FROM sales_cases sc
    JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE $where
    GROUP BY cl.id, cl.client_name, sc.case_month
    ORDER BY cl.client_name, sc.case_month");
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $cid = $row['client_id'];
        if (!isset($result[$cid])) {
            $result[$cid] = ['client_name' => $row['client_name'], 'months' => [], 'total_revenue' => 0, 'total_profit' => 0, 'total_cases' => 0];
        }
        $result[$cid]['months'][(int)$row['case_month']] = [
            'revenue' => (int)$row['revenue'],
            'profit' => (int)$row['profit'],
            'case_count' => (int)$row['case_count'],
        ];
        $result[$cid]['total_revenue'] += (int)$row['revenue'];
        $result[$cid]['total_profit'] += (int)$row['profit'];
        $result[$cid]['total_cases'] += (int)$row['case_count'];
    }
    // Sort by total revenue desc
    uasort($result, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);
    return $result;
}

function getSalesAllianceCostReport(int $companyId, int $year): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT
        al.id as alliance_id, al.alliance_name,
        sc.case_month,
        COALESCE(SUM(sc.cost),0) as cost,
        COUNT(*) as case_count
    FROM sales_cases sc
    JOIN sales_alliances al ON sc.alliance_id = al.id
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.status = 'confirmed'
    GROUP BY al.id, al.alliance_name, sc.case_month
    ORDER BY al.alliance_name, sc.case_month");
    $stmt->execute([$companyId, $year]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $aid = $row['alliance_id'];
        if (!isset($result[$aid])) {
            $result[$aid] = ['alliance_name' => $row['alliance_name'], 'months' => [], 'total_cost' => 0, 'total_cases' => 0];
        }
        $result[$aid]['months'][(int)$row['case_month']] = [
            'cost' => (int)$row['cost'],
            'case_count' => (int)$row['case_count'],
        ];
        $result[$aid]['total_cost'] += (int)$row['cost'];
        $result[$aid]['total_cases'] += (int)$row['case_count'];
    }
    uasort($result, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
    return $result;
}

function getSalesWorkerBreakdown(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT
        sc.worker_type,
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.cost),0) as cost,
        COALESCE(SUM(sc.gross_profit),0) as profit
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'
    GROUP BY sc.worker_type
    ORDER BY revenue DESC");
    $stmt->execute([$companyId, $year, $month]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// 目標
// ----------------------------------------------------------------

function getSalesTargets(int $companyId, int $year): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_targets WHERE company_id = ? AND target_year = ? ORDER BY target_month, target_type');
    $stmt->execute([$companyId, $year]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int)$row['target_month']][$row['target_type']] = $row;
    }
    return $result;
}

function upsertSalesTarget(int $companyId, int $year, int $month, string $type, int $revenueTarget, int $profitTarget = 0): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO sales_targets (company_id, target_year, target_month, target_type, revenue_target, profit_target)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE revenue_target = VALUES(revenue_target), profit_target = VALUES(profit_target)');
    $stmt->execute([$companyId, $year, $month, $type, $revenueTarget, $profitTarget]);
}

function getSalesAchievement(int $companyId, int $year, int $month): array {
    $targets = getSalesTargets($companyId, $year);
    $monthTarget = $targets[$month] ?? [];

    $total = getSalesMonthlySummary($companyId, $year, $month);
    $event = getSalesMonthlySummary($companyId, $year, $month, 'event');
    $regular = getSalesMonthlySummary($companyId, $year, $month, 'regular');

    $tgt = (int)($monthTarget['total']['revenue_target'] ?? 0);
    return [
        'target_revenue' => $tgt,
        'actual_revenue' => (int)$total['total_revenue'],
        'achievement_rate' => $tgt > 0 ? round((int)$total['total_revenue'] / $tgt * 100, 1) : 0,
        'total' => $total,
        'event' => $event,
        'regular' => $regular,
        'targets' => $monthTarget,
    ];
}

// ----------------------------------------------------------------
// ダッシュボード
// ----------------------------------------------------------------

function getSalesDashboardKPIs(int $companyId, int $year, int $month): array {
    $achievement = getSalesAchievement($companyId, $year, $month);
    $total = $achievement['total'];

    // 前月
    $prevMonth = $month - 1;
    $prevYear = $year;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $prevTotal = getSalesMonthlySummary($companyId, $prevYear, $prevMonth);

    $prevRevenue = (int)$prevTotal['total_revenue'];
    $curRevenue = (int)$total['total_revenue'];
    $momChange = $prevRevenue > 0 ? round(($curRevenue - $prevRevenue) / $prevRevenue * 100, 1) : 0;

    // 前年同月
    $prevYearTotal = getSalesMonthlySummary($companyId, $year - 1, $month);
    $prevYearRevenue = (int)$prevYearTotal['total_revenue'];
    $yoyChange = $prevYearRevenue > 0 ? round(($curRevenue - $prevYearRevenue) / $prevYearRevenue * 100, 1) : 0;

    // 外注比率
    $workerBreakdown = getSalesWorkerBreakdown($companyId, $year, $month);
    $totalCases = 0; $outsourceCases = 0;
    foreach ($workerBreakdown as $wb) {
        $totalCases += (int)$wb['case_count'];
        if (in_array($wb['worker_type'], ['アライアンス', '個人外注', '自社外注'])) {
            $outsourceCases += (int)$wb['case_count'];
        }
    }
    $outsourceRate = $totalCases > 0 ? round($outsourceCases / $totalCases * 100, 1) : 0;

    return [
        'revenue' => $curRevenue,
        'profit' => (int)$total['total_profit'],
        'margin' => (float)$total['avg_margin'],
        'case_count' => (int)$total['case_count'],
        'event_count' => (int)$achievement['event']['case_count'],
        'regular_count' => (int)$achievement['regular']['case_count'],
        'target_revenue' => $achievement['target_revenue'],
        'achievement_rate' => $achievement['achievement_rate'],
        'mom_change' => $momChange,
        'outsource_rate' => $outsourceRate,
        'prev_revenue' => $prevRevenue,
        'yoy_change' => $yoyChange,
        'prev_year_revenue' => $prevYearRevenue,
    ];
}

function getSalesRevenueTrend(int $companyId, int $year): array {
    $annual = getSalesAnnualSummary($companyId, $year);
    $targets = getSalesTargets($companyId, $year);

    $result = [];
    for ($m = 1; $m <= 12; $m++) {
        $result[$m] = [
            'revenue' => $annual[$m]['total']['revenue'],
            'profit' => $annual[$m]['total']['profit'],
            'event_revenue' => $annual[$m]['event']['revenue'],
            'regular_revenue' => $annual[$m]['regular']['revenue'],
            'target' => (int)($targets[$m]['total']['revenue_target'] ?? 0),
        ];
    }
    return $result;
}

function getSalesRevenueByClient(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT
        cl.client_name,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.gross_profit),0) as profit,
        COUNT(*) as case_count
    FROM sales_cases sc
    JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'
    GROUP BY cl.id, cl.client_name
    ORDER BY revenue DESC
    LIMIT 10");
    $stmt->execute([$companyId, $year, $month]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// CSV出力ヘルパー
// ----------------------------------------------------------------

function exportSalesCasesCsv(int $companyId, array $filters = []): void {
    $cases = getSalesCases($companyId, $filters, 10000, 0);
    $filename = 'sales_cases_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM
    fputcsv($out, ['ID','種別','年','月','取引先','営業担当','マネージャー','リクルーター','スタッフ区分','外注先','スタッフ名','屋号','エリア','店舗名','開始日','終了日','請求単価','支払単価','稼働日数','売上','原価','粗利','粗利率','ステータス']);
    foreach ($cases as $c) {
        fputcsv($out, [
            $c['id'], $c['case_type'] === 'event' ? 'イベント' : '常勤',
            $c['case_year'], $c['case_month'],
            $c['client_name'] ?? '', $c['sales_rep'], $c['manager'], $c['recruiter'],
            $c['worker_type'], $c['alliance_name'] ?? '', $c['worker_name'],
            $c['brand_name'] ?? '', $c['area_name'] ?? '', $c['store_name'],
            $c['start_date'], $c['end_date'],
            $c['unit_price_in'], $c['unit_price_out'], $c['days_worked'],
            $c['revenue'], $c['cost'], $c['gross_profit'],
            round($c['margin'] * 100, 1) . '%', $c['status'],
        ]);
    }
    fclose($out);
}

// ----------------------------------------------------------------
// 担当者（営業）関連
// ----------------------------------------------------------------

function getSalesReps(int $companyId, ?int $year = null): array {
    $db = getDB();
    $sql = "SELECT DISTINCT sales_rep FROM sales_cases WHERE company_id = ? AND sales_rep != '' AND status = 'confirmed'";
    $params = [$companyId];
    if ($year) { $sql .= ' AND case_year = ?'; $params[] = $year; }
    $sql .= ' ORDER BY sales_rep';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_column($stmt->fetchAll(), 'sales_rep');
}

function getSalesRepReport(int $companyId, int $year, ?string $employeeName = null): array {
    $db = getDB();
    // manager・recruiterもGROUP BY対象にしてPHP側で50/50分割を行う
    $sql = "SELECT
        sc.case_month, sc.case_type,
        sc.sales_rep, sc.manager, sc.recruiter,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.gross_profit),0) as profit,
        COUNT(*) as case_count,
        COALESCE(SUM(sc.new_transactions),0) as new_transactions,
        COALESCE(SUM(sc.negotiations_count),0) as negotiations_count,
        COALESCE(SUM(sc.contracts_count),0) as contracts_count
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.status != '終了'
      AND sc.sales_rep != ''";
    $params = [$companyId, $year];
    if ($employeeName !== null) {
        $sql .= " AND (sc.sales_rep = ? OR sc.manager = ? OR sc.recruiter = ?)";
        $params[] = $employeeName; $params[] = $employeeName; $params[] = $employeeName;
    }
    $sql .= " GROUP BY sc.case_month, sc.case_type, sc.sales_rep, sc.manager, sc.recruiter";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $result = [];
    $addEntry = function(string $name, int $month, string $type, int $revenue, int $profit, int $caseCount, int $newTx, int $neg, int $con) use (&$result): void {
        if (!isset($result[$name])) {
            $result[$name] = [
                'sales_rep' => $name, 'months' => [],
                'total_revenue' => 0, 'total_profit' => 0, 'total_cases' => 0,
                'event_revenue' => 0, 'regular_revenue' => 0,
                'total_new_transactions' => 0, 'total_negotiations' => 0, 'total_contracts' => 0,
            ];
            for ($m = 1; $m <= 12; $m++) {
                $result[$name]['months'][$m] = [
                    'revenue' => 0, 'profit' => 0, 'case_count' => 0,
                    'event_revenue' => 0, 'regular_revenue' => 0,
                    'new_transactions' => 0, 'negotiations_count' => 0, 'contracts_count' => 0,
                ];
            }
        }
        $result[$name]['months'][$month]['revenue']            += $revenue;
        $result[$name]['months'][$month]['profit']             += $profit;
        $result[$name]['months'][$month]['case_count']         += $caseCount;
        $result[$name]['months'][$month][$type . '_revenue']   += $revenue;
        $result[$name]['months'][$month]['new_transactions']   += $newTx;
        $result[$name]['months'][$month]['negotiations_count'] += $neg;
        $result[$name]['months'][$month]['contracts_count']    += $con;
        $result[$name]['total_revenue']          += $revenue;
        $result[$name]['total_profit']           += $profit;
        $result[$name]['total_cases']            += $caseCount;
        $result[$name][$type . '_revenue']       += $revenue;
        $result[$name]['total_new_transactions'] += $newTx;
        $result[$name]['total_negotiations']     += $neg;
        $result[$name]['total_contracts']        += $con;
    };

    foreach ($stmt->fetchAll() as $row) {
        $rep       = $row['sales_rep'];
        $manager   = trim($row['manager'] ?? '');
        $recruiter = trim($row['recruiter'] ?? '');
        $month     = (int)$row['case_month'];
        $type      = $row['case_type'];
        $revenue   = (int)$row['revenue'];
        $profit    = (int)$row['profit'];
        $count     = (int)$row['case_count'];
        $newTx     = (int)$row['new_transactions'];
        $neg       = (int)$row['negotiations_count'];
        $con       = (int)$row['contracts_count'];

        // 売上・粗利を50/50で分割（端数は営業担当側へ）
        $repRev = (int)round($revenue * 0.5);
        $refRev = $revenue - $repRev;
        $repPro = (int)round($profit * 0.5);
        $refPro = $profit - $repPro;

        // 営業担当: 50%（案件数・商談数・成約数は営業担当にのみ帰属）
        $addEntry($rep, $month, $type, $repRev, $repPro, $count, $newTx, $neg, $con);

        // 紹介元（マネージャー → リクルーター → 余売上）: 50%
        $referrer = $manager !== '' ? $manager : ($recruiter !== '' ? $recruiter : '余売上');
        $addEntry($referrer, $month, $type, $refRev, $refPro, 0, 0, 0, 0);
    }

    // 個人フィルター時は当該担当者の行のみ残す
    if ($employeeName !== null) {
        $result = array_filter($result, fn($v) => $v['sales_rep'] === $employeeName);
    }

    uasort($result, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);
    return $result;
}

function getSalesDashboardKPIsFiltered(int $companyId, int $year, int $month, string $salesRep = '', string $caseType = ''): array {
    if (!$salesRep && !$caseType) return getSalesDashboardKPIs($companyId, $year, $month);

    $db = getDB();
    $base = "sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year, $month];
    if ($salesRep) { $base .= " AND (sc.sales_rep = ? OR sc.worker_name = ?)"; $params[] = $salesRep; $params[] = $salesRep; }
    if ($caseType) { $base .= " AND sc.case_type = ?"; $params[] = $caseType; }

    $stmt = $db->prepare("SELECT
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as total_revenue,
        COALESCE(SUM(sc.cost),0) as total_cost,
        COALESCE(SUM(sc.gross_profit),0) as total_profit,
        CASE WHEN SUM(sc.revenue) > 0 THEN ROUND(SUM(sc.gross_profit)/SUM(sc.revenue)*100, 1) ELSE 0 END as avg_margin,
        SUM(CASE WHEN sc.case_type='event' THEN 1 ELSE 0 END) as event_count,
        SUM(CASE WHEN sc.case_type='regular' THEN 1 ELSE 0 END) as regular_count
    FROM sales_cases sc WHERE $base");
    $stmt->execute($params);
    $total = $stmt->fetch();

    $prevMonth = $month - 1; $prevYear = $year;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $subBase = "company_id = ? AND status = 'confirmed'";
    $subBaseParams = [$companyId];
    if ($salesRep) { $subBase .= " AND (sales_rep = ? OR worker_name = ?)"; $subBaseParams[] = $salesRep; $subBaseParams[] = $salesRep; }
    if ($caseType) { $subBase .= " AND case_type = ?"; $subBaseParams[] = $caseType; }
    $stmtPrev = $db->prepare("SELECT COALESCE(SUM(revenue),0) FROM sales_cases WHERE $subBase AND case_year = ? AND case_month = ?");
    $stmtPrev->execute(array_merge($subBaseParams, [$prevYear, $prevMonth]));
    $prevRevenue = (int)$stmtPrev->fetchColumn();

    $stmtYoy = $db->prepare("SELECT COALESCE(SUM(revenue),0) FROM sales_cases WHERE $subBase AND case_year = ? AND case_month = ?");
    $stmtYoy->execute(array_merge($subBaseParams, [$year - 1, $month]));
    $prevYearRevenue = (int)$stmtYoy->fetchColumn();

    $curRevenue = (int)$total['total_revenue'];
    $momChange = $prevRevenue > 0 ? round(($curRevenue - $prevRevenue) / $prevRevenue * 100, 1) : 0;
    $yoyChange = $prevYearRevenue > 0 ? round(($curRevenue - $prevYearRevenue) / $prevYearRevenue * 100, 1) : 0;

    return [
        'revenue' => $curRevenue,
        'profit' => (int)$total['total_profit'],
        'margin' => (float)$total['avg_margin'],
        'case_count' => (int)$total['case_count'],
        'event_count' => (int)$total['event_count'],
        'regular_count' => (int)$total['regular_count'],
        'target_revenue' => 0,
        'achievement_rate' => 0,
        'mom_change' => $momChange,
        'outsource_rate' => 0,
        'prev_revenue' => $prevRevenue,
        'yoy_change' => $yoyChange,
        'prev_year_revenue' => $prevYearRevenue,
    ];
}

function getSalesRevenueTrendFiltered(int $companyId, int $year, string $salesRep = ''): array {
    if (!$salesRep) return getSalesRevenueTrend($companyId, $year);

    $db = getDB();
    $stmt = $db->prepare("SELECT sc.case_month,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.gross_profit),0) as profit
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.status = 'confirmed' AND sc.sales_rep = ?
    GROUP BY sc.case_month ORDER BY sc.case_month");
    $stmt->execute([$companyId, $year, $salesRep]);

    $result = [];
    for ($m = 1; $m <= 12; $m++) { $result[$m] = ['revenue' => 0, 'profit' => 0, 'target' => 0]; }
    foreach ($stmt->fetchAll() as $row) {
        $m = (int)$row['case_month'];
        $result[$m]['revenue'] = (int)$row['revenue'];
        $result[$m]['profit'] = (int)$row['profit'];
    }
    return $result;
}

function getSalesRevenueByClientFiltered(int $companyId, int $year, int $month, string $salesRep = ''): array {
    if (!$salesRep) return getSalesRevenueByClient($companyId, $year, $month);

    $db = getDB();
    $stmt = $db->prepare("SELECT cl.client_name,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.gross_profit),0) as profit,
        COUNT(*) as case_count
    FROM sales_cases sc JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed' AND sc.sales_rep = ?
    GROUP BY cl.id, cl.client_name ORDER BY revenue DESC LIMIT 10");
    $stmt->execute([$companyId, $year, $month, $salesRep]);
    return $stmt->fetchAll();
}

function getSalesWorkerBreakdownFiltered(int $companyId, int $year, int $month, string $salesRep = '', string $caseType = ''): array {
    if (!$salesRep && !$caseType) return getSalesWorkerBreakdown($companyId, $year, $month);

    $db = getDB();
    $where = "sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year, $month];
    if ($salesRep) { $where .= " AND sc.sales_rep = ?"; $params[] = $salesRep; }
    if ($caseType) { $where .= " AND sc.case_type = ?"; $params[] = $caseType; }
    $stmt = $db->prepare("SELECT sc.worker_type,
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.cost),0) as cost,
        COALESCE(SUM(sc.gross_profit),0) as profit
    FROM sales_cases sc
    WHERE $where
    GROUP BY sc.worker_type ORDER BY revenue DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSalesMonthlySummaryFiltered(int $companyId, int $year, int $month, ?string $caseType = null, string $salesRep = ''): array {
    if (!$salesRep) return getSalesMonthlySummary($companyId, $year, $month, $caseType);

    $db = getDB();
    $where = "sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed' AND sc.sales_rep = ?";
    $params = [$companyId, $year, $month, $salesRep];
    if ($caseType) { $where .= ' AND sc.case_type = ?'; $params[] = $caseType; }

    $stmt = $db->prepare("SELECT
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as total_revenue,
        COALESCE(SUM(sc.cost),0) as total_cost,
        COALESCE(SUM(sc.gross_profit),0) as total_profit,
        CASE WHEN SUM(sc.revenue) > 0 THEN ROUND(SUM(sc.gross_profit)/SUM(sc.revenue)*100, 1) ELSE 0 END as avg_margin,
        COALESCE(SUM(sc.days_worked),0) as total_days,
        CASE WHEN COUNT(*) > 0 THEN ROUND(SUM(sc.revenue)/COUNT(*)) ELSE 0 END as avg_revenue
    FROM sales_cases sc WHERE $where");
    $stmt->execute($params);
    return $stmt->fetch();
}
