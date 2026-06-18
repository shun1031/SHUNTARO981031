<?php
// 会社・管理者ユーザー初期設定スクリプト - 実行後削除
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

// 1. KLG会社の確認・作成
$stmt = $db->prepare('SELECT id, company_name, login_id FROM companies WHERE login_id = ?');
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
$stmt = $db->prepare('SELECT id, username FROM users WHERE username = ? AND company_id = ?');
$stmt->execute(['oshino_admin', $companyId]);
$existing = $stmt->fetch();

if (!$existing) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO users (username, password_hash, display_name, role, company_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())')
       ->execute(['oshino_admin', $hash, '押野俊太郎', 'company_admin', $companyId]);
    echo "会社管理者ユーザー作成: OK\n";
} else {
    echo "会社管理者ユーザー確認: 既存\n";
}

echo "\n=== ログイン情報 ===\n";
echo "URL: /login.php (緑のページ)\n";
echo "ユーザーID: oshino_admin\n";
echo "パスワード: admin123\n";
echo "\n完了後このファイルを削除してください\n";
