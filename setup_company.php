<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

// config.phpのみ読み込み（functions.phpは除外して依存を最小化）
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();

// 1. KLG会社の確認・作成
$stmt = $db->prepare('SELECT id, company_name FROM companies WHERE login_id = ?');
$stmt->execute(['KLG']);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    $db->prepare('INSERT INTO companies (login_id, company_name, is_active) VALUES (?, ?, 1)')
       ->execute(['KLG', 'KLG HOLDINGS']);
    $companyId = $db->lastInsertId();
    echo "会社作成: KLG HOLDINGS (id={$companyId})\n";
} else {
    $companyId = $company['id'];
    echo "会社確認: {$company['company_name']} (id={$companyId})\n";
}

// 2. company_adminユーザーの確認・作成
$stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute(['oshino_admin']);
$existing = $stmt->fetch();

if (!$existing) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())')
       ->execute(['oshino_admin', $hash, '押野俊太郎', 'company_admin', $companyId]);
    echo "管理者ユーザー作成: OK\n";
} else {
    // 既存ユーザーのcompany_idを更新
    $db->prepare('UPDATE users SET company_id = ?, role = ?, is_active = 1 WHERE username = ?')
       ->execute([$companyId, 'company_admin', 'oshino_admin']);
    echo "管理者ユーザー更新: OK\n";
}

echo "\n=== ログイン情報 ===\n";
echo "URL: https://shuntaro981031-production.up.railway.app/login.php\n";
echo "ユーザーID: oshino_admin\n";
echo "パスワード: admin123\n";
echo "\nsetup完了！このファイルは削除してください。\n";
