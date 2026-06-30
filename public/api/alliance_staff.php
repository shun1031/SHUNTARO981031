<?php
/**
 * アライアンス人員管理 API
 * GET ?q_company=&q_staff=
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { echo json_encode(['error' => 'Unauthorized']); exit; }

if (!isAdmin()) { echo json_encode(['error' => 'Forbidden']); exit; }

$db = getDB();
$qCompany = trim($_GET['q_company'] ?? '');
$qStaff   = trim($_GET['q_staff']   ?? '');

// アライアンス案件を全件取得（最新のend_dateが先に来るようSORT）
$sql = "SELECT
    sc.id,
    sc.case_type,
    sc.worker_name,
    sc.store_name,
    sc.start_date,
    sc.end_date,
    sc.alliance_id,
    al.alliance_name
FROM sales_cases sc
JOIN sales_alliances al ON sc.alliance_id = al.id
WHERE sc.company_id = :cid
    AND sc.worker_type = 'アライアンス'
    AND sc.alliance_id IS NOT NULL
    AND sc.worker_name IS NOT NULL
    AND TRIM(sc.worker_name) != ''
    AND sc.status != 'cancelled'
ORDER BY al.alliance_name COLLATE utf8mb4_unicode_ci, sc.worker_name, sc.end_date DESC, sc.start_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute([':cid' => $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');

// (alliance_name → worker_name) でグループ化
$grouped = [];
foreach ($rows as $row) {
    $company = $row['alliance_name'];
    $staff   = $row['worker_name'];

    if (!isset($grouped[$company])) {
        $grouped[$company] = [];
    }
    if (!isset($grouped[$company][$staff])) {
        // 初出レコードが最新（ORDER BY end_date DESC）
        $endDate  = $row['end_date']   ?? null;
        $startDate = $row['start_date'] ?? null;

        // 最終稼働日 = end_dateがあればend_date, なければstart_date
        $lastDate = $endDate ?: $startDate;

        $grouped[$company][$staff] = [
            'name'            => $staff,
            'last_store'      => $row['store_name'] ?? '',
            'last_work_date'  => $lastDate,
            'status'          => 'none',   // 後で判定
            '_cases'          => [],
        ];
    }

    // 全案件を保持して稼働状況を後で判定
    $grouped[$company][$staff]['_cases'][] = [
        'case_type'  => $row['case_type'],
        'start_date' => $row['start_date'],
        'end_date'   => $row['end_date'],
    ];
}

// 稼働状況を判定 & _cases を除去 & 検索フィルタ適用
$today = date('Y-m-d');
$companies = [];

foreach ($grouped as $companyName => $staffMap) {
    // 会社名フィルタ
    if ($qCompany !== '' && mb_stripos($companyName, $qCompany) === false) {
        continue;
    }

    $staffList = [];
    foreach ($staffMap as $staffName => $info) {
        // スタッフ名フィルタ
        if ($qStaff !== '' && mb_stripos($staffName, $qStaff) === false) {
            continue;
        }

        // 稼働状況判定: 今日がstart_date〜end_dateの範囲内か
        $status = 'none';
        foreach ($info['_cases'] as $c) {
            $s = $c['start_date'] ?? '';
            $e = $c['end_date']   ?? null;
            if ($s && $s <= $today && ($e === null || $e >= $today)) {
                // 稼働中
                $status = ($c['case_type'] === 'regular') ? 'regular' : 'event';
                break; // 最初に見つかった稼働中を優先
            }
        }

        unset($info['_cases']);
        $info['status'] = $status;

        $staffList[] = $info;
    }

    // スタッフが1人もいなければ会社ブロック自体をスキップ
    if (empty($staffList)) continue;

    // スタッフ: 最終稼働日が新しい順
    usort($staffList, function($a, $b) {
        return strcmp($b['last_work_date'] ?? '', $a['last_work_date'] ?? '');
    });

    $companies[] = [
        'name'  => $companyName,
        'staff' => $staffList,
    ];
}

// 会社名: 五十音順（すでにDBのCOLLATEで並んでいるが念のため）
usort($companies, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

echo json_encode([
    'companies'       => $companies,
    'total_companies' => count($companies),
    'total_staff'     => array_sum(array_map(fn($c) => count($c['staff']), $companies)),
], JSON_UNESCAPED_UNICODE);
