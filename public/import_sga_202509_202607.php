<?php
/**
 * 【一度だけ実行するスクリプト】支出管理 データ投入
 *   対象: 2025年9月〜2026年4月 ／ 2026年7月（計9か月）
 *
 * 方針（案A: この9か月は今回いただいた数字で統一）
 *   ステップ1 … 既存の重複行（正社員給与／法定福利費用／固定費(内容=固定費)／竹内交通費）を削除
 *   ステップ2 … 今回のデータ90件を登録
 *
 * 士業顧問料・家賃・通信・駐車場・柴田・表木・SNAP・ラネット・アメーバ・
 * 役職者給・特別インセンティブ費 などは削除せずそのまま残します。
 *
 * 削除は「年・月・項目・内容・金額」が完全一致した行だけを対象にします。
 * 1つでも違えば削除されず「見つかりませんでした」と表示されるため、
 * 想定外の行を消してしまうことはありません。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して
 *         「① 重複行を削除」→「② 90件を登録」の順に押す。
 *         終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db   = getDB();
$cid  = getCompanyId();
$csrf = getCsrfToken();

// 対象月 [年, 月]
$MONTHS = [
    [2025, 9], [2025, 10], [2025, 11], [2025, 12],
    [2026, 1], [2026, 2], [2026, 3], [2026, 4], [2026, 7],
];

// ============================================================
// ステップ1: 削除する既存行  列: 0年 1月 2項目 3内容 4金額
//   今回登録する 給与／法定福利費／固定費／旅費交通費 と中身が重複するもの
// ============================================================
$DELETE = [
    // ── 2025年9月 ──
    [2025, 9, '交通費',     '9月分竹内交通費',  169454],
    [2025, 9, '固定費',     '固定費',           743159],
    [2025, 9, '正社員給与', '給与',            1438143],
    [2025, 9, '法定福利費用','法定福利費',       486574],

    // ── 2025年10月 ──
    [2025, 10, '交通費',     '10月分竹内交通費', 127852],
    [2025, 10, '固定費',     '固定費',           357543],
    [2025, 10, '正社員給与', '給与',            1468456],
    [2025, 10, '法定福利費用','法定福利費',       508408],
    [2025, 10, '法定福利費用','法定福利費',       100000],

    // ── 2025年11月 ──
    [2025, 11, '交通費',     '11月分竹内交通費', 158995],
    [2025, 11, '固定費',     '固定費',          1019447],
    [2025, 11, '正社員給与', '正社員給与',      1453628],
    [2025, 11, '法定福利費用','法定福利費',       486764],

    // ── 2025年12月 ──
    [2025, 12, '交通費',     '12月分竹内交通費', 116870],
    [2025, 12, '固定費',     '固定費',           540308],
    [2025, 12, '正社員給与', '正社員給与',      1676364],
    [2025, 12, '法定福利費用','法定福利費',        48278],

    // ── 2026年1月 ──
    [2026, 1, '交通費',     '1月分竹内交通費',  108079],
    [2026, 1, '固定費',     '固定費',           355358],
    [2026, 1, '正社員給与', '正社員給与',      1482713],
    [2026, 1, '法定福利費用','法定福利費',       487054],

    // ── 2026年2月 ──（この月に「固定費／固定費」は無い）
    [2026, 2, '固定費',     '2月分竹内交通費',   84365],
    [2026, 2, '正社員給与', '正社員給与',      1509005],
    [2026, 2, '法定福利費用','法定福利費',       508830],

    // ── 2026年3月 ──（4月の数字が入っているとみられる行。正しい3月の数字に置き換わる）
    [2026, 3, '固定費',     '3月分竹内交通費',   79827],
    [2026, 3, '固定費',     '固定費',           661384],
    [2026, 3, '正社員給与', '正社員給与',       521504],
    [2026, 3, '法定福利費用','法定福利費',       215944],

    // ── 2026年4月 ──
    [2026, 4, '固定費',     '4月分竹内交通費',   79827],
    [2026, 4, '固定費',     '固定費',           661384],
    [2026, 4, '正社員給与', '正社員給与',       521504],
    [2026, 4, '法定福利費用','法定福利費',       215944],

    // ── 2026年7月 ──
    [2026, 7, '正社員給与', '佐藤7月分給与',    257324],
    [2026, 7, '正社員給与', '近藤7月分給与',    300000],
    [2026, 7, '法定福利費', '近藤社会保険等',    60000],
];

// ============================================================
// ステップ2: 登録するデータ  列: 0年 1月 2項目 3内容 4金額
// ============================================================
$DATA = [
    // ── 2025年9月 ──
    [2025, 9, '給与', '上田',   39036],
    [2025, 9, '給与', '近藤',  320915],
    [2025, 9, '給与', '佐藤',  252318],
    [2025, 9, '給与', '小栗',  254358],
    [2025, 9, '給与', '倉地',  338789],
    [2025, 9, '給与', '橋本',  271763],
    [2025, 9, '法定福利費',   '月次合計',  508862],
    [2025, 9, '旅費交通費',   '月次合計',  118630],
    [2025, 9, '接待交際費',   '月次合計', 1002331],
    [2025, 9, '会議費',       '月次合計',  449606],
    [2025, 9, '固定費',       '月次合計',  618159],

    // ── 2025年10月 ──
    [2025, 10, '給与', '上田',   39039],
    [2025, 10, '給与', '近藤',  303505],
    [2025, 10, '給与', '佐藤',  223563],
    [2025, 10, '給与', '小栗',  273617],
    [2025, 10, '給与', '倉地',  356969],
    [2025, 10, '給与', '橋本',  271763],
    [2025, 10, '法定福利費',   '月次合計',  508862],
    [2025, 10, '旅費交通費',   '月次合計',   81690],
    [2025, 10, '接待交際費',   '月次合計',  252600],
    [2025, 10, '会議費',       '月次合計',  196284],
    [2025, 10, '固定費',       '月次合計',  643543],

    // ── 2025年11月 ──
    [2025, 11, '給与', '上田',   39039],
    [2025, 11, '給与', '近藤',  303505],
    [2025, 11, '給与', '佐藤',  242743],
    [2025, 11, '給与', '小栗',  269443],
    [2025, 11, '給与', '倉地',  366174],
    [2025, 11, '給与', '橋本',  271763],
    [2025, 11, '法定福利費',   '月次合計',  508862],
    [2025, 11, '旅費交通費',   '月次合計',  513787],
    [2025, 11, '接待交際費',   '月次合計',  129021],
    [2025, 11, '会議費',       '月次合計',  201692],
    [2025, 11, '固定費',       '月次合計',  627465],

    // ── 2025年12月 ──
    [2025, 12, '給与', '上田',   39039],
    [2025, 12, '給与', '近藤',  328773],
    [2025, 12, '給与', '佐藤',  270588],
    [2025, 12, '給与', '小栗',  351471],
    [2025, 12, '給与', '倉地',  421739],
    [2025, 12, '給与', '橋本',  303793],
    [2025, 12, '法定福利費',   '月次合計',  508862],
    [2025, 12, '旅費交通費',   '月次合計',  293596],
    [2025, 12, '接待交際費',   '月次合計', 1435209],
    [2025, 12, '会議費',       '月次合計',  201692],
    [2025, 12, '固定費',       '月次合計',  649582],

    // ── 2026年1月 ──
    [2026, 1, '給与', '上田',   39039],
    [2026, 1, '給与', '近藤',  302167],
    [2026, 1, '給与', '佐藤',  263228],
    [2026, 1, '給与', '小栗',  295521],
    [2026, 1, '給与', '倉地',  349604],
    [2026, 1, '給与', '橋本',  272193],
    [2026, 1, '法定福利費',   '月次合計',  508862],
    [2026, 1, '旅費交通費',   '月次合計',  293596],
    [2026, 1, '接待交際費',   '月次合計',   80743],
    [2026, 1, '会議費',       '月次合計',  125491],
    [2026, 1, '固定費',       '月次合計',  641358],

    // ── 2026年2月（旅費交通費 0円は登録しない） ──
    [2026, 2, '給与', '上田',   39039],
    [2026, 2, '給与', '近藤',  303301],
    [2026, 2, '給与', '佐藤',  258365],
    [2026, 2, '給与', '小栗',  286503],
    [2026, 2, '給与', '倉地',  349604],
    [2026, 2, '給与', '橋本',  272193],
    [2026, 2, '法定福利費',   '月次合計',  508862],
    [2026, 2, '接待交際費',   '月次合計', 1010378],
    [2026, 2, '会議費',       '月次合計',   61527],
    [2026, 2, '固定費',       '月次合計',  655965],

    // ── 2026年3月（旅費交通費 0円は登録しない） ──
    [2026, 3, '給与', '上田',   39068],
    [2026, 3, '給与', '近藤',  271613],
    [2026, 3, '給与', '佐藤',  248980],
    [2026, 3, '給与', '小栗',  298994],
    [2026, 3, '給与', '倉地',  349809],
    [2026, 3, '給与', '橋本',  272353],
    [2026, 3, '法定福利費',   '月次合計',  508732],
    [2026, 3, '接待交際費',   '月次合計',  607212],
    [2026, 3, '会議費',       '月次合計',  179934],
    [2026, 3, '固定費',       '月次合計',  661202],

    // ── 2026年4月（旅費交通費 0円は登録しない） ──
    [2026, 4, '給与', '上田',   39002],
    [2026, 4, '給与', '近藤',  247333],
    [2026, 4, '給与', '佐藤',  235169],
    [2026, 4, '法定福利費',     '月次合計', 216546],
    [2026, 4, '接待交際費',     '月次合計',  97185],
    [2026, 4, '会議費',         '月次合計', 235602],
    [2026, 4, '備品・消耗品費', '月次合計',   5280],
    [2026, 4, '固定費',         '月次合計', 661384],

    // ── 2026年7月（「交通費」→旅費交通費、「その他経費」→経費） ──
    [2026, 7, '給与', '上田',   39002],
    [2026, 7, '給与', '近藤',  227457],
    [2026, 7, '給与', '佐藤',  257324],
    [2026, 7, '法定福利費', '月次合計', 204554],
    [2026, 7, '旅費交通費', '月次合計', 200274],
    [2026, 7, '固定費',     '月次合計', 937037],
    [2026, 7, '経費',       'その他経費', 519250],
];

// ── 対象9か月の既存行を取得 ──
$monthCond = []; $monthArgs = [$cid];
foreach ($MONTHS as [$y, $m]) { $monthCond[] = '(target_year = ? AND target_month = ?)'; $monthArgs[] = $y; $monthArgs[] = $m; }
$exSql = "SELECT id, target_year, target_month, category, content, amount, expense_type
    FROM sga_expenses WHERE company_id = ? AND (" . implode(' OR ', $monthCond) . ")
    ORDER BY target_year, target_month, category, content, id";

$loadExisting = function () use ($db, $exSql, $monthArgs) {
    $st = $db->prepare($exSql); $st->execute($monthArgs); return $st->fetchAll();
};
$existing = $loadExisting();

// ── 削除対象の突き合わせ（年・月・項目・内容・金額が完全一致した行だけ） ──
$matchDeletes = function (array $rows) use ($DELETE) {
    $used = []; $found = []; $missing = [];
    foreach ($DELETE as $d) {
        [$y, $m, $cat, $con, $amt] = $d;
        $hit = null;
        foreach ($rows as $r) {
            if (isset($used[$r['id']])) continue;
            if ((int)$r['target_year'] === $y && (int)$r['target_month'] === $m
                && trim($r['category']) === $cat && trim($r['content']) === $con
                && (int)$r['amount'] === $amt) { $hit = $r; break; }
        }
        if ($hit) { $used[$hit['id']] = true; $found[] = $hit + ['_spec' => $d]; }
        else      { $missing[] = $d; }
    }
    return [$found, $missing, $used];
};
[$delFound, $delMissing, $delIds] = $matchDeletes($existing);

$msgDeleted = null; $msgInserted = null; $skipped = []; $failed = [];

// ── 実行 ──
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {

    if ($action === 'delete') {
        $n = 0;
        foreach ($delFound as $r) {
            try { deleteSgaExpense((int)$r['id'], $cid); $n++; }
            catch (Throwable $e) { $failed[] = sprintf('削除 %d年%d月 %s／%s — %s',
                $r['target_year'], $r['target_month'], $r['category'], $r['content'], $e->getMessage()); }
        }
        $msgDeleted = $n;
        $existing = $loadExisting();
        [$delFound, $delMissing, $delIds] = $matchDeletes($existing);

    } elseif ($action === 'insert') {
        // 念のため、年月・項目・内容が同じ行があればスキップ
        $exactMap = [];
        foreach ($existing as $e) {
            $exactMap[$e['target_year'] . '-' . (int)$e['target_month'] . '-' . trim($e['category']) . '-' . trim($e['content'])] = true;
        }
        $n = 0;
        foreach ($DATA as $d) {
            [$y, $m, $category, $content, $amount] = $d;
            if (isset($exactMap[$y . '-' . $m . '-' . $category . '-' . $content])) {
                $skipped[] = sprintf('%d年%d月 %s／%s', $y, $m, $category, $content);
                continue;
            }
            try {
                createSgaExpense($cid, [
                    'target_year'  => $y,   'target_month' => $m,
                    'category'     => $category, 'content' => $content,
                    'amount'       => $amount, 'note' => '', 'expense_type' => 'sga',
                ]);
                $n++;
            } catch (Throwable $e) {
                $failed[] = sprintf('登録 %d年%d月 %s／%s — %s', $y, $m, $category, $content, $e->getMessage());
            }
        }
        $msgInserted = $n;
        $existing = $loadExisting();
        [$delFound, $delMissing, $delIds] = $matchDeletes($existing);
    }
}

// ── 集計 ──
$keepRows = [];
foreach ($existing as $r) { if (!isset($delIds[$r['id']])) $keepRows[] = $r; }

$planByMonth = []; $grandTotal = 0;
foreach ($DATA as $d) { $planByMonth[$d[0] . '-' . $d[1]][] = $d; $grandTotal += $d[4]; }
$exByMonth = [];
foreach ($existing as $r) { $exByMonth[$r['target_year'] . '-' . (int)$r['target_month']][] = $r; }
$delByMonth = [];
foreach ($delFound as $r) { $delByMonth[$r['target_year'] . '-' . (int)$r['target_month']][] = $r; }

// 今回の90件が既に入っているか（＝ステップ2が済んでいるか）
$insertedAlready = 0;
$exactMap = [];
foreach ($existing as $e) { $exactMap[$e['target_year'] . '-' . (int)$e['target_month'] . '-' . trim($e['category']) . '-' . trim($e['content'])] = true; }
foreach ($DATA as $d) { if (isset($exactMap[$d[0] . '-' . $d[1] . '-' . $d[2] . '-' . $d[3]])) $insertedAlready++; }

$step1Done = (count($delFound) === 0);
$fmt = fn($n) => number_format((int)$n);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>支出管理 データ投入（2025年9月〜2026年4月・2026年7月）</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1140px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.78rem; white-space:nowrap; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
.step { border-left:5px solid #0d6efd; }
.step.done { border-left-color:#198754; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff me-2"></i>支出管理 データ投入</h4>
<p class="text-muted small mb-4">2025年9月〜2026年4月・2026年7月／方針: この9か月は今回の数字で統一（案A）</p>

<?php if ($msgDeleted !== null): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><strong><?= $msgDeleted ?>件の重複行を削除しました。</strong> 続けて下の「② 90件を登録」に進んでください。</div>
<?php endif; ?>
<?php if ($msgInserted !== null): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><strong><?= $msgInserted ?>件を登録しました。</strong></div>
<?php endif; ?>
<?php if ($skipped): ?>
  <div class="alert alert-secondary small"><strong><?= count($skipped) ?>件はスキップしました</strong>（同じ年月・項目・内容が既にあるため）<div class="mt-1"><?= h(implode('、', $skipped)) ?></div></div>
<?php endif; ?>
<?php if ($failed): ?>
  <div class="alert alert-danger small"><strong><?= count($failed) ?>件でエラーが出ました</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- ============ ステップ1 ============ -->
<div class="card mb-4 step <?= $step1Done ? 'done' : '' ?>">
  <div class="card-header bg-white fw-bold">
    <?= $step1Done ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '<i class="bi bi-1-circle me-1"></i>' ?>
    ステップ1: 重複する既存行を削除
    <span class="badge <?= $step1Done ? 'bg-success' : 'bg-danger' ?> ms-2">残り <?= count($delFound) ?>件</span>
  </div>
  <div class="card-body">

  <?php if ($step1Done): ?>
    <div class="text-success small mb-0"><i class="bi bi-check2 me-1"></i>削除対象は残っていません。ステップ2に進めます。</div>
  <?php else: ?>
    <div class="small text-muted mb-2">
      今回登録する <strong>給与／法定福利費／固定費／旅費交通費</strong> と中身が重なる行です。
      年・月・項目・内容・金額が<strong>すべて一致した行だけ</strong>を削除します。
    </div>
    <div class="table-responsive" style="max-height:44vh">
      <table class="table table-sm table-bordered bg-white mb-0">
        <thead class="table-danger" style="position:sticky;top:0"><tr>
          <th>#</th><th>対象月</th><th>項目</th><th>内容</th><th class="num">金額</th><th>削除する理由</th>
        </tr></thead>
        <tbody>
        <?php foreach ($delFound as $i => $r):
          $cat = trim($r['category']);
          $why = $cat === '正社員給与'   ? '今回の「給与（1人1行）」と重複'
               : ($cat === '法定福利費用' || $cat === '法定福利費' ? '今回の「法定福利費」と重複'
               : (mb_strpos(trim($r['content']), '竹内交通費') !== false ? '今回の「旅費交通費」と重複'
               : '今回の「固定費」と重複')); ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= (int)$r['target_year'] ?>年<?= (int)$r['target_month'] ?>月</td>
            <td class="fw-medium"><?= h($r['category']) ?></td>
            <td><?= h($r['content']) ?></td>
            <td class="num"><?= $fmt($r['amount']) ?></td>
            <td class="text-muted"><?= h($why) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($delMissing): ?>
    <div class="alert alert-warning small mt-3 mb-0">
      <strong>次の<?= count($delMissing) ?>件は見つかりませんでした</strong>（金額や文字が画面確認時と変わっている可能性があります）。この分は<strong>削除されません</strong>。
      <ul class="mb-0 mt-1">
        <?php foreach ($delMissing as $d): ?>
          <li><?= $d[0] ?>年<?= $d[1] ?>月 <?= h($d[2]) ?>／<?= h($d[3]) ?> <?= $fmt($d[4]) ?>円</li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <form method="post" class="mt-3" onsubmit="return confirm('<?= count($delFound) ?>件を削除します。よろしいですか？');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>① 重複する<?= count($delFound) ?>件を削除する</button>
    </form>
  <?php endif; ?>

  </div>
</div>

<!-- ============ 残す既存行 ============ -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-shield-check me-1 text-success"></i>そのまま残す既存行 <span class="badge bg-secondary ms-1"><?= count($keepRows) ?>件</span></div>
  <div class="card-body">
    <div class="small text-muted mb-2">士業顧問料・家賃・通信・駐車場・柴田・表木・SNAP・ラネット・アメーバ・役職者給・特別インセンティブ費 などは触りません。</div>
    <div class="table-responsive" style="max-height:34vh">
      <table class="table table-sm table-bordered bg-white mb-0">
        <thead class="table-light" style="position:sticky;top:0"><tr><th>対象月</th><th>項目</th><th>内容</th><th class="num">金額</th></tr></thead>
        <tbody>
        <?php foreach ($keepRows as $r): ?>
          <tr>
            <td><?= (int)$r['target_year'] ?>年<?= (int)$r['target_month'] ?>月</td>
            <td class="fw-medium"><?= h($r['category']) ?></td>
            <td><?= h($r['content']) ?></td>
            <td class="num"><?= $fmt($r['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============ ステップ2 ============ -->
<div class="card mb-4 step <?= $insertedAlready >= count($DATA) ? 'done' : '' ?>">
  <div class="card-header bg-white fw-bold">
    <?= $insertedAlready >= count($DATA) ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '<i class="bi bi-2-circle me-1"></i>' ?>
    ステップ2: 今回のデータ<?= count($DATA) ?>件を登録
    <span class="badge bg-secondary ms-2">登録済み <?= $insertedAlready ?>/<?= count($DATA) ?></span>
  </div>
  <div class="card-body">

    <div class="alert alert-info small mb-3">
      ・<strong><?= count($DATA) ?>件</strong>を登録（区分はすべて<strong>販管費</strong>）／合計 <strong><?= $fmt($grandTotal) ?>円</strong><br>
      ・給与は <strong>項目=給与 ／ 内容=氏名</strong> で1人1行<br>
      ・法定福利費は<strong>「法定福利費」</strong>に統一（「法定福利費用」は使わない）<br>
      ・2026年7月の「交通費」は<strong>旅費交通費</strong>、「その他経費」は<strong>項目=経費／内容=その他経費</strong><br>
      ・金額0円（備品・消耗品費 9〜3月／旅費交通費 2・3・4月）は登録しません<br>
      ・給与以外は内容を「月次合計」としています
    </div>

    <table class="table table-sm table-bordered bg-white w-auto mb-3">
      <thead class="table-light"><tr><th>対象月</th><th class="num">登録件数</th><th class="num">うち給与</th><th class="num">合計金額</th><th class="num">この月の既存</th></tr></thead>
      <tbody>
      <?php foreach ($MONTHS as [$y, $m]):
        $rows = $planByMonth[$y . '-' . $m] ?? []; $t = 0; $sal = 0;
        foreach ($rows as $r) { $t += $r[4]; if ($r[2] === '給与') $sal += $r[4]; } ?>
        <tr>
          <td><?= $y ?>年<?= $m ?>月</td>
          <td class="num"><?= count($rows) ?></td>
          <td class="num"><?= $fmt($sal) ?></td>
          <td class="num fw-bold"><?= $fmt($t) ?></td>
          <td class="num text-muted"><?= count($exByMonth[$y . '-' . $m] ?? []) ?>件（削除対象 <?= count($delByMonth[$y . '-' . $m] ?? []) ?>）</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light"><tr><th>合計</th><th class="num"><?= count($DATA) ?></th><th></th><th class="num"><?= $fmt($grandTotal) ?></th><th></th></tr></tfoot>
    </table>

    <div class="table-responsive mb-3" style="max-height:40vh">
      <table class="table table-sm table-hover bg-white mb-0">
        <thead class="table-light" style="position:sticky;top:0"><tr><th>#</th><th>対象月</th><th>項目</th><th>内容</th><th class="num">金額</th></tr></thead>
        <tbody>
        <?php foreach ($DATA as $i => $d): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><?= $d[0] ?>年<?= $d[1] ?>月</td>
            <td class="fw-medium"><?= h($d[2]) ?></td>
            <td><?= h($d[3]) ?></td>
            <td class="num"><?= $fmt($d[4]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$step1Done): ?>
      <div class="alert alert-warning small mb-0">
        <i class="bi bi-exclamation-triangle me-1"></i>先に<strong>ステップ1の削除</strong>を実行してください。削除前に登録すると二重計上になります。
      </div>
      <button class="btn btn-success btn-lg mt-2" disabled><i class="bi bi-check-circle me-1"></i>② <?= count($DATA) ?>件を登録する</button>
    <?php else: ?>
      <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="insert">
        <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>② <?= count($DATA) ?>件を登録する</button>
      </form>
    <?php endif; ?>

  </div>
</div>

<!-- ============ 実行後の状態 ============ -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-calculator me-1"></i>いまの各月の支出合計</div>
  <div class="card-body">
    <table class="table table-sm table-bordered bg-white w-auto mb-0">
      <thead class="table-light"><tr><th>対象月</th><th class="num">件数</th><th class="num">合計金額</th></tr></thead>
      <tbody>
      <?php $gt = 0; foreach ($MONTHS as [$y, $m]): $rows = $exByMonth[$y . '-' . $m] ?? []; $t = 0;
        foreach ($rows as $r) { $t += (int)$r['amount']; } $gt += $t; ?>
        <tr><td><?= $y ?>年<?= $m ?>月</td><td class="num"><?= count($rows) ?></td><td class="num"><?= $fmt($t) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light"><tr><th>合計</th><th class="num"><?= count($existing) ?></th><th class="num"><?= $fmt($gt) ?></th></tr></tfoot>
    </table>
  </div>
</div>

<a href="<?= BASE_PATH ?>/public/sga_management.php?year=2026&month=7" class="btn btn-primary">支出管理を開く</a>
<div class="alert alert-warning mt-3 mb-0 small">
  <i class="bi bi-exclamation-triangle me-1"></i>両方のステップが終わったら、このページ（import_sga_202509_202607.php）は削除してください。
</div>

</div>
</body>
</html>
