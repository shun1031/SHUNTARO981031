<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = '管理画面';
$db = getDB();
$cid = getCompanyId();

// 会社スコープ付きカウントクエリ（プレースホルダ使用）
$cidFilter  = $cid ? ' AND e.company_id = ?' : '';
$cidFilterT = $cid ? ' AND t.company_id = ?' : '';
$cidParams  = $cid ? [$cid] : [];

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

$stmt = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.is_active = 1{$cidFilter} AND NOT EXISTS (SELECT 1 FROM strengths_finder WHERE employee_id = e.id)");
$stmt->execute($cidParams);
$noSf = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM employees e WHERE e.is_active = 1{$cidFilter} AND NOT EXISTS (SELECT 1 FROM spi_results WHERE employee_id = e.id)");
$stmt->execute($cidParams);
$noSpi = (int)$stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-speedometer2 me-2"></i>管理画面</h1>
                <p>ようこそ、<?= h($_SESSION['display_name'] ?? 'Admin') ?>さん
                <?php if ($cid): ?>
                <span class="badge bg-light text-dark ms-2"><?= h($_SESSION['company_name'] ?? '') ?></span>
                <?php endif; ?>
                </p>
            </div>
            <a href="<?= BASE_PATH ?>/public/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>閲覧画面へ
            </a>
        </div>
    </div>

    <!-- 統計 -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-primary"><?= $totalEmployees ?></div>
                <div class="stat-label">在籍社員数</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-success"><?= $sfCount ?> / <?= $totalEmployees ?></div>
                <div class="stat-label">SF登録済み</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-warning"><?= $spiCount ?> / <?= $totalEmployees ?></div>
                <div class="stat-label">SPI登録済み</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-info"><?= $totalTeams ?></div>
                <div class="stat-label">チーム数</div>
            </div>
        </div>
    </div>

    <!-- 管理メニュー -->
    <div class="row g-3 mb-4">
        <?php if (isSuperAdmin()): ?>
        <div class="col-md-4">
            <div class="card h-100 border-primary">
                <div class="card-header bg-primary text-white"><i class="bi bi-building me-2"></i>会社管理</div>
                <div class="card-body">
                    <p class="text-muted small">会社・ユーザーアカウントの管理</p>
                    <div class="d-grid gap-2">
                        <a href="companies.php" class="btn btn-primary">
                            <i class="bi bi-building me-1"></i>会社一覧・管理
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person-fill me-2"></i>社員管理</div>
                <div class="card-body">
                    <p class="text-muted small">社員情報の追加・編集・削除</p>
                    <div class="d-grid gap-2">
                        <a href="employees.php" class="btn btn-primary">
                            <i class="bi bi-list-ul me-1"></i>社員一覧・管理
                        </a>
                        <a href="employee_form.php" class="btn btn-outline-primary">
                            <i class="bi bi-person-plus me-1"></i>新規社員登録
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>チーム管理</div>
                <div class="card-body">
                    <p class="text-muted small">チームの作成・メンバー管理</p>
                    <div class="d-grid gap-2">
                        <a href="teams.php" class="btn btn-success">
                            <i class="bi bi-list-ul me-1"></i>チーム一覧・管理
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-person-gear me-2"></i>ユーザー管理</div>
                <div class="card-body">
                    <p class="text-muted small">社員のログインアカウント作成・管理</p>
                    <div class="d-grid gap-2">
                        <a href="employee_users.php" class="btn btn-info text-white">
                            <i class="bi bi-person-gear me-1"></i>ユーザー一覧・管理
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 評価・給与・研修 -->
    <h5 class="mt-4 mb-3"><i class="bi bi-clipboard-check me-2"></i>評価・給与・研修</h5>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100 border-primary">
                <div class="card-header bg-primary text-white"><i class="bi bi-clipboard-data me-2"></i>評価管理</div>
                <div class="card-body">
                    <p class="text-muted small">評価期間・評価シート・調整会議</p>
                    <div class="d-grid gap-2">
                        <a href="eval_periods.php" class="btn btn-primary">
                            <i class="bi bi-calendar-range me-1"></i>評価期間
                        </a>
                        <a href="eval_weights.php" class="btn btn-outline-primary">
                            <i class="bi bi-sliders me-1"></i>部署別ウェイト設定
                        </a>
                        <a href="eval_competency_items.php" class="btn btn-outline-primary">
                            <i class="bi bi-list-check me-1"></i>コンピテンシー項目
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-success">
                <div class="card-header bg-success text-white"><i class="bi bi-calculator me-2"></i>給与・賞与</div>
                <div class="card-body">
                    <p class="text-muted small">等級テーブル・給与シミュレーション</p>
                    <div class="d-grid gap-2">
                        <a href="eval_grade_table.php" class="btn btn-success">
                            <i class="bi bi-table me-1"></i>等級・号俸テーブル
                        </a>
                        <a href="salary_simulation.php" class="btn btn-outline-success">
                            <i class="bi bi-graph-up me-1"></i>給与シミュレーション
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-warning">
                <div class="card-header bg-warning text-dark"><i class="bi bi-mortarboard me-2"></i>研修・育成</div>
                <div class="card-body">
                    <p class="text-muted small">研修カタログ・自動推奨ルール</p>
                    <div class="d-grid gap-2">
                        <a href="training_catalog.php" class="btn btn-warning">
                            <i class="bi bi-book me-1"></i>研修カタログ
                        </a>
                        <a href="training_rules.php" class="btn btn-outline-warning">
                            <i class="bi bi-gear me-1"></i>自動推奨ルール
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- データ管理・レポート -->
    <h5 class="mt-4 mb-3"><i class="bi bi-gear me-2"></i>データ管理・レポート</h5>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-upload me-2"></i>データ管理</div>
                <div class="card-body">
                    <p class="text-muted small">CSVインポート</p>
                    <div class="d-grid gap-2">
                        <a href="import.php" class="btn btn-secondary">
                            <i class="bi bi-upload me-1"></i>CSVインポート
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-file-earmark-bar-graph me-2"></i>評価レポート</div>
                <div class="card-body">
                    <p class="text-muted small">成績分布・部署分析・CSV出力</p>
                    <div class="d-grid gap-2">
                        <a href="eval_reports.php" class="btn btn-outline-dark">
                            <i class="bi bi-graph-up me-1"></i>評価レポート
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- アラート: 未登録 -->
    <?php if ((int)$noSf > 0 || (int)$noSpi > 0): ?>
    <div class="card border-warning">
        <div class="card-header text-warning"><i class="bi bi-exclamation-triangle me-2"></i>データ未登録の社員がいます</div>
        <div class="card-body">
            <?php if ((int)$noSf > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span><i class="bi bi-lightning me-2"></i>ストレングスファインダー未登録: <strong><?= $noSf ?>名</strong></span>
                <a href="employees.php?filter=no_sf" class="btn btn-sm btn-outline-warning">確認する</a>
            </div>
            <?php endif; ?>
            <?php if ((int)$noSpi > 0): ?>
            <div class="d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity me-2"></i>SPI未登録: <strong><?= $noSpi ?>名</strong></span>
                <a href="employees.php?filter=no_spi" class="btn btn-sm btn-outline-warning">確認する</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
