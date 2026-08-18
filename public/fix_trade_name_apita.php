<?php
/**
 * 【一度だけ実行するスクリプト】屋号コードの修正：アピタを AT → AP に直す
 *
 * 屋号コードは アピタ=AP / イオンタウン=AT / イオンモール=AM です。
 * 2025年10月〜2026年3月のイベント案件を登録した際、アピタの店舗を誤って
 * AT（イオンタウン）で登録してしまったため、AP に直します。
 *
 * 対象（私が AT で登録してしまったもの）:
 *   2025年11月 アピタ岩倉 → 屋号AT／店舗 岩倉      1件
 *   2026年1月  アピタ安城南 → 屋号AT／店舗 安城南   1件
 *   2026年2月  アピタ安城南 → 屋号AT／店舗 安城南   1件
 *   2026年3月  アピタ安城南 → 屋号AT／店舗 安城南   6件
 *
 * 本当にイオンタウンの案件があれば AT のままにする必要があるため、
 * この画面では屋号ATの案件をすべて一覧にし、チェックを付けたものだけ AP に変更します。
 * 店舗が「岩倉」「安城南」の行は最初からチェックが入っています。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、対象を確認して「AP に変更する」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db   = getDB();
$cid  = getCompanyId();
$csrf = getCsrfToken();

// 私が AT で登録してしまったアピタの店舗名（既定でチェックを入れる）
$APITA_STORES = ['岩倉', '安城南'];

$done = false; $updated = 0; $failed = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    if ($ids) {
        try {
            // company_id も条件に入れて、他社のデータを触らないようにする
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("UPDATE sales_cases SET trade_name = 'AP'
                                WHERE company_id = ? AND trade_name = 'AT' AND id IN ($ph)");
            $st->execute(array_merge([$cid], $ids));
            $updated = $st->rowCount();
        } catch (Throwable $e) {
            $failed = $e->getMessage();
        }
    }
    $done = true;
}

// 屋号が AT の案件をすべて取得
$st = $db->prepare("SELECT sc.id, sc.case_type, sc.case_year, sc.case_month, sc.start_date,
                           sc.worker_name, sc.store_name, sc.trade_name, cl.client_name
                    FROM sales_cases sc
                    LEFT JOIN sales_clients cl ON sc.client_id = cl.id
                    WHERE sc.company_id = ? AND sc.trade_name = 'AT'
                    ORDER BY sc.case_year, sc.case_month, sc.start_date, sc.id");
$st->execute([$cid]);
$rows = $st->fetchAll();

$preCount = 0;
foreach ($rows as $r) { if (in_array(trim($r['store_name']), $APITA_STORES, true)) $preCount++; }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>屋号コードの修正：アピタを AT → AP に</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1000px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.8rem; white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-tags me-2"></i>屋号コードの修正：アピタを AT → AP に</h4>

<?php if ($done): ?>

  <?php if ($failed): ?>
    <div class="alert alert-danger"><strong>更新できませんでした</strong><div class="small mt-1"><?= h($failed) ?></div></div>
  <?php else: ?>
    <div class="alert alert-success">
      <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $updated ?>件の屋号を AT → AP に変更しました</div>
    </div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">いま屋号が <span class="badge bg-secondary">AT</span> の案件：<?= count($rows) ?>件</div>
    <?php if ($rows): ?>
      <div class="small text-muted">下の一覧が残っている AT（イオンタウン）の案件です。</div>
    <?php else: ?>
      <div class="small text-muted">屋号ATの案件は残っていません。</div>
    <?php endif; ?>
  </div></div>

  <a href="<?= BASE_PATH ?>/public/sales_events.php" class="btn btn-primary">イベント案件へ</a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>作業が終わったら、このページ（fix_trade_name_apita.php）は削除してください。
  </div>

<?php else: ?>

  <div class="alert alert-info">
    <div class="fw-semibold">この画面ですること</div>
    <div class="small mt-1" style="line-height:1.9">
      ・屋号コードは <strong>アピタ=AP／イオンタウン=AT／イオンモール=AM</strong> です<br>
      ・2025年10月〜2026年3月のイベント案件を登録したとき、<strong>アピタの店舗を誤って AT で登録</strong>していました<br>
      ・チェックを付けた案件の屋号だけを <strong>AT → AP</strong> に変更します。金額・日付・担当者などは一切変更しません<br>
      ・店舗が <strong>岩倉・安城南</strong> の行は、私が間違えたぶんなので最初からチェックが入っています<br>
      ・本当にイオンタウンの案件があれば、その行のチェックを外してください
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-secondary">屋号が AT の案件はありません。修正は不要です。</div>
  <?php else: ?>

  <div class="card mb-3"><div class="card-body">
    屋号が <span class="badge bg-secondary">AT</span> の案件：<strong><?= count($rows) ?>件</strong>
    ／ うち最初からチェックが入っているもの：<strong><?= $preCount ?>件</strong>
  </div></div>

  <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='変更中...';">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

    <div class="table-responsive mb-3" style="max-height:56vh">
      <table class="table table-sm table-hover bg-white mb-0">
        <thead class="table-light" style="position:sticky;top:0">
          <tr>
            <?php /* 全選択のチェックボックスは置かない。
                     誤ってAT全件をAPに変えてしまう事故を防ぐため、1行ずつ選ぶ形にしている */ ?>
            <th style="width:40px"></th>
            <th>対象月</th><th>種別</th><th>取引先</th><th>スタッフ</th><th>開始日</th>
            <th>屋号</th><th>店舗</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $isApita = in_array(trim($r['store_name']), $APITA_STORES, true); ?>
          <tr class="<?= $isApita ? 'table-warning' : '' ?>">
            <td><input type="checkbox" class="rowchk" name="ids[]" value="<?= (int)$r['id'] ?>" <?= $isApita ? 'checked' : '' ?>></td>
            <td><?= (int)$r['case_year'] ?>年<?= (int)$r['case_month'] ?>月</td>
            <td><?= $r['case_type'] === 'event' ? 'イベント' : '常勤' ?></td>
            <td><?= h($r['client_name'] ?? '') ?></td>
            <td class="fw-medium"><?= h($r['worker_name']) ?></td>
            <td><?= h(substr((string)$r['start_date'], 0, 10)) ?></td>
            <td><span class="badge bg-secondary">AT</span> <i class="bi bi-arrow-right"></i> <span class="badge bg-primary">AP</span></td>
            <td><?= h($r['store_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>チェックした案件を AP に変更する</button>
    <a href="<?= BASE_PATH ?>/public/sales_events.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

  <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
