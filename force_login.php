<?php
// 緊急デバッグ用 - 使用後必ず削除
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

startSession();

$db = getDB();
$stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND company_id IS NULL AND role = ? LIMIT 1');
$stmt->execute(['oshino', 'super_admin']);
$user = $stmt->fetch();

if ($user) {
    session_regenerate_id(true);
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['user_role']    = $user['role'];
    $_SESSION['company_id']   = null;
    $_SESSION['employee_id']  = $user['employee_id'] ?? null;
    $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
    $_SESSION['company_name'] = '';
    $_SESSION['company_login_id'] = '';
    $_SESSION['_last_activity'] = time();
    $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    redirect(BASE_PATH . '/admin/companies.php');
} else {
    header('Content-Type: text/plain');
    echo "ユーザーが見つかりません";
}
