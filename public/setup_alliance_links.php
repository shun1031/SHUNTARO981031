<?php
/**
 * 【一度だけ実行するスクリプト】外注先に「同じ会社の取引先」をまとめて紐づける
 *
 * 外注先マスタと取引先マスタに同じ会社が登録されていることがある
 * （LANGIS・Pachira・U-Plus・onetale・T-Group・ASB・LIFIX など）。
 * 戦略会議の会社数でこれを1社として数えるため、取引先マスタのIDを持たせる。
 * 以前は会社名の文字列で突き合わせていたので、名前を変えると2社に分かれてしまっていた。
 *
 * ※このページは「取引先一覧の整備（setup_clients_master.php）」を実行した後に開くこと。
 *
 * 紐づけ先が無い外注先（個人外注など）はそのままにします。
 * あとから変更したい場合は 管理者メニュー →「売上マスタ」→「外注先」から編集できます。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「紐づけを実行」を押す。
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
// 外注先名 → 取引先の正式名称（自動照合では結び付かないものだけ）
// ============================================================
$LINK = [
    'ASXEED'           => '株式会社AXSEED',
    'LinocaCreation'   => '合同会社Linoa Creation（Atmos）',
    'Linoa Creation'   => '合同会社Linoa Creation（Atmos）',
    'オアシス'         => '株式会社OASIS',
    'コーレン'         => '合同会社COREN',
    'ネクストプレイス' => '株式会社NextPlace',
    'ネクシア'         => '株式会社ネクシア',
    '渡邊拓斗'         => '渡邉拓斗',
    '近藤SEED'         => 'SEED（近藤）',
    'ラクサム'         => '株式会社LaXum',
    'Laxum'            => '株式会社LaXum',
    'テレポート'       => '株式会社テレポートモバイル',
    'クニトコアセット' => '株式会社KunitokoAsset',
    'kunitoko asset'   => '株式会社KunitokoAsset',
    'グラスト'         => '株式会社GRUST',
    'センターフロー'   => '株式会社CenterFlow',
    'フリーダム'       => '株式会社FREEDOM',
    'コンフィアンス'   => '株式会社コンフィアンスグループ',
];

/** 照合キー（setup_negotiation_links.php と同じ考え方） */
function salKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'asKV');
    $n = mb_strtolower($n, 'UTF-8');
    $n = str_replace(['’', '‘', '`', '´', '＇'], "'", $n);
    $n = str_replace(['株式会社', '合同会社', '有限会社', '合資会社', '合名会社', '(株)', '㈱', '（株）'], '', $n);
    // ピリオドと中黒だけ落とす。ハイフンや長音は落とさない（別会社の誤結合を防ぐ）
    $n = str_replace(['.', '．', '・'], '', $n);
    $n = preg_replace('/[\s\x{3000}]+/u', '', $n);
    return $n;
}

/** 表記名があれば表記名、なければ会社名 */
function salLabel(array $c): string {
    $d = trim((string)($c['display_name'] ?? ''));
    return $d !== '' ? $d : trim((string)$c['client_name']);
}

// ============================================================
// マスタを読み込む
// ============================================================
$clStmt = $db->prepare('SELECT id, client_name, display_name FROM sales_clients WHERE company_id = ? ORDER BY id');
$clStmt->execute([$cid]);
$clients = $clStmt->fetchAll(PDO::FETCH_ASSOC);

$clientByKey = [];
foreach ($clients as $c) {
    foreach ([$c['client_name'], $c['display_name']] as $n) {
        $k = salKey((string)$n);
        if ($k !== '' && !isset($clientByKey[$k])) $clientByKey[$k] = $c;
    }
}
$clientById = [];
foreach ($clients as $c) { $clientById[(int)$c['id']] = $c; }

