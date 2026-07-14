<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '担当者別前月比';
$extraCss = ['sales.css'];
$extraJs = ['sales.js'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// ナビ用 前月/翌月
$prevNavM = $month - 1; $prevNavY = $year;
if ($prevNavM < 1) { $prevNavM = 12; $prevNavY--; }
$nextNavM = $month + 1; $nextNavY = $year;
if ($nextNavM > 12) { $nextNavM = 1; $nextNavY++; }

// 左側 = 前月（leftM/leftY）
$leftM = $month - 1; $leftY = $year;
if ($leftM < 1) { $leftM = 12; $leftY--; }

// 左側の比較基準月（さらに1ヶ月前）
$lcmpM = $leftM - 1; $lcmpY = $leftY;
if ($lcmpM < 1) { $lcmpM = 12; $lcmpY--; }

// データ取得（当年・前年のみで全ケースをカバー）
$empFilter       = getEmployeeNameFilter();
$currentYearData = getSalesRepReport($cid, $year,     $empFilter);
$prevYearData    = getSalesRepReport($cid, $year - 1, $empFilter);

$emptyMonth = ['revenue'=>0,'profit'=>0,'case_count'=>0,'regular_revenue'=>0,'event_revenue'=>0];

$getMonthData = function(string $rep, int $y, int $m) use ($year, &$currentYearData, &$prevYearData, $emptyMonth): array {
    $src = ($y === $year) ? $currentYearData : $prevYearData;
    return $src[$rep]['months'][$m] ?? $emptyMonth;
};

// 全担当者を収集
$allReps = [];
foreach ([$currentYearData, $prevYearData] as $yd) {
    foreach (array_keys($yd) as $k) { $allReps[$k] = true; }
}

// 前月比計算（小数第1位）
$calcMomRate = function(int $cur, int $prev): ?float {
    if ($prev <= 0) return null;
    return round($cur / $prev * 100, 1);
};

// ──── 右側（当月 = $month/$year）データ ────
$rightData = [];
foreach (array_keys($allReps) as $rep) {
    $cur  = $getMonthData($rep, $year,  $month);
    $prev = $getMonthData($rep, $leftY, $leftM);
    if (($cur['revenue'] ?? 0) <= 0 && ($prev['revenue'] ?? 0) <= 0) continue;
    $rightData[$rep] = [
        'rep'      => $rep,
        'cur'      => $cur,
        'prev'     => $prev,
        'rev_mom'  => $calcMomRate((int)($cur['revenue'] ?? 0), (int)($prev['revenue'] ?? 0)),
        'prof_mom' => $calcMomRate((int)($cur['profit']  ?? 0), (int)($prev['profit']  ?? 0)),
    ];
}

// ──── 左側（前月 = $leftM/$leftY）データ ────
$leftData = [];
foreach (array_keys($allReps) as $rep) {
    $cur  = $getMonthData($rep, $leftY, $leftM);
    $prev = $getMonthData($rep, $lcmpY, $lcmpM);
    if (($cur['revenue'] ?? 0) <= 0 && ($prev['revenue'] ?? 0) <= 0) continue;
    $leftData[$rep] = [
        'rep'      => $rep,
        'cur'      => $cur,
        'prev'     => $prev,
        'rev_mom'  => $calcMomRate((int)($cur['revenue'] ?? 0), (int)($prev['revenue'] ?? 0)),
        'prof_mom' => $calcMomRate((int)($cur['profit']  ?? 0), (int)($prev['profit']  ?? 0)),
    ];
}

// ソート: 売上前月比の高い順、同率は売上額の高い順、前月比なし(null)は末尾
$sortByMom = function(array &$data): void {
    uasort($data, function($a, $b) {
        if ($a['rev_mom'] === null && $b['rev_mom'] === null) {
            return ($b['cur']['revenue'] ?? 0) <=> ($a['cur']['revenue'] ?? 0);
        }
        if ($a['rev_mom'] === null) return 1;
        if ($b['rev_mom'] === null) return -1;
        $cmp = $b['rev_mom'] <=> $a['rev_mom'];
        if ($cmp !== 0) return $cmp;
        return ($b['cur']['revenue'] ?? 0) <=> ($a['cur']['revenue'] ?? 0);
    });
};

// 直営業を末尾に分離
$sortByMom($rightData);
$rightDirect = isset($rightData['直営業']) ? ['直営業' => $rightData['直営業']] : [];
$rightData   = array_filter($rightData, fn($d) => $d['rep'] !== '直営業');

$sortByMom($leftData);
$leftDirect = isset($leftData['直営業']) ? ['直営業' => $leftData['直営業']] : [];
$leftData   = array_filter($leftData, fn($d) => $d['rep'] !== '直営業');

// 強制表示メンバー（売上0でも常に表示）
$forcedMembers = ['山根脩平'];
$emptyEntry = ['rep'=>'','cur'=>$emptyMonth,'prev'=>$emptyMonth,'rev_mom'=>null,'prof_mom'=>null];
foreach ($forcedMembers as $fm) {
    $inRight = false; foreach ($rightData as $d) { if ($d['rep'] === $fm) { $inRight = true; break; } }
    if (!$inRight) { $stub = $emptyEntry; $stub['rep'] = $fm; $rightData[$fm] = $stub; }
    $inLeft = false; foreach ($leftData as $d) { if ($d['rep'] === $fm) { $inLeft = true; break; } }
    if (!$inLeft) { $stub = $emptyEntry; $stub['rep'] = $fm; $leftData[$fm] = $stub; }
}

// 詳細モーダル用年間推移データ（元の画面と同一）
$fiscalMonthSeq = [
    ['y'=>$year-1,'m'=>9],  ['y'=>$year-1,'m'=>10],
    ['y'=>$year-1,'m'=>11], ['y'=>$year-1,'m'=>12],
    ['y'=>$year,  'm'=>1],  ['y'=>$year,  'm'=>2],
    ['y'=>$year,  'm'=>3],  ['y'=>$year,  'm'=>4],
    ['y'=>$year,  'm'=>5],  ['y'=>$year,  'm'=>6],
    ['y'=>$year,  'm'=>7],  ['y'=>$year,  'm'=>8],
];
$fiscalChartData = [];
foreach (array_unique(array_merge(array_keys($currentYearData), array_keys($prevYearData))) as $rk) {
    $pts = [];
    foreach ($fiscalMonthSeq as $ym) {
        $src = $ym['y'] === $year ? ($currentYearData[$rk] ?? []) : ($prevYearData[$rk] ?? []);
        $md  = $src['months'][$ym['m']] ?? [];
        $rev = (int)($md['revenue'] ?? 0);
        $pro = (int)($md['profit']  ?? 0);
        $pts[] = ['revenue'=>$rev,'profit'=>$pro,'profitRate'=>$rev>0?round($pro/$rev*100,1):null];
    }
    $fiscalChartData[$rk] = $pts;
}

require_once __DIR__ . '/../includes/header.php';

// ──── 前月比カード描画関数 ────
function renderRepCardMom(string $repName, array $entry, string $footerText, bool $showDetail = true): string {
    $cur     = $entry['cur'];
    $prev    = $entry['prev'];
    $revMom  = $entry['rev_mom'];
    $profMom = $entry['prof_mom'];

    $revenue  = (int)($cur['revenue'] ?? 0);
    $profit   = (int)($cur['profit']  ?? 0);
    $profRate = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

    $fmtMom = function(?float $mom, int $curV, int $prevV): string {
        if ($mom === null || $prevV <= 0) return '-';
        $diff  = $curV - $prevV;
        $pct   = round($mom - 100, 1);
        $sign  = $diff >= 0 ? '+' : '';
        $pSign = $pct  >= 0 ? '+' : '';
        return $sign . number_format($diff) . '円(' . $pSign . number_format($pct, 1) . '%)';
    };
    $fmtColor = function(?float $mom): string {
        if ($mom === null)  return '#6b7280';
        if ($mom > 100.0)   return '#059669';
        if ($mom < 100.0)   return '#dc2626';
        return '#374151';
    };

    $prevRev  = (int)($prev['revenue'] ?? 0);
    $prevProf = (int)($prev['profit']  ?? 0);
    $revMomStr  = $fmtMom($revMom,  $revenue, $prevRev);
    $profMomStr = $fmtMom($profMom, $profit,  $prevProf);
    $revColor   = $fmtColor($revMom);
    $profColor  = $fmtColor($profMom);

    ob_start(); ?>
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div class="fw-bold fs-6"><?= h($repName) ?></div>
                <?php if ($showDetail): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0 py-0 px-2" style="font-size:.72rem;line-height:1.8" data-repname="<?= htmlspecialchars($repName, ENT_QUOTES) ?>" onclick="openRepDetail(this.dataset.repname)">詳細</button>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap mt-1">
                <span><span class="text-muted small">売上</span> <span class="fw-bold" style="color:#059669"><?= number_format($revenue) ?></span></span>
                <span class="text-muted small">粗利 <?= number_format($profit) ?></span>
                <?php if ($profRate !== null): ?>
                <span class="text-muted small">粗利率 <?= number_format($profRate, 1) ?>%</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr><td style="padding-left:.75rem">合計売上</td><td class="text-end fw-bold" style="padding-right:.75rem"><?= $revenue ? number_format($revenue) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#3b82f6;font-size:.8rem">●</span> 常勤売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['regular_revenue'] ?? 0) ? number_format($cur['regular_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem"><span style="color:#8b5cf6;font-size:.8rem">●</span> イベント売上</td><td class="text-end" style="padding-right:.75rem"><?= ($cur['event_revenue'] ?? 0) ? number_format($cur['event_revenue']) : '-' ?></td></tr>
                    <tr><td style="padding-left:.75rem">粗利</td><td class="text-end" style="padding-right:.75rem"><?= $profit ? number_format($profit) : '-' ?></td></tr>
                    <tr style="background:#f0fdf4">
                        <td style="padding-left:.75rem;font-weight:500">売上前月比</td>
                        <td class="text-end fw-bold" style="padding-right:.75rem;color:<?= $revColor ?>"><?= h($revMomStr) ?></td>
                    </tr>
                    <tr style="background:#f0fdf4">
                        <td style="padding-left:.75rem;font-weight:500">粗利前月比</td>
                        <td class="text-end fw-bold" style="padding-right:.75rem;color:<?= $profColor ?>"><?= h($profMomStr) ?></td>
                    </tr>
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
                <h1><i class="bi bi-person-badge me-2"></i>担当者別前月比レポート</h1>
                <p><?= $year ?>年<?= $month ?>月</p>
            </div>
            <div class="d-flex align-items-center gap-1">
                <a href="?year=<?= $prevNavY ?>&month=<?= $prevNavM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">‹</a>
                <span class="fw-bold px-2" style="min-width:110px;text-align:center;font-size:.95rem"><?= $year ?>年<?= $month ?>月</span>
                <a href="?year=<?= $nextNavY ?>&month=<?= $nextNavM ?>" class="btn btn-outline-secondary btn-sm px-3" style="font-size:1rem">›</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 前月ランキング（左側） -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">前月ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $leftY ?>年<?= $leftM ?>月</small></h5>
            <?php if (empty($leftData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($leftData as $rep => $entry):
                $hasRate     = $entry['rev_mom'] !== null;
                if ($hasRate) $rank++;
                $rankDisplay = $hasRate ? $rank : '—';
                $rankClass   = $hasRate && $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                $footer      = $leftY.'年'.$leftM.'月（比較月: '.$lcmpY.'年'.$lcmpM.'月）';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <?php if ($hasRate): ?>
                        <span class="client-rank <?= $rankClass ?>"><?= $rankDisplay ?></span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.75rem">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCardMom($entry['rep'], $entry, $footer) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php foreach ($leftDirect as $entry):
                $footer = $leftY.'年'.$leftM.'月（比較月: '.$lcmpY.'年'.$lcmpM.'月）';
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCardMom('直営業', $entry, $footer, false) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 当月ランキング（右側） -->
        <div class="col-lg-6">
            <h5 class="fw-bold mb-3" style="color:#374151">当月ランキング <small class="text-muted fw-normal" style="font-size:.8rem"><?= $year ?>年<?= $month ?>月</small></h5>
            <?php if (empty($rightData)): ?>
            <div class="card"><div class="card-body text-center text-muted py-4">データなし</div></div>
            <?php endif; ?>
            <?php $rank = 0; foreach ($rightData as $rep => $entry):
                $hasRate     = $entry['rev_mom'] !== null;
                if ($hasRate) $rank++;
                $rankDisplay = $hasRate ? $rank : '—';
                $rankClass   = $hasRate && $rank <= 3 ? 'rank-' . $rank : 'rank-other';
                $footer      = $year.'年'.$month.'月（比較月: '.$leftY.'年'.$leftM.'月）';
            ?>
            <div class="card mb-3">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <?php if ($hasRate): ?>
                        <span class="client-rank <?= $rankClass ?>"><?= $rankDisplay ?></span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.75rem">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCardMom($entry['rep'], $entry, $footer) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php foreach ($rightDirect as $entry):
                $footer = $year.'年'.$month.'月（比較月: '.$leftY.'年'.$leftM.'月）';
            ?>
            <div class="card mb-3" style="border-style:dashed;opacity:.85">
                <div class="d-flex align-items-start gap-0">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;padding-top:.75rem;padding-left:.75rem">
                        <span class="text-muted" style="font-size:.75rem">—</span>
                    </div>
                    <div class="flex-grow-1">
                        <?= renderRepCardMom('直営業', $entry, $footer, false) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 担当者詳細モーダル（元の画面と同一） -->
<div class="modal fade" id="repDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="repDetailTitle"></h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pb-3">
                <div style="position:relative;height:300px">
                    <canvas id="repDetailChart"></canvas>
                </div>
                <div class="mt-3" style="overflow-x:auto">
                    <table class="table table-sm table-bordered mb-0 text-center" style="font-size:.75rem;min-width:680px">
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
var FISCAL_YEAR_LABEL = '<?= ($year-1) ?>年9月〜<?= $year ?>年8月';
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
    var data = REP_FISCAL_DATA[repName];
    if (!data) return;
    document.getElementById('repDetailTitle').textContent = repName + '　年間推移（' + FISCAL_YEAR_LABEL + '）';
    _curRepData = { name: repName, data: data };
    var modalEl = document.getElementById('repDetailModal');
    if (!_repModalBs) {
        _repModalBs = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('shown.bs.modal', function() {
            if (_curRepData) _ensureChartJs(function() { _drawRepChart(_curRepData.name, _curRepData.data); });
        });
    }
    _repModalBs.show();
}

function _drawRepChart(repName, data) {
    var labels   = ['9月','10月','11月','12月','1月','2月','3月','4月','5月','6月','7月','8月'];
    var revenues = data.map(function(d) { return d.revenue; });
    var profits  = data.map(function(d) { return d.profit; });
    var rates    = data.map(function(d) { return d.profitRate; });

    if (_repChart) { _repChart.destroy(); _repChart = null; }
    var ctx = document.getElementById('repDetailChart').getContext('2d');

    _repChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '売上',
                    data: revenues,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.06)',
                    yAxisID: 'y',
                    tension: 0,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    datalabels: { display: false },
                },
                {
                    label: '粗利',
                    data: profits,
                    borderColor: '#1e40af',
                    backgroundColor: 'rgba(30,64,175,0.04)',
                    yAxisID: 'y',
                    tension: 0,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    datalabels: { display: false },
                },
                {
                    label: '粗利率',
                    data: rates,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,0.06)',
                    yAxisID: 'y2',
                    tension: 0,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#f59e0b',
                    spanGaps: true,
                    datalabels: {
                        display: function(ctx) {
                            var v = ctx.dataset.data[ctx.dataIndex];
                            return v !== null && v !== undefined;
                        },
                        formatter: function(v) { return v + '%'; },
                        color: '#d97706',
                        font: { size: 11, weight: 'bold' },
                        anchor: 'end',
                        align: 'top',
                        offset: 2,
                    },
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
                            if (ctx.datasetIndex === 2) return ' 粗利率: ' + (v !== null ? v + '%' : '-');
                            return ' ' + ctx.dataset.label + ': ' + (v ? v.toLocaleString() + '円' : '0円');
                        }
                    }
                },
                datalabels: { display: false },
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: function(v) {
                            if (v === 0) return '0';
                            if (v >= 1000000) return (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
                            return Math.round(v / 10000) + '万';
                        }
                    },
                    grid: { color: 'rgba(0,0,0,0.06)' },
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    suggestedMax: 80,
                    ticks: {
                        callback: function(v) { return v + '%'; },
                        stepSize: 20,
                    },
                    grid: { drawOnChartArea: false },
                },
            },
        },
    });

    var thead = document.getElementById('repDetailThead');
    var tbody = document.getElementById('repDetailTbody');
    thead.innerHTML = '<tr><th style="min-width:50px">月</th>' +
        labels.map(function(m) { return '<th class="text-end">' + m + '</th>'; }).join('') + '</tr>';
    var fmtV = function(v) {
        return v > 0 ? '<span>' + v.toLocaleString() + '</span>' : '<span class="text-muted">-</span>';
    };
    var fmtR = function(v) {
        return v !== null ? '<span style="color:#d97706;font-weight:600">' + v + '%</span>' : '<span class="text-muted">-</span>';
    };
    tbody.innerHTML = [
        { label: '売上', vals: revenues.map(fmtV) },
        { label: '粗利', vals: profits.map(fmtV) },
        { label: '粗利率', vals: rates.map(fmtR) },
    ].map(function(row) {
        return '<tr><td class="fw-semibold text-start text-nowrap">' + row.label + '</td>' +
            row.vals.map(function(v) { return '<td class="text-end text-nowrap">' + v + '</td>'; }).join('') +
            '</tr>';
    }).join('');
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
