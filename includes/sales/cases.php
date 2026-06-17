<?php
// ============================================================
// 売上管理: 案件 CRUD
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ----------------------------------------------------------------
// 案件 CRUD
// ----------------------------------------------------------------

function calcSalesCaseAmounts(array &$data): void {
    $priceIn  = (float)($data['unit_price_in'] ?? 0);
    $priceOut = (float)($data['unit_price_out'] ?? 0);
    $days     = (float)($data['days_worked'] ?? 0);
    // 新フォーム: gross_profit直接入力の場合はそちらを優先
    if (isset($data['gross_profit_direct'])) {
        $data['revenue']      = $priceIn;
        $data['cost']         = $priceIn - (int)$data['gross_profit_direct'];
        $data['gross_profit'] = (int)$data['gross_profit_direct'];
        $data['margin']       = $priceIn > 0 ? round($data['gross_profit'] / $priceIn, 4) : 0;
    } else {
        $data['revenue']      = (int)round($priceIn * $days);
        $data['cost']         = (int)round($priceOut * $days);
        $data['gross_profit'] = $data['revenue'] - $data['cost'];
        $data['margin']       = $data['revenue'] > 0 ? round($data['gross_profit'] / $data['revenue'], 4) : 0;
    }
}

function getSalesCase(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT sc.*, cl.client_name, al.alliance_name, sb.brand_name, sb.brand_code, sa.area_name
        FROM sales_cases sc
        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
        LEFT JOIN sales_alliances al ON sc.alliance_id = al.id
        LEFT JOIN sales_store_brands sb ON sc.store_brand_id = sb.id
        LEFT JOIN sales_areas sa ON sc.area_id = sa.id
        WHERE sc.id = ? AND sc.company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function getSalesCases(int $companyId, array $filters = [], int $limit = 200, int $offset = 0): array {
    $db = getDB();
    $where = ['sc.company_id = ?'];
    $params = [$companyId];

    if (!empty($filters['case_type'])) {
        $where[] = 'sc.case_type = ?';
        $params[] = $filters['case_type'];
    }
    if (!empty($filters['year'])) {
        $where[] = 'sc.case_year = ?';
        $params[] = (int)$filters['year'];
    }
    if (!empty($filters['month'])) {
        $where[] = 'sc.case_month = ?';
        $params[] = (int)$filters['month'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'sc.client_id = ?';
        $params[] = (int)$filters['client_id'];
    }
    if (!empty($filters['worker_type'])) {
        $where[] = 'sc.worker_type = ?';
        $params[] = $filters['worker_type'];
    }
    if (!empty($filters['alliance_id'])) {
        $where[] = 'sc.alliance_id = ?';
        $params[] = (int)$filters['alliance_id'];
    }
    if (!empty($filters['area_id'])) {
        $where[] = 'sc.area_id = ?';
        $params[] = (int)$filters['area_id'];
    }
    if (!empty($filters['store_brand_id'])) {
        $where[] = 'sc.store_brand_id = ?';
        $params[] = (int)$filters['store_brand_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'sc.status = ?';
        $params[] = $filters['status'];
    } else {
        $where[] = "sc.status != 'cancelled'";
    }
    if (!empty($filters['search'])) {
        $where[] = '(sc.worker_name LIKE ? OR sc.store_name LIKE ? OR sc.sales_rep LIKE ?)';
        $s = '%' . $filters['search'] . '%';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    if (!empty($filters['employee_name'])) {
        $where[] = '(sc.sales_rep = ? OR sc.worker_name = ?)';
        $params[] = $filters['employee_name'];
        $params[] = $filters['employee_name'];
    }

    $sql = 'SELECT sc.*, cl.client_name, al.alliance_name, sb.brand_name, sb.brand_code, sa.area_name
        FROM sales_cases sc
        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
        LEFT JOIN sales_alliances al ON sc.alliance_id = al.id
        LEFT JOIN sales_store_brands sb ON sc.store_brand_id = sb.id
        LEFT JOIN sales_areas sa ON sc.area_id = sa.id
        WHERE ' . implode(' AND ', $where) .
        ' ORDER BY sc.start_date DESC, sc.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countSalesCases(int $companyId, array $filters = []): int {
    $db = getDB();
    $where = ['sc.company_id = ?'];
    $params = [$companyId];

    if (!empty($filters['case_type'])) { $where[] = 'sc.case_type = ?'; $params[] = $filters['case_type']; }
    if (!empty($filters['year'])) { $where[] = 'sc.case_year = ?'; $params[] = (int)$filters['year']; }
    if (!empty($filters['month'])) { $where[] = 'sc.case_month = ?'; $params[] = (int)$filters['month']; }
    if (!empty($filters['client_id'])) { $where[] = 'sc.client_id = ?'; $params[] = (int)$filters['client_id']; }
    if (!empty($filters['worker_type'])) { $where[] = 'sc.worker_type = ?'; $params[] = $filters['worker_type']; }
    if (!empty($filters['status'])) { $where[] = 'sc.status = ?'; $params[] = $filters['status']; } else { $where[] = "sc.status != 'cancelled'"; }
    if (!empty($filters['search'])) {
        $where[] = '(sc.worker_name LIKE ? OR sc.store_name LIKE ? OR sc.sales_rep LIKE ?)';
        $s = '%' . $filters['search'] . '%';
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    if (!empty($filters['employee_name'])) {
        $where[] = '(sc.sales_rep = ? OR sc.worker_name = ?)';
        $params[] = $filters['employee_name'];
        $params[] = $filters['employee_name'];
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM sales_cases sc WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function createSalesCase(int $companyId, array $data): int {
    $db = getDB();
    calcSalesCaseAmounts($data);
    $startDate = $data['start_date'] ?? '';
    $year = $startDate ? (int)substr($startDate, 0, 4) : (int)date('Y');
    $month = $startDate ? (int)substr($startDate, 5, 2) : (int)date('n');

    $stmt = $db->prepare('INSERT INTO sales_cases (
        company_id, case_type, case_name, carrier, recruitment_count, new_transactions, negotiations_count, contracts_count, case_year, case_month,
        client_id, sales_rep, manager, recruiter,
        worker_type, alliance_id, worker_id, worker_name,
        store_brand_id, area_id, store_name,
        start_date, end_date,
        unit_price_in, unit_price_out, days_worked,
        revenue, cost, gross_profit, margin, status, note
    ) VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?, ?,?,?, ?,?,?,?, ?,?)');
    $stmt->execute([
        $companyId, $data['case_type'], $data['case_name'] ?? null, $data['carrier'] ?? null, $data['recruitment_count'] ?: null, $data['new_transactions'] ?: null, $data['negotiations_count'] ?: null, $data['contracts_count'] ?: null, $year, $month,
        $data['client_id'] ?: null, $data['sales_rep'] ?? '', $data['manager'] ?? '', $data['recruiter'] ?? '',
        $data['worker_type'] ?? '正社員', $data['alliance_id'] ?: null, $data['worker_id'] ?: null, $data['worker_name'] ?? '',
        $data['store_brand_id'] ?: null, $data['area_id'] ?: null, $data['store_name'] ?? '',
        $startDate ?: null, $data['end_date'] ?: null,
        (float)$data['unit_price_in'], (float)($data['unit_price_out'] ?? 0), (float)($data['days_worked'] ?? 0),
        $data['revenue'], $data['cost'], $data['gross_profit'], $data['margin'],
        $data['status'] ?? '商談中', $data['note'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function updateSalesCase(int $id, int $companyId, array $data): void {
    $db = getDB();
    calcSalesCaseAmounts($data);
    $startDate = $data['start_date'] ?? '';
    $year = $startDate ? (int)substr($startDate, 0, 4) : (int)date('Y');
    $month = $startDate ? (int)substr($startDate, 5, 2) : (int)date('n');

    $stmt = $db->prepare('UPDATE sales_cases SET
        case_type=?, case_name=?, carrier=?, recruitment_count=?, new_transactions=?, negotiations_count=?, contracts_count=?, case_year=?, case_month=?,
        client_id=?, sales_rep=?, manager=?, recruiter=?,
        worker_type=?, alliance_id=?, worker_id=?, worker_name=?,
        store_brand_id=?, area_id=?, store_name=?,
        start_date=?, end_date=?,
        unit_price_in=?, unit_price_out=?, days_worked=?,
        revenue=?, cost=?, gross_profit=?, margin=?, status=?, note=?
        WHERE id=? AND company_id=?');
    $stmt->execute([
        $data['case_type'], $data['case_name'] ?? null, $data['carrier'] ?? null, $data['recruitment_count'] ?: null, $data['new_transactions'] ?: null, $data['negotiations_count'] ?: null, $data['contracts_count'] ?: null, $year, $month,
        $data['client_id'] ?: null, $data['sales_rep'] ?? '', $data['manager'] ?? '', $data['recruiter'] ?? '',
        $data['worker_type'] ?? '正社員', $data['alliance_id'] ?: null, $data['worker_id'] ?: null, $data['worker_name'] ?? '',
        $data['store_brand_id'] ?: null, $data['area_id'] ?: null, $data['store_name'] ?? '',
        $startDate ?: null, $data['end_date'] ?: null,
        (float)$data['unit_price_in'], (float)($data['unit_price_out'] ?? 0), (float)($data['days_worked'] ?? 0),
        $data['revenue'], $data['cost'], $data['gross_profit'], $data['margin'],
        $data['status'] ?? '商談中', $data['note'] ?? null,
        $id, $companyId,
    ]);
}

function cancelSalesCase(int $id, int $companyId): void {
    $db = getDB();
    $db->prepare("UPDATE sales_cases SET status='cancelled' WHERE id=? AND company_id=?")->execute([$id, $companyId]);
}

function deleteSalesCase(int $id, int $companyId): void {
    $db = getDB();
    $db->prepare('DELETE FROM sales_cases WHERE id=? AND company_id=?')->execute([$id, $companyId]);
}
