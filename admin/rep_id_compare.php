<?php
/**
 * 担当者集計の比較ページ（読み取り専用・第3段階の事前確認用）
 *
 * 同じ案件データを次の2通りで集計し、金額が一致するかを並べて表示する。
 *   A方式（現行）: 担当者を「名前」で判定する
 *   B方式（新）  : 社員IDがあればその社員、無ければ従来どおり名前で判定する
 *
 * データは一切変更しない。既存の画面・集計にも影響しない。
 * すべて一致することを確認してから、画面を1つずつB方式へ切り替える。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者集計の比較（名前 vs 社員ID）';

// ---- 対象月（既定はデータのある最新月）----
$year  = (int)($_GET['year']  ?? 0);
$month = (int)($_GET['month'] ?? 0);
if (!$year || !$month) {
    $latest = $db->prepare("SELECT case_year, case_month FROM sales_cases
                            WHERE company_id = ? AND status = 'confirmed'
                            ORDER BY case_year DESC, case_month DESC LIMIT 1");
    $latest->execute([$cid]);
    $row = $latest->fetch();
    $year  = $row ? (int)$row['case_year']  : (int)date('Y');
    $month = $row ? (int)$row['case_month'] : (int)date('n');
}

$errorMsg = '';
$rows     = [];
$mismatch = 0;

try {
    // 社員ID => 氏名
    $empStmt = $db->prepare('SELECT id, name FROM employees WHERE company_id = ?');
    $empStmt->execute([$cid]);
    $empName = [];
    foreach ($empStmt->fetchAll() as $e) { $empName[(int)$e['id']] = $e['name']; }

    // 粗利0円稼働者（ダッシュボードと同じ判定）
    $zpStmt = $db->prepare('SELECT name FROM employees WHERE company_id = ? AND is_active = 1 AND zero_profit_flag = 1');
    $zpStmt->execute([$cid]);
    $zeroNames = array_flip($zpStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    // 対象月の確定案件
    $caseStmt = $db->prepare("
        SELECT sales_rep, manager, recruiter,
               sales_rep_id, manager_id, recruiter_id,
               worker_name, case_type, revenue, gross_profit
        FROM sales_cases
        WHERE company_id = ? AND status = 'confirmed' AND sales_rep <> ''
          AND case_year = ? AND case_month = ?
    ");
    $caseStmt->execute([$cid, $year, $month]);
    $cases = $caseStmt->fetchAll();

    // 「意味のある名前」か（空欄・該当者なし は紹介者として扱わない）
    $meaningful = fn($v) => trim((string)$v) !== '' && trim((string)$v) !== '該当者なし';

    $byName = [];  // A方式
    $byId   = [];  // B方式
    $add = function (array &$acc, string $who, int $rev, int $pro): void {
        if (!isset($acc[$who])) $acc[$who] = ['revenue' => 0, 'profit' => 0];
        $acc[$who]['revenue'] += $rev;
        $acc[$who]['profit']  += $pro;
    };

    foreach ($cases as $c) {
        $rev = (int)$c['revenue'];
        $pro = (int)$c['gross_profit'];
        // 粗利0円稼働者: 売上は50/50のまま、粗利は直営業へ100%
        $isZp = isset($zeroNames[trim((string)$c['worker_name'])])
             || (trim((string)$c['worker_name']) === '近藤航' && $year === 2026 && $month === 7 && $c['case_type'] === 'event');

        $repRev = (int)floor($rev / 2);
        $refRev = $rev - $repRev;
        $repPro = $isZp ? 0 : (int)floor($pro / 2);
        $refPro = $isZp ? 0 : $pro - (int)floor($pro / 2);

        // --- A方式: 名前で判定（現行ロジックと同じ） ---
        $repA = $c['sales_rep'];
        $refA = $meaningful($c['manager']) ? $c['manager']
              : ($meaningful($c['recruiter']) ? $c['recruiter'] : '直営業');

        // --- B方式: 社員IDがあれば優先、無ければ名前 ---
        $repB = (!empty($c['sales_rep_id']) && isset($empName[(int)$c['sales_rep_id']]))
              ? $empName[(int)$c['sales_rep_id']] : $c['sales_rep'];
        if (!empty($c['manager_id']) && isset($empName[(int)$c['manager_id']])) {
            $refB = $empName[(int)$c['manager_id']];
        } elseif ($meaningful($c['manager'])) {
            $refB = $c['manager'];
        } elseif (!empty($c['recruiter_id']) && isset($empName[(int)$c['recruiter_id']])) {
            $refB = $empName[(int)$c['recruiter_id']];
        } elseif ($meaningful($c['recruiter'])) {
            $refB = $c['recruiter'];
        } else {
            $refB = '直営業';
        }

        $add($byName, $repA, $repRev, $repPro);
        $add($byName, $refA, $refRev, $refPro);
        $add($byId,   $repB, $repRev, $repPro);
        $add($byId,   $refB, $refRev, $refPro);
        if ($isZp) { $add($byName, '直営業', 0, $pro); $add($byId, '直営業', 0, $pro); }
    }

    foreach (array_unique(array_merge(array_keys($byName), array_keys($byId))) as $who) {
        $a = $byName[$who] ?? ['revenue' => 0, 'profit' => 0];
        $b = $byId[$who]   ?? ['revenue' => 0, 'profit' => 0];
        $ok = ($a['revenue'] === $b['revenue'] && $a['profit'] === $b['profit']);
        if (!$ok) $mismatch++;
        $rows[] = ['who' => $who, 'a' => $a, 'b' => $b, 'ok' => $ok];
    }
    usort($rows, fn($x, $y) => $y['a']['revenue'] <=> $x['a']['revenue']);
} catch (PDOException $e) {
    error_log('[rep_id_compare] ' . $e->getMessage());
    $errorMsg = '集計できませんでした。社員IDの列がまだ作成されていない可能性があります。';
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-clipboard-check me-2"></i>担当者集計の比較</h1>
        <p>同じ案件を「名前で集計」と「社員IDで集計」の2通りで計算し、金額が一致するか確認します。データは変更しません。</p>
    </div>

    <form method="get" class="card mb-4">
        <div class="card-body d-flex align-items-end gap-2 flex-wrap">
            <div>
                <label class="form-label small mb-1">年</label>
                <input type="number" name="year" class="form-control form-control-sm" style="width:110px" value="<?= $year ?>">
            </div>
            <div>
                <label class="form-label small mb-1">月</label>
                <input type="number" name="month" class="form-control form-control-sm" style="width:90px" min="1" max="12" value="<?= $month ?>">
            </div>
            <button class="btn btn-sm btn-primary">表示</button>
        </div>
    </form>

    <?php if ($errorMsg): ?>
    <div class="alert alert-warning"><?= h($errorMsg) ?></div>
    <?php elseif (!$rows): ?>
    <div class="alert alert-secondary">この月の確定案件がありません。</div>
    <?php elseif ($mismatch === 0): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i>
        <strong><?= $year ?>年<?= $month ?>月：全<?= count($rows) ?>名すべて一致しました。</strong>
        この月については、社員IDで集計しても金額は変わりません。
    </div>
    <?php else: ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong><?= $mismatch ?>名で金額が一致しません。</strong>
        切り替える前に原因の確認が必要です。
    </div>
    <?php endif; ?>

    <?php if ($rows): ?>
    <div class="card">
        <div class="card-header"><?= $year ?>年<?= $month ?>月の担当者別集計（50/50分割後）</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>担当者</th>
                            <th class="text-end">売上（名前）</th>
                            <th class="text-end">売上（社員ID）</th>
                            <th class="text-end">粗利（名前）</th>
                            <th class="text-end">粗利（社員ID）</th>
                            <th class="text-center">判定</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr class="<?= $r['ok'] ? '' : 'table-danger' ?>">
                            <td class="fw-medium"><?= h($r['who']) ?></td>
                            <td class="text-end"><?= number_format($r['a']['revenue']) ?></td>
                            <td class="text-end"><?= number_format($r['b']['revenue']) ?></td>
                            <td class="text-end"><?= number_format($r['a']['profit']) ?></td>
                            <td class="text-end"><?= number_format($r['b']['profit']) ?></td>
                            <td class="text-center">
                                <?= $r['ok'] ? '<span class="badge bg-success">一致</span>'
                                             : '<span class="badge bg-danger">不一致</span>' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border mt-3 small mb-0">
        <div class="fw-semibold mb-1">見方</div>
        <ul class="mb-0">
            <li><strong>名前</strong>：現在の画面と同じ計算方法です</li>
            <li><strong>社員ID</strong>：社員IDがあればその社員として、無ければ従来どおり名前で計算します</li>
            <li>「直営業」も含めて全員分を照合します。<strong>すべて一致していれば、切り替えても金額は変わりません</strong></li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
