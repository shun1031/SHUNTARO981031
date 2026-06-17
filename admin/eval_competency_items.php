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
        $desc       = trim($_POST['description'] ?? '');
        $level1     = trim($_POST['level1_desc'] ?? '');
        $level2     = trim($_POST['level2_desc'] ?? '');
        $level3     = trim($_POST['level3_desc'] ?? '');
        $level4     = trim($_POST['level4_desc'] ?? '');
        $level5     = trim($_POST['level5_desc'] ?? '');
        $weight     = (int)($_POST['weight'] ?? 1);
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);

        if (!$name) {
            $error = '項目名は必須です';
        } else {
            $db->prepare(
                'INSERT INTO eval_competency_items (company_id, name, description, level1_desc, level2_desc, level3_desc, level4_desc, level5_desc, weight, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$cid, $name, $desc, $level1, $level2, $level3, $level4, $level5, $weight, $sortOrder]);
            $success = 'コンピテンシー項目を作成しました';
        }
    }

    if ($action === 'update') {
        $id         = (int)($_POST['item_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $level1     = trim($_POST['level1_desc'] ?? '');
        $level2     = trim($_POST['level2_desc'] ?? '');
        $level3     = trim($_POST['level3_desc'] ?? '');
        $level4     = trim($_POST['level4_desc'] ?? '');
        $level5     = trim($_POST['level5_desc'] ?? '');
        $weight     = (int)($_POST['weight'] ?? 1);
        $sortOrder  = (int)($_POST['sort_order'] ?? 0);

        if ($id && $name) {
            $db->prepare(
                'UPDATE eval_competency_items
                 SET name = ?, description = ?, level1_desc = ?, level2_desc = ?, level3_desc = ?, level4_desc = ?, level5_desc = ?, weight = ?, sort_order = ?
                 WHERE id = ? AND company_id = ?'
            )->execute([$name, $desc, $level1, $level2, $level3, $level4, $level5, $weight, $sortOrder, $id, $cid]);
            $success = '更新しました';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['item_id'] ?? 0);
        if ($id) {
            $db->prepare('DELETE FROM eval_competency_items WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
            $success = '削除しました';
        }
    }
}

// === データ取得 ===
$items = getCompetencyItems($cid);

// 編集対象
$editItem = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) {
    foreach ($items as $item) {
        if ($item['id'] === $editId) {
            $editItem = $item;
            break;
        }
    }
}

$pageTitle = 'コンピテンシー項目管理';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item active">コンピテンシー項目管理</li>
        </ol>
    </nav>

    <div class="page-header">
        <h1><i class="bi bi-star me-2"></i>コンピテンシー項目管理</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- コンピテンシー項目一覧（カード表示） -->
    <div class="row g-3 mb-4">
        <?php foreach ($items as $item): ?>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= h($item['name']) ?></strong>
                        <span class="badge bg-secondary ms-2">ウェイト: <?= h($item['weight']) ?></span>
                        <span class="badge bg-light text-dark ms-1">表示順: <?= h($item['sort_order']) ?></span>
                    </div>
                    <div>
                        <a href="?edit=<?= $item['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil me-1"></i>編集
                        </a>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('この項目を削除しますか？')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($item['description']): ?>
                    <p class="text-muted mb-3"><?= h($item['description']) ?></p>
                    <?php endif; ?>

                    <div class="accordion" id="levels<?= $item['id'] ?>">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#levelDetail<?= $item['id'] ?>">
                                    <i class="bi bi-list-ol me-2"></i>5段階定義を表示
                                </button>
                            </h2>
                            <div id="levelDetail<?= $item['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#levels<?= $item['id'] ?>">
                                <div class="accordion-body p-0">
                                    <div class="table-responsive"><table class="table table-sm mb-0">
                                        <?php for ($lv = 1; $lv <= 5; $lv++): ?>
                                        <tr>
                                            <td class="text-center fw-bold" style="width:60px;">
                                                <span class="badge bg-<?= $lv >= 4 ? 'success' : ($lv >= 3 ? 'primary' : ($lv >= 2 ? 'warning text-dark' : 'secondary')) ?>">Lv.<?= $lv ?></span>
                                            </td>
                                            <td class="small"><?= h($item["level{$lv}_desc"] ?? '') ?: '<span class="text-muted">未設定</span>' ?></td>
                                        </tr>
                                        <?php endfor; ?>
                                    </table></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
    <div class="alert alert-info">コンピテンシー項目がまだ登録されていません。下のフォームから追加してください。</div>
    <?php endif; ?>

    <!-- 新規作成 / 編集フォーム -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-<?= $editItem ? 'pencil' : 'plus-circle' ?> me-2"></i>
            <?= $editItem ? 'コンピテンシー項目編集' : '新規コンピテンシー項目' ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'create' ?>">
                <?php if ($editItem): ?>
                <input type="hidden" name="item_id" value="<?= $editItem['id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">項目名 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= h($editItem['name'] ?? '') ?>" placeholder="例: コミュニケーション力">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">ウェイト</label>
                        <input type="number" name="weight" class="form-control" min="1" max="100"
                               value="<?= h($editItem['weight'] ?? 1) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">表示順</label>
                        <input type="number" name="sort_order" class="form-control" min="0"
                               value="<?= h($editItem['sort_order'] ?? 0) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">説明</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="この項目の概要"><?= h($editItem['description'] ?? '') ?></textarea>
                    </div>

                    <?php for ($lv = 1; $lv <= 5; $lv++): ?>
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            <span class="badge bg-<?= $lv >= 4 ? 'success' : ($lv >= 3 ? 'primary' : ($lv >= 2 ? 'warning text-dark' : 'secondary')) ?> me-1">Lv.<?= $lv ?></span>
                            レベル<?= $lv ?>の定義
                        </label>
                        <textarea name="level<?= $lv ?>_desc" class="form-control" rows="2"
                                  placeholder="レベル<?= $lv ?>の行動例・基準を記述"><?= h($editItem["level{$lv}_desc"] ?? '') ?></textarea>
                    </div>
                    <?php endfor; ?>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-<?= $editItem ? 'check-lg' : 'plus-circle' ?> me-1"></i>
                        <?= $editItem ? '更新する' : '追加する' ?>
                    </button>
                    <?php if ($editItem): ?>
                    <a href="eval_competency_items.php" class="btn btn-secondary">キャンセル</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
