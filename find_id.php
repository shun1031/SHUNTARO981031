<?php
/**
 * ユーザーID確認（本人確認: メールアドレス + 生年月日）
 * メール送信機能がないため、本人確認後にその場でユーザーIDを画面表示する。
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

startSession();

$db = getDB();
define('DEFAULT_COMPANY_LOGIN_ID', 'KLG');
$stmt = $db->prepare('SELECT id FROM companies WHERE login_id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([DEFAULT_COMPANY_LOGIN_ID]);
$company = $stmt->fetch();

// --- 試行回数制限（15分で10回まで。メール換えての総当たり対策） ---
function checkFindIdRateLimit(string $key): bool {
    startSession();
    $now = time();
    $window = 900;
    $maxAttempts = 10;
    $attempts = $_SESSION['_findid_attempts'][$key] ?? [];
    $attempts = array_values(array_filter($attempts, fn($t) => ($now - $t) < $window));
    if (count($attempts) >= $maxAttempts) {
        $_SESSION['_findid_attempts'][$key] = $attempts;
        return false;
    }
    $attempts[] = $now;
    $_SESSION['_findid_attempts'][$key] = $attempts;
    return true;
}

$errors = [];
$values = ['email' => '', 'birth_date' => ''];
$found = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = '不正なリクエストです。ページを再読み込みしてから再度お試しください。';
    } elseif (!$company) {
        $errors['general'] = '会社情報が見つかりません。管理者にお問い合わせください。';
    } else {
        $values['email']      = trim($_POST['email'] ?? '');
        $values['birth_date'] = trim($_POST['birth_date'] ?? '');

        if ($values['email'] === '') {
            $errors['email'] = 'メールアドレスを入力してください';
        }
        if ($values['birth_date'] === '') {
            $errors['birth_date'] = '生年月日を入力してください';
        } elseif (!preg_match('/^\d{8}$/', $values['birth_date'])) {
            $errors['birth_date'] = '生年月日は数字8文字（例: 19900101）で入力してください';
        }

        if (!$errors) {
            $rateLimitKey = 'findid_' . strtolower($values['email']);
            if (!checkFindIdRateLimit($rateLimitKey)) {
                $errors['general'] = '試行回数が上限を超えました。15分後に再度お試しください。';
            } else {
                $birthDateForDb = substr($values['birth_date'], 0, 4) . '-' . substr($values['birth_date'], 4, 2) . '-' . substr($values['birth_date'], 6, 2);

                $stmt = $db->prepare('
                    SELECT u.username, e.name
                    FROM employees e
                    JOIN users u ON u.employee_id = e.id
                    WHERE e.company_id = ? AND e.email = ? AND e.birth_date = ? AND e.is_active = 1 AND u.is_active = 1
                    LIMIT 1
                ');
                $stmt->execute([$company['id'], $values['email'], $birthDateForDb]);
                $row = $stmt->fetch();

                if ($row) {
                    $found = $row;
                } else {
                    // メールが存在するかどうかは明かさない汎用エラー
                    $errors['general'] = '入力内容と一致するユーザーが見つかりませんでした。メールアドレスと生年月日をご確認ください。';
                }
            }
        }
    }
}

$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザーID確認 | <?= h(APP_NAME) ?></title>
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
        .login-wrapper { position: relative; z-index: 1; width: 100%; max-width: 440px; padding: 20px; }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 20px 60px rgba(0,0,0,.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .login-card-inner { padding: 2rem 2rem 1.5rem; }
        .login-top-accent { height: 3px; background: linear-gradient(135deg, #059669, #34d399); }
        .form-control { border-radius: 8px; padding: 10px 14px; font-size: .875rem; border: 1px solid #e5e7eb; transition: all .2s ease; }
        .form-control:focus { border-color: #34d399; box-shadow: 0 0 0 3px rgba(5,150,105,.08); }
        .form-control.is-invalid { border-color: #ef4444; }
        .form-label { font-size: .8rem; font-weight: 500; color: #374151; }
        .field-error { font-size: .72rem; color: #dc2626; margin-top: 4px; }
        .btn-success { background: #059669; border: none; border-radius: 10px; padding: 10px; font-weight: 500; font-size: .9rem; }
        .btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(5,150,105,.2); }
        .alert { border-radius: 10px; font-size: .8rem; }
        .login-brand-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #059669, #34d399); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: 0 4px 14px rgba(5,150,105,.2); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .login-card { animation: fadeIn .4s ease both; }
        .generated-id-box {
            background: #ecfdf5; border: 1.5px dashed #059669; border-radius: 12px;
            padding: 16px; text-align: center; margin-bottom: 1rem;
        }
        .generated-id-box .id-value { font-size: 1.4rem; font-weight: 700; color: #047857; letter-spacing: .05em; }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-top-accent"></div>
        <div class="login-card-inner">

<?php if ($found): ?>
            <!-- ===== 確認結果 ===== -->
            <div class="text-center mb-3">
                <div class="login-brand-icon mx-auto"><i class="bi bi-check-lg"></i></div>
                <h2 class="mt-3 mb-1 fw-bold" style="font-size:1.2rem;color:#111827">本人確認できました</h2>
                <p class="text-muted mt-1" style="font-size:.8rem"><?= h($found['name']) ?>さんのユーザーIDです。</p>
            </div>
            <div class="generated-id-box">
                <div class="text-muted small mb-1">あなたのユーザーID</div>
                <div class="id-value"><?= h($found['username']) ?></div>
            </div>
            <a href="<?= h(BASE_PATH) ?>/login.php" class="btn btn-success w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>ログイン画面へ
            </a>

<?php else: ?>
            <!-- ===== 確認フォーム ===== -->
            <div class="text-center mb-3">
                <div class="login-brand-icon mx-auto"><i class="bi bi-question-circle-fill"></i></div>
                <h2 class="mt-2 mb-1 fw-bold" style="font-size:1.2rem;color:#111827">ユーザーID確認</h2>
                <p class="text-muted mt-1" style="font-size:.8rem">登録時のメールアドレスと生年月日を入力してください</p>
            </div>

            <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger py-2 small"><?= h($errors['general']) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= h(BASE_PATH) ?>/find_id.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">メールアドレス</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= h($values['email']) ?>" placeholder="example@gmail.com" required autofocus>
                    <?php if (isset($errors['email'])): ?><div class="field-error"><?= h($errors['email']) ?></div><?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">生年月日</label>
                    <input type="text" name="birth_date" class="form-control <?= isset($errors['birth_date']) ? 'is-invalid' : '' ?>"
                           value="<?= h($values['birth_date']) ?>" inputmode="numeric" maxlength="8" placeholder="例: 19900101" required>
                    <?php if (isset($errors['birth_date'])): ?><div class="field-error"><?= h($errors['birth_date']) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-success w-100 py-2">
                    <i class="bi bi-search me-2"></i>ユーザーIDを確認する
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="<?= h(BASE_PATH) ?>/login.php" class="text-muted small text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i>ログイン画面に戻る
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
