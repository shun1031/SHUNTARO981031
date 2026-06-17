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

$type = $_GET['type'] ?? 'kpi';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

try {
    switch ($type) {
        case 'kpi':
            echo json_encode(getSalesDashboardKPIs($cid, $year, $month));
            break;

        case 'trend':
            echo json_encode(getSalesRevenueTrend($cid, $year));
            break;

        case 'monthly':
            $caseType = $_GET['case_type'] ?? null;
            echo json_encode(getSalesMonthlySummary($cid, $year, $month, $caseType));
            break;

        case 'annual':
            echo json_encode(getSalesAnnualSummary($cid, $year));
            break;

        case 'client':
            echo json_encode(getSalesRevenueByClient($cid, $year, $month));
            break;

        case 'client_report':
            echo json_encode(getSalesClientReport($cid, $year));
            break;

        case 'worker_breakdown':
            echo json_encode(getSalesWorkerBreakdown($cid, $year, $month));
            break;

        case 'achievement':
            echo json_encode(getSalesAchievement($cid, $year, $month));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => '不明なタイプです']);
    }
} catch (Exception $e) {
    error_log('sales_summary API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'データ取得に失敗しました']);
}
