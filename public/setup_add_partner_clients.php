<?php
/**
 * 【一度だけ実行するスクリプト】取引先マスタに未登録の4社を登録する
 *
 * 照合ページ（check_partner_candidates.php）で「取引先マスタに無い」と判定された
 * 4社を、取引先として登録する。商談報告は取引先一覧からの選択式なので、
 * これを登録しないと商談報告を作れない。
 *
 * 誤登録を防ぐため、実行前に次を必ず表示する:
 *   - すでに同じ会社が登録されていないか（正規化して突き合わせ）
 *   - 似た名前の取引先がないか（ULTI-ME に対する ME など）
 *   - 外注先に同名が無いか（あると戦略会議で2社に数えられる）
 *
 * 追加するのは sales_clients の1行だけ。案件・商談報告・集計には一切触れない。
 * 取引先を登録しただけではパートナー数にもパートナー候補数にも入らない。
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
// 登録する4社  [No, 正式名称, 表記名, よみ, 想定担当者, 備考]
// 表記名は法人格を抜いた形（このポータルの決まり）
// ============================================================
$TARGETS = [
    [66, '株式会社T’s Solution', 'T’s Solution', 'ティーズソリューション', '綾部航介', ''],
    [67, '堀恭彰',               '堀恭彰',        'ホリヤスアキ',           '綾部航介', '個人'],
    [68, '平手達也',             '平手達也',      'ヒラテタツヤ',           '綾部航介', '個人'],
    [95, '株式会社ULTI-ME',      'ULTI-ME',       'アルティメ',             '佐藤思杰', '「ME」とは別会社'],
];

/** 照合ページと同じ正規化（法人格・空白・記号・長音を落とす） */
function acNorm(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = preg_replace('/(株式会社|合同会社|有限会社|合資会社|一般社団法人|一般財団法人|\(株\)|（株）|\(有\)|（有）|\(同\)|（同）)/u', '', $s);
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'asKV');
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[’\'`´.,\-‐－ｰー・･。、（）\(\)]/u', '', $s);
    return mb_strtolower((string)$s, 'UTF-8');
}

// ============================================================
// マスタを読む
// ============================================================
$loadMaster = function () use ($db, $cid) {
    $c = $db->prepare('SELECT id, client_name, display_name, is_active FROM sales_clients WHERE company_id = ?');
    $c->execute([$cid]);
    $a = $db->prepare('SELECT id, alliance_name, display_name, client_id FROM sales_alliances WHERE company_id = ?');
    $a->execute([$cid]);
    return [$c->fetchAll(PDO::FETCH_ASSOC), $a->fetchAll(PDO::FETCH_ASSOC)];
};
[$clientRows, $allianceRows] = $loadMaster();

/**
 * 登録前の判定を作る。
 *   exists  … 正規化して完全一致する取引先（＝登録不要）
 *   similar … 似た名前の取引先（誤登録の注意喚起。3文字以上の部分一致のみ）
 *   ally    … 同名の外注先（紐付けが無いと2社に数えられる）
 */
$judge = function (array $t) use ($clientRows, $allianceRows) {
    [$no, $official, $display, $kana, $rep, $memo] = $t;
    $keys = array_values(array_unique(array_filter([acNorm($official), acNorm($display), acNorm($kana)])));

    $exists = null; $similar = []; $ally = [];
    foreach ($clientRows as $c) {
        foreach ([$c['client_name'], $c['display_name']] as $n) {
            $nk = acNorm($n);
            if ($nk === '') continue;
            if (in_array($nk, $keys, true)) { $exists = $c; break 2; }
        }
    }
    if (!$exists) {
        foreach ($clientRows as $c) {
            foreach ([$c['client_name'], $c['display_name']] as $n) {
                $nk = acNorm($n);
                // 2文字以下の短い名前は誤って引っかかるだけなので見ない
                if (mb_strlen($nk) < 3) continue;
                foreach ($keys as $k) {
                    if (mb_strlen($k) < 3) continue;
                    if (mb_strpos($nk, $k) !== false || mb_strpos($k, $nk) !== false) {
                        $similar[(int)$c['id']] = $c;
                    }
                }
            }
        }
    }
    foreach ($allianceRows as $a) {
        foreach ([$a['alliance_name'], $a['display_name']] as $n) {
            $nk = acNorm($n);
            if ($nk !== '' && in_array($nk, $keys, true)) { $ally[(int)$a['id']] = $a; }
        }
    }
    return ['exists' => $exists, 'similar' => array_values($similar), 'ally' => array_values($ally)];
};

