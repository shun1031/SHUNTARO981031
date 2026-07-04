<?php
// ============================================================
// 売上管理: シフト管理
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// シフト管理
// ================================================================

function getShifts(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT * FROM sales_shifts WHERE company_id = ? AND shift_year = ? AND shift_month = ?";
    $params = [$companyId, $year, $month];
    if ($employeeName !== null) { $sql .= " AND employee_name = ?"; $params[] = $employeeName; }
    $sql .= " ORDER BY employee_name, shift_date";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getShiftGrid(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $shifts = getShifts($companyId, $year, $month, $employeeName);
    $grid = [];
    foreach ($shifts as $s) {
        $day = (int)date('j', strtotime($s['shift_date']));
        $grid[$s['employee_name']][$day] = $s;
    }
    return $grid;
}

function getShiftEmployees(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT DISTINCT employee_name FROM sales_shifts WHERE company_id = ? AND shift_year = ? AND shift_month = ?";
    $params = [$companyId, $year, $month];
    if ($employeeName !== null) { $sql .= " AND employee_name = ?"; $params[] = $employeeName; }
    $sql .= " ORDER BY employee_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function saveShift(int $companyId, array $data): int {
    $db = getDB();
    $date = $data['shift_date'];
    $ym = explode('-', $date);
    $startTime = $data['start_time'] ?? null;
    $endTime   = $data['end_time'] ?? null;
    $isDayOff  = (int)($data['is_day_off'] ?? 0);
    // scheduled_time は既存コードの参照互換用（start_time~end_time を文字列合成）
    if ($isDayOff) {
        $scheduledTime = '休み';
    } elseif ($startTime !== null) {
        $scheduledTime = $endTime ? $startTime . '~' . $endTime : $startTime;
    } else {
        $scheduledTime = $data['scheduled_time'] ?? null;
    }
    // 出退勤報告（attendance_status/checkin_time）は saveAttendanceStatus() の責務。
    // ここではシフト予定項目のみ更新し、既存の出退勤データは保持する。
    $stmt = $db->prepare("INSERT INTO sales_shifts
        (company_id, employee_name, shift_date, shift_year, shift_month,
         scheduled_time, start_time, end_time, is_day_off, checkin_time, report_status, location, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            scheduled_time = VALUES(scheduled_time),
            start_time = VALUES(start_time),
            end_time = VALUES(end_time),
            is_day_off = VALUES(is_day_off),
            location = VALUES(location)");
    $stmt->execute([
        $companyId, $data['employee_name'], $date, (int)$ym[0], (int)$ym[1],
        $scheduledTime, $startTime, $endTime, $isDayOff,
        $data['checkin_time'] ?? null, $data['report_status'] ?? '',
        $data['location'] ?? null, $data['note'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function getShiftSummary(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT employee_name,
        COUNT(*) as total_shifts,
        SUM(CASE WHEN checkin_time IS NOT NULL AND checkin_time != '' THEN 1 ELSE 0 END) as attended,
        SUM(CASE WHEN report_status = '○' THEN 1 ELSE 0 END) as reported
    FROM sales_shifts WHERE company_id = ? AND shift_year = ? AND shift_month = ?";
    $params = [$companyId, $year, $month];
    if ($employeeName !== null) { $sql .= " AND employee_name = ?"; $params[] = $employeeName; }
    $sql .= " GROUP BY employee_name ORDER BY employee_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ================================================================
// 出退勤報告（一般ユーザー向け）
// ================================================================

/**
 * 出退勤報告を保存（UPSERT）。シフト提出済みの行があれば
 * scheduled_time / location / note は維持し、出退勤関連の項目のみ更新する。
 */
function saveAttendanceStatus(int $companyId, array $data): int {
    $db = getDB();
    $date = $data['work_date'];
    $ym = explode('-', $date);
    $status   = $data['attendance_status'] ?? null;
    $checkin  = $data['checkin_time']     ?? null;
    $checkout = $data['checkout_time']    ?? null;

    // 退勤のみの更新（checkin_time を上書きしない）
    if (isset($data['checkout_only']) && $data['checkout_only']) {
        $stmt = $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, checkout_time)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE checkout_time = VALUES(checkout_time)");
        $stmt->execute([$companyId, $data['employee_name'], $date, (int)$ym[0], (int)$ym[1], $checkout]);
    } else {
        $stmt = $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, attendance_status, checkin_time, checkout_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE attendance_status = VALUES(attendance_status),
                checkin_time  = IF(VALUES(checkin_time)  IS NOT NULL, VALUES(checkin_time),  checkin_time),
                checkout_time = IF(VALUES(checkout_time) IS NOT NULL, VALUES(checkout_time), checkout_time)");
        $stmt->execute([
            $companyId, $data['employee_name'], $date, (int)$ym[0], (int)$ym[1],
            $status, $checkin, $checkout,
        ]);
    }
    return (int)$db->lastInsertId();
}

/**
 * シフトと出退勤情報からステータスを自動計算
 */
function calcShiftStatus(array $shift): string {
    if (!empty($shift['is_day_off'])) return '休み';
    $status   = $shift['attendance_status'] ?? '';
    $start    = $shift['start_time']   ?? '';
    $end      = $shift['end_time']     ?? '';
    $checkin  = $shift['checkin_time'] ?? '';
    $checkout = $shift['checkout_time'] ?? '';
    // 明示的に欠勤（管理者が「欠勤」ボタンで確定した場合のみ）
    if ($status === '欠勤') return '欠勤';
    // 遅刻: 出勤時刻がシフト開始より遅い
    if ($checkin && $start && $checkin > $start) return '遅刻';
    // 早退: 退勤時刻がシフト終了より早い（両方ある場合）
    if ($checkout && $end && $checkout < $end) return '早退';
    // 出勤
    if ($checkin || $checkout) return '出勤';
    return '';
}

/**
 * 社員画面・管理者画面共通のステータス表示情報を返す
 * 未来日は空、過去/当日はデータに応じたバッジ/テキストを返す
 * @return array{badge:string, text:string, color:string}
 */
function getShiftStatusDisplay(?array $shift, string $dateStr, string $today): array {
    $empty = ['badge' => '', 'text' => '', 'color' => ''];
    if ($dateStr > $today || $shift === null) return $empty;
    if (!empty($shift['is_day_off'])) {
        return ['badge' => '休み', 'text' => '', 'color' => 'secondary'];
    }
    $status = calcShiftStatus($shift);
    if ($status === '欠勤' && ($shift['attendance_status'] ?? '') !== '欠勤') {
        $status = '';
    }
    $colorMap = ['出勤' => 'success', '遅刻' => 'warning', '早退' => 'warning', '欠勤' => 'danger'];
    if ($status !== '' && $status !== '休み') {
        return ['badge' => $status, 'text' => '', 'color' => $colorMap[$status] ?? 'secondary'];
    }
    $hasShift = !empty($shift['start_time']) || !empty($shift['end_time']) || !empty($shift['scheduled_time']);
    if ($hasShift) {
        return ['badge' => '', 'text' => '報告未完了', 'color' => ''];
    }
    return $empty;
}

/**
 * 月間の出退勤ステータス集計（ダッシュボードKPI用）
 * 戻り値: ['present' => 出勤日数, 'absent' => 欠勤数, 'early_leave' => 早退数, 'late' => 遅刻数]
 */
function getAttendanceStatusCounts(int $companyId, string $employeeName, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT attendance_status, COUNT(*) as cnt FROM sales_shifts
        WHERE company_id = ? AND employee_name = ? AND shift_year = ? AND shift_month = ? AND attendance_status IS NOT NULL
        GROUP BY attendance_status");
    $stmt->execute([$companyId, $employeeName, $year, $month]);
    $counts = ['present' => 0, 'absent' => 0, 'early_leave' => 0, 'late' => 0];
    $map = ['出勤' => 'present', '欠勤' => 'absent', '早退' => 'early_leave', '遅刻' => 'late'];
    foreach ($stmt->fetchAll() as $row) {
        $key = $map[$row['attendance_status']] ?? null;
        if ($key) { $counts[$key] = (int)$row['cnt']; }
    }
    return $counts;
}

/**
 * 指定期間のシフト一覧（直近1週間表示など）
 */
function getShiftsBetween(int $companyId, string $employeeName, string $fromDate, string $toDate): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_shifts
        WHERE company_id = ? AND employee_name = ? AND shift_date BETWEEN ? AND ?
        ORDER BY shift_date");
    $stmt->execute([$companyId, $employeeName, $fromDate, $toDate]);
    return $stmt->fetchAll();
}

/**
 * 1件のシフトを取得（本人確認用）
 */
function getShiftByDate(int $companyId, string $employeeName, string $date): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_shifts WHERE company_id = ? AND employee_name = ? AND shift_date = ?');
    $stmt->execute([$companyId, $employeeName, $date]);
    return $stmt->fetch();
}

function deleteShift(int $id, int $companyId, ?string $employeeName = null): void {
    $db = getDB();
    $sql = 'DELETE FROM sales_shifts WHERE id = ? AND company_id = ?';
    $params = [$id, $companyId];
    if ($employeeName !== null) { $sql .= ' AND employee_name = ?'; $params[] = $employeeName; }
    $db->prepare($sql)->execute($params);
}
