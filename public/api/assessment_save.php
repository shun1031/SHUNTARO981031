<?php
/**
 * SPI/SF受検 自動保存API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireApiAuth();
$cid = getCompanyId();
$myEmpId = $_SESSION['employee_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$type = $data['type'] ?? '';
$attemptId = (int)($data['attempt_id'] ?? 0);
$questionId = (int)($data['question_id'] ?? 0);
$answer = (int)($data['answer'] ?? 0);

if (!in_array($type, ['spi', 'sf']) || !$attemptId || !$questionId || $answer < 1 || $answer > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    // attemptの所有者確認
    $db = getDB();
    $table = $type === 'spi' ? 'spi_attempts' : 'sf_attempts';
    $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = ? AND employee_id = ? AND status = 'in_progress'");
    $stmt->execute([$attemptId, $myEmpId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied or attempt already completed']);
        exit;
    }

    saveAssessmentAnswer($type, $attemptId, $myEmpId, $cid, $questionId, $answer);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('assessment_save API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '保存に失敗しました']);
}
