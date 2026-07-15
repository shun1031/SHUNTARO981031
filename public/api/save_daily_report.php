<?php
/**
 * 日報保存 AJAX API（フルリロードなし）
 * POST form data: action=create|update|delete, csrf, ...
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }

$data   = $_POST;
$action = $data['action'] ?? 'create';
$myName = getEmployeeNameFilter();

if (!verifyCsrfToken($data['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }

if ($action === 'create' || $action === 'update') {
    $postEmpName = trim($data['employee_name'] ?? '');
    if ($myName !== null && $myName !== $postEmpName) { echo json_encode(['error' => 'Forbidden']); exit; }
    if ($action === 'update' && !empty($data['id'])) {
        $ex = getDB()->prepare('SELECT employee_name FROM sales_daily_reports WHERE id=? AND company_id=?');
        $ex->execute([(int)$data['id'], $cid]);
        $owner = $ex->fetchColumn();
        if ($myName !== null && $owner !== false && $owner !== $myName) { echo json_encode(['error' => 'Forbidden']); exit; }
    }

    // 店舗予算の保存（光AD のみ、同月に予算未登録なら保存）
    $workType     = $data['work_type'] ?? '';
    $budgetDetail = trim($data['budget_detail'] ?? '');
    $workDate     = $data['work_date'] ?? date('Y-m-d');
    $budYear      = (int)substr($workDate, 0, 4);
    $budMonth     = (int)substr($workDate, 5, 2);
    if ($budgetDetail && in_array($workType, ['光AD', 'ショップ'], true) && $postEmpName) {
        $db = getDB();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS store_monthly_budgets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_name VARCHAR(100) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, budget_detail TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smb (company_id, employee_name, year, month), INDEX idx_smb_emp (company_id, employee_name, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) {}
        $bStmt = $db->prepare("INSERT INTO store_monthly_budgets (company_id, employee_name, year, month, budget_detail) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE budget_detail=VALUES(budget_detail), updated_at=NOW()");
        $bStmt->execute([$cid, $postEmpName, $budYear, $budMonth, $budgetDetail]);
    }

    try {
        $id = saveDailyReport($cid, $data);
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[save_daily_report API] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $delId = (int)($data['id'] ?? 0);
    if ($myName !== null) {
        $ex = getDB()->prepare('SELECT employee_name FROM sales_daily_reports WHERE id=? AND company_id=?');
        $ex->execute([$delId, $cid]);
        $owner = $ex->fetchColumn();
        if ($owner !== false && $owner !== $myName) { echo json_encode(['error' => 'Forbidden']); exit; }
    }
    deleteDailyReport($delId, $cid);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
