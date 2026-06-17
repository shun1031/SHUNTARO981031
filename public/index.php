<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
$cid = getCompanyId();

$pageTitle = 'ダッシュボード';

$db = getDB();

// 会社スコープ付きカウントクエリ（プレースホルダ使用）
$cidFilter     = $cid ? ' AND e.company_id = ?' : '';
$cidFilterT    = $cid ? ' AND t.company_id = ?' : '';
$cidParams     = $cid ? [$cid] : [];

$stmt = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.is_active = 1{$cidFilter}");
$stmt->execute($cidParams);
$totalEmployees = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM teams t WHERE 1=1{$cidFilterT}");
$stmt->execute($cidParams);
$totalTeams = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM strengths_finder sf JOIN employees e ON sf.employee_id = e.id WHERE e.is_active = 1{$cidFilter}");
$stmt->execute($cidParams);
$sfCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM spi_results sp JOIN employees e ON sp.employee_id = e.id WHERE e.is_active = 1{$cidFilter}");
$stmt->execute($cidParams);
$spiCount = (int)$stmt->fetchColumn();

// 最近追加された社員
$recentSql = 'SELECT e.*, t.name AS team_name FROM employees e
     LEFT JOIN team_members tm ON e.id = tm.employee_id
     LEFT JOIN teams t ON tm.team_id = t.id
     WHERE e.is_active = 1';
if ($cid) {
    $recentSql .= ' AND e.company_id = ?';
}
$recentSql .= ' ORDER BY e.created_at DESC LIMIT 6';
$stmt = $db->prepare($recentSql);
$stmt->execute($cidParams);
$recentEmployees = $stmt->fetchAll();

// チーム一覧
$teams = getAllTeams($cid);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- ページヘッダー -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-grid-1x2 me-2"></i>ダッシュボード</h1>
                <p>組織の全体像をひと目で把握</p>
            </div>
            <div class="text-end">
                <span class="tag"><i class="bi bi-calendar3 me-1"></i><?= date('Y年n月j日') ?></span>
            </div>
        </div>
    </div>

    <!-- 統計カード -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--a600)"><?= $totalEmployees ?></div>
                <div class="stat-label"><i class="bi bi-people me-1"></i>在籍社員数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--cyan)"><?= $totalTeams ?></div>
                <div class="stat-label"><i class="bi bi-diagram-3 me-1"></i>チーム数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--purple)"><?= $sfCount ?></div>
                <div class="stat-label"><i class="bi bi-lightning me-1"></i>SF登録済み</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--amber)"><?= $spiCount ?></div>
                <div class="stat-label"><i class="bi bi-activity me-1"></i>SPI登録済み</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- チーム一覧 -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-2" style="color:var(--a600)"></i>チーム</span>
                    <a href="<?= BASE_PATH ?>/public/teams.php" class="btn btn-sm btn-outline-success">すべて見る <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
                <div class="card-body p-3">
                    <?php if (empty($teams)): ?>
                    <div class="empty-state">
                        <i class="bi bi-diagram-3"></i>
                        <p>チームがまだ登録されていません</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($teams as $team): ?>
                    <a href="<?= BASE_PATH ?>/public/team.php?id=<?= $team['id'] ?>" class="text-decoration-none">
                        <div class="team-card p-3 mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?= h($team['name']) ?></div>
                                    <?php if ($team['manager_name']): ?>
                                    <div class="text-muted small mt-1">
                                        <i class="bi bi-person-circle me-1"></i><?= h($team['manager_name']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="tag">
                                        <i class="bi bi-people"></i><?= $team['member_count'] ?>名
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- クイックアクセス -->
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-grid me-2" style="color:var(--a600)"></i>クイックアクセス</div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/public/employees.php" class="quick-action">
                                <i class="bi bi-person-lines-fill"></i>
                                <small>社員一覧</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/public/strengths.php" class="quick-action">
                                <i class="bi bi-lightning-fill" style="color:var(--purple)"></i>
                                <small>SF分析</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="<?= BASE_PATH ?>/public/spi.php" class="quick-action">
                                <i class="bi bi-activity" style="color:var(--cyan)"></i>
                                <small>SPI分析</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 最近の社員 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people me-2" style="color:var(--a600)"></i>社員</span>
                    <a href="<?= BASE_PATH ?>/public/employees.php" class="btn btn-sm btn-outline-success">一覧 <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
                <div class="card-body p-3">
                    <?php foreach ($recentEmployees as $emp): ?>
                    <a href="<?= BASE_PATH ?>/public/employee.php?id=<?= $emp['id'] ?>" class="d-flex align-items-center text-decoration-none text-dark mb-3 p-2 rounded-3" style="transition: background .15s ease;">
                        <div class="me-3 flex-shrink-0">
                            <?php $initial = mb_substr($emp['name'], 0, 1) ?>
                            <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#059669,#34d399);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;box-shadow:0 2px 8px rgba(5,150,105,.2);">
                                <?= h($initial) ?>
                            </div>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-medium text-truncate" style="font-size:.85rem"><?= h($emp['name']) ?></div>
                            <div class="text-muted text-truncate" style="font-size:.7rem"><?= h($emp['job_title'] ?? $emp['department'] ?? '') ?></div>
                        </div>
                        <i class="bi bi-chevron-right text-muted" style="font-size:.7rem;opacity:.4"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
