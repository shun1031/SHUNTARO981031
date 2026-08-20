<?php
/**
 * 【一度だけ実行するスクリプト】2026年2月〜7月の常勤案件に光ADを一括設定
 *
 * 光AD（hikari_ad_flag）は戦略会議と常勤案件一覧のバッジだけが参照する項目で、
 * 売上・原価・粗利・給与のどの計算にも使われない。まとめて設定しても金額は変わらない。
 *
 * 件数の差異をゼロにするため、対象月の常勤案件をいったん全部「光ADなし」にしてから、
 * 下のリストに載っている行だけをONにする。
 *
 * ユーザー確認済みの取り決め:
 *   - 突き合わせは「スタッフ名」だけで行う（店舗名は表記ゆれが多いため確認用の表示のみ）
 *     ※各月にスタッフ名の重複が無いことを事前に確認済み
 *   - 名前はシステム側の表記が正しい:
 *       安山裕亮 → 安山祐亮 ／ 小栗瑞寿・小栗瑞樹 → 小栗瑞貴 ／ 佐藤思 → 佐藤思杰
 *   - 7月の「小栗瑞貴 / 森山幸心」は案件が未登録のため対象外。
 *     案件を追加したあとで画面から光ADにチェックを入れる
 *   - 2月・3月の「ADラダー / 光」は光ADに含めない
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「光ADを設定」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

const HA_YEAR = 2026;

// ============================================================
// 光ADにするスタッフ（月 => [スタッフ名 => ご指定の店舗名]）
// 店舗名は突き合わせには使わない。目視確認のために持っておく
// ============================================================
$TARGETS = [
    2 => [
        '竹内丈治' => '碧南',
        '綾部航介' => '美濃加茂',
        '堀恭彰'   => '岐阜芥見',
        '安山祐亮' => '知立',          // ご指定は「安山裕亮」
        '山口大士' => 'GA知立',
        '近藤航'   => '安城',
        '山内文月' => '多治見南',
        '鈴木真矢' => '安城住吉',
        '名倉雅貴' => 'モレラ岐阜',
        '小栗瑞貴' => 'モレラ岐阜',    // ご指定は「小栗瑞寿」
        '佐藤思杰' => '岐阜',
    ],
    3 => [
        '綾部航介' => '美濃加茂',
        '堀恭彰'   => '岐阜芥見',
        '山内文月' => '多治見南',
        '安山祐亮' => '知立',          // ご指定は「安山裕亮」
        '柴田一心' => 'GA知立',
        '近藤航'   => '安城',
        '山口大士' => '安城住吉',
        '竹内丈治' => '碧南',
        '名倉雅貴' => 'モレラ岐阜',
        '小栗瑞貴' => 'モレラ岐阜',    // ご指定は「小栗瑞樹」
        '佐藤思杰' => '岐南',
    ],
    4 => [
        '押野俊太郎' => '安城住吉',
        '東郷光啓'   => '知立',
        '名倉雅貴'   => 'GA知立',
        '佐藤思杰'   => '安城',
        '田中敦之'   => '碧南',
        '石谷悠真'   => '多治見南',
        '堀恭彰'     => '岐阜芥見',
        '田中哲史'   => 'サンロード',
        '綾部航介'   => '美濃加茂',
    ],
    5 => [
        '押野俊太郎' => 'サンロード',
        '東郷光啓'   => 'GA知立',
        '名倉雅貴'   => '安城住吉',
        '佐藤思杰'   => '安城',        // ご指定は「佐藤思」
        '田中敦之'   => '碧南',
        '石谷悠真'   => '多治見南',
        '堀恭彰'     => '岐阜芥見',
        '綾部航介'   => '美濃加茂',
        '竹内陽'     => '知立',
    ],
    6 => [
        '加藤洋亮'   => '森山幸心',
        '押野俊太郎' => 'サンロード',
        '東郷光啓'   => 'GA知立',
        '佐藤思杰'   => '安城',        // ご指定は「佐藤思」
        '田中敦之'   => '碧南',
        '石谷悠真'   => '多治見南',
        '堀恭彰'     => '岐阜芥見',
        '竹内陽'     => '知立',
        '綾部航介'   => '美濃加茂',
        '板倉久美子' => '安城住吉',
    ],
    7 => [
        '板倉久美子' => '安城住吉',
        '押野俊太郎' => 'サンロード',
        '東郷光啓'   => 'GA知立',
        '佐藤思杰'   => '安城',
        '田中敦之'   => '碧南',
        '石谷悠真'   => '多治見南',
        '加藤洋亮'   => '岐阜芥見',
        '竹内陽'     => '知立',
        '綾部航介'   => '美濃加茂',
        // 「小栗瑞貴 / 森山幸心」は案件が未登録のため対象外。
        // 案件を追加したあとで、常勤案件の画面から光ADにチェックを入れてください
    ],
];

/** 名前の突き合わせキー（前後の空白と全角空白を落とすだけ。別人を誤って一致させない） */
function haKey(string $n): string {
    return preg_replace('/[\s\x{3000}]+/u', '', trim($n));
}

