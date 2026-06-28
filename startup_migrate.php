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
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS case_name VARCHAR(200) DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS recruitment_count INT DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS carrier VARCHAR(50) DEFAULT NULL AFTER note",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS new_transactions INT NOT NULL DEFAULT 0 AFTER carrier",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS negotiations_count INT NOT NULL DEFAULT 0 AFTER new_transactions",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS contracts_count INT NOT NULL DEFAULT 0 AFTER negotiations_count",

    // sales_daily_reports: 既存 ALTER (IF NOT EXISTS で冪等化)
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS location_type VARCHAR(20) DEFAULT NULL AFTER carrier",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS work_type VARCHAR(30) DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS mobile_external INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS mobile_change_count INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sb_hikari_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sb_hikari_provider_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sb_hikari_transfer INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS air_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS air_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS biglobe_hikari INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS commufa_hikari INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS aupay_card INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS au_denki INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS au_smartpass INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS fixed_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS fixed_provider_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS fixed_transfer INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS home_router_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS home_router_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS visit_groups INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS consultation_groups INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS mobile_acquisitions INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS setup_support INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sim_mnp INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sim_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sim_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sim_fixed INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS sim_router INT NOT NULL DEFAULT 0",

    // sales_daily_reports: イベント・ショップ系
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_mnp INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_up INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_down INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_kihenkaku INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_tenyo INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_jihen INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_sb_hikari_1g_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_sb_hikari_1g10 INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_bl_hikari_1g_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_hikari_12g INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_hikari_10g INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_air_new INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_air_change INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS ev_air_rental INT NOT NULL DEFAULT 0",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS catch_count INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_seated INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_proposals INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_negotiations INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_contracts INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS personal_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS fixed_check_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS fixed_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS event_reflection TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_visits INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_proposals INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_negotiations INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_contracts INT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_acquisition_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_fixed_check_detail TEXT DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS shop_comment TEXT DEFAULT NULL",

    // ---- sales_transport_costs: 申請ステータス ----
    "ALTER TABLE sales_transport_costs ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'submitted' COMMENT '申請ステータス'",

    // ---- event_plans: 予定案件テーブル ----
    "CREATE TABLE IF NOT EXISTS event_plans (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, client_name VARCHAR(200) NOT NULL, store_name VARCHAR(200) DEFAULT NULL, work_date DATE NOT NULL, required_count INT NOT NULL DEFAULT 1, status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending', linked_case_id INT DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_ep_company (company_id), INDEX idx_ep_date (work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_cases: 予定案件リンク ----
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS plan_id INT DEFAULT NULL COMMENT '予定案件ID'",
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
