<?php
/**
 * 案件店舗管理 API
 * GET ?division=first|other&year=&month=&q=&status=&active_only=
 *
 * 既存の sales_cases（常勤・イベント両方）をそのまま集計する。
 * 新しいデータは持たず、案件追加・編集の内容がそのまま反映される。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }
if (!isAdmin()) { echo json_encode(['error' => 'Forbidden']); exit; }

session_write_close();

$db       = getDB();
$division = ($_GET['division'] ?? 'first') === 'other' ? 'other' : 'first';
// 案件種別: regular=常勤 / event=イベント（常勤とイベントを分けて表示する）
$caseType = $_GET['case_type'] ?? 'regular';
if (!in_array($caseType, ['regular', 'event'], true)) $caseType = 'regular';
$year     = (int)($_GET['year']  ?? 0);
$month    = (int)($_GET['month'] ?? 0);
$q        = trim($_GET['q'] ?? '');
$statusF  = trim($_GET['status'] ?? '');
$activeOnly = ($_GET['active_only'] ?? '') === '1';

// 年月未指定なら、選択中の案件種別でデータがある最新月にフォールバック
if (!$year || !$month) {
    $lStmt = $db->prepare("SELECT case_year, case_month FROM sales_cases
                           WHERE company_id = ? AND case_type = ?
                           ORDER BY case_year DESC, case_month DESC LIMIT 1");
    $lStmt->execute([$cid, $caseType]);
    $l = $lStmt->fetch(PDO::FETCH_ASSOC);
    $year  = $l ? (int)$l['case_year']  : (int)date('Y');
    $month = $l ? (int)$l['case_month'] : (int)date('n');
}

/**
 * 既存の案件データからステータスを判定する
 * （新しいステータス項目は作らず、status と稼働期間から導出）
 *
 * 判定の基準は「今日」ではなく【表示中の月】。
 * 表示月の月初〜月末と案件の稼働期間が重なっていれば「稼働中」とする。
 * これにより過去月を表示しても、その月の稼働状況をそのまま確認できる。
 *
 * @param string $monthStart 表示月の月初 (Y-m-d)
 * @param string $monthEnd   表示月の月末 (Y-m-d)
 */
function caseStoreStatus(array $c, string $monthStart, string $monthEnd): string {
    $st = $c['status'] ?? '';
    if ($st === 'cancelled') return '稼働終了';
    if ($st === 'draft')     return '準備中';
    $s = $c['start_date'] ?? null;
    $e = $c['end_date']   ?? null;
    // 表示月より前に終了している
    if ($e && $e < $monthStart) return '稼働終了';
    // 表示月より後に開始する
    if ($s && $s > $monthEnd)   return '調整中';
    // 表示月と稼働期間が重なっている
    if ($s && $s <= $monthEnd && ($e === null || $e >= $monthStart)) return '稼働中';
    return '調整中';
}

// 区分: 1次案件 = case_division='1次' / その他案件 = それ以外（未設定含む）
$divWhere = $division === 'first'
    ? "AND sc.case_division = '1次'"
    : "AND (sc.case_division IS NULL OR sc.case_division <> '1次')";

try {
    $stmt = $db->prepare("
        SELECT sc.id, sc.case_type, sc.case_division, sc.client_id, sc.store_name,
               sc.worker_name, sc.worker_type, sc.unit_price_in, sc.status,
               sc.start_date, sc.end_date, sc.note,
               cl.client_name, cl.display_name
        FROM sales_cases sc
        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
        WHERE sc.company_id = ? AND sc.case_type = ? AND sc.case_year = ? AND sc.case_month = ?
          $divWhere
        ORDER BY cl.client_name, sc.store_name, sc.worker_name
    ");
    $stmt->execute([$cid, $caseType, $year, $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'データの取得に失敗しました']); exit;
}

// 判定基準は表示中の月（月初〜月末）
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// ── 絞り込み（検索 / ステータス / 稼働中のみ）──
$filtered = [];
foreach ($rows as $r) {
    $r['_status'] = caseStoreStatus($r, $monthStart, $monthEnd);
    // 案件店舗管理は「会社名（正式名称）」で表示する（表記名は使わない）
    $r['_client'] = trim((string)($r['client_name'] ?? '')) !== '' ? (string)$r['client_name'] : '未設定';
    $r['_store']  = trim((string)($r['store_name'] ?? '')) !== '' ? (string)$r['store_name'] : '未設定';

    if ($q !== '' && mb_stripos($r['_client'], $q) === false && mb_stripos($r['_store'], $q) === false) continue;
    if ($statusF !== '' && $r['_status'] !== $statusF) continue;
    if ($activeOnly && $r['_status'] !== '稼働中') continue;
    $filtered[] = $r;
}

// ── クライアント → 店舗 → 稼働者 に集約 ──
$clients = [];
foreach ($filtered as $r) {
    $ck = $r['_client'];
    if (!isset($clients[$ck])) {
        $clients[$ck] = ['name' => $ck, 'stores' => [], '_prices' => []];
    }
    $sk = $r['_store'];
    if (!isset($clients[$ck]['stores'][$sk])) {
        $clients[$ck]['stores'][$sk] = ['store_name' => $sk, 'workers' => []];
    }
    $clients[$ck]['stores'][$sk]['workers'][] = [
        'id'          => (int)$r['id'],
        'worker_name' => trim((string)($r['worker_name'] ?? '')),
        'store_name'  => $sk,
        'unit_price'  => (int)round((float)$r['unit_price_in']),
        'status'      => $r['_status'],
        'note'        => (string)($r['note'] ?? ''),
        'case_type'   => (string)$r['case_type'],
    ];
    if ((float)$r['unit_price_in'] > 0) $clients[$ck]['_prices'][] = (float)$r['unit_price_in'];
}

// ── クライアント単位の集計 ──
$out = [];
$allWorkers = []; $allStores = []; $allActiveStores = []; $allPrices = [];
foreach ($clients as $ck => $c) {
    $stores = array_values($c['stores']);
    $workerNames = []; $activeStores = [];
    foreach ($stores as $s) {
        foreach ($s['workers'] as $w) {
            if ($w['worker_name'] !== '') {
                $workerNames[$w['worker_name']] = true;
                $allWorkers[$w['worker_name']]  = true;
            }
            if ($w['status'] === '稼働中') {
                $activeStores[$s['store_name']]        = true;
                $allActiveStores[$ck.'|'.$s['store_name']] = true;
            }
        }
        $allStores[$ck.'|'.$s['store_name']] = true;
    }
    $prices = $c['_prices'];
    foreach ($prices as $p) $allPrices[] = $p;
    $out[] = [
        'name'         => $c['name'],
        'store_count'  => count($stores),
        'worker_count' => count($workerNames),
        'avg_price'    => $prices ? (int)round(array_sum($prices) / count($prices)) : 0,
        'active_store_count' => count($activeStores),
        'stores'       => $stores,
    ];
}

echo json_encode([
    'ok'        => true,
    'division'  => $division,
    'case_type' => $caseType,
    'year'      => $year,
    'month'     => $month,
    'clients'  => $out,
    'summary'  => [
        'client_count'       => count($out),
        'store_count'        => count($allStores),
        'worker_count'       => count($allWorkers),
        'avg_price'          => $allPrices ? (int)round(array_sum($allPrices) / count($allPrices)) : 0,
        'active_store_count' => count($allActiveStores),
    ],
    'updated_at' => date('Y/m/d H:i'),
], JSON_UNESCAPED_UNICODE);
