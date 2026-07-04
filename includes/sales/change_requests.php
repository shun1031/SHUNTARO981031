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

function getChangeRequests(int $companyId, ?string $employeeName = null, ?string $status = null, ?int $year = null, ?int $month = null): array {
    $db = getDB();
    $where = ['company_id = ?'];
    $params = [$companyId];
    if ($employeeName !== null) { $where[] = 'employee_name = ?'; $params[] = $employeeName; }
    if ($status !== null) { $where[] = 'status = ?'; $params[] = $status; }
    if ($year !== null && $month !== null) {
        $where[] = 'YEAR(created_at) = ? AND MONTH(created_at) = ?';
        $params[] = $year;
        $params[] = $month;
    }
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
 * 申請を承認し、実データに反映する。
 */
function approveChangeRequest(int $id, int $companyId, string $reviewerName): bool {
    $db = getDB();
    $req = getChangeRequest($id, $companyId);
    if (!$req || $req['status'] !== 'pending') {
        return false;
    }

    $ym  = explode('-', $req['target_date']);
    $y   = (int)($ym[0] ?? 0);
    $m   = (int)($ym[1] ?? 0);
    $emp = $req['employee_name'];
    $dt  = $req['target_date'];
    $val = $req['requested_value'];
    $isCancel = ($val === '取消');

    switch ($req['request_type']) {
        case 'checkin_change':
            $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkin_time)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE checkin_time = VALUES(checkin_time)")
               ->execute([$companyId, $emp, $dt, $y, $m, $val]);
            break;

        case 'checkout_change':
            $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkout_time)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE checkout_time = VALUES(checkout_time)")
               ->execute([$companyId, $emp, $dt, $y, $m, $val]);
            break;

        case 'attendance_add':
            // val = "HH:MM" または "HH:MM/HH:MM"（出勤[/退勤]）
            $parts    = explode('/', $val, 2);
            $checkin  = trim($parts[0]) ?: null;
            $checkout = isset($parts[1]) ? (trim($parts[1]) ?: null) : null;
            $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkin_time, checkout_time)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    checkin_time  = IF(VALUES(checkin_time)  IS NOT NULL, VALUES(checkin_time),  checkin_time),
                    checkout_time = IF(VALUES(checkout_time) IS NOT NULL, VALUES(checkout_time), checkout_time)")
               ->execute([$companyId, $emp, $dt, $y, $m, $checkin, $checkout]);
            break;

        case 'shift_change':
            if ($isCancel) {
                $db->prepare("UPDATE sales_shifts SET scheduled_time = NULL, start_time = NULL, end_time = NULL, is_day_off = 1
                    WHERE company_id = ? AND employee_name = ? AND shift_date = ?")
                   ->execute([$companyId, $emp, $dt]);
            } else {
                $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, scheduled_time)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE scheduled_time = VALUES(scheduled_time)")
                   ->execute([$companyId, $emp, $dt, $y, $m, $val]);
            }
            break;

        case 'daily_report_edit':
            if ($isCancel) {
                $db->prepare("DELETE FROM sales_daily_reports WHERE company_id = ? AND employee_name = ? AND work_date = ?")
                   ->execute([$companyId, $emp, $dt]);
            }
            // 取消以外は管理者が手動で編集後に承認
            break;

        case 'transport_edit':
            if ($isCancel) {
                $db->prepare("DELETE FROM sales_transport_costs WHERE company_id = ? AND employee_name = ? AND target_year = ? AND target_month = ?")
                   ->execute([$companyId, $emp, $y, $m]);
            } else {
                // 申請値から数値のみ抽出して total_amount を更新
                $newAmount = (int)preg_replace('/[^0-9]/', '', $val);
                if ($newAmount > 0) {
                    $db->prepare("UPDATE sales_transport_costs SET total_amount = ? WHERE company_id = ? AND employee_name = ? AND target_year = ? AND target_month = ?")
                       ->execute([$newAmount, $companyId, $emp, $y, $m]);
                }
            }
            break;

        case 'attendance_change': // 旧型式: 後方互換
            $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkin_time)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE checkin_time = VALUES(checkin_time)")
               ->execute([$companyId, $emp, $dt, $y, $m, $val]);
            break;
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
