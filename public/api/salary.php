<?php
// ============================================================
// 給与管理 API
// ============================================================
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

// インセンティブ率（sales_rep_report.php と同じ定義）
const INCENTIVE_RATES = ['竹内陽' => 0.0, '直営業' => 0.0, '佐藤思杰' => 0.20, '近藤航' => 0.20];
const INCENTIVE_DEFAULT = 0.30;

function getIncentiveRate(string $name): float {
    return array_key_exists($name, INCENTIVE_RATES) ? INCENTIVE_RATES[$name] : INCENTIVE_DEFAULT;
}

// ----------------------------------------------------------------
// 担当者別の分割済み粗利を取得（インセンティブ計算用）
// ----------------------------------------------------------------
function getIncentiveProfitByPerson(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT sales_rep, manager, recruiter,
               COALESCE(SUM(gross_profit), 0) as profit
        FROM sales_cases
        WHERE company_id = ? AND case_year = ? AND case_month = ?
          AND status != 'cancelled' AND sales_rep != ''
        GROUP BY sales_rep, manager, recruiter
    ");
    $stmt->execute([$companyId, $year, $month]);

    $byPerson = [];
    foreach ($stmt->fetchAll() as $row) {
        $profit  = (int)$row['profit'];
        $repPro  = (int)floor($profit / 2);
        $refPro  = $profit - $repPro;
        $rep     = $row['sales_rep'];
        $manager = trim($row['manager'] ?? '');
        $recruiter = trim($row['recruiter'] ?? '');
        $referrer  = $manager !== '' ? $manager : ($recruiter !== '' ? $recruiter : '直営業');

        $byPerson[$rep]      = ($byPerson[$rep]      ?? 0) + $repPro;
        $byPerson[$referrer] = ($byPerson[$referrer] ?? 0) + $refPro;
    }
    return $byPerson;
}

