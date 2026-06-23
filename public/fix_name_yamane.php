<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
if (!isAdmin()) die('管理者のみ');
$db  = getDB();
$cid = getCompanyId();
$n = 0;
foreach (['sales_rep','manager','recruiter'] as $col) {
    $s = $db->prepare("UPDATE sales_cases SET $col='山根脩平' WHERE company_id=? AND $col='山根'");
    $s->execute([$cid]);
    $n += $s->rowCount();
}
echo "<p>更新: {$n}件 — 「山根」→「山根脩平」に変更しました</p>";
