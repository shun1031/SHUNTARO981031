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

$empFilter   = getEmployeeNameFilter();
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
$forcedMembers = ['山根脩平'];
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

// インセンティブ率マップ（0=なし、それ以外は割合）
$INCENTIVE_RATES = [
    '竹内陽'   => 0,
    '直営業'   => 0,
    '佐藤思杰' => 0.20,
    '近藤航'   => 0.20,
];
$INCENTIVE_DEFAULT = 0.30;

require_once __DIR__ . '/../includes/header.php';

function renderRepCard(string $repName, array $cur, string $footerText, bool $showDetail = true): string {
    global $INCENTIVE_RATES, $INCENTIVE_DEFAULT;
    $rate = array_key_exists($repName, $INCENTIVE_RATES)
        ? $INCENTIVE_RATES[$repName]
        : $INCENTIVE_DEFAULT;
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
<!-- ▼ 担当者詳細モーダル -->
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
                            return Math.round(v / 10000).toLocaleString() + '万';
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

    // 月別数値テーブル
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
