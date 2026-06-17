<?php
// ============================================================
// 売上管理: 申請（シフト変更・出退勤時間変更）
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// 申請 CRUD
// ================================================================

function createChangeRequest(int $companyId, array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO sales_change_requests
        (company_id, employee_name, request_type, target_date, current_value, requested_value, reason, status)
        VALUES (?,?,?,?,?,?,?,\'pending\')');
    $stmt->execute([
        $companyId, $data['employee_name'], $data['request_type'], $data['target_date'],
        $data['current_value'] ?? null, $data['requested_value'], $data['reason'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function getChangeRequests(int $companyId, ?string $employeeName = null, ?string $status = null): array {
    $db = getDB();
    $where = ['company_id = ?'];
    $params = [$companyId];
    if ($employeeName !== null) { $where[] = 'employee_name = ?'; $params[] = $employeeName; }
    if ($status !== null) { $where[] = 'status = ?'; $params[] = $status; }
    $sql = 'SELECT * FROM sales_change_requests WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getChangeRequest(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_change_requests WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function countPendingChangeRequests(int $companyId, ?string $employeeName = null): int {
    $db = getDB();
    $sql = "SELECT COUNT(*) FROM sales_change_requests WHERE company_id = ? AND status = 'pending'";
    $params = [$companyId];
    if ($employeeName !== null) { $sql .= ' AND employee_name = ?'; $params[] = $employeeName; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * 申請を承認し、実データ（sales_shifts）に反映する。
 */
function approveChangeRequest(int $id, int $companyId, string $reviewerName): bool {
    $db = getDB();
    $req = getChangeRequest($id, $companyId);
    if (!$req || $req['status'] !== 'pending') {
        return false;
    }

    $ym = explode('-', $req['target_date']);
    if ($req['request_type'] === 'shift_change') {
        $stmt = $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, scheduled_time)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE scheduled_time = VALUES(scheduled_time)");
        $stmt->execute([$companyId, $req['employee_name'], $req['target_date'], (int)$ym[0], (int)$ym[1], $req['requested_value']]);
    } else { // attendance_change
        $stmt = $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkin_time)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE checkin_time = VALUES(checkin_time)");
        $stmt->execute([$companyId, $req['employee_name'], $req['target_date'], (int)$ym[0], (int)$ym[1], $req['requested_value']]);
    }

    $db->prepare("UPDATE sales_change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND company_id = ?")
       ->execute([$reviewerName, $id, $companyId]);
    return true;
}

function rejectChangeRequest(int $id, int $companyId, string $reviewerName): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sales_change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND company_id = ? AND status = 'pending'");
    $stmt->execute([$reviewerName, $id, $companyId]);
    return $stmt->rowCount() > 0;
}
