<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/eval_functions.php';

header('Content-Type: application/json; charset=utf-8');
requireApiAuth();
$cid = getCompanyId();
$db  = getDB();
$myEmpId = $_SESSION['employee_id'] ?? 0;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $empId = (int)($data['employee_id'] ?? 0);
        $memo  = trim($data['memo'] ?? '');
        $cat   = $data['category'] ?? 'other';
        $date  = $data['praised_date'] ?? date('Y-m-d');
        if (!$empId || !$memo || !$myEmpId) {
            http_response_code(400);
            echo json_encode(['error' => '必須項目が不足しています']);
            exit;
        }
        $stmt = $db->prepare('INSERT INTO praise_points (company_id, employee_id, author_id, memo, category, praised_date) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$cid, $empId, $myEmpId, mb_substr($memo, 0, 500), $cat, $date]);
        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $empId = (int)($_GET['employee_id'] ?? 0);
        if (!$empId) { echo json_encode([]); exit; }
        $praise = getPraisePoints($empId, $_GET['from'] ?? null, $_GET['to'] ?? null, $cid);
        echo json_encode($praise);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log('praise API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラーが発生しました']);
}
