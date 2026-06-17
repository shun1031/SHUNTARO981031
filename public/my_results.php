<?php
/**
 * マイ診断結果ページ v2
 * 社員が自分のSPI・SF結果をビジュアルに確認できる
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();
$myEmpId = $_SESSION['employee_id'] ?? 0;

if (!$myEmpId) {
    $_SESSION['flash'] = '社員情報が登録されていません';
    header('Location: index.php');
    exit;
}

$emp = getEmployee($myEmpId, $cid);
$sf  = getStrengthsFinder($myEmpId);
$spi = getSpiResult($myEmpId);
$sfDefs  = getStrengthsThemeDefinitions();
$spiDims = getSpiDimensions();
$status  = getAssessmentStatus($myEmpId);

$top5 = $sf ? getTop5Strengths($sf) : [];
$domainSummary = $sf ? getDomainSummary($sf) : [];
$domainColors = ['実行力' => '#e65100', '影響力' => '#1b5e20', '人間関係力' => '#0d47a1', '戦略的思考力' => '#4a148c'];
$domainBgs = ['実行力' => '#fff3e0', '影響力' => '#e8f5e9', '人間関係力' => '#e3f2fd', '戦略的思考力' => '#f3e5f5'];

// SPI全次元
$allSpiScores = [];
if ($spi) {
    foreach ($spiDims as $catKey => $cat) {
        foreach ($cat['items'] as $key => $label) {
            if (isset($spi[$key]) && $spi[$key] !== null) {
                $allSpiScores[] = ['key'=>$key, 'label'=>$label, 'score'=>(int)$spi[$key], 'category'=>$cat['label']];
            }
        }
    }
}

// SPI職場適応性
$workScores = [];
$workLabels = [];
if ($spi) {
    foreach ($spiDims['workplace']['items'] as $key => $label) {
        if (isset($spi[$key]) && $spi[$key] !== null) {
            $workLabels[] = $label;
            $workScores[] = (int)$spi[$key];
        }
    }
}

// SPI強み・弱みトップ3
$spiSorted = $allSpiScores;
usort($spiSorted, fn($a,$b) => $b['score'] - $a['score']);
$spiStrengths = array_slice($spiSorted, 0, 3);
$spiWeaknesses = array_slice(array_reverse($spiSorted), 0, 3);

$pageTitle = 'マイ診断結果';
$extraCss = ['assessment.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ===== ヒーロー ===== -->
<div class="result-hero" style="background:linear-gradient(135deg,#064e3b 0%,#047857 50%,#059669 100%)">
    <div style="font-size:3rem;margin-bottom:0.5rem"><i class="bi bi-person-badge"></i></div>
    <div class="result-title"><?= h($emp['name'] ?? '') ?> さんの診断結果</div>
    <p class="mb-0 opacity-75"><?= h($emp['job_title'] ?? '') ?> <?= $emp['department'] ? '/ ' . h($emp['department']) : '' ?></p>
</div>

<!-- ===== ステータスカード ===== -->
<div class="row mb-4 g-3">
    <div class="col-md-6">
        <div class="card h-100" style="border-left:4px solid <?= $status['spi_done'] ? '#27ae60' : '#f39c12' ?>">
            <div class="card-body d-flex justify-content-between align-items-center py-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-activity fs-4" style="color:<?= $status['spi_done'] ? '#27ae60' : '#f39c12' ?>"></i>
                        <strong>SPI性格検査</strong>
                    </div>
                    <?php if ($status['spi_done']): ?>
                    <span class="badge bg-success">受検済み</span>
                    <?php elseif ($status['spi_in_progress']): ?>
                    <span class="badge bg-warning text-dark">受検中</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">未受検</span>
                    <?php endif; ?>
                </div>
                <a href="spi_test.php" class="btn btn-sm <?= $status['spi_done'] ? 'btn-outline-success' : 'btn-success' ?>">
                    <i class="bi bi-<?= $status['spi_done'] ? 'arrow-repeat' : 'play-fill' ?> me-1"></i><?= $status['spi_done'] ? '再受検' : ($status['spi_in_progress'] ? '続きから' : '受検する') ?>
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100" style="border-left:4px solid <?= $status['sf_done'] ? '#27ae60' : '#f39c12' ?>">
            <div class="card-body d-flex justify-content-between align-items-center py-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-lightning-fill fs-4" style="color:<?= $status['sf_done'] ? '#e65100' : '#f39c12' ?>"></i>
                        <strong>ストレングスファインダー</strong>
                    </div>
                    <?php if ($status['sf_done']): ?>
                    <span class="badge bg-success">受検済み</span>
                    <?php elseif ($status['sf_in_progress']): ?>
                    <span class="badge bg-warning text-dark">受検中</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">未受検</span>
                    <?php endif; ?>
                </div>
                <a href="sf_test.php" class="btn btn-sm <?= $status['sf_done'] ? 'btn-outline-success' : 'btn-success' ?>">
                    <i class="bi bi-<?= $status['sf_done'] ? 'arrow-repeat' : 'play-fill' ?> me-1"></i><?= $status['sf_done'] ? '再受検' : ($status['sf_in_progress'] ? '続きから' : '受検する') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (!$spi && !$sf): ?>
<div class="text-center py-5">
    <div style="font-size:4rem;color:#dee2e6"><i class="bi bi-clipboard-pulse"></i></div>
    <h5 class="mt-3">まだ診断を受けていません</h5>
    <p class="text-muted">SPI性格検査やストレングスファインダー診断を受けて<br>自分の強みや特性を知りましょう。</p>
    <div class="d-flex justify-content-center gap-3 mt-3">
        <a href="spi_test.php" class="btn btn-lg btn-primary"><i class="bi bi-activity me-2"></i>SPI検査を受ける</a>
        <a href="sf_test.php" class="btn btn-lg btn-outline-warning"><i class="bi bi-lightning me-2"></i>SF診断を受ける</a>
    </div>
</div>
<?php endif; ?>

<!-- ========== SF結果 ========== -->
<?php if ($sf && $top5): ?>
<div id="sf-results" class="mb-5">
    <div class="d-flex align-items-center gap-2 mb-3 pb-2" style="border-bottom:3px solid #e65100">
        <i class="bi bi-lightning-fill fs-4" style="color:#e65100"></i>
        <h5 class="mb-0 fw-bold">ストレングスファインダー結果</h5>
    </div>

    <!-- Top5 テーブル形式 -->
    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-trophy me-2 text-warning"></i>あなたのトップ5</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px" class="text-center">順位</th>
                        <th style="width:140px">資質名</th>
                        <th style="width:100px">ドメイン</th>
                        <th>説明</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; foreach ($top5 as $key => $rank): $i++;
                        $def = $sfDefs[$key] ?? ['ja'=>$key, 'domain'=>'', 'desc'=>''];
                        $color = $domainColors[$def['domain']] ?? '#666';
                        $bg = $domainBgs[$def['domain']] ?? '#f8f9fa';
                    ?>
                    <tr style="background:<?= $bg ?>">
                        <td class="text-center">
                            <div style="width:36px;height:36px;border-radius:50%;background:<?= $color ?>;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1rem"><?= $rank ?></div>
                        </td>
                        <td><strong style="font-size:1.05rem"><?= h($def['ja']) ?></strong></td>
                        <td><span class="badge px-2 py-1" style="background:<?= $color ?>"><?= h($def['domain']) ?></span></td>
                        <td class="text-muted" style="font-size:0.88rem"><?= h($def['desc']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-pie-chart me-2"></i>ドメイン分布</div>
                <div class="card-body">
                    <canvas id="sfDomainChart" style="max-width:280px;max-height:280px;margin:0 auto"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <?php if (!empty($sf['analysis'])): ?>
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-robot me-2"></i>AI分析コメント</div>
                <div class="card-body" style="font-size:0.9rem;line-height:1.8"><?= nl2br(h($sf['analysis'])) ?></div>
            </div>
            <?php elseif (!empty($sf['top5_text'])): ?>
            <div class="card h-100">
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div class="text-center">
                        <div class="text-muted small mb-2">トップ5サマリー</div>
                        <div class="fw-bold" style="font-size:1.1rem"><?= h($sf['top5_text']) ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========== SPI結果 ========== -->
<?php if ($spi): ?>
<div id="spi-results" class="mb-5">
    <div class="d-flex align-items-center gap-2 mb-3 pb-2" style="border-bottom:3px solid #27ae60">
        <i class="bi bi-activity fs-4" style="color:#27ae60"></i>
        <h5 class="mb-0 fw-bold">SPI性格検査結果</h5>
    </div>

    <!-- 強み・弱み 比較テーブル -->
    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th colspan="3" class="text-success text-center" style="background:#d4edda"><i class="bi bi-hand-thumbs-up me-1"></i>あなたの強みトップ3</th>
                        <th style="width:1px;background:#dee2e6"></th>
                        <th colspan="3" class="text-danger text-center" style="background:#f8d7da"><i class="bi bi-arrow-up-circle me-1"></i>伸びしろトップ3</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width:50px">点数</th>
                        <th>項目</th>
                        <th style="width:80px">カテゴリ</th>
                        <th style="width:1px;background:#dee2e6"></th>
                        <th class="text-center" style="width:50px">点数</th>
                        <th>項目</th>
                        <th style="width:80px">カテゴリ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($r = 0; $r < 3; $r++):
                        $st = $spiStrengths[$r] ?? null;
                        $wk = $spiWeaknesses[$r] ?? null;
                    ?>
                    <tr>
                        <?php if ($st): ?>
                        <td class="text-center"><span class="badge bg-success fs-6"><?= $st['score'] ?></span></td>
                        <td class="fw-bold"><?= h($st['label']) ?></td>
                        <td class="small text-muted"><?= h($st['category']) ?></td>
                        <?php else: ?><td colspan="3">-</td><?php endif; ?>
                        <td style="width:1px;background:#dee2e6"></td>
                        <?php if ($wk): ?>
                        <td class="text-center"><span class="badge bg-danger fs-6"><?= $wk['score'] ?></span></td>
                        <td class="fw-bold"><?= h($wk['label']) ?></td>
                        <td class="small text-muted"><?= h($wk['category']) ?></td>
                        <?php else: ?><td colspan="3">-</td><?php endif; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- レーダーチャート -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-radar me-2"></i>職場適応性</div>
                <div class="card-body">
                    <canvas id="spiRadarChart" style="max-width:350px;max-height:350px;margin:0 auto"></canvas>
                </div>
            </div>
        </div>

        <!-- 全次元スコア テーブル形式 -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header fw-bold"><i class="bi bi-bar-chart me-2"></i>全次元スコア一覧</div>
                <div class="table-responsive" style="max-height:500px;overflow-y:auto">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light" style="position:sticky;top:0;z-index:1">
                            <tr>
                                <th style="width:100px">カテゴリ</th>
                                <th style="width:110px">項目</th>
                                <th>スコア</th>
                                <th style="width:50px" class="text-center">点数</th>
                                <th style="width:60px" class="text-center">判定</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $prevCat = ''; foreach ($allSpiScores as $s):
                                $pct = $s['score'] * 10;
                                $grad = $s['score'] >= 7 ? '#27ae60' : ($s['score'] >= 4 ? '#f39c12' : '#e74c3c');
                                $judge = $s['score'] >= 8 ? '高い' : ($s['score'] >= 6 ? 'やや高' : ($s['score'] >= 4 ? '標準' : ($s['score'] >= 2 ? 'やや低' : '低い')));
                                $judgeBg = $s['score'] >= 8 ? '#d4edda' : ($s['score'] >= 6 ? '#d1ecf1' : ($s['score'] >= 4 ? '#fff3cd' : '#f8d7da'));
                                $showCat = ($s['category'] !== $prevCat);
                                $prevCat = $s['category'];
                            ?>
                            <tr>
                                <td class="small text-muted"><?= $showCat ? h($s['category']) : '' ?></td>
                                <td class="fw-medium" style="font-size:0.9rem"><?= h($s['label']) ?></td>
                                <td>
                                    <div style="height:20px;background:#f0f2f5;border-radius:10px;overflow:hidden">
                                        <div style="width:<?= $pct ?>%;height:100%;background:<?= $grad ?>;border-radius:10px;transition:width 1s"></div>
                                    </div>
                                </td>
                                <td class="text-center fw-bold" style="font-size:1rem"><?= $s['score'] ?></td>
                                <td class="text-center"><span class="badge" style="background:<?= $judgeBg ?>;color:#333;font-size:0.7rem"><?= $judge ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($spi['analysis'])): ?>
    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-robot me-2"></i>AI分析コメント</div>
        <div class="card-body" style="font-size:0.9rem;line-height:1.8"><?= nl2br(h($spi['analysis'])) ?></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Chart.jsはfooter.phpで読み込まれるので、inlineJsで描画
$inlineJs = '';
if ($sf && $top5) {
    $labelsJson = json_encode(array_keys($domainSummary), JSON_UNESCAPED_UNICODE);
    $dataJson = json_encode(array_values($domainSummary));
    $inlineJs .= "
new Chart(document.getElementById('sfDomainChart'), {
    type:'doughnut',
    data:{ labels:{$labelsJson}, datasets:[{ data:{$dataJson}, backgroundColor:['#e65100','#1b5e20','#0d47a1','#4a148c'], borderWidth:0 }] },
    options:{ responsive:true, cutout:'60%', plugins:{ legend:{ position:'bottom', labels:{ font:{size:12}, padding:14 } } } }
});
";
}
if ($spi && $workScores) {
    $wLabelsJson = json_encode($workLabels, JSON_UNESCAPED_UNICODE);
    $wScoresJson = json_encode($workScores);
    $inlineJs .= "
new Chart(document.getElementById('spiRadarChart'), {
    type:'radar',
    data:{ labels:{$wLabelsJson}, datasets:[{ label:'あなたのスコア', data:{$wScoresJson}, fill:true, backgroundColor:'rgba(39,174,96,0.15)', borderColor:'#27ae60', pointBackgroundColor:'#27ae60', pointRadius:5, borderWidth:2 }] },
    options:{ responsive:true, scales:{ r:{ beginAtZero:true, max:10, ticks:{stepSize:2,font:{size:10}}, pointLabels:{font:{size:11}} } }, plugins:{legend:{display:false}} }
});
";
}
require_once __DIR__ . '/../includes/footer.php';
?>
