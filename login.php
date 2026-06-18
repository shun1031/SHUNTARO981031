<?php
/**
 * ログイン（単一テナント運用 / KLG専用）
 * 通常: 個人ログイン（会社IDはKLG固定、会社ID入力ステップは廃止）
 * SA用: ?admin=1
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

// キャッシュ防止
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

startSession();

// ログイン済みならリダイレクト（POST時はスキップ＝再認証を許可）
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'super_admin') {
        redirect(BASE_PATH . '/admin/companies.php');
    } elseif ($role === 'employee') {
        redirect(BASE_PATH . '/employee/dashboard.php');
    } else {
        redirect(BASE_PATH . '/public/sales_dashboard.php');
    }
}

$db = getDB();
$error = '';
$step = 'personal'; // 単一テナント運用（会社ID入力ステップは廃止）

// 単一テナント運用: ログイン対象の会社IDを固定（KLG専用）
define('DEFAULT_COMPANY_LOGIN_ID', 'KLG');

// --- ログイン試行回数制限 ---
function checkLoginRateLimit(string $key): bool {
    startSession();
    $now = time();
    $window = 900; // 15分
    $maxAttempts = 10;
    $attempts = $_SESSION['_login_attempts'][$key] ?? [];
    // 期限切れの試行を削除（キーを再インデックス）
    $attempts = array_values(array_filter($attempts, fn($t) => ($now - $t) < $window));
    if (count($attempts) >= $maxAttempts) {
        $_SESSION['_login_attempts'][$key] = $attempts;
        return false; // ブロック
    }
    $attempts[] = $now;
    $_SESSION['_login_attempts'][$key] = $attempts;
    return true;
}

function clearLoginRateLimit(string $key): void {
    startSession();
    unset($_SESSION['_login_attempts'][$key]);
}

// --- SA管理者ログインモード ---
if (isset($_GET['admin'])) {
    $step = 'admin';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rateLimitKey = 'admin_' . $username;

        if (!checkLoginRateLimit($rateLimitKey)) {
            $error = 'ログイン試行回数が上限を超えました。15分後に再度お試しください。';
        } elseif ($username && $password) {
            $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND company_id IS NULL AND role = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$username, 'super_admin']);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                clearLoginRateLimit($rateLimitKey);
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['user_role']    = $user['role'];
                $_SESSION['company_id']   = null;
                $_SESSION['employee_id']  = $user['employee_id'];
                $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
                $_SESSION['company_name'] = '';
                $_SESSION['company_login_id'] = '';
                $_SESSION['_last_activity'] = time();
                $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
                redirect(BASE_PATH . '/admin/companies.php');
            } else {
                $error = 'ユーザーIDまたはパスワードが正しくありません';
            }
        } else {
            $error = '入力してください';
        }
    }

// --- 個人ログイン（単一テナント運用: 会社IDはKLG固定） ---
} else {
    $companyLoginId = DEFAULT_COMPANY_LOGIN_ID;
    $stmt = $db->prepare('SELECT id, company_name, login_id, logo_path FROM companies WHERE login_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$companyLoginId]);
    $company = $stmt->fetch();

    $step = 'personal';

    if (!$company) {
        // 会社レコードが見つからない場合も画面は表示し、ログインは失敗させる
        $company = ['id' => 0, 'company_name' => APP_NAME, 'login_id' => $companyLoginId, 'logo_path' => ''];
        $error = '会社情報が見つかりません。管理者にお問い合わせください。';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $rateLimitKey = 'user_' . $company['id'] . '_' . $username;

        if (!checkLoginRateLimit($rateLimitKey)) {
            $error = 'ログイン試行回数が上限を超えました。15分後に再度お試しください。';
        } elseif ($username && $password) {
            // 会社IDでスコープしてユーザー検索
            $stmt = $db->prepare('SELECT u.* FROM users u WHERE u.username = ? AND u.company_id = ? AND u.is_active = 1 LIMIT 1');
            $stmt->execute([$username, $company['id']]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                clearLoginRateLimit($rateLimitKey);
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['user_role']    = $user['role'];
                $_SESSION['company_id']   = $user['company_id'];
                $_SESSION['employee_id']  = $user['employee_id'];
                $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
                $_SESSION['company_name'] = $company['company_name'];
                $_SESSION['company_login_id'] = $company['login_id'];
                $_SESSION['company_logo'] = $company['logo_path'] ?? '';
                $_SESSION['_last_activity'] = time();
                $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

                if ($user['role'] === 'employee') {
                    redirect(BASE_PATH . '/employee/dashboard.php');
                } else {
                    redirect(BASE_PATH . '/public/sales_dashboard.php');
                }
            } else {
                $error = 'ユーザーIDまたはパスワードが正しくありません';
            }
        } else {
            $error = '入力してください';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #f9fafb;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: 'Noto Sans JP', -apple-system, sans-serif;
            margin: 0;
            position: relative;
        }
        body::before {
            content: ''; position: fixed; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(ellipse at 20% 50%, rgba(5,150,105,.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 20%, rgba(52,211,153,.04) 0%, transparent 50%);
            z-index: 0;
        }
        .login-wrapper { position: relative; z-index: 1; width: 100%; max-width: 420px; padding: 20px; }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 20px 60px rgba(0,0,0,.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .login-card-inner { padding: 2rem 2rem 1.5rem; }
        .login-top-accent { height: 3px; background: linear-gradient(135deg, #059669, #34d399); }
        .company-badge {
            background: linear-gradient(135deg, #059669, #34d399);
            color: #fff; border-radius: 10px; padding: 8px 16px;
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 0.85rem; font-weight: 500;
            box-shadow: 0 4px 14px rgba(5,150,105,.2);
        }
        .step-indicator { display: flex; gap: 8px; justify-content: center; margin-bottom: 1.5rem; }
        .step-dot { width: 32px; height: 4px; border-radius: 4px; background: #e5e7eb; transition: all .3s ease; }
        .step-dot.active { background: linear-gradient(135deg, #059669, #34d399); width: 48px; }
        .step-dot.done { background: #10b981; }
        .form-control { border-radius: 8px; padding: 10px 14px; font-size: .875rem; border: 1px solid #e5e7eb; transition: all .2s ease; }
        .form-control:focus { border-color: #34d399; box-shadow: 0 0 0 3px rgba(5,150,105,.08); }
        .input-group-text { border-radius: 8px 0 0 8px; background: #f9fafb; border: 1px solid #e5e7eb; color: #6b7280; font-size: .875rem; }
        .form-label { font-size: .8rem; font-weight: 500; color: #374151; }
        .btn-primary { background: #111827; border: none; border-radius: 10px; padding: 10px; font-weight: 500; font-size: .9rem; transition: all .2s ease; }
        .btn-primary:hover { background: #1f2937; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(17,24,39,.15); }
        .btn-danger { background: #ef4444; border: none; border-radius: 10px; padding: 10px; font-weight: 500; font-size: .9rem; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239,68,68,.2); }
        .btn-success { background: #059669; border: none; border-radius: 10px; padding: 10px; font-weight: 500; font-size: .9rem; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(5,150,105,.2); }
        .alert { border-radius: 10px; font-size: .8rem; }
        .login-brand { display: flex; align-items: center; gap: 8px; justify-content: center; margin-bottom: 4px; }
        .login-brand-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #059669, #34d399); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1rem; box-shadow: 0 4px 14px rgba(5,150,105,.2); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .login-card { animation: fadeIn .4s ease both; }
    </style>
</head>
<body>
<div class="login-wrapper">
            <div class="login-card">
                <div class="login-top-accent"></div>
                <div class="login-card-inner">

<?php if ($step === 'personal'): ?>
                <!-- ===== 個人ログイン ===== -->
                <div class="text-center mb-3">
                    <?php if (!empty($company['logo_path'])): ?>
                    <img src="<?= h(BASE_PATH) ?>/<?= h($company['logo_path']) ?>" alt="<?= h($company['company_name']) ?>" style="max-height:50px;max-width:180px;object-fit:contain;margin-bottom:12px">
                    <?php else: ?>
                    <div class="login-brand-icon mx-auto" style="width:44px;height:44px;font-size:1.2rem;border-radius:12px"><i class="bi bi-person-fill"></i></div>
                    <?php endif; ?>
                    <h2 class="mt-2 mb-1 fw-bold" style="font-size:1.2rem;color:#111827"><?= h($company['company_name']) ?></h2>
                    <p class="text-muted mt-1" style="font-size:.8rem">ユーザーIDとパスワードを入力してください</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= h(BASE_PATH) ?>/login.php">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">ユーザーID</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="username" class="form-control" required autofocus
                                   value="<?= h($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-semibold">パスワード</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success w-100 py-2">
                        <i class="bi bi-box-arrow-in-right me-2"></i>ログイン
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="<?= h(BASE_PATH) ?>/find_id.php" class="text-muted small text-decoration-none">
                        <i class="bi bi-question-circle me-1"></i>ユーザーIDを忘れた方はこちら
                    </a>
                </div>

                <div class="text-center mt-3">
                    <a href="<?= h(BASE_PATH) ?>/register.php" class="btn btn-outline-success w-100 py-2">
                        <i class="bi bi-person-plus me-2"></i>新規ユーザー登録
                    </a>
                </div>

                <div class="text-center mt-4">
                    <a href="?admin=1" class="text-muted small text-decoration-none">
                        <i class="bi bi-shield-lock me-1"></i>システム管理者はこちら
                    </a>
                </div>

<?php elseif ($step === 'admin'): ?>
                <!-- ===== SA管理者ログイン ===== -->
                <div class="text-center mb-4">
                    <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1.2rem;box-shadow:0 4px 14px rgba(239,68,68,.2)"><i class="bi bi-shield-lock-fill"></i></div>
                    <h2 class="mt-3 mb-0 fw-bold" style="font-size:1.3rem;color:#111827">システム管理者</h2>
                    <p class="text-muted mt-1" style="font-size:.8rem"><?= h(APP_NAME) ?></p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= h(BASE_PATH) ?>/login.php?admin=1">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">管理者ID</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-gear"></i></span>
                            <input type="text" name="username" class="form-control" required autofocus
                                   value="<?= h($_POST['username'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-semibold">パスワード</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-danger w-100 py-2">
                        <i class="bi bi-shield-check me-2"></i>管理者ログイン
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="<?= BASE_PATH ?>/login.php" class="text-muted small text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>会社ログインに戻る
                    </a>
                </div>

<?php endif; ?>

                </div>
            </div>
            <div class="text-center mt-3">
                <small style="color:#9ca3af;font-size:.65rem">Powered by bMS</small>
            </div>
</div>
</body>
</html>
