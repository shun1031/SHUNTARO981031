<?php
/**
 * 【一度だけ実行するスクリプト】2026年3月 常勤案件 データ投入
 *
 * スプレッドシートの「2026年3月稼働分」45件を常勤案件として登録します。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 *
 * 元データの読み替え（ユーザー確認済み）:
 *   - 金額は「月の合計額」を請求単価(月)/支払単価(月)に入れる。
 *     常勤案件は単価に稼働日数を掛けないため、これで売上・原価・粗利が元データと一致する。
 *     （元データの単価列は行によって日額と月額が混在しているため使わない）
 *   - キャリアの選択肢(docomo/au/SB/楽天/CATV/コミュファ)に有るものはキャリア、
 *     無いもの（ED/YMD/JS/AM/KS）は屋号へ。
 *   - スタッフ区分「外注」は「個人外注」として登録。
 *   - 区分は全件「2次以降」。そのため予算区分は無し（1次のときだけ必要な項目のため）。
 *   - 今月は取引先が「光AD」の行が無いため、光ADチェックは全件OFF。
 *   - 元データ8列目の日付は稼働開始日。開始月には使わず（使うと12月の案件になってしまう）、
 *     備考に「稼働開始 YYYY/MM」として残す。開始月は全件 2026/03。
 *   - 管理者・採用者の「該当者なし」は空欄にして直営業扱いにする（フォームの選択肢にも無いため）。
 *   - 元データに契約終了日の列は無い。終了月は空欄。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$YEAR  = 2026;
$MONTH = 3;

// 列: 0取引先 1営業担当 2管理者 3採用者 4スタッフ区分 5外注先 6スタッフ名
//     7稼働日数 8キャリア 9屋号 10店舗名 11請求単価(月) 12支払単価(月) 13光AD 14備考
$DATA = [
    ['プレイス','竹内陽','','','自社外注','','山根脩平',21,'','ED','安城',450000,300000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','','','正社員','','近藤航',21,'SB','','安城',470000,300000,0,'稼働開始 2026/01'],
    ['プレイス','竹内陽','','','自社外注','','押野俊太郎',21,'','YMD','名古屋本店',450000,300000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','','','自社外注','','後藤太一',21,'','ED','安城',450000,300000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','','','自社外注','','綾部航介',21,'SB','','美濃加茂',470000,400000,0,'稼働開始 2025/12'],
    ['プレイミー','名倉雅貴','名倉雅貴','','自社外注','','日置航暉',21,'SB','','半田亀崎',420000,336000,0,'稼働開始 2025/12'],
    ['humanR','綾部航介','綾部航介','','アライアンス','NextAssist','浅井貴広',21,'','YMD','北方',420000,399000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','','','自社外注','','山内文月',21,'SB','','多治見南',470000,300000,0,'稼働開始 2025/12'],
    ['ラネット','竹内陽','竹内陽','','自社外注','','東郷光啓',21,'SB','','知立',630000,400000,0,'稼働開始 2025/12'],
    ['ラネット','竹内陽','竹内陽','','正社員','','倉地樂',21,'SB','','GA知立',630000,450000,0,'稼働開始 2025/12'],
    ['LANGIS','竹内陽','','','個人外注','','鈴木拓弥',21,'SB','','平針',420000,399000,0,'稼働開始 2025/12'],
    ['クラウドエージェント','竹内陽','東郷光啓','','アライアンス','ネクストプレイス','石川海里',21,'SB','','長久手',441000,420000,0,'稼働開始 2025/12'],
    ['ラネット','竹内陽','','','自社外注','','竹内陽',10,'SB','','中津川',400000,500000,0,'稼働開始 2025/12'],
    ['LANGIS','竹内陽','','','個人外注','','飛田拓斗',21,'SB','','AT各務原鵜沼',409500,315000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','綾部航介','','アライアンス','ASXEED','増岡あかり',21,'','ED','安城',450000,420000,0,'稼働開始 2025/12'],
    ['ラネット','竹内陽','','','アライアンス','小林幹汰','長岡拓也',21,'SB','','AM豊川',525000,483000,0,'稼働開始 2025/12'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','渡邉拓斗',20,'SB','','GA知立',500000,435000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','竹内陽','','アライアンス','T-Group','石谷悠真',21,'','ED','安城',450000,399000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','綾部航介','','アライアンス','東峰グループ','長井優斗',21,'SB','','トップガン',450000,399000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','綾部航介','','自社外注','','堀恭彰',21,'SB','','芥見',470000,315000,0,'稼働開始 2026/01'],
    ['LLC','綾部航介','綾部航介','','アライアンス','U-plus','サトウレイジ',21,'','ED','安城',462000,378000,0,'稼働開始 2026/01'],
    ['ラネット','竹内陽','','','自社外注','','田中敦之',21,'SB','','碧南',514500,330000,0,'稼働開始 2025/12'],
    ['プレイス','竹内陽','','','個人外注','','安山祐亮',21,'SB','','知立',470000,336000,0,'稼働開始 2026/01'],
    ['プレイス','竹内陽','綾部航介','綾部航介','アライアンス','U-plus','加藤洋亮',21,'','JS','小牧',450000,378000,0,'稼働開始 2026/02'],
    ['Pachira','綾部航介','綾部航介','綾部航介','アライアンス','U-plus','鬼頭優輔',21,'','ED','弥富',388500,357000,0,'稼働開始 2026/02'],
    ['テレニシ','竹内陽','','近藤航','自社外注','','丹後心来',21,'SB','','岐南',525000,300000,0,'稼働開始 2026/02'],
    ['プレイス','竹内陽','竹内陽','','個人外注','','石川巽',21,'SB','','トップガン',525000,500000,0,'稼働開始 2026/01'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','ASB','大石託実',21,'SB','','AM豊川',525000,420000,0,'稼働開始 2025/12'],
    ['テレニシ','竹内陽','','綾部航介','個人外注','','田中哲史',21,'SB','','モレラ岐阜',525000,336000,0,'稼働開始 2026/02'],
    ['プレイス','竹内陽','','','自社外注','','竹内丈治',21,'SB','','碧南',470000,330000,0,'稼働開始 2026/01'],
    ['ラネット','竹内陽','','近藤航','自社外注','','清水一平',21,'SB','','碧南',514500,300000,0,'稼働開始 2026/01'],
    ['プレイス','竹内陽','竹内陽','','アライアンス','T-Group','宮島航希',21,'','ED','安城',450000,409500,0,'稼働開始 2026/01'],
    ['プレイス','竹内陽','','','自社外注','','西澤克輝',21,'','ED','安城',450000,300000,0,'稼働開始 2026/01'],
    ['プレイス','竹内陽','綾部航介','','アライアンス','ASXEED','山口大士',21,'SB','','安城住吉',470000,450000,0,'稼働開始 2026/03'],
    ['プレイス','竹内陽','綾部航介','','アライアンス','Pachira','柴田一心',21,'SB','','GA知立',470000,420000,0,'稼働開始 2026/03'],
    ['プレイス','竹内陽','竹内陽','','正社員','','小栗瑞貴',21,'SB','','モレラ岐阜',470000,300000,0,'稼働開始 2026/03'],
    ['プレイス','竹内陽','竹内陽','','正社員','','佐藤思杰',21,'SB','','岐南',470000,260000,0,'稼働開始 2026/03'],
    ['テレニシ','竹内陽','竹内陽','','個人外注','','小林友裕',21,'SB','','モレラ岐阜',525000,350000,0,'稼働開始 2026/03'],
    ['テレニシ','竹内陽','綾部航介','','アライアンス','東峰グループ','安樂斐悠馬',21,'SB','','岐南',609000,378000,0,'稼働開始 2026/03'],
    ['ライフフレンド','竹内陽','竹内陽','','アライアンス','onetale','青木大輝',1,'','KS','岡崎大樹寺',100000,0,0,'稼働開始 2026/01'],
    ['Pachira','綾部航介','綾部航介','','アライアンス','LaXum','澤口',20,'','ED','千種',320000,300000,0,'稼働開始 2026/03'],
    ['ラネット','竹内陽','','','自社外注','','ADラダー',21,'SB','','光',800000,0,0,'稼働開始 2026/02'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小木曽太一',25,'SB','','AM豊川',625000,537500,0,'稼働開始 2026/03'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小木曽太一土日',2,'SB','','AM豊川',70000,60000,0,'稼働開始 2026/03'],
    ['プレイス','竹内陽','','','自社外注','','名倉雅貴',21,'SB','','モレラ岐阜',470000,300000,0,'稼働開始 2026/03'],
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

// ── 担当者名の名簿チェック（営業マンIDと突き合わせて表記ゆれを洗い出す） ──
// 営業担当・管理者は「社員一覧で営業担当にチェックが入っている人」でないと
// 常勤案件フォームのプルダウンに出てこないため、ここで事前に確認できるようにする。
$roles = [];   // 名前 => 役割の一覧
foreach ($DATA as $d) {
    if ($d[1] !== '') { $roles[$d[1]]['営業担当'] = true; }
    if ($d[2] !== '') { $roles[$d[2]]['管理者']   = true; }
    if ($d[3] !== '') { $roles[$d[3]]['採用者']   = true; }
}
$rosterRows = [];
foreach (array_keys($roles) as $personName) {
    $row = ['name' => $personName, 'roles' => implode('・', array_keys($roles[$personName])),
            'hits' => 0, 'active' => null, 'emp_type' => null, 'rep_flag' => null, 'id' => null];
    try {
        $rs = $db->prepare('SELECT id, is_active, employment_type, sales_rep_flag
                            FROM employees WHERE company_id = ? AND name = ?');
        $rs->execute([$cid, $personName]);
        $hits = $rs->fetchAll();
        $row['hits'] = count($hits);
        if (count($hits) === 1) {
            $row['id']       = (int)$hits[0]['id'];
            $row['active']   = (int)$hits[0]['is_active'];
            $row['emp_type'] = $hits[0]['employment_type'];
            $row['rep_flag'] = (int)$hits[0]['sales_rep_flag'];
        }
    } catch (PDOException $e) {
        error_log('[import_regular_202603 roster] ' . $e->getMessage());
    }
    $rosterRows[] = $row;
}

$done = false; $created = 0; $skipped = []; $failed = []; $newClients = []; $newAlliances = [];

// ── 登録実行 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    $startDate  = sprintf('%04d-%02d-01', $YEAR, $MONTH);
    foreach ($DATA as $d) {
        [$clientName, $salesRep, $manager, $recruiter, $workerType, $allianceName,
         $workerName, $days, $carrier, $tradeName, $storeName, $priceIn, $priceOut,
         $hikariAd, $note] = $d;
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
                'trade_name'          => $tradeName,
                'area_id'             => null,
                'store_name'          => $storeName,
                'unit_price_in'       => $priceIn,
                'unit_price_out'      => $priceOut,
                'days_worked'         => $days,
                'status'              => 'confirmed',
                'note'                => $note,
                'case_division'       => '2次以降',
                'budget_division'     => null,
                'hikari_ad_flag'      => $hikariAd,
                'gross_profit_direct' => $priceIn - $priceOut,
                // 通常のフォーム保存と同じように、担当者・稼働スタッフの社員IDも入れる
                // （名簿に一意に一致する名前だけ。一致しない場合はNULLのまま）
                'sales_rep_id'        => resolveEmployeeIdByName($cid, $salesRep),
                'manager_id'          => resolveEmployeeIdByName($cid, $manager),
                'recruiter_id'        => resolveEmployeeIdByName($cid, $recruiter),
                'worker_employee_id'  => resolveEmployeeIdByName($cid, $workerName),
            ]);
            $created++;
        } catch (Throwable $e) {
            $failed[] = $workerName . ' — ' . $e->getMessage();
        }
    }
    $done = true;
}

// ── 確認用の集計 ──
$dupRows = 0; $totalIn = 0; $totalOut = 0; $hikariCount = 0;
foreach ($DATA as $d) {
    if (isset($existingNames[$d[6]])) $dupRows++;
    $totalIn  += $d[11];
    $totalOut += $d[12];
    if ($d[13]) $hikariCount++;
}
// 新規作成になるマスタ
$willCreateClients = [];
$willCreateAlliances = [];
foreach ($DATA as $d) {
    if ($d[0] !== '' && !isset($clientMap[$d[0]]))   { $willCreateClients[$d[0]] = true; }
    if ($d[4] === 'アライアンス' && $d[5] !== '' && !isset($allianceMap[$d[5]])) { $willCreateAlliances[$d[5]] = true; }
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
.wrap { max-width:1220px; margin:0 auto; padding:24px 16px 60px; }
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
    常勤案件（<?= $YEAR ?>年<?= $MONTH ?>月）を確認する
  </a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_regular_202603.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
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
      <strong><?= count($DATA) - $dupRows ?>件</strong>だけ登録します。
    </div>
  </div>
  <?php endif; ?>

  <!-- 担当者名の名簿チェック -->
  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2"><i class="bi bi-person-badge me-1"></i>営業担当・管理者・採用者の名前が名簿と合っているか</div>
    <div class="small text-muted mb-2">
      「営業担当チェック」が入っていない人は、常勤案件フォームの<strong>営業担当・管理者のプルダウンに出てきません</strong>。
      このスクリプトは名前をそのまま登録できますが、後から画面で編集するときに選び直せなくなるため、
      ✗ が出た人は先に社員一覧でチェックを入れることをおすすめします。
    </div>
    <div class="table-responsive">
    <table class="table table-sm bg-white mb-0">
      <thead class="table-light"><tr>
        <th>名前</th><th>この月での役割</th><th>名簿</th><th>在籍</th><th>雇用区分</th><th>営業担当チェック</th><th>社員ID</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rosterRows as $r):
        $needsRepFlag = strpos($r['roles'], '営業担当') !== false || strpos($r['roles'], '管理者') !== false;
        $ng = $r['hits'] !== 1 || ($needsRepFlag && $r['rep_flag'] !== 1) || $r['active'] === 0;
      ?>
        <tr class="<?= $ng ? 'table-warning' : '' ?>">
          <td class="fw-medium"><?= h($r['name']) ?></td>
          <td><?= h($r['roles']) ?></td>
          <td><?php if ($r['hits'] === 1): ?><span class="text-success">✓ あり</span>
              <?php elseif ($r['hits'] === 0): ?><span class="text-danger fw-bold">✗ 名簿に無い</span>
              <?php else: ?><span class="text-danger fw-bold">✗ 同姓同名 <?= (int)$r['hits'] ?>人</span><?php endif; ?></td>
          <td><?= $r['active'] === null ? '-' : ($r['active'] ? '在籍' : '<span class="text-danger">退職</span>') ?></td>
          <td><?= h($r['emp_type'] ?? '-') ?></td>
          <td><?php if (!$needsRepFlag): ?><span class="text-muted">不要</span>
              <?php elseif ($r['rep_flag'] === 1): ?><span class="text-success">✓</span>
              <?php else: ?><span class="text-danger fw-bold">✗ 未チェック</span><?php endif; ?></td>
          <td class="text-muted"><?= $r['id'] ?? '-' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div></div>

  <div class="alert alert-info">
    <div class="fw-semibold">登録する内容</div>
    <div class="small mt-1" style="line-height:1.9">
      ・<?= count($DATA) ?>件を <strong><?= $YEAR ?>年<?= $MONTH ?>月</strong> の常勤案件として登録（開始月=<?= $YEAR ?>/<?= sprintf('%02d', $MONTH) ?>、ステータス=確定）<br>
      ・売上合計 <strong><?= number_format($totalIn) ?>円</strong> ／ 原価合計 <strong><?= number_format($totalOut) ?>円</strong> ／ 粗利合計 <strong><?= number_format($totalIn - $totalOut) ?>円</strong><br>
      ・請求単価(月)・支払単価(月)には<strong>月の合計額</strong>を入れています（常勤案件は稼働日数を掛けないため、これで元データと金額が一致します）<br>
      ・<strong>SB・docomo はキャリア</strong>、<strong>ED・YMD・JS・AM・KS は屋号</strong>に登録（キャリアの選択肢に無いため）<br>
      ・スタッフ区分の「外注」は<strong>個人外注</strong>として登録<br>
      ・区分は全件<strong>2次以降</strong>（予算区分は1次のときだけの項目なので設定なし）<br>
      ・今月は取引先が「光AD」の行が無いため、<strong>光ADチェックは全件OFF</strong>
        （安樂斐悠馬・小林友裕はテレニシ、名倉雅貴はプレイスとして登録）<br>
      ・元データ8列目の日付は<strong>稼働開始日</strong>なので、備考に「稼働開始 2025/12」の形で記録（開始月は全件2026/03）<br>
      ・管理者・採用者の「該当者なし」は<strong>空欄（直営業）</strong>にして登録（澤口・ADラダー・小木曽太一×2・名倉雅貴の5件）<br>
      ・元データに契約終了日の列はありません。終了月は空欄<br>
      <?php if ($willCreateClients): ?>・取引先を新規作成します: <strong><?= h(implode('、', array_keys($willCreateClients))) ?></strong><br><?php endif; ?>
      <?php if ($willCreateAlliances): ?>・外注先を新規作成します: <strong><?= h(implode('、', array_keys($willCreateAlliances))) ?></strong><br><?php endif; ?>
    </div>
  </div>

  <div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>登録後の注意：</strong>稼働日数が21未満の5件（竹内陽10日・渡邉拓斗20日・澤口20日・青木大輝1日・小木曽太一土日2日）は、
    常勤案件の一覧で<strong>「金額反映」ボタン（🔄）を押すと日割り計算に置き換わり、金額が変わってしまいます</strong>。押さないでください。
  </div>

  <div class="table-responsive mb-3" style="max-height:52vh">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr>
          <th>#</th><th>取引先</th><th>営業</th><th>管理者</th><th>採用者</th><th>ｽﾀｯﾌ区分</th><th>外注先</th>
          <th>スタッフ</th><th>ｷｬﾘｱ</th><th>屋号</th><th>店舗</th><th>光AD</th><th class="text-end">稼働</th>
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
          <td><?= h($d[5]) ?><?= ($d[4] === 'アライアンス' && $d[5] !== '' && !isset($allianceMap[$d[5]])) ? ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' : '' ?></td>
          <td class="fw-medium"><?= h($d[6]) ?><?= $isDup ? ' <span class="badge bg-danger" style="font-size:.6rem">既存</span>' : '' ?></td>
          <td><?= h($d[8]) ?></td>
          <td><?= h($d[9]) ?></td>
          <td><?= h($d[10]) ?></td>
          <td><?= $d[13] ? '<span class="badge bg-primary" style="font-size:.6rem">光AD</span>' : '' ?></td>
          <td class="text-end"><?= (int)$d[7] ?></td>
          <td class="text-end"><?= number_format($d[11]) ?></td>
          <td class="text-end"><?= number_format($d[12]) ?></td>
          <td class="text-end <?= ($d[11] - $d[12]) < 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format($d[11] - $d[12]) ?></td>
          <td class="text-muted" style="font-size:.7rem"><?= h($d[14]) ?></td>
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
