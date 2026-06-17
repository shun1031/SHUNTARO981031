<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/eval_functions.php';

header('Content-Type: application/json; charset=utf-8');
requireApiAuth();
$cid = getCompanyId();
$db  = getDB();
$myEmpId = $_SESSION['employee_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$sheetId  = (int)($data['sheet_id'] ?? 0);
$saveType = $data['save_type'] ?? '';
$items    = $data['data'] ?? [];

if (!$sheetId || !$saveType) {
    http_response_code(400);
    echo json_encode(['error' => 'sheet_id and save_type required']);
    exit;
}

$sheet = getEvalSheet($sheetId, $cid);
if (!$sheet) { http_response_code(404); echo json_encode(['error'=>'Sheet not found']); exit; }

$isOwner = ($sheet['employee_id'] == $myEmpId);
$isEvaluator = ($sheet['evaluator_id'] == $myEmpId);
$isAdmin = in_array(getCurrentUser()['role'] ?? '', ['super_admin','company_admin']);
if (!$isOwner && !$isEvaluator && !$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$prefix = $isEvaluator && !$isOwner ? 'primary' : 'self';
$updated = 0;

try {
    $db->beginTransaction();
    if ($saveType === 'performance') {
        foreach ($items as $item) {
            $scoreId = (int)($item['id'] ?? 0);
            if (!$scoreId) continue;
            if ($prefix === 'self') {
                $db->prepare('UPDATE eval_performance_scores SET target_value=?, actual_value=?, self_score=?, self_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['target_value']??null, $item['actual_value']??null, $item['self_score']??null, $item['self_comment']??null, $scoreId, $sheetId]);
            } else {
                $db->prepare('UPDATE eval_performance_scores SET primary_score=?, primary_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['primary_score']??null, $item['primary_comment']??null, $scoreId, $sheetId]);
            }
            $updated++;
        }
    } elseif ($saveType === 'action') {
        foreach ($items as $item) {
            $scoreId = (int)($item['id'] ?? 0);
            if (!$scoreId) continue;
            if ($prefix === 'self') {
                $db->prepare('UPDATE eval_action_scores SET actual_value=?, is_achieved=?, self_score=?, self_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['actual_value']??null, $item['is_achieved']??0, $item['self_score']??null, $item['self_comment']??null, $scoreId, $sheetId]);
            } else {
                $db->prepare('UPDATE eval_action_scores SET primary_score=?, primary_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['primary_score']??null, $item['primary_comment']??null, $scoreId, $sheetId]);
            }
            $updated++;
        }
    } elseif ($saveType === 'competency') {
        foreach ($items as $item) {
            $scoreId = (int)($item['id'] ?? 0);
            if (!$scoreId) continue;
            if ($prefix === 'self') {
                $db->prepare('UPDATE eval_competency_scores SET self_level=?, self_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['self_level']??null, $item['self_comment']??null, $scoreId, $sheetId]);
            } else {
                $db->prepare('UPDATE eval_competency_scores SET primary_level=?, primary_comment=? WHERE id=? AND sheet_id=?')
                   ->execute([$item['primary_level']??null, $item['primary_comment']??null, $scoreId, $sheetId]);
            }
            $updated++;
        }
    } elseif ($saveType === 'comment') {
        $col = $prefix . '_comment';
        if (!in_array($col, ['self_comment', 'primary_comment'], true)) {
            throw new RuntimeException('Invalid comment column');
        }
        $db->prepare("UPDATE eval_sheets SET {$col} = ?, updated_at = NOW() WHERE id = ? AND company_id = ?")
           ->execute([$data['comment'] ?? '', $sheetId, $cid]);
        $updated = 1;
    }
    $db->commit();
    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
