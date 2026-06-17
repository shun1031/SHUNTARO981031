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

$pageTitle = 'SPI分析';

$db     = getDB();
$teams  = getAllTeams($cid);
$dims   = getSpiDimensions();

$filterTeam = isset($_GET['team']) ? (int)$_GET['team'] : 0;

// SPI取得
$sql = 'SELECT e.id, e.name, e.job_title, e.department, t.name AS team_name, t.id AS team_id, sp.*
        FROM employees e
        JOIN spi_results sp ON e.id = sp.employee_id
        LEFT JOIN team_members tm ON e.id = tm.employee_id
        LEFT JOIN teams t ON tm.team_id = t.id
        WHERE e.is_active = 1';
$params = [];
if ($cid) {
    $sql .= ' AND e.company_id = ?';
    $params[] = $cid;
}
if ($filterTeam > 0) {
    $sql .= ' AND tm.team_id = ?';
    $params[] = $filterTeam;
}
$sql .= ' ORDER BY e.employee_number, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$spiList = $stmt->fetchAll();

// 全体平均スコア
$avgScores = [];
$cnt = count($spiList);
if ($cnt > 0) {
    foreach ($dims as $category) {
        foreach (array_keys($category['items']) as $key) {
            $sum = 0;
            $n   = 0;
            foreach ($spiList as $sp) {
                if (isset($sp[$key]) && $sp[$key] !== null) {
                    $sum += $sp[$key];
                    $n++;
                }
            }
            $avgScores[$key] = $n > 0 ? round($sum / $n, 1) : null;
        }
    }
}

// 職場適応性レーダー用データ（全体平均）
$workKeys   = array_keys($dims['workplace']['items']);
$workLabels = array_values($dims['workplace']['items']);
$workAvgs   = array_map(fn($k) => $avgScores[$k] ?? 0, $workKeys);

$inlineJs = 'window.BMS_BASE_PATH = "' . BASE_PATH . '";';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-activity me-2"></i>SPI分析</h1>
            <p><?= count($spiList) ?>名のデータを分析中</p>
        </div>
    </div>

    <!-- フィルター -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div class="row g-2">
                <div class="col-md-4">
                    <select class="form-select form-select-sm" onchange="location.href='spi.php?team='+this.value">
                        <option value="0">すべてのチーム</option>
                        <?php foreach ($teams as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filterTeam === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- チャートエリア -->
    <div class="row g-4 mb-4">
        <!-- 職場適応性レーダー（全体平均） -->
        <?php if ($cnt > 0): ?>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-radar me-2"></i>職場適応性 全体平均 (n=<?= $cnt ?>)</div>
                <div class="card-body">
                    <canvas id="avgRadar" style="max-width:350px;max-height:350px;margin:0 auto"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 各側面の平均スコア -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-bar-chart-horizontal me-2"></i>側面別 平均スコア</div>
                <div class="card-body">
                    <?php foreach ($dims as $catKey => $category): ?>
                    <div class="section-title"><?= h($category['label']) ?></div>
                    <?php foreach ($category['items'] as $key => $label): ?>
                    <?php
                        $avg = $avgScores[$key] ?? null;
                        $pct = $avg !== null ? min(100, $avg * 10) : 0;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= h($label) ?></span>
                            <span class="text-muted"><?= $avg !== null ? $avg . '/10' : '-' ?></span>
                        </div>
                        <div class="score-bar">
                            <div class="score-fill" data-width="<?= $pct ?>" style="width:0;background:linear-gradient(90deg,#27ae60,#16a085)"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 社員別SPI一覧 -->
    <div class="card">
        <div class="card-header"><i class="bi bi-table me-2"></i>社員別 職場適応性スコア</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:110px">社員名</th>
                            <?php foreach ($dims['workplace']['items'] as $label): ?>
                            <th class="text-center" style="min-width:60px;font-size:11px"><?= h($label) ?></th>
                            <?php endforeach; ?>
                            <th>分析</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($spiList as $sp): ?>
                        <tr>
                            <td>
                                <a href="employee.php?id=<?= $sp['id'] ?>" class="fw-medium text-decoration-none"><?= h($sp['name']) ?></a>
                                <?php if ($sp['team_name']): ?>
                                <div class="text-muted" style="font-size:11px"><?= h($sp['team_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($workKeys as $key): ?>
                            <?php
                                $score = $sp[$key] ?? null;
                                $bg    = $score !== null ? (
                                    $score >= 8 ? '#d4edda' : ($score >= 6 ? '#fff3cd' : ($score !== null ? '#f8d7da' : 'transparent'))
                                ) : 'transparent';
                            ?>
                            <td class="text-center" style="background:<?= $bg ?>">
                                <?= $score !== null ? $score : '-' ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="max-width:200px">
                                <?php if ($sp['analysis']): ?>
                                <span class="small text-muted" data-bs-toggle="tooltip" title="<?= h($sp['analysis']) ?>">
                                    <?= h(mb_substr($sp['analysis'], 0, 50)) ?>...
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <!-- 平均行 -->
                        <?php if ($cnt > 1): ?>
                        <tr class="table-secondary fw-semibold">
                            <td>全体平均</td>
                            <?php foreach ($workKeys as $key): ?>
                            <td class="text-center"><?= $avgScores[$key] !== null ? $avgScores[$key] : '-' ?></td>
                            <?php endforeach; ?>
                            <td></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-2 text-muted small">
                <span class="badge" style="background:#d4edda;color:#333">8以上</span> 高い
                <span class="badge" style="background:#fff3cd;color:#333">6-7</span> 中程度
                <span class="badge" style="background:#f8d7da;color:#333">5以下</span> 低い
            </div>
        </div>
    </div>
</div>

<?php
$radarLabelsJson = json_encode($workLabels, JSON_UNESCAPED_UNICODE);
$radarDataJson   = json_encode($workAvgs);

$inlineJs .= <<<JS

setTimeout(() => { document.querySelectorAll('.score-fill').forEach(el => el.style.width = (el.dataset.width||'0')+'%'); }, 200);

(function() {
    const c = document.getElementById('avgRadar');
    if (!c) return;
    new Chart(c, {
        type: 'radar',
        data: {
            labels: {$radarLabelsJson},
            datasets: [{ data: {$radarDataJson}, backgroundColor: 'rgba(39,174,96,.25)', borderColor: '#27ae60', borderWidth: 2, pointBackgroundColor: '#27ae60' }]
        },
        options: {
            scales: { r: { min: 0, max: 10, ticks: { stepSize: 2, font: { size: 9 } }, pointLabels: { font: { size: 10 } } } },
            plugins: { legend: { display: false } }
        }
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
