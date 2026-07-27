<?php
/**
 * 常勤案件 一括インポート API
 * POST action=preview : 貼り付けデータを解釈して確認用に返す（DBは変更しない）
 * POST action=commit  : 実際に案件を登録する
 *
 * 列の解釈は includes/sales/regular_import.php を参照
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/sales/regular_import.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
$user = getCurrentUser();
if (!in_array($user['role'] ?? '', ['super_admin', 'company_admin'], true)) {
    echo json_encode(['error' => '権限がありません']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }
if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'CSRF']); exit; }

$action = $_POST['action'] ?? '';
$year   = (int)($_POST['year']  ?? date('Y'));
$month  = (int)($_POST['month'] ?? date('n'));
$raw    = (string)($_POST['data'] ?? '');
if ($year < 2000 || $month < 1 || $month > 12) { echo json_encode(['error' => '対象年月が不正です']); exit; }

// ─── マスタ（既存名 → id） ───
$clientMap = [];
foreach (getSalesClients($cid) as $cl) { $clientMap[trim($cl['client_name'])] = (int)$cl['id']; }
$allianceMap = [];
foreach (getSalesAlliances($cid) as $al) { $allianceMap[trim($al['alliance_name'])] = (int)$al['id']; }

// ─── 貼り付けデータの解釈 ───
$parsed       = parseRegularImport($raw, $clientMap, $allianceMap);
$rows         = $parsed['rows'];
$skipped      = $parsed['skipped'];
$newClients   = $parsed['new_clients'];
$newAlliances = $parsed['new_alliances'];

$totalIn  = array_sum(array_column($rows, 'unit_price_in'));
$totalOut = array_sum(array_column($rows, 'unit_price_out'));

// ─── 確認（プレビュー） ───
if ($action === 'preview') {
    echo json_encode([
        'success'       => true,
        'target'        => sprintf('%d年%d月', $year, $month),
        'rows'          => $rows,
        'skipped'       => $skipped,
        'new_clients'   => $newClients,
        'new_alliances' => $newAlliances,
        'count'         => count($rows),
        'total_revenue' => $totalIn,
        'total_cost'    => $totalOut,
        'total_profit'  => $totalIn - $totalOut,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── 登録 ───
if ($action === 'commit') {
    if (!$rows) { echo json_encode(['error' => '登録できる行がありません']); exit; }
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $created = 0; $failed = [];
    $createdClients = 0; $createdAlliances = 0;

    foreach ($rows as $r) {
        try {
            // 取引先が未登録なら作成
            $clientId = $r['client_id'];
            if (!$clientId && $r['client_name'] !== '') {
                if (isset($clientMap[$r['client_name']])) {
                    $clientId = $clientMap[$r['client_name']];
                } else {
                    $clientId = createSalesClient($cid, ['client_name' => $r['client_name']]);
                    $clientMap[$r['client_name']] = $clientId;
                    $createdClients++;
                }
            }
            // 外注先が未登録なら作成
            $allianceId = $r['alliance_id'];
            if (!$allianceId && $r['alliance_name'] !== '') {
                if (isset($allianceMap[$r['alliance_name']])) {
                    $allianceId = $allianceMap[$r['alliance_name']];
                } else {
                    $allianceId = createSalesAlliance($cid, ['alliance_name' => $r['alliance_name']]);
                    $allianceMap[$r['alliance_name']] = $allianceId;
                    $createdAlliances++;
                }
            }

            createSalesCase($cid, [
                'case_type'           => 'regular',
                'client_id'           => $clientId,
                'start_date'          => $startDate,
                'end_date'            => '',
                'sales_rep'           => $r['sales_rep'],
                'manager'             => $r['manager'],
                'recruiter'           => $r['recruiter'],
                'worker_type'         => $r['worker_type'],
                'worker_name'         => $r['worker_name'],
                'alliance_id'         => $allianceId ?: null,
                'carrier'             => $r['carrier'],
                'trade_name'          => '',          // 元データに列がないため空
                'area_id'             => null,
                'store_name'          => $r['store_name'],
                'unit_price_in'       => $r['unit_price_in'],
                'unit_price_out'      => $r['unit_price_out'],
                'days_worked'         => $r['days_worked'],
                'status'              => 'confirmed',
                'note'                => $r['note'],
                'case_division'       => null,        // 元データに列がないため空
                'gross_profit_direct' => $r['unit_price_in'] - $r['unit_price_out'],
            ]);
            $created++;
        } catch (Throwable $e) {
            error_log('[import_regular_cases] line ' . $r['line'] . ': ' . $e->getMessage());
            $failed[] = ['line' => $r['line'], 'worker_name' => $r['worker_name'], 'error' => $e->getMessage()];
        }
    }

    echo json_encode([
        'success'           => true,
        'created'           => $created,
        'failed'            => $failed,
        'created_clients'   => $createdClients,
        'created_alliances' => $createdAlliances,
        'target'            => sprintf('%d年%d月', $year, $month),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
