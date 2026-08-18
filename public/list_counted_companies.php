<?php
/**
 * 【確認用スクリプト・読み取り専用】会社数実績の内訳を一覧表示する
 *
 * 戦略会議の「会社数実績」に数えられている会社を、年間推移とまったく同じ条件で
 * 集計して一覧にします。どの会社が何を根拠に計上されているかを確認できます。
 *
 * このスクリプトはデータを一切変更しません（SELECT のみ）。
 * 確認が終わったらこのファイルを削除してください。
 *
 * 使い方: 管理者でログインした状態でこのURLを開く。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

/** 会社名の突き合わせキー（public/api/strategy_meeting.php の smNameKey と同じ） */
function lcNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'aKV');
    return mb_strtolower($n, 'UTF-8');
}
function lcYm(?int $v): string {
    if (!$v) return '';
    return (int)floor($v / 100) . '年' . ((int)$v % 100) . '月';
}

// 対象年度・対象月（年間推移と同じ。既定は当月）
$fy    = (int)date('n') >= 9 ? (int)date('Y') + 1 : (int)date('Y');
$upto  = (int)date('Y') * 100 + (int)date('n');
$FY    = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";
$repNames = getSalesRepCandidates($cid);

// ------------------------------------------------------------
// 1. 案件データから計上されている会社（取引先・外注先）
// ------------------------------------------------------------
$rows = [];   // キー => 表示用データ
if ($repNames) {
    $ph = implode(',', array_fill(0, count($repNames), '?'));

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(cl.display_name), ''), cl.client_name) AS name,
                                 MIN(sc.case_year * 100 + sc.case_month) AS first_ym,
                                 MIN(COALESCE(er.name, sc.sales_rep)) AS rep
                          FROM sales_cases sc
                          JOIN sales_clients cl ON sc.client_id = cl.id
                          LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
                          WHERE sc.company_id = ? AND sc.status = 'confirmed' AND {$FY}
                            AND COALESCE(er.name, sc.sales_rep) IN ({$ph})
                          GROUP BY cl.id, cl.display_name, cl.client_name");
    $stmt->execute(array_merge([$cid, $fy - 1, $fy], $repNames));
    foreach ($stmt->fetchAll() as $r) {
        $k = lcNameKey((string)$r['name']);
        if ($k === '') continue;
        $rows[$k] = ['name' => $r['name'], 'src' => '取引先', 'rep' => $r['rep'],
                     'ym' => (int)$r['first_ym'], 'active' => true];
    }

    $stmt = $db->prepare("SELECT al.alliance_name AS name,
                                 MIN(sc.case_year * 100 + sc.case_month) AS first_ym,
                                 MIN(COALESCE(em.name, sc.manager)) AS rep
                          FROM sales_cases sc
                          JOIN sales_alliances al ON sc.alliance_id = al.id
                          LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
                          WHERE sc.company_id = ? AND sc.status = 'confirmed'
                            AND sc.worker_type = 'アライアンス' AND {$FY}
                            AND COALESCE(em.name, sc.manager) IN ({$ph})
                          GROUP BY al.id, al.alliance_name");
    $stmt->execute(array_merge([$cid, $fy - 1, $fy], $repNames));
    foreach ($stmt->fetchAll() as $r) {
        $k = lcNameKey((string)$r['name']);
        if ($k === '') continue;
        if (isset($rows[$k])) {   // 取引先にも同名がある場合は早い方の月を採用
            $rows[$k]['ym'] = min($rows[$k]['ym'], (int)$r['first_ym']);
            $rows[$k]['src'] = '取引先・外注先';
            continue;
        }
        $rows[$k] = ['name' => $r['name'], 'src' => '外注先', 'rep' => $r['rep'],
                     'ym' => (int)$r['first_ym'], 'active' => true];
    }
}
$caseCount = count($rows);

