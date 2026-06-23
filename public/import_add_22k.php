<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ'); }

$db  = getDB();
$cid = getCompanyId();

// センターフロー クライアントID取得
$s = $db->prepare('SELECT id FROM sales_clients WHERE company_id=? AND client_name=? LIMIT 1');
$s->execute([$cid, 'センターフロー']);
$client_id = (int)$s->fetchColumn();

// オアシス アライアンスID取得
$s = $db->prepare('SELECT id FROM sales_alliances WHERE company_id=? AND alliance_name=? LIMIT 1');
$s->execute([$cid, 'オアシス']);
$alliance_id = (int)$s->fetchColumn();

// 不足している1件を追加
$db->prepare("INSERT INTO sales_cases
    (company_id, case_type, case_year, case_month,
     client_id, sales_rep, manager, recruiter,
     worker_type, alliance_id, worker_name,
     start_date, end_date, unit_price_in, unit_price_out, days_worked,
     revenue, cost, gross_profit, margin, status, carrier, store_name)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
->execute([
    $cid,'event',2026,6,
    $client_id,'名倉雅貴','竹内陽','',
    'アライアンス',$alliance_id,'北岡空駆',
    '2026-06-16','2026-06-16',22000,20000,1,
    22000,20000,2000,round(2000/22000,4),'confirmed','ED','安城'
]);

echo '✓ センターフロー 北岡空駆 2026/06/16 ED安城 22,000円 を追加しました<br>';
echo '<a href="' . BASE_PATH . '/public/sales_events.php?year=2026&month=6">イベント案件6月へ</a>';
echo '<p style="color:red">実行後このファイルを削除してください</p>';
