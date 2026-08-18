<?php
/**
 * 【一度だけ使う確認用スクリプト・読み取り専用】商談報告 一括登録の事前照合
 *
 * ご提供いただいた担当企業リスト（73社）を現在のデータと突き合わせ、
 *   1. すでに会社数に数えられている会社（対象年月の指定が不要）
 *   2. 新しく追加される会社（対象年月の指定が必要）
 *   3. 表記が近いだけで別会社として登録されてしまう会社（要確認）
 * を一覧表示します。
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

// ------------------------------------------------------------
// 照合対象のリスト（ご提供いただいた担当企業）
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

// ------------------------------------------------------------
// 会社名の突き合わせキー（本体 public/api/strategy_meeting.php と同じルール）
// ------------------------------------------------------------
function chkNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'a');
    return mb_strtolower($n, 'UTF-8');
}

/**
 * 表記ゆれの検出用。記号・空白・法人格を落として比べる（照合には使わず、注意喚起のみ）
 * 半角カナは全角に直す。「ﾗﾈｯﾄ」と「ラネット」は文字コードが全く違うため、
 * これをしないと似ている判定にすらならず見逃してしまう
 */
function chkLooseKey(string $name): string {
    $n = chkNameKey($name);
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'KV');  // 半角カナ→全角カナ
    $n = preg_replace('/[\s\'’`"”，,．.・\-–—_（）()【】\[\]]/u', '', $n);
    return preg_replace('/^(株式会社|有限会社|合同会社)|(株式会社|有限会社|合同会社)$/u', '', $n);
}

/**
 * よく似た名前を探す。
 * 「I'bis」と「l'bis」のように大文字のIと小文字のLが違うだけの場合、
 * 記号を落としただけでは一致しないため、似ている度合いでも拾う。
 * @return array{0:string,1:int}|null [似ている名前, 一致率%]
 */
function chkFindSimilar(string $name, array $candidates): ?array {
    $a = chkLooseKey($name);
    if (mb_strlen($a) < 2) return null;
    $best = null; $bestPct = 0;
    foreach ($candidates as $cand) {
        $b = chkLooseKey($cand);
        if ($a === $b) continue;
        similar_text($a, $b, $pct);
        if ($pct > $bestPct) { $bestPct = $pct; $best = $cand; }
    }
    return ($best !== null && $bestPct >= 75) ? [$best, (int)round($bestPct)] : null;
}

// ------------------------------------------------------------
// 現在すでに会社数に数えられている会社（年間推移と同じ条件）
// ------------------------------------------------------------
$fyNow = (int)date('n') >= 9 ? (int)date('Y') + 1 : (int)date('Y');
$FY    = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";
$repNames = getSalesRepCandidates($cid);
$counted  = [];   // キー => ['name'=>表示名, 'ym'=>初回月, 'type'=>取引先/外注先]

