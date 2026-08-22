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
 * シフト変更申請の自由入力から開始時刻・終了時刻を読み取る。
 * 申請値は社員の手入力なので表記がそろっていない（例: 10:00〜19:00 / 10:00~19:00 / 10:00）。
 * 全角を半角に均したうえで HH:MM を先頭から2つまで拾う。
 * 時刻として読み取れない場合は [null, null] を返し、呼び出し側で既存の時刻を保持する。
 *
 * @return array{0:?string,1:?string} [開始時刻, 終了時刻]
 */
function parseShiftTimeRange(?string $raw): array {
    if ($raw === null || trim($raw) === '') return [null, null];
    $s = function_exists('mb_convert_kana') ? mb_convert_kana($raw, 'as') : $raw;
    if (!preg_match_all('/(\d{1,2}):(\d{2})/', $s, $matches, PREG_SET_ORDER)) {
        return [null, null];
    }
    $times = [];
    foreach ($matches as $hit) {
        $hour   = (int)$hit[1];
        $minute = (int)$hit[2];
        // 1つでも時刻として成立しない値があれば、拾える分だけ反映せず全体を諦める。
        // （例:「25:00〜19:00」で 19:00 を開始時刻と取り違えないため）
        if ($hour > 23 || $minute > 59) return [null, null];
        $times[] = sprintf('%02d:%02d', $hour, $minute);
    }
    // 「10:00〜19:00（休憩12:00〜13:00）」のように3つ以上あっても先頭2つを使う
    return [$times[0], $times[1] ?? null];
}

/**
 * シフト変更申請の希望時間が、承認してもシフト時間に反映できない値かを判定する。
 * 申請一覧の警告表示で使う（承認処理と同じ基準で判定するためここに置く）。
 */
function shiftChangeNeedsManualFix(array $request): bool {
    if (($request['request_type'] ?? '') !== 'shift_change') return false;
    $val = $request['requested_value'] ?? '';
    if ($val === '取消') return false; // 取消は時刻を伴わない正常な申請
    [$start] = parseShiftTimeRange($val);
    return $start === null;
}

/**
 * 申請を承認し、実データに反映する。
 *
 * @return array{ok:bool, warning:?string}
 *   ok      … 承認できたか（申請が存在しない・承認待ちでない場合は false）
 *   warning … 承認はしたが一部を反映できなかったときの理由。問題なければ null
 *             'shift_time_unparsed' = 希望時間を時刻として読み取れずシフト時間を変更していない
 */
function approveChangeRequest(int $id, int $companyId, string $reviewerName): array {
    $db = getDB();
    $req = getChangeRequest($id, $companyId);
    if (!$req || $req['status'] !== 'pending') {
        return ['ok' => false, 'warning' => null];
    }
    $warning = null;

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
                // シフト管理画面が表示しているのは start_time / end_time なので、
                // scheduled_time だけを更新すると承認しても画面の時間が変わらない。
                // 時刻として読み取れたときは start_time / end_time にも反映する。
                // 読み取れないときは従来どおり scheduled_time だけ更新し、既存の時刻は壊さない。
                [$newStart, $newEnd] = parseShiftTimeRange($val);
                if ($newStart !== null) {
                    // 休み扱いの日に時間を入れ直す申請もあるため is_day_off は必ず解除する
                    $scheduled = $newEnd !== null ? $newStart . '~' . $newEnd : $newStart;
                    $db->prepare("INSERT INTO sales_shifts
                            (company_id, employee_name, shift_date, shift_year, shift_month,
                             scheduled_time, start_time, end_time, is_day_off)
                        VALUES (?,?,?,?,?,?,?,?,0)
                        ON DUPLICATE KEY UPDATE
                            scheduled_time = VALUES(scheduled_time),
                            start_time     = VALUES(start_time),
                            end_time       = VALUES(end_time),
                            is_day_off     = 0")
                       ->execute([$companyId, $emp, $dt, $y, $m, $scheduled, $newStart, $newEnd]);
                } else {
                    // 時刻として読み取れなかった。承認自体は通すが、シフト時間は変えられない。
                    // 管理者が気づけるよう呼び出し側へ知らせる（黙って落とすと反映漏れに気づけない）
                    $warning = 'shift_time_unparsed';
                    $db->prepare("INSERT INTO sales_shifts (company_id, employee_name, shift_date, shift_year, shift_month, scheduled_time)
                        VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE scheduled_time = VALUES(scheduled_time)")
                       ->execute([$companyId, $emp, $dt, $y, $m, $val]);
                }
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
    return ['ok' => true, 'warning' => $warning];
}

function rejectChangeRequest(int $id, int $companyId, string $reviewerName): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE sales_change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND company_id = ? AND status = 'pending'");
    $stmt->execute([$reviewerName, $id, $companyId]);
    return $stmt->rowCount() > 0;
}
