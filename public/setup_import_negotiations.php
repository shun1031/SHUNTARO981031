<?php
/**
 * 【一度だけ実行するスクリプト】パートナー候補54社を商談報告に一括登録する
 *
 * 照合ページ（check_partner_candidates.php）で「B 商談報告への追加候補」と
 * 判定された54社を、商談報告としてまとめて登録する。
 *
 * ユーザー確認済みの取り決め:
 *   - 対象年月は全社 2025年9月
 *   - ステータスは全社「取引候補」
 *   - 区分（光AD/常勤/イベント）は設定しない（候補段階では不要）
 *   - 担当者はリストのとおり。ALBERA だけ竹内陽・東郷光啓の2人
 *
 * 二重登録の防止:
 *   - 会社は取引先マスタのIDで特定する（会社名の文字列では突き合わせない）
 *   - すでに商談報告がある会社はスキップする
 *   - 取引先が1社に特定できない会社は登録しない（要確認として表示）
 *   - 商談報告テーブルには UNIQUE(会社ID) があるので、万一の重複はDBが拒否する
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }
$csrf = getCsrfToken();

// ============================================================
// 登録の中身（ユーザー確認済み）
// ============================================================
const NI_YM     = 202509;        // 対象年月: 2025年9月
const NI_STATUS = '取引候補';
const NI_DIVISION = '';          // 区分は設定しない

// [No, 正式名称, よみ, [担当者...]]
$LIST = [
    [1,  '株式会社フェローズ',        'フェローズ',            ['竹内陽']],
    [3,  '株式会社Proud Partners',    'プラウドパートナーズ',  ['竹内陽']],
    [6,  '株式会社EXceed',            'エクシード',            ['竹内陽']],
    [13, '株式会社Hope Village',      'ホープビレッジ',        ['竹内陽']],
    [15, 'polish',                    'ポーリッシュ',          ['竹内陽']],
    [16, '株式会社Desafios',          'デサフィオス',          ['竹内陽']],
    [17, '株式会社秀星',              'シュウセイ',            ['竹内陽']],
    [22, 'Attrus株式会社',            'アトラス',              ['竹内陽']],
    [23, '株式会社wanny',             'ワニー',                ['竹内陽']],
    [24, 'Y.W.C株式会社',             'ワイダブリューシー',    ['竹内陽']],
    [25, '株式会社エークラス',        'エークラス',            ['竹内陽']],
    [26, '株式会社Retemper',          'リテンパー',            ['竹内陽']],
    [28, '株式会社Free Professional', 'フリープロフェッショナル', ['竹内陽']],
    [29, '株式会社ネクステラ',        'ネクステラ',            ['竹内陽']],
    [30, 'SUNNY株式会社',             'サニー',                ['竹内陽']],
    [31, '株式会社Lecrin',            'レクラン',              ['竹内陽']],
    [33, '株式会社LEAVES',            'リーブス',              ['竹内陽']],
    [34, '株式会社ALBERA',            'アルベラ',              ['竹内陽', '東郷光啓']],
    [35, '株式会社ME',                'エムイー',              ['竹内陽']],
    [36, 'lotus株式会社',             'ロータス',              ['竹内陽']],
    [46, '株式会社CLEAR',             'クリア',                ['竹内陽']],
    [51, '株式会社libridge',          'ライブリッジ',          ['綾部航介']],
    [52, '伊藤夏哉',                  'イトウナツヤ',          ['綾部航介']],
    [53, '株式会社Face to Faith',     'フェイストゥフェイス',  ['綾部航介']],
    [56, '兵藤一考',                  'ヒョウドウカズタカ',    ['綾部航介']],
    [58, 'スタートリンク株式会社',    'スタートリンク',        ['綾部航介']],
    [60, '株式会社ユウテック',        'ユウテック',            ['綾部航介']],
    [63, '株式会社Fleuve',            'フレーブ',              ['綾部航介']],
    [65, '株式会社Lie Pont',          'リポン',                ['綾部航介']],
    [66, '株式会社T’s Solution',      'ティーズソリューション', ['綾部航介']],
    [67, '堀恭彰',                    'ホリヤスアキ',          ['綾部航介']],
    [68, '平手達也',                  'ヒラテタツヤ',          ['綾部航介']],
    [70, '株式会社アナザーウェイブ',  'アナザーウェイブ',      ['名倉雅貴']],
    [71, '株式会社MIC',               'エムアイシー',          ['名倉雅貴']],
    [72, '株式会社Thinks',            'シンクス',              ['名倉雅貴']],
    [73, '株式会社D-MAK',             'ディーマーク',          ['名倉雅貴']],
    [74, '株式会社デックサポート',    'デックサポート',        ['名倉雅貴']],
    [75, '株式会社Function',          'ファンクション',        ['名倉雅貴']],
    [77, '株式会社No.Limit',          'ノーリミット',          ['名倉雅貴']],
    [78, '株式会社FIRSTART',          'ファーストアート',      ['名倉雅貴']],
    [82, '有限会社半田機工',          'ハンダキコウ',          ['名倉雅貴']],
    [86, '株式会社オルフェーブル',    'オルフェーブル',        ['東郷光啓']],
    [91, '株式会社SWACK',             'スワック',              ['佐藤思杰']],
    [92, '合同会社baddass',           'バッダス',              ['佐藤思杰']],
    [93, '合同会社UnderWill',         'アンダーウィル',        ['佐藤思杰']],
    [94, '株式会社DTC',               'ディーティーシー',      ['佐藤思杰']],
    [95, '株式会社ULTI-ME',           'アルティメ',            ['佐藤思杰']],
    [96, '株式会社center force',      'センターフォース',      ['山根脩平']],
    [97, '株式会社wonder craft',      'ワンダークラフト',      ['山根脩平']],
    [98, '株式会社NAP',               'ナップ',                ['山根脩平']],
    [99, '株式会社ライクスタッフィング', 'ライクスタッフィング', ['山根脩平']],
    [100,'株式会社PEACE',             'ピース',                ['山根脩平']],
    [101,'株式会社GRACE',             'グレイス',              ['山根脩平']],
    [102,'株式会社F-tria',            'エフトリア',            ['山根脩平']],
];

/** 照合ページと同じ正規化（法人格・空白・記号・長音を落とす） */
function niNorm(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/(株式会社|合同会社|有限会社|合資会社|一般社団法人|一般財団法人|\(株\)|（株）|\(有\)|（有）|\(同\)|（同）)/u', '', $s);
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV');
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[’\'`´.,\-‐－ｰー・･。、（）\(\)]/u', '', $s);
    return mb_strtolower((string)$s, 'UTF-8');
}

