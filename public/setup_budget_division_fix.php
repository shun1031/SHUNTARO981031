<?php
/**
 * 【一度だけ実行するスクリプト】予算区分が未入力の常勤案件（1次）に予算区分を設定する
 *
 * 総合ダッシュボード「案件区分分析 → 1次の内訳」の【未設定】に入っている案件に、
 * キャリア予算 / 代理店予算 を設定する。
 *
 * 書き換えるのは budget_division（予算区分）の1項目だけ。
 * 売上・原価・粗利・単価・稼働数・区分（case_division）には一切触れない。
 * 予算区分はダッシュボードの内訳表示にしか使われていないため、金額は1円も変わらない。
 *
 * ユーザー確認済みの取り決め:
 *   - プレイスの【安城】の案件は【代理店予算】
 *   - 【安城以外】の案件は、既定では「変更しない」。必要なら画面で選ぶ
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「予算区分を設定」を押す。
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

/** 選べる予算区分（常勤案件の登録画面と同じ2つだけ） */
const BD_VALUES = ['キャリア予算', '代理店予算'];
/** 既定で選んでおく店舗と予算区分（ユーザー確認済み） */
const BD_DEFAULT_STORE = '安城';
const BD_DEFAULT_VALUE = '代理店予算';

// ── 年度（9月始まり）。ダッシュボードと同じ決め方 ──
$_today     = new DateTimeImmutable('today');
$_fyDefault = (int)$_today->format('n') >= 9 ? (int)$_today->format('Y') + 1 : (int)$_today->format('Y');
$fyYear = (int)($_GET['fy'] ?? $_fyDefault);
if ($fyYear < 2000 || $fyYear > 2100) $fyYear = $_fyDefault;
$fyParams = [$fyYear - 1, $fyYear];
$fyLabel  = ($fyYear - 1) . '年9月〜' . $fyYear . '年8月';

const BD_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";

// ============================================================
// 対象案件（＝ダッシュボードの「未設定」に入っているもの）を取る
// ============================================================
$labelSql = clientLabelSql('cl');
$loadRows = function () use ($db, $cid, $fyParams, $labelSql) {
    $st = $db->prepare("
        SELECT sc.id, sc.case_year, sc.case_month, sc.budget_division,
               COALESCE({$labelSql}, '(取引先なし)') AS client_name,
               sc.sales_rep, sc.manager, sc.worker_name, sc.store_name, sc.carrier, sc.revenue, sc.note
        FROM sales_cases sc
        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
        WHERE sc.company_id = ? AND sc.status = 'confirmed'
          AND sc.case_division = '1次'
          AND sc.case_type <> 'event'
          AND (sc.budget_division IS NULL OR sc.budget_division NOT IN ('キャリア予算','代理店予算'))
          AND " . BD_FY_WHERE . "
        ORDER BY sc.case_year, sc.case_month, client_name, sc.worker_name");
    $st->execute(array_merge([$cid], $fyParams));
    return $st->fetchAll();
};
$rows = $loadRows();
$allowedIds = [];
foreach ($rows as $r) { $allowedIds[(int)$r['id']] = true; }

// ============================================================
// 実行
// ============================================================
$done = false; $changed = 0; $execErr = ''; $changedByValue = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $input = (array)($_POST['bd'] ?? []);
    // 画面に出した案件以外・想定外の値は受け付けない（二重の保険）
    $plan = [];
    foreach ($input as $id => $val) {
        $id  = (int)$id;
        $val = is_string($val) ? trim($val) : '';
        if (!isset($allowedIds[$id]))            continue;   // 対象外の案件
        if (!in_array($val, BD_VALUES, true))    continue;   // 「変更しない」または不正な値
        $plan[$id] = $val;
    }

    if (!$plan) {
        $execErr = '設定する案件が1件も選ばれていません。';
    } else {
        try {
            // 書き換えるのは budget_division の1列だけ。
            // 条件を重ねて、イベント案件・2次以降・他期・キャンセル分に当たらないようにする。
            // ※UPDATE には別名(sc)を付けないので、期の条件もここに直接書く
            //   （BD_FY_WHERE は sc. 付きなのでそのままでは使えない）
            $upd = $db->prepare("UPDATE sales_cases SET budget_division = ?
                                 WHERE id = ? AND company_id = ?
                                   AND case_type <> 'event'
                                   AND status = 'confirmed'
                                   AND case_division = '1次'
                                   AND ((case_year = ? AND case_month >= 9)
                                     OR (case_year = ? AND case_month <= 8))");
            $db->beginTransaction();
            foreach ($plan as $id => $val) {
                $upd->execute(array_merge([$val, $id, $cid], $fyParams));
                if ($upd->rowCount() > 0) {
                    $changed++;
                    $changedByValue[$val] = ($changedByValue[$val] ?? 0) + 1;
                }
            }
            $db->commit();
            $done = true;
            $rows = $loadRows();          // 残りを取り直す
            $allowedIds = [];
            foreach ($rows as $r) { $allowedIds[(int)$r['id']] = true; }
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_budget_division_fix] ' . $e->getMessage());
            $execErr = '更新に失敗しました。';
        }
    }
}

