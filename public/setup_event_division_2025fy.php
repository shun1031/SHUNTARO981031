<?php
/**
 * 【一度だけ実行するスクリプト】今期（2025年9月〜2026年8月）のイベント案件の区分を1次にする
 *
 * 対象は下の7社のイベント案件だけ。書き換えるのは「区分（case_division）」の1項目のみで、
 * 売上・原価・粗利・単価・稼働数には一切触れない。
 * 区分はどの金額計算にも使われていないため、実行しても金額は1円も変わらない。
 *
 * ユーザー確認済みの取り決め:
 *   - 対象は【イベント案件のみ】。常勤案件は絶対に触らない
 *   - 対象期間は今期（2025年9月〜2026年8月）のみ
 *   - ステータスが「確定」の案件だけ。キャンセル済みは対象外
 *   - 区分が「2次以降」の案件だけを1次にする。未設定（空）の案件は変更しない
 *     （未設定の案件はこの画面で一覧表示して報告する）
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「区分を1次にする」を押す。
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
// 対象の設定
// ============================================================
const ED_FY_START_YEAR  = 2025;   // 2025年9月〜
const ED_FY_END_YEAR    = 2026;   // 2026年8月まで

/** 今期（9月〜翌8月）の条件。パラメータは [開始年, 終了年] */
const ED_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";
$fyParams = [ED_FY_START_YEAR, ED_FY_END_YEAR];

/**
 * 対象の取引先。キーがご指定の会社名で、値は探すときの表記ゆれ候補。
 * 取引先マスタの「正式名称」「表記名」のどちらに入っていても拾えるようにしている
 */
$TARGET_CLIENTS = [
    'ラネット'   => ['ラネット'],
    'アスカ'     => ['アスカ', 'ASUKA', '飛鳥'],
    'FREEDOM'    => ['FREEDOM', 'フリーダム', 'ＦＲＥＥＤＯＭ'],
    'テレニシ'   => ['テレニシ'],
    'MDC'        => ['MDC', 'ＭＤＣ'],
    'テレポート' => ['テレポート'],
    'ベルパーク' => ['ベルパーク', 'BELLPARK', 'BELL PARK'],
];

// ============================================================
// 取引先マスタから候補を探す
// ============================================================
$allClients = getSalesClients($cid, false);   // 削除済みも含めて全件

$matched = [];        // ご指定名 => [ ['id'=>, 'label'=>, 'formal'=>], ... ]
$matchedIds = [];     // 見つかった取引先IDの集合
foreach ($TARGET_CLIENTS as $given => $aliases) {
    $hits = [];
    foreach ($allClients as $c) {
        $formal = trim((string)$c['client_name']);
        $disp   = trim((string)($c['display_name'] ?? ''));
        foreach ($aliases as $a) {
            // 大文字小文字を区別せずに部分一致で探す（表記ゆれを拾うため）
            if (mb_stripos($formal, $a) !== false || ($disp !== '' && mb_stripos($disp, $a) !== false)) {
                $hits[(int)$c['id']] = [
                    'id'     => (int)$c['id'],
                    'label'  => clientLabel($c),
                    'formal' => $formal,
                    'active' => (int)($c['is_active'] ?? 1) === 1,
                ];
                break;
            }
        }
    }
    $matched[$given] = array_values($hits);
    foreach ($hits as $id => $_) { $matchedIds[$id] = true; }
}

