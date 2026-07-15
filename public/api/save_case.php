<?php
/**
 * 案件保存 AJAX API（フルリロードなし）
 * POST: action=create|update|delete|cancel, csrf, case_type, ...
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireAnyLogin();
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
        $cs = $db->prepare('SELECT id FROM sales_clients WHERE company_id = ? AND client_name = ? LIMIT 1');
        $cs->execute([$cid, $_clientNameInput]);
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
    ];
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
