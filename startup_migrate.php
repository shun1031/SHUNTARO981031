<?php
/**
 * 起動時自動マイグレーション（CLI専用）
 * Dockerfileのスタートアップコマンドから実行される
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/config/env.php';

loadEnv(__DIR__ . '/.env');

$host    = getenv('DB_HOST')    ?: 'localhost';
$dbname  = getenv('DB_NAME')    ?: '';
$user    = getenv('DB_USER')    ?: '';
$pass    = getenv('DB_PASS')    ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    $db = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "[migrate] DB接続失敗: " . $e->getMessage() . PHP_EOL;
    exit(0); // DB接続失敗でもサーバー起動は続行
}

$migrations = [
    // sales_cases: 不足カラム
    "ALTER TABLE sales_cases ADD COLUMN case_name VARCHAR(200) DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN recruitment_count INT DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN carrier VARCHAR(50) DEFAULT NULL AFTER note",
    "ALTER TABLE sales_cases ADD COLUMN new_transactions INT NOT NULL DEFAULT 0 AFTER carrier",
    "ALTER TABLE sales_cases ADD COLUMN negotiations_count INT NOT NULL DEFAULT 0 AFTER new_transactions",
    "ALTER TABLE sales_cases ADD COLUMN contracts_count INT NOT NULL DEFAULT 0 AFTER negotiations_count",

    // sales_daily_reports: 拡張カラム（既存列は Duplicate column エラーを catch で無視）
    "ALTER TABLE sales_daily_reports ADD COLUMN location_type VARCHAR(20) DEFAULT NULL AFTER carrier",
    "ALTER TABLE sales_daily_reports ADD COLUMN work_type VARCHAR(30) DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN mobile_external INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN mobile_change_count INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_provider_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_transfer INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN air_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN air_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN biglobe_hikari INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN commufa_hikari INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN aupay_card INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN au_denki INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN au_smartpass INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN fixed_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN fixed_provider_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN fixed_transfer INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN home_router_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN home_router_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN visit_groups INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN consultation_groups INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN mobile_acquisitions INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN setup_support INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sim_mnp INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sim_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sim_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sim_fixed INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN sim_router INT NOT NULL DEFAULT 0",

    // sales_daily_reports: イベント・ショップ系
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_mnp INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_up INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_down INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_kihenkaku INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_tenyo INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_jihen INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_sb_hikari_1g_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_sb_hikari_1g10 INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_bl_hikari_1g_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_hikari_12g INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_hikari_10g INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_air_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_air_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN ev_air_rental INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN catch_count INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_seated INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_proposals INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_negotiations INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_contracts INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN personal_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN fixed_check_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN fixed_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN event_reflection TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_visits INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_proposals INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_negotiations INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_contracts INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_fixed_check_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN shop_comment TEXT DEFAULT NULL",

    // ---- sales_transport_costs: 申請ステータス ----
    "ALTER TABLE sales_transport_costs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'submitted' COMMENT '申請ステータス'",

    // ---- sales_shifts: 退勤時刻カラム追加 ----
    "ALTER TABLE sales_shifts ADD COLUMN checkout_time VARCHAR(10) DEFAULT NULL COMMENT '退勤実績時刻'",

    // ---- event_plans: 予定案件テーブル ----
    "CREATE TABLE IF NOT EXISTS event_plans (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, client_name VARCHAR(200) NOT NULL, store_name VARCHAR(200) DEFAULT NULL, work_date DATE NOT NULL, required_count INT NOT NULL DEFAULT 1, status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending', linked_case_id INT DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_ep_company (company_id), INDEX idx_ep_date (work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_cases: 予定案件リンク ----
    "ALTER TABLE sales_cases ADD COLUMN plan_id INT DEFAULT NULL COMMENT '予定案件ID'",

    // ---- 会社名 KLG HOLDINGS → LiberTeen ----
    "UPDATE companies SET company_name='LiberTeen' WHERE company_name='KLG HOLDINGS'",

    // ---- company_adminアカウントで employee_id 未リンクのものを同名社員に自動リンク ----
    "UPDATE users u INNER JOIN employees e ON e.company_id = u.company_id AND e.name = u.display_name AND e.is_active = 1 SET u.employee_id = e.id WHERE u.employee_id IS NULL AND u.company_id IS NOT NULL AND u.role = 'company_admin'",

    // ---- sales_change_requests: request_type ENUM に新申請種別を追加 ----
    "ALTER TABLE sales_change_requests MODIFY COLUMN request_type ENUM('shift_change','attendance_change','checkin_change','checkout_change','attendance_add','daily_report_edit','transport_edit') NOT NULL",

    // ---- sales_transport_costs: エビデンスバイナリをDBに保存（Railway永続化対応） ----
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_1 LONGBLOB DEFAULT NULL",
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_2 LONGBLOB DEFAULT NULL",
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_3 LONGBLOB DEFAULT NULL",

    // ---- sales_shifts: 追加稼働フラグ ----
    "ALTER TABLE sales_shifts ADD COLUMN is_additional TINYINT(1) NOT NULL DEFAULT 0 COMMENT '追加稼働フラグ'",

    // ---- salary_additional_payments: 追加支給テーブル ----
    "CREATE TABLE IF NOT EXISTS salary_additional_payments (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, pay_year INT NOT NULL, pay_month INT NOT NULL, worker_name VARCHAR(100) NOT NULL, amount INT NOT NULL DEFAULT 0, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sap (company_id, pay_year, pay_month, worker_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sga_expenses: 販管費管理テーブル ----
    "CREATE TABLE IF NOT EXISTS sga_expenses (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, target_year INT NOT NULL, target_month INT NOT NULL, category VARCHAR(100) NOT NULL, content VARCHAR(255) NOT NULL DEFAULT '', amount BIGINT NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_sga_company_month (company_id, target_year, target_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // ---- sga_expenses: content カラム追加（既存テーブル用） ----
    "ALTER TABLE sga_expenses ADD COLUMN content VARCHAR(255) NOT NULL DEFAULT '' AFTER category",
];

$ok = 0;
$fail = 0;
foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        $ok++;
    } catch (PDOException $e) {
        echo "[migrate] WARN: " . substr($sql, 0, 60) . " => " . $e->getMessage() . PHP_EOL;
        $fail++;
    }
}

echo "[migrate] 完了: {$ok}件成功, {$fail}件スキップ/失敗" . PHP_EOL;
