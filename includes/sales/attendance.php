<?php
// ============================================================
// 売上管理: 出勤管理
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// 出勤管理
// ================================================================

function getAttendance(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_attendance WHERE company_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date, employee_name");
    $stmt->execute([$companyId, sprintf('%04d-%02d-01', $year, $month), date('Y-m-t', mktime(0,0,0,$month,1,$year))]);
    return $stmt->fetchAll();
}

function saveAttendance(int $companyId, array $data): int {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO sales_attendance (company_id, employee_name, work_date, checkin_time) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE checkin_time = VALUES(checkin_time)");
    $stmt->execute([$companyId, $data['employee_name'], $data['work_date'], $data['checkin_time'] ?? null]);
    return (int)$db->lastInsertId();
}