// ------------------------------------------------------------
// 2. 商談報告から計上されている会社
// ------------------------------------------------------------
$stmt = $db->prepare('SELECT client_name, client_name_key, rep_name, status,
                             candidate_ym, active_ym, excluded_ym
                      FROM strategy_meeting_negotiations WHERE company_id = ?');
$stmt->execute([$cid]);
foreach ($stmt->fetchAll() as $r) {
    $k = $r['client_name_key'];
    $cand = $r['candidate_ym'] !== null ? (int)$r['candidate_ym'] : null;
    $exc  = $r['excluded_ym']  !== null ? (int)$r['excluded_ym']  : null;

    if (isset($rows[$k])) {
        // 案件がある会社。案件が正なので取引中のまま。月は早い方
        if ($cand !== null) $rows[$k]['ym'] = min($rows[$k]['ym'], $cand);
        $rows[$k]['src']  = $rows[$k]['src'] . '・商談報告';
        $rows[$k]['note'] = '商談報告あり（' . $r['status'] . '）';
        continue;
    }
    // 商談報告のみの会社。候補になっていない・除外済みは会社数に入らない
    if ($cand === null || $cand > $upto) continue;
    if ($exc !== null && $exc <= $upto)  continue;
    $rows[$k] = ['name' => $r['client_name'], 'src' => '商談報告', 'rep' => $r['rep_name'],
                 'ym' => $cand, 'active' => ($r['active_ym'] !== null),
                 'note' => $r['status']];
}

// 当月までに計上開始していない会社は除く
$rows = array_filter($rows, fn($r) => $r['ym'] <= $upto);

// 並び順: 取引中 → 候補、そのあと計上開始月
uasort($rows, function ($a, $b) {
    if ($a['active'] !== $b['active']) return $a['active'] ? -1 : 1;
    return $a['ym'] <=> $b['ym'];
});

$total       = count($rows);
$activeCount = count(array_filter($rows, fn($r) => $r['active']));

// ------------------------------------------------------------
// いただいた担当企業リストに載っているか
// ------------------------------------------------------------
$LIST = [
    '竹内陽' => ['フェローズ','KTT','プレイス','プラウドパートナー','クラウドエージェント','LANGIS',
                 'エクシード','オアシス','魁組','コンフィアンス','VIN','T-Group','onetale','Hope Village',
                 'polish','秀星','フリーダム','SEED','I’bis','スリーエス','アトラス','クリア','ワニー',
                 'YWC','エークラス','リテンパー','ホルドブレイ','フリプロ','ネクステラ','サニー','レクラン',
                 'リーブス','アルベラ','ME','loutus','近藤SEED'],
    '山根脩平' => ['wonder craft','center force','NAP','Grase','ユウテック'],
    '綾部航介' => ['U-Plus','アスシード','ライブリッジ','waplus','グラスト','flueve','ラクサム',
                   'ネクストアシスト','FF','玉腰','リポン','スタートリンク','クニトコアセット','T’sソリューション'],
    '佐藤思杰' => ['badass','アンダーウィル','DTC','SWACK','youth','アルティム'],
    '名倉雅貴' => ['クニトコアセット','プレイミー','ANW（アナザーウェーブ）','Function','No.Limit','MIC',
                   'デックサポート','D-MAK','FIRSTART','Thinks','センターフロー','LIBERTY','Assh'],
];
$inList = [];
foreach ($LIST as $names) foreach ($names as $n) $inList[lcNameKey($n)] = true;
$notInList = array_filter($rows, fn($r, $k) => !isset($inList[$k]), ARRAY_FILTER_USE_BOTH);

$pageTitle = '会社数実績の内訳';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-list-ul me-2"></i>会社数実績の内訳</h1>
        <p>データは変更していません（確認のみ）。年度：<?= $fy - 1 ?>年9月〜<?= $fy ?>年8月／
           <?= (int)date('Y') ?>年<?= (int)date('n') ?>月時点</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">会社数実績（青線）</div>
            <div class="fs-3 fw-bold text-primary"><?= $total ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">取引有会社数（赤線）</div>
            <div class="fs-3 fw-bold text-danger"><?= $activeCount ?></div>
            <div class="small text-muted">実際に案件がある</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">取引候補</div>
            <div class="fs-3 fw-bold"><?= $total - $activeCount ?></div>
            <div class="small text-muted">まだ案件がない</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">担当企業リストに無い</div>
            <div class="fs-3 fw-bold text-warning"><?= count($notInList) ?></div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">会社数実績の内訳（<?= $total ?>社）</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>#</th><th>会社名</th><th>状態</th><th>計上の根拠</th><th>営業担当</th>
                        <th>計上開始</th><th>リスト</th></tr>
                </thead>
                <tbody>
                <?php $i = 0; foreach ($rows as $k => $r): $i++; ?>
                    <tr>
                        <td class="text-muted small"><?= $i ?></td>
                        <td class="fw-medium"><?= h($r['name']) ?></td>
                        <td>
                            <?php if ($r['active']): ?>
                                <span class="badge bg-danger">取引中</span>
                            <?php else: ?>
                                <span class="badge bg-primary">取引候補</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= h($r['src']) ?>
                            <?= !empty($r['note']) ? '<span class="text-muted">（' . h($r['note']) . '）</span>' : '' ?></td>
                        <td class="small text-muted"><?= h($r['rep'] ?? '') ?></td>
                        <td class="small text-nowrap"><?= h(lcYm($r['ym'])) ?></td>
                        <td class="small">
                            <?= isset($inList[$k]) ? '<span class="text-muted">あり</span>'
                                                   : '<span class="text-warning fw-bold">無し</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-bold">担当企業リストに載っていない会社（<?= count($notInList) ?>社）</div>
        <div class="card-body">
            <?php if (!$notInList): ?>
                <p class="text-muted mb-0">ありません。</p>
            <?php else: ?>
                <p class="small text-muted">
                    いただいた担当企業リスト（竹内陽・山根脩平・綾部航介・佐藤思杰・名倉雅貴の5名分）に
                    含まれていない会社です。外注先や、リストに無い営業担当の会社が該当します。
                </p>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>会社名</th><th>計上の根拠</th><th>営業担当</th><th>計上開始</th></tr></thead>
                        <tbody>
                        <?php foreach ($notInList as $r): ?>
                            <tr><td class="fw-medium"><?= h($r['name']) ?></td>
                                <td class="small"><?= h($r['src']) ?></td>
                                <td class="small text-muted"><?= h($r['rep'] ?? '') ?></td>
                                <td class="small text-nowrap"><?= h(lcYm($r['ym'])) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