// ----------------------------------------------------------------
// メインクエリ: 常勤案件（自社外注）+ インセンティブ計算
// ----------------------------------------------------------------
function buildSalaryData(int $companyId, int $payYear, int $payMonth, array $filters = []): array {
    // 稼働月 = 支払予定月 - 1
    $workDt = new DateTime("{$payYear}-{$payMonth}-01");
    $workDt->modify('-1 month');
    $workYear  = (int)$workDt->format('Y');
    $workMonth = (int)$workDt->format('n');

    // インセンティブ月 = 支払予定月 - 2
    $incDt = clone $workDt;
    $incDt->modify('-1 month');
    $incYear  = (int)$incDt->format('Y');
    $incMonth = (int)$incDt->format('n');

    $db = getDB();

    // 常勤案件クエリ
    $where  = ["sc.company_id = ?", "sc.case_type = 'regular'", "sc.worker_type = '自社外注'",
               "sc.case_year = ?", "sc.case_month = ?", "sc.status != 'cancelled'"];
    $params = [$companyId, $workYear, $workMonth];

    if (!empty($filters['client_id'])) {
        $where[]  = 'sc.client_id = ?';
        $params[] = (int)$filters['client_id'];
    }
    if (!empty($filters['store_name'])) {
        $where[]  = 'sc.store_name LIKE ?';
        $params[] = '%' . $filters['store_name'] . '%';
    }
    if (!empty($filters['sales_rep'])) {
        $where[]  = 'sc.sales_rep LIKE ?';
        $params[] = '%' . $filters['sales_rep'] . '%';
    }
    if (!empty($filters['worker_name'])) {
        $where[]  = 'sc.worker_name LIKE ?';
        $params[] = '%' . $filters['worker_name'] . '%';
    }
    if (!empty($filters['status'])) {
        $where[]  = 'sc.status = ?';
        $params[] = $filters['status'];
    }

    $sql = "SELECT sc.id, sc.worker_name, sc.client_id, sc.store_name, sc.carrier,
                   sc.unit_price_in, sc.unit_price_out, sc.days_worked,
                   sc.revenue, sc.cost, sc.gross_profit, sc.status,
                   sc.sales_rep,
                   cl.client_name
            FROM sales_cases sc
            LEFT JOIN sales_clients cl ON sc.client_id = cl.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sc.worker_name, sc.id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // 担当者別インセンティブ粗利
    $incProfitMap = getIncentiveProfitByPerson($companyId, $incYear, $incMonth);

    // 追加支給を読み込む
    $addMap = [];
    try {
        $addStmt = $db->prepare("SELECT worker_name, amount, reason FROM salary_additional_payments
            WHERE company_id = ? AND pay_year = ? AND pay_month = ?");
        $addStmt->execute([$companyId, $payYear, $payMonth]);
        foreach ($addStmt->fetchAll() as $r) {
            $addMap[$r['worker_name']] = ['amount' => (int)$r['amount'], 'reason' => $r['reason'] ?? ''];
        }
    } catch (PDOException $e) { /* テーブル未作成時は無視 */ }

    // スタッフ別に集計
    $staffMap = [];
    foreach ($rows as $r) {
        $name = $r['worker_name'];
        if (!isset($staffMap[$name])) {
            $staffMap[$name] = [
                'worker_name'    => $name,
                'case_count'     => 0,
                'regular_salary' => 0,
                'cases'          => [],
            ];
        }
        $salary70 = (int)round($r['revenue'] * 0.7);
        $staffMap[$name]['case_count']++;
        $staffMap[$name]['regular_salary'] += $salary70;
        $staffMap[$name]['cases'][] = [
            'id'          => (int)$r['id'],
            'client_name' => $r['client_name'] ?? '',
            'store_name'  => $r['store_name']  ?? '',
            'carrier'     => $r['carrier']      ?? '',
            'sales_rep'   => $r['sales_rep']    ?? '',
            'revenue'     => (int)$r['revenue'],
            'salary70'    => $salary70,
            'status'      => $r['status'],
        ];
    }

    // インセンティブ付与・合計
    $staffList = [];
    foreach ($staffMap as $name => $s) {
        $rate        = getIncentiveRate($name);
        $splitProfit = $incProfitMap[$name] ?? 0;
        $incentive   = ($rate > 0 && $splitProfit > 0) ? (int)round($splitProfit * $rate) : 0;
        $add         = $addMap[$name] ?? ['amount' => 0, 'reason' => ''];
        $additional  = $add['amount'];
        $total       = $s['regular_salary'] + $additional + $incentive;

        $staffList[] = array_merge($s, [
            'incentive'          => $incentive,
            'additional'         => $additional,
            'additional_reason'  => $add['reason'],
            'total'              => $total,
            'incentive_detail'   => [
                'split_profit' => $splitProfit,
                'rate'         => $rate,
                'amount'       => $incentive,
            ],
        ]);
    }

    // スタッフ名順
    usort($staffList, fn($a, $b) => strcmp($a['worker_name'], $b['worker_name']));

    // 集計
    $summary = [
        'staff_count'      => count($staffList),
        'case_count'       => array_sum(array_column($staffList, 'case_count')),
        'regular_total'    => array_sum(array_column($staffList, 'regular_salary')),
        'additional_total' => array_sum(array_column($staffList, 'additional')),
        'incentive_total'  => array_sum(array_column($staffList, 'incentive')),
        'grand_total'      => array_sum(array_column($staffList, 'total')),
    ];

    return [
        'pay_year'   => $payYear,
        'pay_month'  => $payMonth,
        'work_year'  => $workYear,
        'work_month' => $workMonth,
        'inc_year'   => $incYear,
        'inc_month'  => $incMonth,
        'staff'      => $staffList,
        'summary'    => $summary,
    ];
}

// ----------------------------------------------------------------
// POST: 追加支給の保存
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!verifyCsrfToken($input['csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['error' => 'csrf']); exit;
    }
    if (($input['action'] ?? '') === 'save_additional') {
        $py         = (int)($input['pay_year']    ?? 0);
        $pm         = (int)($input['pay_month']   ?? 0);
        $workerName = trim($input['worker_name']  ?? '');
        $amount     = max(0, (int)($input['amount'] ?? 0));
        $reason     = trim($input['reason']       ?? '');
        if (!$py || !$pm || $workerName === '') {
            echo json_encode(['error' => 'invalid']); exit;
        }
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO salary_additional_payments
            (company_id, pay_year, pay_month, worker_name, amount, reason)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount), reason = VALUES(reason)");
        $stmt->execute([$cid, $py, $pm, $workerName, $amount, $reason]);
        echo json_encode(['ok' => true]); exit;
    }
    echo json_encode(['error' => 'unknown_action']); exit;
}

