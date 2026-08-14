<?php
/**
 * 担当者の社員ID 移行確認ページ（読み取り専用）
 * 第2段階の作業状況を目で確認するためのもの。データは一切変更しない。
 *  - 案件に登場する担当者名ごとに、名簿と一致するか／IDが付いているかを一覧表示
 *  - 名簿にない名前（表記ゆれ・退職者など）を洗い出す
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者IDの移行確認';

// 対象3項目（案件側のカラム名 => 表示名, IDカラム名）
$targets = [
    'sales_rep' => ['営業担当', 'sales_rep_id'],
    'manager'   => ['管理者',   'manager_id'],
    'recruiter' => ['採用者',   'recruiter_id'],
];

// ------------------------------------------------------------------
// 既存案件への社員ID付与（このボタンを押したときだけ実行）
//  - 名簿と一意に一致する名前だけを対象にする（同姓同名は付けない）
//  - 既にIDが付いている案件は触らない（未設定のみ）
//  - 名前・金額・集計には一切影響しない
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backfill') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { die('不正なリクエストです'); }
    $applied = [];
    foreach ($targets as $nameCol => [$label, $idCol]) {
        try {
            $stmt = $db->prepare("
                UPDATE sales_cases sc
                JOIN (
                    SELECT company_id, name, MIN(id) AS eid
                    FROM employees
                    GROUP BY company_id, name
                    HAVING COUNT(*) = 1
                ) e ON e.company_id = sc.company_id AND e.name = sc.$nameCol
                SET sc.$idCol = e.eid
                WHERE sc.company_id = ? AND sc.$idCol IS NULL AND sc.$nameCol <> ''
            ");
            $stmt->execute([$cid]);
            $applied[] = $label . ' ' . $stmt->rowCount() . '件';
        } catch (PDOException $e) {
            error_log('[rep_id_check backfill] ' . $e->getMessage());
            $applied[] = $label . ' 失敗';
        }
    }
    // PRG: 再読み込みで二重実行されないようにする
    redirect(BASE_PATH . '/admin/rep_id_check.php?done=' . urlencode(implode(' / ', $applied)));
}

$rows        = [];
$summary     = ['total' => 0, 'linked' => 0, 'unlinked' => 0];
$linkable    = 0;   // これから付与できる件数（名簿と一意に一致し、まだIDが無いもの）
$columnReady = true;
$csrf        = getCsrfToken();

foreach ($targets as $nameCol => [$label, $idCol]) {
    try {
        $stmt = $db->prepare("
            SELECT sc.$nameCol AS person, COUNT(*) AS cnt,
                   SUM(CASE WHEN sc.$idCol IS NOT NULL THEN 1 ELSE 0 END) AS linked,
                   (SELECT COUNT(*) FROM employees e
                     WHERE e.company_id = sc.company_id AND e.name = sc.$nameCol) AS roster_hits
            FROM sales_cases sc
            WHERE sc.company_id = ? AND sc.$nameCol IS NOT NULL AND sc.$nameCol <> ''
            GROUP BY sc.$nameCol, sc.company_id
            ORDER BY cnt DESC
        ");
        $stmt->execute([$cid]);
        foreach ($stmt->fetchAll() as $r) {
            $cnt    = (int)$r['cnt'];
            $linked = (int)$r['linked'];
            $hits   = (int)$r['roster_hits'];
            $rows[] = [
                'label'  => $label,
                'person' => $r['person'],
                'cnt'    => $cnt,
                'linked' => $linked,
                'hits'   => $hits,
            ];
            $summary['total']    += $cnt;
            $summary['linked']   += $linked;
            $summary['unlinked'] += ($cnt - $linked);
            if ($hits === 1) { $linkable += ($cnt - $linked); }
        }
    } catch (PDOException $e) {
        $columnReady = false;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-link-45deg me-2"></i>担当者IDの移行確認</h1>
        <p>案件の担当者名が社員名簿と結び付いているかの確認ページです。データは変更しません。</p>
    </div>

    <?php if (!$columnReady): ?>
    <div class="alert alert-warning">
        社員IDの列がまだ作成されていません。デプロイ後に自動で追加されます。
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['done'])): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="bi bi-check-circle me-1"></i>社員IDを付与しました（<?= h($_GET['done']) ?>）
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($columnReady && $linkable > 0): ?>
    <div class="card border-primary mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="fw-semibold mb-1">既存案件に社員IDを付与できます</div>
                <div class="small text-muted">
                    対象は <strong class="text-primary"><?= number_format($linkable) ?>件</strong>
                    （名簿と一意に一致する名前のみ）。<br>
                    すでにIDが付いている案件は触りません。名前・売上・粗利・給与は変わりません。
                </div>
            </div>
            <form method="post" onsubmit="return confirm('既存案件に社員IDを付与します。名前や金額は変わりません。実行しますか？')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="backfill">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-link-45deg me-1"></i>社員IDを付与する
                </button>
            </form>
        </div>
    </div>
    <?php elseif ($columnReady && $summary['total'] > 0): ?>
    <div class="alert alert-secondary">
        付与できる案件はありません（対象はすべて付与済み、または名簿と一致しない名前です）。
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">担当者名が入っている案件（延べ）</div>
                <div class="fs-4 fw-bold"><?= number_format($summary['total']) ?>件</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">社員IDが付いている</div>
                <div class="fs-4 fw-bold text-success"><?= number_format($summary['linked']) ?>件</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small">まだ付いていない</div>
                <div class="fs-4 fw-bold text-danger"><?= number_format($summary['unlinked']) ?>件</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">担当者名ごとの状況</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>項目</th><th>案件に入っている名前</th>
                            <th class="text-end">件数</th><th class="text-end">ID付き</th>
                            <th>名簿との一致</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                        <tr><td colspan="5" class="text-center text-muted p-4">対象データがありません</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-muted small"><?= h($r['label']) ?></td>
                            <td class="fw-medium"><?= h($r['person']) ?></td>
                            <td class="text-end"><?= number_format($r['cnt']) ?></td>
                            <td class="text-end"><?= number_format($r['linked']) ?></td>
                            <td>
                                <?php if (in_array($r['person'], ['直営業', '該当者なし'], true)): ?>
                                    <span class="badge bg-secondary">人ではない区分（対象外）</span>
                                <?php elseif ($r['hits'] === 1): ?>
                                    <span class="badge bg-success">名簿にあり</span>
                                <?php elseif ($r['hits'] === 0): ?>
                                    <span class="badge bg-danger">名簿になし</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">同姓同名が<?= (int)$r['hits'] ?>人</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <div class="fw-semibold mb-1">見方</div>
        <ul class="mb-0">
            <li><span class="badge bg-success">名簿にあり</span>：社員IDを付けられます</li>
            <li><span class="badge bg-danger">名簿になし</span>：表記ゆれか、名簿に未登録の方です。名簿の氏名を直すか、社員として登録すると解消します</li>
            <li><span class="badge bg-warning text-dark">同姓同名</span>：どちらの方か機械では判断できないため、IDは付けません</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
