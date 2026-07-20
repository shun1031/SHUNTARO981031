<?php
/**
 * 出発報告 自動送信スケジューラー（CLI常駐）
 * Dockerfileのスタートアップからバックグラウンド起動される。
 *
 * 動作: 1分ごとにシフト管理を確認し、
 *   - 出発報告対象者（departure_report_flag=1）かつメール登録済みのスタッフの
 *   - 休みでないシフトの出勤予定時間（start_time）の30分前になったら
 *   - 出発確認メールを自動送信する（シフトごとに1回のみ）
 *
 * 回答の通知先: DEPARTURE_NOTIFY_EMAIL 環境変数（未設定時は SMTP_USER）
 */
if (php_sapi_name() !== 'cli') { exit(1); }

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/departure_mail.php';

function schedLog(string $msg): void {
    echo '[departure_scheduler] ' . date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
}

function schedConnect(): ?PDO {
    $host    = getenv('DB_HOST')    ?: 'localhost';
    $dbname  = getenv('DB_NAME')    ?: '';
    $user    = getenv('DB_USER')    ?: '';
    $pass    = getenv('DB_PASS')    ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    try {
        return new PDO("mysql:host={$host};dbname={$dbname};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        schedLog('DB接続失敗: ' . $e->getMessage());
        return null;
    }
}

$baseUrl = departureBaseUrl();
schedLog('起動しました (baseUrl=' . $baseUrl . ')');

$db = null;
while (true) {
    try {
        if (!$db) {
            $db = schedConnect();
            if (!$db) { sleep(60); continue; }
        }

        $now      = time();
        $today    = date('Y-m-d', $now);
        $tomorrow = date('Y-m-d', $now + 86400); // 日付をまたぐ深夜シフト対応

        $st = $db->prepare("
            SELECT s.id AS shift_id, s.company_id, s.shift_date, s.start_time,
                   e.id AS emp_id, e.name, e.email
            FROM sales_shifts s
            JOIN employees e ON e.company_id = s.company_id AND e.name = s.employee_name
            WHERE s.shift_date IN (?, ?)
              AND (s.is_day_off IS NULL OR s.is_day_off = 0)
              AND s.start_time IS NOT NULL AND s.start_time != ''
              AND e.is_active = 1
              AND e.departure_report_flag = 1
              AND e.email IS NOT NULL AND e.email != ''
              AND NOT EXISTS (SELECT 1 FROM departure_reports dr WHERE dr.shift_id = s.id)
        ");
        $st->execute([$today, $tomorrow]);

        $adminEmail = getenv('DEPARTURE_NOTIFY_EMAIL') ?: (getenv('SMTP_USER') ?: null);

        foreach ($st->fetchAll() as $row) {
            $startTs = strtotime($row['shift_date'] . ' ' . $row['start_time']);
            if ($startTs === false) continue;
            $diff = $startTs - $now;
            // 出勤予定の30分前〜出勤時刻の間で未送信なら送信
            if ($diff > 0 && $diff <= 1800) {
                $emp = ['id' => (int)$row['emp_id'], 'name' => $row['name'], 'email' => $row['email']];
                $res = sendDepartureReportMail($db, (int)$row['company_id'], $emp, $adminEmail, (int)$row['shift_id'], $baseUrl);
                if ($res['success']) {
                    schedLog('自動送信: ' . $row['name'] . ' (' . $row['email'] . ') シフト ' . $row['shift_date'] . ' ' . $row['start_time']);
                } else {
                    schedLog('送信失敗: ' . $row['name'] . ' → ' . ($res['error'] ?? '不明'));
                }
            }
        }
    } catch (Throwable $e) {
        schedLog('エラー: ' . $e->getMessage());
        $db = null; // 次ループで再接続
    }
    sleep(60);
}
