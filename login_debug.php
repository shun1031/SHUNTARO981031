<?php
// 一時デバッグファイル - 確認後削除すること
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    echo "DB接続: OK\n";

    $stmt = $db->prepare('SELECT id, username, role, is_active, company_id, CHAR_LENGTH(password_hash) as hash_len, password_hash FROM users WHERE username = ? AND company_id IS NULL AND role = ? AND is_active = 1 LIMIT 1');
    $stmt->execute(['oshino', 'super_admin']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "ユーザー発見: OK\n";
        echo "username: " . $user['username'] . "\n";
        echo "role: " . $user['role'] . "\n";
        echo "is_active: " . $user['is_active'] . "\n";
        echo "company_id: " . var_export($user['company_id'], true) . "\n";
        echo "hash_length: " . $user['hash_len'] . "\n";
        echo "hash_preview: " . substr($user['password_hash'], 0, 20) . "...\n";
        echo "verify(admin123): " . (password_verify('admin123', $user['password_hash']) ? 'TRUE' : 'FALSE') . "\n";
    echo "\n--- 設定値 ---\n";
    echo "BASE_PATH: [" . BASE_PATH . "]\n";
    echo "redirect先: [" . BASE_PATH . "/admin/companies.php]\n";
    } else {
        echo "ユーザーが見つかりません\n";
        $all = $db->query("SELECT id, username, role, is_active, company_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
        echo "全ユーザー: " . json_encode($all, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}