// ============================================================
// 今期のイベント案件を取引先ごとに集計（確定分のみ）
// ============================================================
$cntStmt = $db->prepare("
    SELECT sc.client_id,
           COUNT(*) AS c_all,
           SUM(CASE WHEN sc.case_division = '2次以降' THEN 1 ELSE 0 END) AS c_second,
           SUM(CASE WHEN sc.case_division = '1次'     THEN 1 ELSE 0 END) AS c_first,
           SUM(CASE WHEN sc.case_division IS NULL OR sc.case_division = '' THEN 1 ELSE 0 END) AS c_unset
    FROM sales_cases sc
    WHERE sc.company_id = ? AND sc.case_type = 'event' AND sc.status = 'confirmed'
      AND " . ED_FY_WHERE . "
    GROUP BY sc.client_id");
$cntStmt->execute(array_merge([$cid], $fyParams));
$countByClient = [];
foreach ($cntStmt->fetchAll() as $r) {
    $countByClient[(int)$r['client_id']] = [
        'all'    => (int)$r['c_all'],
        'second' => (int)$r['c_second'],
        'first'  => (int)$r['c_first'],
        'unset'  => (int)$r['c_unset'],
    ];
}

// ============================================================
// 実行
// ============================================================
$done = false; $changed = 0; $execErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['client_ids'] ?? []))));
    // 画面に出した取引先以外は受け付けない（想定外の会社を書き換えないための保険）
    $ids = array_values(array_filter($ids, fn($i) => isset($matchedIds[$i])));

    if (!$ids) {
        $execErr = '対象の取引先が1社も選ばれていません。';
    } else {
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // 書き換えるのは case_division の1列だけ。金額・単価・稼働数には触れない。
            // 条件を二重に絞って、常勤案件・他期・キャンセル分・未設定分に当たらないようにする
            $sql = "UPDATE sales_cases SET case_division = '1次'
                    WHERE company_id = ?
                      AND case_type = 'event'
                      AND status = 'confirmed'
                      AND case_division = '2次以降'
                      AND client_id IN ({$ph})
                      AND ((case_year = ? AND case_month >= 9) OR (case_year = ? AND case_month <= 8))";
            $st = $db->prepare($sql);
            $st->execute(array_merge([$cid], $ids, $fyParams));
            $changed = $st->rowCount();
            $done = true;

            // 実行後の状態を取り直す
            $cntStmt->execute(array_merge([$cid], $fyParams));
            $countByClient = [];
            foreach ($cntStmt->fetchAll() as $r) {
                $countByClient[(int)$r['client_id']] = [
                    'all'    => (int)$r['c_all'],
                    'second' => (int)$r['c_second'],
                    'first'  => (int)$r['c_first'],
                    'unset'  => (int)$r['c_unset'],
                ];
            }
        } catch (PDOException $e) {
            error_log('[setup_event_division] ' . $e->getMessage());
            $execErr = '更新に失敗しました。';
        }
    }
}

// ============================================================
// 画面に出す明細
// ============================================================
$labelSql = clientLabelSql('cl');

