<?php
// 一時マイグレーション用 - 実行後必ず削除すること
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();
echo "=== sales_cases テーブル カラム追加マイグレーション ===\n\n";

$migrations = [
    "ALTER TABLE sales_cases ADD COLUMN carrier VARCHAR(50) NULL COMMENT 'キャリア' AFTER note",
    "ALTER TABLE sales_cases ADD COLUMN new_transactions INT NOT NULL DEFAULT 0 COMMENT '新規件数' AFTER carrier",
    "ALTER TABLE sales_cases ADD COLUMN negotiations_count INT NOT NULL DEFAULT 0 COMMENT '商談件数' AFTER new_transactions",
    "ALTER TABLE sales_cases ADD COLUMN contracts_count INT NOT NULL DEFAULT 0 COMMENT '契約件数' AFTER negotiations_count",
];

foreach ($migrations as $sql) {
    preg_match('/ADD COLUMN (\w+)/', $sql, $m);
    $col = $m[1] ?? '?';
    try {
        $db->exec($sql);
        echo "OK: $col を追加しました\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "SKIP: $col は既に存在します\n";
        } else {
            echo "ERROR: $col - " . $e->getMessage() . "\n";
        }
    }
}

echo "\n完了！このファイルを削除してください: /migrate_sales_cases.php\n";
