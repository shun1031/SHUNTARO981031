<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('super_admin', 'company_admin');

$pageTitle = 'バックアップ管理';
$backupDir = __DIR__ . '/../backups';
$message = '';
$messageType = '';

// --- Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'セッションが無効です。再度お試しください。';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        // Create backup now
        if ($action === 'create') {
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0700, true);
            }
            $htaccess = $backupDir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Require all denied\n");
            }

            $date = date('Y-m-d_His');
            $filename = "backup_{$date}.sql";
            $filepath = "{$backupDir}/{$filename}";

            try {
                phpBackup($filepath);
                $size = round(filesize($filepath) / 1024, 1);
                $message = "バックアップを作成しました: {$filename} ({$size} KB)";
                $messageType = 'success';

                // Log
                file_put_contents($backupDir . '/backup.log',
                    date('Y-m-d H:i:s') . " Manual backup: {$filename} ({$size} KB)\n", FILE_APPEND);
            } catch (Exception $e) {
                $message = 'バックアップの作成に失敗しました: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }

        // Restore backup
        if ($action === 'restore') {
            $file = basename($_POST['file'] ?? '');
            $filepath = "{$backupDir}/{$file}";

            if (!$file || !file_exists($filepath) || !preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql(\.gz)?$/', $file)) {
                $message = '無効なバックアップファイルです。';
                $messageType = 'danger';
            } else {
                try {
                    // First create a safety backup before restoring
                    $safetyFile = "{$backupDir}/backup_pre_restore_" . date('Y-m-d_His') . ".sql";
                    phpBackup($safetyFile);

                    // Restore
                    restoreBackup($filepath);
                    $message = "バックアップを復元しました: {$file} (復元前のバックアップ: " . basename($safetyFile) . ")";
                    $messageType = 'success';

                    // Log
                    file_put_contents($backupDir . '/backup.log',
                        date('Y-m-d H:i:s') . " Restored: {$file}\n", FILE_APPEND);
                } catch (Exception $e) {
                    $message = '復元に失敗しました: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }

        // Delete backup
        if ($action === 'delete') {
            $file = basename($_POST['file'] ?? '');
            $filepath = "{$backupDir}/{$file}";

            if (!$file || !file_exists($filepath) || !preg_match('/^backup_.*\.sql(\.gz)?$/', $file)) {
                $message = '無効なファイルです。';
                $messageType = 'danger';
            } else {
                unlink($filepath);
                $message = "削除しました: {$file}";
                $messageType = 'success';

                file_put_contents($backupDir . '/backup.log',
                    date('Y-m-d H:i:s') . " Deleted: {$file}\n", FILE_APPEND);
            }
        }
    }
}

// --- List backups ---
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/backup_*.sql*');
    if ($files) {
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        foreach ($files as $f) {
            $backups[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'date' => filemtime($f),
            ];
        }
    }
}

// Read log
$logContent = '';
$logFile = $backupDir . '/backup.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logContent = implode("\n", array_slice(array_reverse($lines), 0, 50));
}

