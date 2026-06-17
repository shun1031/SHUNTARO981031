<?php
// ============================================================
// 売上管理: イベントカレンダー/月末総会
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// イベントカレンダー
// ================================================================

function getEventCalendar(int $companyId, int $year, int $month, ?int $clientId = null, ?string $employeeName = null): array {
    $db = getDB();
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));

    $sql = "SELECT sc.*, cl.client_name, COALESCE(sw.worker_name, sc.worker_name) as resolved_worker_name
    FROM sales_cases sc
    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    LEFT JOIN sales_workers sw ON sc.worker_id = sw.id
    WHERE sc.company_id = ? AND sc.case_type = 'event' AND sc.status = 'confirmed'
      AND sc.start_date <= ? AND sc.end_date >= ?";
    $params = [$companyId, $endDate, $startDate];
    if ($clientId) { $sql .= " AND sc.client_id = ?"; $params[] = $clientId; }
    if ($employeeName !== null) { $sql .= " AND (sc.worker_name = ? OR sw.worker_name = ? OR sc.sales_rep = ?)"; $params[] = $employeeName; $params[] = $employeeName; $params[] = $employeeName; }
    $sql .= " ORDER BY sc.start_date, cl.client_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $cases = $stmt->fetchAll();

    $calendar = [];
    foreach ($cases as $c) {
        $sd = max($startDate, $c['start_date']);
        $ed = min($endDate, $c['end_date']);
        $cur = $sd;
        while ($cur <= $ed) {
            $day = (int)date('j', strtotime($cur));
            $calendar[$day][] = [
                'case_id' => $c['id'],
                'client_name' => $c['client_name'],
                'case_name' => $c['store_name'] ?? $c['resolved_worker_name'] ?? '',
                'workers' => $c['resolved_worker_name'] ?? '',
                'revenue' => $c['revenue'],
            ];
            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }
    }
    return $calendar;
}

// ================================================================
// 月末総会
// ================================================================

function getMonthlyMeetingData(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $result = ['overall' => [], 'regular' => [], 'event' => []];

    foreach (['overall' => null, 'regular' => 'regular', 'event' => 'event'] as $key => $type) {
        $where = "sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'";
        $params = [$companyId, $year, $month];
        if ($type) { $where .= " AND sc.case_type = ?"; $params[] = $type; }
        if ($employeeName !== null) { $where .= " AND (sc.sales_rep = ? OR sc.worker_name = ?)"; $params[] = $employeeName; $params[] = $employeeName; }

        $stmt = $db->prepare("SELECT
            COUNT(*) as case_count,
            COALESCE(SUM(sc.revenue),0) as revenue,
            COALESCE(SUM(sc.cost),0) as cost,
            COALESCE(SUM(sc.gross_profit),0) as profit,
            CASE WHEN SUM(sc.revenue)>0 THEN ROUND(SUM(sc.gross_profit)/SUM(sc.revenue)*100,1) ELSE 0 END as margin,
            COALESCE(SUM(sc.days_worked),0) as days,
            CASE WHEN SUM(sc.days_worked)>0 THEN ROUND(SUM(sc.revenue)/SUM(sc.days_worked)) ELSE 0 END as avg_daily
        FROM sales_cases sc WHERE $where");
        $stmt->execute($params);
        $result[$key] = $stmt->fetch();
    }

    $stmt = $db->prepare("SELECT revenue_target FROM sales_targets WHERE company_id = ? AND target_year = ? AND target_month = ? AND target_type = 'total' LIMIT 1");
    $stmt->execute([$companyId, $year, $month]);
    $target = $stmt->fetchColumn();
    $result['target'] = (int)$target;
    $result['achievement'] = $result['target'] > 0 ? round($result['overall']['revenue'] / $result['target'] * 100, 1) : 0;

    return $result;
}

function getPersonalSalesBreakdown(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT sc.sales_rep,
        COUNT(*) as case_count,
        COALESCE(SUM(sc.revenue),0) as revenue,
        COALESCE(SUM(sc.cost),0) as cost,
        COALESCE(SUM(sc.gross_profit),0) as profit,
        CASE WHEN SUM(sc.revenue)>0 THEN ROUND(SUM(sc.gross_profit)/SUM(sc.revenue)*100,1) ELSE 0 END as margin,
        SUM(CASE WHEN sc.case_type='event' THEN sc.revenue ELSE 0 END) as event_revenue,
        SUM(CASE WHEN sc.case_type='regular' THEN sc.revenue ELSE 0 END) as regular_revenue
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'";
    $params = [$companyId, $year, $month];
    if ($employeeName !== null) { $sql .= " AND (sc.sales_rep = ? OR sc.worker_name = ?)"; $params[] = $employeeName; $params[] = $employeeName; }
    $sql .= " GROUP BY sc.sales_rep ORDER BY revenue DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getReportEmployees(int $companyId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT DISTINCT employee_name FROM sales_shifts WHERE company_id = ?
        UNION SELECT DISTINCT employee_name FROM sales_daily_reports WHERE company_id = ?
        UNION SELECT DISTINCT employee_name FROM sales_transport_costs WHERE company_id = ?
        ORDER BY employee_name");
    $stmt->execute([$companyId, $companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
