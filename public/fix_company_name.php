<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
if (!isAdmin()) die('管理者のみ');
$db  = getDB();
$cid = getCompanyId();
$stmt = $db->prepare("UPDATE companies SET company_name='LiberTeen' WHERE id=?");
$stmt->execute([$cid]);
echo '<p style="color:green">会社名を「LiberTeen」に更新しました（' . $stmt->rowCount() . '件）</p>';
echo '<p>再ログインすると反映されます。</p>';
echo '<a href="' . BASE_PATH . '/logout.php">ログアウトして確認</a>';
