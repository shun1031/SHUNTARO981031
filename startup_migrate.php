<?php
/**
 * 襍ｷ蜍墓凾閾ｪ蜍輔・繧､繧ｰ繝ｬ繝ｼ繧ｷ繝ｧ繝ｳ・・LI蟆ら畑・・ * Dockerfile縺ｮ繧ｹ繧ｿ繝ｼ繝医い繝・・繧ｳ繝槭Φ繝峨°繧牙ｮ溯｡後＆繧後ｋ
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
    echo "[migrate] DB謗･邯壼､ｱ謨・ " . $e->getMessage() . PHP_EOL;
    exit(0); // DB謗･邯壼､ｱ謨励〒繧ゅし繝ｼ繝舌・襍ｷ蜍輔・邯夊｡・}

$migrations = [
    // sales_cases: 荳崎ｶｳ繧ｫ繝ｩ繝
    "ALTER TABLE sales_cases ADD COLUMN case_name VARCHAR(200) DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN recruitment_count INT DEFAULT NULL",
    "ALTER TABLE sales_cases ADD COLUMN carrier VARCHAR(50) DEFAULT NULL AFTER note",
    "ALTER TABLE sales_cases ADD COLUMN new_transactions INT NOT NULL DEFAULT 0 AFTER carrier",
    "ALTER TABLE sales_cases ADD COLUMN negotiations_count INT NOT NULL DEFAULT 0 AFTER new_transactions",
    "ALTER TABLE sales_cases ADD COLUMN contracts_count INT NOT NULL DEFAULT 0 AFTER negotiations_count",

    // sales_daily_reports: 譌｢蟄・ALTER (IF NOT EXISTS 縺ｧ蜀ｪ遲牙喧)
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

    // sales_daily_reports: 繧､繝吶Φ繝医・繧ｷ繝ｧ繝・・邉ｻ
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

    // ---- sales_transport_costs: 逕ｳ隲九せ繝・・繧ｿ繧ｹ ----
    "ALTER TABLE sales_transport_costs ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'submitted' COMMENT '逕ｳ隲九せ繝・・繧ｿ繧ｹ'",

    // ---- sales_shifts: 騾蜍､譎ょ綾繧ｫ繝ｩ繝霑ｽ蜉 ----
    "ALTER TABLE sales_shifts ADD COLUMN checkout_time VARCHAR(10) DEFAULT NULL COMMENT '騾蜍､螳溽ｸｾ譎ょ綾'",

    // ---- event_plans: 莠亥ｮ壽｡井ｻｶ繝・・繝悶Ν ----
    "CREATE TABLE IF NOT EXISTS event_plans (id INT PRIMARY KEY AUTO_INCREMENT, company_id INT NOT NULL, client_name VARCHAR(200) NOT NULL, store_name VARCHAR(200) DEFAULT NULL, work_date DATE NOT NULL, required_count INT NOT NULL DEFAULT 1, status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending', linked_case_id INT DEFAULT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_ep_company (company_id), INDEX idx_ep_date (work_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // ---- sales_cases: 莠亥ｮ壽｡井ｻｶ繝ｪ繝ｳ繧ｯ ----
    "ALTER TABLE sales_cases ADD COLUMN plan_id INT DEFAULT NULL COMMENT '莠亥ｮ壽｡井ｻｶID'",

    // ---- 莨夂､ｾ蜷・KLG HOLDINGS 竊・LiberTeen ----
    "UPDATE companies SET company_name='LiberTeen' WHERE company_name='KLG HOLDINGS'",

    // ---- company_admin繧｢繧ｫ繧ｦ繝ｳ繝医〒 employee_id 譛ｪ繝ｪ繝ｳ繧ｯ縺ｮ繧ゅ・繧貞酔蜷咲､ｾ蜩｡縺ｫ閾ｪ蜍輔Μ繝ｳ繧ｯ ----
    "UPDATE users u INNER JOIN employees e ON e.company_id = u.company_id AND e.name = u.display_name AND e.is_active = 1 SET u.employee_id = e.id WHERE u.employee_id IS NULL AND u.company_id IS NOT NULL AND u.role = 'company_admin'",

    // ---- sales_change_requests: request_type ENUM 縺ｫ譁ｰ逕ｳ隲狗ｨｮ蛻･繧定ｿｽ蜉 ----
    "ALTER TABLE sales_change_requests MODIFY COLUMN request_type ENUM('shift_change','attendance_change','checkin_change','checkout_change','attendance_add','daily_report_edit','transport_edit') NOT NULL",

    // ---- sales_transport_costs: 繧ｨ繝薙ョ繝ｳ繧ｹ繝舌う繝翫Μ繧奪B縺ｫ菫晏ｭ假ｼ・ailway豌ｸ邯壼喧蟇ｾ蠢懶ｼ・----
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_1 LONGBLOB DEFAULT NULL",
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_2 LONGBLOB DEFAULT NULL",
    "ALTER TABLE sales_transport_costs ADD COLUMN evidence_data_3 LONGBLOB DEFAULT NULL",
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

echo "[migrate] 螳御ｺ・ {$ok}莉ｶ謌仙粥, {$fail}莉ｶ繧ｹ繧ｭ繝・・/螟ｱ謨・ . PHP_EOL;

