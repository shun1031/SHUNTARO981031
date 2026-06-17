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

$pageTitle = 'チーム一覧';
$teams = getAllTeams($cid);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-diagram-3 me-2"></i>チーム一覧</h1>
        <p><?= count($teams) ?>チームの情報</p>
    </div>

    <div class="row g-4">
        <?php foreach ($teams as $team): ?>
        <?php $members = getTeamMembers($team['id']); ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header" style="border-left:4px solid #3498db">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-bold fs-6"><?= h($team['name']) ?></span>
                            <span class="badge bg-light text-dark ms-2"><?= count($members) ?>名</span>
                        </div>
                        <a href="team.php?id=<?= $team['id'] ?>" class="btn btn-sm btn-outline-primary">詳細 →</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($team['manager_name']): ?>
                    <div class="mb-3">
                        <span class="info-label">マネージャー</span>
                        <div class="mt-1">
                            <a href="employee.php?id=<?= $team['manager_id'] ?>" class="text-decoration-none">
                                <i class="bi bi-person-fill-gear me-1 text-primary"></i><?= h($team['manager_name']) ?>
                            </a>
                            <?php if ($team['sub_manager_name']): ?>
                            / <a href="employee.php?id=<?= $team['sub_manager_id'] ?>" class="text-decoration-none text-secondary">
                                <?= h($team['sub_manager_name']) ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- メンバーアバター -->
                    <div class="mb-3">
                        <div class="info-label mb-1">メンバー</div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($members as $m): ?>
                            <a href="employee.php?id=<?= $m['id'] ?>" data-bs-toggle="tooltip" title="<?= h($m['name']) ?>"
                               style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#059669,#34d399);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;text-decoration:none">
                                <?= h(mb_substr($m['name'], 0, 1)) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($team['team_strengths']): ?>
                    <div class="p-2 rounded mb-2" style="background:#f0fdf4;border-left:3px solid #27ae60">
                        <div class="info-label mb-1">強み</div>
                        <p class="small mb-0"><?= nl2br(h(mb_substr($team['team_strengths'], 0, 120))) ?><?= mb_strlen($team['team_strengths']) > 120 ? '...' : '' ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($team['team_challenges']): ?>
                    <div class="p-2 rounded" style="background:#fff8f0;border-left:3px solid #f39c12">
                        <div class="info-label mb-1">課題</div>
                        <p class="small mb-0"><?= nl2br(h(mb_substr($team['team_challenges'], 0, 120))) ?><?= mb_strlen($team['team_challenges']) > 120 ? '...' : '' ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($teams)): ?>
        <div class="col-12 text-center p-5">
            <i class="bi bi-diagram-3 fs-1 text-muted mb-3 d-block"></i>
            <p class="text-muted">チームデータがありません。</p>
            <?php if (isLoggedIn()): ?>
            <a href="<?= BASE_PATH ?>/admin/teams.php" class="btn btn-primary">チームを追加する</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
