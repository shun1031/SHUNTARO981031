<?php
/**
 * 【一度だけ実行するスクリプト】2026年7月 常勤案件 データ投入
 *
 * スプレッドシートの「2026年7月稼働者分」を常勤案件として登録します。
 * 稼働開始が2026/08の3件（渡邊咲樹・澤崎竣・サトウレイジ）は7月分ではないため含めていません。
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

// 列: 0取引先 1営業担当 2管理者 3採用者 4スタッフ区分 5外注先 6スタッフ名 7備考 8稼働日数 9キャリア 10店舗名 11請求単価(月) 12支払単価(月)
$DATA = [
    ['プレイス','竹内陽','','','自社外注','','山根脩平','稼働開始 2025/12',21,'ED','安城',450000,300000],
    ['プレイス','竹内陽','','','自社外注','','後藤太一','稼働開始 2025/12',21,'ED','安城',450000,300000],
    ['プレイス','竹内陽','','','自社外注','','綾部航介','稼働開始 2025/12',21,'SB','美濃加茂',470000,400000],
    ['プレイミー','名倉雅貴','名倉雅貴','','自社外注','','日置航暉','稼働開始 2025/12',21,'SB','半田亀崎',420000,336000],
    ['LANGIS','竹内陽','','','個人外注','','鈴木拓弥','稼働開始 2025/12',21,'SB','平針',420000,399000],
    ['LANGIS','竹内陽','','鈴木真矢','個人外注','','飛田拓斗','稼働開始 2025/12',21,'SB','AT各務原鵜沼',409500,315000],
    ['ラネット','竹内陽','','','アライアンス','小林幹汰','長岡拓也','稼働開始 2025/12',21,'SB','AM豊川',525000,483000],
    ['LLC','綾部航介','綾部航介','','アライアンス','U-plus','サトウレイジ','稼働開始 2026/01',21,'ED','安城',462000,378000],
    ['Pachira','綾部航介','綾部航介','綾部航介','アライアンス','U-plus','鬼頭優輔','稼働開始 2026/02',21,'ED','弥富',388500,357000],
    ['ラネット','竹内陽','竹内陽','','アライアンス','ASB','大石託実','稼働開始 2025/12',21,'SB','AM豊川',525000,420000],
    ['ラネット','竹内陽','','近藤航','自社外注','','清水一平','稼働開始 2026/01',21,'SB','碧南',514500,300000],
    ['プレイス','竹内陽','','鈴木真矢','自社外注','','西澤克輝','稼働開始 2026/01',21,'ED','安城',450000,300000],
    ['ライフフレンド','竹内陽','竹内陽','','アライアンス','onetale','青木大輝','稼働開始 2026/01',1,'KS','岡崎大樹寺',100000,0],
    ['プレイス','竹内陽','該当者なし','該当者なし','正社員','','佐藤思杰','稼働開始 2026/04',21,'SB','安城',470000,260000],
    ['プレイス','竹内陽','該当者なし','佐藤思杰','個人外注','','佐藤悠太','稼働開始 2026/04',21,'ED','安城',450000,252000],
    ['LANGIS','竹内陽','該当者なし','竹内陽','個人外注','','倉地樂','稼働開始 2026/04',21,'SB','イーアス春日井',546000,409500],
    ['LANGIS','竹内陽','該当者なし','竹内陽','個人外注','','竹内丈治','稼働開始 2026/04',21,'SB','カメリアガーデン幸田',504000,378000],
    ['LANGIS','竹内陽','該当者なし','竹内陽','個人外注','','小栗瑞貴','稼働開始 2026/04',21,'SB','鈴鹿',483000,362250],
    ['LANGIS','竹内陽','綾部航介','該当者なし','アライアンス','Pachira','柴田一心','稼働開始 2026/04',21,'SB','アイモール三好',462000,441000],
    ['LANGIS','竹内陽','該当者なし','近藤航','自社外注','','丹後心来','稼働開始 2026/04',21,'SB','野並',441000,300000],
    ['クラウドエージェント','竹内陽','該当者なし','該当者なし','個人外注','','安山祐亮','稼働開始 2026/04',21,'SB','ららぽーと安城',441000,336000],
    ['クラウドエージェント','竹内陽','該当者なし','該当者なし','個人外注','','小林友裕','稼働開始 2026/04',21,'SB','共和',441000,350000],
    ['waplus','綾部航介','綾部航介','該当者なし','アライアンス','東峰グループ','安樂斐悠馬','稼働開始 2026/04',21,'SB','一宮妙興寺',441000,378000],
    ['プレイス','竹内陽','該当者なし','該当者なし','アライアンス','T-Group','石谷悠真','稼働開始 2026/04',21,'SB','多治見南',470000,399000],
    ['プレイス','竹内陽','該当者なし','該当者なし','個人外注','','田中敦之','稼働開始 2026/04',21,'SB','碧南',470000,352500],
    ['プレイス','竹内陽','該当者なし','該当者なし','自社外注','','山内文月','稼働開始 2026/04',21,'ED','安城',450000,336000],
    ['プレイス','竹内陽','該当者なし','該当者なし','自社外注','','押野俊太郎','稼働開始 2026/05',21,'SB','名古屋サンロード',470000,290000],
    ['プレイス','竹内陽','綾部航介','該当者なし','アライアンス','U-plus','長井優斗','稼働開始 2026/04',21,'SB','TG',492000,420000],
    ['プレイス','竹内陽','該当者なし','該当者なし','自社外注','','東郷光啓','稼働開始 2026/05',21,'SB','GA知立',470000,290000],
    ['プレイス','竹内陽','該当者なし','該当者なし','自社外注','','竹内陽','稼働開始 2026/05',21,'SB','知立',470000,500000],
    ['LANGIS','竹内陽','綾部航介','該当者なし','アライアンス','合同会社ANTA','平手達也','稼働開始 2026/06',21,'SB','四軒家',441000,378000],
    ['プレイス','竹内陽','竹内陽','該当者なし','アライアンス','LANGIS','板倉久美子','稼働開始 2026/06',21,'SB','安城住吉',470000,441000],
    ['プレイス','竹内陽','該当者なし','該当者なし','自社外注','','名倉雅貴','稼働開始 2026/06',21,'SB','TG',492000,344400],
    ['LANGIS','竹内陽','綾部航介','該当者なし','個人外注','','堀恭彰','稼働開始 2026/07',21,'SB','バロー長良',399000,294000],
    ['プレイス','竹内陽','綾部航介','該当者なし','アライアンス','U-plus','加藤洋亮','稼働開始 2026/07',21,'SB','岐阜芥見',470000,378000],
    ['ラクサム','綾部航介','綾部航介','該当者なし','アライアンス','U-plus','菊池励','稼働開始 2026/07',21,'ED','au尾張旭',378000,357000],
    ['TIR','綾部航介','名倉雅貴','該当者なし','アライアンス','LinocaCreation','松井健悟','稼働開始 2026/07',21,'AT','平和堂高富店',340000,315000],
    ['プレイス','竹内陽','綾部航介','該当者なし','アライアンス','ASXEED','増岡あかり','稼働開始 2026/07',21,'ED','安城',450000,378000],
];

// ── 既に当月に登録されている常勤案件（二重登録チェック用） ──
$exStmt = $db->prepare("SELECT sc.worker_name, cl.client_name
    FROM sales_cases sc LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id=? AND sc.case_year=? AND sc.case_month=? AND sc.case_type='regular' AND sc.status!='cancelled'
    ORDER BY sc.id");
$exStmt->execute([$cid, $YEAR, $MONTH]);
$existing = $exStmt->fetchAll();
$existingNames = [];
foreach ($existing as $e) { $existingNames[trim($e['worker_name'])] = true; }

// ── マスタ ──
$clientMap = [];
foreach (getSalesClients($cid) as $c) { $clientMap[trim($c['client_name'])] = (int)$c['id']; }
$allianceMap = [];
foreach (getSalesAlliances($cid) as $a) { $allianceMap[trim($a['alliance_name'])] = (int)$a['id']; }

$done = false; $created = 0; $skipped = []; $failed = []; $newClients = []; $newAlliances = [];

// ── 登録実行 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    $startDate  = sprintf('%04d-%02d-01', $YEAR, $MONTH);
    foreach ($DATA as $d) {
        [$clientName, $salesRep, $manager, $recruiter, $workerType, $allianceName,
         $workerName, $note, $days, $carrier, $storeName, $priceIn, $priceOut] = $d;
        if (!$includeDup && isset($existingNames[$workerName])) {
            $skipped[] = $workerName;
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
            createSalesCase($cid, [
                'case_type'           => 'regular',
                'client_id'           => $clientId,
                'start_date'          => $startDate,
                'end_date'            => '',
                'sales_rep'           => $salesRep,
                'manager'             => $manager,
                'recruiter'           => $recruiter,
                'worker_type'         => $workerType,
                'worker_name'         => $workerName,
                'alliance_id'         => $allianceId ?: null,
                'carrier'             => $carrier,
                'trade_name'          => '',
                'area_id'             => null,
                'store_name'          => $storeName,
                'unit_price_in'       => $priceIn,
                'unit_price_out'      => $priceOut,
                'days_worked'         => $days,
                'status'              => 'confirmed',
                'note'                => $note,
                'case_division'       => null,
                'gross_profit_direct' => $priceIn - $priceOut,
            ]);
            $created++;
        } catch (Throwable $e) {
            $failed[] = $workerName . ' — ' . $e->getMessage();
        }
    }
    $done = true;
}

// ── 確認用の集計 ──
$dupRows = 0; $totalIn = 0; $totalOut = 0;
foreach ($DATA as $d) {
    if (isset($existingNames[$d[6]])) $dupRows++;
    $totalIn  += $d[11];
    $totalOut += $d[12];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $YEAR ?>年<?= $MONTH ?>月 常勤案件 データ投入</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1140px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.76rem; white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-database-add me-2"></i><?= $YEAR ?>年<?= $MONTH ?>月 常勤案件 データ投入</h4>

<?php if ($done): ?>

  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $created ?>件を登録しました</div>
    <?php if ($newClients): ?><div class="small mt-1">取引先を新規作成: <?= h(implode('、', array_unique($newClients))) ?></div><?php endif; ?>
    <?php if ($newAlliances): ?><div class="small">外注先を新規作成: <?= h(implode('、', array_unique($newAlliances))) ?></div><?php endif; ?>
  </div>

  <?php if ($skipped): ?>
  <div class="alert alert-secondary small">
    <strong><?= count($skipped) ?>件はスキップしました</strong>（同じスタッフ名が既に<?= $MONTH ?>月に登録されていたため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="alert alert-danger small">
    <strong><?= count($failed) ?>件は登録できませんでした</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <a href="<?= BASE_PATH ?>/public/sales_regular.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-primary">
    常勤案件（<?= $MONTH ?>月）を確認する
  </a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_regular_202607.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
  </div>

<?php else: ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">
      いま <?= $YEAR ?>年<?= $MONTH ?>月 に登録されている常勤案件：
      <span class="badge bg-secondary"><?= count($existing) ?>件</span>
    </div>
    <?php if ($existing): ?>
      <div class="small text-muted" style="line-height:2">
        <?php foreach ($existing as $e): ?>
          <span class="badge bg-light text-dark border me-1"><?= h($e['worker_name']) ?><?= $e['client_name'] ? '／' . h($e['client_name']) : '' ?></span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="small text-muted">まだ登録されていません。</div>
    <?php endif; ?>
  </div></div>

  <?php if ($dupRows): ?>
  <div class="alert alert-warning">
    <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>
      これから登録する<?= count($DATA) ?>件のうち<?= $dupRows ?>件は、同じスタッフ名が既に<?= $MONTH ?>月に登録されています
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
      ・<?= count($DATA) ?>件を <strong><?= $YEAR ?>年<?= $MONTH ?>月</strong> の常勤案件として登録（開始月=<?= $YEAR ?>/<?= sprintf('%02d', $MONTH) ?>、ステータス=確定）<br>
      ・売上合計 <strong><?= number_format($totalIn) ?>円</strong> ／ 原価合計 <strong><?= number_format($totalOut) ?>円</strong> ／ 粗利合計 <strong><?= number_format($totalIn - $totalOut) ?>円</strong><br>
      ・スタッフ区分の「外注」は<strong>個人外注</strong>として登録<br>
      ・屋号・区分（1次／2次以降）・終了月は元データに列がないため<strong>空欄</strong><br>
      ・稼働開始日は<strong>備考</strong>に記録（例: 稼働開始 2025/12）<br>
      ・稼働開始が2026/08の3件（渡邊咲樹・澤崎竣・サトウレイジ）は<strong>含めていません</strong>
    </div>
  </div>

  <div class="table-responsive mb-3" style="max-height:58vh">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr>
          <th>#</th><th>取引先</th><th>営業</th><th>管理者</th><th>採用者</th><th>区分</th><th>外注先</th>
          <th>スタッフ</th><th>ｷｬﾘｱ</th><th>店舗</th><th class="text-end">稼働</th>
          <th class="text-end">請求単価(月)</th><th class="text-end">支払単価(月)</th><th class="text-end">粗利</th><th>備考</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($DATA as $i => $d): $isDup = isset($existingNames[$d[6]]); ?>
        <tr class="<?= $isDup ? 'table-warning' : '' ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= h($d[0]) ?><?= isset($clientMap[$d[0]]) ? '' : ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' ?></td>
          <td><?= h($d[1]) ?></td>
          <td><?= h($d[2]) ?></td>
          <td><?= h($d[3]) ?></td>
          <td><?= h($d[4]) ?></td>
          <td><?= h($d[5]) ?></td>
          <td class="fw-medium"><?= h($d[6]) ?><?= $isDup ? ' <span class="badge bg-danger" style="font-size:.6rem">既存</span>' : '' ?></td>
          <td><?= h($d[9]) ?></td>
          <td><?= h($d[10]) ?></td>
          <td class="text-end"><?= (int)$d[8] ?></td>
          <td class="text-end"><?= number_format($d[11]) ?></td>
          <td class="text-end"><?= number_format($d[12]) ?></td>
          <td class="text-end <?= ($d[11] - $d[12]) < 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format($d[11] - $d[12]) ?></td>
          <td class="text-muted" style="font-size:.7rem"><?= h($d[7]) ?></td>
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
        同じスタッフ名が既にある<?= $dupRows ?>件も登録する（二重登録になります。通常はチェックしないでください）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sales_regular.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
