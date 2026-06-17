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

$pageTitle = 'ストレングスファインダー分析';

$db      = getDB();
$teams   = getAllTeams($cid);
$sfDefs  = getStrengthsThemeDefinitions();

// 絞り込み
$filterTeam = isset($_GET['team']) ? (int)$_GET['team'] : 0;
$filterDomain = $_GET['domain'] ?? '';

// 全SF取得
$sql = 'SELECT e.id, e.name, e.job_title, e.department, t.name AS team_name, t.id AS team_id, sf.*
        FROM employees e
        JOIN strengths_finder sf ON e.id = sf.employee_id
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
$sfList = $stmt->fetchAll();

// 資質ごとの出現数（トップ5での）
$themeCount   = [];
$domainTotals = ['実行力' => 0, '影響力' => 0, '人間関係力' => 0, '戦略的思考力' => 0];
foreach ($sfList as $sf) {
    foreach ($sfDefs as $key => $def) {
        if (isset($sf[$key]) && $sf[$key] !== null && (int)$sf[$key] <= 5) {
            $themeCount[$key]              = ($themeCount[$key] ?? 0) + 1;
            $domainTotals[$def['domain']]  = ($domainTotals[$def['domain']] ?? 0) + 1;
        }
    }
}
arsort($themeCount);

// ドメイン別の色
$domainColors = [
    '実行力'       => '#e65100',
    '影響力'       => '#1b5e20',
    '人間関係力'   => '#0d47a1',
    '戦略的思考力' => '#4a148c',
];

$inlineJs = 'window.BMS_BASE_PATH = "' . BASE_PATH . '";';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-lightning-fill me-2"></i>ストレングスファインダー分析</h1>
                <p><?= count($sfList) ?>名のデータを分析中</p>
            </div>
        </div>
    </div>

    <!-- フィルター -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <select class="form-select form-select-sm" onchange="location.href='strengths.php?team='+this.value">
                        <option value="0">すべてのチーム</option>
                        <?php foreach ($teams as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filterTeam === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($domainColors as $domain => $color): ?>
                        <button class="btn btn-sm <?= $filterDomain === $domain ? 'text-white' : 'btn-outline-secondary' ?>"
                                style="<?= $filterDomain === $domain ? "background:$color;border-color:$color" : '' ?>"
                                onclick="filterDomain('<?= $domain ?>')">
                            <?= $domain ?>
                        </button>
                        <?php endforeach; ?>
                        <?php if ($filterDomain): ?>
                        <a href="strengths.php<?= $filterTeam ? '?team='.$filterTeam : '' ?>" class="btn btn-sm btn-secondary">
                            クリア
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- ドメイン分布 -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-pie-chart me-2"></i>ドメイン分布（トップ5集計）</div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <div style="max-width:320px;width:100%">
                        <canvas id="domainChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 資質ランキング -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-trophy me-2 text-warning"></i>資質出現数ランキング（トップ5内）</div>
                <div class="card-body">
                    <?php $rank = 1; foreach (array_slice($themeCount, 0, 12, true) as $key => $cnt): ?>
                    <?php
                        $def   = $sfDefs[$key];
                        $pct   = count($sfList) > 0 ? round($cnt / count($sfList) * 100) : 0;
                        $color = $domainColors[$def['domain']] ?? '#3498db';
                    ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="text-muted" style="width:20px;text-align:right;font-size:12px"><?= $rank ?></div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small" data-bs-toggle="tooltip" title="<?= h($def['desc']) ?>">
                                    <?= h($def['ja']) ?>
                                    <span class="badge" style="background:<?= $color ?>20;color:<?= $color ?>;font-size:10px"><?= h($def['domain']) ?></span>
                                </span>
                                <span class="text-muted small"><?= $cnt ?>名 (<?= $pct ?>%)</span>
                            </div>
                            <div class="score-bar">
                                <div class="score-fill" data-width="<?= $pct ?>" style="width:0;background:<?= $color ?>"></div>
                            </div>
                        </div>
                    </div>
                    <?php $rank++; endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 社員別SF一覧 -->
    <div class="card">
        <div class="card-header"><i class="bi bi-table me-2"></i>社員別 トップ5資質</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:120px">社員名</th>
                            <th>1位</th><th>2位</th><th>3位</th><th>4位</th><th>5位</th>
                            <th>分析</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sfList as $sf): ?>
                        <?php $top5 = getTop5Strengths($sf); ?>
                        <tr class="<?= $filterDomain ? 'sf-row' : '' ?>"
                            <?php if ($filterDomain):
                                // ドメインフィルター
                                $hasMatch = false;
                                foreach ($top5 as $key => $score) {
                                    if (($sfDefs[$key]['domain'] ?? '') === $filterDomain) { $hasMatch = true; break; }
                                }
                                if (!$hasMatch) echo ' style="display:none"';
                            endif; ?>>
                            <td>
                                <a href="employee.php?id=<?= $sf['id'] ?>" class="text-decoration-none fw-medium">
                                    <?= h($sf['name']) ?>
                                </a>
                                <?php if ($sf['team_name']): ?>
                                <div class="text-muted" style="font-size:11px"><?= h($sf['team_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php
                            $rankNum = 1;
                            foreach ($top5 as $key => $score):
                                $def    = $sfDefs[$key] ?? ['ja' => $key, 'domain' => ''];
                                $color  = $domainColors[$def['domain']] ?? '#999';
                            ?>
                            <td>
                                <span class="badge px-2 py-1" style="background:<?= $color ?>20;color:<?= $color ?>;font-size:11px"
                                      data-bs-toggle="tooltip" title="<?= h($def['domain']) ?>: <?= h($def['desc']) ?>">
                                    <?= h($def['ja']) ?>
                                </span>
                            </td>
                            <?php $rankNum++; endforeach; ?>
                            <?php for ($i = $rankNum; $i <= 5; $i++): ?>
                            <td>-</td>
                            <?php endfor; ?>
                            <td style="max-width:200px">
                                <?php if ($sf['analysis']): ?>
                                <span class="small text-muted d-block text-truncate" style="max-width:200px"
                                      data-bs-toggle="tooltip" title="<?= h($sf['analysis']) ?>">
                                    <?= h(mb_substr($sf['analysis'], 0, 60)) ?>...
                                </span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$domainLabels = json_encode(array_keys($domainTotals), JSON_UNESCAPED_UNICODE);
$domainVals   = json_encode(array_values($domainTotals));
$domainBgColors = json_encode(array_values($domainColors));
$teamParam    = $filterTeam ? '?team=' . $filterTeam : '?';

$inlineJs .= <<<JS

// スコアバー
setTimeout(() => { document.querySelectorAll('.score-fill').forEach(el => el.style.width = (el.dataset.width||'0')+'%'); }, 200);

// ドメインドーナツ
new Chart(document.getElementById('domainChart'), {
    type: 'doughnut',
    data: {
        labels: {$domainLabels},
        datasets: [{ data: {$domainVals}, backgroundColor: {$domainBgColors}, borderWidth: 0 }]
    },
    options: {
        plugins: { legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 12 } } },
        cutout: '55%'
    }
});

function filterDomain(domain) {
    const base = 'strengths.php{$teamParam}';
    location.href = base + (base.includes('?') ? '&' : '?') + 'domain=' + encodeURIComponent(domain);
}
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
