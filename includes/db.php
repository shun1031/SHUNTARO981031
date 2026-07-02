<?php
// ============================================================
// データベース接続
// ============================================================

require_once __DIR__ . '/../config/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            runDbMigrations($pdo);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die('DB接続エラー: ' . $e->getMessage());
            } else {
                die('データベースに接続できませんでした。設定を確認してください。');
            }
        }
    }
    return $pdo;
}

function runDbMigrations(PDO $pdo): void {
    // checkout_time カラムが存在しない場合に追加
    $cols = $pdo->query("SHOW COLUMNS FROM sales_shifts LIKE 'checkout_time'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE sales_shifts ADD COLUMN checkout_time VARCHAR(10) DEFAULT NULL AFTER checkin_time");
    }
}
