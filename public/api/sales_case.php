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

$method = $_SERVER['REQUEST_METHOD'];

try {
switch ($method) {
    case 'GET':
        $id = (int)($_GET['id'] ?? 0);
        if ($id) {
            $case = getSalesCase($id, $cid);
            echo json_encode($case ?: ['error' => '案件が見つかりません']);
        } else {
            $filters = [
                'case_type' => $_GET['case_type'] ?? '',
                'year' => $_GET['year'] ?? '',
                'month' => $_GET['month'] ?? '',
                'client_id' => $_GET['client_id'] ?? '',
                'worker_type' => $_GET['worker_type'] ?? '',
                'status' => $_GET['status'] ?? '',
                'search' => $_GET['search'] ?? '',
            ];
            $limit = min((int)($_GET['limit'] ?? 100), 500);
            $offset = max((int)($_GET['offset'] ?? 0), 0);
            $cases = getSalesCases($cid, $filters, $limit, $offset);
            $total = countSalesCases($cid, $filters);
            echo json_encode(['data' => $cases, 'total' => $total]);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($input['case_type']) || empty($input['start_date'])) {
            http_response_code(400);
            echo json_encode(['error' => '必須項目が不足しています']);
            exit;
        }
        $id = createSalesCase($cid, $input);
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'IDが必要です']);
            exit;
        }
        updateSalesCase($id, $cid, $input);
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'IDが必要です']);
            exit;
        }
        deleteSalesCase($id, $cid);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
} catch (Exception $e) {
    error_log('sales_case API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラーが発生しました']);
}
