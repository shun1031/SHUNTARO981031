<?php
/**
 * 【一度だけ実行するスクリプト】2026年7月 イベント案件 データ投入
 *
 * スプレッドシートの「2026年7月稼働分」75件をイベント案件として登録します。
 * 金額は 日単価 × 稼働日数 でシステムが自動計算します（元データと全行一致を検証済み）。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$YEAR  = 2026;
$MONTH = 7;

// 列: 0取引先 1営業担当 2管理者 3採用者 4スタッフ区分 5外注先 6スタッフ名 7開始日 8終了日 9キャリア 10店舗名 11請求単価(日) 12支払単価(日) 13稼働日数
$DATA = [
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','藤吉太一','2026-07-02','2026-07-05','','アイモール三好',25000,22000,4],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','横田華愛','2026-07-02','2026-07-05','','アイモール三好',24000,20000,4],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','川合温','2026-07-03','2026-07-06','','なるぱーく',30000,25000,4],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','渡部大空','2026-07-03','2026-07-03','AM','ドーム前',30000,25000,1],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','渡部大空','2026-07-04','2026-07-05','','ララパーク伊勢',30000,25000,2],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','渡部大空','2026-07-06','2026-07-06','AM','ドーム前',30000,25000,1],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','藤吉太一','2026-07-08','2026-07-12','docomo','杏林堂小松',25000,22000,5],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','横田華愛','2026-07-08','2026-07-12','docomo','杏林堂小松',24000,20000,5],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','渡部大空','2026-07-10','2026-07-13','au','ドンキ豊田本店',30000,25000,4],
    ['Laxum','綾部航介','東郷光啓','該当者なし','アライアンス','LIFIX','栗田ひかり','2026-07-18','2026-07-20','SB','リーフウォーク稲沢',22000,19000,3],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','北岡空駆','2026-07-10','2026-07-10','au','イオン大安',30000,25000,1],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','北岡空駆','2026-07-11','2026-07-12','au','イオンタウン伊勢',35000,30000,2],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','北岡空駆','2026-07-13','2026-07-13','au','イオン大安',30000,25000,1],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','小島光流','2026-07-11','2026-07-12','ED','岡崎本店',25000,23000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','廣瀬空恩','2026-07-11','2026-07-12','ED','豊田本店',25000,23000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','河合隼人','2026-07-11','2026-07-12','ED','豊橋',18000,16000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','伊藤光星','2026-07-11','2026-07-12','ED','天白',18000,16000,2],
    ['VIN','竹内陽','竹内陽','該当者なし','個人外注','','牛島捷介','2026-07-11','2026-07-12','AM','常滑',29000,28000,2],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','渡邉晏','2026-07-11','2026-07-12','AM','常滑',20000,20000,2],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','恩田愛佳','2026-07-11','2026-07-12','AM','東浦',20000,20000,2],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','神山隼人','2026-07-11','2026-07-11','ED','豊田本店',18000,16000,1],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','藤吉太一','2026-07-17','2026-07-20','docomo','オークワ土岐',25000,22000,4],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','横田華愛','2026-07-17','2026-07-17','docomo','オークワ土岐',24000,20000,1],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','早川佳織','2026-07-11','2026-07-12','AM','常滑',25000,25000,2],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','横田華愛','2026-07-20','2026-07-20','docomo','オークワ土岐',24000,20000,1],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','川合温','2026-07-20','2026-07-20','AM','茶屋',25000,25000,1],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','廣瀬空恩','2026-07-18','2026-07-19','ED','日進',25000,23000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','河合隼人','2026-07-18','2026-07-20','ED','なるぱーく',23000,21000,3],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','伊藤光星','2026-07-18','2026-07-19','ED','東浦',18000,16000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','神山隼人','2026-07-18','2026-07-19','ED','長久手',18000,16000,2],
    ['Laxum','綾部航介','東郷光啓','該当者なし','アライアンス','LIFIX','加藤悠貴','2026-07-18','2026-07-20','SB','東刈谷',24000,21000,3],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','牛島捷介','2026-07-18','2026-07-20','AM','東浦',29000,28000,3],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','渡邉晏','2026-07-18','2026-07-18','SB','生桑',25000,25000,1],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','恩田愛佳','2026-07-18','2026-07-20','AM','茶屋',20000,20000,3],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','高田夢斗','2026-07-18','2026-07-19','docomo','オークワ土岐',24000,20000,2],
    ['l\'bis','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','田中菜緒美','2026-07-18','2026-07-20','SB','不明',25000,24000,3],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','岩田宗士','2026-07-18','2026-07-20','','アピア木曽川',28000,25000,3],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','安田佑希','2026-07-18','2026-07-20','SB','鈴鹿',28000,23000,3],
    ['オアシス','竹内陽','該当者なし','該当者なし','自社外注','','林航平','2026-07-18','2026-07-20','','モゾ',25000,0,3],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','杉本龍星','2026-07-18','2026-07-18','ED','豊田本店',16000,15000,1],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','杉本龍星','2026-07-19','2026-07-19','YMD','岡崎',16000,15000,1],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','安井健斗','2026-07-20','2026-07-20','ED','日進',25000,23000,1],
    ['VIN','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','川合温','2026-07-18','2026-07-18','SB','生桑',25000,25000,1],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','渡邉拓斗','2026-07-18','2026-07-19','SB','刈谷ハイウェイ',35000,30000,2],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','小木曽太一','2026-07-18','2026-07-20','SB','刈谷ハイウェイ',35000,30000,3],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','船橋天広','2026-07-18','2026-07-20','SB','刈谷ハイウェイ',35000,30000,3],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','立澤怜大','2026-07-18','2026-07-19','SB','刈谷ハイウェイ',35000,30000,2],
    ['グラスト','綾部航介','竹内陽','該当者なし','アライアンス','U-plus','藤吉太一','2026-07-23','2026-07-26','au','イベント',25000,23000,4],
    ['グラスト','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','横田華愛','2026-07-23','2026-07-26','au','イベント',24000,20000,4],
    ['センターフロー','名倉雅貴','該当者なし','該当者なし','アライアンス','Revive','伊藤龍哉','2026-07-20','2026-07-20','ED','東浦',18000,17000,1],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','杉本龍星','2026-07-25','2026-07-26','KS','みなと',16000,15000,2],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','大塚','濱島慎助','2026-07-25','2026-07-25','YMD','四日市',18000,16000,1],
    ['kunitoko asset','名倉雅貴','竹内陽','該当者なし','アライアンス','大塚','濱島慎助','2026-07-26','2026-07-26','JS','たじみ',18000,16000,1],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','廣瀬空恩','2026-07-25','2026-07-26','ED','日進竹の山',25000,23000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','河合隼人','2026-07-25','2026-07-26','ED','日進竹の山',23000,21000,2],
    ['センターフロー','名倉雅貴','竹内陽','該当者なし','アライアンス','近藤SEED','伊藤光星','2026-07-25','2026-07-26','ED','豊橋ミラまち',18000,16000,2],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','川合温','2026-07-24','2026-07-27','au','ららぽーとみなと',25000,20000,4],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','北岡空駆','2026-07-24','2026-07-27','au','イオン日永',30000,25000,4],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','渡部大空','2026-07-18','2026-07-21','au','イオンタウン四日市泊',30000,25000,4],
    ['LIBERTY','名倉雅貴','竹内陽','該当者なし','アライアンス','オアシス','北岡空駆','2026-07-18','2026-07-20','AM','津南',30000,25000,3],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','高橋翔','2026-07-25','2026-07-26','AP','岡崎北',35000,30000,2],
    ['ラネット','竹内陽','竹内陽','該当者なし','アライアンス','渡邊拓斗','梅野篤輝','2026-07-25','2026-07-26','AP','岡崎北',35000,30000,2],
    ['フリーダム','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','林航平','2026-07-25','2026-07-26','AM','ドーム前',33000,0,2],
    ['フリーダム','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','岩田宗士','2026-07-25','2026-07-26','AM','ドーム前',33000,28000,2],
    ['フリーダム','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','早川佳織','2026-07-25','2026-07-26','AM','ドーム前',33000,25000,2],
    ['フリーダム','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','青川萌夏','2026-07-25','2026-07-26','AM','ドーム前',25000,20000,2],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','牛島捷介','2026-07-25','2026-07-26','AM','豊橋南',30000,25000,2],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','渡邉晏','2026-07-25','2026-07-26','AM','豊橋南',27000,22000,2],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','安田佑希','2026-07-25','2026-07-26','AM','四日市北',28000,23000,2],
    ['コンフィアンス','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','小川日向','2026-07-25','2026-07-26','AM','四日市北',22000,19000,2],
    ['l\'bis','竹内陽','竹内陽','該当者なし','アライアンス','オアシス','田中菜緒美','2026-07-25','2026-07-26','AM','和歌山',25000,24000,2],
    ['LANGIS','竹内陽','該当者なし','該当者なし','正社員','','近藤航','2026-07-04','2026-07-05','AM','モレラ岐阜',23000,0,2],
    ['LANGIS','竹内陽','該当者なし','該当者なし','正社員','','近藤航','2026-07-11','2026-07-12','AM','東員',23000,0,2],
    ['LANGIS','竹内陽','該当者なし','該当者なし','正社員','','近藤航','2026-07-18','2026-07-19','AM','四日市北',23000,0,2],
    ['LANGIS','竹内陽','該当者なし','該当者なし','正社員','','近藤航','2026-07-25','2026-07-26','AM','東員',23000,0,2],
];

// ── 既に当月に登録されているイベント案件（二重登録チェック用: スタッフ名+開始日） ──
$exStmt = $db->prepare("SELECT sc.worker_name, sc.start_date, sc.store_name, cl.client_name
    FROM sales_cases sc LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id=? AND sc.case_year=? AND sc.case_month=? AND sc.case_type='event' AND sc.status!='cancelled'
    ORDER BY sc.id");
$exStmt->execute([$cid, $YEAR, $MONTH]);
$existing = $exStmt->fetchAll();
$existingKeys = [];
foreach ($existing as $e) {
    $existingKeys[trim($e['worker_name']) . '|' . substr((string)$e['start_date'], 0, 10)] = true;
}

// ── マスタ ──
$clientMap = [];
foreach (getSalesClients($cid) as $c) { $clientMap[trim($c['client_name'])] = (int)$c['id']; }
$allianceMap = [];
foreach (getSalesAlliances($cid) as $a) { $allianceMap[trim($a['alliance_name'])] = (int)$a['id']; }

$done = false; $created = 0; $skipped = []; $failed = []; $newClients = []; $newAlliances = [];

// ── 登録実行 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    foreach ($DATA as $d) {
        [$clientName, $salesRep, $manager, $recruiter, $workerType, $allianceName,
         $workerName, $startDate, $endDate, $carrier, $storeName, $priceIn, $priceOut, $days] = $d;
        $dupKey = $workerName . '|' . $startDate;
        if (!$includeDup && isset($existingKeys[$dupKey])) {
            $skipped[] = $workerName . '（' . $startDate . '）';
            continue;
        }
        try {
            $clientId = $clientMap[$clientName] ?? null;
            if (!$clientId && $clientName !== '') {
                $clientId = createSalesClient($cid, ['client_name' => $clientName]);
                $clientMap[$clientName] = $clientId;
                $newClients[] = $clientName;
            }
            $allianceId = null;
            if ($workerType === 'アライアンス' && $allianceName !== '') {
                $allianceId = $allianceMap[$allianceName] ?? null;
                if (!$allianceId) {
                    $allianceId = createSalesAlliance($cid, ['alliance_name' => $allianceName]);
                    $allianceMap[$allianceName] = $allianceId;
                    $newAlliances[] = $allianceName;
                }
            }
            // 金額は 日単価×稼働日数 でシステムが自動計算（gross_profit_directは使わない）
            createSalesCase($cid, [
                'case_type'      => 'event',
                'client_id'      => $clientId,
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'sales_rep'      => $salesRep,
                'manager'        => $manager,
                'recruiter'      => $recruiter,
                'worker_type'    => $workerType,
                'worker_name'    => $workerName,
                'alliance_id'    => $allianceId ?: null,
                'carrier'        => $carrier,
                'trade_name'     => '',
                'area_id'        => null,
                'store_name'     => $storeName,
                'unit_price_in'  => $priceIn,
                'unit_price_out' => $priceOut,
                'days_worked'    => $days,
                'status'         => 'confirmed',
                'note'           => '',
                'case_division'  => null,
            ]);
            $created++;
        } catch (Throwable $e) {
            $failed[] = $workerName . '（' . $startDate . '） — ' . $e->getMessage();
        }
    }
    $done = true;
}

// ── 確認用の集計 ──
$dupRows = 0; $totalIn = 0; $totalOut = 0;
foreach ($DATA as $d) {
    if (isset($existingKeys[$d[6] . '|' . $d[7]])) $dupRows++;
    $totalIn  += $d[11] * $d[13];
    $totalOut += $d[12] * $d[13];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $YEAR ?>年<?= $MONTH ?>月 イベント案件 データ投入</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1180px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.74rem; white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-database-add me-2"></i><?= $YEAR ?>年<?= $MONTH ?>月 イベント案件 データ投入</h4>

<?php if ($done): ?>

  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $created ?>件を登録しました</div>
    <?php if ($newClients): ?><div class="small mt-1">取引先を新規作成: <?= h(implode('、', array_unique($newClients))) ?></div><?php endif; ?>
    <?php if ($newAlliances): ?><div class="small">外注先を新規作成: <?= h(implode('、', array_unique($newAlliances))) ?></div><?php endif; ?>
  </div>

  <?php if ($skipped): ?>
  <div class="alert alert-secondary small">
    <strong><?= count($skipped) ?>件はスキップしました</strong>（同じスタッフ名・開始日の案件が既に<?= $MONTH ?>月に登録されていたため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="alert alert-danger small">
    <strong><?= count($failed) ?>件は登録できませんでした</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <a href="<?= BASE_PATH ?>/public/sales_events.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-primary">
    イベント案件（<?= $MONTH ?>月）を確認する
  </a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_event_202607.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
  </div>

<?php else: ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">
      いま <?= $YEAR ?>年<?= $MONTH ?>月 に登録されているイベント案件：
      <span class="badge bg-secondary"><?= count($existing) ?>件</span>
    </div>
    <?php if ($existing): ?>
      <div class="small text-muted" style="line-height:2">
        <?php foreach ($existing as $e): ?>
          <span class="badge bg-light text-dark border me-1"><?= h($e['worker_name']) ?>（<?= h(substr((string)$e['start_date'], 0, 10)) ?>）</span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="small text-muted">まだ登録されていません。</div>
    <?php endif; ?>
  </div></div>

  <?php if ($dupRows): ?>
  <div class="alert alert-warning">
    <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>
      これから登録する<?= count($DATA) ?>件のうち<?= $dupRows ?>件は、同じスタッフ名・開始日の案件が既に<?= $MONTH ?>月に登録されています
    </div>
    <div class="small mt-1">
      二重登録を防ぐため、既定では<strong>その<?= $dupRows ?>件をスキップ</strong>し、
      <strong><?= count($DATA) - $dupRows ?>件</strong>だけ登録します（下の表で赤いバッジが付いた行）。
    </div>
  </div>
  <?php endif; ?>

  <div class="alert alert-info">
    <div class="fw-semibold">登録する内容</div>
    <div class="small mt-1" style="line-height:1.9">
      ・<?= count($DATA) ?>件を <strong><?= $YEAR ?>年<?= $MONTH ?>月</strong> のイベント案件として登録（ステータス=確定）<br>
      ・売上合計 <strong><?= number_format($totalIn) ?>円</strong> ／ 原価合計 <strong><?= number_format($totalOut) ?>円</strong> ／ 粗利合計 <strong><?= number_format($totalIn - $totalOut) ?>円</strong>（日単価×稼働日数で自動計算。元データと全行一致を確認済み）<br>
      ・スタッフ区分の「外注」は<strong>個人外注</strong>として登録<br>
      ・屋号・区分（1次／2次以降）は元データに列がないため<strong>空欄</strong><br>
      ・キャリアが空欄の行（6件）はそのまま<strong>空欄</strong>で登録
    </div>
  </div>

  <div class="table-responsive mb-3" style="max-height:56vh">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr>
          <th>#</th><th>取引先</th><th>営業</th><th>管理者</th><th>採用者</th><th>区分</th><th>外注先</th>
          <th>スタッフ</th><th>開始日</th><th>終了日</th><th>ｷｬﾘｱ</th><th>店舗</th>
          <th class="text-end">単価(日)</th><th class="text-end">支払(日)</th><th class="text-end">日数</th>
          <th class="text-end">売上</th><th class="text-end">原価</th><th class="text-end">粗利</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($DATA as $i => $d): $isDup = isset($existingKeys[$d[6] . '|' . $d[7]]);
            $rev = $d[11] * $d[13]; $cost = $d[12] * $d[13]; ?>
        <tr class="<?= $isDup ? 'table-warning' : '' ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= h($d[0]) ?><?= isset($clientMap[$d[0]]) ? '' : ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' ?></td>
          <td><?= h($d[1]) ?></td>
          <td><?= h($d[2]) ?></td>
          <td><?= h($d[3]) ?></td>
          <td><?= h($d[4]) ?></td>
          <td><?= h($d[5]) ?><?= ($d[4] === 'アライアンス' && $d[5] !== '' && !isset($allianceMap[$d[5]])) ? ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' : '' ?></td>
          <td class="fw-medium"><?= h($d[6]) ?><?= $isDup ? ' <span class="badge bg-danger" style="font-size:.6rem">既存</span>' : '' ?></td>
          <td><?= h(substr($d[7], 5)) ?></td>
          <td><?= h(substr($d[8], 5)) ?></td>
          <td><?= h($d[9]) ?: '<span class="text-muted">-</span>' ?></td>
          <td><?= h($d[10]) ?></td>
          <td class="text-end"><?= number_format($d[11]) ?></td>
          <td class="text-end"><?= number_format($d[12]) ?></td>
          <td class="text-end"><?= (int)$d[13] ?></td>
          <td class="text-end"><?= number_format($rev) ?></td>
          <td class="text-end"><?= number_format($cost) ?></td>
          <td class="text-end <?= ($rev - $cost) < 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format($rev - $cost) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($dupRows): ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="include_dup" value="1" id="incDup">
      <label class="form-check-label small" for="incDup">
        同じスタッフ名・開始日が既にある<?= $dupRows ?>件も登録する（二重登録になります。通常はチェックしないでください）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sales_events.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
