<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eval_functions.php';

requireAnyLogin();
$cid = getCompanyId();
$db  = getDB();

$period = getActivePeriod($cid);
$myEmpId = $_SESSION['employee_id'] ?? 0;

$mySheet = null;
$subSheets = [];
if ($period && $myEmpId) {
    $mySheet   = getEvalSheetByEmployee($period['id'], $myEmpId, $cid);
    $subSheets = getSubordinateSheets($period['id'], $myEmpId);
}

// ステッパー定義
$steps = [
    'self_eval'    => ['label' => '自己評価', 'icon' => 'bi-pencil-square'],
    'primary_eval' => ['label' => '1次評価', 'icon' => 'bi-person-check'],
    'adjustment'   => ['label' => '調整', 'icon' => 'bi-sliders'],
    'feedback'     => ['label' => 'フィードバック', 'icon' => 'bi-chat-left-dots'],
    'closed'       => ['label' => '完了', 'icon' => 'bi-check-circle'],
];

function stepIndex(string $status): int {
    $map = ['draft'=>0,'open'=>0,'self_eval'=>0,'self_submitted'=>1,'primary_eval'=>1,'primary_submitted'=>2,'adjustment'=>2,'adjusted'=>3,'feedback'=>3,'feedback_done'=>4,'closed'=>4];
    return $map[$status] ?? 0;
}

$pageTitle = '評価ダッシュボード';
$extraCss  = ['assets/css/eval.css'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-speedometer2 me-2"></i>評価ダッシュボード</h1>
            <p><?= $period ? h($period['name']) : '現在進行中の評価期間はありません' ?></p>
        </div>
    </div>

    <?php if ($period): ?>
    <!-- ステッパー -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="eval-stepper">
                <?php $currentIdx = stepIndex($period['status']); $i = 0; ?>
                <?php foreach ($steps as $key => $step): ?>
                <div class="eval-step <?= $i < $currentIdx ? 'completed' : ($i === $currentIdx ? 'active' : '') ?>">
                    <div class="step-circle"><i class="bi <?= $step['icon'] ?>"></i></div>
                    <div class="step-label"><?= $step['label'] ?></div>
                </div>
                <?php if ($i < count($steps) - 1): ?><div class="step-line <?= $i < $currentIdx ? 'completed' : '' ?>"></div><?php endif; ?>
                <?php $i++; endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- 自分の評価 -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person me-2"></i>自分の評価</div>
                <div class="card-body">
                    <?php if ($mySheet): ?>
                        <?php $st = getEvalStatusLabel($mySheet['status']); ?>
                        <div class="d-flex align-items-center mb-3">
                            <span class="badge <?= $st['class'] ?> me-2"><?= $st['label'] ?></span>
                            <span class="text-muted small">最終更新: <?= $mySheet['updated_at'] ? date('Y/m/d H:i', strtotime($mySheet['updated_at'])) : '-' ?></span>
                        </div>
                        <div class="d-grid gap-2">
                            <a href="eval_my.php?sheet_id=<?= $mySheet['id'] ?>" class="btn btn-primary">
                                <i class="bi bi-pencil-square me-1"></i>自己評価を入力する
                            </a>
                            <a href="eval_history.php" class="btn btn-outline-secondary">
                                <i class="bi bi-clock-history me-1"></i>過去の評価履歴
                            </a>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">評価シートが未作成です。管理者にお問い合わせください。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 部下の評価 -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-people me-2"></i>部下の評価</div>
                <div class="card-body">
                    <?php if (!empty($subSheets)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead><tr><th>社員</th><th>部署</th><th>ステータス</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($subSheets as $s):
                                    $sl = getEvalStatusLabel($s['status']); ?>
                                <tr>
                                    <td><?= h($s['employee_name']) ?></td>
                                    <td class="text-muted small"><?= h($s['department'] ?? '') ?></td>
                                    <td><span class="badge <?= $sl['class'] ?> badge-sm"><?= $sl['label'] ?></span></td>
                                    <td><a href="eval_primary.php?sheet_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">評価</a></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">評価対象の部下はいません。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 褒めポイント -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-hand-thumbs-up me-2"></i>最近の褒めポイント</span>
                    <a href="praise.php" class="btn btn-sm btn-outline-primary">すべて見る</a>
                </div>
                <div class="card-body p-2">
                    <?php $praises = getRecentPraise($cid, 5);
                    if (empty($praises)): ?>
                        <p class="text-muted text-center py-3">まだ褒めポイントがありません</p>
                    <?php else:
                        foreach ($praises as $p): ?>
                        <div class="d-flex align-items-start gap-2 p-2 border-bottom">
                            <i class="bi bi-star-fill text-warning mt-1"></i>
                            <div>
                                <div class="small"><strong><?= h($p['author_name']) ?></strong> → <?= h($p['employee_name']) ?></div>
                                <div class="text-muted small"><?= h(mb_strimwidth($p['comment'], 0, 60, '...')) ?></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- クイックリンク -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-link-45deg me-2"></i>クイックリンク</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6"><a href="praise.php" class="btn btn-outline-warning w-100 py-3"><i class="bi bi-hand-thumbs-up d-block mb-1 fs-4"></i>褒める</a></div>
                        <div class="col-6"><a href="eval_history.php" class="btn btn-outline-info w-100 py-3"><i class="bi bi-clock-history d-block mb-1 fs-4"></i>履歴</a></div>
                        <div class="col-6">
                            <?php if ($mySheet): ?>
                            <a href="eval_feedback.php?sheet_id=<?= $mySheet['id'] ?>" class="btn btn-outline-success w-100 py-3"><i class="bi bi-chat-left-dots d-block mb-1 fs-4"></i>FB確認</a>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary w-100 py-3" disabled><i class="bi bi-chat-left-dots d-block mb-1 fs-4"></i>FB確認</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>現在進行中の評価期間がありません。管理者が評価期間を作成するとここに表示されます。</div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
