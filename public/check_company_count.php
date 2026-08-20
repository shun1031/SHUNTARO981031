<?php
/**
 * 【確認用ページ・データは一切変更しません】
 * 取引先一覧の件数と、戦略会議のパートナー数が合わない原因を1社ずつ突き止める。
 *
 * 取引先一覧: 案件が1件でもある（キャンセル以外・全期間・担当者の条件なし）
 * 戦略会議  : 今年度の確定案件があり、営業担当（外注先は管理者）が
 *             「社員一覧で営業担当チェックが付いた在籍中の正社員・自社外注」である
 *
 * 使い方: 管理者でログインした状態でこのURLを開くだけ。
 *         確認が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// 戦略会議と同じ年度（9月〜翌8月）
$fy       = (int)date('n') >= 9 ? (int)date('Y') + 1 : (int)date('Y');
$fyParams = [$fy - 1, $fy];
$FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";
$fyLabel  = substr((string)($fy - 1), 2) . '.9〜' . substr((string)$fy, 2) . '.8';

$repNames = getSalesRepCandidates($cid);
$repPh    = $repNames ? implode(',', array_fill(0, count($repNames), '?')) : "''";

// 外注先 → 同じ会社の取引先
$allyClient = [];
foreach ($db->query("SELECT id, client_id FROM sales_alliances WHERE company_id = " . (int)$cid) as $r) {
    $allyClient[(int)$r['id']] = $r['client_id'] !== null ? (int)$r['client_id'] : null;
}
$ckey = fn(int $id) => 'C' . $id;
$akey = function (int $aid) use ($allyClient, $ckey) {
    $c = $allyClient[$aid] ?? null;
    return $c ? $ckey($c) : 'A' . $aid;
};

// ============================================================
// 取引先一覧に出ている会社（＝今の画面の条件）
// ============================================================
$listClients = [];
[$clSql, $clParams] = tradeClientHasCaseSql($fy, $repNames);
$st = $db->prepare("SELECT id, " . clientLabelSql('sales_clients') . " AS label
                    FROM sales_clients
                    WHERE company_id = ? AND is_active = 1 AND {$clSql}");
$st->execute(array_merge([$cid], $clParams));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $listClients[(int)$r['id']] = (string)$r['label'];
}

$listAlliances = [];
[$alSql, $alParams] = tradeAllianceHasCaseSql($fy, $repNames);
$st = $db->prepare("SELECT id, " . allianceLabelSql('sales_alliances') . " AS label
                    FROM sales_alliances
                    WHERE company_id = ? AND is_active = 1 AND {$alSql}");
$st->execute(array_merge([$cid], $alParams));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $listAlliances[(int)$r['id']] = (string)$r['label'];
}

// 取引先一覧の「会社数」（重複を除いたキー）
$listKeys = [];
foreach ($listClients   as $id => $n) $listKeys[$ckey($id)]  = $n;
foreach ($listAlliances as $id => $n) { $k = $akey($id); if (!isset($listKeys[$k])) $listKeys[$k] = $n; }

// ============================================================
// 戦略会議が数えている会社
// ============================================================
$smClients = []; $smAlliances = [];
if ($repNames) {
    $st = $db->prepare("SELECT DISTINCT cl.id, " . clientLabelSql('cl') . " AS label
        FROM sales_cases sc JOIN sales_clients cl ON sc.client_id = cl.id
        LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
        WHERE sc.company_id = ? AND sc.status = 'confirmed' AND {$FY_WHERE}
          AND COALESCE(er.name, sc.sales_rep) IN ({$repPh})");
    $st->execute(array_merge([$cid], $fyParams, $repNames));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $smClients[(int)$r['id']] = (string)$r['label'];

    $st = $db->prepare("SELECT DISTINCT al.id, " . allianceLabelSql('al') . " AS label
        FROM sales_cases sc JOIN sales_alliances al ON sc.alliance_id = al.id
        LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
        WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.worker_type = 'アライアンス'
          AND {$FY_WHERE} AND COALESCE(em.name, sc.manager) IN ({$repPh})");
    $st->execute(array_merge([$cid], $fyParams, $repNames));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $smAlliances[(int)$r['id']] = (string)$r['label'];
}

$smKeys = [];
foreach ($smClients   as $id => $n) $smKeys[$ckey($id)]  = $n;
foreach ($smAlliances as $id => $n) { $k = $akey($id); if (!isset($smKeys[$k])) $smKeys[$k] = $n; }

// ============================================================
// 差分の理由を調べる
// ============================================================
/** 取引先が戦略会議に入らない理由 */
function reasonForClient(PDO $db, int $cid, int $clientId, string $FY_WHERE, array $fyParams, array $repNames, string $repPh): string {
    $s = $db->prepare("SELECT COUNT(*) FROM sales_cases sc WHERE sc.company_id=? AND sc.client_id=? AND sc.status='confirmed' AND {$FY_WHERE}");
    $s->execute(array_merge([$cid, $clientId], $fyParams));
    $fyConfirmed = (int)$s->fetchColumn();

    if ($fyConfirmed === 0) {
        $s = $db->prepare("SELECT COUNT(*) FROM sales_cases sc WHERE sc.company_id=? AND sc.client_id=? AND {$FY_WHERE} AND sc.status <> 'cancelled'");
        $s->execute(array_merge([$cid, $clientId], $fyParams));
        if ((int)$s->fetchColumn() > 0) return '今年度の案件はあるが、確定になっていない（下書き）';
        return '今年度（' . '9月〜8月' . '）の案件が無い（過去の案件のみ）';
    }
    // 今年度の確定案件はある → 営業担当が対象外
    $s = $db->prepare("SELECT DISTINCT COALESCE(er.name, sc.sales_rep) AS n
                       FROM sales_cases sc
                       LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
                       WHERE sc.company_id=? AND sc.client_id=? AND sc.status='confirmed' AND {$FY_WHERE}");
    $s->execute(array_merge([$cid, $clientId], $fyParams));
    $names = array_filter($s->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return '営業担当が営業マン一覧に居ない（案件の営業担当: ' . (implode(' / ', $names) ?: '未設定') . '）';
}

/** 外注先が戦略会議に入らない理由 */
function reasonForAlliance(PDO $db, int $cid, int $allianceId, string $FY_WHERE, array $fyParams): string {
    $s = $db->prepare("SELECT COUNT(*) FROM sales_cases sc WHERE sc.company_id=? AND sc.alliance_id=? AND sc.status='confirmed' AND sc.worker_type='アライアンス' AND {$FY_WHERE}");
    $s->execute(array_merge([$cid, $allianceId], $fyParams));
    if ((int)$s->fetchColumn() === 0) {
        $s = $db->prepare("SELECT COUNT(*) FROM sales_cases sc WHERE sc.company_id=? AND sc.alliance_id=? AND sc.status='confirmed' AND {$FY_WHERE}");
        $s->execute(array_merge([$cid, $allianceId], $fyParams));
        if ((int)$s->fetchColumn() > 0) return 'スタッフ区分が「アライアンス」以外の案件しかない';
        return '今年度の確定案件が無い';
    }
    $s = $db->prepare("SELECT DISTINCT COALESCE(em.name, sc.manager) AS n
                       FROM sales_cases sc
                       LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
                       WHERE sc.company_id=? AND sc.alliance_id=? AND sc.status='confirmed' AND sc.worker_type='アライアンス' AND {$FY_WHERE}");
    $s->execute(array_merge([$cid, $allianceId], $fyParams));
    $names = array_filter($s->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return '管理者が営業マン一覧に居ない（案件の管理者: ' . (implode(' / ', $names) ?: '未設定') . '）';
}

$onlyList = [];   // 取引先一覧には出るが戦略会議に入っていない
foreach ($listClients as $id => $label) {
    if (isset($smKeys[$ckey($id)])) continue;
    $onlyList[] = ['種別' => '取引先', '会社' => $label,
                   '理由' => reasonForClient($db, $cid, $id, $FY_WHERE, $fyParams, $repNames, $repPh)];
}
foreach ($listAlliances as $id => $label) {
    if (isset($smKeys[$akey($id)])) continue;
    $onlyList[] = ['種別' => '外注先', '会社' => $label,
                   '理由' => reasonForAlliance($db, $cid, $id, $FY_WHERE, $fyParams)];
}

$onlySm = [];   // 戦略会議に入っているが取引先一覧に出ていない
foreach ($smKeys as $k => $label) {
    if (!isset($listKeys[$k])) $onlySm[] = $label;
}

$pageTitle = '会社数の突き合わせ';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-search me-2"></i>会社数の突き合わせ</h1>
        <p>取引先一覧の件数と戦略会議のパートナー数が合わない原因を1社ずつ調べます。
           <strong>このページはデータを読むだけで、何も変更しません。</strong></p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">取引先タブ</div>
            <div class="fs-3 fw-bold"><?= count($listClients) ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">外注先タブ</div>
            <div class="fs-3 fw-bold"><?= count($listAlliances) ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">重複を除いた合計</div>
            <div class="fs-3 fw-bold text-primary"><?= count($listKeys) ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">戦略会議のパートナー数</div>
            <div class="fs-3 fw-bold text-success"><?= count($smKeys) ?></div><div class="small text-muted">社</div></div></div></div>
    </div>

    <div class="alert alert-info" style="font-size:.85rem">
        戦略会議の対象期間：<strong><?= h($fyLabel) ?></strong>／
        営業マン一覧（<?= count($repNames) ?>名）：<?= h(implode('、', $repNames)) ?: '（該当なし）' ?>
    </div>

    <div class="card border-primary mb-4">
        <div class="card-header bg-primary text-white fw-bold">
            取引先一覧には出るが、戦略会議に入っていない会社（<?= count($onlyList) ?>社）
        </div>
        <div class="table-responsive" style="max-height:520px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.83rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th style="width:80px">種別</th><th style="width:260px">会社</th><th>戦略会議に入らない理由</th></tr>
                </thead>
                <tbody>
                <?php if (!$onlyList): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">ありません</td></tr>
                <?php endif; ?>
                <?php foreach ($onlyList as $r): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?= h($r['種別']) ?></span></td>
                        <td class="fw-medium"><?= h($r['会社']) ?></td>
                        <td class="small"><?= h($r['理由']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">
            戦略会議に入っているが、取引先一覧に出ていない会社（<?= count($onlySm) ?>社）
        </div>
        <div class="table-responsive" style="max-height:300px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.83rem">
                <tbody>
                <?php if (!$onlySm): ?>
                    <tr><td class="text-center text-muted py-3">ありません</td></tr>
                <?php endif; ?>
                <?php foreach ($onlySm as $n): ?>
                    <tr><td><?= h($n) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            ここに出る場合は、削除済みにした会社が戦略会議側で数えられている可能性があります。
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
