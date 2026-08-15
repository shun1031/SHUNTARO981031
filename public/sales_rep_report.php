<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者別売上';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY++; }

// 担当者別 月別売上目標の保存（AJAX / 集計クエリの前に処理して軽量に返す）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rep_target') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'csrf']); exit; }
    session_write_close(); // 連続入力で後続リクエストをブロックしない
    $rep = trim($_POST['rep_name'] ?? '');
    $ty  = (int)($_POST['t_year']  ?? 0);
    $tm  = (int)($_POST['t_month'] ?? 0);
    $tv  = max(0, (int)str_replace([',', '¥', ' ', '　'], '', $_POST['t_value'] ?? '0'));
    if ($rep !== '' && $ty && $tm >= 1 && $tm <= 12) {
        $_db = getDB();
        try {
            $_db->prepare('INSERT INTO sales_rep_targets (company_id, rep_name, year, month, target_revenue) VALUES (?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE target_revenue=VALUES(target_revenue), updated_at=NOW()')
                ->execute([$cid, $rep, $ty, $tm, $tv]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'db']); exit;
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

$empFilter   = getEmployeeNameFilter();

// 詳細モーダルの年度切替（該当担当者の12ヶ月分のみ返す軽量API）
if (($_GET['ajax_rep_fy'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $_fy  = (int)($_GET['fy'] ?? 0);
    $_rep = trim($_GET['rep'] ?? '');
    if ($_fy < 2000 || $_fy > 2100 || $_rep === '') { echo json_encode(['error' => 'invalid']); exit; }
    session_write_close();
    $_seq = [];
    for ($i = 0; $i < 4; $i++)  { $_seq[] = ['y' => $_fy - 1, 'm' => 9 + $i]; }
    for ($i = 1; $i <= 8; $i++) { $_seq[] = ['y' => $_fy,     'm' => $i]; }
    $_curData  = getSalesRepReport($cid, $_fy,     $empFilter);
    $_prevData = getSalesRepReport($cid, $_fy - 1, $empFilter);
    $_pts = [];
    foreach ($_seq as $_ym) {
        $_src = $_ym['y'] === $_fy ? ($_curData[$_rep] ?? []) : ($_prevData[$_rep] ?? []);
        $_md  = $_src['months'][$_ym['m']] ?? [];
        $_rv  = (int)($_md['revenue'] ?? 0);
        $_pr  = (int)($_md['profit']  ?? 0);
        $_pts[] = ['revenue' => $_rv, 'profit' => $_pr, 'profitRate' => $_rv > 0 ? round($_pr / $_rv * 100, 1) : null];
    }
    $_tgts = array_fill(0, 12, 0);
    try {
        $_ts = getDB()->prepare('SELECT year, month, target_revenue FROM sales_rep_targets
                                 WHERE company_id = ? AND rep_name = ? AND year IN (?, ?)');
        $_ts->execute([$cid, $_rep, $_fy - 1, $_fy]);
        $_tm = [];
        foreach ($_ts->fetchAll() as $_tr) { $_tm[(int)$_tr['year'] . '|' . (int)$_tr['month']] = (int)$_tr['target_revenue']; }
        foreach ($_seq as $_i => $_ym) { $_tgts[$_i] = $_tm[$_ym['y'] . '|' . $_ym['m']] ?? 0; }
    } catch (PDOException $e) { /* テーブル未作成時は0のまま */ }
    echo json_encode(['ok' => true, 'fy' => $_fy, 'data' => $_pts, 'targets' => $_tgts], JSON_UNESCAPED_UNICODE);
    exit;
}

$yearlyData  = getSalesRepReport($cid, $year,     $empFilter);
$prevYearly  = getSalesRepReport($cid, $year - 1, $empFilter);
$yearlyDataAll = $yearlyData; // 年間推移チャート用（フィルター前）

// 月間: 直営業を分離 → 残りを売上順 → 直営業を末尾
$monthlyData = $yearlyData;
uasort($monthlyData, function($a, $b) use ($month) {
    return ($b['months'][$month]['revenue'] ?? 0) <=> ($a['months'][$month]['revenue'] ?? 0);
});
// 今月0円でも年間売上がある（または年間案件数がある）人は表示する
$monthlyData    = array_filter($monthlyData, fn($d) =>
    ($d['months'][$month]['revenue'] ?? 0) > 0 ||
    ($d['months'][$month]['case_count'] ?? 0) > 0 ||
    $d['total_revenue'] > 0 ||
    $d['total_cases'] > 0
);
$monthlyDirect  = isset($monthlyData['直営業']) ? ['直営業' => $monthlyData['直営業']] : [];
$monthlyData    = array_filter($monthlyData, fn($d) => $d['sales_rep'] !== '直営業');

// 年間: 直営業を分離 → 残りを売上順 → 直営業を末尾
uasort($yearlyData, fn($a,$b) => $b['total_revenue'] <=> $a['total_revenue']);
$yearlyData    = array_filter($yearlyData, fn($d) => $d['total_revenue'] > 0);
$yearlyDirect  = isset($yearlyData['直営業']) ? ['直営業' => $yearlyData['直営業']] : [];
$yearlyData    = array_filter($yearlyData, fn($d) => $d['sales_rep'] !== '直営業');

// 強制表示メンバー（売上0でも必ずランキングに表示）
// 社員一覧で「営業担当」にチェックが入っている在籍中の正社員・自社外注を対象とする
// （旧: 山根脩平をベタ書き。名簿で管理できるようになったため置き換え）
$forcedMembers = getSalesRepCandidates($cid);
$emptyMonthEntry = ['revenue'=>0,'profit'=>0,'case_count'=>0,'regular_revenue'=>0,'event_revenue'=>0];
$emptyYearEntry  = ['sales_rep'=>'','total_revenue'=>0,'total_profit'=>0,'total_cases'=>0,'regular_revenue'=>0,'event_revenue'=>0,'months'=>[]];
foreach ($forcedMembers as $fm) {
    // 月間にいなければ0で追加
    $inMonthly = false;
    foreach ($monthlyData as $d) { if ($d['sales_rep'] === $fm) { $inMonthly = true; break; } }
    if (!$inMonthly) {
        $stub = $emptyYearEntry; $stub['sales_rep'] = $fm;
        $monthlyData[$fm] = $stub;
    }
    // 年間にいなければ0で追加
    $inYearly = false;
    foreach ($yearlyData as $d) { if ($d['sales_rep'] === $fm) { $inYearly = true; break; } }
    if (!$inYearly) {
        $stub = $emptyYearEntry; $stub['sales_rep'] = $fm;
        $yearlyData[$fm] = $stub;
    }
}

// ---- 担当者別年間推移チャートデータ（9月〜翌年8月） ----
$fiscalMonthSeq = [
    ['y'=>$year-1,'m'=>9],  ['y'=>$year-1,'m'=>10],
    ['y'=>$year-1,'m'=>11], ['y'=>$year-1,'m'=>12],
    ['y'=>$year,  'm'=>1],  ['y'=>$year,  'm'=>2],
    ['y'=>$year,  'm'=>3],  ['y'=>$year,  'm'=>4],
    ['y'=>$year,  'm'=>5],  ['y'=>$year,  'm'=>6],
    ['y'=>$year,  'm'=>7],  ['y'=>$year,  'm'=>8],
];
$fiscalChartData = [];
foreach (array_unique(array_merge(array_keys($yearlyDataAll), array_keys($prevYearly))) as $rk) {
    $pts = [];
    foreach ($fiscalMonthSeq as $ym) {
        $src = $ym['y'] === $year ? ($yearlyDataAll[$rk] ?? []) : ($prevYearly[$rk] ?? []);
        $md  = $src['months'][$ym['m']] ?? [];
        $rev = (int)($md['revenue'] ?? 0);
        $pro = (int)($md['profit']  ?? 0);
        $pts[] = ['revenue'=>$rev, 'profit'=>$pro, 'profitRate'=> $rev>0 ? round($pro/$rev*100,1) : null];
    }
    $fiscalChartData[$rk] = $pts;
}

// ---- 担当者別 月別売上目標（年度9月〜翌8月の2年分を1クエリで取得） ----
$repTargetData = [];
try {
    $_tStmt = getDB()->prepare('SELECT rep_name, year, month, target_revenue FROM sales_rep_targets
                                WHERE company_id = ? AND year IN (?, ?)');
    $_tStmt->execute([$cid, $year - 1, $year]);
    $_tMap = [];
    foreach ($_tStmt->fetchAll() as $_tr) {
        $_tMap[$_tr['rep_name'] . '|' . (int)$_tr['year'] . '|' . (int)$_tr['month']] = (int)$_tr['target_revenue'];
    }
    foreach (array_keys($fiscalChartData) as $rk) {
        $vals = [];
        foreach ($fiscalMonthSeq as $ym) {
            $vals[] = $_tMap[$rk . '|' . $ym['y'] . '|' . $ym['m']] ?? 0;
        }
        $repTargetData[$rk] = $vals;
    }
} catch (PDOException $e) {
    $repTargetData = [];
}

// インセンティブ率は社員一覧（employees.incentive_rate）で管理する。
// 定義は includes/sales/reports.php の resolveIncentiveRate() に一本化した。
$INCENTIVE_RATE_MAP = getIncentiveRateMap($cid);

require_once __DIR__ . '/../includes/header.php';

function renderRepCard(string $repName, array $cur, string $footerText, bool $showDetail = true): string {
    global $INCENTIVE_RATE_MAP;
    $rate = resolveIncentiveRate($repName, $INCENTIVE_RATE_MAP);
    $profit     = (int)($cur['profit'] ?? 0);
    $revenue    = (int)($cur['revenue'] ?? 0);
    $profitRate = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;
    $incentive  = ($rate > 0 && $profit > 0) ? (int)round($profit * $rate) : null;
    ob_start(); ?>
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div class="fw-bold fs-6"><?= h($repName) ?> <span class="text-muted small fw-normal ms-1"><?= ($cur['case_count'] ?? 0) ?>件</span></div>
                <?php if ($showDetail): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0 py-0 px-2" style="font-size:.72rem;line-height:1.8" data-repname="<?= htmlspecialchars($repName, ENT_QUOTES) ?>" onclick="openRepDetail(this.dataset.repname)">詳細</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td style="padding-left:.75rem">合計売上</td><td class="text-end fw-bold" style="padding-right:.75rem"><?= ($cur['revenue'] ?? 0) ? number_format($cur['revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#3b82f6;font-size:.8rem">●</span> 常勤売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['regular_revenue'] ?? 0) ? number_format($cur['regular_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#8b5cf6;font-size:.8rem">●</span> イベント売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['event_revenue'] ?? 0) ? number_format($cur['event_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">粗利</td><td class="text-end" style="padding-right:.75rem"><?= $profit ? number_format($profit) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">粗利率</td><td class="text-end" style="padding-right:.75rem"><?= $profitRate !== null ? number_format($profitRate, 1).'%' : '-' ?></td></tr>
                    <?php if ($incentive !== null): ?>
                    <tr style="background:#fffbeb">
                        <td style="padding-left:.75rem;color:#d97706;font-weight:500">インセンティブ</td>
                        <td class="text-end fw-bold" style="padding-right:.75rem;color:#d97706"><?= number_format($incentive) ?></td>
                    </tr>
                    <?php elseif ($rate === 0): ?>
                    <tr style="background:#f9fafb">
                        <td style="padding-left:.75rem;color:#9ca3af;font-size:.8rem">インセンティブ</td>
                        <td class="text-end text-muted small" style="padding-right:.75rem">なし</td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:#f9fafb"><td colspan="2" class="text-muted small" style="padding-left:.75rem;padding-right:.75rem"><?= $footerText ?></td></tr>
                </tbody>
            </table>
        </div>
    <?php return ob_get_clean();
}
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-badge me-2"></i>担当者別売上レポート</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex align-items-center gap-1">
                <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 月間ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">月間ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年<?= $month ?>月</small></h5>
            <?php if (empty($monthlyData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($monthlyData as $rep => $data): $rank++;
                $cur = $data['months'][$month] ?? [];
                $cur['revenue']         = $cur['revenue']         ?? 0;
                $cur['profit']          = $cur['profit']          ?? 0;
                $cur['case_count']      = $cur['case_count']      ?? 0;
                $cur['regular_revenue'] = $cur['regular_revenue'] ?? 0;
                $cur['event_revenue']   = $cur['event_revenue']   ?? 0;
                $prevMRev = $prevYearly[$rep]['months'][$month]['revenue'] ?? 0;
                $yoyText  = $prevMRev > 0 ? round(($cur['revenue'] - $prevMRev) / $prevMRev * 100, 1).'%' : '-';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard($data['sales_rep'], $cur, $year.'年'.$month.'月（前年同月：'.$yoyText.'）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php /* 直営業は順位なしで末尾に表示 */
            foreach ($monthlyDirect as $data):
                $cur = $data['months'][$month] ?? [];
                $cur['revenue']         = $cur['revenue']         ?? 0;
                $cur['profit']          = $cur['profit']          ?? 0;
                $cur['case_count']      = $cur['case_count']      ?? 0;
                $cur['regular_revenue'] = $cur['regular_revenue'] ?? 0;
                $cur['event_revenue']   = $cur['event_revenue']   ?? 0;
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard('直営業', $cur, $year.'年'.$month.'月（前年同月：-）') ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 年間総合ランキング -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">年間総合ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年</small></h5>
            <?php if (empty($yearlyData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($yearlyData as $rep => $data): $rank++;
                $annual = [
                    'revenue'         => $data['total_revenue'],
                    'profit'          => $data['total_profit'],
                    'case_count'      => $data['total_cases'],
                    'regular_revenue' => $data['regular_revenue'] ?? 0,
                    'event_revenue'   => $data['event_revenue']   ?? 0,
                ];
                $prevARev = $prevYearly[$rep]['total_revenue'] ?? 0;
                $yoyAText = $prevARev > 0 ? round(($data['total_revenue'] - $prevARev) / $prevARev * 100, 1).'%' : '-';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="client-rank rank-<?= $rank <= 3 ? $rank : 'other' ?>"><?= $rank ?></span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard($data['sales_rep'], $annual, $year.'年度合計（前年比：'.$yoyAText.'）', false) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php /* 直営業は順位なしで末尾に表示 */
            foreach ($yearlyDirect as $data):
                $annual = [
                    'revenue'         => $data['total_revenue'],
                    'profit'          => $data['total_profit'],
                    'case_count'      => $data['total_cases'],
                    'regular_revenue' => $data['regular_revenue'] ?? 0,
                    'event_revenue'   => $data['event_revenue']   ?? 0,
                ];
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCard('直営業', $annual, $year.'年度合計（前年比：-）', false) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<style>
/* 年間推移テーブル: 5行でもスクロールせず一目で見えるよう行を詰める */
.rep-detail-table td, .rep-detail-table th { padding: .2rem .3rem; vertical-align: middle; }
.rep-detail-table .rep-tgt-inp { box-shadow: none; background: transparent; }
.rep-detail-table .rep-tgt-inp:focus { background: transparent; }
</style>
<!-- ▼ 担当者詳細モーダル -->
<div class="modal fade" id="repDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="repDetailTitle"></h6>
                <div class="d-flex align-items-center gap-2 ms-auto me-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem" onclick="setRepFy(-1)" title="前年度">◀</button>
                    <span class="fw-semibold text-nowrap" id="repFyLabel" style="font-size:.75rem;min-width:150px;text-align:center"></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.7rem" onclick="setRepFy(1)" title="翌年度">▶</button>
                </div>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pb-3">
                <div style="position:relative;height:300px">
                    <canvas id="repDetailChart"></canvas>
                </div>
                <div class="mt-3" style="overflow-x:auto">
                    <table class="table table-sm table-bordered mb-0 text-center rep-detail-table" style="font-size:.72rem;min-width:760px">
                        <thead class="table-light" id="repDetailThead"></thead>
                        <tbody id="repDetailTbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var REP_FISCAL_DATA   = <?= json_encode($fiscalChartData, JSON_UNESCAPED_UNICODE) ?>;
var REP_TARGET_DATA   = <?= json_encode($repTargetData, JSON_UNESCAPED_UNICODE) ?>;
var REP_BASE_FY       = <?= (int)$year ?>;
var REP_TGT_CSRF      = '<?= h(getCsrfToken()) ?>';
var _repFy            = REP_BASE_FY;   // 詳細モーダルで表示中の年度
var _repFyLoading     = false;

// 年度 fy の12ヶ月分 [年, 月]（9月〜翌8月）
function repFiscalYm(fy) {
    var out = [];
    for (var i = 0; i < 4; i++)  out.push([fy - 1, 9 + i]);
    for (var j = 1; j <= 8; j++) out.push([fy, j]);
    return out;
}
function repFyLabel(fy) { return (fy - 1) + '年9月〜' + fy + '年8月'; }
var _repChart    = null;
var _repModalBs  = null;
var _chartReady  = false;
var _curRepData  = null;

function _ensureChartJs(cb) {
    if (_chartReady) { cb(); return; }
    function afterChart() {
        if (typeof ChartDataLabels !== 'undefined') {
            try { Chart.register(ChartDataLabels); } catch(e) {}
            _chartReady = true; cb();
        } else {
            var s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2';
            s2.onload = function() {
                try { Chart.register(ChartDataLabels); } catch(e) {}
                _chartReady = true; cb();
            };
            document.head.appendChild(s2);
        }
    }
    if (typeof Chart !== 'undefined') { afterChart(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4';
    s.onload = afterChart;
    document.head.appendChild(s);
}

function openRepDetail(repName) {
    _repFy = REP_BASE_FY;
    var data = REP_FISCAL_DATA[repName];
    // 売上0円で年間データがない担当者も全月0円として表示する
    if (!data) {
        data = [];
        for (var i = 0; i < 12; i++) data.push({revenue: 0, profit: 0, profitRate: null});
    }
    var targets = (REP_TARGET_DATA[repName] || []).slice();
    while (targets.length < 12) targets.push(0);
    _setRepDetailHeader(repName, _repFy);
    _curRepData = { name: repName, data: data, targets: targets, fy: _repFy };
    var modalEl = document.getElementById('repDetailModal');
    if (!_repModalBs) {
        _repModalBs = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('shown.bs.modal', function() {
            if (_curRepData) _ensureChartJs(function() {
                _drawRepChart(_curRepData.name, _curRepData.data, _curRepData.targets, _curRepData.fy);
            });
        });
    }
    _repModalBs.show();
}

function _setRepDetailHeader(repName, fy) {
    document.getElementById('repDetailTitle').textContent = repName + '　年間推移（' + repFyLabel(fy) + '）';
    var lbl = document.getElementById('repFyLabel');
    if (lbl) lbl.textContent = repFyLabel(fy);
}

// 年度切替（モーダル内のグラフ・表・タイトルのみ更新。画面はリロードしない）
function setRepFy(delta) {
    if (_repFyLoading || !_curRepData) return;
    var newFy  = _repFy + delta;
    var repName = _curRepData.name;
    _repFyLoading = true;
    var body = document.querySelector('#repDetailModal .modal-body');
    if (body) body.style.opacity = '.5';
    var url = location.pathname + '?ajax_rep_fy=1&fy=' + newFy + '&rep=' + encodeURIComponent(repName);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) throw new Error('failed');
            _repFy = d.fy;
            _curRepData = { name: repName, data: d.data, targets: d.targets, fy: d.fy };
            _setRepDetailHeader(repName, d.fy);
            _ensureChartJs(function() { _drawRepChart(repName, d.data, d.targets, d.fy); });
        })
        .catch(function() { alert('年度データの取得に失敗しました'); })
        .then(function() {
            _repFyLoading = false;
            if (body) body.style.opacity = '1';
        });
}

function _drawRepChart(repName, data, targetsArg, fy) {
    if (typeof fy === 'undefined' || !fy) fy = _repFy;
    var labels   = ['9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月','8月'];
    var revenues = data.map(function(d) { return d.revenue; });
    var profits  = data.map(function(d) { return d.profit; });
    var rates    = data.map(function(d) { return d.profitRate; });

    var targets = (targetsArg || REP_TARGET_DATA[repName] || []).slice();
    while (targets.length < 12) targets.push(0);
    var ymList = repFiscalYm(fy);

    // 目標が未入力(0)の月は線を引かない
    function _tgtLine()  { return targets.map(function(t) { return t > 0 ? t : null; }); }
    // ※売上達成率はグラフから外したため、表側の _updateAchv() のみで計算する
    // 累計（年度の初め=9月から順に足し上げる）。既存の月別データを合計するだけで計算式は変えない
    function _cum(arr) {
        var sum = 0;
        return arr.map(function(v) { sum += (v || 0); return sum; });
    }
    // 累計目標: 目標が未入力(0)の月は点を打たない（従来の「目標線を引かない」仕様に合わせる）
    function _cumTgtLine() {
        var sum = 0;
        return targets.map(function(t) {
            if (!t || t <= 0) return null;
            sum += t;
            return sum;
        });
    }

    if (_repChart) { _repChart.destroy(); _repChart = null; }
    var ctx = document.getElementById('repDetailChart').getContext('2d');

    // 当月＝棒グラフ（左軸）／累計＝折れ線（右軸）
    _repChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '当月売上',
                    type: 'bar',
                    data: revenues,
                    backgroundColor: '#60a5fa',
                    borderColor: '#3b82f6',
                    borderWidth: 0,
                    yAxisID: 'y',
                    order: 3,
                    datalabels: { display: false },
                },
                {
                    label: '当月粗利',
                    type: 'bar',
                    data: profits,
                    backgroundColor: '#fbbf24',
                    borderColor: '#f59e0b',
                    borderWidth: 0,
                    yAxisID: 'y',
                    order: 3,
                    datalabels: { display: false },
                },
                {
                    label: '累計売上',
                    type: 'line',
                    data: _cum(revenues),
                    borderColor: '#2563eb',
                    backgroundColor: '#2563eb',
                    pointBackgroundColor: '#2563eb',
                    yAxisID: 'y2',
                    tension: 0,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2,
                    order: 1,
                    datalabels: { display: false },
                },
                {
                    label: '累計粗利',
                    type: 'line',
                    data: _cum(profits),
                    borderColor: '#ea580c',
                    backgroundColor: '#ea580c',
                    pointBackgroundColor: '#ea580c',
                    yAxisID: 'y2',
                    tension: 0,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2,
                    order: 1,
                    datalabels: { display: false },
                },
                {
                    label: '累計目標',
                    type: 'line',
                    data: _cumTgtLine(),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.06)',
                    borderDash: [6, 4],
                    yAxisID: 'y2',
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                    spanGaps: true,
                    order: 2,
                    datalabels: { display: false },
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(ctx) {
                            var v = ctx.parsed.y;
                            // 当月・累計とも金額（円）
                            if (v === null || v === undefined) return ' ' + ctx.dataset.label + ': -';
                            return ' ' + ctx.dataset.label + ': ' + v.toLocaleString() + '円';
                        }
                    }
                },
                datalabels: { display: false },
            },
            scales: {
                // 左軸: 当月の売上・粗利（万円）
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    title: { display: true, text: '（万円）', font: { size: 10 }, color: '#6b7280' },
                    ticks: {
                        callback: function(v) {
                            if (v === 0) return '0';
                            return Math.round(v / 10000).toLocaleString();
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.06)' },
                },
                // 右軸: 累計の売上・粗利・目標（万円）
                y2: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    title: { display: true, text: '（万円）', font: { size: 10 }, color: '#6b7280' },
                    ticks: {
                        callback: function(v) {
                            if (v === 0) return '0';
                            return Math.round(v / 10000).toLocaleString();
                        }
                    },
                    grid: { drawOnChartArea: false },
                },
            },
        },
    });

    // 月別数値テーブル（売上目標 → 売上 → 売上達成率 → 粗利 → 粗利率）
    var thead = document.getElementById('repDetailThead');
    var tbody = document.getElementById('repDetailTbody');
    // 累計列（末尾）: 背景色を付けて月別と区切る。率は総合ダッシュボードと同じく合計同士で割る
    var CUM_TD = ' class="text-end text-nowrap fw-semibold" style="background:#f1f5f9"';
    thead.innerHTML = '<tr><th style="min-width:56px">月</th>' +
        labels.map(function(m) { return '<th class="text-end">' + m + '</th>'; }).join('') +
        '<th class="text-end" style="background:#e2e8f0;min-width:78px">累計</th></tr>';
    var fmtV = function(v) {
        return v > 0 ? '<span>' + v.toLocaleString() + '</span>' : '<span class="text-muted">-</span>';
    };
    var fmtR = function(v) {
        return v !== null ? '<span style="color:#d97706;font-weight:600">' + v + '%</span>' : '<span class="text-muted">-</span>';
    };
    var _sum = function(arr) {
        return arr.reduce(function(a, b) { return a + (b || 0); }, 0);
    };
    var cumRev = _sum(revenues);
    var cumPro = _sum(profits);

    var html = '';
    // 1. 売上目標（手入力）
    html += '<tr><td class="fw-semibold text-start text-nowrap">売上目標</td>';
    for (var i = 0; i < 12; i++) {
        html += '<td class="p-0"><input type="text" inputmode="numeric" class="rep-tgt-inp form-control form-control-sm border-0 text-end px-1"'
             + ' style="font-size:.72rem;height:24px;background:transparent" data-idx="' + i + '"'
             + ' value="' + (targets[i] > 0 ? targets[i].toLocaleString() : '') + '"></td>';
    }
    // 累計は入力欄にせず自動計算（各月と合計がずれないようにするため）
    html += '<td' + CUM_TD + ' id="repCumTgt"></td></tr>';
    // 2. 売上
    html += '<tr><td class="fw-semibold text-start text-nowrap">売上</td>'
         + revenues.map(function(v) { return '<td class="text-end text-nowrap">' + fmtV(v) + '</td>'; }).join('')
         + '<td' + CUM_TD + '>' + fmtV(cumRev) + '</td></tr>';
    // 3. 売上達成率（売上 ÷ 売上目標 × 100）
    html += '<tr><td class="fw-semibold text-start text-nowrap">売上達成率</td>';
    for (var j = 0; j < 12; j++) {
        html += '<td class="text-end text-nowrap" id="repAchv' + j + '"></td>';
    }
    html += '<td' + CUM_TD + ' id="repCumAchv"></td></tr>';
    // 4. 粗利
    html += '<tr><td class="fw-semibold text-start text-nowrap">粗利</td>'
         + profits.map(function(v) { return '<td class="text-end text-nowrap">' + fmtV(v) + '</td>'; }).join('')
         + '<td' + CUM_TD + '>' + fmtV(cumPro) + '</td></tr>';
    // 5. 粗利率（粗利の合計 ÷ 売上の合計。総合ダッシュボードと同じ求め方）
    html += '<tr><td class="fw-semibold text-start text-nowrap">粗利率</td>'
         + rates.map(function(v) { return '<td class="text-end text-nowrap">' + fmtR(v) + '</td>'; }).join('')
         + '<td' + CUM_TD + '>' + fmtR(cumRev > 0 ? Math.round(cumPro / cumRev * 1000) / 10 : null) + '</td></tr>';
    tbody.innerHTML = html;

    // 達成率セルの更新（目標未入力・0なら「-」）
    function _updateAchv(idx) {
        var cell = document.getElementById('repAchv' + idx);
        if (!cell) return;
        var tgt = targets[idx] || 0;
        var rev = revenues[idx] || 0;
        if (tgt <= 0) { cell.innerHTML = '<span class="text-muted">-</span>'; return; }
        var pct = Math.round(rev / tgt * 1000) / 10;
        cell.innerHTML = '<span style="font-weight:600;color:' + (pct >= 100 ? '#059669' : '#dc2626') + '">' + pct + '%</span>';
    }
    // 累計の「売上目標」「売上達成率」は目標の入力に応じて変わるため、その都度計算し直す
    function _updateCum() {
        var cumTgt = _sum(targets);
        var tCell = document.getElementById('repCumTgt');
        var aCell = document.getElementById('repCumAchv');
        if (tCell) tCell.innerHTML = fmtV(cumTgt);
        if (aCell) {
            if (cumTgt <= 0) { aCell.innerHTML = '<span class="text-muted">-</span>'; }
            else {
                var pct = Math.round(cumRev / cumTgt * 1000) / 10;
                aCell.innerHTML = '<span style="font-weight:600;color:' + (pct >= 100 ? '#059669' : '#dc2626') + '">' + pct + '%</span>';
            }
        }
    }

    for (var k = 0; k < 12; k++) _updateAchv(k);
    _updateCum();

    // 目標を入力したらグラフの「累計目標」を即時反映（再描画せずデータ差し替えのみ）
    // ※累計売上・累計粗利は目標に影響されないため差し替え不要
    function _syncTargetSeries() {
        if (!_repChart || !_repChart.data) return;
        var ds = _repChart.data.datasets;
        if (ds.length < 5) return;
        ds[4].data = _cumTgtLine();
        _repChart.update();
    }

    // 売上目標の入力 → 達成率を即時再計算 → 非同期保存（画面リロードなし）
    tbody.querySelectorAll('.rep-tgt-inp').forEach(function(inp) {
        inp.addEventListener('input', function() {
            var idx = parseInt(inp.dataset.idx, 10);
            targets[idx] = Math.max(0, parseInt(String(inp.value).replace(/[^0-9]/g, ''), 10) || 0);
            _updateAchv(idx);
            _updateCum();
            _syncTargetSeries();
        });
        inp.addEventListener('change', function() {
            var idx = parseInt(inp.dataset.idx, 10);
            var val = targets[idx] || 0;
            inp.value = val > 0 ? val.toLocaleString() : '';
            // 基準年度を表示中のときのみキャッシュを更新（他年度はAPIから都度取得）
            if (fy === REP_BASE_FY) {
                if (!REP_TARGET_DATA[repName]) REP_TARGET_DATA[repName] = targets.slice();
                REP_TARGET_DATA[repName][idx] = val;
            }
            if (_curRepData && _curRepData.fy === fy) _curRepData.targets = targets.slice();
            var ym = ymList[idx];
            var fd = new FormData();
            fd.append('action', 'save_rep_target');
            fd.append('csrf', REP_TGT_CSRF);
            fd.append('rep_name', repName);
            fd.append('t_year', ym[0]);
            fd.append('t_month', ym[1]);
            fd.append('t_value', val);
            fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    // 保存失敗時のみ一時的に色を付けて知らせる（通常時は周囲のセルと同じ背景）
                    if (res && res.error) { inp.style.background = '#fee2e2'; }
                    else { inp.style.background = 'transparent'; }
                })
                .catch(function() { inp.style.background = '#fee2e2'; });
        });
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
