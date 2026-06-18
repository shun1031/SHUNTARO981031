<?php
// 一時マイグレーション用 - 実行後必ず削除すること
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';

$db = getDB();
echo "=== employees テーブル カラム追加マイグレーション ===\n\n";

$migrations = [
    "ALTER TABLE employees ADD COLUMN employment_type VARCHAR(30) NULL COMMENT '雇用形態（自社/アライアンス）' AFTER is_active",
    "ALTER TABLE employees ADD COLUMN employment_subtype VARCHAR(30) NULL COMMENT '雇用区分（社員/外注/アルバイト）' AFTER employment_type",
    "ALTER TABLE employees ADD COLUMN work_style VARCHAR(30) NULL COMMENT '勤務形態（常勤/イベント）' AFTER employment_subtype",
    "ALTER TABLE employees ADD COLUMN retirement_date DATE NULL COMMENT '退職日' AFTER hire_date",
    "ALTER TABLE employees ADD COLUMN skills_json TEXT NULL COMMENT 'スキル（JSON配列）' AFTER bio",
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

echo "\n完了！このファイルを削除してください: /migrate_employees.php\n";
