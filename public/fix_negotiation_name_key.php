<?php
/**
 * 【一度だけ実行するスクリプト】商談報告の「見分け用の名前」を作り直す
 *
 * 会社名の突き合わせルールに「半角カナ→全角カナ」を追加したため、
 * すでに登録済みの商談報告が持っている突き合わせ用の値（client_name_key）を
 * 新しいルールで作り直します。
 *
 * ・画面に表示される会社名（client_name）は 1文字も変更しません
 * ・案件データ・取引先一覧・アライアンス・売上には一切触れません
 * ・作り直すと同じ会社になってしまう組み合わせがある場合は、
 *   何も更新せずに一覧を表示して中断します（勝手に消したりまとめたりしません）
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

/** 新しい突き合わせルール（public/api/strategy_meeting.php の smNameKey と同じ） */
function fixNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'aKV');
    return mb_strtolower($n, 'UTF-8');
}

// ------------------------------------------------------------
// 現状を調べる（この時点では何も更新しない）
// ------------------------------------------------------------
$stmt = $db->prepare('SELECT id, client_name, client_name_key FROM strategy_meeting_negotiations
                      WHERE company_id = ? ORDER BY id');
$stmt->execute([$cid]);
$rows = $stmt->fetchAll();

$changes   = [];   // 作り直しが必要な行
$collision = [];   // 作り直すと同じ会社になってしまう組み合わせ
$byNewKey  = [];

foreach ($rows as $r) {
    $newKey = fixNameKey((string)$r['client_name']);
    $byNewKey[$newKey][] = $r;
    if ($newKey !== $r['client_name_key']) {
        $changes[] = ['id' => (int)$r['id'], 'name' => $r['client_name'],
                      'old' => $r['client_name_key'], 'new' => $newKey];
    }
}
foreach ($byNewKey as $k => $group) {
    if (count($group) > 1) $collision[$k] = $group;
}

$done = false;
$msg  = '';

// ------------------------------------------------------------
// 実行（衝突が無いときだけ）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if ($collision) {
        $msg = '同じ会社になってしまう組み合わせがあるため、実行しませんでした。';
    } elseif (!$changes) {
        $msg = '作り直しが必要な商談報告はありませんでした。';
        $done = true;
    } else {
        try {
            $db->beginTransaction();
            $upd = $db->prepare('UPDATE strategy_meeting_negotiations SET client_name_key = ?
                                 WHERE id = ? AND company_id = ?');
            foreach ($changes as $c) $upd->execute([$c['new'], $c['id'], $cid]);
            $db->commit();
            $msg  = count($changes) . '件の商談報告を作り直しました。';
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[fix_negotiation_name_key] ' . $e->getMessage());
            $msg = '作り直しに失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '商談報告の見分け用の名前を作り直す';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-tools me-2"></i>商談報告の見分け用の名前を作り直す</h1>
        <p>表示される会社名は変更しません。案件データ・取引先一覧・売上にも触れません。</p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">商談報告の件数</div>
            <div class="fs-3 fw-bold"><?= count($rows) ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">作り直しが必要</div>
            <div class="fs-3 fw-bold text-primary"><?= count($changes) ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">同じ会社になる組み合わせ</div>
            <div class="fs-3 fw-bold <?= $collision ? 'text-danger' : 'text-success' ?>"><?= count($collision) ?></div></div></div></div>
    </div>

    <?php if ($collision): ?>
        <div class="alert alert-danger">
            <strong>作り直すと同じ会社になってしまう組み合わせがあります。</strong><br>
            自動では処理できないため、実行できません。下記の会社について、
            どちらを残すか（または会社名を直すか）をご判断ください。
        </div>
        <div class="card mb-4"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>まとまってしまう名前</th><th>該当する商談報告</th></tr></thead>
                <tbody>
                <?php foreach ($collision as $k => $group): ?>
                    <tr>
                        <td class="fw-medium"><?= h($k) ?></td>
                        <td><?php foreach ($group as $g): ?>
                            <span class="badge bg-secondary me-1">#<?= (int)$g['id'] ?> <?= h($g['client_name']) ?></span>
                        <?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>

    <?php if ($changes && !$done): ?>
        <div class="card mb-4">
            <div class="card-header fw-bold">作り直す内容（表示名は変わりません）</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>会社名（表示・変更なし）</th><th>見分け用（現在）</th><th>見分け用（作り直し後）</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($changes as $c): ?>
                        <tr>
                            <td class="fw-medium"><?= h($c['name']) ?></td>
                            <td class="text-muted small"><?= h($c['old']) ?></td>
                            <td class="small"><?= h($c['new']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$done): ?>
        <?php if (!$changes && !$collision): ?>
            <div class="alert alert-success mb-0">
                作り直しが必要な商談報告はありません。このファイルは削除して問題ありません。
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button type="submit" class="btn btn-primary" <?= $collision ? 'disabled' : '' ?>>
                    <?= $collision ? '実行できません（上記をご確認ください）' : '実行する' ?>
                </button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-info mb-0">完了しました。このファイルを削除してください。</div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
