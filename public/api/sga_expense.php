<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireApiAuth();

$cid = getCompanyId();
if (!$cid) {
    http_response_code(403);
    echo json_encode(['error' => '会社情報が取得できません']);
    exit;
}

$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['super_admin', 'company_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => '権限がありません']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
switch ($method) {
    case 'GET':
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));
        echo json_encode([
            'summary' => getSgaSummary($cid, $year, $month),
            'expenses' => getSgaExpenses($cid, $year, $month),
        ]);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($input['category']) || empty($input['target_year']) || empty($input['target_month'])) {
            http_response_code(400);
            echo json_encode(['error' => '必須項目が不足しています']);
            exit;
        }
        $id = createSgaExpense($cid, $input);
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? 0);
        if (!$id || empty($input['category']) || empty($input['target_year']) || empty($input['target_month'])) {
            http_response_code(400);
            echo json_encode(['error' => '必須項目が不足しています']);
            exit;
        }
        if (!getSgaExpense($id, $cid)) {
            http_response_code(404);
            echo json_encode(['error' => '対象が見つかりません']);
            exit;
        }
        updateSgaExpense($id, $cid, $input);
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'IDが必要です']);
            exit;
        }
        deleteSgaExpense($id, $cid);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
} catch (Exception $e) {
    error_log('sga_expense API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラーが発生しました']);
}
