<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h1>403 アクセス権限がありません</h1>';
    echo '<p>この機能は管理者のみ利用できます。</p>';
    echo '<a href="' . BASE_PATH . '/public/index.php">ダッシュボードへ戻る</a>';
    echo '</body></html>';
    exit;
}
$cid = getCompanyId();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) redirect(BASE_PATH . '/public/teams.php');

$team    = getTeam($id, $cid);
if (!$team) redirect(BASE_PATH . '/public/teams.php');

$members = getTeamMembers($id);
$sfDefs  = getStrengthsThemeDefinitions();

$pageTitle = h($team['name']);

// チーム全体のSF集計
$domainTotals = ['実行力' => 0, '影響力' => 0, '人間関係力' => 0, '戦略的思考力' => 0];
$top5Counts   = [];
foreach ($members as $m) {
    $sfData = getStrengthsFinder($m['id']);
    if (!$sfData) continue;
    $top5 = getTop5Strengths($sfData);
    foreach ($top5 as $key => $rank) {
        $def = $sfDefs[$key] ?? null;
        if (!$def) continue;
        $domainTotals[$def['domain']] = ($domainTotals[$def['domain']] ?? 0) + 1;
        $top5Counts[$key]             = ($top5Counts[$key] ?? 0) + 1;
    }
}
arsort($top5Counts);

// チームSPIスコア平均
$spiAvg   = [];
$spiCount = 0;
$spiDims  = getSpiDimensions();
$workKeys = array_keys($spiDims['workplace']['items']);
foreach ($members as $m) {
    $sp = getSpiResult($m['id']);
    if (!$sp) continue;
    $spiCount++;
    foreach ($workKeys as $key) {
        $spiAvg[$key] = ($spiAvg[$key] ?? 0) + ($sp[$key] ?? 0);
    }
}
if ($spiCount > 0) {
    foreach ($spiAvg as $k => $v) {
        $spiAvg[$k] = round($v / $spiCount, 1);
    }
}

