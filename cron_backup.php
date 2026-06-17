<?php
/**
 * Daily Database Backup Script
 * - Run via cron at 0:00 daily
 * - Retention: 14 days (auto-delete older backups)
 * - Usage: php /path/to/cron_backup.php
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/config/config.php';

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0700, true);
}

// Protect backups directory
$htaccess = $backupDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n");
}

$indexFile = $backupDir . '/index.php';
if (!file_exists($indexFile)) {
    file_put_contents($indexFile, "<?php http_response_code(403); exit;\n");
}

// --- Create backup ---
$date = date('Y-m-d_His');
$filename = "backup_{$date}.sql.gz";
$filepath = "{$backupDir}/{$filename}";

// Use mysqldump
$cmd = sprintf(
    'mysqldump --host=%s --user=%s --password=%s --default-character-set=utf8mb4 --single-transaction --routines --triggers %s 2>&1 | gzip > %s',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME),
    escapeshellarg($filepath)
);

exec($cmd, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($filepath) || filesize($filepath) < 100) {
    // mysqldump failed — try PHP-based backup
    $filepath = str_replace('.sql.gz', '.sql', $filepath);
    $filename = str_replace('.sql.gz', '.sql', $filename);
    phpBackup($filepath);
}

// Log
$size = file_exists($filepath) ? round(filesize($filepath) / 1024, 1) : 0;
$logMsg = date('Y-m-d H:i:s') . " Backup created: {$filename} ({$size} KB)\n";
file_put_contents($backupDir . '/backup.log', $logMsg, FILE_APPEND);
echo $logMsg;

// --- Delete old backups (older than 14 days) ---
$retentionDays = 14;
$files = glob($backupDir . '/backup_*.sql*');
$deleted = 0;
foreach ($files as $file) {
    if (filemtime($file) < strtotime("-{$retentionDays} days")) {
        unlink($file);
        $deleted++;
    }
}
if ($deleted > 0) {
    $logMsg = date('Y-m-d H:i:s') . " Deleted {$deleted} old backup(s)\n";
    file_put_contents($backupDir . '/backup.log', $logMsg, FILE_APPEND);
    echo $logMsg;
}

echo "Done.\n";

// --- PHP-based backup fallback ---
function phpBackup(string $filepath): void
{
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $out = fopen($filepath, 'w');
    fwrite($out, "-- bMS Database Backup\n");
    fwrite($out, "-- Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database: " . DB_NAME . "\n\n");
    fwrite($out, "SET NAMES utf8mb4;\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // CREATE TABLE
        $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($out, $create['Create Table'] . ";\n\n");

        // INSERT data
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
