<?php
/**
 * 【確認専用ページ・書き込みは一切しない】
 * 総合ダッシュボード「案件区分分析 → 1次の内訳」の【未設定】が
 * どの案件を指しているのかを一覧で確認する。
 *
 * ダッシュボードの計算（public/sales_dashboard.php の「案件区分別売上」）と
 * まったく同じ条件で集計しているので、円グラフの数字とここの数字は必ず一致する。
 *
 * 「未設定」の正体:
 *   区分が【1次】の【常勤案件】で、【予算区分】が
 *   「キャリア予算」でも「代理店予算」でもないもの（未入力・空）。
 *
 * このページはデータを一切変更しない。年度は ?fy=2026 のように指定できる
 * （fy=2026 は 2025年9月〜2026年8月）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// ── 年度（9月始まり）。ダッシュボードと同じ決め方 ──
$_today     = new DateTimeImmutable('today');
$_fyDefault = (int)$_today->format('n') >= 9 ? (int)$_today->format('Y') + 1 : (int)$_today->format('Y');
$fyYear = (int)($_GET['fy'] ?? $_fyDefault);
if ($fyYear < 2000 || $fyYear > 2100) $fyYear = $_fyDefault;
$fyParams = [$fyYear - 1, $fyYear];
$fyLabel  = ($fyYear - 1) . '-' . $fyYear . '年度（' . ($fyYear - 1) . '年9月〜' . $fyYear . '年8月）';

const CB_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";

// ============================================================
// ① ダッシュボードとまったく同じ集計を再現する
//    （円グラフの数字と一致するかを確認するため）
// ============================================================
$divRev = ['first'=>0,'second'=>0,'carrier'=>0,'agency'=>0,'event'=>0,'unset'=>0];
$divCnt = $divRev;
$stmt = $db->prepare("
    SELECT sc.case_division, sc.case_type, sc.budget_division,
           COALESCE(SUM(sc.revenue),0) AS rev, COUNT(*) AS cnt
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_division IN ('1次','2次以降')
      AND " . CB_FY_WHERE . "
    GROUP BY sc.case_division, sc.case_type, sc.budget_division");
$stmt->execute(array_merge([$cid], $fyParams));
foreach ($stmt->fetchAll() as $r) {
    $rev = (int)$r['rev']; $cnt = (int)$r['cnt'];
    if ($r['case_division'] !== '1次') { $divRev['second'] += $rev; $divCnt['second'] += $cnt; continue; }
    $divRev['first'] += $rev; $divCnt['first'] += $cnt;
    if ($r['case_type'] === 'event')                    $k = 'event';
    elseif ($r['budget_division'] === 'キャリア予算')   $k = 'carrier';
    elseif ($r['budget_division'] === '代理店予算')     $k = 'agency';
    else                                                $k = 'unset';
    $divRev[$k] += $rev; $divCnt[$k] += $cnt;
}

// ============================================================
// ② 「未設定」に入っている案件の明細
// ============================================================
$labelSql = clientLabelSql('cl');
$detStmt = $db->prepare("
    SELECT sc.id, sc.case_year, sc.case_month, sc.case_type,
           sc.case_division, sc.budget_division,
           COALESCE({$labelSql}, '(取引先なし)') AS client_name,
           sc.sales_rep, sc.manager, sc.worker_name, sc.store_name, sc.carrier,
           sc.revenue, sc.note, sc.created_at
    FROM sales_cases sc
    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_division = '1次'
      AND sc.case_type <> 'event'
      AND (sc.budget_division IS NULL OR sc.budget_division NOT IN ('キャリア予算','代理店予算'))
      AND " . CB_FY_WHERE . "
    ORDER BY sc.case_year, sc.case_month, client_name, sc.worker_name");
$detStmt->execute(array_merge([$cid], $fyParams));
$rows = $detStmt->fetchAll();

// ============================================================
// ③ 予算区分に実際に入っている値の内訳（なぜ未設定になったかの手がかり）
// ============================================================
$valStmt = $db->prepare("
    SELECT CASE WHEN sc.budget_division IS NULL THEN '(未入力・NULL)'
                WHEN TRIM(sc.budget_division) = '' THEN '(空文字)'
                ELSE sc.budget_division END AS val,
           COUNT(*) AS cnt, COALESCE(SUM(sc.revenue),0) AS rev
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_division = '1次' AND sc.case_type <> 'event'
      AND " . CB_FY_WHERE . "
    GROUP BY val ORDER BY cnt DESC");
$valStmt->execute(array_merge([$cid], $fyParams));
$values = $valStmt->fetchAll();

// 備考の傾向（案件人員一覧からのアサインが原因かを見分ける）
$fromBoard = 0;
foreach ($rows as $r) {
    if (mb_strpos((string)$r['note'], '案件人員一覧からアサイン') !== false) $fromBoard++;
}

$fmt = fn($n) => number_format((int)$n);
$pct = fn($p, $t) => $t > 0 ? round($p / $t * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>「1次の内訳 → 未設定」の中身を確認</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1180px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.78rem; white-space:nowrap; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
.note { white-space:normal; min-width:14rem; font-size:.7rem; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-search me-2"></i>「1次の内訳 → 未設定」の中身</h4>
<p class="text-muted small mb-3"><?= h($fyLabel) ?>　／　<span class="badge bg-success">このページはデータを一切変更しません（確認専用）</span></p>

<div class="mb-4">
  <?php foreach ([$fyYear - 1, $fyYear, $fyYear + 1] as $f): ?>
    <a href="?fy=<?= $f ?>" class="btn btn-sm <?= $f === $fyYear ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= $f - 1 ?>-<?= $f ?>年度
    </a>
  <?php endforeach; ?>
</div>

<!-- 未設定とは何か -->
<div class="alert alert-info">
  <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>「未設定」とは</div>
  <div class="small" style="line-height:1.9">
    区分が<strong>【1次】</strong>の<strong>【常勤案件】</strong>のうち、
    <strong>【予算区分】が「キャリア予算」でも「代理店予算」でもない</strong>案件のことです。<br>
    予算区分は常勤案件の登録画面で「区分＝1次」を選んだときだけ出てくる項目で、
    <strong>キャリア予算 / 代理店予算</strong> の2つしか選べません。
    ここが空のまま保存された案件が「未設定」に入ります。<br>
    ※イベント案件は予算区分を持たない仕組みなので、1次でも必ず「イベント」に分類され、未設定には入りません。
  </div>
</div>

<!-- ① 円グラフとの照合 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart me-1"></i>① 総合ダッシュボードの円グラフと同じ集計</div>
  <div class="card-body">
    <div class="small text-muted mb-2">ダッシュボードとまったく同じ条件で数え直しています。数字が一致すれば見ている対象は同じです。</div>
    <table class="table table-sm table-bordered bg-white w-auto mb-0">
      <thead class="table-light"><tr><th>区分</th><th class="num">金額</th><th class="num">構成比</th><th class="num">件数</th></tr></thead>
      <tbody>
        <tr class="table-light"><th colspan="4" class="small">区分別売上</th></tr>
        <tr><td>1次</td><td class="num"><?= $fmt($divRev['first']) ?>円</td>
            <td class="num"><?= $pct($divRev['first'], $divRev['first'] + $divRev['second']) ?>%</td>
            <td class="num"><?= $fmt($divCnt['first']) ?>件</td></tr>
        <tr><td>2次以降</td><td class="num"><?= $fmt($divRev['second']) ?>円</td>
            <td class="num"><?= $pct($divRev['second'], $divRev['first'] + $divRev['second']) ?>%</td>
            <td class="num"><?= $fmt($divCnt['second']) ?>件</td></tr>
        <tr class="table-light"><th colspan="4" class="small">1次の内訳</th></tr>
        <?php foreach ([['carrier','キャリア常勤'],['agency','代理店常勤'],['event','イベント'],['unset','未設定']] as [$k, $lbl]): ?>
        <tr<?= $k === 'unset' ? ' class="table-warning fw-bold"' : '' ?>>
          <td><?= h($lbl) ?></td>
          <td class="num"><?= $fmt($divRev[$k]) ?>円</td>
          <td class="num"><?= $pct($divRev[$k], $divRev['first']) ?>%</td>
          <td class="num"><?= $fmt($divCnt[$k]) ?>件</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ③ 予算区分に入っている値 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-list-check me-1"></i>② 1次の常勤案件で、予算区分に何が入っているか</div>
  <div class="card-body">
    <table class="table table-sm table-bordered bg-white w-auto mb-0">
      <thead class="table-light"><tr><th>予算区分の中身</th><th class="num">件数</th><th class="num">売上</th><th>分類</th></tr></thead>
      <tbody>
      <?php foreach ($values as $v):
        $isUnset = !in_array($v['val'], ['キャリア予算', '代理店予算'], true); ?>
        <tr<?= $isUnset ? ' class="table-warning"' : '' ?>>
          <td class="fw-medium"><?= h($v['val']) ?></td>
          <td class="num"><?= $fmt($v['cnt']) ?>件</td>
          <td class="num"><?= $fmt($v['rev']) ?>円</td>
          <td><?= $isUnset ? '<span class="badge bg-warning text-dark">未設定に入る</span>' : '<span class="badge bg-success">正しく分類</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ② 明細 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-table me-1"></i>③ 「未設定」に入っている案件の明細
    <span class="badge bg-warning text-dark ms-1"><?= count($rows) ?>件</span>
    <span class="text-muted small ms-2">合計 <?= $fmt($divRev['unset']) ?>円</span>
  </div>
  <div class="card-body">
    <?php if ($fromBoard > 0): ?>
    <div class="alert alert-secondary small py-2">
      <i class="bi bi-lightbulb me-1"></i>このうち <strong><?= $fromBoard ?>件</strong> は備考が「案件人員一覧からアサイン」です。
      案件人員一覧からアサインして作られた案件は、<strong>区分は引き継ぐのに予算区分は引き継がれない</strong>作りになっているため、
      ここに入ります。
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="small text-muted">該当する案件はありません。</div>
    <?php else: ?>
      <div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover bg-white mb-0">
          <thead class="table-light" style="position:sticky;top:0">
            <tr>
              <th>#</th><th>案件ID</th><th>対象月</th><th>取引先</th><th>営業</th><th>管理者</th>
              <th>スタッフ</th><th>ｷｬﾘｱ</th><th>店舗</th><th class="num">売上</th>
              <th>予算区分</th><th>登録日</th><th>備考</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $i => $r): ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td class="text-muted"><?= (int)$r['id'] ?></td>
              <td><?= (int)$r['case_year'] ?>年<?= (int)$r['case_month'] ?>月</td>
              <td class="fw-medium"><?= h($r['client_name']) ?></td>
              <td><?= h($r['sales_rep']) ?></td>
              <td><?= h($r['manager']) ?></td>
              <td><?= h($r['worker_name']) ?></td>
              <td><?= h($r['carrier']) ?></td>
              <td><?= h($r['store_name']) ?></td>
              <td class="num"><?= $fmt($r['revenue']) ?></td>
              <td class="text-danger">
                <?= $r['budget_division'] === null ? '(未入力)' : ($r['budget_division'] === '' ? '(空)' : h($r['budget_division'])) ?>
              </td>
              <td class="text-muted"><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
              <td class="note text-muted"><?= h($r['note']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- 直し方 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-tools me-1"></i>④ 直したい場合</div>
  <div class="card-body small" style="line-height:1.9">
    ・<strong>1件ずつ直す</strong> … 常勤案件の一覧でその月を開き、該当案件の編集ボタンから
    「予算区分」を選んで保存すれば、次の表示から正しい内訳に入ります。<br>
    ・<strong>まとめて直す</strong> … どの案件をキャリア予算／代理店予算にするかを決めていただければ、
    一括で設定する確認ページを作れます（今回のイベント区分と同じ方式）。<br>
    ・<strong>今後増えないようにする</strong> … 案件人員一覧からのアサインで予算区分が引き継がれるように直すこともできます。
  </div>
</div>

<a href="<?= BASE_PATH ?>/public/sales_dashboard.php" class="btn btn-primary">総合ダッシュボードに戻る</a>
<a href="<?= BASE_PATH ?>/public/sales_regular.php" class="btn btn-outline-primary ms-2">常勤案件を開く</a>

<div class="alert alert-secondary mt-3 mb-0 small">
  <i class="bi bi-shield-check me-1"></i>このページは読み取り専用です。開いても押しても、データは一切変わりません。
  確認が終わったら削除して構いません。
</div>

</div>
</body>
</html>