// client_id 列が無い環境ではこのページは動かせない（マイグレーション前）
$hasClientId = true;
try {
    $alStmt = $db->prepare('SELECT id, alliance_name, alliance_type, client_id, is_active
                            FROM sales_alliances WHERE company_id = ? ORDER BY id');
    $alStmt->execute([$cid]);
    $alliances = $alStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasClientId = false;
    $alliances   = [];
}

// 外注先の案件数（判断材料として表示）
$caseCount = [];
try {
    $ccStmt = $db->prepare("SELECT alliance_id, COUNT(*) AS c FROM sales_cases
                            WHERE company_id = ? AND alliance_id IS NOT NULL AND status <> 'cancelled'
                            GROUP BY alliance_id");
    $ccStmt->execute([$cid]);
    foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $caseCount[(int)$r['alliance_id']] = (int)$r['c'];
} catch (PDOException $e) { /* 表示用なので失敗しても続行 */ }

// ============================================================
// 1件ごとに紐づけ先を決める
// ============================================================
$toLink  = [];   // これから紐づける
$already = [];   // すでに紐づいている
$none    = [];   // 紐づけ先が無い（そのまま）

foreach ($alliances as $a) {
    $name   = trim((string)$a['alliance_name']);
    $target = $LINK[$name] ?? $name;
    $cl     = $clientByKey[salKey($target)] ?? null;
    $row    = $a + ['cases' => $caseCount[(int)$a['id']] ?? 0];

    if ($cl && (int)($a['client_id'] ?? 0) === (int)$cl['id']) {
        $already[] = $row + ['client' => $cl];
    } elseif ($cl) {
        $toLink[] = $row + ['client' => $cl];
    } else {
        // 現在の紐づけを表示できるようにしておく（手で設定済みの場合を消さないため）
        $cur = $clientById[(int)($a['client_id'] ?? 0)] ?? null;
        $none[] = $row + ['client' => $cur];
    }
}

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($hasClientId && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    try {
        $db->beginTransaction();
        $up = $db->prepare('UPDATE sales_alliances SET client_id = ?, updated_at = NOW()
                            WHERE id = ? AND company_id = ?');
        $n = 0;
        foreach ($toLink as $t) {
            $up->execute([(int)$t['client']['id'], (int)$t['id'], $cid]);
            $n++;
        }
        $db->commit();
        $msg  = "外注先 {$n}件に取引先を紐づけました。";
        $done = true;
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[setup_alliance_links] ' . $e->getMessage());
        $msg = '紐づけに失敗したため、変更をすべて取り消しました。' . $e->getMessage();
    }
}

$pageTitle = '外注先と取引先の紐づけ';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-diagram-3 me-2"></i>外注先と取引先の紐づけ</h1>
        <p>外注先マスタと取引先マスタに同じ会社が登録されている場合に、両者をIDで結びます。
           戦略会議の会社数で1社として数えるために使います。他の画面（売上・請求・給与）には影響しません。</p>
    </div>

    <?php if (!$hasClientId): ?>
        <div class="alert alert-danger">
            外注先マスタに紐づけ用の列がまだありません。デプロイ後の初回起動でマイグレーションが走ると使えるようになります。
        </div>
    <?php endif; ?>

    <div class="alert alert-warning">
        <i class="bi bi-1-circle me-1"></i>
        <strong>先に「取引先一覧の整備」を実行してください。</strong>
        まだの場合、紐づけ先が見つからない外注先が多く出ます。
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">紐づける</div>
            <div class="fs-3 fw-bold text-primary"><?= count($toLink) ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">すでに紐づいている</div>
            <div class="fs-3 fw-bold text-muted"><?= count($already) ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">紐づけ先なし（そのまま）</div>
            <div class="fs-3 fw-bold text-muted"><?= count($none) ?></div><div class="small text-muted">件</div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">紐づける外注先（<?= count($toLink) ?>件）</div>
        <div class="table-responsive" style="max-height:520px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>外注先名</th><th>種別</th><th style="width:24px"></th>
                        <th>同じ会社の取引先（表記名）</th><th>正式名称</th><th class="text-end">案件数</th></tr>
                </thead>
                <tbody>
                <?php if (!$toLink): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">紐づける外注先はありません</td></tr>
                <?php endif; ?>
                <?php foreach ($toLink as $t): ?>
                    <tr>
                        <td class="fw-medium"><?= h($t['alliance_name']) ?></td>
                        <td class="small text-muted"><?= h($t['alliance_type']) ?></td>
                        <td class="text-center text-primary">→</td>
                        <td class="fw-medium text-primary"><?= h(salLabel($t['client'])) ?></td>
                        <td class="small text-muted"><?= h($t['client']['client_name']) ?></td>
                        <td class="text-end"><?= $t['cases'] ?: '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($already): ?>
    <div class="card mb-4">
        <div class="card-header fw-bold">すでに紐づいている外注先（<?= count($already) ?>件）</div>
        <div class="table-responsive" style="max-height:300px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>外注先名</th><th>紐づけ先の取引先</th></tr></thead>
                <tbody>
                <?php foreach ($already as $a): ?>
                    <tr><td><?= h($a['alliance_name']) ?></td><td><?= h(salLabel($a['client'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">
            紐づけ先が見つからない外注先（<?= count($none) ?>件）
            <span class="text-muted fw-normal small ms-1">そのままにします。単独の会社として数えられます</span>
        </div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>外注先名</th><th>種別</th><th>現在の紐づけ</th><th class="text-end">案件数</th><th>状態</th></tr>
                </thead>
                <tbody>
                <?php if (!$none): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">ありません</td></tr>
                <?php endif; ?>
                <?php foreach ($none as $n): ?>
                    <tr>
                        <td><?= h($n['alliance_name']) ?></td>
                        <td class="small text-muted"><?= h($n['alliance_type']) ?></td>
                        <td class="small"><?= $n['client'] ? h(salLabel($n['client'])) : '<span class="text-muted">-</span>' ?></td>
                        <td class="text-end"><?= $n['cases'] ?: '-' ?></td>
                        <td class="small text-muted"><?= (int)$n['is_active'] === 1 ? '有効' : '無効' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            取引先一覧にも同じ会社があるものが混ざっていないかご確認ください。
            混ざっている場合は 管理者メニュー →「売上マスタ」→「外注先」から個別に紐づけできます。
        </div>
    </div>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('<?= count($toLink) ?>件を紐づけます。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg" <?= (!$hasClientId || !$toLink) ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>紐づけを実行
            </button>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。戦略会議の会社数をご確認ください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
