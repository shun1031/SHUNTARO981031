<?php
// ============================================================
// 売上管理: 交通費管理
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// 交通費管理
// ================================================================

function getTransportCosts(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT * FROM sales_transport_costs WHERE company_id = ? AND target_year = ? AND target_month = ?";
    $params = [$companyId, $year, $month];
    if ($employeeName !== null) { $sql .= " AND employee_name = ?"; $params[] = $employeeName; }
    $sql .= " ORDER BY employee_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTransportCost(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_transport_costs WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function saveTransportCost(int $companyId, array $data): int {
    $db = getDB();
    $id = (int)($data['id'] ?? 0);
    $fields = [
        'employee_name' => $data['employee_name'],
        'target_year' => (int)$data['target_year'],
        'target_month' => (int)$data['target_month'],
        'total_amount' => (int)($data['total_amount'] ?? 0),
        'evidence_url_1' => $data['evidence_url_1'] ?? null,
        'distance_km_1' => $data['distance_km_1'] ?? null,
        'work_days_1' => $data['work_days_1'] ?? null,
        'cost_1' => $data['cost_1'] ?? null,
        'evidence_url_2' => $data['evidence_url_2'] ?? null,
        'distance_km_2' => $data['distance_km_2'] ?? null,
        'work_days_2' => $data['work_days_2'] ?? null,
        'cost_2' => $data['cost_2'] ?? null,
        'evidence_url_3' => $data['evidence_url_3'] ?? null,
        'distance_km_3' => $data['distance_km_3'] ?? null,
        'work_days_3' => $data['work_days_3'] ?? null,
        'cost_3' => $data['cost_3'] ?? null,
        'highway_cost' => (int)($data['highway_cost'] ?? 0),
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE sales_transport_costs SET $sets WHERE id = ? AND company_id = ?");
        $stmt->execute([...array_values($fields), $id, $companyId]);
        return $id;
    }
    $fields['company_id'] = $companyId;
    $cols = implode(', ', array_keys($fields));
    $phs = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $db->prepare("INSERT INTO sales_transport_costs ($cols) VALUES ($phs)");
    $stmt->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function deleteTransportCost(int $id, int $companyId): bool {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM sales_transport_costs WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->rowCount() > 0;
}
