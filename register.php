<?php
/**
 * 新規ユーザー登録（単一テナント運用 / KLG専用）
 * 入力されたプロフィールを employees に、ログイン情報を users に登録する。
 * ユーザーIDは自動生成（英数字10文字）。
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

startSession();

$db = getDB();
define('DEFAULT_COMPANY_LOGIN_ID', 'KLG');
$stmt = $db->prepare('SELECT id, company_name, logo_path FROM companies WHERE login_id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([DEFAULT_COMPANY_LOGIN_ID]);
$company = $stmt->fetch();

// PRG: 登録完了後の表示（セッションフラッシュから読む。リロードしても再登録されない）
$successInfo = null;
if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['_register_success'])) {
    $successInfo = $_SESSION['_register_success'];
    unset($_SESSION['_register_success']);
}

function generateUniqueUsername(PDO $db): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $id = '';
        for ($i = 0; $i < 10; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $db->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$id]);
    } while ($check->fetch());
    return $id;
}

$errors = [];
$values = ['name' => '', 'name_kana' => '', 'phone' => '', 'birth_date' => '', 'affiliation_company' => '', 'email' => ''];

if (!$successInfo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = '不正なリクエストです。ページを再読み込みしてから再度お試しください。';
    } elseif (!$company) {
        $errors['general'] = '会社情報が見つかりません。管理者にお問い合わせください。';
    } else {
        $values['name']       = trim($_POST['name'] ?? '');
        $values['name_kana']  = trim($_POST['name_kana'] ?? '');
        $values['phone']      = trim($_POST['phone'] ?? '');
        $values['birth_date'] = trim($_POST['birth_date'] ?? '');
        $values['affiliation_company'] = trim($_POST['affiliation_company'] ?? '');
        $values['email']      = trim($_POST['email'] ?? '');
        $password             = $_POST['password'] ?? '';

        if ($values['name'] === '') {
            $errors['name'] = '氏名を入力してください';
        }
        if ($values['name_kana'] === '') {
            $errors['name_kana'] = 'フリガナを入力してください';
        }
        if ($values['phone'] === '') {
            $errors['phone'] = '電話番号を入力してください';
        }

        // 生年月日: 数字8文字（YYYYMMDD）必須
        if ($values['birth_date'] === '') {
            $errors['birth_date'] = '生年月日を入力してください';
        } elseif (!preg_match('/^\d{8}$/', $values['birth_date'])) {
            $errors['birth_date'] = '生年月日は数字8文字（例: 19900101）で入力してください';
        } else {
            $y = (int)substr($values['birth_date'], 0, 4);
            $m = (int)substr($values['birth_date'], 4, 2);
            $d = (int)substr($values['birth_date'], 6, 2);
            if (!checkdate($m, $d, $y)) {
                $errors['birth_date'] = '生年月日が正しい日付ではありません';
            }
        }

        // 所属会社: 必須
        if ($values['affiliation_company'] === '') {
            $errors['affiliation_company'] = '所属会社を入力してください';
        }

        // メールアドレス: 必須 + gmailのみ
        if ($values['email'] === '') {
            $errors['email'] = 'メールアドレスを入力してください';
        } elseif (!preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $values['email'])) {
            $errors['email'] = 'メールアドレスはGmail（@gmail.com）の形式で入力してください';
        }

        // パスワード: 必須 + 英数字8文字以上
        if ($password === '') {
            $errors['password'] = 'パスワードを入力してください';
        } elseif (!preg_match('/^[A-Za-z0-9]{8,}$/', $password)) {
            $errors['password'] = 'パスワードは英数字8文字以上で入力してください';
        }

        // メール重複チェック（同一会社内）
        if (!isset($errors['email'])) {
            $dup = $db->prepare('SELECT id FROM employees WHERE company_id = ? AND email = ?');
            $dup->execute([$company['id'], $values['email']]);
            if ($dup->fetch()) {
                $errors['email'] = 'このメールアドレスは既に登録されています';
            }
        }

        if (!$errors) {
            try {
                $db->beginTransaction();

                $birthDateForDb = substr($values['birth_date'], 0, 4) . '-' . substr($values['birth_date'], 4, 2) . '-' . substr($values['birth_date'], 6, 2);

                $stmt = $db->prepare('INSERT INTO employees (company_id, name, name_kana, phone, birth_date, affiliation_company, email, is_active) VALUES (?,?,?,?,?,?,?,1)');
                $stmt->execute([$company['id'], $values['name'], $values['name_kana'], $values['phone'], $birthDateForDb, $values['affiliation_company'], $values['email']]);
                $employeeId = (int)$db->lastInsertId();

                $username = generateUniqueUsername($db);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt2 = $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, employee_id, is_active) VALUES (?,?,?,?,?,?,1)');
                $stmt2->execute([$username, $hash, $values['name'], 'employee', $company['id'], $employeeId]);

                $db->commit();

                $_SESSION['_register_success'] = ['username' => $username, 'name' => $values['name']];
                redirect(BASE_PATH . '/register.php?done=1');
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $errors['general'] = '登録に失敗しました。しばらくしてから再度お試しください。';
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
    <title>新規ユーザー登録 | <?= h(APP_NAME) ?></title>
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
        .login-wrapper { position: relative; z-index: 1; width: 100%; max-width: 520px; padding: 20px; }
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
        .input-group-text { border-radius: 8px 0 0 8px; background: #f9fafb; border: 1px solid #e5e7eb; color: #6b7280; font-size: .875rem; }
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

<?php if ($successInfo): ?>
            <!-- ===== 登録完了 ===== -->
            <div class="text-center mb-3">
                <div class="login-brand-icon mx-auto"><i class="bi bi-check-lg"></i></div>
                <h2 class="mt-3 mb-1 fw-bold" style="font-size:1.2rem;color:#111827">登録が完了しました</h2>
                <p class="text-muted mt-1" style="font-size:.8rem"><?= h($successInfo['name']) ?>さん、ご登録ありがとうございます。</p>
            </div>
            <div class="generated-id-box">
                <div class="text-muted small mb-1">あなたのユーザーID</div>
                <div class="id-value"><?= h($successInfo['username']) ?></div>
            </div>
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i>このユーザーIDは今後のログインに必要です。必ず控えてください。
            </div>
            <a href="<?= h(BASE_PATH) ?>/login.php" class="btn btn-success w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-2"></i>ログイン画面へ
            </a>

<?php else: ?>
            <!-- ===== 登録フォーム ===== -->
            <div class="text-center mb-3">
                <div class="login-brand-icon mx-auto"><i class="bi bi-person-plus-fill"></i></div>
                <h2 class="mt-2 mb-1 fw-bold" style="font-size:1.2rem;color:#111827">新規ユーザー登録</h2>
                <p class="text-muted mt-1" style="font-size:.8rem">以下の項目を入力してください</p>
            </div>

            <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger py-2 small"><?= h($errors['general']) ?></div>
            <?php endif; ?>

            <form method="POST" action="<?= h(BASE_PATH) ?>/register.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                <div class="row g-3 mb-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">氏名</label>
                        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= h($values['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="field-error"><?= h($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">フリガナ</label>
                        <input type="text" name="name_kana" class="form-control <?= isset($errors['name_kana']) ? 'is-invalid' : '' ?>"
                               value="<?= h($values['name_kana']) ?>" required>
                        <?php if (isset($errors['name_kana'])): ?><div class="field-error"><?= h($errors['name_kana']) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-1 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">電話番号</label>
                        <input type="tel" name="phone" class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                               value="<?= h($values['phone']) ?>" placeholder="090-1234-5678" required>
                        <?php if (isset($errors['phone'])): ?><div class="field-error"><?= h($errors['phone']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">生年月日</label>
                        <input type="text" name="birth_date" class="form-control <?= isset($errors['birth_date']) ? 'is-invalid' : '' ?>"
                               value="<?= h($values['birth_date']) ?>" inputmode="numeric" maxlength="8" placeholder="例: 19900101" required>
                        <?php if (isset($errors['birth_date'])): ?><div class="field-error"><?= h($errors['birth_date']) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="mb-1 mt-2">
                    <label class="form-label fw-semibold">所属会社</label>
                    <input type="text" name="affiliation_company" class="form-control <?= isset($errors['affiliation_company']) ? 'is-invalid' : '' ?>"
                           value="<?= h($values['affiliation_company']) ?>" maxlength="150" required>
                    <div class="form-text" style="font-size:.75rem"><i class="bi bi-info-circle me-1"></i>正式名称で入力してください。例　株式会社LiberTeen</div>
                    <?php if (isset($errors['affiliation_company'])): ?><div class="field-error"><?= h($errors['affiliation_company']) ?></div><?php endif; ?>
                </div>

                <div class="mb-1 mt-2">
                    <label class="form-label fw-semibold">メールアドレス</label>
                    <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= h($values['email']) ?>" placeholder="example@gmail.com" required>
                    <?php if (isset($errors['email'])): ?><div class="field-error"><?= h($errors['email']) ?></div><?php endif; ?>
                </div>

                <div class="mb-3 mt-2">
                    <label class="form-label fw-semibold">パスワード</label>
                    <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           placeholder="英数字8文字以上" required>
                    <?php if (isset($errors['password'])): ?><div class="field-error"><?= h($errors['password']) ?></div><?php endif; ?>
                </div>

                <div class="alert alert-secondary py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>ユーザーIDは登録完了後に自動で発行されます。
                </div>

                <button type="submit" class="btn btn-success w-100 py-2">
                    <i class="bi bi-person-plus me-2"></i>登録する
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
