<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$error = '';
$success = '';

// === POST処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name       = trim($_POST['name'] ?? '');
        $fiscalYear = (int)($_POST['fiscal_year'] ?? 0);
        $half       = trim($_POST['half'] ?? 'first');
        $startDate  = trim($_POST['start_date'] ?? '');
        $endDate    = trim($_POST['end_date'] ?? '');

        if (!$name || !$fiscalYear || !$startDate || !$endDate || !in_array($half, ['first','second'])) {
            $error = '全ての必須項目を入力してください';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO eval_periods (company_id, name, fiscal_year, half, start_date, end_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$cid, $name, $fiscalYear, $half, $startDate, $endDate, 'draft']);
            $success = '評価期間を作成しました';
        }
    }

    if ($action === 'update') {
        $id         = (int)($_POST['period_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $fiscalYear = (int)($_POST['fiscal_year'] ?? 0);
        $half       = trim($_POST['half'] ?? 'first');
        $startDate  = trim($_POST['start_date'] ?? '');
        $endDate    = trim($_POST['end_date'] ?? '');

        if ($id && $name && $fiscalYear && $startDate && $endDate && in_array($half, ['first','second'])) {
            $db->prepare(
                'UPDATE eval_periods SET name = ?, fiscal_year = ?, half = ?, start_date = ?, end_date = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([$name, $fiscalYear, $half, $startDate, $endDate, $id, $cid]);
            $success = '更新しました';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['period_id'] ?? 0);
        if ($id) {
            // draft状態のみ削除可能
            $check = $db->prepare('SELECT status FROM eval_periods WHERE id = ? AND company_id = ?');
            $check->execute([$id, $cid]);
            $period = $check->fetch();
            if ($period && $period['status'] === 'draft') {
                $db->prepare('DELETE FROM eval_periods WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
                $success = '削除しました';
            } else {
                $error = '下書き状態の期間のみ削除できます';
            }
        }
    }

    if ($action === 'change_status') {
        $id        = (int)($_POST['period_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');

        $period = getEvalPeriod($id, $cid);
        if (!$period) {
            $error = '期間が見つかりません';
        } else {
            // ステータス遷移の検証
            $transitions = [
                'draft'        => 'open',
                'open'         => 'self_eval',
                'self_eval'    => 'primary_eval',
                'primary_eval' => 'adjustment',
                'adjustment'   => 'feedback',
                'feedback'     => 'closed',
            ];

            $currentStatus = $period['status'];
            if (!isset($transitions[$currentStatus]) || $transitions[$currentStatus] !== $newStatus) {
                $error = '無効なステータス遷移です';
            } else {
                // open→self_eval 時にシート一括生成
                if ($currentStatus === 'open' && $newStatus === 'self_eval') {
                    $count = generateEvalSheets($id, $cid);
                    $success = "ステータスを変更し、{$count}件の評価シートを生成しました";
                } else {
                    $success = 'ステータスを変更しました';
                }

                $db->prepare('UPDATE eval_periods SET status = ? WHERE id = ? AND company_id = ?')
                   ->execute([$newStatus, $id, $cid]);
            }
        }
    }
}

// === データ取得 ===
$periods = getEvalPeriods($cid);

// シート数を取得
$sheetCounts = [];
foreach ($periods as $p) {
    $cnt = $db->prepare('SELECT COUNT(*) FROM eval_sheets WHERE period_id = ?');
    $cnt->execute([$p['id']]);
    $sheetCounts[$p['id']] = (int)$cnt->fetchColumn();
}

// 次ステータスマッピング
$nextStatusMap = [
    'draft'        => ['status' => 'open',         'label' => '公開する'],
    'open'         => ['status' => 'self_eval',     'label' => '自己評価開始'],
    'self_eval'    => ['status' => 'primary_eval',  'label' => '1次評価開始'],
    'primary_eval' => ['status' => 'adjustment',    'label' => '調整開始'],
    'adjustment'   => ['status' => 'feedback',      'label' => 'FB開始'],
    'feedback'     => ['status' => 'closed',        'label' => '完了にする'],
];

$pageTitle = '評価期間管理';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item active">評価期間管理</li>
        </ol>
    </nav>

    <div class="page-header">
        <h1><i class="bi bi-calendar-range me-2"></i>評価期間管理</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 評価期間一覧 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">評価期間一覧</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>期間名</th>
                                <th>年度</th>
                                <th>半期</th>
                                <th>開始日</th>
                                <th>終了日</th>
                                <th>ステータス</th>
                                <th>シート数</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $p):
                                $sl = getEvalStatusLabel($p['status']);
                            ?>
                            <tr>
                                <td><?= h($p['name']) ?></td>
                                <td><?= h($p['fiscal_year']) ?></td>
                                <td><?= $p['half'] === 'first' ? '上期' : '下期' ?></td>
                                <td><?= h($p['start_date']) ?></td>
                                <td><?= h($p['end_date']) ?></td>
                                <td><span class="badge <?= h($sl['class']) ?>"><?= h($sl['label']) ?></span></td>
                                <td><?= $sheetCounts[$p['id']] ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="eval_performance_items.php?period_id=<?= $p['id'] ?>" class="btn btn-outline-info" title="業績項目">
                                            <i class="bi bi-list-ol"></i>
                                        </a>
                                        <a href="eval_action_items.php?period_id=<?= $p['id'] ?>" class="btn btn-outline-info" title="行動項目">
                                            <i class="bi bi-check2-square"></i>
                                        </a>
                                        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['id'] ?>" title="編集">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (isset($nextStatusMap[$p['status']])): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('ステータスを変更しますか？')">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= h($nextStatusMap[$p['status']]['status']) ?>">
                                            <button type="submit" class="btn btn-outline-primary btn-sm" title="<?= h($nextStatusMap[$p['status']]['label']) ?>">
                                                <i class="bi bi-arrow-right-circle me-1"></i><?= h($nextStatusMap[$p['status']]['label']) ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- 編集モーダル -->
                            <div class="modal fade" id="editModal<?= $p['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">評価期間編集</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">期間名 <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control" value="<?= h($p['name']) ?>" required>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-6">
                                                        <label class="form-label">年度 <span class="text-danger">*</span></label>
                                                        <input type="number" name="fiscal_year" class="form-control" value="<?= h($p['fiscal_year']) ?>" required>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">半期 <span class="text-danger">*</span></label>
                                                        <select name="half" class="form-select" required>
                                                            <option value="first" <?= $p['half'] === 'first' ? 'selected' : '' ?>>上期</option>
                                                            <option value="second" <?= $p['half'] === 'second' ? 'selected' : '' ?>>下期</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="row g-3 mt-1">
                                                    <div class="col-6">
                                                        <label class="form-label">開始日 <span class="text-danger">*</span></label>
                                                        <input type="date" name="start_date" class="form-control" value="<?= h($p['start_date']) ?>" required>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">終了日 <span class="text-danger">*</span></label>
                                                        <input type="date" name="end_date" class="form-control" value="<?= h($p['end_date']) ?>" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <?php if ($p['status'] === 'draft'): ?>
                                                <button type="submit" name="action" value="delete" class="btn btn-danger me-auto" onclick="return confirm('この評価期間を削除しますか？')">
                                                    <i class="bi bi-trash me-1"></i>削除
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                                <button type="submit" class="btn btn-primary">保存</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($periods)): ?>
                <div class="card-body text-center text-muted">評価期間がまだ登録されていません</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 新規作成 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>新規評価期間</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">期間名 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="例: 2025年度 上期評価">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">年度 <span class="text-danger">*</span></label>
                                <input type="number" name="fiscal_year" class="form-control" required value="<?= date('Y') ?>" min="2020" max="2099">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">半期 <span class="text-danger">*</span></label>
                                <select name="half" class="form-select" required>
                                    <option value="first">上期</option>
                                    <option value="second">下期</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">開始日 <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">終了日 <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-calendar-plus me-1"></i>作成する
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