if ($repNames) {
    $ph = implode(',', array_fill(0, count($repNames), '?'));

    $stmt = $db->prepare("SELECT COALESCE(NULLIF(TRIM(cl.display_name), ''), cl.client_name) AS name,
                                 MIN(sc.case_year * 100 + sc.case_month) AS first_ym
                          FROM sales_cases sc
                          JOIN sales_clients cl ON sc.client_id = cl.id
                          LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
                          WHERE sc.company_id = ? AND sc.status = 'confirmed' AND {$FY}
                            AND COALESCE(er.name, sc.sales_rep) IN ({$ph})
                          GROUP BY cl.id, cl.display_name, cl.client_name");
    $stmt->execute(array_merge([$cid, $fyNow - 1, $fyNow], $repNames));
    foreach ($stmt->fetchAll() as $r) {
        $k = chkNameKey((string)$r['name']);
        if ($k !== '') $counted[$k] = ['name' => $r['name'], 'ym' => (int)$r['first_ym'], 'type' => '取引先'];
    }

    $stmt = $db->prepare("SELECT al.alliance_name AS name,
                                 MIN(sc.case_year * 100 + sc.case_month) AS first_ym
                          FROM sales_cases sc
                          JOIN sales_alliances al ON sc.alliance_id = al.id
                          LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
                          WHERE sc.company_id = ? AND sc.status = 'confirmed'
                            AND sc.worker_type = 'アライアンス' AND {$FY}
                            AND COALESCE(em.name, sc.manager) IN ({$ph})
                          GROUP BY al.id, al.alliance_name");
    $stmt->execute(array_merge([$cid, $fyNow - 1, $fyNow], $repNames));
    foreach ($stmt->fetchAll() as $r) {
        $k = chkNameKey((string)$r['name']);
        if ($k !== '' && !isset($counted[$k])) {
            $counted[$k] = ['name' => $r['name'], 'ym' => (int)$r['first_ym'], 'type' => '外注先'];
        }
    }
}

// すでに登録済みの商談報告
$already = [];
$stmt = $db->prepare('SELECT client_name, client_name_key FROM strategy_meeting_negotiations WHERE company_id = ?');
$stmt->execute([$cid]);
foreach ($stmt->fetchAll() as $r) $already[$r['client_name_key']] = $r['client_name'];

// 表記ゆれ検出用に、システム側の全社名をゆるいキーで持つ
$looseMap     = [];
$countedNames = [];
foreach ($counted as $c) {
    $looseMap[chkLooseKey($c['name'])][] = $c['name'];
    $countedNames[] = $c['name'];
}

// ------------------------------------------------------------
// 照合
// ------------------------------------------------------------
$rows = [];       // 表示用
$seen = [];       // リスト内の重複検出
foreach ($LIST as $rep => $names) {
    foreach ($names as $name) {
        $k = chkNameKey($name);
        $dupInList = isset($seen[$k]);
        $seen[$k] = true;

        $status = '新規追加';
        $note   = '';
        if (isset($counted[$k])) {
            $status = 'すでに計上済み';
            $note   = $counted[$k]['type'] . '・初回 '
                    . (int)floor($counted[$k]['ym'] / 100) . '年' . ($counted[$k]['ym'] % 100) . '月';
        } elseif (isset($already[$k])) {
            $status = '商談報告に登録済み';
        } else {
            // 記号や法人格の違いだけで一致する会社があるか
            $lk = chkLooseKey($name);
            if (!empty($looseMap[$lk])) {
                $status = '表記ゆれの疑い';
                $note   = 'システム側の表記: ' . implode(' / ', $looseMap[$lk]);
            } else {
                // 1〜2文字違いのよく似た名前も拾う（I'bis と l'bis のようなケース）
                $sim = chkFindSimilar($name, $countedNames);
                if ($sim !== null) {
                    $status = '表記ゆれの疑い';
                    $note   = 'システム側に似た名前: ' . $sim[0] . '（一致率 ' . $sim[1] . '%）';
                }
            }
        }
        if ($dupInList) $note = trim($note . '（リスト内で重複。1社として扱われます）');

        $rows[] = ['rep' => $rep, 'name' => $name, 'status' => $status, 'note' => $note, 'dup' => $dupInList];
    }
}

$sum = ['すでに計上済み' => 0, '新規追加' => 0, '表記ゆれの疑い' => 0, '商談報告に登録済み' => 0];
$uniqueNew = [];
foreach ($rows as $r) {
    if ($r['dup']) continue;
    $sum[$r['status']]++;
    if ($r['status'] === '新規追加') $uniqueNew[] = $r['name'];
}

$pageTitle = '商談報告 一括登録の事前照合';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-search me-2"></i>商談報告 一括登録の事前照合</h1>
        <p>データは変更していません（確認のみ）。年度：<?= $fyNow - 1 ?>年9月〜<?= $fyNow ?>年8月</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">リストの社数（重複を除く）</div>
            <div class="fs-3 fw-bold"><?= count($seen) ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">すでに計上済み</div>
            <div class="fs-3 fw-bold text-success"><?= $sum['すでに計上済み'] ?></div>
            <div class="small text-muted">対象年月の指定は不要</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">新しく追加される</div>
            <div class="fs-3 fw-bold text-primary"><?= $sum['新規追加'] ?></div>
            <div class="small text-muted">対象年月の指定が必要</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">表記ゆれの疑い</div>
            <div class="fs-3 fw-bold text-danger"><?= $sum['表記ゆれの疑い'] ?></div>
            <div class="small text-muted">要確認</div></div></div></div>
    </div>

    <?php if ($sum['表記ゆれの疑い'] > 0): ?>
    <div class="alert alert-warning">
        <strong>表記ゆれの疑いがある会社があります。</strong>
        このまま登録すると別会社として数えられ、社数が増えすぎます。
        下の表の「システム側の表記」に合わせるかご確認ください。
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">照合結果</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>営業担当</th><th>会社名</th><th>状態</th><th>備考</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr<?= $r['status'] === '表記ゆれの疑い' ? ' class="table-warning"' : '' ?>>
                        <td class="small text-muted"><?= h($r['rep']) ?></td>
                        <td class="fw-medium"><?= h($r['name']) ?></td>
                        <td>
                            <?php
                            $badge = ['すでに計上済み' => 'success', '新規追加' => 'primary',
                                      '表記ゆれの疑い' => 'danger', '商談報告に登録済み' => 'secondary'][$r['status']];
                            ?>
                            <span class="badge bg-<?= $badge ?>"><?= h($r['status']) ?></span>
                        </td>
                        <td class="small text-muted"><?= h($r['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-bold">対象年月のご指定が必要な会社（<?= count($uniqueNew) ?>社）</div>
        <div class="card-body">
            <?php if (!$uniqueNew): ?>
                <p class="text-muted mb-0">ありません。</p>
            <?php else: ?>
                <p class="small text-muted">
                    分かる会社は実際の月を、分からない会社は既定の2025年9月で登録します。
                </p>
                <pre class="mb-0" style="white-space:pre-wrap;font-size:.85rem"><?= h(implode("\n", $uniqueNew)) ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
