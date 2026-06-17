<?php
/**
 * 社員ログインアカウント管理
 * 会社管理者が自社の社員にログインアカウントを作成・編集する
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$db   = getDB();
$cid  = getCompanyId(); // セッションから（SAはNULL）
$csrf = getCsrfToken();

// SAは会社選択が必要（GET/POSTからcompany_idを取得）
if (isSuperAdmin()) {
    $cid = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
    if (!$cid) {
        // 会社選択画面を表示
        $companies = $db->query('SELECT id, company_name AS name, login_id FROM companies WHERE is_active = 1 ORDER BY id')->fetchAll();
        $pageTitle = 'ユーザー管理 - 会社選択';
        require_once __DIR__ . '/../includes/header.php';
        ?>
        <h4 class="mb-3"><i class="bi bi-building me-2"></i>会社を選択してください</h4>
        <div class="row g-3">
            <?php foreach ($companies as $c): ?>
            <div class="col-md-4">
                <a href="?company_id=<?= $c['id'] ?>" class="card text-decoration-none h-100">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-building fs-2 text-success d-block mb-2"></i>
                        <div class="fw-bold"><?= h($c['name']) ?></div>
                        <small class="text-muted"><?= h($c['login_id']) ?></small>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
}

$flash = '';
$flashType = 'success';

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $username   = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'employee';

        // バリデーション
        if (!$employeeId || !$username) {
            $flash = 'ユーザーIDは必須です';
            $flashType = 'danger';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
            $flash = 'ユーザーIDは半角英数字・アンダースコア・ハイフン（3〜50文字）で入力してください';
            $flashType = 'danger';
        } elseif ($role !== 'employee' && $role !== 'company_admin') {
            $flash = '不正な権限です';
            $flashType = 'danger';
        } else {
            // 社員が自社に所属しているか確認
            $empCheck = $db->prepare('SELECT id, name FROM employees WHERE id = ? AND company_id = ? AND is_active = 1');
            $empCheck->execute([$employeeId, $cid]);
            $emp = $empCheck->fetch();
            if (!$emp) {
                $flash = '対象の社員が見つかりません';
                $flashType = 'danger';
            } else {
                if ($action === 'create') {
                    if (!$password) {
                        $flash = '新規作成時はパスワードが必須です';
                        $flashType = 'danger';
                    } else {
                        // ユーザーID重複チェック（同一会社内）
                        $dupCheck = $db->prepare('SELECT id FROM users WHERE username = ? AND company_id = ?');
                        $dupCheck->execute([$username, $cid]);
                        if ($dupCheck->fetch()) {
                            $flash = 'ユーザーID「' . $username . '」は既に使用されています';
                            $flashType = 'danger';
                        } else {
                            // 全体でのユニーク制約チェック
                            $globalDup = $db->prepare('SELECT id FROM users WHERE username = ?');
                            $globalDup->execute([$username]);
                            if ($globalDup->fetch()) {
                                $flash = 'ユーザーID「' . $username . '」は他の会社で使用されています。別のIDを指定してください';
                                $flashType = 'danger';
                            } else {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, employee_id, is_active) VALUES (?,?,?,?,?,?,1)');
                                $stmt->execute([$username, $hash, $emp['name'], $role, $cid, $employeeId]);
                                $flash = $emp['name'] . 'のアカウントを作成しました — ログインID: <code>' . h($username) . '</code> / パスワード: <code>' . h($password) . '</code>';
                                $flashRaw = true;
                            }
                        }
                    }
                } elseif ($action === 'update') {
                    $userId = (int)($_POST['user_id'] ?? 0);
                    // ユーザーが自社に属しているか確認
                    $userCheck = $db->prepare('SELECT id, username FROM users WHERE id = ? AND company_id = ?');
                    $userCheck->execute([$userId, $cid]);
                    $existingUser = $userCheck->fetch();
                    if (!$existingUser) {
                        $flash = '対象のアカウントが見つかりません';
                        $flashType = 'danger';
                    } else {
                        // ユーザーID変更時の重複チェック
                        if ($username !== $existingUser['username']) {
                            $dupCheck = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
                            $dupCheck->execute([$username, $userId]);
                            if ($dupCheck->fetch()) {
                                $flash = 'ユーザーID「' . $username . '」は既に使用されています';
                                $flashType = 'danger';
                            }
                        }
                        if (!$flash) {
                            if ($password) {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $db->prepare('UPDATE users SET username = ?, password_hash = ?, role = ?, display_name = ?, updated_at = NOW() WHERE id = ?')
                                   ->execute([$username, $hash, $role, $emp['name'], $userId]);
                                $flash = $emp['name'] . 'のアカウントを更新しました — 新パスワード: <code>' . h($password) . '</code>';
                                $flashRaw = true;
                            } else {
                                $db->prepare('UPDATE users SET username = ?, role = ?, display_name = ?, updated_at = NOW() WHERE id = ?')
                                   ->execute([$username, $role, $emp['name'], $userId]);
                                $flash = $emp['name'] . 'のアカウントを更新しました';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (!$newPass || strlen($newPass) < 6) {
            $flash = 'パスワードは6文字以上で入力してください';
            $flashType = 'danger';
        } else {
            $userCheck = $db->prepare('SELECT id, display_name FROM users WHERE id = ? AND company_id = ?');
            $userCheck->execute([$userId, $cid]);
            $targetUser = $userCheck->fetch();
            if ($targetUser) {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $userId]);
                $flash = $targetUser['display_name'] . 'のパスワードを変更しました — 新パスワード: <code>' . h($newPass) . '</code>';
                $flashRaw = true;
            }
        }
    } elseif ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $userCheck = $db->prepare('SELECT id, is_active, display_name FROM users WHERE id = ? AND company_id = ?');
        $userCheck->execute([$userId, $cid]);
        $targetUser = $userCheck->fetch();
        if ($targetUser) {
            $newActive = $targetUser['is_active'] ? 0 : 1;
            $db->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$newActive, $userId]);
            $flash = $targetUser['display_name'] . 'のアカウントを' . ($newActive ? '有効化' : '無効化') . 'しました';
        }
    }
}

// 社員一覧（ユーザー情報付き）
$sql = 'SELECT e.id AS emp_id, e.employee_number, e.name, e.job_title, e.department,
               u.id AS user_id, u.username, u.role AS user_role, u.is_active AS user_active, u.last_login_at
        FROM employees e
        LEFT JOIN users u ON e.id = u.employee_id AND u.company_id = ?
        WHERE e.is_active = 1 AND e.company_id = ?
        ORDER BY e.employee_number, e.name';
$stmt = $db->prepare($sql);
$stmt->execute([$cid, $cid]);
$employees = $stmt->fetchAll();

// 統計
$totalEmps = count($employees);
$withAccount = count(array_filter($employees, fn($e) => $e['user_id']));
$withoutAccount = $totalEmps - $withAccount;

$pageTitle = 'ユーザー管理';
require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">管理画面</a></li>
        <li class="breadcrumb-item active">ユーザー管理</li>
    </ol>
</nav>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?> alert-dismissible"><?= !empty($flashRaw) ? $flash : h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="bi bi-person-gear me-2"></i>ユーザー管理（ログインアカウント）</h4>
    <?php if (isSuperAdmin()): ?>
    <a href="employee_users.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>会社選択に戻る</a>
    <?php endif; ?>
</div>

<?php if ($totalEmps === 0): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i><strong>この会社にはまだ社員が登録されていません。</strong><br>
    ユーザーアカウントを作成するには、まず社員を登録してください。
    <div class="mt-2">
        <a href="employees.php<?= isSuperAdmin() ? '?company_id=' . $cid : '' ?>" class="btn btn-sm btn-warning"><i class="bi bi-person-plus me-1"></i>社員を登録する</a>
        <a href="employee_form.php" class="btn btn-sm btn-outline-warning ms-2"><i class="bi bi-plus me-1"></i>新規社員登録</a>
    </div>
</div>
<?php endif; ?>

<!-- 統計 -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center border-primary">
            <div class="card-body py-2">
                <div class="text-muted small">全社員</div>
                <div class="fs-3 fw-bold text-primary"><?= $totalEmps ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center border-success">
            <div class="card-body py-2">
                <div class="text-muted small">アカウント有</div>
                <div class="fs-3 fw-bold text-success"><?= $withAccount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center border-warning">
            <div class="card-body py-2">
                <div class="text-muted small">アカウント未作成</div>
                <div class="fs-3 fw-bold text-warning"><?= $withoutAccount ?></div>
            </div>
        </div>
    </div>
</div>

<!-- 社員一覧 -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2"></i>社員一覧</span>
        <input type="text" id="userSearch" class="form-control form-control-sm" style="width:200px" placeholder="検索...">
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>社員番号</th>
                        <th>氏名</th>
                        <th>役職</th>
                        <th>ログインID</th>
                        <th class="text-center">権限</th>
                        <th class="text-center">状態</th>
                        <th class="text-center">最終ログイン</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="userTableBody">
                    <?php foreach ($employees as $emp): ?>
                    <tr data-search="<?= h(($emp['employee_number'] ?? '') . ' ' . $emp['name'] . ' ' . ($emp['username'] ?? '')) ?>">
                        <td class="small text-muted"><?= h($emp['employee_number'] ?? '-') ?></td>
                        <td class="fw-medium"><?= h($emp['name']) ?></td>
                        <td class="small"><?= h($emp['job_title'] ?? '-') ?></td>
                        <?php if ($emp['user_id']): ?>
                        <td><code><?= h($emp['username']) ?></code></td>
                        <td class="text-center">
                            <?php if ($emp['user_role'] === 'company_admin'): ?>
                            <span class="badge bg-warning text-dark">管理者</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">社員</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($emp['user_active']): ?>
                            <span class="badge bg-success">有効</span>
                            <?php else: ?>
                            <span class="badge bg-danger">無効</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small text-muted"><?= $emp['last_login_at'] ? date('m/d H:i', strtotime($emp['last_login_at'])) : '-' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary" onclick="openEditModal(<?= h(json_encode([
                                    'user_id' => $emp['user_id'],
                                    'employee_id' => $emp['emp_id'],
                                    'name' => $emp['name'],
                                    'username' => $emp['username'],
                                    'role' => $emp['user_role'],
                                ])) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="openPasswordModal(<?= $emp['user_id'] ?>, '<?= h($emp['name']) ?>')" title="パスワード変更">
                                    <i class="bi bi-key"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= h($emp['name']) ?>のアカウントを<?= $emp['user_active'] ? '無効化' : '有効化' ?>しますか？')">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $emp['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-<?= $emp['user_active'] ? 'danger' : 'success' ?>" title="<?= $emp['user_active'] ? '無効化' : '有効化' ?>">
                                        <i class="bi bi-<?= $emp['user_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php else: ?>
                        <td><span class="text-muted">未作成</span></td>
                        <td class="text-center">-</td>
                        <td class="text-center">-</td>
                        <td class="text-center">-</td>
                        <td>
                            <button class="btn btn-sm btn-success" onclick="openCreateModal(<?= $emp['emp_id'] ?>, '<?= h($emp['name']) ?>')">
                                <i class="bi bi-plus-circle me-1"></i>作成
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 作成モーダル -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="employee_id" id="createEmpId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>アカウント作成: <span id="createEmpName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ユーザーID <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required pattern="[a-zA-Z0-9_\-]{3,50}" placeholder="半角英数字（3〜50文字）">
                        <div class="form-text">ログインに使用するIDです</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">パスワード <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" id="createPassword" required minlength="6" placeholder="6文字以上">
                            <button type="button" class="btn btn-outline-secondary" onclick="generatePassword('createPassword')"><i class="bi bi-shuffle"></i></button>
                        </div>
                        <div class="form-text">社員に伝えてください。後から変更可能です</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">権限</label>
                        <select name="role" class="form-select">
                            <option value="employee">一般社員</option>
                            <option value="company_admin">会社管理者</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>作成</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 編集モーダル -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="editUserId">
                <input type="hidden" name="employee_id" id="editEmpId">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>アカウント編集: <span id="editEmpName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ユーザーID <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="editUsername" class="form-control" required pattern="[a-zA-Z0-9_\-]{3,50}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">パスワード</label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" id="editPassword" placeholder="変更する場合のみ入力" minlength="6">
                            <button type="button" class="btn btn-outline-secondary" onclick="generatePassword('editPassword')"><i class="bi bi-shuffle"></i></button>
                        </div>
                        <div class="form-text">空欄のままなら現在のパスワードを維持します</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">権限</label>
                        <select name="role" id="editRole" class="form-select">
                            <option value="employee">一般社員</option>
                            <option value="company_admin">会社管理者</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>更新</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- パスワード変更モーダル -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="pwUserId">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>パスワード変更: <span id="pwEmpName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">新しいパスワード <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="new_password" class="form-control" id="pwNewPassword" required minlength="6" placeholder="6文字以上">
                            <button type="button" class="btn btn-outline-secondary" onclick="generatePassword('pwNewPassword')"><i class="bi bi-shuffle me-1"></i>自動生成</button>
                        </div>
                        <div class="form-text">変更後、社員に新しいパスワードを伝えてください</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>パスワード変更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPasswordModal(userId, empName) {
    document.getElementById('pwUserId').value = userId;
    document.getElementById('pwEmpName').textContent = empName;
    document.getElementById('pwNewPassword').value = '';
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
}

function openCreateModal(empId, empName) {
    document.getElementById('createEmpId').value = empId;
    document.getElementById('createEmpName').textContent = empName;
    new bootstrap.Modal(document.getElementById('createModal')).show();
}

function openEditModal(data) {
    document.getElementById('editUserId').value = data.user_id;
    document.getElementById('editEmpId').value = data.employee_id;
    document.getElementById('editEmpName').textContent = data.name;
    document.getElementById('editUsername').value = data.username;
    document.getElementById('editRole').value = data.role;
    document.getElementById('editPassword').value = '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function generatePassword(fieldId) {
    var chars = 'abcdefghijkmnpqrstuvwxyz23456789';
    var pass = '';
    for (var i = 0; i < 10; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
    document.getElementById(fieldId).value = pass;
}

// 検索
document.getElementById('userSearch')?.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#userTableBody tr').forEach(function(tr) {
        var text = (tr.dataset.search || '').toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
