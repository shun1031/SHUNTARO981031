<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin');

$db        = getDB();
$csrf      = getCsrfToken();
$companyId = (int)($_GET['company_id'] ?? 0);
$error     = '';
$success   = '';

if (!$companyId) {
    redirect(BASE_PATH . '/admin/companies.php');
}

$company = getCompany($companyId);
if (!$company) {
    redirect(BASE_PATH . '/admin/companies.php');
}

// 会社の社員一覧（employee_idリンク用）
$employees = getAllEmployees(true, $companyId);

// === 保存処理 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        die('不正なリクエストです');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username    = trim($_POST['username'] ?? '');
        $password    = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $role        = $_POST['role'] ?? 'employee';
        $employeeId  = (int)($_POST['employee_id'] ?? 0) ?: null;

        if (!$username || !$password) {
            $error = 'ユーザーIDとパスワードは必須です';
        } elseif (!in_array($role, ['company_admin', 'employee'])) {
            $error = '無効なロールです';
        } else {
            $existing = $db->prepare('SELECT id FROM users WHERE username = ?');
            $existing->execute([$username]);
            if ($existing->fetch()) {
                $error = 'このユーザーIDは既に使用されています';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    'INSERT INTO users (username, password_hash, display_name, role, company_id, employee_id) VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([$username, $hash, $displayName ?: $username, $role, $companyId, $employeeId]);
                $success = 'ユーザーを作成しました（ID: ' . $username . '）';
            }
        }
    }

    if ($action === 'update') {
        $userId      = (int)($_POST['user_id'] ?? 0);
        $displayName = trim($_POST['display_name'] ?? '');
        $role        = $_POST['role'] ?? 'employee';
        $employeeId  = (int)($_POST['employee_id'] ?? 0) ?: null;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        if ($userId && in_array($role, ['company_admin', 'employee'])) {
            $db->prepare('UPDATE users SET display_name = ?, role = ?, employee_id = ?, is_active = ? WHERE id = ? AND company_id = ?')
               ->execute([$displayName, $role, $employeeId, $isActive, $userId, $companyId]);

            if ($newPassword) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            }
            $success = '更新しました';
        }
    }

    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId) {
            $db->prepare('DELETE FROM users WHERE id = ? AND company_id = ?')->execute([$userId, $companyId]);
            $success = '削除しました';
        }
    }
}

// ユーザー一覧取得
$stmt = $db->prepare(
    'SELECT u.*, e.name AS employee_name
     FROM users u
     LEFT JOIN employees e ON u.employee_id = e.id
     WHERE u.company_id = ?
     ORDER BY u.role, u.username'
);
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();

$roleLabels = [
    'company_admin' => ['label' => '管理者', 'class' => 'bg-warning text-dark'],
    'employee'      => ['label' => '社員', 'class' => 'bg-info text-dark'],
];

$pageTitle = h($company['company_name']) . ' - ユーザー管理';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">管理</a></li>
            <li class="breadcrumb-item"><a href="companies.php">会社管理</a></li>
            <li class="breadcrumb-item active"><?= h($company['company_name']) ?></li>
        </ol>
    </nav>

    <div class="page-header">
        <h1><i class="bi bi-people me-2"></i><?= h($company['company_name']) ?> - ユーザー管理</h1>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible"><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ユーザー一覧 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">登録済みユーザー（<?= count($users) ?>名）</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ユーザーID</th>
                                <th>表示名</th>
                                <th>ロール</th>
                                <th>紐付け社員</th>
                                <th>状態</th>
                                <th>最終ログイン</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">ユーザーが登録されていません</td></tr>
                            <?php endif; ?>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><code><?= h($u['username']) ?></code></td>
                                <td><?= h($u['display_name']) ?></td>
                                <td>
                                    <?php $rl = $roleLabels[$u['role']] ?? ['label' => $u['role'], 'class' => 'bg-secondary']; ?>
                                    <span class="badge <?= $rl['class'] ?>"><?= $rl['label'] ?></span>
                                </td>
                                <td><?= $u['employee_name'] ? h($u['employee_name']) : '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <?= $u['is_active'] ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">無効</span>' ?>
                                </td>
                                <td class="small"><?= $u['last_login_at'] ? h(formatDate($u['last_login_at'], 'Y/m/d H:i')) : '-' ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editUser<?= $u['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- 編集モーダル -->
                            <div class="modal fade" id="editUser<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">ユーザー編集: <?= h($u['username']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">表示名</label>
                                                    <input type="text" name="display_name" class="form-control" value="<?= h($u['display_name']) ?>">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">ロール</label>
                                                    <select name="role" class="form-select">
                                                        <option value="company_admin" <?= $u['role'] === 'company_admin' ? 'selected' : '' ?>>管理者</option>
                                                        <option value="employee" <?= $u['role'] === 'employee' ? 'selected' : '' ?>>社員</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">紐付け社員</label>
                                                    <select name="employee_id" class="form-select">
                                                        <option value="">紐付けなし</option>
                                                        <?php foreach ($employees as $emp): ?>
                                                        <option value="<?= $emp['id'] ?>" <?= (int)$u['employee_id'] === (int)$emp['id'] ? 'selected' : '' ?>><?= h($emp['name']) ?>（<?= h($emp['employee_number']) ?>）</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">新しいパスワード（変更する場合のみ）</label>
                                                    <input type="password" name="new_password" class="form-control" placeholder="変更しない場合は空欄">
                                                </div>
                                                <div class="form-check">
                                                    <input type="checkbox" name="is_active" class="form-check-input" id="uActive<?= $u['id'] ?>" <?= $u['is_active'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="uActive<?= $u['id'] ?>">有効</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" name="action" value="delete" class="btn btn-danger me-auto" onclick="return confirm('このユーザーを削除しますか？')">
                                                    <i class="bi bi-trash me-1"></i>削除
                                                </button>
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

        <!-- 新規ユーザー作成 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-person-plus me-2"></i>新規ユーザー作成</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">ユーザーID <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required
                                   pattern="[a-zA-Z0-9_@.\-]+" title="英数字・記号のみ"
                                   placeholder="例: tanaka.taro">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">パスワード <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">表示名</label>
                            <input type="text" name="display_name" class="form-control" placeholder="例: 田中太郎">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ロール</label>
                            <select name="role" class="form-select">
                                <option value="company_admin">管理者</option>
                                <option value="employee" selected>社員</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">紐付け社員</label>
                            <select name="employee_id" class="form-select">
                                <option value="">紐付けなし</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= h($emp['name']) ?>（<?= h($emp['employee_number']) ?>）</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">社員ロールの場合、自分のプロフィールと紐付けます</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-person-plus me-1"></i>作成する
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
