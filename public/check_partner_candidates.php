<?php
/**
 * 【確認専用ページ・書き込みは一切しない】
 * パートナー候補リスト（102件）と、いまのシステムのデータを突き合わせる。
 *
 * 出すもの:
 *   1. 営業担当6名が商談報告の担当者として選べるか
 *   2. 102件の照合表（取引先マスタのID・現在取引中か・担当者・追加候補か）
 *   3. A 現在取引中のため除外 / B 追加候補 / C 重複 / D 担当者要確認
 *   4. E 取引先マスタに無い会社（先に登録が必要）
 *   5. F 外注先にも同名がある会社（二重カウント注意）
 *
 * このページはデータを一切変更しない。INSERT/UPDATE/DELETE も POST 処理も無い。
 * 年度は ?fy=2026 のように指定できる（fy=2026 は 2025年9月〜2026年8月）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// ── 年度（9月始まり） ──
$_today     = new DateTimeImmutable('today');
$_fyDefault = (int)$_today->format('n') >= 9 ? (int)$_today->format('Y') + 1 : (int)$_today->format('Y');
$fyYear = (int)($_GET['fy'] ?? $_fyDefault);
if ($fyYear < 2000 || $fyYear > 2100) $fyYear = $_fyDefault;
$fyParams = [$fyYear - 1, $fyYear];
$fyLabel  = ($fyYear - 1) . '年9月〜' . $fyYear . '年8月';

const CP_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";

// ============================================================
// 照合リスト  [No, 担当者, 正式名称（登録したい形）, よみ, 備考]
// よみは会社名が英字のときの突き合わせに使う（Pachira ↔ パキラ など）
// ============================================================
$LIST = [
    // ---- 竹内陽 ----
    [1,'竹内陽','株式会社フェローズ','フェローズ',''],
    [2,'竹内陽','株式会社KTT','ケーティーティー',''],
    [3,'竹内陽','株式会社Proud Partners','プラウドパートナーズ',''],
    [4,'竹内陽','CloudAdent株式会社','クラウドエージェント',''],
    [5,'竹内陽','株式会社LANGIS','ランギス',''],
    [6,'竹内陽','株式会社EXceed','エクシード',''],
    [7,'竹内陽','株式会社魁組','サキガケグミ',''],
    [8,'竹内陽','株式会社コンフィアンスグループ','コンフィアンスグループ',''],
    [9,'竹内陽','株式会社V.I.N','ブイアイエヌ','イベント'],
    [10,'竹内陽','株式会社T-Group','ティーグループ',''],
    [11,'竹内陽','合同会社onetale','ワンテール',''],
    [12,'竹内陽','渡邉拓斗','ワタナベタクト','個人'],
    [13,'竹内陽','株式会社Hope Village','ホープビレッジ',''],
    [14,'竹内陽','大塚','オオツカ','個人'],
    [15,'竹内陽','polish','ポーリッシュ',''],
    [16,'竹内陽','株式会社Desafios','デサフィオス',''],
    [17,'竹内陽','株式会社秀星','シュウセイ',''],
    [18,'竹内陽','株式会社FREEDOM','フリーダム',''],
    [19,'竹内陽','SEED','シード',''],
    [20,'竹内陽','株式会社I’bis','アイビス',''],
    [21,'竹内陽','株式会社スリーエス','スリーエス',''],
    [22,'竹内陽','Attrus株式会社','アトラス',''],
    [23,'竹内陽','株式会社wanny','ワニー',''],
    [24,'竹内陽','Y.W.C株式会社','ワイダブリューシー',''],
    [25,'竹内陽','株式会社エークラス','エークラス',''],
    [26,'竹内陽','株式会社Retemper','リテンパー',''],
    [27,'竹内陽','株式会社ホルドブレイ','ホルドブレイ',''],
    [28,'竹内陽','株式会社Free Professional','フリープロフェッショナル',''],
    [29,'竹内陽','株式会社ネクステラ','ネクステラ',''],
    [30,'竹内陽','SUNNY株式会社','サニー',''],
    [31,'竹内陽','株式会社Lecrin','レクラン',''],
    [32,'竹内陽','株式会社樹','イツキ',''],
    [33,'竹内陽','株式会社LEAVES','リーブス',''],
    [34,'竹内陽','株式会社ALBERA','アルベラ',''],
    [35,'竹内陽','株式会社ME','エムイー',''],
    [36,'竹内陽','lotus株式会社','ロータス',''],
    [37,'竹内陽','株式会社プレイス','プレイス',''],
    [38,'竹内陽','株式会社ラネット','ラネット',''],
    [39,'竹内陽','ライフフレンド株式会社','ライフフレンド',''],
    [40,'竹内陽','株式会社OASIS','オアシス',''],
    [41,'竹内陽','株式会社テレニシ','テレニシ',''],
    [42,'竹内陽','株式会社ベルパーク','ベルパーク',''],
    [43,'竹内陽','株式会社アスカ','アスカ',''],
    [44,'竹内陽','株式会社MDC','エムディーシー',''],
    [45,'竹内陽','株式会社SNAP','スナップ',''],
    [46,'竹内陽','株式会社CLEAR','クリア',''],
    // ---- 綾部航介 ----
    [47,'綾部航介','株式会社U-Plus','ユープラス',''],
    [48,'綾部航介','株式会社AXSEED','アスシード',''],
    [49,'綾部航介','株式会社waplus','ワプラス',''],
    [50,'綾部航介','株式会社nextassist','ネクストアシスト',''],
    [51,'綾部航介','株式会社libridge','ライブリッジ',''],
    [52,'綾部航介','伊藤夏哉','イトウナツヤ','個人'],
    [53,'綾部航介','株式会社Face to Faith','フェイストゥフェイス',''],
    [54,'綾部航介','株式会社Pachira','パキラ',''],
    [55,'綾部航介','株式会社GRUST','グラスト',''],
    [56,'綾部航介','兵藤一考','ヒョウドウカズタカ','個人'],
    [57,'綾部航介','ASB株式会社','エーエスビー',''],
    [58,'綾部航介','スタートリンク株式会社','スタートリンク',''],
    [59,'綾部航介','株式会社TIR','ティーアイアール',''],
    [60,'綾部航介','株式会社ユウテック','ユウテック',''],
    [61,'綾部航介','株式会社humanR','ヒューマンアール',''],
    [62,'綾部航介','株式会社LLC','エルエルシー',''],
    [63,'綾部航介','株式会社Fleuve','フレーブ',''],
    [64,'綾部航介','株式会社Pachira','パキラ','玉越（54と同じ会社）'],
    [65,'綾部航介','株式会社Lie Pont','リポン',''],
    [66,'綾部航介','株式会社T’s Solution','ティーズソリューション',''],
    [67,'綾部航介','堀恭彰','ホリヤスアキ','個人'],
    [68,'綾部航介','平手達也','ヒラテタツヤ','個人'],
    // ---- 名倉雅貴 ----
    [69,'名倉雅貴','株式会社CenterFlow','センターフロー',''],
    [70,'名倉雅貴','株式会社アナザーウェイブ','アナザーウェイブ',''],
    [71,'名倉雅貴','株式会社MIC','エムアイシー',''],
    [72,'名倉雅貴','株式会社Thinks','シンクス',''],
    [73,'名倉雅貴','株式会社D-MAK','ディーマーク',''],
    [74,'名倉雅貴','株式会社デックサポート','デックサポート',''],
    [75,'名倉雅貴','株式会社Function','ファンクション',''],
    [76,'名倉雅貴','合同会社Linoa Creation','リノアクリエイション','Atmosは別名。1社として登録'],
    [77,'名倉雅貴','株式会社No.Limit','ノーリミット',''],
    [78,'名倉雅貴','株式会社FIRSTART','ファーストアート',''],
    [79,'名倉雅貴','株式会社プレイミー','プレイミー',''],
    [80,'名倉雅貴','LIBERTY株式会社','リバティ',''],
    [81,'名倉雅貴','株式会社KunitokoAsset','クニトコアセット',''],
    [82,'名倉雅貴','有限会社半田機工','ハンダキコウ','石川亮次'],
    [83,'名倉雅貴','株式会社ネクシア','ネクシア',''],
    // ---- 東郷光啓 ----
    [84,'東郷光啓','株式会社LIFIX','リフィックス',''],
    [85,'東郷光啓','株式会社V.I.N','ブイアイエヌ','9と同じ会社'],
    [86,'東郷光啓','株式会社オルフェーブル','オルフェーブル',''],
    [87,'東郷光啓','株式会社テレポートモバイル','テレポートモバイル',''],
    [88,'東郷光啓','株式会社ALBERA','アルベラ','34と同じ会社'],
    [89,'東郷光啓','合同会社COREN','コーレン',''],
    [90,'東郷光啓','株式会社NextPlace','ネクストプレイス',''],
    // ---- 佐藤思杰 ----
    [91,'佐藤思杰','株式会社SWACK','スワック',''],
    [92,'佐藤思杰','合同会社baddass','バッダス',''],
    [93,'佐藤思杰','合同会社UnderWill','アンダーウィル',''],
    [94,'佐藤思杰','株式会社DTC','ディーティーシー',''],
    [95,'佐藤思杰','株式会社ULTI-ME','アルティメ',''],
    // ---- 山根脩平 ----
    [96,'山根脩平','株式会社center force','センターフォース',''],
    [97,'山根脩平','株式会社wonder craft','ワンダークラフト',''],
    [98,'山根脩平','株式会社NAP','ナップ',''],
    [99,'山根脩平','株式会社ライクスタッフィング','ライクスタッフィング',''],
    [100,'山根脩平','株式会社PEACE','ピース',''],
    [101,'山根脩平','株式会社GRACE','グレイス',''],
    [102,'山根脩平','株式会社F-tria','エフトリア',''],
];

// ============================================================
// 突き合わせ用の正規化
//   法人格・空白・記号・長音を落とし、全角英数は半角に、半角カナは全角に揃える
// ============================================================
function cpNorm(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/(株式会社|合同会社|有限会社|合資会社|一般社団法人|一般財団法人|\(株\)|（株）|\(有\)|（有）|\(同\)|（同）)/u', '', $s);
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV');
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[’\'`´.,\-‐－ｰー・･。、（）\(\)]/u', '', $s);
    return mb_strtolower((string)$s, 'UTF-8');
}

// ============================================================
// マスタと実績をまとめて読む（読み取りのみ）
// ============================================================
$clients = $db->prepare('SELECT id, client_name, display_name, is_active FROM sales_clients WHERE company_id = ?');
$clients->execute([$cid]);
$clientRows = $clients->fetchAll(PDO::FETCH_ASSOC);

$alliances = $db->prepare('SELECT id, alliance_name, display_name, client_id, is_active FROM sales_alliances WHERE company_id = ?');
$alliances->execute([$cid]);
$allianceRows = $alliances->fetchAll(PDO::FETCH_ASSOC);

// 今年度の確定案件（取引先ごと）: 件数と営業担当
$cs = $db->prepare("
    SELECT sc.client_id, COUNT(*) AS cnt,
           GROUP_CONCAT(DISTINCT COALESCE(er.name, sc.sales_rep) ORDER BY COALESCE(er.name, sc.sales_rep) SEPARATOR '、') AS reps
    FROM sales_cases sc
    LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
    WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.client_id IS NOT NULL
      AND " . CP_FY_WHERE . "
    GROUP BY sc.client_id");
$cs->execute(array_merge([$cid], $fyParams));
$caseByClient = [];
foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $caseByClient[(int)$r['client_id']] = ['cnt' => (int)$r['cnt'], 'reps' => (string)$r['reps']];
}

// 今年度の確定案件（外注先ごと）: 件数と管理者
$as = $db->prepare("
    SELECT sc.alliance_id, COUNT(*) AS cnt,
           GROUP_CONCAT(DISTINCT COALESCE(em.name, sc.manager) ORDER BY COALESCE(em.name, sc.manager) SEPARATOR '、') AS mgrs
    FROM sales_cases sc
    LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.worker_type = 'アライアンス' AND sc.alliance_id IS NOT NULL
      AND " . CP_FY_WHERE . "
    GROUP BY sc.alliance_id");
$as->execute(array_merge([$cid], $fyParams));
$caseByAlliance = [];
foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $caseByAlliance[(int)$r['alliance_id']] = ['cnt' => (int)$r['cnt'], 'mgrs' => (string)$r['mgrs']];
}

// 既存の商談報告
$ns = $db->prepare('SELECT client_id, client_name, rep_name, status FROM strategy_meeting_negotiations WHERE company_id = ?');
$ns->execute([$cid]);
$negByClient = [];
$negNoClient = [];
foreach ($ns->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['client_id'] !== null) $negByClient[(int)$r['client_id']] = $r;
    else                          $negNoClient[] = $r;
}

// 営業担当として選べる人
$repCandidates = getSalesRepCandidates($cid);
$repFlag = array_flip($repCandidates);
$LIST_REPS = ['竹内陽', '綾部航介', '名倉雅貴', '東郷光啓', '佐藤思杰', '山根脩平'];

// ── 正規化キーの索引を作る ──
$clientIndex = [];   // 正規化名 => [取引先の行, ...]
foreach ($clientRows as $c) {
    foreach ([$c['client_name'], $c['display_name']] as $n) {
        $k = cpNorm($n);
        if ($k !== '') $clientIndex[$k][(int)$c['id']] = $c;
    }
}
$allianceIndex = [];
foreach ($allianceRows as $a) {
    foreach ([$a['alliance_name'], $a['display_name']] as $n) {
        $k = cpNorm($n);
        if ($k !== '') $allianceIndex[$k][(int)$a['id']] = $a;
    }
}

/**
 * 完全一致 → 見つからなければ「似た名前」を候補として返す。
 * 似た名前の拾い方は2通り:
 *   ① 部分一致（3文字以上。「テレポート」と「テレポートモバイル」など）
 *   ② 綴り違い（英数字のみ・5文字以上で、2文字ぶん以内の違い。
 *      Fleuve ↔ flueve のような打ち間違い・入れ替わりを拾う）
 * ②は日本語だと誤判定しやすいので、英数字だけの名前に限定する
 */
