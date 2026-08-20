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

    // ---- salary_additional_payments: 1人につき複数明細を登録できるようにする ----
    // 旧: UNIQUE(company_id, pay_year, pay_month, worker_name) で1人1件のみ
    // 新: 制約を外して複数行を許可（既存データは1件目の明細としてそのまま残る）
    "ALTER TABLE salary_additional_payments DROP INDEX uk_sap",
    "ALTER TABLE salary_additional_payments ADD INDEX idx_sap (company_id, pay_year, pay_month, worker_name)",

    // ---- sales_prev_year_revenues: 前年同月売上の手入力テーブル ----
    // 案件データが無い過去月の売上を記録し、前年同月比の算出に使う（案件データは作らない）
    "CREATE TABLE IF NOT EXISTS sales_prev_year_revenues (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, case_type VARCHAR(20) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, revenue BIGINT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_spyr (company_id, case_type, year, month), INDEX idx_spyr (company_id, year, month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // 2024-2025年度 5〜7月の実績（案件データが無いため手入力値として登録）
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'regular', 2025, 5, 16943313 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'event',   2025, 5,  9372500 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'regular', 2025, 6, 20055184 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'event',   2025, 6, 11851500 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'regular', 2025, 7, 17839699 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue) SELECT DISTINCT company_id, 'event',   2025, 7, 12890700 FROM sales_cases",

    // ---- sales_cases: キャリアの表記ゆれを統一（選択式化に伴う） ----
    "UPDATE sales_cases SET carrier='docomo' WHERE carrier IN ('ドコモ','ドコモ ','ﾄﾞｺﾓ','DOCOMO','Docomo')",
    "UPDATE sales_cases SET carrier='SB' WHERE carrier IN ('ソフトバンク','softbank','SoftBank','SOFTBANK','sb')",
    "UPDATE sales_cases SET carrier='au' WHERE carrier IN ('AU','Au','ａｕ')",
    "UPDATE sales_cases SET carrier='楽天' WHERE carrier IN ('楽天モバイル','rakuten','Rakuten')",

    // ---- sales_prev_year_revenues: 粗利も保存できるようにする ----
    "ALTER TABLE sales_prev_year_revenues ADD COLUMN profit BIGINT NOT NULL DEFAULT 0 AFTER revenue",
    // 2025-2026年度 9〜12月の実績（案件データが無いため手入力値として登録）
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2025,  9, 17213272,  5473866 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2025,  9, 13035800,  4092740 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2025, 10, 17033505,  5548442 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2025, 10,  8952795,  1172435 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2025, 11, 16991004,  5805130 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2025, 11,  8474890,  1288352 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2025, 12, 16574851,  4959614 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2025, 12,  3141000, -4008182 FROM sales_cases",
    // 2025-2026年度 1〜4月の実績（案件データが無いため手入力値として登録）
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2026,  1, 17187726,  4558660 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2026,  1,  5074000,   926659 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2026,  2, 20587735,  6804018 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2026,  2,  4254518,  1054418 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2026,  3, 20929270,  7360169 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2026,  3,  6941418,   947700 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'regular', 2026,  4, 15671732,  4356922 FROM sales_cases",
    "INSERT IGNORE INTO sales_prev_year_revenues (company_id, case_type, year, month, revenue, profit) SELECT DISTINCT company_id, 'event',   2026,  4,  1574000,    58000 FROM sales_cases",

    // ---- salary_regular_overrides: 常勤案件売上(7割)の手入力上書きテーブル ----
    // 保存された行がある月・スタッフのみ自動計算を上書きする（行がなければ従来どおり自動計算）
    "CREATE TABLE IF NOT EXISTS salary_regular_overrides (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, pay_year INT NOT NULL, pay_month INT NOT NULL, worker_name VARCHAR(100) NOT NULL, amount INT NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sro (company_id, pay_year, pay_month, worker_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- salary_additional_payments: 1人1件の制約を外し複数明細を許可 ----
    // 既存データは1件目の明細としてそのまま残る
    "ALTER TABLE salary_additional_payments DROP INDEX uk_sap",
    "ALTER TABLE salary_additional_payments ADD INDEX idx_sap (company_id, pay_year, pay_month, worker_name)",

    // ---- salary_additional_payments: 追加支給テーブル ----
    "CREATE TABLE IF NOT EXISTS salary_additional_payments (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, pay_year INT NOT NULL, pay_month INT NOT NULL, worker_name VARCHAR(100) NOT NULL, amount INT NOT NULL DEFAULT 0, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sap (company_id, pay_year, pay_month, worker_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sga_expenses: 販管費管理テーブル ----
    "CREATE TABLE IF NOT EXISTS sga_expenses (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, target_year INT NOT NULL, target_month INT NOT NULL, category VARCHAR(100) NOT NULL, content VARCHAR(255) NOT NULL DEFAULT '', amount BIGINT NOT NULL DEFAULT 0, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_sga_company_month (company_id, target_year, target_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // ---- sga_expenses: content カラム追加（既存テーブル用） ----
    "ALTER TABLE sga_expenses ADD COLUMN content VARCHAR(255) NOT NULL DEFAULT '' AFTER category",
    // ---- sga_expenses: 区分カラム追加（sga=販管費 / cost=原価） ----
    "ALTER TABLE sga_expenses ADD COLUMN expense_type VARCHAR(10) NOT NULL DEFAULT 'sga' COMMENT '区分 sga=販管費 cost=原価' AFTER note",

    // ---- sales_cases: 屋号カラム追加 ----
    "ALTER TABLE sales_cases ADD COLUMN trade_name VARCHAR(100) DEFAULT NULL AFTER carrier",

    // ---- sales_daily_reports: 目標設定カラム ----
    "ALTER TABLE sales_daily_reports ADD COLUMN goal_type VARCHAR(10) DEFAULT NULL",
    "ALTER TABLE sales_daily_reports ADD COLUMN goal_value INT DEFAULT NULL",

    // ---- employees: 雇用形態・区分カラム（既存環境向け） ----
    "ALTER TABLE employees ADD COLUMN employment_type VARCHAR(30) DEFAULT NULL",
    "ALTER TABLE employees ADD COLUMN employment_subtype VARCHAR(30) DEFAULT NULL",

    // ---- employees: 所属会社カラム ----
    "ALTER TABLE employees ADD COLUMN affiliation_company VARCHAR(150) DEFAULT NULL COMMENT '所属会社'",

    // ---- employee_salaries: 正社員給与管理テーブル ----
    "CREATE TABLE IF NOT EXISTS employee_salaries (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_id INT NOT NULL, pay_year SMALLINT NOT NULL, pay_month TINYINT NOT NULL, base_pay INT NOT NULL DEFAULT 0, position_allowance INT NOT NULL DEFAULT 0, overtime_allowance INT NOT NULL DEFAULT 0, commute_allowance INT NOT NULL DEFAULT 0, other_allowance INT NOT NULL DEFAULT 0, health_insurance INT NOT NULL DEFAULT 0, pension INT NOT NULL DEFAULT 0, employment_insurance INT NOT NULL DEFAULT 0, income_tax INT NOT NULL DEFAULT 0, resident_tax INT NOT NULL DEFAULT 0, other_deduction INT NOT NULL DEFAULT 0, slip_image LONGBLOB DEFAULT NULL, slip_mime VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_es (company_id, employee_id, pay_year, pay_month), INDEX idx_es_month (company_id, pay_year, pay_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- employee_salaries: 明細項目JSON（勤怠/支給/控除/集計/コメント） ----
    "ALTER TABLE employee_salaries ADD COLUMN detail TEXT DEFAULT NULL AFTER pay_month",

    // ---- sales_cases: 案件単位の粗利0円フラグ（特定案件だけ粗利を直営業100%にする） ----
    "ALTER TABLE sales_cases ADD COLUMN zero_profit_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '粗利0円稼働（案件単位）'",
    // 2026年7月イベント案件の近藤航のみ粗利0円稼働として扱う
    "UPDATE sales_cases SET zero_profit_flag=1 WHERE worker_name='近藤航' AND case_type='event' AND case_year=2026 AND case_month=7",

    // ---- employees: 出発報告対象者・粗利0円稼働者フラグ ----
    "ALTER TABLE employees ADD COLUMN departure_report_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '出発報告対象者'",
    "ALTER TABLE employees ADD COLUMN zero_profit_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '粗利0円稼働者'",

    // ---- employees: ランク・営業担当（正社員/自社外注のみ対象） ----
    // ※rank はMySQL8の予約語のため staff_rank とする
    "ALTER TABLE employees ADD COLUMN staff_rank VARCHAR(20) DEFAULT NULL COMMENT 'ランク（ブロンズ/シルバー/ゴールド）'",
    "ALTER TABLE employees ADD COLUMN sales_rep_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '営業担当'",

    // ---- employees: インセンティブ率（NULL=未設定。既定30%が適用される） ----
    // これまで3ファイルに名前でベタ書きしていた率を名簿に移す
    "ALTER TABLE employees ADD COLUMN incentive_rate DECIMAL(5,4) DEFAULT NULL COMMENT 'インセンティブ率（0.20=20%）。NULLは既定30%'",
    // 現行のベタ書きと同じ値を初期投入する（金額を変えないため）
    // ※既に値が入っている場合は上書きしない
    "UPDATE employees SET incentive_rate = 0.0000 WHERE incentive_rate IS NULL AND name = '竹内陽'",
    "UPDATE employees SET incentive_rate = 0.2000 WHERE incentive_rate IS NULL AND name IN ('佐藤思杰','近藤航')",

    // ---- departure_reports: 出発報告メール履歴 ----
    "CREATE TABLE IF NOT EXISTS departure_reports (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_id INT NOT NULL, token VARCHAR(64) NOT NULL, sent_to VARCHAR(200) NOT NULL, admin_email VARCHAR(200) DEFAULT NULL, answer VARCHAR(10) DEFAULT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, answered_at TIMESTAMP NULL DEFAULT NULL, UNIQUE KEY uk_dr_token (token), INDEX idx_dr_emp (company_id, employee_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_cases: 区分「その他」「二次以降」を「2次以降」に統一 ----
    "UPDATE sales_cases SET case_division='2次以降' WHERE case_division IN ('その他','二次以降')",

    // ---- sales_frame_targets: 目標二次以降枠数カラム ----
    "ALTER TABLE sales_frame_targets ADD COLUMN target_second_frame INT NOT NULL DEFAULT 0 COMMENT '目標二次以降枠数' AFTER target_first_frame",

    // ---- sales_shifts: 出発時間（出発報告対象者のみ使用） ----
    "ALTER TABLE sales_shifts ADD COLUMN departure_time VARCHAR(10) DEFAULT NULL COMMENT '出発予定時間' AFTER start_time",

    // ---- app_sessions: DBセッション保存（再デプロイでログアウトされない） ----
    "CREATE TABLE IF NOT EXISTS app_sessions (id VARCHAR(128) PRIMARY KEY, data MEDIUMTEXT NOT NULL, last_activity INT NOT NULL, INDEX idx_last_activity (last_activity)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- departure_reports: 通知先の複数指定（カンマ区切り）対応 ----
    "ALTER TABLE departure_reports MODIFY COLUMN admin_email VARCHAR(500) DEFAULT NULL",

    // ---- departure_reports: シフト連携自動送信用カラム ----
    "ALTER TABLE departure_reports ADD COLUMN shift_id INT DEFAULT NULL COMMENT '自動送信元シフトID'",
    "ALTER TABLE departure_reports ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0 COMMENT '自動送信フラグ'",
    "ALTER TABLE departure_reports ADD UNIQUE KEY uk_dr_shift (shift_id)",

    // ---- employees: 雇用形態の旧値（自社+区分）を新選択肢へ変換 ----
    "UPDATE employees SET employment_type='自社外注' WHERE employment_type='自社' AND employment_subtype='外注'",
    "UPDATE employees SET employment_type='アルバイト' WHERE employment_type='自社' AND employment_subtype='アルバイト'",
    "UPDATE employees SET employment_type='正社員' WHERE employment_type='自社'",

    // ---- store_monthly_budgets: 店舗予算（月次）テーブル ----
    "CREATE TABLE IF NOT EXISTS store_monthly_budgets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, employee_name VARCHAR(100) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, budget_detail TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smb (company_id, employee_name, year, month), INDEX idx_smb_emp (company_id, employee_name, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- invoice_checks: 請求書チェック管理テーブル ----
    "CREATE TABLE IF NOT EXISTS invoice_checks (id INT PRIMARY KEY AUTO_INCREMENT, bms_company_id INT NOT NULL, company_type VARCHAR(30) NOT NULL, ref_id INT NOT NULL DEFAULT 0, ref_name VARCHAR(100) NOT NULL DEFAULT '', check_year SMALLINT NOT NULL, check_month TINYINT NOT NULL, check_create TINYINT(1) NOT NULL DEFAULT 0, check_staff1 TINYINT(1) NOT NULL DEFAULT 0, check_staff2 TINYINT(1) NOT NULL DEFAULT 0, final_check TINYINT(1) NOT NULL DEFAULT 0, updated_by VARCHAR(100) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE KEY uq_ic (bms_company_id, company_type, ref_id, ref_name, check_year, check_month), INDEX idx_ic_company (bms_company_id, company_type, check_year, check_month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- store_monthly_budgets: 重複行を削除（最新のidを残す） ----
    "DELETE s1 FROM store_monthly_budgets s1 INNER JOIN store_monthly_budgets s2 ON s1.company_id=s2.company_id AND s1.employee_name=s2.employee_name AND s1.year=s2.year AND s1.month=s2.month AND s1.id < s2.id",

    // ---- store_monthly_budgets: UNIQUE KEY が未作成の環境向けに追加 ----
    "ALTER TABLE store_monthly_budgets ADD UNIQUE KEY uk_smb (company_id, employee_name, year, month)",

    // ---- sales_cases: 案件区分（1次/その他） ----
    "ALTER TABLE sales_cases ADD COLUMN case_division VARCHAR(20) DEFAULT NULL",

    // ---- sales_cases: 予算区分（キャリア予算/代理店予算。常勤の1次案件のみ入力） ----
    "ALTER TABLE sales_cases ADD COLUMN budget_division VARCHAR(20) DEFAULT NULL AFTER case_division",

    // ---- sales_cases: 担当者の社員ID（第2段階・名前と併存させるだけで集計はまだ使わない） ----
    // 改姓・同姓同名に備えて社員を番号で特定できるようにする。値の書き込みのみ行い、
    // 画面や集計は従来どおり名前を参照する（切り替えは第3段階で1画面ずつ行う）
    "ALTER TABLE sales_cases ADD COLUMN sales_rep_id INT DEFAULT NULL COMMENT '営業担当の社員ID（名前と併存）'",
    "ALTER TABLE sales_cases ADD COLUMN manager_id INT DEFAULT NULL COMMENT '管理者の社員ID（名前と併存）'",
    "ALTER TABLE sales_cases ADD COLUMN recruiter_id INT DEFAULT NULL COMMENT '採用者の社員ID（名前と併存）'",
    "ALTER TABLE sales_cases ADD INDEX idx_cases_rep_ids (company_id, sales_rep_id)",

    // ---- sales_cases: 稼働スタッフの社員ID ----
    // ※既存の worker_id は sales_workers への外部キーのため流用できない。社員名簿用に別途持つ
    "ALTER TABLE sales_cases ADD COLUMN worker_employee_id INT DEFAULT NULL COMMENT '稼働スタッフの社員ID（名前と併存）'",
    "ALTER TABLE sales_cases ADD INDEX idx_cases_worker_emp (company_id, worker_employee_id)",

    // ---- sales_clients: 取引先一覧（表記名・連絡先・契約書のGoogleドライブ紐付け） ----
    "ALTER TABLE sales_clients ADD COLUMN display_name VARCHAR(100) DEFAULT NULL COMMENT '表記名（アプリ内表示名）'",
    "ALTER TABLE sales_clients ADD COLUMN email VARCHAR(191) DEFAULT NULL COMMENT '担当者メールアドレス'",
    "ALTER TABLE sales_clients ADD COLUMN contract_file_id VARCHAR(191) DEFAULT NULL COMMENT '契約書のGoogleドライブ ファイルID'",
    "ALTER TABLE sales_clients ADD COLUMN contract_file_name VARCHAR(255) DEFAULT NULL COMMENT '契約書のファイル名（表示用）'",
    "ALTER TABLE sales_clients ADD COLUMN contract_url VARCHAR(500) DEFAULT NULL COMMENT '契約書のURL（ファイルID未使用時のフォールバック）'",
    // 表記名が未設定の既存データは会社名で初期化（表示崩れ防止）
    "UPDATE sales_clients SET display_name = client_name WHERE display_name IS NULL OR display_name = ''",

    // ---- sales_rep_targets: 担当者別 月別売上目標テーブル ----
    "CREATE TABLE IF NOT EXISTS sales_rep_targets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, rep_name VARCHAR(100) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, target_revenue INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_srt (company_id, rep_name, year, month), INDEX idx_srt_company (company_id, year, month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_frame_targets: 月別枠数目標テーブル ----
    "CREATE TABLE IF NOT EXISTS sales_frame_targets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, case_type VARCHAR(20) NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, target_first_frame INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sft (company_id, case_type, year, month), INDEX idx_sft_company (company_id, case_type, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_cases: 光ADフラグ（戦略会議画面でのみ使用） ----
    // 常勤案件のみで入力する。既存の集計・画面はこの列を参照しないため数字は変わらない。
    // DEFAULT 0 なので既存案件はすべて「光ADでない」として扱われる（データ書き換えなし）
    "ALTER TABLE sales_cases ADD COLUMN hikari_ad_flag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '光AD（戦略会議のみで使用）'",
    "ALTER TABLE sales_cases ADD INDEX idx_cases_hikari_ad (company_id, hikari_ad_flag)",

    // ---- strategy_meeting_memos: 戦略会議の企業メモ（企業ごとに1件） ----
    // 戦略会議画面だけが読み書きする。既存テーブルには一切書き込まない
    "CREATE TABLE IF NOT EXISTS strategy_meeting_memos (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, client_id INT NOT NULL, memo TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smm (company_id, client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- strategy_meeting_settings: 戦略会議の設定（目標企業数） ----
    // 戦略会議画面だけが読み書きする。既定は100社
    "CREATE TABLE IF NOT EXISTS strategy_meeting_settings (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, target_client_count INT NOT NULL DEFAULT 100, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_sms (company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- strategy_meeting_negotiations: 商談報告（1社につき1件） ----
    // 同じ会社が二重登録されないよう、会社名キーに UNIQUE 制約を付けてDB側で防ぐ。
    // ステータスを書き換えたときは、その変更が「いつ起きたか」の年月を記録する
    // （candidate_ym / active_ym / excluded_ym）。これで過去の月のグラフが後から変わらない
    "CREATE TABLE IF NOT EXISTS strategy_meeting_negotiations (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, client_id INT DEFAULT NULL COMMENT '取引先マスタのID（新規入力ならNULL）', client_name VARCHAR(200) NOT NULL COMMENT '会社名（表示用）', client_name_key VARCHAR(200) NOT NULL COMMENT '突き合わせ用の正規化キー', rep_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '営業担当者名', rep_employee_id INT DEFAULT NULL COMMENT '営業担当者の社員ID', status VARCHAR(20) NOT NULL DEFAULT '取引候補' COMMENT '取引開始/取引候補/温度感低め/合わない/倒産/その他', status_other VARCHAR(100) DEFAULT NULL COMMENT 'その他を選んだときの自由入力', note TEXT DEFAULT NULL, first_report_ym INT NOT NULL COMMENT '初回登録の年月 YYYYMM（新規商談数の基準）', candidate_ym INT DEFAULT NULL COMMENT '取引候補以上になった最初の年月 YYYYMM', active_ym INT DEFAULT NULL COMMENT '取引開始になった最初の年月 YYYYMM', excluded_ym INT DEFAULT NULL COMMENT '会社数から外れた年月 YYYYMM', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smn (company_id, client_name_key), INDEX idx_smn_first (company_id, first_report_ym), INDEX idx_smn_cand (company_id, candidate_ym), INDEX idx_smn_active (company_id, active_ym)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- strategy_meeting_monthly_targets: 会社数・取引有会社数の月別目標 ----
    // 既存の sales_rep_targets と同じ形。9月〜翌8月の各月に設定する
    "CREATE TABLE IF NOT EXISTS strategy_meeting_monthly_targets (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, target_company_count INT NOT NULL DEFAULT 0 COMMENT '会社数目標', target_active_count INT NOT NULL DEFAULT 0 COMMENT '取引有会社数目標', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uk_smmt (company_id, year, month), INDEX idx_smmt_company (company_id, year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_alliances: 同じ会社の取引先との紐づけ ----
    // 外注先と取引先に同じ会社が登録されていることがある（LANGIS・Pachira・U-Plus など）。
    // 戦略会議の会社数でこれを1社として数えるため、取引先マスタのIDを持たせる。
    // 名前ではなくIDで結ぶので、あとから会社名・表記名を変えても集計が壊れない。
    // 参照している画面は戦略会議のみ。既存の集計・請求・給与は一切この列を読まない
    "ALTER TABLE sales_alliances ADD COLUMN client_id INT DEFAULT NULL COMMENT '同じ会社の取引先ID（戦略会議の名寄せ用）'",
    "ALTER TABLE sales_alliances ADD INDEX idx_sa_client (company_id, client_id)",

    // ---- sales_alliances: 表記名（取引先と同じ考え方） ----
    // alliance_name = 正式名称（請求書管理など社外向けの並びで使う）
    // display_name  = 表記名（案件一覧など画面表示で使う）
    "ALTER TABLE sales_alliances ADD COLUMN display_name VARCHAR(100) DEFAULT NULL COMMENT '表記名（アプリ内表示名）'",
    // 表記名が未設定の既存データは外注先名で初期化（表示崩れ防止）
    "UPDATE sales_alliances SET display_name = alliance_name WHERE display_name IS NULL OR display_name = ''",
    // 取引先一覧の「外注先」タブで担当者メールも管理できるようにする
    "ALTER TABLE sales_alliances ADD COLUMN email VARCHAR(191) DEFAULT NULL COMMENT '担当者メールアドレス'",

    // ---- strategy_meeting_negotiations: 取引先IDでの重複禁止 ----
    // 商談報告は「1社につき1件」。取引先を選ぶ方式に変えたので、同じ取引先の
    // 2件目をDB側で拒否する。client_id が NULL の行は対象外（MySQLのUNIQUEはNULLを重複と見ない）
    "ALTER TABLE strategy_meeting_negotiations ADD UNIQUE KEY uk_smn_client (company_id, client_id)",

    // ---- strategy_meeting_negotiations: 区分（光AD / 常勤 / イベント） ----
    // パートナー候補は案件がまだ無いため、案件データから区分を計算できない。
    // 戦略会議の候補一覧で区分を出せるように、商談報告フォームで直接持たせる。
    // 既存の行は空欄（画面では「-」表示）。戦略会議だけが読む列で、既存の集計は参照しない
    "ALTER TABLE strategy_meeting_negotiations ADD COLUMN division VARCHAR(10) NOT NULL DEFAULT '' COMMENT '光AD/常勤/イベント（候補一覧の表示用）' AFTER status_other",
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
