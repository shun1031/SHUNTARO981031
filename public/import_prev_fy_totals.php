<?php
/**
 * 【一度だけ実行するスクリプト】前期（2024年9月〜2025年8月）の月次実績を登録
 *
 * ダッシュボードの「前年同月売上の手入力テーブル」（sales_prev_year_revenues）に、
 * 前期の常勤・イベントの月次 売上／粗利 を登録します。
 *
 * このテーブルは案件データ（sales_cases）とは別物です。案件は1件も作りません。
 *   - 読んでいるのは public/sales_dashboard.php だけ
 *     （総合／常勤／イベントの3ダッシュボードは同じファイル）
 *   - 案件一覧・営業マン別売上・取引先別売上・戦略会議・請求書・給与 などは参照しない
 *   - 案件データがある月は案件データが優先され、無い月だけこの数字が使われる
 *
 * 登録するのは9か月ぶん（2024/9〜2025/4 と 2025/8）。
 * 2025年5・6・7月は既に売上だけ登録済み（粗利0）なので、この画面では触りません。
 * 粗利が分かったら、別途この画面の「上書きする」で入れられます。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db   = getDB();
$cid  = getCompanyId();
$csrf = getCsrfToken();

// [年, 月, 常勤売上, 常勤粗利, イベント売上, イベント粗利]
$DATA = [
    [2024,  9,   4144390,  -2043283,    537710,   438950],
    [2024, 10,   8115120,   2256446,    634040,   234080],
    [2024, 11,   9390792,   3472864,    420000,   230000],
    [2024, 12,  10550505,   5487498,   1487500,  1180900],
    [2025,  1,  12814370,   5543928,   1117000,   597560],
    [2025,  2,  11849323,   5567704,    899320,   509880],
    [2025,  3,   9814864,   2402484,   1007500,   567100],
    [2025,  4,  14116676,   6343950,    490500,   348500],
    [2025,  8,  18660001,   6417529,  13616868,  3653818],
];

// ── いま前期に入っている手入力実績 ──
$exStmt = $db->prepare("SELECT case_type, year, month, revenue, profit
                        FROM sales_prev_year_revenues
                        WHERE company_id = ? AND ((year = 2024 AND month >= 9) OR (year = 2025 AND month <= 8))
                        ORDER BY year, month, case_type");
$exStmt->execute([$cid]);
$existing = $exStmt->fetchAll();
$exMap = [];
foreach ($existing as $e) { $exMap[$e['year'] . '-' . (int)$e['month'] . '-' . $e['case_type']] = $e; }

$done = false; $inserted = 0; $updatedRows = 0; $skipped = []; $failed = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $overwrite = !empty($_POST['overwrite']);
    foreach ($DATA as [$y, $m, $rRev, $rPro, $eRev, $ePro]) {
        foreach ([['regular', $rRev, $rPro], ['event', $eRev, $ePro]] as [$type, $rev, $pro]) {
            $key = $y . '-' . $m . '-' . $type;
            if (isset($exMap[$key]) && !$overwrite) {
                $skipped[] = sprintf('%d年%d月 %s', $y, $m, $type === 'regular' ? '常勤' : 'イベント');
                continue;
            }
            try {
                // 同じ 会社・種別・年・月 は UNIQUE なので、あれば更新・無ければ追加
                $st = $db->prepare("INSERT INTO sales_prev_year_revenues
                        (company_id, case_type, year, month, revenue, profit)
                        VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE revenue = VALUES(revenue), profit = VALUES(profit)");
                $st->execute([$cid, $type, $y, $m, $rev, $pro]);
                // rowCount: 追加=1 / 更新=2 / 変化なし=0
                if ($st->rowCount() >= 2) { $updatedRows++; } else { $inserted++; }
            } catch (Throwable $e) {
                $failed[] = sprintf('%d年%d月 %s — %s', $y, $m, $type, $e->getMessage());
            }
        }
    }
    $done = true;
    // 実行後の状態を取り直す
    $exStmt->execute([$cid]);
    $existing = $exStmt->fetchAll();
}

// ── 確認用の集計 ──
$sumRRev = $sumRPro = $sumERev = $sumEPro = 0;
$dupCount = 0;
foreach ($DATA as [$y, $m, $rRev, $rPro, $eRev, $ePro]) {
    $sumRRev += $rRev; $sumRPro += $rPro; $sumERev += $eRev; $sumEPro += $ePro;
    if (isset($exMap[$y . '-' . $m . '-regular'])) $dupCount++;
    if (isset($exMap[$y . '-' . $m . '-event']))   $dupCount++;
}
// 既存分（5-7月など、今回入れない月）
$otherRev = $otherPro = 0;
foreach ($existing as $e) {
    $k = $e['year'] . '-' . (int)$e['month'] . '-' . $e['case_type'];
    $inData = false;
    foreach ($DATA as [$y, $m]) { if ($y == $e['year'] && $m == (int)$e['month']) { $inData = true; break; } }
    if (!$inData) { $otherRev += (int)$e['revenue']; $otherPro += (int)$e['profit']; }
}
$fmt = fn($n) => number_format($n);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>前期（2024年9月〜2025年8月）の月次実績 登録</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1080px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.82rem; white-space:nowrap; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-calendar2-range me-2"></i>前期（2024年9月〜2025年8月）の月次実績 登録</h4>

<?php if ($done): ?>

  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i>
      <?= $inserted ?>件を追加<?= $updatedRows ? '、' . $updatedRows . '件を上書き' : '' ?>しました
    </div>
  </div>
  <?php if ($skipped): ?>
  <div class="alert alert-secondary small">
    <strong><?= count($skipped) ?>件はスキップしました</strong>（すでに登録済みのため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($failed): ?>
  <div class="alert alert-danger small"><strong>登録できなかったもの</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">いま前期に入っている月次実績：<?= count($existing) ?>行</div>
    <div class="table-responsive">
      <table class="table table-sm bg-white mb-0">
        <thead class="table-light"><tr><th>年月</th><th>種別</th><th class="num">売上</th><th class="num">粗利</th></tr></thead>
        <tbody>
        <?php foreach ($existing as $e): ?>
          <tr>
            <td><?= (int)$e['year'] ?>年<?= (int)$e['month'] ?>月</td>
            <td><?= $e['case_type'] === 'regular' ? '常勤' : 'イベント' ?></td>
            <td class="num"><?= $fmt((int)$e['revenue']) ?></td>
            <td class="num <?= (int)$e['profit'] < 0 ? 'text-danger fw-bold' : '' ?>"><?= $fmt((int)$e['profit']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>

  <a href="<?= BASE_PATH ?>/public/sales_dashboard.php?fy=2025" class="btn btn-primary">総合ダッシュボード（2024年度）を見る</a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_prev_fy_totals.php）は削除してください。
  </div>

<?php else: ?>

  <div class="alert alert-info">
    <div class="fw-semibold">この画面ですること</div>
    <div class="small mt-1" style="line-height:1.9">
      ・前期（2024年9月〜2025年8月）の<strong>月ごとの売上・粗利</strong>を、常勤／イベント別に登録します<br>
      ・登録先は<strong>ダッシュボード専用の手入力テーブル</strong>です。<strong>案件は1件も作りません</strong><br>
      ・案件一覧・営業マン別売上・取引先別売上・戦略会議・請求書・給与 には<strong>影響しません</strong><br>
      ・反映されるのは<strong>総合／常勤／イベントの3ダッシュボード</strong>だけです
        （年度の売上推移チャート・月別売上テーブル・常勤/イベント別の内訳・前年同月比のKPI）<br>
      ・案件データがある月は案件データが優先されるので、今期の表示は変わりません
    </div>
  </div>

  <?php if ($dupCount): ?>
  <div class="alert alert-warning">
    <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>
      これから登録する18行のうち<?= $dupCount ?>行は、すでに登録されています</div>
    <div class="small mt-1">既定では<strong>スキップ</strong>します。上書きしたい場合は下のチェックを入れてください。</div>
  </div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">いま前期に入っている月次実績：<?= count($existing) ?>行</div>
    <?php if ($existing): ?>
      <div class="small text-muted mb-2">2025年5・6・7月は売上だけ登録済みで、粗利は0です。この画面では触りません。</div>
      <div class="table-responsive">
        <table class="table table-sm bg-white mb-0">
          <thead class="table-light"><tr><th>年月</th><th>種別</th><th class="num">売上</th><th class="num">粗利</th></tr></thead>
          <tbody>
          <?php foreach ($existing as $e): ?>
            <tr><td><?= (int)$e['year'] ?>年<?= (int)$e['month'] ?>月</td>
                <td><?= $e['case_type'] === 'regular' ? '常勤' : 'イベント' ?></td>
                <td class="num"><?= $fmt((int)$e['revenue']) ?></td>
                <td class="num"><?= $fmt((int)$e['profit']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="small text-muted">まだ登録されていません。</div>
    <?php endif; ?>
  </div></div>

  <div class="alert alert-info">
    <div class="fw-semibold">登録する内容（9か月ぶん・18行）</div>
    <div class="small mt-1" style="line-height:1.9">
      ・常勤　　 売上合計 <strong><?= $fmt($sumRRev) ?>円</strong> ／ 粗利合計 <strong><?= $fmt($sumRPro) ?>円</strong>（<?= round($sumRPro / $sumRRev * 100, 1) ?>%）<br>
      ・イベント 売上合計 <strong><?= $fmt($sumERev) ?>円</strong> ／ 粗利合計 <strong><?= $fmt($sumEPro) ?>円</strong>（<?= round($sumEPro / $sumERev * 100, 1) ?>%）<br>
      ・すでに登録済みの5〜7月ぶんを足すと、前期の年度合計は
        売上 <strong><?= $fmt($sumRRev + $sumERev + $otherRev) ?>円</strong> ／
        粗利 <strong><?= $fmt($sumRPro + $sumEPro + $otherPro) ?>円</strong> になります<br>
      ・<strong>2024年9月の常勤は粗利がマイナス</strong>（−2,043,283円）です。元データどおりです<br>
      ・5〜7月の粗利が0のままなので、年度の粗利合計はその分少なく出ます
    </div>
  </div>

  <div class="table-responsive mb-3">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light">
        <tr>
          <th>年月</th>
          <th class="num">常勤 売上</th><th class="num">常勤 粗利</th><th class="num">率</th>
          <th class="num">イベント 売上</th><th class="num">イベント 粗利</th><th class="num">率</th>
          <th>状態</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($DATA as [$y, $m, $rRev, $rPro, $eRev, $ePro]):
        $dup = isset($exMap[$y . '-' . $m . '-regular']) || isset($exMap[$y . '-' . $m . '-event']); ?>
        <tr class="<?= $dup ? 'table-warning' : '' ?>">
          <td class="fw-medium"><?= $y ?>年<?= $m ?>月</td>
          <td class="num"><?= $fmt($rRev) ?></td>
          <td class="num <?= $rPro < 0 ? 'text-danger fw-bold' : '' ?>"><?= $fmt($rPro) ?></td>
          <td class="num text-muted"><?= $rRev ? round($rPro / $rRev * 100, 1) : 0 ?>%</td>
          <td class="num"><?= $fmt($eRev) ?></td>
          <td class="num"><?= $fmt($ePro) ?></td>
          <td class="num text-muted"><?= $eRev ? round($ePro / $eRev * 100, 1) : 0 ?>%</td>
          <td><?= $dup ? '<span class="badge bg-danger">登録済み</span>' : '<span class="badge bg-success">新規</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="fw-bold" style="background:#f0fdf4">
          <td>9か月 合計</td>
          <td class="num"><?= $fmt($sumRRev) ?></td>
          <td class="num"><?= $fmt($sumRPro) ?></td>
          <td class="num"><?= round($sumRPro / $sumRRev * 100, 1) ?>%</td>
          <td class="num"><?= $fmt($sumERev) ?></td>
          <td class="num"><?= $fmt($sumEPro) ?></td>
          <td class="num"><?= round($sumEPro / $sumERev * 100, 1) ?>%</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($dupCount): ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="ow">
      <label class="form-check-label small" for="ow">
        すでに登録されている<?= $dupCount ?>行も<strong>上書きする</strong>（既定はスキップ）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sales_dashboard.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