/** 明細を取る共通クエリ */
$detail = function (string $extraCond) use ($db, $cid, $fyParams, $labelSql) {
    $st = $db->prepare("
        SELECT sc.id, sc.case_year, sc.case_month, sc.case_division,
               COALESCE({$labelSql}, '(取引先なし)') AS client_name,
               sc.sales_rep, sc.worker_name, sc.store_name, sc.carrier, sc.revenue
        FROM sales_cases sc
        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
        WHERE sc.company_id = ? AND sc.case_type = 'event' AND sc.status = 'confirmed'
          AND " . ED_FY_WHERE . " {$extraCond}
        ORDER BY sc.case_year, sc.case_month, client_name, sc.worker_name");
    $st->execute(array_merge([$cid], $fyParams));
    return $st->fetchAll();
};

$idList = array_keys($matchedIds);
$idPh   = $idList ? implode(',', array_map('intval', $idList)) : '0';

// 1) これから1次に変える案件（対象7社・区分が2次以降）
$toChange = $detail(" AND sc.case_division = '2次以降' AND sc.client_id IN ({$idPh})");
// 2) 対象7社のうち区分が未設定の案件（変更しないが報告する）
$unsetRows = $detail(" AND (sc.case_division IS NULL OR sc.case_division = '') AND sc.client_id IN ({$idPh})");
// 3) 7社以外も含めた今期イベント全体の未設定件数（参考）
$unsetAll = $detail(" AND (sc.case_division IS NULL OR sc.case_division = '')");

// 4) 参考: 今期にイベント案件がある取引先の一覧（社名が一致しなかったときの手がかり）
$allCliStmt = $db->prepare("
    SELECT COALESCE({$labelSql}, '(取引先なし)') AS client_name, sc.client_id, COUNT(*) AS cnt
    FROM sales_cases sc
    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id = ? AND sc.case_type = 'event' AND sc.status = 'confirmed'
      AND " . ED_FY_WHERE . "
    GROUP BY sc.client_id, client_name
    ORDER BY cnt DESC, client_name");
$allCliStmt->execute(array_merge([$cid], $fyParams));
$allEventClients = $allCliStmt->fetchAll();

// 常勤案件が1件も変わっていないことを示すための件数（実行前後で同じはず）
$regStmt = $db->prepare("
    SELECT SUM(CASE WHEN case_division = '1次' THEN 1 ELSE 0 END) AS c_first,
           SUM(CASE WHEN case_division = '2次以降' THEN 1 ELSE 0 END) AS c_second,
           COUNT(*) AS c_all
    FROM sales_cases sc
    WHERE company_id = ? AND case_type = 'regular' AND " . ED_FY_WHERE);
$regStmt->execute(array_merge([$cid], $fyParams));
$regular = $regStmt->fetch() ?: ['c_first' => 0, 'c_second' => 0, 'c_all' => 0];

$fmt   = fn($n) => number_format((int)$n);
$ymLbl = fn($y, $m) => $y . '年' . $m . '月';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>イベント案件の区分を1次にする（2025年9月〜2026年8月）</title>
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

<h4 class="fw-bold mb-1"><i class="bi bi-calendar-check me-2"></i>イベント案件の区分を1次にする</h4>
<p class="text-muted small mb-4">
    対象期間: 2025年9月〜2026年8月（今期）／対象: <strong>イベント案件のみ・ステータス確定のみ</strong>／
    <strong>常勤案件は一切触りません</strong>
</p>

<?php if ($execErr): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($execErr) ?></div>
<?php endif; ?>

<?php if ($done): ?>
    <div class="alert alert-success">
        <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $changed ?>件の区分を「2次以降」から「1次」に変更しました</div>
        <div class="small mt-1">売上・原価・粗利・単価・稼働数は変更していません。</div>
    </div>
<?php endif; ?>

<!-- ① 7社の照合結果 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-search me-1"></i>① ご指定の7社と取引先マスタの照合
  </div>
  <div class="card-body">
    <div class="small text-muted mb-2">
        取引先マスタの「正式名称」「表記名」の両方を、表記ゆれの候補も含めて探しています。<br>
        <strong>チェックが入っている取引先だけ</strong>が変更の対象です。関係ない会社が混ざっていたらチェックを外してください。
    </div>
    <table class="table table-sm table-bordered bg-white mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:2.5rem"></th>
          <th>ご指定の会社名</th>
          <th>見つかった取引先（表記名）</th>
          <th>正式名称</th>
          <th class="num">今期のイベント案件</th>
          <th class="num">うち2次以降<br><span class="text-danger">＝変更対象</span></th>
          <th class="num">うち1次</th>
          <th class="num">うち未設定</th>
        </tr>
      </thead>
      <tbody>
      <?php $totalChange = 0; foreach ($TARGET_CLIENTS as $given => $_a):
        $hits = $matched[$given];
        if (!$hits): ?>
        <tr class="table-warning">
          <td></td>
          <td class="fw-bold"><?= h($given) ?></td>
          <td colspan="6" class="text-danger">
            <i class="bi bi-exclamation-triangle me-1"></i>取引先マスタに見つかりませんでした（この会社は変更されません）
          </td>
        </tr>
        <?php continue; endif; ?>
        <?php foreach ($hits as $i => $hit):
          $c = $countByClient[$hit['id']] ?? ['all'=>0,'second'=>0,'first'=>0,'unset'=>0];
          $totalChange += $c['second']; ?>
        <tr<?= $c['all'] === 0 ? ' class="table-secondary"' : '' ?>>
          <td class="text-center">
            <input type="checkbox" class="form-check-input ed-cli" form="edForm" name="client_ids[]"
                   value="<?= $hit['id'] ?>"<?= $c['second'] > 0 ? ' checked' : '' ?><?= $done ? ' disabled' : '' ?>>
          </td>
          <td class="fw-bold"><?= $i === 0 ? h($given) : '' ?></td>
          <td><?= h($hit['label']) ?><?= $hit['active'] ? '' : ' <span class="badge bg-secondary" style="font-size:.6rem">削除済み</span>' ?></td>
          <td class="text-muted"><?= h($hit['formal']) ?></td>
          <td class="num"><?= $fmt($c['all']) ?>件</td>
          <td class="num fw-bold <?= $c['second'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= $fmt($c['second']) ?>件</td>
          <td class="num text-muted"><?= $fmt($c['first']) ?>件</td>
          <td class="num <?= $c['unset'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>"><?= $fmt($c['unset']) ?>件</td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr><th colspan="5" class="text-end">変更する件数の合計</th>
            <th class="num text-danger"><?= $fmt($totalChange) ?>件</th><th></th><th></th></tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- ② 未設定の案件（ご要望の報告） -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-question-circle me-1 text-warning"></i>② 区分が未設定の案件
    <span class="badge bg-warning text-dark ms-1">7社ぶん <?= count($unsetRows) ?>件</span>
    <span class="badge bg-secondary ms-1">今期のイベント全体 <?= count($unsetAll) ?>件</span>
  </div>
  <div class="card-body">
    <div class="small text-muted mb-2">
        ご指定どおり、<strong>未設定の案件は変更しません</strong>。どの案件が未設定かの確認用です。
    </div>
    <?php if (!$unsetRows): ?>
      <div class="small text-success"><i class="bi bi-check2 me-1"></i>ご指定の7社には、区分が未設定の案件はありません。</div>
    <?php else: ?>
      <div class="table-responsive" style="max-height:34vh">
        <table class="table table-sm table-bordered bg-white mb-0">
          <thead class="table-light" style="position:sticky;top:0">
            <tr><th>対象月</th><th>取引先</th><th>営業</th><th>スタッフ</th><th>ｷｬﾘｱ</th><th>店舗</th><th class="num">売上</th></tr>
          </thead>
          <tbody>
          <?php foreach ($unsetRows as $r): ?>
            <tr>
              <td><?= $ymLbl((int)$r['case_year'], (int)$r['case_month']) ?></td>
              <td class="fw-medium"><?= h($r['client_name']) ?></td>
              <td><?= h($r['sales_rep']) ?></td>
              <td><?= h($r['worker_name']) ?></td>
              <td><?= h($r['carrier']) ?></td>
              <td><?= h($r['store_name']) ?></td>
              <td class="num"><?= $fmt($r['revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (count($unsetAll) > count($unsetRows)): ?>
    <div class="small text-muted mt-2">
        ※ 7社以外にも未設定の案件が <strong><?= count($unsetAll) - count($unsetRows) ?>件</strong> あります（今回は対象外）。
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ③ 変更する案件の明細 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-list-ul me-1"></i>③ 「2次以降」→「1次」に変える案件
    <span class="badge bg-danger ms-1"><?= count($toChange) ?>件</span>
  </div>
  <div class="card-body">
    <?php if (!$toChange): ?>
      <div class="small text-muted">変更対象の案件がありません<?= $done ? '（実行済みのため）' : '' ?>。</div>
    <?php else: ?>
      <div class="table-responsive" style="max-height:46vh">
        <table class="table table-sm table-hover bg-white mb-0">
          <thead class="table-light" style="position:sticky;top:0">
            <tr><th>#</th><th>対象月</th><th>取引先</th><th>営業</th><th>スタッフ</th><th>ｷｬﾘｱ</th><th>店舗</th><th class="num">売上</th><th>現在の区分</th></tr>
          </thead>
          <tbody>
          <?php foreach ($toChange as $i => $r): ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td><?= $ymLbl((int)$r['case_year'], (int)$r['case_month']) ?></td>
              <td class="fw-medium"><?= h($r['client_name']) ?></td>
              <td><?= h($r['sales_rep']) ?></td>
              <td><?= h($r['worker_name']) ?></td>
              <td><?= h($r['carrier']) ?></td>
              <td><?= h($r['store_name']) ?></td>
              <td class="num"><?= $fmt($r['revenue']) ?></td>
              <td><?= h($r['case_division']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ④ 参考情報 -->
<div class="card mb-4">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-info-circle me-1"></i>④ 参考: 今期にイベント案件がある取引先
    <span class="badge bg-secondary ms-1"><?= count($allEventClients) ?>社</span>
  </div>
  <div class="card-body">
    <div class="small text-muted mb-2">
        ①で「見つかりませんでした」と出た会社があれば、この一覧から正しい表記を探してください。
    </div>
    <div class="d-flex flex-wrap gap-1" style="max-height:16vh;overflow:auto">
      <?php foreach ($allEventClients as $r): ?>
        <span class="badge bg-light text-dark border"><?= h($r['client_name']) ?> <span class="text-muted"><?= $fmt($r['cnt']) ?></span></span>
      <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-3 pt-2 border-top">
        <strong>常勤案件（今期）:</strong> 全<?= $fmt($regular['c_all']) ?>件（1次 <?= $fmt($regular['c_first']) ?>件 / 2次以降 <?= $fmt($regular['c_second']) ?>件）
        … この数字は<strong>実行しても変わりません</strong>。実行後に見比べて確認できます。
    </div>
  </div>
</div>

<!-- 実行 -->
<?php if (!$done): ?>
<div class="alert alert-info">
  <div class="fw-semibold">実行すること</div>
  <div class="small mt-1" style="line-height:1.9">
    ・チェックした取引先の、<strong>今期（2025年9月〜2026年8月）のイベント案件</strong>のうち、<br>
    　区分が<strong>「2次以降」のものだけ</strong>を「1次」に書き換えます<br>
    ・書き換えるのは<strong>区分の1項目だけ</strong>。売上・原価・粗利・単価・稼働数には触れません<br>
    ・<strong>常勤案件・キャンセル済みの案件・区分が未設定の案件は対象外</strong>です<br>
    ・区分はどの金額計算にも使われていないため、<strong>金額は1円も変わりません</strong>
  </div>
</div>

<form method="post" id="edForm" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='実行中...';">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <button type="submit" class="btn btn-danger btn-lg"<?= $toChange ? '' : ' disabled' ?>>
    <i class="bi bi-check-circle me-1"></i>区分を1次にする（<?= count($toChange) ?>件）
  </button>
  <a href="<?= BASE_PATH ?>/public/sales_events.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
</form>
<?php else: ?>
<a href="<?= BASE_PATH ?>/public/case_stores.php" class="btn btn-primary">案件店舗管理で確認する（イベント → 1次案件）</a>
<a href="<?= BASE_PATH ?>/public/sales_dashboard.php" class="btn btn-outline-primary ms-2">総合ダッシュボードで確認する</a>
<?php endif; ?>

<div class="alert alert-warning mt-3 mb-0 small">
  <i class="bi bi-exclamation-triangle me-1"></i>実行が終わったら、このページ（setup_event_division_2025fy.php）は削除してください。
</div>

</div>
</body>
</html>