/** 商談報告テーブルの client_name_key（既存の保存処理と同じ作り方） */
function niNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'aKV');
    return mb_strtolower($n, 'UTF-8');
}

// ============================================================
// マスタ・既存データを読む
// ============================================================
$loadState = function () use ($db, $cid) {
    $c = $db->prepare('SELECT id, client_name, display_name, is_active FROM sales_clients WHERE company_id = ?');
    $c->execute([$cid]);
    $clients = $c->fetchAll(PDO::FETCH_ASSOC);

    $n = $db->prepare('SELECT client_id, client_name, status FROM strategy_meeting_negotiations WHERE company_id = ?');
    $n->execute([$cid]);
    $negs = [];
    foreach ($n->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['client_id'] !== null) $negs[(int)$r['client_id']] = $r;
    }
    return [$clients, $negs];
};
[$clientRows, $negByClient] = $loadState();
$repAllowed = array_flip(getSalesRepCandidates($cid));

/** 会社名から取引先を1社に特定する（完全一致のみ。曖昧なものは登録しない） */
$resolve = function (array $item) use ($clientRows) {
    [$no, $official, $kana, $reps] = $item;
    $keys = array_values(array_unique(array_filter([niNorm($official), niNorm($kana)])));
    $hits = [];
    foreach ($clientRows as $c) {
        foreach ([$c['client_name'], $c['display_name']] as $n) {
            $k = niNorm($n);
            if ($k !== '' && in_array($k, $keys, true)) { $hits[(int)$c['id']] = $c; break; }
        }
    }
    return array_values($hits);
};

// ============================================================
// 1件ずつ判定
// ============================================================
$rows = [];
foreach ($LIST as $item) {
    [$no, $official, $kana, $reps] = $item;
    $hits   = $resolve($item);
    $client = count($hits) === 1 ? $hits[0] : null;
    $neg    = $client ? ($negByClient[(int)$client['id']] ?? null) : null;

    $badReps = array_values(array_filter($reps, fn($r) => !isset($repAllowed[$r])));

    $state = 'ok'; $reason = '登録できます';
    if (!$hits)                { $state = 'ng'; $reason = '取引先マスタに見つかりません'; }
    elseif (count($hits) > 1)  { $state = 'ng'; $reason = '同じ名前の取引先が複数あります（要確認）'; }
    elseif ($neg)              { $state = 'skip'; $reason = 'すでに商談報告があります（' . $neg['status'] . '）'; }
    elseif ($badReps)          { $state = 'ng'; $reason = '担当者を選べません: ' . implode('、', $badReps); }
    elseif ((int)$client['is_active'] !== 1) { $state = 'ng'; $reason = 'この取引先は削除済みです'; }

    $rows[] = compact('no', 'official', 'kana', 'reps', 'client', 'hits', 'neg', 'state', 'reason');
}
$okRows = array_values(array_filter($rows, fn($r) => $r['state'] === 'ok'));

