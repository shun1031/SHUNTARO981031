<?php
/**
 * 【一度だけ実行するスクリプト】商談報告に取引先IDを紐づける
 *
 * 商談報告はこれまで「会社名の文字列」で取引先と突き合わせていたため、
 * 取引先一覧で表記名を変えると同じ会社が2社に数えられてしまっていた。
 * 取引先IDで結び直すことで、以後は名前を何度変えても集計が崩れなくなる。
 *
 * ※このページは「取引先一覧の整備（setup_clients_master.php）」を実行した後に開くこと。
 *   先に実行していないと、紐づけ先の取引先が見つからない行が大量に出る。
 *
 * ユーザー確認済みの取り決め:
 *   - 商談報告の「SEED」と「近藤SEED」は同じ会社。「SEED（近藤）」1件に統合する
 *   - 取引先一覧に無い8社（クリア/flueve/玉腰/リポン/T'sソリューション/youth/アルティム/Assh）は
 *     取引先一覧に暫定登録済みなので、そこへ紐づける
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
// 商談報告の会社名 → 取引先の正式名称（自動照合では結び付かないものだけ）
// ============================================================
$LINK = [
    'プラウドパートナー'      => '株式会社Proud Partners',
    'クラウドエージェント'    => 'CloudAdent株式会社',
    'エクシード'              => '株式会社EXceed',
    'オアシス'                => '株式会社OASIS',
    'コンフィアンス'          => '株式会社コンフィアンスグループ',
    'フリーダム'              => '株式会社FREEDOM',
    'SEED'                    => 'SEED（近藤）',
    '近藤SEED'                => 'SEED（近藤）',
    'アトラス'                => 'Attrus株式会社',
    'ワニー'                  => '株式会社wanny',
    'リテンパー'              => '株式会社Retemper',
    'フリプロ'                => '株式会社Free Professional',
    'サニー'                  => 'SUNNY株式会社',
    'レクラン'                => '株式会社Lecrin',
    'リーブス'                => '株式会社LEAVES',
    'アルベラ'                => '株式会社ALBERA',
    'loutus'                  => 'lotus株式会社',
    'Grase'                   => '株式会社GRACE',
    'アスシード'              => '株式会社AXSEED',
    'ライブリッジ'            => '株式会社libridge',
    'グラスト'                => '株式会社GRUST',
    'ラクサム'                => '株式会社LaXum',
    'Laxum'                   => '株式会社LaXum',
    'ネクストアシスト'        => '株式会社nextassist',
    'FF'                      => '株式会社Face to Faith',
    'クニトコアセット'        => '株式会社KunitokoAsset',
    'badass'                  => '合同会社baddass',
    'アンダーウィル'          => '合同会社UnderWill',
    'ANW（アナザーウェーブ）' => '株式会社アナザーウェイブ',
    'アナザーウェーブ'        => '株式会社アナザーウェイブ',
    'センターフロー'          => '株式会社CenterFlow',
    'テレポート'              => '株式会社テレポートモバイル',
    'コーレン'                => '合同会社COREN',
    'ネクストプレイス'        => '株式会社NextPlace',
    'ASXEED'                  => '株式会社AXSEED',
];

/**
 * 照合キー。setup_clients_master.php と同じ考え方。
 * 全角→半角・小文字・空白除去・法人格の除去・アポストロフィの統一に加えて、
 * ピリオドと中黒も落とす（VIN と V.I.N、YWC と Y.W.C を同じものとして扱うため）
 */
function snlKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'asKV');
    $n = mb_strtolower($n, 'UTF-8');
    $n = str_replace(['’', '‘', '`', '´', '＇'], "'", $n);
    $n = str_replace(['株式会社', '合同会社', '有限会社', '合資会社', '合名会社', '(株)', '㈱', '（株）'], '', $n);
    // ピリオドと中黒だけ落とす（VIN と V.I.N、YWC と Y.W.C を同じとみなすため）。
    // ハイフンや長音は落とさない ── 別会社を誤って同じ会社とみなす危険があるため
    $n = str_replace(['.', '．', '・'], '', $n);
    $n = preg_replace('/[\s\x{3000}]+/u', '', $n);
    return $n;
}

