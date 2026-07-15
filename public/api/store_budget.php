<?php
/**
 * 店舗予算 CRUD API
 * GET  ?employee=&year=&month= → {exists, budget}
 * POST {action:save, employee, year, month, budget_detail, csrf}
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS store_monthly_budgets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_name VARCHAR(100) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, budget_detail TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smb (company_id, employee_name, year, month), INDEX idx_smb_emp (company_id, employee_name, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $emp   = trim($_GET['employee'] ?? '');
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $empFilter = getEmployeeNameFilter();
    if ($empFilter !== null) $emp = $empFilter;
    if (!$emp) { echo json_encode(['exists' => false, 'budget' => null]); exit; }
    $stmt = $db->prepare("SELECT budget_detail FROM store_monthly_budgets WHERE company_id=? AND employee_name=? AND year=? AND month=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid, $emp, $year, $month]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'exists' => (bool)$row,
        'budget' => $row ? (json_decode($row['budget_detail'] ?? '{}', true) ?: null) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) $body = $_POST;
    if (!verifyCsrfToken($body['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }
    $emp    = trim($body['employee'] ?? '');
    $year   = (int)($body['year']  ?? date('Y'));
    $month  = (int)($body['month'] ?? date('n'));
    $detail = $body['budget_detail'] ?? '{}';
    $empFilter = getEmployeeNameFilter();
    if ($empFilter !== null && $empFilter !== $emp) { echo json_encode(['error' => 'Forbidden']); exit; }
    if (!$emp) { echo json_encode(['error' => 'employee required']); exit; }
    if (is_array($detail)) $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
    if (json_decode($detail) === null && json_last_error() !== JSON_ERROR_NONE) { echo json_encode(['error' => 'Invalid JSON']); exit; }
    $db->prepare("DELETE FROM store_monthly_budgets WHERE company_id=? AND employee_name=? AND year=? AND month=?")->execute([$cid, $emp, $year, $month]);
    $db->prepare("INSERT INTO store_monthly_budgets (company_id, employee_name, year, month, budget_detail) VALUES (?,?,?,?,?)")->execute([$cid, $emp, $year, $month, $detail]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
