<?php
// ============================================================
// DBベースのセッションハンドラー
// PHPのセッションをファイルではなくDB(app_sessions)へ保存する。
// これにより、Railway等の再デプロイ（コンテナ再作成）でセッションが
// 消えてログアウトされる問題を防ぐ。
// ============================================================

class DBSessionHandler implements SessionHandlerInterface {
    private ?PDO $db = null;

    private function conn(): ?PDO {
        if ($this->db === null) {
            try { $this->db = getDB(); } catch (Throwable $e) { return null; }
        }
        return $this->db;
    }

    public function open($path, $name): bool {
        $db = $this->conn();
        if ($db) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS app_sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    data MEDIUMTEXT NOT NULL,
                    last_activity INT NOT NULL,
                    INDEX idx_last_activity (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (PDOException $e) {}
        }
        return true;
    }

    public function close(): bool { return true; }

    public function read($id): string|false {
        $db = $this->conn();
        if (!$db) return '';
        try {
            $stmt = $db->prepare('SELECT data FROM app_sessions WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetchColumn();
            return $row !== false ? (string)$row : '';
        } catch (PDOException $e) {
            return '';
        }
    }

    public function write($id, $data): bool {
        $db = $this->conn();
        if (!$db) return false;
        try {
            $stmt = $db->prepare('INSERT INTO app_sessions (id, data, last_activity) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)');
            $stmt->execute([$id, $data, time()]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function destroy($id): bool {
        $db = $this->conn();
        if (!$db) return true;
        try {
            $db->prepare('DELETE FROM app_sessions WHERE id = ?')->execute([$id]);
        } catch (PDOException $e) {}
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime): int|false {
        $db = $this->conn();
        if (!$db) return 0;
        try {
            $stmt = $db->prepare('DELETE FROM app_sessions WHERE last_activity < ?');
            $stmt->execute([time() - (int)$max_lifetime]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

/**
 * DBセッションハンドラーを登録する（session_start前に呼ぶ）
 */
function registerDbSessionHandler(): void {
    static $registered = false;
    if ($registered) return;
    if (session_status() !== PHP_SESSION_NONE) return; // 既に開始済みなら何もしない
    try {
        session_set_save_handler(new DBSessionHandler(), true);
        $registered = true;
    } catch (Throwable $e) {
        // 失敗時はデフォルト（ファイル）にフォールバック
    }
}