// ============================================================
// 実行
// ============================================================
$done = false; $created = []; $skipped = []; $failed = []; $execErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $picked = array_map('intval', (array)($_POST['nos'] ?? []));
    $allowed = array_column($TARGETS, 0);
    $picked = array_values(array_filter($picked, fn($n) => in_array($n, $allowed, true)));

    if (!$picked) {
        $execErr = '登録する会社が1社も選ばれていません。';
    } else {
        foreach ($TARGETS as $t) {
            [$no, $official, $display, $kana, $rep, $memo] = $t;
            if (!in_array($no, $picked, true)) continue;
            // 実行の直前にもう一度だけ重複を見る（画面を開いてから登録された場合の保険）
            $j = $judge($t);
            if ($j['exists']) {
                $skipped[] = $official . '（すでに登録済み: ID' . (int)$j['exists']['id'] . '）';
                continue;
            }
            try {
                $newId = createSalesClient($cid, [
                    'client_name'  => $official,
                    'display_name' => $display,
                    'note'         => 'パートナー候補として登録（想定担当: ' . $rep . '）',
                ]);
                $created[] = $official . '（ID' . $newId . '）';
            } catch (Throwable $e) {
                error_log('[setup_add_partner_clients] ' . $e->getMessage());
                $failed[] = $official . ' — ' . $e->getMessage();
            }
        }
        $done = true;
        [$clientRows, $allianceRows] = $loadMaster();   // 表示を取り直す
    }
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>取引先マスタに未登録の4社を登録する</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1040px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.8rem; }
.nw { white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-1"><i class="bi bi-building-add me-2"></i>取引先マスタに未登録の4社を登録する</h4>
<p class="text-muted small mb-4">
    追加するのは<strong>取引先マスタの1行だけ</strong>。案件・商談報告・集計には一切触れません。<br>
    <strong>取引先を登録しただけでは、パートナー数にもパートナー候補数にも入りません。</strong>
</p>

<?php if ($execErr): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($execErr) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= count($created) ?>社を登録しました</div>
    <?php if ($created): ?><div class="small mt-1"><?= $h(implode(' ／ ', $created)) ?></div><?php endif; ?>
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

<div class="card mb-4">
  <div class="card-header bg-white fw-bold"><i class="bi bi-list-check me-1"></i>登録する会社と、重複チェックの結果</div>
  <div class="card-body">
    <div class="small text-muted mb-3">
      <strong>誤登録を防ぐため、1社ずつ次を確認しています。</strong><br>
      ① すでに同じ会社が登録されていないか（法人格・記号・全角半角の違いを吸収して照合）<br>
      ② 似た名前の取引先が無いか（別会社を誤って重複登録しないため）<br>
      ③ 外注先に同名が無いか（あると戦略会議で2社に数えられます）
    </div>

    <form method="post" id="acForm" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <table class="table table-sm table-bordered bg-white align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:2.5rem"></th>
            <th>No</th><th>正式名称（登録する名前）</th><th>表記名</th><th class="nw">想定担当</th><th>チェック結果</th>
          </tr>
        </thead>
        <tbody>
        <?php $canRegister = 0; foreach ($TARGETS as $t):
          [$no, $official, $display, $kana, $rep, $memo] = $t;
          $j = $judge($t);
          $ok = !$j['exists'];
          if ($ok) $canRegister++; ?>
          <tr class="<?= $j['exists'] ? 'table-secondary' : ($j['similar'] ? 'table-warning' : '') ?>">
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="nos[]" value="<?= $no ?>"
                     <?= $ok && !$done ? 'checked' : '' ?> <?= $ok && !$done ? '' : 'disabled' ?>>
            </td>
            <td class="text-muted nw"><?= $no ?></td>
            <td class="fw-medium"><?= $h($official) ?>
              <?php if ($memo): ?><span class="text-muted" style="font-size:.7rem">（<?= $h($memo) ?>）</span><?php endif; ?>
              <div class="text-muted" style="font-size:.68rem">よみ: <?= $h($kana) ?></div>
            </td>
            <td><?= $h($display) ?></td>
            <td class="nw"><?= $h($rep) ?></td>
            <td>
              <?php if ($j['exists']): ?>
                <span class="badge bg-secondary">登録済み</span>
                <span class="text-muted">
                  <?= $h($j['exists']['display_name'] ?: $j['exists']['client_name']) ?>（ID<?= (int)$j['exists']['id'] ?>）
                </span>
              <?php else: ?>
                <span class="badge bg-success">新規登録できます</span>
              <?php endif; ?>

              <?php if ($j['similar']): ?>
                <div class="text-danger mt-1" style="font-size:.72rem">
                  <i class="bi bi-exclamation-triangle me-1"></i>似た名前の取引先:
                  <?= $h(implode(' / ', array_map(fn($c) => ($c['display_name'] ?: $c['client_name']) . '(ID' . $c['id'] . ')', $j['similar']))) ?>
                  <br>別会社であればこのまま登録してください。同じ会社ならチェックを外してください。
                </div>
              <?php endif; ?>

              <?php if ($j['ally']): ?>
                <div class="text-info mt-1" style="font-size:.72rem">
                  <i class="bi bi-link-45deg me-1"></i>外注先に同名:
                  <?= $h(implode(' / ', array_map(fn($a) => ($a['display_name'] ?: $a['alliance_name']) . ($a['client_id'] ? '（取引先紐付済）' : '（紐付なし）'), $j['ally']))) ?>
                  <br>紐付けが無い場合は、登録後に取引先一覧の「外注先」タブでこの取引先を紐付けてください。
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!$done): ?>
      <div class="alert alert-info small">
        <div class="fw-semibold">実行すること</div>
        <div style="line-height:1.9">
          ・チェックした会社を<strong>取引先マスタに追加します</strong>（正式名称・表記名・備考のみ）<br>
          ・備考には「パートナー候補として登録（想定担当: ○○）」と入ります<br>
          ・<strong>案件・商談報告・売上・パートナー数には一切影響しません</strong><br>
          ・登録した会社は案件が無いため、取引先一覧の「取引先」タブにはまだ出ません<br>
          ・実行の直前にもう一度だけ重複を確認し、すでにあればスキップします
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg"<?= $canRegister ? '' : ' disabled' ?>>
        <i class="bi bi-check-circle me-1"></i>登録を実行（<?= $canRegister ?>社）
      </button>
      <a href="<?= BASE_PATH ?>/public/clients.php" class="btn btn-outline-secondary ms-2">キャンセル</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($done): ?>
<a href="<?= BASE_PATH ?>/public/check_partner_candidates.php" class="btn btn-primary">照合ページで確認する</a>
<a href="<?= BASE_PATH ?>/public/strategy_meeting.php" class="btn btn-outline-primary ms-2">戦略会議を開く</a>
<?php endif; ?>

<div class="alert alert-warning mt-3 mb-0 small">
  <i class="bi bi-exclamation-triangle me-1"></i>実行が終わったら、このページ（setup_add_partner_clients.php）は削除してください。
</div>

</div>
</body>
</html>
