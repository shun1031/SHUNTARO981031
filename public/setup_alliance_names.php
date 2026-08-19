<?php
/**
 * 【一度だけ実行するスクリプト】外注先の正式名称・表記名を取引先に揃える
 *
 * 外注先マスタは名前が1つしか無かったため、案件一覧などに「近藤SEED」「U-plus」の
 * ような古い表記が残っていた。取引先と同じく「正式名称」「表記名」の2つを持たせ、
 * 紐づけ先の取引先からそのままコピーする。
 *
 * 紐づけ先の取引先が無い外注先（個人外注など）は、今の名前を正式名称・表記名の
 * 両方に入れるだけで、名前は変えない。
 *
 * ※このページは「外注先と取引先の紐づけ」を実行した後に開くこと。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「反映を実行」を押す。
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
// 外注先と、紐づけ先の取引先を読み込む
// ============================================================
$hasCols = true;
try {
    $stmt = $db->prepare('SELECT id, alliance_name, display_name, alliance_type, client_id, is_active
                          FROM sales_alliances WHERE company_id = ? ORDER BY id');
    $stmt->execute([$cid]);
    $alliances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasCols   = false;
    $alliances = [];
}

$clStmt = $db->prepare('SELECT id, client_name, display_name FROM sales_clients WHERE company_id = ?');
$clStmt->execute([$cid]);
$clientById = [];
foreach ($clStmt->fetchAll(PDO::FETCH_ASSOC) as $c) { $clientById[(int)$c['id']] = $c; }

// 案件数（判断材料）
$caseCount = [];
try {
    $ccStmt = $db->prepare("SELECT alliance_id, COUNT(*) AS c FROM sales_cases
                            WHERE company_id = ? AND alliance_id IS NOT NULL AND status <> 'cancelled'
                            GROUP BY alliance_id");
    $ccStmt->execute([$cid]);
    foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $caseCount[(int)$r['alliance_id']] = (int)$r['c'];
} catch (PDOException $e) { /* 表示用なので失敗しても続行 */ }

// ============================================================
// 1件ごとに反映後の名前を決める
// ============================================================
$plan     = [];   // 名前が変わるもの
$keepAs   = [];   // 変わらないもの（紐づけなし・すでに同じ）
$warnings = [];

foreach ($alliances as $a) {
    $curName = trim((string)$a['alliance_name']);
    $curDisp = trim((string)($a['display_name'] ?? ''));
    $cl      = $clientById[(int)($a['client_id'] ?? 0)] ?? null;

    if ($cl) {
        // 紐づけ先の取引先から正式名称・表記名をそのままコピーする
        $newName = trim((string)$cl['client_name']);
        $newDisp = trim((string)$cl['display_name']) !== '' ? trim((string)$cl['display_name']) : $newName;
    } else {
        // 紐づけ先が無いものは今の名前をそのまま使う（表記名だけ埋める）
        $newName = $curName;
        $newDisp = $curDisp !== '' ? $curDisp : $curName;
    }

    $row = [
        'id'       => (int)$a['id'],
        'type'     => (string)$a['alliance_type'],
        'cur_name' => $curName,
        'cur_disp' => $curDisp,
        'new_name' => $newName,
        'new_disp' => $newDisp,
        'linked'   => $cl !== null,
        'cases'    => $caseCount[(int)$a['id']] ?? 0,
        'active'   => (int)$a['is_active'] === 1,
    ];
    if ($newName !== $curName || $newDisp !== $curDisp) $plan[] = $row;
    else $keepAs[] = $row;
}

