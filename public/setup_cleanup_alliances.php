<?php
/**
 * 【一度だけ実行するスクリプト】使っていない外注先を削除済みにする
 *
 * 案件が1件も無く、取引先とも紐づいていない外注先を「削除済み」に移します。
 * 完全に消すのではないので、外注先タブの「削除済み」から元に戻せます。
 *
 * 案件が0件なので、売上・原価・粗利・給与のどの金額も変わりません。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「削除済みにする」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// ============================================================
// 対象を洗い出す
//   ・登録中（is_active = 1）
//   ・案件が1件も無い（キャンセル済みも含めて0件）
//   ・「同じ会社の取引先」が紐づいていない
// ============================================================
$stmt = $db->prepare("
    SELECT a.id, a.alliance_name, a.display_name, a.alliance_type,
           (SELECT COUNT(*) FROM sales_cases sc
             WHERE sc.company_id = a.company_id AND sc.alliance_id = a.id) AS case_all,
           (SELECT COUNT(*) FROM sales_workers w
             WHERE w.company_id = a.company_id AND w.alliance_id = a.id) AS worker_count
    FROM sales_alliances a
    WHERE a.company_id = ? AND a.is_active = 1 AND a.client_id IS NULL
    ORDER BY a.alliance_name
");
$stmt->execute([$cid]);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$targets = [];   // 削除済みにする
$keep    = [];   // 案件があるので残す
foreach ($all as $r) {
    if ((int)$r['case_all'] === 0) $targets[] = $r; else $keep[] = $r;
}

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if (!$targets) {
        $msg = '対象の外注先はありませんでした。';
        $done = true;
    } else {
        try {
            $db->beginTransaction();
            $up = $db->prepare('UPDATE sales_alliances SET is_active = 0, updated_at = NOW()
                                WHERE id = ? AND company_id = ?');
            $n = 0;
            foreach ($targets as $t) { $up->execute([(int)$t['id'], $cid]); $n++; }
            $db->commit();
            $msg  = "外注先 {$n}件を削除済みにしました。外注先タブの「削除済み」から元に戻せます。";
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_cleanup_alliances] ' . $e->getMessage());
            $msg = '処理に失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '使っていない外注先の整理';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-trash3 me-2"></i>使っていない外注先の整理</h1>
        <p>案件が1件も無く、取引先とも紐づいていない外注先を「削除済み」に移します。
           <strong>完全には消さないので、あとから元に戻せます。</strong></p>
    </div>

    <div class="alert alert-info">
        対象はすべて<strong>案件が0件</strong>です。売上・原価・粗利・給与のどの金額も変わりません。
        案件フォームの外注先プルダウンからは消えます。
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">削除済みにする</div>
            <div class="fs-3 fw-bold text-primary"><?= count($targets) ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">案件があるので残す</div>
            <div class="fs-3 fw-bold text-muted"><?= count($keep) ?></div><div class="small text-muted">件</div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">削除済みにする外注先（<?= count($targets) ?>件）</div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>正式名称</th><th>表記名</th><th>種別</th>
                        <th class="text-end">案件数</th><th class="text-end">紐づくスタッフ</th></tr>
                </thead>
                <tbody>
                <?php if (!$targets): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">対象はありません</td></tr>
                <?php endif; ?>
                <?php foreach ($targets as $t): ?>
                    <tr>
                        <td class="fw-medium"><?= h($t['alliance_name']) ?></td>
                        <td><?= h($t['display_name'] ?: $t['alliance_name']) ?></td>
                        <td class="small text-muted"><?= h($t['alliance_type']) ?></td>
                        <td class="text-end">0</td>
                        <td class="text-end"><?= (int)$t['worker_count'] ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            「紐づくスタッフ」に数字がある場合、そのスタッフの所属欄が空欄になります。金額には影響しません。
        </div>
    </div>

    <?php if ($keep): ?>
    <div class="card mb-4">
        <div class="card-header fw-bold">
            案件があるので残す外注先（<?= count($keep) ?>件）
            <span class="text-muted fw-normal small ms-1">取引先と紐づいていませんが、案件があるため触りません</span>
        </div>
        <div class="table-responsive" style="max-height:300px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>正式名称</th><th>種別</th><th class="text-end">案件数</th></tr></thead>
                <tbody>
                <?php foreach ($keep as $k): ?>
                    <tr><td><?= h($k['alliance_name']) ?></td>
                        <td class="small text-muted"><?= h($k['alliance_type']) ?></td>
                        <td class="text-end"><?= (int)$k['case_all'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('<?= count($targets) ?>件を削除済みにします。あとから元に戻せます。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg" <?= !$targets ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>削除済みにする
            </button>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。取引先一覧の「外注先」タブ →「削除済み」でご確認いただけます。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
