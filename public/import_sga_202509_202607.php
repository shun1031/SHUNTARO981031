<?php
/**
 * 【一度だけ実行するスクリプト】支出管理 データ投入
 *   対象: 2025年9月〜2026年4月 ／ 2026年7月（計9か月）
 *
 * 支出管理（sga_expenses）に、給与・法定福利費・旅費交通費・接待交際費・会議費・
 * 備品消耗品費・固定費・経費を、区分＝販管費 として登録します。
 *
 * 決定事項（ユーザー確認済み）:
 *   ① 給与は 項目=給与 ／ 内容=氏名 で1人1行
 *   ② 「法定福利費用」ではなく「法定福利費」に統一
 *   ③ 2026年7月の「交通費」→ 旅費交通費、「その他経費」→ 経費（内容にその他経費と記載）
 *   ④ 金額0円の行（備品・消耗品費 9〜3月／旅費交通費 2・3・4月）は登録しない
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
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

// 列: 0年 1月 2項目 3内容 4金額
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

// ── いま対象月に入っている支出（二重登録チェック用） ──
$monthCond = [];
$monthArgs = [$cid];
foreach ($MONTHS as [$y, $m]) { $monthCond[] = '(target_year = ? AND target_month = ?)'; $monthArgs[] = $y; $monthArgs[] = $m; }
$exStmt = $db->prepare("SELECT id, target_year, target_month, category, content, amount, expense_type
    FROM sga_expenses WHERE company_id = ? AND (" . implode(' OR ', $monthCond) . ")
    ORDER BY target_year, target_month, category, content");
$exStmt->execute($monthArgs);
$existing = $exStmt->fetchAll();

$exactMap = [];   // 年-月-項目-内容 が完全一致（＝二重登録になる）
$catMap   = [];   // 年-月-項目 が一致（内容違い。目視確認用）
$exByMonth = [];  // 月ごとの既存行
foreach ($existing as $e) {
    $k = $e['target_year'] . '-' . (int)$e['target_month'];
    $exactMap[$k . '-' . trim($e['category']) . '-' . trim($e['content'])] = $e;
    $catMap[$k . '-' . trim($e['category'])][] = $e;
    $exByMonth[$k][] = $e;
}

$done = false; $created = 0; $skipped = []; $failed = [];

// ── 登録実行 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    foreach ($DATA as $d) {
        [$y, $m, $category, $content, $amount] = $d;
        $key = $y . '-' . $m . '-' . $category . '-' . $content;
        if (!$includeDup && isset($exactMap[$key])) {
            $skipped[] = sprintf('%d年%d月 %s／%s', $y, $m, $category, $content);
            continue;
        }
        try {
            createSgaExpense($cid, [
                'target_year'  => $y,
                'target_month' => $m,
                'category'     => $category,
                'content'      => $content,
                'amount'       => $amount,
                'note'         => '',
                'expense_type' => 'sga',
            ]);
            $created++;
        } catch (Throwable $e) {
            $failed[] = sprintf('%d年%d月 %s／%s — %s', $y, $m, $category, $content, $e->getMessage());
        }
    }
    $done = true;
    // 実行後の状態を取り直す
    $exStmt->execute($monthArgs);
    $existing = $exStmt->fetchAll();
    $exByMonth = [];
    foreach ($existing as $e) { $exByMonth[$e['target_year'] . '-' . (int)$e['target_month']][] = $e; }
}

// ── 確認用の集計 ──
$planByMonth = [];   // 月ごとの投入予定行
$dupRows = 0; $grandTotal = 0;
foreach ($DATA as $d) {
    [$y, $m, $category, $content, $amount] = $d;
    $planByMonth[$y . '-' . $m][] = $d;
    $grandTotal += $amount;
    if (isset($exactMap[$y . '-' . $m . '-' . $category . '-' . $content])) $dupRows++;
}
// 年月＋項目が既にあるが内容が違う（目視確認してほしい組み合わせ）
$catWarn = [];
foreach ($DATA as $d) {
    [$y, $m, $category, $content] = $d;
    $ck = $y . '-' . $m . '-' . $category;
    if (isset($catMap[$ck]) && !isset($exactMap[$ck . '-' . $content])) {
        $catWarn[$ck] = $catMap[$ck];
    }
}
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
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-receipt-cutoff me-2"></i>支出管理 データ投入（2025年9月〜2026年4月・2026年7月）</h4>

<?php if ($done): ?>

  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $created ?>件を登録しました</div>
  </div>

  <?php if ($skipped): ?>
  <div class="alert alert-secondary small">
    <strong><?= count($skipped) ?>件はスキップしました</strong>（同じ年月・項目・内容が既に登録されていたため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="alert alert-danger small">
    <strong><?= count($failed) ?>件は登録できませんでした</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">実行後の各月の支出合計</div>
    <table class="table table-sm mb-0 w-auto">
      <thead class="table-light"><tr><th>対象月</th><th class="num">件数</th><th class="num">合計金額</th></tr></thead>
      <tbody>
      <?php foreach ($MONTHS as [$y, $m]): $rows = $exByMonth[$y . '-' . $m] ?? []; $t = 0; foreach ($rows as $r) { $t += (int)$r['amount']; } ?>
        <tr><td><?= $y ?>年<?= $m ?>月</td><td class="num"><?= count($rows) ?></td><td class="num"><?= $fmt($t) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <a href="<?= BASE_PATH ?>/public/sga_management.php?year=2026&month=7" class="btn btn-primary">支出管理を確認する</a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_sga_202509_202607.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
  </div>

<?php else: ?>

  <!-- ① 現状 -->
  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">
      いま対象の9か月に登録されている支出：<span class="badge bg-secondary"><?= count($existing) ?>件</span>
    </div>
    <?php if ($existing): ?>
      <table class="table table-sm table-bordered bg-white">
        <thead class="table-light"><tr><th>対象月</th><th>項目</th><th>内容</th><th class="num">金額</th><th>区分</th></tr></thead>
        <tbody>
        <?php foreach ($existing as $e): ?>
          <tr>
            <td><?= (int)$e['target_year'] ?>年<?= (int)$e['target_month'] ?>月</td>
            <td class="fw-medium"><?= h($e['category']) ?></td>
            <td><?= h($e['content']) ?></td>
            <td class="num"><?= $fmt($e['amount']) ?></td>
            <td><?= ($e['expense_type'] ?? 'sga') === 'cost' ? '原価' : '販管費' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="small text-muted">この9か月にはまだ1件も登録されていません。</div>
    <?php endif; ?>
  </div></div>

  <!-- ② 重複警告 -->
  <?php if ($dupRows): ?>
  <div class="alert alert-warning">
    <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>
      これから登録する<?= count($DATA) ?>件のうち<?= $dupRows ?>件は、年月・項目・内容がまったく同じ行が既にあります
    </div>
    <div class="small mt-1">
      二重登録を防ぐため、既定では<strong>その<?= $dupRows ?>件をスキップ</strong>し、
      <strong><?= count($DATA) - $dupRows ?>件</strong>だけ登録します（下の表で「既存」バッジが付いた行）。
    </div>
  </div>
  <?php endif; ?>

  <?php if ($catWarn): ?>
  <div class="alert alert-danger">
    <div class="fw-bold"><i class="bi bi-exclamation-octagon me-1"></i>要確認：同じ月に同じ項目が別の内容で既に入っています</div>
    <div class="small mt-1">
      内容の文字が違うため自動ではスキップされません。<strong>同じ費用が二重に計上されないか、下の内容を必ず目視で確認してください。</strong>
    </div>
    <table class="table table-sm mt-2 mb-0 bg-white w-auto">
      <thead class="table-light"><tr><th>対象月</th><th>項目</th><th>既存の内容</th><th class="num">既存の金額</th></tr></thead>
      <tbody>
      <?php foreach ($catWarn as $ck => $rows): foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['target_year'] ?>年<?= (int)$r['target_month'] ?>月</td>
          <td class="fw-medium"><?= h($r['category']) ?></td>
          <td><?= h($r['content']) ?></td>
          <td class="num"><?= $fmt($r['amount']) ?></td>
        </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ③ 登録内容の説明 -->
  <div class="alert alert-info">
    <div class="fw-semibold">登録する内容</div>
    <div class="small mt-1" style="line-height:1.9">
      ・<strong><?= count($DATA) ?>件</strong>を支出管理に登録（区分はすべて<strong>販管費</strong>／原価では登録しません）<br>
      ・合計 <strong><?= $fmt($grandTotal) ?>円</strong>（9か月ぶん）<br>
      ・給与は <strong>項目=給与 ／ 内容=氏名</strong> で1人1行<br>
      ・法定福利費は「法定福利費用」ではなく<strong>「法定福利費」</strong>で統一<br>
      ・2026年7月の「交通費」は<strong>旅費交通費</strong>、「その他経費」は<strong>項目=経費／内容=その他経費</strong>として登録<br>
      ・金額0円（備品・消耗品費 9〜3月／旅費交通費 2・3・4月）は<strong>登録しません</strong><br>
      ・給与以外の項目は内容を「月次合計」としています（月の合計額1行という意味）
    </div>
  </div>

  <!-- ④ 月別サマリー -->
  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">月別の登録予定</div>
    <table class="table table-sm table-bordered bg-white w-auto mb-0">
      <thead class="table-light"><tr><th>対象月</th><th class="num">登録件数</th><th class="num">うち給与</th><th class="num">合計金額</th><th class="num">既存件数</th></tr></thead>
      <tbody>
      <?php $sumRows = 0; foreach ($MONTHS as [$y, $m]):
        $rows = $planByMonth[$y . '-' . $m] ?? []; $t = 0; $sal = 0;
        foreach ($rows as $r) { $t += $r[4]; if ($r[2] === '給与') $sal += $r[4]; }
        $sumRows += count($rows); ?>
        <tr>
          <td><?= $y ?>年<?= $m ?>月</td>
          <td class="num"><?= count($rows) ?></td>
          <td class="num"><?= $fmt($sal) ?></td>
          <td class="num fw-bold"><?= $fmt($t) ?></td>
          <td class="num text-muted"><?= count($exByMonth[$y . '-' . $m] ?? []) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light"><tr>
        <th>合計</th><th class="num"><?= $sumRows ?></th><th></th><th class="num"><?= $fmt($grandTotal) ?></th><th></th>
      </tr></tfoot>
    </table>
  </div></div>

  <!-- ⑤ 全行 -->
  <div class="table-responsive mb-3" style="max-height:52vh">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr><th>#</th><th>対象月</th><th>項目</th><th>内容</th><th class="num">金額</th><th>区分</th></tr>
      </thead>
      <tbody>
      <?php foreach ($DATA as $i => $d): [$y, $m, $category, $content, $amount] = $d;
        $isDup = isset($exactMap[$y . '-' . $m . '-' . $category . '-' . $content]); ?>
        <tr class="<?= $isDup ? 'table-warning' : '' ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= $y ?>年<?= $m ?>月</td>
          <td class="fw-medium"><?= h($category) ?></td>
          <td><?= h($content) ?><?= $isDup ? ' <span class="badge bg-danger" style="font-size:.6rem">既存</span>' : '' ?></td>
          <td class="num"><?= $fmt($amount) ?></td>
          <td class="text-muted">販管費</td>
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
        年月・項目・内容が同じ<?= $dupRows ?>件も登録する（二重登録になります。通常はチェックしないでください）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sga_management.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
