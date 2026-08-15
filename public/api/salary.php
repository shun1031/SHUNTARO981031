<?php
// ============================================================
// 給与管理 API
// ============================================================
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

// インセンティブ率は社員一覧（employees.incentive_rate）で管理する。
// 定義は includes/sales/reports.php の resolveIncentiveRate() に一本化した。
function getIncentiveRate(string $name): float {
    return resolveIncentiveRate($name, getIncentiveRateMap(getCompanyId() ?? 0));
}

// ----------------------------------------------------------------
// 担当者別の分割済み粗利を取得（インセンティブ計算用）
// ----------------------------------------------------------------
function getIncentiveProfitByPerson(int $companyId, int $year, int $month): array {
    $db = getDB();
    // 第3段階: 担当者は社員IDがあればその社員、無ければ従来どおり名前で特定する
    $empNameById = [];
    try {
        $enStmt = $db->prepare('SELECT id, name FROM employees WHERE company_id = ?');
        $enStmt->execute([$companyId]);
        foreach ($enStmt->fetchAll() as $_e) { $empNameById[(int)$_e['id']] = $_e['name']; }
    } catch (PDOException $e) { error_log('[getIncentiveProfitByPerson] ' . $e->getMessage()); }
    $nameOf = function ($id, string $fallback) use ($empNameById): string {
        return (!empty($id) && isset($empNameById[(int)$id])) ? $empNameById[(int)$id] : $fallback;
    };
    // 紹介者として扱える値か（空欄と「該当者なし」は人ではない。他の画面と同じ判定）
    $isPerson = fn(string $v): bool => $v !== '' && $v !== '該当者なし';

    $stmt = $db->prepare("
        SELECT sales_rep, manager, recruiter,
               sales_rep_id, manager_id, recruiter_id,
               COALESCE(SUM(gross_profit), 0) as profit
        FROM sales_cases
        WHERE company_id = ? AND case_year = ? AND case_month = ?
          AND status != 'cancelled' AND sales_rep != ''
        GROUP BY sales_rep, manager, recruiter, sales_rep_id, manager_id, recruiter_id
    ");
    $stmt->execute([$companyId, $year, $month]);

    $byPerson = [];
    foreach ($stmt->fetchAll() as $row) {
        $profit  = (int)$row['profit'];
        $repPro  = (int)floor($profit / 2);
        $refPro  = $profit - $repPro;
        $rep       = $nameOf($row['sales_rep_id'] ?? null, $row['sales_rep']);
        $manager   = trim($nameOf($row['manager_id']   ?? null, (string)($row['manager']   ?? '')));
        $recruiter = trim($nameOf($row['recruiter_id'] ?? null, (string)($row['recruiter'] ?? '')));
        $referrer  = $isPerson($manager) ? $manager : ($isPerson($recruiter) ? $recruiter : '直営業');

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
    // 対象者は社員名簿の雇用形態で判定する（案件側のスタッフ区分ではなく名簿を正とする）
    // 名簿に登録が無い稼働スタッフ（アライアンス等）は対象外になる
    $where  = ["sc.company_id = ?", "sc.case_type = 'regular'", "emp_w.employment_type = '自社外注'",
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
                   COALESCE(NULLIF(TRIM(cl.display_name),''), cl.client_name) AS client_name
            FROM sales_cases sc
            LEFT JOIN sales_clients cl ON sc.client_id = cl.id
            -- 社員名簿と結び付いている稼働スタッフのみを対象にする（内部結合）
            INNER JOIN employees emp_w
                    ON emp_w.id = sc.worker_employee_id
                   AND emp_w.company_id = sc.company_id
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
        // 1人に複数明細を持てる（id順に並べる）
        $addStmt = $db->prepare("SELECT id, worker_name, amount, reason FROM salary_additional_payments
            WHERE company_id = ? AND pay_year = ? AND pay_month = ? ORDER BY id");
        $addStmt->execute([$companyId, $payYear, $payMonth]);
        foreach ($addStmt->fetchAll() as $r) {
            $addMap[$r['worker_name']][] = ['amount' => (int)$r['amount'], 'reason' => $r['reason'] ?? ''];
        }
    } catch (PDOException $e) { /* テーブル未作成時は無視 */ }

    // 常勤案件売上(7割)の手入力上書きを読み込む
    $ovrMap = [];
    try {
        $ovrStmt = $db->prepare("SELECT worker_name, amount FROM salary_regular_overrides
            WHERE company_id = ? AND pay_year = ? AND pay_month = ?");
        $ovrStmt->execute([$companyId, $payYear, $payMonth]);
        foreach ($ovrStmt->fetchAll() as $r) {
            $ovrMap[$r['worker_name']] = (int)$r['amount'];
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
        // 追加支給: 複数明細の合計。理由は一覧用に要約、出力用に連結
        $addItems   = $addMap[$name] ?? [];
        $additional = 0;
        $addReasons = [];
        foreach ($addItems as $it) {
            $additional += $it['amount'];
            if (trim($it['reason']) !== '') $addReasons[] = $it['reason'];
        }
        $addReasonFirst = $addReasons[0] ?? '';
        $addReasonLabel = $addReasonFirst;                       // 一覧用「交通費 他1件」
        if (count($addReasons) > 1) {
            $addReasonLabel .= ' 他' . (count($addReasons) - 1) . '件';
        }
        $addReasonJoined = implode('・', $addReasons);           // CSV/Excel用

        // 常勤案件売上(7割): 手入力があればそちらを優先（自動計算値も併せて返す）
        $autoRegular = $s['regular_salary'];
        $isOverride  = array_key_exists($name, $ovrMap);
        if ($isOverride) { $s['regular_salary'] = $ovrMap[$name]; }

        $total       = $s['regular_salary'] + $additional + $incentive;

        $staffList[] = array_merge($s, [
            'regular_auto'       => $autoRegular,   // 自動計算だった金額（併記用）
            'regular_override'   => $isOverride,    // 手入力で上書きされているか
            'incentive'          => $incentive,
            'additional'         => $additional,
            'additional_reason'  => $addReasonLabel,    // 一覧表示用（要約）
            'additional_reasons' => $addReasonJoined,   // CSV/Excel用（連結）
            'additional_items'   => array_values($addItems), // 明細（詳細画面・編集用）
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
    // 給与データの保存は管理者のみ
    requireAdminWrite(true);
    header('Content-Type: application/json; charset=UTF-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!verifyCsrfToken($input['csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['error' => 'csrf']); exit;
    }
    if (($input['action'] ?? '') === 'save_additional') {
        $py         = (int)($input['pay_year']    ?? 0);
        $pm         = (int)($input['pay_month']   ?? 0);
        $workerName = trim($input['worker_name']  ?? '');
        if (!$py || !$pm || $workerName === '') {
            echo json_encode(['error' => 'invalid']); exit;
        }
        // 明細（複数行）をまとめて保存。旧形式（amount/reason 単体）も受け付ける
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            $items = [['amount' => $input['amount'] ?? 0, 'reason' => $input['reason'] ?? '']];
        }
        $clean = [];
        foreach ($items as $it) {
            $amt = max(0, (int)($it['amount'] ?? 0));
            $rsn = trim((string)($it['reason'] ?? ''));
            if ($amt === 0 && $rsn === '') continue;   // 空行は保存しない
            $clean[] = ['amount' => $amt, 'reason' => $rsn];
        }
        $db = getDB();
        try {
            // 該当スタッフの明細を入れ替える（既存を削除してから登録）
            $db->beginTransaction();
            $db->prepare("DELETE FROM salary_additional_payments
                WHERE company_id = ? AND pay_year = ? AND pay_month = ? AND worker_name = ?")
               ->execute([$cid, $py, $pm, $workerName]);
            if ($clean) {
                $ins = $db->prepare("INSERT INTO salary_additional_payments
                    (company_id, pay_year, pay_month, worker_name, amount, reason)
                    VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($clean as $it) {
                    $ins->execute([$cid, $py, $pm, $workerName, $it['amount'], $it['reason']]);
                }
            }
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error' => 'db']); exit;
        }
        echo json_encode(['ok' => true]); exit;
    }

    // 常勤案件売上(7割)の手入力上書き（clear=1 で自動計算に戻す）
    if (($input['action'] ?? '') === 'save_regular_override') {
        $py         = (int)($input['pay_year']   ?? 0);
        $pm         = (int)($input['pay_month']  ?? 0);
        $workerName = trim($input['worker_name'] ?? '');
        $clear      = !empty($input['clear']);
        $amount     = max(0, (int)($input['amount'] ?? 0));
        if (!$py || !$pm || $workerName === '') {
            echo json_encode(['error' => 'invalid']); exit;
        }
        $db = getDB();
        try {
            if ($clear) {
                // 保存済みの上書きを削除 → 次回から自動計算に戻る
                $db->prepare("DELETE FROM salary_regular_overrides
                    WHERE company_id = ? AND pay_year = ? AND pay_month = ? AND worker_name = ?")
                   ->execute([$cid, $py, $pm, $workerName]);
            } else {
                $db->prepare("INSERT INTO salary_regular_overrides
                    (company_id, pay_year, pay_month, worker_name, amount)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount)")
                   ->execute([$cid, $py, $pm, $workerName, $amount]);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => 'db']); exit;
        }
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
                       $s['regular_salary'], $s['additional'] ?? 0, $s['additional_reasons'] ?? ($s['additional_reason'] ?? ''),
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
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($s['additional_reasons'] ?? ($s['additional_reason'] ?? ''), ENT_XML1) . '</Data></Cell>';
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