// ============================================================
// 対象月の常勤案件を読み込んで突き合わせる
// ============================================================
$plan       = [];   // 月 => 行の配列
$notFound   = [];   // 見つからなかった（実行できない）
$ambiguous  = [];   // 複数見つかった（実行できない）
$willClear  = [];   // 今チェックが入っているが、リストに無い行

foreach ($TARGETS as $month => $people) {
    $st = $db->prepare("SELECT sc.id, sc.worker_name, sc.store_name, sc.hikari_ad_flag,
                               " . clientLabelSql('cl') . " AS client_name
                        FROM sales_cases sc
                        LEFT JOIN sales_clients cl ON sc.client_id = cl.id
                        WHERE sc.company_id = ? AND sc.case_type = 'regular'
                          AND sc.case_year = ? AND sc.case_month = ?
                          AND sc.status <> 'cancelled'
                        ORDER BY sc.id");
    $st->execute([$cid, HA_YEAR, $month]);
    $cases = $st->fetchAll(PDO::FETCH_ASSOC);

    // スタッフ名 => 行（同名が複数あれば配列に溜める）
    $byName = [];
    foreach ($cases as $c) { $byName[haKey((string)$c['worker_name'])][] = $c; }

    $rows = [];
    $hitIds = [];
    foreach ($people as $name => $storeHint) {
        $k = haKey($name);
        $found = $byName[$k] ?? [];
        if (count($found) === 0) {
            $notFound[] = ['month' => $month, 'name' => $name, 'store' => $storeHint];
            continue;
        }
        if (count($found) > 1) {
            $ambiguous[] = ['month' => $month, 'name' => $name, 'store' => $storeHint,
                            'stores' => array_map(fn($r) => (string)$r['store_name'], $found)];
            continue;
        }
        $c = $found[0];
        $hitIds[(int)$c['id']] = true;
        $rows[] = [
            'id'        => (int)$c['id'],
            'name'      => (string)$c['worker_name'],
            'store'     => (string)$c['store_name'],
            'client'    => (string)$c['client_name'],
            'hint'      => $storeHint,
            'already'   => (int)$c['hikari_ad_flag'] === 1,
            'store_diff'=> haKey((string)$c['store_name']) !== haKey($storeHint),
        ];
    }
    $plan[$month] = [
        'rows'     => $rows,
        'total'    => count($cases),
        'now'      => count(array_filter($cases, fn($c) => (int)$c['hikari_ad_flag'] === 1)),
        'after'    => count($rows),
    ];

    // 今チェックが入っているが、リストに無い行（実行すると外れる）
    foreach ($cases as $c) {
        if ((int)$c['hikari_ad_flag'] !== 1) continue;
        if (isset($hitIds[(int)$c['id']])) continue;
        $willClear[] = ['month' => $month, 'name' => (string)$c['worker_name'],
                        'store' => (string)$c['store_name'], 'client' => (string)$c['client_name']];
    }
}

$blocked = $notFound || $ambiguous;

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if ($blocked) {
        $msg = '特定できない行が残っているため実行しませんでした。';
    } else {
        try {
            $db->beginTransaction();
            $clear = $db->prepare("UPDATE sales_cases SET hikari_ad_flag = 0
                                   WHERE company_id = ? AND case_type = 'regular'
                                     AND case_year = ? AND case_month = ?");
            $set   = $db->prepare("UPDATE sales_cases SET hikari_ad_flag = 1
                                   WHERE id = ? AND company_id = ?");
            $nSet = 0;
            foreach ($plan as $month => $p) {
                // まず対象月を全部「光ADなし」にする（件数の差異をゼロにするため）
                $clear->execute([$cid, HA_YEAR, $month]);
                foreach ($p['rows'] as $r) { $set->execute([$r['id'], $cid]); $nSet++; }
            }
            $db->commit();
            $msg  = "2月〜7月の常勤案件に光ADを設定しました（合計 {$nSet}件）。";
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_hikari_ad] ' . $e->getMessage());
            $msg = '設定に失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$totalAfter = array_sum(array_map(fn($p) => $p['after'], $plan));

$pageTitle = '光ADの一括設定';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-lightning-charge me-2"></i>光ADの一括設定（2026年2月〜7月）</h1>
        <p>常勤案件の光ADにまとめてチェックを入れます。突き合わせは<strong>スタッフ名</strong>で行います。
           光ADは戦略会議と案件一覧のバッジだけが使う項目なので、
           <strong>売上・原価・粗利・給与の金額は一切変わりません</strong>。</p>
    </div>

    <div class="alert alert-info" style="font-size:.85rem">
        件数の差異をゼロにするため、<strong>対象月の常勤案件をいったん全部「光ADなし」にしてから、
        下の一覧の行だけをONにします。</strong>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <?php if ($notFound || $ambiguous): ?>
    <div class="card border-danger mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-exclamation-triangle me-1"></i>特定できない行（<?= count($notFound) + count($ambiguous) ?>件）
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr><th style="width:70px">月</th><th>スタッフ名</th><th>ご指定の店舗</th><th>内容</th></tr></thead>
                <tbody>
                <?php foreach ($notFound as $r): ?>
                    <tr><td><?= (int)$r['month'] ?>月</td><td class="fw-medium"><?= h($r['name']) ?></td>
                        <td class="small text-muted"><?= h($r['store']) ?></td>
                        <td class="small text-danger">その月の常勤案件に見つかりません</td></tr>
                <?php endforeach; ?>
                <?php foreach ($ambiguous as $r): ?>
                    <tr><td><?= (int)$r['month'] ?>月</td><td class="fw-medium"><?= h($r['name']) ?></td>
                        <td class="small text-muted"><?= h($r['store']) ?></td>
                        <td class="small text-danger">同じ名前の案件が<?= count($r['stores']) ?>件あります（<?= h(implode(' / ', $r['stores'])) ?>）</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">この一覧が空になるまで実行できません。</div>
    </div>
    <?php endif; ?>

    <?php if ($willClear): ?>
    <div class="card border-warning mb-4">
        <div class="card-header bg-warning fw-bold">
            <i class="bi bi-eraser me-1"></i>今チェックが入っていますが、実行すると外れる行（<?= count($willClear) ?>件）
        </div>
        <div class="table-responsive" style="max-height:280px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr><th style="width:70px">月</th><th>取引先</th><th>スタッフ名</th><th>店舗</th></tr></thead>
                <tbody>
                <?php foreach ($willClear as $r): ?>
                    <tr><td><?= (int)$r['month'] ?>月</td><td class="small text-muted"><?= h($r['client']) ?></td>
                        <td class="fw-medium"><?= h($r['name']) ?></td><td class="small"><?= h($r['store']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            リストに載っていないため光ADから外れます。残すべき行が混ざっていないかご確認ください。
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">月ごとの件数</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.85rem">
                <thead class="table-light">
                    <tr><th style="width:90px">月</th><th class="text-end">常勤案件</th>
                        <th class="text-end">今の光AD</th><th class="text-center" style="width:30px"></th>
                        <th class="text-end">実行後</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plan as $month => $p): ?>
                    <tr>
                        <td class="fw-medium"><?= HA_YEAR ?>年<?= (int)$month ?>月</td>
                        <td class="text-end text-muted"><?= $p['total'] ?>件</td>
                        <td class="text-end text-muted"><?= $p['now'] ?>件</td>
                        <td class="text-center text-primary">→</td>
                        <td class="text-end fw-bold text-primary"><?= $p['after'] ?>件</td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-light">
                    <td class="fw-bold">合計</td><td></td><td></td><td></td>
                    <td class="text-end fw-bold text-primary"><?= $totalAfter ?>件</td>
                </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            7月は「小栗瑞貴 / 森山幸心」の案件がまだ無いため9件です。
            案件を追加したあと、常勤案件の画面から光ADにチェックを入れてください（→10件）。
        </div>
    </div>

    <?php foreach ($plan as $month => $p): ?>
    <div class="card mb-3">
        <div class="card-header fw-bold">
            <?= HA_YEAR ?>年<?= (int)$month ?>月に光ADを付ける案件（<?= count($p['rows']) ?>件）
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr><th>取引先</th><th>スタッフ名</th><th>実際の店舗</th><th>ご指定の店舗</th><th style="width:90px">現在</th></tr>
                </thead>
                <tbody>
                <?php foreach ($p['rows'] as $r): ?>
                    <tr>
                        <td class="small text-muted"><?= h($r['client']) ?></td>
                        <td class="fw-medium"><?= h($r['name']) ?></td>
                        <td class="small"><?= h($r['store']) ?></td>
                        <td class="small <?= $r['store_diff'] ? 'text-warning-emphasis' : 'text-muted' ?>">
                            <?= h($r['hint']) ?><?= $r['store_diff'] ? '（表記違い）' : '' ?>
                        </td>
                        <td class="small">
                            <?= $r['already'] ? '<span class="badge bg-primary">光AD済み</span>'
                                              : '<span class="text-muted">なし → 付ける</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('2月〜7月の常勤案件に光ADを設定します（合計<?= $totalAfter ?>件）。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg" <?= $blocked ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>光ADを設定
            </button>
            <?php if ($blocked): ?>
                <span class="text-danger small ms-2">特定できない行が残っています</span>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。常勤案件の各月と、戦略会議の光AD絞り込みでご確認ください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