// ============================================================
// ダッシュボードと同じ集計（実行前後の比較用）
// ============================================================
$divRev = ['first'=>0,'second'=>0,'carrier'=>0,'agency'=>0,'event'=>0,'unset'=>0];
$divCnt = $divRev;
$sm = $db->prepare("
    SELECT sc.case_division, sc.case_type, sc.budget_division,
           COALESCE(SUM(sc.revenue),0) AS rev, COUNT(*) AS cnt
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.status = 'confirmed'
      AND sc.case_division IN ('1次','2次以降')
      AND " . BD_FY_WHERE . "
    GROUP BY sc.case_division, sc.case_type, sc.budget_division");
$sm->execute(array_merge([$cid], $fyParams));
foreach ($sm->fetchAll() as $r) {
    $rev = (int)$r['rev']; $cnt = (int)$r['cnt'];
    if ($r['case_division'] !== '1次') { $divRev['second'] += $rev; $divCnt['second'] += $cnt; continue; }
    $divRev['first'] += $rev; $divCnt['first'] += $cnt;
    if ($r['case_type'] === 'event')                   $k = 'event';
    elseif ($r['budget_division'] === 'キャリア予算')  $k = 'carrier';
    elseif ($r['budget_division'] === '代理店予算')    $k = 'agency';
    else                                               $k = 'unset';
    $divRev[$k] += $rev; $divCnt[$k] += $cnt;
}

// 既定の選択（安城＝代理店予算 / それ以外＝変更しない）で何件になるか
$defaultCount = 0; $otherStoreCount = 0;
foreach ($rows as $r) {
    if (trim((string)$r['store_name']) === BD_DEFAULT_STORE) $defaultCount++;
    else                                                     $otherStoreCount++;
}

$fmt = fn($n) => number_format((int)$n);
$pct = fn($p, $t) => $t > 0 ? round($p / $t * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>予算区分が未入力の案件に予算区分を設定する</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1180px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.78rem; white-space:nowrap; }
.num { text-align:right; font-variant-numeric:tabular-nums; }
select.bd { font-size:.76rem; padding:.15rem .4rem; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-wallet2 me-2"></i>予算区分が未入力の案件に予算区分を設定する</h4>
<p class="text-muted small mb-4">
    対象: <?= h($fyLabel) ?>／区分が<strong>1次</strong>の<strong>常勤案件</strong>で、予算区分が空のもの<br>
    書き換えるのは<strong>予算区分の1項目だけ</strong>。売上・原価・粗利・単価・稼働数・区分には触れません
</p>

<?php if ($execErr): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($execErr) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $changed ?>件に予算区分を設定しました</div>
    <div class="small mt-1">
      <?php foreach ($changedByValue as $v => $n): ?>
        <?= h($v) ?>: <?= $n ?>件
      <?php endforeach; ?>
      ／ 売上・原価・粗利・区分は変更していません。
    </div>
  </div>
<?php endif; ?>

<!-- ① 実行前後の内訳 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart me-1"></i>① いまの「1次の内訳」（総合ダッシュボードと同じ集計）</div>
  <div class="card-body">
    <table class="table table-sm table-bordered bg-white w-auto mb-0">
      <thead class="table-light"><tr><th>1次の内訳</th><th class="num">金額</th><th class="num">構成比</th><th class="num">件数</th></tr></thead>
      <tbody>
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
    <div class="small text-muted mt-2">
      実行すると「未設定」の件数・金額が減り、選んだ予算区分（キャリア常勤／代理店常勤）に移ります。<br>
      <strong>1次の合計（<?= $fmt($divRev['first']) ?>円・<?= $fmt($divCnt['first']) ?>件）は変わりません。</strong>
    </div>
  </div>
</div>

<!-- ② 明細と選択 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-list-check me-1"></i>② 予算区分を設定する案件
    <span class="badge bg-warning text-dark ms-1"><?= count($rows) ?>件</span>
  </div>
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="small text-success"><i class="bi bi-check2 me-1"></i>予算区分が未入力の案件はありません<?= $done ? '（実行済み）' : '' ?>。</div>
    <?php else: ?>
      <div class="alert alert-info small py-2">
        <strong>店舗が「<?= h(BD_DEFAULT_STORE) ?>」の <?= $defaultCount ?>件</strong>には、ご指定どおり
        <strong><?= h(BD_DEFAULT_VALUE) ?></strong>をあらかじめ選んであります。<br>
        <?php if ($otherStoreCount > 0): ?>
        <span class="text-danger fw-semibold">
          店舗が「<?= h(BD_DEFAULT_STORE) ?>」以外の <?= $otherStoreCount ?>件は「変更しない」にしてあります。
        </span>
        こちらも設定する場合は、その行のプルダウンで選んでください。
        <?php endif; ?>
      </div>

      <form method="post" id="bdForm" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='実行中...';">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <div class="mb-2 d-flex gap-2 flex-wrap align-items-center">
          <span class="small text-muted">まとめて選ぶ:</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bdSetAll('代理店予算')">全部を代理店予算</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bdSetAll('キャリア予算')">全部をキャリア予算</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bdSetAll('')">全部を変更しない</button>
        </div>

        <div class="table-responsive" style="max-height:56vh">
          <table class="table table-sm table-hover bg-white mb-0">
            <thead class="table-light" style="position:sticky;top:0">
              <tr>
                <th>#</th><th>案件ID</th><th>対象月</th><th>取引先</th><th>営業</th>
                <th>スタッフ</th><th>店舗</th><th class="num">売上</th><th>設定する予算区分</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
              $isDefaultStore = trim((string)$r['store_name']) === BD_DEFAULT_STORE;
              $sel = $isDefaultStore ? BD_DEFAULT_VALUE : ''; ?>
              <tr<?= $isDefaultStore ? '' : ' class="table-warning"' ?>>
                <td class="text-muted"><?= $i + 1 ?></td>
                <td class="text-muted"><?= (int)$r['id'] ?></td>
                <td><?= (int)$r['case_year'] ?>年<?= (int)$r['case_month'] ?>月</td>
                <td class="fw-medium"><?= h($r['client_name']) ?></td>
                <td><?= h($r['sales_rep']) ?></td>
                <td><?= h($r['worker_name']) ?></td>
                <td<?= $isDefaultStore ? '' : ' class="fw-bold text-danger"' ?>><?= h($r['store_name']) ?></td>
                <td class="num"><?= $fmt($r['revenue']) ?></td>
                <td>
                  <select class="form-select form-select-sm bd" name="bd[<?= (int)$r['id'] ?>]">
                    <option value=""<?= $sel === '' ? ' selected' : '' ?>>変更しない</option>
                    <?php foreach (BD_VALUES as $v): ?>
                    <option value="<?= h($v) ?>"<?= $sel === $v ? ' selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="alert alert-info small mt-3 mb-3">
          <div class="fw-semibold">実行すること</div>
          <div style="line-height:1.9">
            ・プルダウンで選んだ案件の<strong>予算区分だけ</strong>を書き換えます<br>
            ・「変更しない」の行には<strong>一切触れません</strong><br>
            ・<strong>区分（1次/2次以降）・売上・原価・粗利・単価・稼働数は変更しません</strong><br>
            ・予算区分はダッシュボードの内訳表示にしか使われていないため、<strong>金額は1円も変わりません</strong>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">
          <i class="bi bi-check-circle me-1"></i>予算区分を設定する
        </button>
        <a href="<?= BASE_PATH ?>/public/sales_dashboard.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($done): ?>
<a href="<?= BASE_PATH ?>/public/sales_dashboard.php" class="btn btn-primary">総合ダッシュボードで確認する</a>
<a href="<?= BASE_PATH ?>/public/check_budget_unset.php" class="btn btn-outline-primary ms-2">未設定の確認ページを開く</a>
<?php endif; ?>

<div class="alert alert-warning mt-3 mb-0 small">
  <i class="bi bi-exclamation-triangle me-1"></i>実行が終わったら、このページ（setup_budget_division_fix.php）は削除してください。
</div>

<script>
function bdSetAll(v) {
    document.querySelectorAll('select.bd').forEach(function (s) { s.value = v; });
}
</script>

</div>
</body>
</html>