// ============================================================
// 実行
// ============================================================
$done = false; $created = 0; $skipped = []; $failed = []; $execErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $picked  = array_map('intval', (array)($_POST['nos'] ?? []));
    $okNos   = array_column($okRows, 'no');
    $picked  = array_values(array_filter($picked, fn($n) => in_array($n, $okNos, true)));

    if (!$picked) {
        $execErr = '登録する会社が1社も選ばれていません。';
    } else {
        $insNeg = $db->prepare("INSERT INTO strategy_meeting_negotiations
            (company_id, client_id, client_name, client_name_key, rep_name, rep_employee_id,
             status, status_other, division, note, first_report_ym, candidate_ym, active_ym, excluded_ym)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)");
        $insRep = $db->prepare("INSERT INTO strategy_meeting_negotiation_reps
            (company_id, negotiation_id, rep_name, rep_employee_id) VALUES (?,?,?,?)");

        foreach ($rows as $r) {
            if (!in_array($r['no'], $picked, true)) continue;
            $clientId = (int)$r['client']['id'];
            // 実行の直前にもう一度だけ重複を見る
            $chk = $db->prepare('SELECT id FROM strategy_meeting_negotiations WHERE company_id = ? AND client_id = ?');
            $chk->execute([$cid, $clientId]);
            if ($chk->fetchColumn() !== false) {
                $skipped[] = $r['official'] . '（すでに商談報告あり）';
                continue;
            }
            $label = trim((string)$r['client']['display_name']) ?: $r['client']['client_name'];
            try {
                $db->beginTransaction();
                $insNeg->execute([
                    $cid, $clientId, $label, niNameKey($label),
                    $r['reps'][0], resolveEmployeeIdByName($cid, $r['reps'][0]),
                    NI_STATUS, null, NI_DIVISION, null,
                    NI_YM, NI_YM,
                ]);
                $negId = (int)$db->lastInsertId();
                foreach ($r['reps'] as $rn) {
                    $insRep->execute([$cid, $negId, $rn, resolveEmployeeIdByName($cid, $rn)]);
                }
                $db->commit();
                $created++;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[setup_import_negotiations] ' . $e->getMessage());
                $failed[] = $r['official'] . ' — ' . $e->getMessage();
            }
        }
        $done = true;

        // 表示を作り直す
        [$clientRows, $negByClient] = $loadState();
        $rows = [];
        foreach ($LIST as $item) {
            [$no, $official, $kana, $reps] = $item;
            $hits   = $resolve($item);
            $client = count($hits) === 1 ? $hits[0] : null;
            $neg    = $client ? ($negByClient[(int)$client['id']] ?? null) : null;
            $state  = $neg ? 'skip' : (!$client ? 'ng' : 'ok');
            $reason = $neg ? ('商談報告あり（' . $neg['status'] . '）') : (!$client ? '取引先が特定できません' : '未登録');
            $rows[] = compact('no', 'official', 'kana', 'reps', 'client', 'hits', 'neg', 'state', 'reason');
        }
        $okRows = array_values(array_filter($rows, fn($r) => $r['state'] === 'ok'));
    }
}

