<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/eval_functions.php';

header('Content-Type: application/json; charset=utf-8');
requireApiAuth();
$cid = getCompanyId();
$db  = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$sheetId = (int)($data['sheet_id'] ?? 0);
if (!$sheetId) { http_response_code(400); echo json_encode(['error'=>'sheet_id required']); exit; }

$sheet = getEvalSheet($sheetId, $cid);
if (!$sheet) { http_response_code(404); echo json_encode(['error'=>'Sheet not found']); exit; }

try {
    // ウェイト取得
    $weights = getAxisWeight($cid, $sheet['department_key']) ?: ['weight_performance'=>40,'weight_action'=>40,'weight_competency'=>20];

    // 業績スコア計算（加重平均）
    $perfScores = getPerformanceScores($sheetId);
    $calcAxis = function($scores, $scoreField) {
        $totalWeight = 0; $weightedSum = 0;
        foreach ($scores as $s) {
            $val = $s[$scoreField] ?? null;
            $w = (int)($s['weight'] ?? 100);
            if ($val !== null) { $weightedSum += (float)$val * $w; $totalWeight += $w; }
        }
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
    };

    $actScores  = getActionScores($sheetId);
    $compScores = getCompetencyScores($sheetId);

    // コンピテンシーは1-5→0-100変換
    $calcComp = function($scores, $field) {
        $totalWeight = 0; $weightedSum = 0;
        foreach ($scores as $s) {
            $level = $s[$field] ?? null;
            $w = (int)($s['weight'] ?? 100);
            if ($level !== null) { $weightedSum += ((int)$level * 20) * $w; $totalWeight += $w; }
        }
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
    };

    $validPrefixes = ['self', 'primary', 'final'];
    foreach ($validPrefixes as $prefix) {
        if (!in_array($prefix, $validPrefixes, true)) continue; // 防御的チェック
        $sf = $prefix === 'self' ? 'self_score' : ($prefix === 'primary' ? 'primary_score' : 'final_score');
        $lf = $prefix === 'self' ? 'self_level' : ($prefix === 'primary' ? 'primary_level' : 'final_level');

        $sp = $calcAxis($perfScores, $sf);
        $sa = $calcAxis($actScores, $sf);
        $sc = $calcComp($compScores, $lf);

        if ($sp !== null || $sa !== null || $sc !== null) {
            $wp = ($weights['weight_performance'] ?? 40) / 100;
            $wa = ($weights['weight_action'] ?? 40) / 100;
            $wc = ($weights['weight_competency'] ?? 20) / 100;
            $total = round(($sp ?? 0) * $wp + ($sa ?? 0) * $wa + ($sc ?? 0) * $wc, 2);

            $db->prepare("UPDATE eval_sheets SET {$prefix}_score_performance=?, {$prefix}_score_action=?, {$prefix}_score_competency=?, {$prefix}_score_total=?, updated_at=NOW() WHERE id=? AND company_id=?")
               ->execute([$sp, $sa, $sc, $total, $sheetId, $cid]);
        }
    }

    // 更新されたシート取得
    $updated = getEvalSheet($sheetId, $cid);
    echo json_encode([
        'success' => true,
        'scores' => [
            'self_performance'  => $updated['self_score_performance'],
            'self_action'       => $updated['self_score_action'],
            'self_competency'   => $updated['self_score_competency'],
            'self_total'        => $updated['self_score_total'],
            'primary_performance' => $updated['primary_score_performance'],
            'primary_action'      => $updated['primary_score_action'],
            'primary_competency'  => $updated['primary_score_competency'],
            'primary_total'       => $updated['primary_score_total'],
        ]
    ]);
} catch (Exception $e) {
    error_log('eval_calc API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '計算に失敗しました']);
}