$radarLabels = array_values($spiDims['workplace']['items']);
$radarData   = array_values($spiAvg);
$inlineJs    = 'window.BMS_BASE_PATH = "' . BASE_PATH . '";';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/public/index.php">ホーム</a></li>
            <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/public/teams.php">チーム</a></li>
            <li class="breadcrumb-item active"><?= h($team['name']) ?></li>
        </ol>
    </nav>

    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-diagram-3 me-2"></i><?= h($team['name']) ?></h1>
                <?php if ($team['description']): ?>
                <p><?= h($team['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 左カラム: チーム情報 -->
        <div class="col-lg-7">

            <!-- マネジメント情報 -->
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person-gear me-2"></i>マネジメント情報</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($team['manager_name']): ?>
                        <div class="col-sm-6">
                            <div class="info-label">マネージャー</div>
                            <a href="employee.php?id=<?= $team['manager_id'] ?>" class="d-flex align-items-center gap-2 mt-2 text-decoration-none">
                                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#059669,#34d399);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">
                                    <?= h(mb_substr($team['manager_name'], 0, 1)) ?>
                                </div>
                                <span class="fw-medium"><?= h($team['manager_name']) ?></span>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if ($team['sub_manager_name']): ?>
                        <div class="col-sm-6">
                            <div class="info-label">サブマネージャー</div>
                            <a href="employee.php?id=<?= $team['sub_manager_id'] ?>" class="d-flex align-items-center gap-2 mt-2 text-decoration-none">
                                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#95a5a6,#7f8c8d);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">
                                    <?= h(mb_substr($team['sub_manager_name'], 0, 1)) ?>
                                </div>
                                <span class="fw-medium"><?= h($team['sub_manager_name']) ?></span>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($team['management_points']): ?>
                    <div class="mt-3 p-3 rounded" style="background:#eff3ff;border-left:3px solid #3498db">
                        <div class="info-label mb-1">マネジメントの要点</div>
                        <p class="small mb-0" style="line-height:1.8"><?= nl2br(h($team['management_points'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- メンバー一覧 -->
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-people me-2"></i>メンバー (<?= count($members) ?>名)</div>
                <div class="card-body p-0">
                    <?php foreach ($members as $m): ?>
                    <?php
                        $mSf   = getStrengthsFinder($m['id']);
                        $mTop5 = $mSf ? getTop5Strengths($mSf) : [];
                    ?>
                    <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                        <a href="employee.php?id=<?= $m['id'] ?>" style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#059669,#34d399);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;text-decoration:none;flex-shrink:0">
                            <?= h(mb_substr($m['name'], 0, 1)) ?>
                        </a>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between flex-wrap gap-1">
                                <div>
                                    <a href="employee.php?id=<?= $m['id'] ?>" class="fw-semibold text-decoration-none text-dark"><?= h($m['name']) ?></a>
                                    <span class="text-muted small ms-2"><?= h($m['job_title'] ?? '') ?></span>
                                </div>
                            </div>
                            <?php if (!empty($mTop5)): ?>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php foreach ($mTop5 as $key => $rank): ?>
                                <?php $def = $sfDefs[$key] ?? ['ja' => $key, 'domain' => '']; ?>
                                <span class="badge" style="font-size:10px;background:#f0f4ff;color:#3d5a9e"><?= h($def['ja']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- チームの強みと課題 -->
            <?php if ($team['team_strengths'] || $team['team_challenges']): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-clipboard-data me-2"></i>チームアセスメント</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($team['team_strengths']): ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded h-100" style="background:#f0fdf4;border-left:4px solid #27ae60">
                                <div class="fw-semibold mb-2" style="color:#27ae60"><i class="bi bi-check-circle me-1"></i>チームの強み</div>
                                <p class="small mb-0" style="line-height:1.8"><?= nl2br(h($team['team_strengths'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($team['team_challenges']): ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded h-100" style="background:#fff8f0;border-left:4px solid #f39c12">
                                <div class="fw-semibold mb-2" style="color:#f39c12"><i class="bi bi-exclamation-triangle me-1"></i>チームの課題</div>
                                <p class="small mb-0" style="line-height:1.8"><?= nl2br(h($team['team_challenges'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- このチームに合う人 -->
            <?php if ($team['ideal_recruit']): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-left:4px solid #8e44ad">
                    <i class="bi bi-person-plus me-2" style="color:#8e44ad"></i>このチームに合う人
                </div>
                <div class="card-body">
                    <p class="mb-0" style="line-height:1.9"><?= nl2br(h($team['ideal_recruit'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 右カラム: 分析 -->
        <div class="col-lg-5">

            <!-- SF ドメイン集計 -->
            <?php if (array_sum($domainTotals) > 0): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-top:3px solid #f39c12">
                    <i class="bi bi-lightning-fill me-2" style="color:#f39c12"></i>SF ドメイン分布
                </div>
                <div class="card-body">
                    <canvas id="domainChart" height="200"></canvas>
                </div>
            </div>

            <!-- SF 人気資質 -->
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-trophy me-2 text-warning"></i>チーム内トップ資質</div>
                <div class="card-body p-0">
                    <?php foreach (array_slice($top5Counts, 0, 8, true) as $key => $cnt): ?>
                    <?php $def = $sfDefs[$key] ?? ['ja' => $key, 'domain' => '']; ?>
                    <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-medium"><?= h($def['ja']) ?></span>
                                <span class="text-muted small"><?= $cnt ?>名</span>
                            </div>
                            <div class="score-bar">
                                <div class="score-fill" data-width="<?= min(100, $cnt / count($members) * 100) ?>" style="width:0;background:linear-gradient(90deg,#f39c12,#e67e22)"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- SPI チーム平均 -->
            <?php if (!empty($radarData) && $spiCount > 0): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-top:3px solid #27ae60">
                    <i class="bi bi-activity me-2" style="color:#27ae60"></i>SPI 職場適応性（チーム平均）
                </div>
                <div class="card-body">
                    <div style="max-width:300px;margin:0 auto 16px">
                        <canvas id="teamSpiRadar"></canvas>
                    </div>
                    <p class="text-muted small text-center mb-0">n=<?= $spiCount ?>名の平均値</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($team['strengths_analysis']): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-bar-chart me-2"></i>SF総合分析</div>
                <div class="card-body">
                    <p class="small mb-0" style="line-height:1.9"><?= nl2br(h($team['strengths_analysis'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php
$domainJson  = json_encode(array_keys($domainTotals), JSON_UNESCAPED_UNICODE);
$domainVals  = json_encode(array_values($domainTotals));
$radarJson   = json_encode($radarLabels, JSON_UNESCAPED_UNICODE);
$radarVals   = json_encode($radarData);

$inlineJs .= <<<JS

// スコアバーアニメーション
setTimeout(() => {
    document.querySelectorAll('.score-fill').forEach(el => {
        el.style.width = (el.dataset.width || '0') + '%';
    });
}, 200);

// ドメインドーナツチャート
(function() {
    const c = document.getElementById('domainChart');
    if (!c) return;
    new Chart(c, {
        type: 'doughnut',
        data: {
            labels: {$domainJson},
            datasets: [{ data: {$domainVals}, backgroundColor: ['#e65100','#1b5e20','#0d47a1','#4a148c'], borderWidth: 0 }]
        },
        options: { plugins: { legend: { position: 'right', labels: { font: { size: 12 } } } }, cutout: '60%' }
    });
})();

// チームSPIレーダー
(function() {
    const c = document.getElementById('teamSpiRadar');
    if (!c || !{$radarJson}.length) return;
    new Chart(c, {
        type: 'radar',
        data: {
            labels: {$radarJson},
            datasets: [{ data: {$radarVals}, backgroundColor: 'rgba(39,174,96,.2)', borderColor: '#27ae60', borderWidth: 2 }]
        },
        options: {
            scales: { r: { min: 0, max: 10, ticks: { stepSize: 2, font: { size: 9 } }, pointLabels: { font: { size: 9 } } } },
            plugins: { legend: { display: false } }
        }
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