// ============================================================
// 取引先マスタ（紐づけ先）
// ============================================================
$clStmt = $db->prepare('SELECT id, client_name, display_name FROM sales_clients WHERE company_id = ? ORDER BY id');
$clStmt->execute([$cid]);
$clients = $clStmt->fetchAll(PDO::FETCH_ASSOC);

$clientByKey = [];
foreach ($clients as $c) {
    foreach ([$c['client_name'], $c['display_name']] as $n) {
        $k = snlKey((string)$n);
        if ($k !== '' && !isset($clientByKey[$k])) $clientByKey[$k] = $c;
    }
}

// ============================================================
// 商談報告
// ============================================================
$ngStmt = $db->prepare('SELECT id, client_id, client_name, client_name_key, rep_name, status,
                               first_report_ym, candidate_ym, active_ym, excluded_ym, note
                        FROM strategy_meeting_negotiations WHERE company_id = ? ORDER BY id');
$ngStmt->execute([$cid]);
$negs = $ngStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 1件ごとに紐づけ先を決める
// ============================================================
$linked   = [];   // 紐づけできた
$unlinked = [];   // 紐づけ先が見つからない
$byClient = [];   // 取引先ID => 商談報告の行（複数あれば統合が必要）

foreach ($negs as $n) {
    $name = (string)$n['client_name'];
    // 対応表を優先し、無ければ会社名そのままで照合する
    $targetName = $LINK[trim($name)] ?? $name;
    $cl = $clientByKey[snlKey($targetName)] ?? null;

    if (!$cl) {
        $unlinked[] = $n + ['tried' => $targetName];
        continue;
    }
    $row = $n + ['client' => $cl];
    $linked[] = $row;
    $byClient[(int)$cl['id']][] = $row;
}

// 同じ取引先を指す商談報告が2件以上ある → 1件に統合する
$merges = [];
foreach ($byClient as $clientId => $rows) {
    if (count($rows) < 2) continue;
    usort($rows, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $merges[] = ['client_id' => $clientId, 'client' => $rows[0]['client'], 'rows' => $rows];
}

/** 統合後の年月を決める（最初になった月を残す。片方でも会社数に載っていれば除外は解除） */
function snlMergeYms(array $rows): array {
    $minOf = function (string $col) use ($rows) {
        $vals = [];
        foreach ($rows as $r) { if ($r[$col] !== null) $vals[] = (int)$r[$col]; }
        return $vals ? min($vals) : null;
    };
    // 1行でも除外されていない（＝会社数に載っている）行があれば、統合後も載せる
    $anyAlive = false;
    foreach ($rows as $r) { if ($r['excluded_ym'] === null) { $anyAlive = true; break; } }
    return [
        'first'    => $minOf('first_report_ym'),
        'cand'     => $minOf('candidate_ym'),
        'active'   => $minOf('active_ym'),
        'excluded' => $anyAlive ? null : $minOf('excluded_ym'),
    ];
}

// 統合で消える行のID（更新対象から外す）
$mergeAwayIds = [];
foreach ($merges as $m) {
    foreach (array_slice($m['rows'], 1) as $r) $mergeAwayIds[(int)$r['id']] = true;
}

$cntLink  = 0;   // client_id を入れる件数
$cntSame  = 0;   // すでに正しく紐づいている件数
foreach ($linked as $l) {
    if (isset($mergeAwayIds[(int)$l['id']])) continue;
    if ((int)($l['client_id'] ?? 0) === (int)$l['client']['id']) $cntSame++; else $cntLink++;
}

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if ($unlinked) {
        $msg = '紐づけ先が見つからない商談報告が残っているため実行しませんでした。'
             . '先に「取引先一覧の整備」を実行してください。';
    } else {
        try {
            $db->beginTransaction();

            // --- 1) 統合（残す行に年月をまとめ、余った行を削除する）---
            $nMerged = 0;
            foreach ($merges as $m) {
                $keep = $m['rows'][0];
                $ym   = snlMergeYms($m['rows']);
                // 備考は各行の内容を改行でつなぐ（消えないようにする）
                $notes = [];
                foreach ($m['rows'] as $r) {
                    $t = trim((string)($r['note'] ?? ''));
                    if ($t !== '' && !in_array($t, $notes, true)) $notes[] = $t;
                }
                $db->prepare('UPDATE strategy_meeting_negotiations
                              SET first_report_ym = ?, candidate_ym = ?, active_ym = ?, excluded_ym = ?,
                                  note = ?, updated_at = NOW()
                              WHERE id = ? AND company_id = ?')
                   ->execute([$ym['first'], $ym['cand'], $ym['active'], $ym['excluded'],
                              $notes ? implode("\n", $notes) : null,
                              (int)$keep['id'], $cid]);
                foreach (array_slice($m['rows'], 1) as $r) {
                    $db->prepare('DELETE FROM strategy_meeting_negotiations WHERE id = ? AND company_id = ?')
                       ->execute([(int)$r['id'], $cid]);
                    $nMerged++;
                }
            }

            // --- 2) 取引先IDを入れる。会社名も取引先マスタの表記名に揃える ---
            $up = $db->prepare('UPDATE strategy_meeting_negotiations
                                SET client_id = ?, client_name = ?, client_name_key = ?, updated_at = NOW()
                                WHERE id = ? AND company_id = ?');
            $nUp = 0;
            foreach ($linked as $l) {
                if (isset($mergeAwayIds[(int)$l['id']])) continue;   // 統合で消えた行
                $cl    = $l['client'];
                $label = trim((string)$cl['display_name']) !== '' ? $cl['display_name'] : $cl['client_name'];
                $key   = mb_strtolower(mb_convert_kana((string)$label, 'aKV'), 'UTF-8');
                $up->execute([(int)$cl['id'], $label, $key, (int)$l['id'], $cid]);
                $nUp++;
            }

            $db->commit();
            $msg  = "商談報告 {$nUp}件に取引先を紐づけました"
                  . ($nMerged ? "（うち{$nMerged}件を統合して削除）" : '') . '。';
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_negotiation_links] ' . $e->getMessage());
            $msg = '紐づけに失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '商談報告の取引先紐づけ';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-link-45deg me-2"></i>商談報告の取引先紐づけ</h1>
        <p>商談報告を取引先一覧のIDで結び直します。これ以降は取引先一覧で会社名・表記名を変えても、
           戦略会議の会社数が二重になりません。</p>
    </div>

    <div class="alert alert-warning">
        <i class="bi bi-1-circle me-1"></i>
        <strong>先に「取引先一覧の整備」を実行してください。</strong>
        まだの場合、紐づけ先が見つからない行が下に大量に出ます。
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">商談報告</div>
            <div class="fs-3 fw-bold"><?= count($negs) ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">紐づける</div>
            <div class="fs-3 fw-bold text-primary"><?= $cntLink ?></div><div class="small text-muted">件</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">統合して1件にする</div>
            <div class="fs-3 fw-bold text-warning"><?= count($merges) ?></div><div class="small text-muted">組</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">紐づけ先が不明</div>
            <div class="fs-3 fw-bold <?= $unlinked ? 'text-danger' : 'text-muted' ?>"><?= count($unlinked) ?></div>
            <div class="small text-muted">件</div></div></div></div>
    </div>

    <?php if ($unlinked): ?>
    <div class="card border-danger mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-exclamation-triangle me-1"></i>紐づけ先が見つからない商談報告（<?= count($unlinked) ?>件）
        </div>
        <div class="table-responsive" style="max-height:360px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>商談報告の会社名</th><th>探した名前</th><th>営業担当</th><th>ステータス</th></tr></thead>
                <tbody>
                <?php foreach ($unlinked as $u): ?>
                    <tr><td class="fw-medium"><?= h($u['client_name']) ?></td>
                        <td class="small text-muted"><?= h($u['tried']) ?></td>
                        <td class="small"><?= h($u['rep_name']) ?></td>
                        <td class="small"><?= h($u['status']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            この一覧が空になるまで「紐づけを実行」は動きません。
            該当の会社を取引先一覧に追加するか、商談報告を削除してください。
        </div>
    </div>
    <?php endif; ?>

    <?php if ($merges): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning fw-bold">
            <i class="bi bi-arrow-left-right me-1"></i>1件に統合する商談報告（<?= count($merges) ?>組）
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light">
                    <tr><th>統合先の取引先</th><th>統合される商談報告</th><th>統合後の対象年月</th></tr>
                </thead>
                <tbody>
                <?php foreach ($merges as $m): $ym = snlMergeYms($m['rows']); ?>
                    <tr>
                        <td class="fw-medium">
                            <?= h(trim((string)$m['client']['display_name']) !== '' ? $m['client']['display_name'] : $m['client']['client_name']) ?>
                        </td>
                        <td>
                            <?php foreach ($m['rows'] as $i => $r): ?>
                                <div>
                                    <span class="badge bg-<?= $i === 0 ? 'primary' : 'secondary' ?> me-1"><?= $i === 0 ? '残す' : '削除' ?></span>
                                    <?= h($r['client_name']) ?>
                                    <span class="text-muted small">
                                        （<?= h($r['rep_name']) ?> / <?= h($r['status']) ?>）
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="small text-muted">
                            初回 <?= $ym['first'] ? (int)floor($ym['first'] / 100) . '年' . ($ym['first'] % 100) . '月' : '-' ?><br>
                            候補 <?= $ym['cand']  ? (int)floor($ym['cand']  / 100) . '年' . ($ym['cand']  % 100) . '月' : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            統合すると会社数が<?= array_sum(array_map(fn($m) => count($m['rows']) - 1, $merges)) ?>社減ります（同じ会社を重ねて数えていた分）。
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">
            紐づける商談報告（<?= $cntLink ?>件）
            <?php if ($cntSame): ?>
                <span class="text-muted fw-normal small ms-1">すでに正しく紐づいている <?= $cntSame ?>件は除く</span>
            <?php endif; ?>
        </div>
        <div class="table-responsive" style="max-height:560px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>商談報告の会社名</th><th style="width:24px"></th><th>紐づける取引先（表記名）</th>
                        <th>正式名称</th><th>営業担当</th><th>ステータス</th></tr>
                </thead>
                <tbody>
                <?php foreach ($linked as $l):
                    if (isset($mergeAwayIds[(int)$l['id']])) continue;
                    if ((int)($l['client_id'] ?? 0) === (int)$l['client']['id']) continue;
                    $disp = trim((string)$l['client']['display_name']) !== '' ? $l['client']['display_name'] : $l['client']['client_name'];
                    $changed = trim((string)$l['client_name']) !== trim((string)$disp);
                ?>
                    <tr>
                        <td class="<?= $changed ? 'text-muted' : '' ?>"><?= h($l['client_name']) ?></td>
                        <td class="text-center text-primary">→</td>
                        <td class="fw-medium <?= $changed ? 'text-primary' : '' ?>"><?= h($disp) ?></td>
                        <td class="small text-muted"><?= h($l['client']['client_name']) ?></td>
                        <td class="small"><?= h($l['rep_name']) ?></td>
                        <td class="small"><?= h($l['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            商談報告に保存されている会社名も、取引先一覧の表記名に揃えます。
        </div>
    </div>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('<?= $cntLink ?>件を紐づけ、<?= count($merges) ?>組を統合します。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg"
                    <?= ($unlinked || ($cntLink + count($merges)) === 0) ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>紐づけを実行
            </button>
            <?php if ($unlinked): ?>
                <span class="text-danger small ms-2">紐づけ先が不明な行が残っています</span>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。戦略会議の画面で会社数と年間推移グラフをご確認ください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
