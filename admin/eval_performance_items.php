<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$error = '';
$success = '';

// 期間IDチェック
$periodId = (int)($_GET['period_id'] ?? 0);
if (!$periodId) {
    header('Location: eval_periods.php');
    exit;
}

$period = getEvalPeriod($periodId, $cid);
if (!$period) {
    header('Location: eval_periods.php');
    exit;
}

// === POST処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name       = trim($_POST['name'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $weight     = (int)($_POST['weight'] ?? 1);
        $deptKey    = trim($_POST['department_key'] ?? '') ?: null;
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);

        if (!$name) {
            $error = '項目名は必須です';
        } else {
            $db->prepare(
                'INSERT INTO eval_performance_items (period_id, company_id, name, unit, weight, department_key, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$periodId, $cid, $name, $unit, $weight, $deptKey, $sortOrder]);
            $success = '業績項目を追加しました';
        }
    }

    if ($action === 'update') {
        $id         = (int)($_POST['item_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $weight     = (int)($_POST['weight'] ?? 1);
        $deptKey    = trim($_POST['department_key'] ?? '') ?: null;
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);

        if ($id && $name) {
            $db->prepare(
                'UPDATE eval_performance_items SET name = ?, unit = ?, weight = ?, department_key = ?, sort_order = ?
                 WHERE id = ? AND period_id = ? AND company_id = ?'
            )->execute([$name, $unit, $weight, $deptKey, $sortOrder, $id, $periodId, $cid]);
            $success = '更新しました';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['item_id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM eval_performance_items WHERE id = ? AND period_id = ? AND company_id = ?')
               ->execute([$id, $periodId, $cid]);
            $success = '削除しました';
        }
    }

    if ($action === 'copy_from_previous') {
        // 前期間を検索
        $prevStmt = $db->prepare(
            'SELECT id FROM eval_periods
             WHERE company_id = ? AND (fiscal_year < ? OR (fiscal_year = ? AND half < ?))
             ORDER BY fiscal_year DESC, half DESC LIMIT 1'
        );
        $prevStmt->execute([$cid, $period['fiscal_year'], $period['fiscal_year'], $period['half']]);
        $prev = $prevStmt->fetch();

        if (!$prev) {
            $error = '前の評価期間が見つかりません';
        } else {
            $prevItems = $db->prepare('SELECT name, unit, weight, department_key, sort_order FROM eval_performance_items WHERE period_id = ? AND company_id = ?');
            $prevItems->execute([$prev['id'], $cid]);
            $copied = 0;
            foreach ($prevItems->fetchAll() as $pi) {
                $db->prepare(
                    'INSERT INTO eval_performance_items (period_id, company_id, name, unit, weight, department_key, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$periodId, $cid, $pi['name'], $pi['unit'], $pi['weight'], $pi['department_key'], $pi['sort_order']]);
                $copied++;
            }
            $success = "前期間から{$copied}件の項目をコピーしました";
        }
    }
}

// === データ取得 ===
$items = $db->prepare(
    'SELECT * FROM eval_performance_items WHERE period_id = ? AND company_id = ? ORDER BY sort_order, name'
);
$items->execute([$periodId, $cid]);
$items = $items->fetchAll();

$pageTitle = '業績項目管理';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item"><a href="eval_periods.php">評価期間</a></li>
            <li class="breadcrumb-item"><?= h($period['name']) ?></li>
            <li class="breadcrumb-item active">業績項目</li>
        </ol>
    </nav>

    <div class="page-header d-flex justify-content-between align-items-center">
        <h1><i class="bi bi-list-ol me-2"></i>業績項目管理</h1>
        <form method="POST" class="d-inline" onsubmit="return confirm('前の評価期間から項目をコピーしますか？')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="copy_from_previous">
            <button type="submit" class="btn btn-outline-info">
                <i class="bi bi-copy me-1"></i>前期間からコピー
            </button>
        </form>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 項目一覧 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">業績項目一覧 - <?= h($period['name']) ?></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>項目名</th>
                                <th>単位</th>
                                <th class="text-center">ウェイト</th>
                                <th>部署キー</th>
                                <th class="text-center">表示順</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= h($item['unit'] ?: '-') ?></td>
                                <td class="text-center"><?= h($item['weight']) ?></td>
                                <td><?= $item['department_key'] ? '<code>' . h($item['department_key']) . '</code>' : '<span class="text-muted">全部署</span>' ?></td>
                                <td class="text-center"><?= h($item['sort_order']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $item['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('この項目を削除しますか？')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <!-- 編集モーダル -->
                            <div class="modal fade" id="editModal<?= $item['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">業績項目編集</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">項目名 <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control" value="<?= h($item['name']) ?>" required>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-6">
                                                        <label class="form-label">単位</label>
                                                        <select name="unit" class="form-select">
                                                            <option value="">なし</option>
                                                            <?php foreach (['円', '件', '%', '回', '時間'] as $u): ?>
                                                            <option value="<?= h($u) ?>" <?= $item['unit'] === $u ? 'selected' : '' ?>><?= h($u) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">ウェイト</label>
                                                        <input type="number" name="weight" class="form-control" value="<?= h($item['weight']) ?>" min="1" max="100">
                                                    </div>
                                                </div>
                                                <div class="row g-3 mt-1">
                                                    <div class="col-6">
                                                        <label class="form-label">部署キー</label>
                                                        <input type="text" name="department_key" class="form-control" value="<?= h($item['department_key'] ?? '') ?>" placeholder="空=全部署">
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">表示順</label>
                                                        <input type="number" name="sort_order" class="form-control" value="<?= h($item['sort_order']) ?>" min="0">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
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
                <?php if (empty($items)): ?>
                <div class="card-body text-center text-muted">業績項目がまだ登録されていません</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 新規追加 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>新規業績項目</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">項目名 <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="例: 売上高">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">単位</label>
                            <select name="unit" class="form-select">
                                <option value="">なし</option>
                                <option value="円">円</option>
                                <option value="件">件</option>
                                <option value="%">%</option>
                                <option value="回">回</option>
                                <option value="時間">時間</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">ウェイト</label>
                            <input type="number" name="weight" class="form-control" value="1" min="1" max="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">部署キー</label>
                            <input type="text" name="department_key" class="form-control" placeholder="空=全部署共通">
                            <div class="form-text">特定部署のみの場合にキーを入力</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">表示順</label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-plus-circle me-1"></i>追加する
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
