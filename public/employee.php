<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect(BASE_PATH . '/public/employees.php'); }

// 権限チェック: 管理者は全員、一般社員は自分のみ閲覧可
$myEmpId = $_SESSION['employee_id'] ?? 0;
if (!isAdmin() && $id !== $myEmpId) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>アクセス拒否</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h1>403 アクセス権限がありません</h1>';
    echo '<p>自分の社員情報のみ閲覧できます。</p>';
    echo '<a href="' . BASE_PATH . '/public/my_results.php">マイ診断結果へ</a>';
    echo '</body></html>';
    exit;
}

$employee = getEmployee($id, $cid);
if (!$employee) { redirect(BASE_PATH . '/public/employees.php'); }

$career  = getEmployeeCareer($id);
$sf      = getStrengthsFinder($id);
$spi     = getSpiResult($id);
$sfDefs  = getStrengthsThemeDefinitions();
$spiDims = getSpiDimensions();

$pageTitle = h($employee['name']);

// ストレングスファインダートップ5
$top5 = $sf ? getTop5Strengths($sf) : [];

// SPI職場適応性スコア
$workplaceSpi = [];
if ($spi) {
    foreach ($spiDims['workplace']['items'] as $key => $label) {
        if (isset($spi[$key]) && $spi[$key] !== null) {
            $workplaceSpi[$key] = ['label' => $label, 'score' => (int)$spi[$key]];
        }
    }
}

