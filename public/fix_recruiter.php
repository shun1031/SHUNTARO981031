<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
if (!isAdmin()) { die('管理者のみ'); }
$db  = getDB();
$cid = getCompanyId();
$stmt = $db->prepare("UPDATE sales_cases SET recruiter='' WHERE company_id=? AND recruiter='鈴木真矢'");
$stmt->execute([$cid]);
echo '<p>更新件数: ' . $stmt->rowCount() . '件</p>';
echo '<p style="color:green">鈴木真矢をリクルーターから削除しました。直営業に変更されます。</p>';
echo '<a href="' . BASE_PATH . '/public/sales_regular.php?year=2026&month=5">常勤案件5月へ</a>';
