<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$db    = getDB();
$csrf  = getCsrfToken();
$error = '';
$success = '';

// === 保存処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $loginId     = trim($_POST['login_id'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');

        if (!$loginId || !$companyName) {
            $error = 'ログインIDと企業名は必須です';
        } else {
            $existing = $db->prepare('SELECT id FROM companies WHERE login_id = ?');
            $existing->execute([$loginId]);
            if ($existing->fetch()) {
                $error = 'このログインIDは既に使用されています';
            } else {
                $stmt = $db->prepare('INSERT INTO companies (login_id, company_name) VALUES (?, ?)');
                $stmt->execute([$loginId, $companyName]);
                $success = '会社を作成しました';
            }
        }
    }

    if ($action === 'update') {
        $id          = (int)($_POST['company_id'] ?? 0);
        $companyName = trim($_POST['company_name'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($id && $companyName) {
            // ロゴアップロード処理
            $logoPath = null;
            if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])) {
                    $logoDir = __DIR__ . '/../public/assets/images/logos/';
                    if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);
                    $filename = 'company_' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoDir . $filename)) {
                        $logoPath = 'public/assets/images/logos/' . $filename;
                        $db->prepare('UPDATE companies SET company_name = ?, is_active = ?, logo_path = ? WHERE id = ?')
                           ->execute([$companyName, $isActive, $logoPath, $id]);
                        $success = '更新しました（ロゴも変更）';
                    }
                } else {
                    $error = '画像ファイル（PNG/JPG/GIF/SVG/WebP）を選択してください';
                }
            }
            if (!$logoPath && !$error) {
                $db->prepare('UPDATE companies SET company_name = ?, is_active = ? WHERE id = ?')
                   ->execute([$companyName, $isActive, $id]);
                $success = '更新しました';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['company_id'] ?? 0);
        if ($id > 1) { // デフォルト会社は削除不可
            $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$id]);
            $success = '削除しました';
        }
    }
}

// 会社一覧取得
$companies = $db->query(
    'SELECT c.*, COUNT(DISTINCT u.id) AS user_count, COUNT(DISTINCT e.id) AS employee_count
     FROM companies c
     LEFT JOIN users u ON c.id = u.company_id
     LEFT JOIN employees e ON c.id = e.company_id AND e.is_active = 1
     GROUP BY c.id
     ORDER BY c.id'
)->fetchAll();

$pageTitle = '会社管理';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item active">会社管理</li>
        </ol>
    </nav>

    <div class="page-header">
        <h1><i class="bi bi-building me-2"></i>会社管理</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 会社一覧 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">登録済み会社</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ログインID</th>
                                <th>企業名</th>
                                <th>社員数</th>
                                <th>アカウント数</th>
                                <th>状態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($companies as $c): ?>
                            <tr>
                                <td><?= $c['id'] ?></td>
                                <td><code><?= h($c['login_id']) ?></code></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($c['logo_path'])): ?>
                                        <img src="<?= BASE_PATH ?>/<?= h($c['logo_path']) ?>" alt="" style="height:24px;width:auto;max-width:60px;object-fit:contain">
                                        <?php endif; ?>
                                        <?= h($c['company_name']) ?>
                                    </div>
                                </td>
                                <td><?= $c['employee_count'] ?></td>
                                <td>
                                    <?php if ($c['employee_count'] > 0 && $c['user_count'] < $c['employee_count']): ?>
                                    <span class="text-warning fw-bold"><?= $c['user_count'] ?></span>
                                    <small class="text-muted">/ <?= $c['employee_count'] ?></small>
                                    <?php else: ?>
                                    <?= $c['user_count'] ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['is_active']): ?>
                                    <span class="badge bg-success">有効</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">無効</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="employee_users.php?company_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-people me-1"></i>ユーザー管理
                                    </a>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $c['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- 編集モーダル -->
                            <div class="modal fade" id="editModal<?= $c['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="company_id" value="<?= $c['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">会社編集</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">会社ロゴ</label>
                                                    <?php if (!empty($c['logo_path'])): ?>
                                                    <div class="mb-2"><img src="<?= BASE_PATH ?>/<?= h($c['logo_path']) ?>" style="max-height:48px;max-width:150px;object-fit:contain" alt="現在のロゴ"></div>
                                                    <?php endif; ?>
                                                    <input type="file" name="logo" class="form-control form-control-sm" accept="image/*">
                                                    <div class="form-text">PNG/JPG/SVG推奨（変更しない場合は空欄）</div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">ログインID</label>
                                                    <input type="text" class="form-control" value="<?= h($c['login_id']) ?>" disabled>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">企業名</label>
                                                    <input type="text" name="company_name" class="form-control" value="<?= h($c['company_name']) ?>" required>
                                                </div>
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_active" class="form-check-input" id="active<?= $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="active<?= $c['id'] ?>">有効</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <?php if ($c['id'] > 1): ?>
                                                <button type="submit" name="action" value="delete" class="btn btn-danger me-auto" onclick="return confirm('この会社を削除しますか？関連データも全て削除されます。')">
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
            </div>
        </div>

        <!-- 新規会社作成 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>新規会社作成</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">ログインID <span class="text-danger">*</span></label>
                            <input type="text" name="login_id" class="form-control" required
                                   pattern="[a-zA-Z0-9_-]+" title="英数字・ハイフン・アンダースコアのみ"
                                   placeholder="例: company-abc">
                            <div class="form-text">英数字・ハイフン・アンダースコアのみ</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">企業名 <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control" required
                                   placeholder="例: 株式会社ABC">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-building-add me-1"></i>作成する
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
