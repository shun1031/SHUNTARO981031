<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

requireApiAuth();

$cid = getCompanyId();
if (!$cid) {
    echo json_encode(['ok' => false, 'error' => 'No company']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['employee_name']) || empty($input['shift_date'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// 権限チェック: 一般社員は自分の名前のみ
$empFilter = getEmployeeNameFilter();
if ($empFilter !== null && $empFilter !== $input['employee_name']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '他のユーザーのシフトは登録できません']);
    exit;
}

try {
    $id = saveShift($cid, $input);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
