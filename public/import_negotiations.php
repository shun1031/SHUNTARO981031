<?php
/**
 * 【一度だけ実行するスクリプト】担当企業リストを商談報告に一括登録
 *
 * ご提供いただいた担当企業リスト73社のうち、まだ会社数に計上されていない会社を
 * 商談報告として登録します。すでに計上されている会社は登録しません
 * （登録しても会社数は増えず、新規商談数だけが増えてしまうため）。
 *
 * ユーザー確認済みの取り決め:
 *   - ステータスは全社「取引候補」。すでに案件がある会社は集計時に自動で
 *     「取引開始」として扱われるため、これで問題ない
 *   - 対象年月は全社 2026年8月
 *   - 「I’bis」は大文字のIが正しい表記のため、そのまま登録する。
 *     取引先一覧側の「l’bis」（小文字のL）は後日ユーザーが手動で修正する。
 *     修正されるまでは別会社として2社に数えられる（修正後は自動で1社にまとまる）
 *   - 「SEED」「NAP」は似た名前が既存にあるが別会社。登録する
 *   - 「クニトコアセット」はリスト内で2人に重複。1社として登録する
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// ------------------------------------------------------------
// 登録する内容
// ------------------------------------------------------------
const IMP_STATUS = '取引候補';
const IMP_YEAR   = 2026;
const IMP_MONTH  = 8;

/** 既存の会社と同一のため登録しないもの（キーは会社名）。現在は該当なし */
$SKIP_SAME = [];

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

/** 会社名の突き合わせキー（public/api/strategy_meeting.php の smNameKey と同じ） */
function impNameKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'aKV');
    return mb_strtolower($n, 'UTF-8');
}

// ------------------------------------------------------------
// すでに会社数に計上されている会社を調べる（年間推移と同じ条件）
// ------------------------------------------------------------
$fyNow = (int)date('n') >= 9 ? (int)date('Y') + 1 : (int)date('Y');
$FY    = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";
$repNames = getSalesRepCandidates($cid);
$counted  = [];

if ($repNames) {
    $ph = implode(',', array_fill(0, count($repNames), '?'));
    $sqls = [
        "SELECT COALESCE(NULLIF(TRIM(cl.display_name), ''), cl.client_name) AS name
         FROM sales_cases sc JOIN sales_clients cl ON sc.client_id = cl.id
         LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
         WHERE sc.company_id = ? AND sc.status = 'confirmed' AND {$FY}
           AND COALESCE(er.name, sc.sales_rep) IN ({$ph})",
        "SELECT al.alliance_name AS name
         FROM sales_cases sc JOIN sales_alliances al ON sc.alliance_id = al.id
         LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
         WHERE sc.company_id = ? AND sc.status = 'confirmed' AND sc.worker_type = 'アライアンス' AND {$FY}
           AND COALESCE(em.name, sc.manager) IN ({$ph})",
    ];
    foreach ($sqls as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$cid, $fyNow - 1, $fyNow], $repNames));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $k = impNameKey((string)$n);
            if ($k !== '') $counted[$k] = true;
        }
    }
}

// すでに商談報告に登録済みの会社
$exists = [];
$stmt = $db->prepare('SELECT client_name_key FROM strategy_meeting_negotiations WHERE company_id = ?');
$stmt->execute([$cid]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $k) $exists[$k] = true;

// ------------------------------------------------------------
// 登録対象を組み立てる
// ------------------------------------------------------------
$targets = [];   // 登録する
$skipped = [];   // 登録しない（理由つき）
$seen    = [];

foreach ($LIST as $rep => $names) {
    foreach ($names as $name) {
        $k = impNameKey($name);
        if (isset($seen[$k]))            { $skipped[] = [$rep, $name, 'リスト内で重複']; continue; }
        $seen[$k] = true;
        if (isset($SKIP_SAME[$name]))    { $skipped[] = [$rep, $name, '「' . $SKIP_SAME[$name] . '」と同一のため']; continue; }
        if (isset($counted[$k]))         { $skipped[] = [$rep, $name, 'すでに会社数に計上済み']; continue; }
        if (isset($exists[$k]))          { $skipped[] = [$rep, $name, 'すでに商談報告に登録済み']; continue; }
        $targets[] = ['rep' => $rep, 'name' => $name, 'key' => $k];
    }
}

// ------------------------------------------------------------
// 実行
// ------------------------------------------------------------
$done = false;
$msg  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if (!$targets) {
        $msg = '登録する会社はありませんでした。';
        $done = true;
    } else {
        $ym = IMP_YEAR * 100 + IMP_MONTH;
        try {
            $db->beginTransaction();
            $ins = $db->prepare("INSERT INTO strategy_meeting_negotiations
                (company_id, client_name, client_name_key, rep_name, rep_employee_id,
                 status, first_report_ym, candidate_ym, active_ym, excluded_ym)
                VALUES (?,?,?,?,?,?,?,?,NULL,NULL)");
            foreach ($targets as $t) {
                $ins->execute([$cid, $t['name'], $t['key'], $t['rep'],
                               resolveEmployeeIdByName($cid, $t['rep']),
                               IMP_STATUS, $ym, $ym]);
            }
            $db->commit();
            $msg  = count($targets) . '社を商談報告に登録しました。';
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[import_negotiations] ' . $e->getMessage());
            $msg = '登録に失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '担当企業リストの一括登録';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-upload me-2"></i>担当企業リストの一括登録</h1>
        <p>ステータス「<?= h(IMP_STATUS) ?>」／対象年月 <?= IMP_YEAR ?>年<?= IMP_MONTH ?>月 で登録します。</p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">登録する会社</div>
            <div class="fs-3 fw-bold text-primary"><?= count($targets) ?></div></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">登録しない会社</div>
            <div class="fs-3 fw-bold text-muted"><?= count($skipped) ?></div></div></div></div>
    </div>

    <?php if (!$done): ?>
    <div class="alert alert-info">
        すでに会社数に計上されている会社は登録しません。登録すると会社数は増えないまま、
        新規商談数だけが増えてしまうためです。
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">登録する会社（<?= count($targets) ?>社）</div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>営業担当</th><th>会社名</th></tr></thead>
                <tbody>
                <?php foreach ($targets as $t): ?>
                    <tr><td class="small text-muted"><?= h($t['rep']) ?></td>
                        <td class="fw-medium"><?= h($t['name']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">登録しない会社（<?= count($skipped) ?>社）</div>
        <div class="table-responsive" style="max-height:320px;overflow-y:auto">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>営業担当</th><th>会社名</th><th>理由</th></tr></thead>
                <tbody>
                <?php foreach ($skipped as [$rep, $name, $why]): ?>
                    <tr><td class="small text-muted"><?= h($rep) ?></td>
                        <td><?= h($name) ?></td>
                        <td class="small text-muted"><?= h($why) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('<?= count($targets) ?>社を登録します。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary" <?= $targets ? '' : 'disabled' ?>>登録を実行</button>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。戦略会議の画面で会社数をご確認のうえ、このファイルを削除してください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