$csrf = getCsrfToken();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-shield-check me-2"></i>バックアップ管理</h1>
                <p class="text-muted mb-0">データベースの自動バックアップ（毎日0時実行・14日保存）</p>
            </div>
            <form method="post" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>今すぐバックアップ
                </button>
            </form>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i>
        <?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">バックアップ数</div>
                    <div class="fs-3 fw-bold"><?= count($backups) ?><small class="text-muted fs-6">件</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">合計サイズ</div>
                    <div class="fs-3 fw-bold"><?= round(array_sum(array_column($backups, 'size')) / 1024 / 1024, 1) ?><small class="text-muted fs-6"> MB</small></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">最新バックアップ</div>
                    <div class="fs-6 fw-bold">
                        <?= !empty($backups) ? date('Y/m/d H:i', $backups[0]['date']) : '-' ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">保存期間</div>
                    <div class="fs-3 fw-bold">14<small class="text-muted fs-6">日間</small></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup List -->
    <div class="card mb-4">
        <div class="card-header"><strong><i class="bi bi-archive me-1"></i>バックアップ一覧</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ファイル名</th>
                            <th class="text-end">サイズ</th>
                            <th>作成日時</th>
                            <th>経過日数</th>
                            <th style="width:200px" class="text-center">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backups)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">バックアップがありません</td></tr>
                        <?php endif; ?>
                        <?php foreach ($backups as $b):
                            $days = floor((time() - $b['date']) / 86400);
                            $sizeKB = round($b['size'] / 1024, 1);
                            $sizeMB = round($b['size'] / 1024 / 1024, 2);
                            $sizeDisplay = $b['size'] > 1048576 ? "{$sizeMB} MB" : "{$sizeKB} KB";
                        ?>
                        <tr>
                            <td class="fw-medium"><i class="bi bi-file-earmark-zip me-1 text-muted"></i><?= h($b['name']) ?></td>
                            <td class="text-end"><?= $sizeDisplay ?></td>
                            <td><?= date('Y/m/d H:i:s', $b['date']) ?></td>
                            <td>
                                <span class="badge <?= $days > 10 ? 'bg-warning' : 'bg-secondary' ?>"><?= $days ?>日前</span>
                            </td>
                            <td class="text-center">
                                <form method="post" style="display:inline" onsubmit="return confirm('このバックアップを復元しますか？\n現在のデータは復元前に自動バックアップされます。\n\n<?= h($b['name']) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="file" value="<?= h($b['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="復元">
                                        <i class="bi bi-arrow-counterclockwise"></i> 復元
                                    </button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('このバックアップを削除しますか？\n<?= h($b['name']) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="file" value="<?= h($b['name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="削除">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Log -->
    <div class="card mb-3">
        <div class="card-header">
            <strong><i class="bi bi-journal-text me-1"></i>バックアップログ</strong>
            <small class="text-muted ms-2">(最新50件)</small>
        </div>
        <div class="card-body">
            <?php if ($logContent): ?>
            <pre class="mb-0" style="font-size:.8rem;max-height:300px;overflow-y:auto;background:#f8f9fa;padding:1rem;border-radius:8px"><?= h($logContent) ?></pre>
            <?php else: ?>
            <p class="text-muted mb-0">ログはまだありません</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
// --- Helper functions ---

function phpBackup(string $filepath): void
{
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $out = fopen($filepath, 'w');
    if (!$out) {
        throw new Exception('ファイルを作成できません');
    }

    fwrite($out, "-- bMS Database Backup\n");
    fwrite($out, "-- Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database: " . DB_NAME . "\n\n");
    fwrite($out, "SET NAMES utf8mb4;\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($out, $create['Create Table'] . ";\n\n");

        $rows = $db->query("SELECT * FROM `{$table}`");
        $first = true;
        $columns = null;
        $batch = [];
        $batchSize = 0;

        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                $columns = array_keys($row);
                $colList = '`' . implode('`,`', $columns) . '`';
                $first = false;
            }

            $values = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $db->quote($val);
                }
            }
            $batch[] = '(' . implode(',', $values) . ')';
            $batchSize++;

            if ($batchSize >= 500) {
                fwrite($out, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
                $batchSize = 0;
            }
        }

        if (!empty($batch)) {
            fwrite($out, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($out, "\n");
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);
}

function restoreBackup(string $filepath): void
{
    $isGzip = str_ends_with($filepath, '.gz');

    if ($isGzip) {
        $sql = gzdecode(file_get_contents($filepath));
    } else {
        $sql = file_get_contents($filepath);
    }

    if (!$sql) {
        throw new Exception('バックアップファイルを読み込めません');
    }

    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Split by semicolons (handling multi-line statements)
    $statements = preg_split('/;\s*\n/', $sql);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
            continue;
        }
        $db->exec($stmt);
    }

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
}