function cpFind(array $keys, array $index): array {
    foreach ($keys as $k) {
        if ($k !== '' && isset($index[$k])) return [array_values($index[$k]), 'exact'];
    }
    $hits = [];
    foreach ($keys as $k) {
        if ($k === '') continue;
        $kAscii = (bool)preg_match('/^[0-9a-z]+$/', $k);
        foreach ($index as $ik => $rows) {
            if ($ik === $k) continue;
            $near = false;
            // ① 部分一致
            if (mb_strlen($k) >= 3 && (mb_strpos($ik, $k) !== false || mb_strpos($k, $ik) !== false)) {
                $near = true;
            }
            // ② 綴り違い（英数字のみ）
            if (!$near && $kAscii && mb_strlen($k) >= 5 && preg_match('/^[0-9a-z]+$/', $ik)
                && abs(strlen($ik) - strlen($k)) <= 2 && levenshtein($k, $ik) <= 2) {
                $near = true;
            }
            if ($near) { foreach ($rows as $id => $r) $hits[$id] = $r; }
        }
    }
    return [array_values($hits), $hits ? 'partial' : 'none'];
}

// ============================================================
// 1件ずつ判定
// ============================================================
$result = [];
$seenNorm = [];    // 正規化名 => 最初に出たNo（リスト内の重複判定）
foreach ($LIST as [$no, $listRep, $official, $kana, $memo]) {
    $keys = array_values(array_unique(array_filter([cpNorm($official), cpNorm($kana)])));
    $mainKey = $keys[0] ?? '';

    // リスト内の重複
    $dupOf = null;
    if ($mainKey !== '' && isset($seenNorm[$mainKey])) $dupOf = $seenNorm[$mainKey];
    elseif ($mainKey !== '')                            $seenNorm[$mainKey] = $no;

    [$cHits, $cHow] = cpFind($keys, $clientIndex);
    [$aHits, $aHow] = cpFind($keys, $allianceIndex);

    // 取引先の確定（完全一致が1件なら確定、複数or部分一致なら要確認）
    $client = ($cHow === 'exact' && count($cHits) === 1) ? $cHits[0] : null;
    $clientId = $client ? (int)$client['id'] : null;

    $case = $clientId !== null ? ($caseByClient[$clientId] ?? null) : null;
    $neg  = $clientId !== null ? ($negByClient[$clientId] ?? null) : null;

    // 外注先側の案件（個人が外注先として稼働している場合）
    $allianceCase = null;
    foreach ($aHits as $a) {
        $ac = $caseByAlliance[(int)$a['id']] ?? null;
        if ($ac) { $allianceCase = $ac + ['name' => $a['alliance_name'], 'linked' => $a['client_id']]; break; }
    }

    $trading = ($case && $case['cnt'] > 0) || ($allianceCase !== null);

    // 担当者の突き合わせ
    $actualReps = trim((string)($case['reps'] ?? '')) ?: trim((string)($allianceCase['mgrs'] ?? ''));
    $negRep     = trim((string)($neg['rep_name'] ?? ''));
    $repMismatch = false;
    if ($actualReps !== '' && mb_strpos($actualReps, $listRep) === false) $repMismatch = true;
    if ($negRep !== '' && $negRep !== $listRep)                           $repMismatch = true;

    // 判定
    $reasons = [];
    if ($dupOf !== null)              $reasons[] = 'No.' . $dupOf . ' と同じ会社';
    if (!$client && $cHow === 'none') $reasons[] = '取引先マスタに見つからない（先に登録が必要）';
    if (!$client && $cHow === 'partial') $reasons[] = '似た名前の取引先あり（要確認）';
    if (!$client && $cHow === 'exact' && count($cHits) > 1) $reasons[] = '同じ名前の取引先が複数（要確認）';
    if ($case)          $reasons[] = '今年度の案件 ' . $case['cnt'] . '件';
    if ($allianceCase)  $reasons[] = '外注先として案件 ' . $allianceCase['cnt'] . '件';
    if ($neg)           $reasons[] = '商談報告あり（' . $neg['status'] . '／' . ($negRep ?: '担当者なし') . '）';
    if ($repMismatch)   $reasons[] = '担当者が食い違う';
    if (!$reasons)      $reasons[] = '取引なし・商談報告なし';

    $candidate = (!$trading && $dupOf === null);

    $result[] = [
        'no' => $no, 'rep' => $listRep, 'official' => $official, 'kana' => $kana, 'memo' => $memo,
        'client' => $client, 'cHits' => $cHits, 'cHow' => $cHow,
        'aHits' => $aHits, 'allianceCase' => $allianceCase,
        'case' => $case, 'neg' => $neg,
        'trading' => $trading, 'dupOf' => $dupOf,
        'actualReps' => $actualReps, 'repMismatch' => $repMismatch,
        'candidate' => $candidate, 'reasons' => $reasons,
    ];
}

