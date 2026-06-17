<?php
// ============================================================
// 売上管理: 請求書 (個人別・会社別)
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// 個人別請求書
// ================================================================

function getPersonalInvoices(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_personal_invoices WHERE company_id = ? AND invoice_year = ? AND invoice_month = ? ORDER BY employee_name");
    $stmt->execute([$companyId, $year, $month]);
    return $stmt->fetchAll();
}

function getPersonalInvoice(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_personal_invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function savePersonalInvoice(int $companyId, array $data): int {
    $db = getDB();
    $id = (int)($data['id'] ?? 0);
    $fields = [
        'invoice_year' => (int)$data['invoice_year'],
        'invoice_month' => (int)$data['invoice_month'],
        'employee_name' => $data['employee_name'],
        'line_number' => (int)($data['line_number'] ?? 1),
        'grade' => $data['grade'] ?? null,
        'alliance_type' => $data['alliance_type'] ?? null,
        'alliance_name' => $data['alliance_name'] ?? null,
        'base_fee' => (int)($data['base_fee'] ?? 0),
        'transport_cost' => (int)($data['transport_cost'] ?? 0),
        'transport_cost_client' => (int)($data['transport_cost_client'] ?? 0),
        'shift_days' => (int)($data['shift_days'] ?? 0),
        'actual_work_days' => (int)($data['actual_work_days'] ?? 0),
        'client_ladder' => (int)($data['client_ladder'] ?? 0),
        'agency_ladder' => (int)($data['agency_ladder'] ?? 0),
        'extra_work_revenue' => (int)($data['extra_work_revenue'] ?? 0),
        'absence_deduction' => (int)($data['absence_deduction'] ?? 0),
        'other_charges' => (int)($data['other_charges'] ?? 0),
        'subtotal_fee' => (int)($data['subtotal_fee'] ?? 0),
        'event_fee' => (int)($data['event_fee'] ?? 0),
        'sales_incentive' => (int)($data['sales_incentive'] ?? 0),
        'mgmt_incentive' => (int)($data['mgmt_incentive'] ?? 0),
        'recruit_incentive' => (int)($data['recruit_incentive'] ?? 0),
        'role_allowance' => (int)($data['role_allowance'] ?? 0),
        'incentive_total' => (int)($data['incentive_total'] ?? 0),
        'welfare' => (int)($data['welfare'] ?? 0),
        'social_insurance' => (int)($data['social_insurance'] ?? 0),
        'invoice_amount' => (int)($data['invoice_amount'] ?? 0),
        'end_date' => $data['end_date'] ?? null,
        'note' => $data['note'] ?? null,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE sales_personal_invoices SET $sets WHERE id = ? AND company_id = ?");
        $stmt->execute([...array_values($fields), $id, $companyId]);
        return $id;
    }
    $fields['company_id'] = $companyId;
    $cols = implode(', ', array_keys($fields));
    $phs = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $db->prepare("INSERT INTO sales_personal_invoices ($cols) VALUES ($phs)");
    $stmt->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function deletePersonalInvoice(int $id, int $companyId): bool {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM sales_personal_invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->rowCount() > 0;
}

// ================================================================
// 会社別請求書
// ================================================================

function getCompanyInvoices(int $companyId, int $year, ?int $month = null): array {
    $db = getDB();
    $sql = "SELECT * FROM sales_company_invoices WHERE company_id = ? AND invoice_year = ?";
    $params = [$companyId, $year];
    if ($month) { $sql .= " AND invoice_month = ?"; $params[] = $month; }
    $sql .= " ORDER BY client_name, invoice_month";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function saveCompanyInvoice(int $companyId, array $data): int {
    $db = getDB();
    $id = (int)($data['id'] ?? 0);
    $fields = [
        'client_name' => $data['client_name'],
        'client_id' => $data['client_id'] ?? null,
        'invoice_year' => (int)$data['invoice_year'],
        'invoice_month' => (int)$data['invoice_month'],
        'base_revenue' => (int)($data['base_revenue'] ?? 0),
        'extra_revenue' => (int)($data['extra_revenue'] ?? 0),
        'total_revenue' => (int)($data['total_revenue'] ?? 0),
        'note' => $data['note'] ?? null,
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE sales_company_invoices SET $sets WHERE id = ? AND company_id = ?");
        $stmt->execute([...array_values($fields), $id, $companyId]);
        return $id;
    }
    $fields['company_id'] = $companyId;
    $cols = implode(', ', array_keys($fields));
    $phs = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $db->prepare("INSERT INTO sales_company_invoices ($cols) VALUES ($phs)");
    $stmt->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function generateCompanyInvoices(int $companyId, int $year, int $month): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT
        COALESCE(sc2.client_name, '不明') as client_name,
        sc2.id as client_id,
        SUM(sc.revenue) as base_revenue,
        0 as extra_revenue,
        SUM(sc.revenue) as total_revenue
    FROM sales_cases sc
    LEFT JOIN sales_clients sc2 ON sc.client_id = sc2.id
    WHERE sc.company_id = ? AND sc.case_year = ? AND sc.case_month = ? AND sc.status = 'confirmed'
    GROUP BY sc.client_id, sc2.id, sc2.client_name");
    $stmt->execute([$companyId, $year, $month]);
    $rows = $stmt->fetchAll();
    $count = 0;
    foreach ($rows as $r) {
        $db->prepare("INSERT INTO sales_company_invoices (company_id, client_name, client_id, invoice_year, invoice_month, base_revenue, extra_revenue, total_revenue)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE base_revenue = VALUES(base_revenue), total_revenue = VALUES(total_revenue)")
            ->execute([$companyId, $r['client_name'], $r['client_id'], $year, $month, $r['base_revenue'], 0, $r['total_revenue']]);
        $count++;
    }
    return $count;
}
