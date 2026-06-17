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

$pageTitle = '社員一覧';

$db = getDB();

// チームフィルター
$teamFilter = isset($_GET['team']) ? (int)$_GET['team'] : 0;
$teams = getAllTeams($cid);

// 社員一覧取得
$sql = 'SELECT e.*,
               t.name AS team_name, t.id AS team_id,
               sf.achiever, sf.strategic, sf.learner, sf.maximizer, sf.relator,
               sf.futuristic, sf.analytical, sf.top5_text,
               sp.leadership, sp.teamwork, sp.problem_solving
        FROM employees e
        LEFT JOIN team_members tm ON e.id = tm.employee_id
        LEFT JOIN teams t ON tm.team_id = t.id
        LEFT JOIN strengths_finder sf ON e.id = sf.employee_id
        LEFT JOIN spi_results sp ON e.id = sp.employee_id
        WHERE e.is_active = 1';
$params = [];
if ($cid) {
    $sql .= ' AND e.company_id = ?';
    $params[] = $cid;
}
if ($teamFilter > 0) {
    $sql .= ' AND tm.team_id = ?';
    $params[] = $teamFilter;
}
$sql .= ' ORDER BY e.employee_number, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$sfThemes = getStrengthsThemeDefinitions();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-person-lines-fill me-2"></i>社員一覧</h1>
                <p><?= count($employees) ?>名の社員情報</p>
            </div>
        </div>
    </div>

    <!-- フィルター・検索 -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="employeeSearch" class="form-control" placeholder="名前・職種・部署で検索...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 社員グリッド -->
    <div class="row g-3">
        <?php foreach ($employees as $emp): ?>
        <?php
            $initial  = mb_substr($emp['name'], 0, 1);
            $top5     = [];
            if (!empty($emp['top5_text'])) {
                $top5 = explode('・', $emp['top5_text']);
            }
            $searchText = strtolower($emp['name'] . ' ' . ($emp['name_kana'] ?? '') . ' ' . ($emp['job_title'] ?? '') . ' ' . ($emp['department'] ?? '') . ' ' . ($emp['team_name'] ?? ''));
        ?>
        <div class="col-sm-6 col-xl-4">
            <a href="employee.php?id=<?= $emp['id'] ?>" class="employee-card p-4 h-100"
               data-search="<?= h($searchText) ?>" data-bs-class="employee-filter-item">
                <div class="employee-filter-item" data-search="<?= h($searchText) ?>">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="avatar flex-shrink-0">
                            <?php if ($emp['photo_path'] && file_exists(UPLOAD_DIR . $emp['photo_path'])): ?>
                                <img src="<?= h(UPLOAD_URL . $emp['photo_path']) ?>" alt="<?= h($emp['name']) ?>">
                            <?php else: ?>
                                <?= h($initial) ?>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-bold fs-6 text-dark"><?= h($emp['name']) ?></div>
                            <?php if ($emp['name_kana']): ?>
                            <div class="text-muted" style="font-size:11px"><?= h($emp['name_kana']) ?></div>
                            <?php endif; ?>
                            <div class="text-muted small mt-1">
                                <?php if ($emp['job_title']): ?>
                                <i class="bi bi-briefcase me-1"></i><?= h($emp['job_title']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($emp['team_name']): ?>
                            <span class="badge bg-light text-dark mt-1" style="font-size:11px">
                                <i class="bi bi-diagram-3 me-1"></i><?= h($emp['team_name']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($emp['hire_date']): ?>
                    <div class="text-muted small mb-2">
                        <i class="bi bi-calendar3 me-1"></i>入社: <?= formatDate($emp['hire_date'], 'Y年n月') ?>
                        <span class="ms-2 text-info"><?= getYearsOfService($emp['hire_date']) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($top5)): ?>
                    <div class="mt-2">
                        <div class="info-label mb-1">トップ資質</div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach (array_slice($top5, 0, 3) as $i => $theme): ?>
                            <span class="badge" style="font-size:11px;background:#eff3ff;color:#3d5a9e">
                                <?= h(trim($theme)) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3 d-flex gap-2 text-muted" style="font-size:11px">
                        <?php if ($emp['achiever'] !== null): ?>
                        <span><i class="bi bi-lightning-fill text-warning"></i> SF</span>
                        <?php endif; ?>
                        <?php if ($emp['leadership'] !== null): ?>
                        <span><i class="bi bi-activity text-success"></i> SPI</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>

        <?php if (empty($employees)): ?>
        <div class="col-12">
            <div class="card text-center p-5">
                <i class="bi bi-person-x fs-1 text-muted mb-3"></i>
                <p class="text-muted">社員データがありません。<br>管理画面から登録してください。</p>
                <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_PATH ?>/admin/employee_form.php" class="btn btn-primary mt-2">社員を追加する</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// 検索フィルター（インライン補完）
document.getElementById('employeeSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.employee-filter-item').forEach(item => {
        const text = item.dataset.search || '';
        const col  = item.closest('.col-sm-6, .col-xl-4, .col');
        if (col) col.style.display = text.includes(q) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
