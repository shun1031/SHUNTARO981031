<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isSuperAdmin()) {
    http_response_code(403);
    die('管理者のみアクセス可能です');
}

$db = getDB();

// MySQL 9.4 supports IF NOT EXISTS for ADD COLUMN
$migrations = [
    // ---- sales_cases: 不足カラム ----
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS case_name VARCHAR(200) DEFAULT NULL COMMENT '案件名'",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS recruitment_count INT DEFAULT NULL COMMENT '採用人数'",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS carrier VARCHAR(50) DEFAULT NULL COMMENT 'キャリア' AFTER note",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS new_transactions INT NOT NULL DEFAULT 0 COMMENT '新規件数' AFTER carrier",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS negotiations_count INT NOT NULL DEFAULT 0 COMMENT '商談件数' AFTER new_transactions",
    "ALTER TABLE sales_cases ADD COLUMN IF NOT EXISTS contracts_count INT NOT NULL DEFAULT 0 COMMENT '契約件数' AFTER negotiations_count",

    // ---- sales_daily_reports: 既存ALTER (IF NOT EXISTS で冪等化) ----
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS location_type VARCHAR(20) DEFAULT NULL AFTER carrier",
    "ALTER TABLE sales_daily_reports ADD COLUMN IF NOT EXISTS work_type VARCHAR(30) DEFAULT NULL COMMENT '業務形態'",
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

    // ---- sales_daily_reports: イベント・ショップ系 不足カラム ----
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
];

$results = [];
$errors = 0;
foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => substr($sql, 0, 80) . '...', 'ok' => true, 'msg' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['sql' => substr($sql, 0, 80) . '...', 'ok' => false, 'msg' => $e->getMessage()];
        $errors++;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>DB Migration</title>
<style>
body { font-family: monospace; padding: 20px; background: #1e1e2e; color: #cdd6f4; }
h1 { color: #89b4fa; }
.ok { color: #a6e3a1; }
.err { color: #f38ba8; }
table { border-collapse: collapse; width: 100%; margin-top: 1em; }
td { padding: 4px 8px; border-bottom: 1px solid #45475a; font-size: 13px; }
.summary { margin-top: 1.5em; padding: 10px; border-radius: 6px; background: #313244; }
a { color: #89b4fa; }
</style>
</head>
<body>
<h1>DB Migration</h1>
<p>実行結果: <?= count($results) ?>件 / エラー: <span class="<?= $errors > 0 ? 'err' : 'ok' ?>"><?= $errors ?>件</span></p>
<table>
<?php foreach ($results as $r): ?>
<tr>
    <td class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? '✓' : '✗' ?></td>
    <td><?= htmlspecialchars($r['sql']) ?></td>
    <td><?= $r['ok'] ? '' : htmlspecialchars($r['msg']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<div class="summary">
<?php if ($errors === 0): ?>
    <span class="ok">✓ マイグレーション完了。全カラムが追加されました。</span>
<?php else: ?>
    <span class="err">⚠ エラーが <?= $errors ?>件あります（既存カラムのエラーは無視して問題ありません）。</span>
<?php endif; ?>
</div>
<p><a href="<?= BASE_PATH ?>/public/sales_regular.php">常勤案件へ</a> |
   <a href="<?= BASE_PATH ?>/public/sales_events.php">イベント案件へ</a> |
   <a href="<?= BASE_PATH ?>/public/sales_daily_report.php">日報へ</a> |
   <a href="<?= BASE_PATH ?>/public/sales_transport.php">交通費へ</a></p>
</body>
</html>