// ── 集計 ──
$grpA = array_values(array_filter($result, fn($r) => $r['trading'] && $r['dupOf'] === null));
$grpB = array_values(array_filter($result, fn($r) => $r['candidate']));
$grpC = array_values(array_filter($result, fn($r) => $r['dupOf'] !== null));
$grpD = array_values(array_filter($result, fn($r) => $r['repMismatch'] || ($r['cHow'] === 'partial') || ($r['cHow'] === 'exact' && !$r['client'])));
$grpE = array_values(array_filter($result, fn($r) => !$r['client'] && $r['cHow'] === 'none' && $r['dupOf'] === null));
$grpF = array_values(array_filter($result, fn($r) => $r['client'] && $r['aHits']));

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>パートナー候補リスト 照合結果</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:100%; margin:0 auto; padding:24px 24px 60px; }
td, th { font-size:.76rem; }
th { white-space:nowrap; }
.nw { white-space:nowrap; }
.reason { font-size:.7rem; color:#6b7280; }
.sticky thead th { position:sticky; top:0; background:#f1f5f9; z-index:2; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-search me-2"></i>パートナー候補リスト 照合結果</h4>
<p class="text-muted small mb-3">
    対象年度: <?= $h($fyLabel) ?>　／　リスト <?= count($LIST) ?>件　／
    <span class="badge bg-success">このページはデータを一切変更しません（確認専用）</span>
</p>

<div class="mb-4">
  <?php foreach ([$fyYear - 1, $fyYear] as $f): ?>
    <a href="?fy=<?= $f ?>" class="btn btn-sm <?= $f === $fyYear ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $f-1 ?>-<?= $f ?>年度</a>
  <?php endforeach; ?>
</div>

<!-- 集計 -->
<div class="row g-3 mb-4">
  <?php foreach ([
      ['A 現在取引中のため除外', count($grpA), 'secondary'],
      ['B 商談報告への追加候補', count($grpB), 'primary'],
      ['C 重複している', count($grpC), 'warning'],
      ['D 担当者・照合の要確認', count($grpD), 'danger'],
      ['E 取引先マスタに無い', count($grpE), 'dark'],
      ['F 外注先にも同名あり', count($grpF), 'info'],
  ] as [$lbl, $n, $col]): ?>
  <div class="col-6 col-md-2">
    <div class="card border-<?= $col ?>"><div class="card-body py-2 px-3">
      <div class="text-muted" style="font-size:.68rem"><?= $h($lbl) ?></div>
      <div class="fw-bold" style="font-size:1.4rem"><?= $n ?><span style="font-size:.8rem">社</span></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- 営業担当チェック -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-person-check me-1"></i>1. 営業担当6名が商談報告の担当者として選べるか</div>
  <div class="card-body">
    <table class="table table-sm table-bordered bg-white w-auto mb-2">
      <thead class="table-light"><tr><th>担当者</th><th>商談報告で選べるか</th><th class="nw">リスト件数</th></tr></thead>
      <tbody>
      <?php foreach ($LIST_REPS as $r):
        $cnt = count(array_filter($LIST, fn($x) => $x[1] === $r)); ?>
        <tr>
          <td class="fw-medium"><?= $h($r) ?></td>
          <td><?= isset($repFlag[$r])
                ? '<span class="badge bg-success">選べる</span>'
                : '<span class="badge bg-danger">選べない（社員一覧で営業担当のチェックが必要）</span>' ?></td>
          <td class="nw"><?= $cnt ?>件</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="small text-muted">
      商談報告の担当者に出てくるのは「社員一覧で営業担当にチェックがある、在籍中の正社員・自社外注」です。<br>
      いま選べる人：<?= $repCandidates ? $h(implode('、', $repCandidates)) : '（なし）' ?>
    </div>
  </div>
</div>

<!-- 照合表 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-table me-1"></i>2. 102件の照合表</div>
  <div class="card-body">
    <div class="table-responsive sticky" style="max-height:70vh">
      <table class="table table-sm table-bordered table-hover bg-white mb-0">
        <thead>
          <tr>
            <th>No</th><th>会社・個人名</th><th>取引先マスタ（表記名）</th><th>ID</th>
            <th>リスト担当</th><th>案件上の担当</th><th>取引中</th><th>重複</th><th>追加候補</th><th>判定理由</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($result as $r): ?>
          <tr class="<?= $r['dupOf'] !== null ? 'table-warning' : ($r['trading'] ? 'table-secondary' : '') ?>">
            <td class="text-muted nw"><?= $r['no'] ?></td>
            <td class="fw-medium"><?= $h($r['official']) ?>
                <?php if ($r['memo']): ?><span class="text-muted" style="font-size:.66rem">（<?= $h($r['memo']) ?>）</span><?php endif; ?></td>
            <td>
              <?php if ($r['client']): ?>
                <?= $h($r['client']['display_name'] ?: $r['client']['client_name']) ?>
                <?php if ((int)$r['client']['is_active'] !== 1): ?><span class="badge bg-secondary" style="font-size:.6rem">削除済み</span><?php endif; ?>
              <?php elseif ($r['cHits']): ?>
                <span class="text-danger">要確認:
                  <?= $h(implode(' / ', array_map(fn($c) => ($c['display_name'] ?: $c['client_name']) . '(ID' . $c['id'] . ')', $r['cHits']))) ?></span>
              <?php else: ?>
                <span class="text-danger">未登録</span>
              <?php endif; ?>
              <?php if ($r['aHits']): ?>
                <div class="text-info" style="font-size:.66rem">外注先にも同名:
                  <?= $h(implode(' / ', array_map(fn($a) => ($a['display_name'] ?: $a['alliance_name']) . ($a['client_id'] ? '（取引先紐付済）' : '（紐付なし・要注意）'), $r['aHits']))) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted nw"><?= $r['client'] ? (int)$r['client']['id'] : '-' ?></td>
            <td class="nw"><?= $h($r['rep']) ?></td>
            <td class="nw <?= $r['repMismatch'] ? 'text-danger fw-bold' : '' ?>"><?= $r['actualReps'] !== '' ? $h($r['actualReps']) : '-' ?></td>
            <td class="nw"><?= $r['trading'] ? '<span class="badge bg-secondary">取引中</span>' : '-' ?></td>
            <td class="nw"><?= $r['dupOf'] !== null ? '<span class="badge bg-warning text-dark">No.' . $r['dupOf'] . '</span>' : '-' ?></td>
            <td class="nw"><?= $r['candidate'] ? '<span class="badge bg-primary">候補</span>' : '-' ?></td>
            <td class="reason"><?= $h(implode(' / ', $r['reasons'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- A〜F -->
<?php
$groups = [
  ['A. 現在取引中のため除外する会社', $grpA, 'secondary', '今年度に確定案件があるため、すでにパートナーとして数えられています。商談報告への追加は不要です。'],
  ['B. 現在取引がなく、商談報告への追加候補となる会社', $grpB, 'primary', '案件が無いのでパートナーにもパートナー候補にも入っていません。商談報告を登録すると「パートナー候補」に加わります。'],
  ['C. 重複している会社', $grpC, 'warning', 'リスト内で同じ会社が2回出てきています。会社としては1社です。担当者をどうするかご判断ください。'],
  ['D. 担当者・照合の確認が必要な会社', $grpD, 'danger', 'リスト上の担当者と案件・商談報告上の担当者が食い違う、または取引先の特定ができないものです。勝手に判断していません。'],
  ['E. 取引先マスタに無い会社（先に登録が必要）', $grpE, 'dark', '商談報告は取引先一覧からの選択式のため、先に取引先として登録しないと商談報告を作れません。'],
  ['F. 外注先にも同名がある会社（二重カウント注意）', $grpF, 'info', '取引先と外注先の両方に同じ会社がある場合、外注先側に「同じ会社の取引先」を紐付けておかないと2社に数えられます。'],
];
foreach ($groups as [$title, $rows, $col, $desc]): ?>
<div class="card mb-3">
  <div class="card-header bg-white fw-bold">
    <span class="badge bg-<?= $col ?> me-1"><?= count($rows) ?>社</span><?= $h($title) ?>
  </div>
  <div class="card-body">
    <div class="small text-muted mb-2"><?= $h($desc) ?></div>
    <?php if (!$rows): ?>
      <div class="small text-success">該当なし</div>
    <?php else: ?>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($rows as $r): ?>
          <span class="badge bg-light text-dark border">
            <?= $r['no'] ?>. <?= $h($r['official']) ?>
            <span class="text-muted">／<?= $h($r['rep']) ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($negNoClient): ?>
<div class="alert alert-warning small">
  <strong>参考:</strong> 取引先が選ばれていない古い商談報告が <?= count($negNoClient) ?>件 あります
  （<?= $h(implode('、', array_map(fn($n) => $n['client_name'], array_slice($negNoClient, 0, 10)))) ?>）。
  これらは会社の突き合わせができず、パートナー数の重複の原因になります。
</div>
<?php endif; ?>

<a href="<?= BASE_PATH ?>/public/strategy_meeting.php" class="btn btn-primary">戦略会議に戻る</a>
<a href="<?= BASE_PATH ?>/public/clients.php" class="btn btn-outline-primary ms-2">取引先一覧を開く</a>

<div class="alert alert-secondary mt-3 mb-0 small">
  <i class="bi bi-shield-check me-1"></i>このページは読み取り専用です。開いても押しても、データは一切変わりません。
</div>

</div>
</body>
</html>