// 担当者ごとの件数（登録できるものだけ）
$byRep = [];
foreach ($okRows as $r) { foreach ($r['reps'] as $rn) { $byRep[$rn] = ($byRep[$rn] ?? 0) + 1; } }
ksort($byRep);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$stateBadge = function (string $s): string {
    if ($s === 'ok')   return '<span class="badge bg-success">登録できます</span>';
    if ($s === 'skip') return '<span class="badge bg-secondary">スキップ</span>';
    return '<span class="badge bg-danger">登録しません</span>';
};
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>パートナー候補54社を商談報告に一括登録</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1100px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.78rem; }
.nw { white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-chat-left-text me-2"></i>パートナー候補を商談報告に一括登録</h4>
<p class="text-muted small mb-4">
    対象年月 <strong>2025年9月</strong>／ステータス <strong>取引候補</strong>／区分は設定しません<br>
    会社は<strong>取引先マスタのID</strong>で特定します（会社名の文字列では突き合わせません）
</p>

<?php if ($execErr): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($execErr) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $created ?>社を商談報告に登録しました</div>
    <div class="small mt-1">パートナー候補数が <?= $created ?>社ぶん増えます。取引中の会社の数（パートナー数）は変わりません。</div>
  </div>
  <?php if ($skipped): ?>
    <div class="alert alert-secondary small"><strong><?= count($skipped) ?>社はスキップしました</strong>
      <div class="mt-1"><?= $h(implode(' ／ ', $skipped)) ?></div></div>
  <?php endif; ?>
  <?php if ($failed): ?>
    <div class="alert alert-danger small"><strong><?= count($failed) ?>社は登録できませんでした</strong>
      <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= $h($f) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
<?php endif; ?>

<!-- サマリー -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-people me-1"></i>担当者ごとの登録件数</div>
  <div class="card-body">
    <?php if (!$byRep): ?>
      <div class="small text-muted">登録できる会社がありません<?= $done ? '（実行済み）' : '' ?>。</div>
    <?php else: ?>
      <table class="table table-sm table-bordered bg-white w-auto mb-0">
        <thead class="table-light"><tr><th>担当者</th><th class="nw">件数</th></tr></thead>
        <tbody>
        <?php foreach ($byRep as $rn => $n): ?>
          <tr><td class="fw-medium"><?= $h($rn) ?></td><td class="nw"><?= $n ?>件</td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light"><tr><th>登録する会社</th><th class="nw"><?= count($okRows) ?>社</th></tr></tfoot>
      </table>
      <div class="small text-muted mt-2">
        ※ ALBERA は竹内陽・東郷光啓の2人が担当のため、両方の件数に入っています（会社としては1社です）。
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- 明細 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-list-check me-1"></i>登録する会社の一覧
    <span class="badge bg-primary ms-1"><?= count($okRows) ?>社</span>
    <span class="text-muted small ms-2">全<?= count($rows) ?>件</span>
  </div>
  <div class="card-body">
    <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-bordered table-hover bg-white mb-0">
          <thead class="table-light" style="position:sticky;top:0">
            <tr>
              <th style="width:2.5rem"></th>
              <th>No</th><th>会社・個人名</th><th>取引先（表記名）</th><th>ID</th><th>担当者</th><th>状態</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['state'] === 'ng' ? 'table-danger' : ($r['state'] === 'skip' ? 'table-secondary' : '') ?>">
              <td class="text-center">
                <input type="checkbox" class="form-check-input" name="nos[]" value="<?= $r['no'] ?>"
                       <?= $r['state'] === 'ok' && !$done ? 'checked' : '' ?>
                       <?= $r['state'] === 'ok' && !$done ? '' : 'disabled' ?>>
              </td>
              <td class="text-muted nw"><?= $r['no'] ?></td>
              <td class="fw-medium"><?= $h($r['official']) ?></td>
              <td>
                <?php if ($r['client']): ?>
                  <?= $h($r['client']['display_name'] ?: $r['client']['client_name']) ?>
                <?php elseif ($r['hits']): ?>
                  <span class="text-danger"><?= $h(implode(' / ', array_map(fn($c) => ($c['display_name'] ?: $c['client_name']) . '(ID' . $c['id'] . ')', $r['hits']))) ?></span>
                <?php else: ?>
                  <span class="text-danger">未登録</span>
                <?php endif; ?>
              </td>
              <td class="text-muted nw"><?= $r['client'] ? (int)$r['client']['id'] : '-' ?></td>
              <td class="nw"><?= $h(implode('、', $r['reps'])) ?></td>
              <td class="nw"><?= $stateBadge($r['state']) ?>
                <div class="text-muted" style="font-size:.68rem"><?= $h($r['reason']) ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$done): ?>
      <div class="alert alert-info small mt-3">
        <div class="fw-semibold">実行すること</div>
        <div style="line-height:1.9">
          ・チェックした会社を<strong>商談報告</strong>として登録します<br>
          ・対象年月は全社 <strong>2025年9月</strong>、ステータスは全社 <strong>取引候補</strong>、区分は<strong>設定しません</strong><br>
          ・担当者は一覧のとおり。<strong>ALBERA は2人</strong>登録します<br>
          ・登録すると<strong>パートナー候補数が <?= count($okRows) ?>社ぶん増えます</strong><br>
          ・<strong>パートナー数（取引中の会社）・売上・案件は変わりません</strong><br>
          ・すでに商談報告がある会社、取引先が特定できない会社は<strong>登録しません</strong><br>
          ・実行の直前にもう一度だけ重複を確認します
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg"<?= $okRows ? '' : ' disabled' ?>>
        <i class="bi bi-check-circle me-1"></i>登録を実行（<?= count($okRows) ?>社）
      </button>
      <a href="<?= BASE_PATH ?>/public/strategy_meeting.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($done): ?>
<a href="<?= BASE_PATH ?>/public/strategy_meeting.php" class="btn btn-primary">戦略会議で確認する</a>
<a href="<?= BASE_PATH ?>/public/check_partner_candidates.php" class="btn btn-outline-primary ms-2">照合ページを開く</a>
<?php endif; ?>

<div class="alert alert-warning mt-3 mb-0 small">
  <i class="bi bi-exclamation-triangle me-1"></i>実行が終わったら、このページ（setup_import_negotiations.php）は削除してください。
</div>

</div>
</body>
</html>