// ----------------------------------------------------------------
// エクスポート
// ----------------------------------------------------------------
$payYear  = (int)($_GET['pay_year']  ?? date('Y'));
$payMonth = (int)($_GET['pay_month'] ?? date('n'));
$filters  = [
    'client_id'   => $_GET['client_id']   ?? '',
    'store_name'  => $_GET['store_name']  ?? '',
    'sales_rep'   => $_GET['sales_rep']   ?? '',
    'worker_name' => $_GET['worker_name'] ?? '',
    'status'      => $_GET['status']      ?? '',
];

$export = $_GET['export'] ?? '';

if ($export === 'csv' || $export === 'excel') {
    $data = buildSalaryData($cid, $payYear, $payMonth, $filters);
    $label = "{$payYear}年{$payMonth}月末";
    $workLabel = "{$data['work_year']}年{$data['work_month']}月稼働分";
    $incLabel  = "{$data['inc_year']}年{$data['inc_month']}月分";
    $filename  = "給与一覧_{$payYear}{$payMonth}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['支払予定月', '稼働月', 'インセンティブ月', 'スタッフ名', '案件数',
                   '常勤案件売上（7割）', '追加支給', '追加支給理由', 'インセンティブ費用', '総支給額']);
    foreach ($data['staff'] as $s) {
        fputcsv($out, [$label, $workLabel, $incLabel, $s['worker_name'], $s['case_count'],
                       $s['regular_salary'], $s['additional'] ?? 0, $s['additional_reason'] ?? '',
                       $s['incentive'], $s['total']]);
    }
    fputcsv($out, ['合計', '', '', '',
                   $data['summary']['case_count'],
                   $data['summary']['regular_total'],
                   $data['summary']['additional_total'] ?? 0,
                   '',
                   $data['summary']['incentive_total'],
                   $data['summary']['grand_total']]);
    fclose($out);
    exit;
}

if ($export === 'excel_xml') {
    $data = buildSalaryData($cid, $payYear, $payMonth, $filters);
    $label     = "{$payYear}年{$payMonth}月末";
    $workLabel = "{$data['work_year']}年{$data['work_month']}月稼働分";
    $incLabel  = "{$data['inc_year']}年{$data['inc_month']}月分";
    $filename  = "給与一覧_{$payYear}{$payMonth}.xls";

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
         xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Worksheet ss:Name="給与一覧"><Table>' . "\n";

    $hdr = ['支払予定月', '稼働月', 'インセンティブ月', 'スタッフ名', '案件数',
            '常勤案件売上（7割）', '追加支給', '追加支給理由', 'インセンティブ費用', '総支給額'];
    echo '<Row>';
    foreach ($hdr as $h) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($data['staff'] as $s) {
        echo '<Row>';
        foreach ([$label, $workLabel, $incLabel, $s['worker_name']] as $v) {
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($v, ENT_XML1) . '</Data></Cell>';
        }
        foreach ([$s['case_count'], $s['regular_salary'], $s['additional'] ?? 0] as $v) {
            echo '<Cell><Data ss:Type="Number">' . (int)$v . '</Data></Cell>';
        }
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($s['additional_reason'] ?? '', ENT_XML1) . '</Data></Cell>';
        foreach ([$s['incentive'], $s['total']] as $v) {
            echo '<Cell><Data ss:Type="Number">' . (int)$v . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    }
    // 合計行
    echo '<Row>';
    foreach (['合計', '', '', '', $data['summary']['case_count'],
              $data['summary']['regular_total'], $data['summary']['additional_total'] ?? 0, '',
              $data['summary']['incentive_total'], $data['summary']['grand_total']] as $i => $v) {
        $type = ($i >= 4 && $i !== 7) ? 'Number' : 'String';
        echo '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars((string)$v, ENT_XML1) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    echo '</Table></Worksheet></Workbook>';
    exit;
}

// ----------------------------------------------------------------
// JSON レスポンス
// ----------------------------------------------------------------
header('Content-Type: application/json; charset=UTF-8');
$data = buildSalaryData($cid, $payYear, $payMonth, $filters);
echo json_encode($data, JSON_UNESCAPED_UNICODE);
