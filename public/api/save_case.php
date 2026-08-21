<?php
/**
 * 案件保存 AJAX API（フルリロードなし）
 * POST: action=create|update|delete|cancel, csrf, case_type, ...
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireAnyLogin();
// 案件の追加・編集・削除は管理者のみ（画面のボタンを隠すだけでは防げないためここで確認する）
requireAdminWrite(true);
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }

$data   = $_POST;
$action = $data['action'] ?? '';

if (!verifyCsrfToken($data['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }

if ($action === 'create' || $action === 'update') {
    $_clientId = ($data['client_id'] ?? '') ?: null;
    $_clientNameInput = trim($data['client_name_input'] ?? '');
    if (!$_clientId && $_clientNameInput) {
        $db = getDB();
        // 表記名・会社名のどちらで入力されても既存の取引先に紐づける（二重登録の防止）
        $cs = $db->prepare('SELECT id FROM sales_clients
                            WHERE company_id = ? AND (client_name = ? OR display_name = ?)
                            ORDER BY (client_name = ?) DESC LIMIT 1');
        $cs->execute([$cid, $_clientNameInput, $_clientNameInput, $_clientNameInput]);
        $existingCid = $cs->fetchColumn();
        $_clientId = $existingCid ? (int)$existingCid : createSalesClient($cid, ['client_name' => $_clientNameInput]);
    }

    $caseType  = $data['case_type'] ?? 'event';
    $startDate = $data['start_date'] ?? '';
    $endDate   = $data['end_date']   ?? '';
    if ($caseType === 'regular') {
        if ($startDate && strlen($startDate) === 7) $startDate .= '-01';
        if ($endDate   && strlen($endDate)   === 7) $endDate   .= '-01';
    }

    $caseData = [
        'case_type'      => $caseType,
        'client_id'      => $_clientId,
        'start_date'     => $startDate,
        'end_date'       => $endDate,
        'sales_rep'      => trim($data['sales_rep'] ?? ''),
        'manager'        => trim($data['manager_name'] ?? ''),
        'recruiter'      => trim($data['recruiter_name'] ?? ''),
        'worker_type'    => $data['worker_type'] ?? '正社員',
        'worker_name'    => trim($data['worker_name'] ?? ''),
        'alliance_id'    => ($data['alliance_id'] ?? '') ?: null,
        'carrier'        => trim($data['carrier'] ?? ''),
        'trade_name'     => trim($data['trade_name'] ?? ''),
        'area_id'        => ($data['area_id'] ?? '') ?: null,
        'store_name'     => trim($data['store_name'] ?? ''),
        'unit_price_in'  => (int)($data['unit_price_in']  ?? 0),
        'unit_price_out' => (int)($data['unit_price_out'] ?? 0),
        'days_worked'    => $caseType === 'regular' ? (int)($data['months_count'] ?? 1) : (int)($data['days_worked'] ?? 0),
        'status'         => $data['status'] ?? 'confirmed',
        'note'           => trim($data['notes'] ?? ''),
        'case_division'  => ($data['case_division'] ?? '') ?: null,
        // 第2段階: 担当者の社員IDを名前と一緒に保存する（集計はまだ名前を使う）
        // 名簿に無い名前・同姓同名はNULL。担当者を変更したら必ず入れ替わるよう毎回計算する
        'sales_rep_id'   => resolveEmployeeIdByName($cid, $data['sales_rep'] ?? ''),
        'manager_id'     => resolveEmployeeIdByName($cid, $data['manager_name'] ?? ''),
        'recruiter_id'   => resolveEmployeeIdByName($cid, $data['recruiter_name'] ?? ''),
        // 稼働スタッフの社員ID（名簿に無い外部スタッフはNULLのまま）
        'worker_employee_id' => resolveEmployeeIdByName($cid, $data['worker_name'] ?? ''),
        // 予算区分: 常勤の1次案件のみ保持。それ以外は必ずクリアする
        'budget_division' => ($caseType === 'regular' && ($data['case_division'] ?? '') === '1次')
                             ? (trim($data['budget_division'] ?? '') ?: null)
                             : null,
    ];
    // 必要人数: 案件人員一覧で「あと何人足りないか」を出すために使う。
    // 送られてこないフォームではキーごと渡さないので、既存の値はそのまま残る
    if (array_key_exists('recruitment_count', $data)) {
        $caseData['recruitment_count'] = ($data['recruitment_count'] !== '' && $data['recruitment_count'] !== null)
            ? max(0, (int)$data['recruitment_count']) : null;
    }
    // 光AD（戦略会議のみで使用）: 常勤案件フォームから送られてきたときだけ保存する。
    // イベント案件フォームにはこの項目が無いのでキーごと渡さず、既存の値をそのまま残す
    if (array_key_exists('hikari_ad_flag', $data)) {
        $caseData['hikari_ad_flag'] = !empty($data['hikari_ad_flag']) ? 1 : 0;
    }
    if ($caseType === 'regular') {
        $caseData['gross_profit_direct'] = $caseData['unit_price_in'] - $caseData['unit_price_out'];
    }

    try {
        if ($action === 'create') {
            $id = createSalesCase($cid, $caseData);
            $planId = (int)($data['plan_id'] ?? 0);
            if ($planId && $id) {
                try {
                    getDB()->prepare("UPDATE event_plans SET status='confirmed', linked_case_id=? WHERE id=? AND company_id=? AND status='pending'")->execute([$id, $planId, $cid]);
                    getDB()->prepare("UPDATE sales_cases SET plan_id=? WHERE id=? AND company_id=?")->execute([$planId, $id, $cid]);
                } catch (Exception $_e) {}
            }
            echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
        } else {
            updateSalesCase((int)$data['id'], $cid, $caseData);
            echo json_encode(['success' => true, 'id' => (int)$data['id']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        error_log('[save_case API] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    deleteSalesCase((int)($data['id'] ?? 0), $cid);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cancel') {
    cancelSalesCase((int)($data['id'] ?? 0), $cid);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
