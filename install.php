<?php
// ============================================================
// 初回インストールスクリプト（実行後は削除してください）
// ============================================================

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== bMS Database Setup ===\n\n";

try {
    // DB接続（データベース指定なし）
    $dsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "DB接続: OK\n";

    // データベース選択
    $pdo->exec('USE `' . DB_NAME . '`');
    echo "データベース選択: " . DB_NAME . " OK\n\n";

    // SQLファイル読み込み
    $sqlFile = __DIR__ . '/sql/schema.sql';
    if (!file_exists($sqlFile)) {
        die("ERROR: schema.sql が見つかりません\n");
    }

    $sql = file_get_contents($sqlFile);
    echo "schema.sql 読み込み: OK (" . strlen($sql) . " bytes)\n\n";

    // SQL分割・実行
    // まずコメントを保持しつつセミコロンで分割
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => !empty($s) && !preg_match('/^\s*(--.*)?\s*$/s', $s)
    );

    echo count($statements) . " statements found\n\n";

    $success = 0;
    $errors  = 0;
    foreach ($statements as $i => $stmt) {
        // コメント行のみのステートメントはスキップ
        $clean = preg_replace('/--.*$/m', '', $stmt);
        $clean = trim($clean);
        if (empty($clean)) continue;

        try {
            $pdo->exec($stmt);
            // テーブル名やINSERTを表示
            if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(\S+)/i', $stmt, $m)) {
                echo "  [CREATE] {$m[1]} ... OK\n";
            } elseif (preg_match('/INSERT INTO\s+(\S+)/i', $stmt, $m)) {
                echo "  [INSERT] {$m[1]} ... OK\n";
            } elseif (preg_match('/ALTER TABLE\s+(\S+)/i', $stmt, $m)) {
                echo "  [ALTER]  {$m[1]} ... OK\n";
            } elseif (preg_match('/UPDATE\s+(\S+)/i', $stmt, $m)) {
                echo "  [UPDATE] {$m[1]} ... OK\n";
            } else {
                echo "  [EXEC]   OK\n";
            }
            $success++;
        } catch (PDOException $e) {
            echo "  [ERROR]  " . substr($e->getMessage(), 0, 120) . "\n";
            $errors++;
        }
    }

    echo "\n=== Summary ===\n";
    echo "Success: {$success}\n";
    echo "Errors:  {$errors}\n";
    echo "\nSetup complete!\n";
    echo "\nダッシュボード: /bms/public/\n";
    echo "管理画面: /bms/admin/login.php (admin / admin123)\n";
    echo "\n※ このファイル (install.php) は削除してください ※\n";

} catch (PDOException $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}