// レーダーチャート用データ
$radarLabels = [];
$radarData   = [];
foreach ($workplaceSpi as $item) {
    $radarLabels[] = $item['label'];
    $radarData[]   = $item['score'];
}
$inlineJs = 'window.BMS_BASE_PATH = "' . BASE_PATH . '";';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- パンくず -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/public/index.php">ホーム</a></li>
            <li class="breadcrumb-item"><a href="<?= BASE_PATH ?>/public/employees.php">社員一覧</a></li>
            <li class="breadcrumb-item active"><?= h($employee['name']) ?></li>
        </ol>
    </nav>

    <!-- ヘッダーカード -->
    <div class="card mb-4">
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-4 flex-wrap">
                <!-- アバター -->
                <div class="avatar" style="width:100px;height:100px;border-radius:50%;background:linear-gradient(135deg,#059669,#34d399);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:#fff;flex-shrink:0">
                    <?php if ($employee['photo_path'] && file_exists(UPLOAD_DIR . $employee['photo_path'])): ?>
                        <img src="<?= h(UPLOAD_URL . $employee['photo_path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <?php else: ?>
                        <?= h(mb_substr($employee['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>

                <!-- 基本情報 -->
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h2 class="mb-0"><?= h($employee['name']) ?></h2>
                        <?php if ($employee['name_kana']): ?>
                        <span class="text-muted"><?= h($employee['name_kana']) ?></span>
                        <?php endif; ?>
                        <?php if (!$employee['is_active']): ?>
                        <span class="badge bg-secondary">退職済み</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted mt-1"><?= h($employee['job_title'] ?? '') ?></div>
                    <div class="d-flex flex-wrap gap-3 mt-2">
                        <?php if ($employee['team_name']): ?>
                        <span><i class="bi bi-diagram-3 me-1 text-primary"></i>
                            <a href="team.php?id=<?= $employee['team_id'] ?>" class="text-decoration-none"><?= h($employee['team_name']) ?></a>
                        </span>
                        <?php endif; ?>
                        <?php if ($employee['hire_date']): ?>
                        <span><i class="bi bi-calendar3 me-1 text-muted"></i><?= formatDate($employee['hire_date'], 'Y年n月') ?>入社
                            <span class="text-info ms-1">(<?= getYearsOfService($employee['hire_date']) ?>)</span>
                        </span>
                        <?php endif; ?>
                        <?php if ($employee['email']): ?>
                        <span><i class="bi bi-envelope me-1 text-muted"></i><?= h($employee['email']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 操作ボタン -->
                <div class="d-flex gap-2">
                    <?php if (isLoggedIn() && !isEmployee()): ?>
                    <a href="<?= BASE_PATH ?>/admin/employee_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil me-1"></i>編集
                    </a>
                    <?php elseif (isEmployee() && getSessionEmployeeId() === $id): ?>
                    <a href="<?= BASE_PATH ?>/admin/employee_form.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-pencil me-1"></i>編集
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 左カラム -->
        <div class="col-lg-7">

            <!-- 自己紹介 -->
            <?php if ($employee['bio']): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-person me-2"></i>プロフィール</div>
                <div class="card-body">
                    <p class="mb-0" style="line-height:1.9"><?= nl2br(h($employee['bio'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- キャリア -->
            <?php if (!empty($career)): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-briefcase me-2"></i>キャリア履歴</div>
                <div class="card-body p-0">
                    <div class="timeline p-3">
                        <?php foreach ($career as $c): ?>
                        <div class="d-flex gap-3 mb-4">
                            <div class="text-center" style="min-width:48px">
                                <div class="rounded-circle d-inline-flex align-items-center justify-content-center <?= $c['is_internal'] ? 'bg-primary' : 'bg-secondary' ?> text-white" style="width:36px;height:36px">
                                    <i class="bi bi-<?= $c['is_internal'] ? 'building' : 'globe' ?>" style="font-size:14px"></i>
                                </div>
                                <?php if (!$c['is_current']): ?>
                                <div class="border-start mx-auto mt-2" style="width:1px;height:24px;border-color:#dee2e6!important"></div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between flex-wrap gap-1">
                                    <div>
                                        <span class="fw-semibold"><?= h($c['position'] ?? '') ?></span>
                                        <?php if ($c['is_internal']): ?>
                                        <span class="badge bg-primary-subtle text-primary ms-2" style="font-size:11px">社内</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-2" style="font-size:11px"><?= h($c['company'] ?? '前職') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= $c['start_year'] ?>年<?= $c['start_month'] ?>月 〜
                                        <?= $c['is_current'] ? '現在' : ($c['end_year'] ? $c['end_year'] . '年' . $c['end_month'] . '月' : '') ?>
                                    </div>
                                </div>
                                <?php if ($c['description']): ?>
                                <p class="text-muted small mt-1 mb-0"><?= nl2br(h($c['description'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 得意・苦手 -->
            <?php if ($employee['strengths_text'] || $employee['weaknesses_text']): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-sliders me-2"></i>傾向</div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($employee['strengths_text']): ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded" style="background:#f0fdf4;border-left:4px solid #27ae60">
                                <div class="fw-semibold mb-2" style="color:#27ae60"><i class="bi bi-hand-thumbs-up me-1"></i>得意なこと</div>
                                <p class="mb-0 small" style="line-height:1.8"><?= nl2br(h($employee['strengths_text'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($employee['weaknesses_text']): ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded" style="background:#fff8f0;border-left:4px solid #f39c12">
                                <div class="fw-semibold mb-2" style="color:#f39c12"><i class="bi bi-hand-thumbs-down me-1"></i>苦手なこと</div>
                                <p class="mb-0 small" style="line-height:1.8"><?= nl2br(h($employee['weaknesses_text'])) ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- この人の活かし方 -->
            <?php if ($employee['how_to_utilize']): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-left:4px solid #8e44ad">
                    <i class="bi bi-star me-2" style="color:#8e44ad"></i><?= h($employee['name']) ?>さんの活かし方
                </div>
                <div class="card-body">
                    <p class="mb-0" style="line-height:1.9"><?= nl2br(h($employee['how_to_utilize'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 右カラム -->
        <div class="col-lg-5">

            <!-- ストレングスファインダー -->
            <?php if ($sf): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-top:3px solid #f39c12">
                    <i class="bi bi-lightning-fill me-2" style="color:#f39c12"></i>ストレングスファインダー
                </div>
                <div class="card-body">
                    <!-- トップ5 -->
                    <div class="section-title">トップ5資質</div>
                    <?php
                    $rank = 1;
                    foreach ($top5 as $key => $score):
                        $def = $sfDefs[$key] ?? ['ja' => $key, 'domain' => '', 'desc' => ''];
                        $badgeClass = match($def['domain']) {
                            '実行力'       => 'badge-executing',
                            '影響力'       => 'badge-influencing',
                            '人間関係力'   => 'badge-relationship',
                            '戦略的思考力' => 'badge-strategic',
                            default        => 'badge-executing',
                        };
                    ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="strength-rank-badge rank-<?= $rank ?>">
                            <?= $rank ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold" data-bs-toggle="tooltip" title="<?= h($def['desc']) ?>">
                                    <?= h($def['ja']) ?>
                                </span>
                                <span class="badge-domain <?= $badgeClass ?>"><?= h($def['domain']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php $rank++; endforeach; ?>

                    <?php if ($sf['analysis']): ?>
                    <div class="mt-3 p-3 rounded" style="background:#fffbf0;border-left:3px solid #f39c12">
                        <div class="info-label mb-1">分析コメント</div>
                        <p class="small mb-0" style="line-height:1.8"><?= nl2br(h($sf['analysis'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- SPI -->
            <?php if ($spi): ?>
            <div class="card mb-4">
                <div class="card-header" style="border-top:3px solid #27ae60">
                    <i class="bi bi-activity me-2" style="color:#27ae60"></i>SPI分析
                </div>
                <div class="card-body">
                    <!-- 職場適応性レーダーチャート -->
                    <?php if (!empty($radarLabels)): ?>
                    <div class="section-title">職場適応性</div>
                    <div style="max-width:300px;margin:0 auto 16px">
                        <canvas id="spiRadar"></canvas>
                    </div>
                    <?php endif; ?>

                    <!-- 行動的側面 -->
                    <div class="section-title mt-3">行動・意欲的側面</div>
                    <?php
                    $behavioralItems = array_merge(
                        $spiDims['behavioral']['items'],
                        $spiDims['motivational']['items']
                    );
                    foreach ($behavioralItems as $key => $label):
                        if (!isset($spi[$key]) || $spi[$key] === null) continue;
                        $score = (int)$spi[$key];
                        $pct   = min(100, $score * 10);
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= h($label) ?></span>
                            <span class="text-muted"><?= $score ?>/10</span>
                        </div>
                        <div class="score-bar">
                            <div class="score-fill" data-width="<?= $pct ?>" style="width:0"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($spi['analysis']): ?>
                    <div class="mt-3 p-3 rounded" style="background:#f0fdf4;border-left:3px solid #27ae60">
                        <div class="info-label mb-1">分析コメント</div>
                        <p class="small mb-0" style="line-height:1.8"><?= nl2br(h($spi['analysis'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php
$radarJson  = json_encode($radarLabels, JSON_UNESCAPED_UNICODE);
$radarVals  = json_encode($radarData);
$inlineJs  .= <<<JS

// スコアバーアニメーション
setTimeout(() => {
    document.querySelectorAll('.score-fill').forEach(el => {
        el.style.width = (el.dataset.width || '0') + '%';
    });
}, 200);

// SPI レーダーチャート
(function() {
    const canvas = document.getElementById('spiRadar');
    if (!canvas || !{$radarJson}.length) return;
    new Chart(canvas, {
        type: 'radar',
        data: {
            labels: {$radarJson},
            datasets: [{
                data: {$radarVals},
                backgroundColor: 'rgba(39,174,96,.2)',
                borderColor: '#27ae60',
                borderWidth: 2,
                pointBackgroundColor: '#27ae60',
            }]
        },
        options: {
            scales: { r: { min: 0, max: 10, ticks: { stepSize: 2, font: { size: 10 } }, pointLabels: { font: { size: 10 } } } },
            plugins: { legend: { display: false } }
        }
    });
})();
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