// 正式名称は外注先マスタでも重複禁止。反映後にぶつかる組み合わせが無いか調べる
$nameOwner = [];
foreach ($alliances as $a) { $nameOwner[trim((string)$a['alliance_name'])] = (int)$a['id']; }
$newNameSeen = [];
foreach ($plan as $p) {
    if (isset($newNameSeen[$p['new_name']])) {
        $warnings[] = "「{$p['new_name']}」に変える外注先が2件あります（#{$newNameSeen[$p['new_name']]} と #{$p['id']}）。"
                    . '同じ取引先に2つの外注先が紐づいているため、片方の紐づけを外してください';
    } else {
        $newNameSeen[$p['new_name']] = $p['id'];
    }
    $owner = $nameOwner[$p['new_name']] ?? null;
    if ($owner !== null && $owner !== $p['id']) {
        $warnings[] = "「{$p['new_name']}」は外注先 #{$owner} がすでに使っている名前です";
    }
}

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($hasCols && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if ($warnings) {
        $msg = '確認が必要な項目が残っているため実行しませんでした。';
    } else {
        try {
            $db->beginTransaction();
            $up = $db->prepare('UPDATE sales_alliances SET alliance_name = ?, display_name = ?, updated_at = NOW()
                                WHERE id = ? AND company_id = ?');
            $n = 0;
            foreach ($plan as $p) { $up->execute([$p['new_name'], $p['new_disp'], $p['id'], $cid]); $n++; }
            $db->commit();
            $msg  = "外注先 {$n}件の名前を取引先に揃えました。";
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_alliance_names] ' . $e->getMessage());
            $msg = '反映に失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '外注先の名前を取引先に揃える';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-pencil-square me-2"></i>外注先の名前を取引先に揃える</h1>
        <p>外注先にも「正式名称」と「表記名」を持たせ、紐づけ先の取引先からそのままコピーします。
           案件は外注先のIDで結びついているため、<strong>支払金額・原価は変わりません</strong>。</p>
    </div>

    <?php if (!$hasCols): ?>
        <div class="alert alert-danger">
            外注先マスタに表記名の列がまだありません。デプロイ後の初回起動で使えるようになります。
        </div>
    <?php endif; ?>

    <div class="alert alert-warning">
        <i class="bi bi-1-circle me-1"></i>
        <strong>先に「外注先と取引先の紐づけ」を実行してください。</strong>
        紐づいていない外注先は名前が変わりません。
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <?php if ($warnings): ?>
    <div class="card border-danger mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-exclamation-triangle me-1"></i>実行前に確認が必要（<?= count($warnings) ?>件）
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($warnings as $w): ?>
            <li class="list-group-item small text-danger"><?= h($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">名前が変わる外注先</div>
            <div class="fs-3 fw-bold text-primary"><?= count($plan) ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">変わらない外注先</div>
            <div class="fs-3 fw-bold text-muted"><?= count($keepAs) ?></div><div class="small text-muted">件</div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">名前が変わる外注先（<?= count($plan) ?>件）</div>
        <div class="table-responsive" style="max-height:560px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>今の名前</th><th style="width:24px"></th><th>新・正式名称</th><th>新・表記名</th>
                        <th>種別</th><th class="text-end">案件数</th></tr>
                </thead>
                <tbody>
                <?php if (!$plan): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">名前が変わる外注先はありません</td></tr>
                <?php endif; ?>
                <?php foreach ($plan as $p): ?>
                    <tr>
                        <td class="text-muted"><?= h($p['cur_name']) ?></td>
                        <td class="text-center text-primary">→</td>
                        <td class="fw-medium"><?= h($p['new_name']) ?></td>
                        <td class="fw-medium text-primary"><?= h($p['new_disp']) ?></td>
                        <td class="small text-muted"><?= h($p['type']) ?></td>
                        <td class="text-end"><?= $p['cases'] ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            案件一覧・アライアンス別売上・アライアンス人員管理などには「表記名」が出ます。
            請求書管理のアライアンスタブだけは「正式名称」で表示されます。
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">
            名前が変わらない外注先（<?= count($keepAs) ?>件）
            <span class="text-muted fw-normal small ms-1">取引先と紐づいていない、またはすでに同じ名前</span>
        </div>
        <div class="table-responsive" style="max-height:360px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>名前</th><th>種別</th><th>取引先との紐づけ</th><th class="text-end">案件数</th></tr>
                </thead>
                <tbody>
                <?php foreach ($keepAs as $k): ?>
                    <tr>
                        <td><?= h($k['cur_name']) ?></td>
                        <td class="small text-muted"><?= h($k['type']) ?></td>
                        <td class="small"><?= $k['linked'] ? 'あり' : '<span class="text-muted">なし</span>' ?></td>
                        <td class="text-end"><?= $k['cases'] ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('<?= count($plan) ?>件の名前を変更します。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg" <?= (!$hasCols || $warnings || !$plan) ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>反映を実行
            </button>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。常勤案件一覧・イベント案件一覧・アライアンス別売上・請求書管理でご確認ください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
