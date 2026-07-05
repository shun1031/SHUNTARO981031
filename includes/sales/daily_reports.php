<?php
// ============================================================
// 売上管理: 日報管理
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ================================================================
// 日報管理
// ================================================================

function getDailyReports(int $companyId, int $year, int $month, ?string $employee = null): array {
    $db = getDB();
    $sql = "SELECT * FROM sales_daily_reports WHERE company_id = ? AND work_date BETWEEN ? AND ?";
    $params = [$companyId, sprintf('%04d-%02d-01', $year, $month), date('Y-m-t', mktime(0,0,0,$month,1,$year))];
    if ($employee) { $sql .= " AND employee_name = ?"; $params[] = $employee; }
    $sql .= " ORDER BY work_date DESC, employee_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getDailyReport(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sales_daily_reports WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

/**
 * sales_daily_reports に必要なカラムが存在しなければ追加する（起動時移行漏れへの保険）
 */
function ensureDailyReportColumns(PDO $db): void {
    $required = [
        'location_type'               => "VARCHAR(20) DEFAULT NULL",
        'work_type'                   => "VARCHAR(30) DEFAULT NULL",
        'mobile_external'             => "INT NOT NULL DEFAULT 0",
        'mobile_change_count'         => "INT NOT NULL DEFAULT 0",
        'sb_hikari_new'               => "INT NOT NULL DEFAULT 0",
        'sb_hikari_provider_change'   => "INT NOT NULL DEFAULT 0",
        'sb_hikari_transfer'          => "INT NOT NULL DEFAULT 0",
        'air_new'                     => "INT NOT NULL DEFAULT 0",
        'air_change'                  => "INT NOT NULL DEFAULT 0",
        'biglobe_hikari'              => "INT NOT NULL DEFAULT 0",
        'commufa_hikari'              => "INT NOT NULL DEFAULT 0",
        'aupay_card'                  => "INT NOT NULL DEFAULT 0",
        'au_denki'                    => "INT NOT NULL DEFAULT 0",
        'au_smartpass'                => "INT NOT NULL DEFAULT 0",
        'fixed_new'                   => "INT NOT NULL DEFAULT 0",
        'fixed_provider_change'       => "INT NOT NULL DEFAULT 0",
        'fixed_transfer'              => "INT NOT NULL DEFAULT 0",
        'home_router_new'             => "INT NOT NULL DEFAULT 0",
        'home_router_change'          => "INT NOT NULL DEFAULT 0",
        'visit_groups'                => "INT NOT NULL DEFAULT 0",
        'consultation_groups'         => "INT NOT NULL DEFAULT 0",
        'mobile_acquisitions'         => "INT NOT NULL DEFAULT 0",
        'setup_support'               => "INT NOT NULL DEFAULT 0",
        'sim_mnp'                     => "INT NOT NULL DEFAULT 0",
        'sim_new'                     => "INT NOT NULL DEFAULT 0",
        'sim_change'                  => "INT NOT NULL DEFAULT 0",
        'sim_fixed'                   => "INT NOT NULL DEFAULT 0",
        'sim_router'                  => "INT NOT NULL DEFAULT 0",
        'ev_mnp'                      => "INT NOT NULL DEFAULT 0",
        'ev_up'                       => "INT NOT NULL DEFAULT 0",
        'ev_down'                     => "INT NOT NULL DEFAULT 0",
        'ev_kihenkaku'                => "INT NOT NULL DEFAULT 0",
        'ev_tenyo'                    => "INT NOT NULL DEFAULT 0",
        'ev_jihen'                    => "INT NOT NULL DEFAULT 0",
        'ev_sb_hikari_1g_new'         => "INT NOT NULL DEFAULT 0",
        'ev_sb_hikari_1g10'           => "INT NOT NULL DEFAULT 0",
        'ev_bl_hikari_1g_new'         => "INT NOT NULL DEFAULT 0",
        'ev_hikari_12g'               => "INT NOT NULL DEFAULT 0",
        'ev_hikari_10g'               => "INT NOT NULL DEFAULT 0",
        'ev_air_new'                  => "INT NOT NULL DEFAULT 0",
        'ev_air_change'               => "INT NOT NULL DEFAULT 0",
        'ev_air_rental'               => "INT NOT NULL DEFAULT 0",
        'catch_count'                 => "INT DEFAULT NULL",
        'event_seated'                => "INT DEFAULT NULL",
        'event_proposals'             => "INT DEFAULT NULL",
        'event_negotiations'          => "INT DEFAULT NULL",
        'event_contracts'             => "INT DEFAULT NULL",
        'event_acquisition_detail'    => "TEXT DEFAULT NULL",
        'personal_acquisition_detail' => "TEXT DEFAULT NULL",
        'fixed_check_detail'          => "TEXT DEFAULT NULL",
        'fixed_acquisition_detail'    => "TEXT DEFAULT NULL",
        'event_reflection'            => "TEXT DEFAULT NULL",
        'shop_visits'                 => "INT DEFAULT NULL",
        'shop_proposals'              => "INT DEFAULT NULL",
        'shop_negotiations'           => "INT DEFAULT NULL",
        'shop_contracts'              => "INT DEFAULT NULL",
        'shop_acquisition_detail'     => "TEXT DEFAULT NULL",
        'shop_fixed_check_detail'     => "TEXT DEFAULT NULL",
        'shop_comment'                => "TEXT DEFAULT NULL",
    ];
    try {
        $rows = $db->query("SHOW COLUMNS FROM sales_daily_reports")->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_flip(array_column($rows, 'Field'));
        foreach ($required as $col => $def) {
            if (!isset($existing[$col])) {
                $db->exec("ALTER TABLE `sales_daily_reports` ADD COLUMN IF NOT EXISTS `{$col}` {$def}");
                error_log("[ensureDailyReportColumns] added missing column: {$col}");
            }
        }
    } catch (PDOException $e) {
        error_log('[ensureDailyReportColumns] ' . $e->getMessage());
    }
}

function saveDailyReport(int $companyId, array $data): int {
    $db = getDB();
    // リクエスト初回のみ: 不足カラムをその場で追加（Railway移行漏れの保険）
    static $migrated = false;
    if (!$migrated) {
        ensureDailyReportColumns($db);
        $migrated = true;
    }
    $id = (int)($data['id'] ?? 0);
    // 空文字列を null に正規化（nullable INT カラムへの '' 投入で MySQL strict モードエラーになるのを防ぐ）
    $ni = static fn(string $key): ?int =>
        isset($data[$key]) && $data[$key] !== '' ? (int)$data[$key] : null;
    $fields = [
        'employee_name' => $data['employee_name'],
        'work_date' => $data['work_date'],
        'location' => $data['location'] ?? null,
        'carrier' => $data['carrier'] ?? null,
        'location_type' => $data['location_type'] ?? null,
        'work_type' => $data['work_type'] ?? null,
        'contacts' => (int)($data['contacts'] ?? 0),
        'consultations' => (int)($data['consultations'] ?? 0),
        'seated' => (int)($data['seated'] ?? 0),
        // SBモバイル
        'sb_mnp' => (int)($data['sb_mnp'] ?? 0),
        'sb_new' => (int)($data['sb_new'] ?? 0),
        'sb_change' => (int)($data['sb_change'] ?? 0),
        'sb_upgrade' => (int)($data['sb_upgrade'] ?? 0),
        // YM
        'ym_mnp' => (int)($data['ym_mnp'] ?? 0),
        'ym_new' => (int)($data['ym_new'] ?? 0),
        'ym_change' => (int)($data['ym_change'] ?? 0),
        'ym_downgrade' => (int)($data['ym_downgrade'] ?? 0),
        // SB固定商材
        'sb_hikari' => (int)($data['sb_hikari'] ?? 0),
        'sb_air' => (int)($data['sb_air'] ?? 0),
        'ouchi_denwa' => (int)($data['ouchi_denwa'] ?? 0),
        'paypay_card' => (int)($data['paypay_card'] ?? 0),
        'ouchi_denki' => (int)($data['ouchi_denki'] ?? 0),
        'selection_amount' => (int)($data['selection_amount'] ?? 0),
        'acquisition_points' => (int)($data['acquisition_points'] ?? 0),
        // au/UQ
        'au_mnp' => (int)($data['au_mnp'] ?? 0),
        'au_new' => (int)($data['au_new'] ?? 0),
        'au_change' => (int)($data['au_change'] ?? 0),
        'au_upgrade' => (int)($data['au_upgrade'] ?? 0),
        'uq_mnp' => (int)($data['uq_mnp'] ?? 0),
        'uq_new' => (int)($data['uq_new'] ?? 0),
        'uq_change' => (int)($data['uq_change'] ?? 0),
        'uq_downgrade' => (int)($data['uq_downgrade'] ?? 0),
        // 固定スタッフ共通
        'mobile_external' => (int)($data['mobile_external'] ?? 0),
        'mobile_change_count' => (int)($data['mobile_change_count'] ?? 0),
        // SB固定
        'sb_hikari_new' => (int)($data['sb_hikari_new'] ?? 0),
        'sb_hikari_provider_change' => (int)($data['sb_hikari_provider_change'] ?? 0),
        'sb_hikari_transfer' => (int)($data['sb_hikari_transfer'] ?? 0),
        'air_new' => (int)($data['air_new'] ?? 0),
        'air_change' => (int)($data['air_change'] ?? 0),
        // au追加
        'biglobe_hikari' => (int)($data['biglobe_hikari'] ?? 0),
        'commufa_hikari' => (int)($data['commufa_hikari'] ?? 0),
        'aupay_card' => (int)($data['aupay_card'] ?? 0),
        'au_denki' => (int)($data['au_denki'] ?? 0),
        'au_smartpass' => (int)($data['au_smartpass'] ?? 0),
        // 固定系共通
        'fixed_new' => (int)($data['fixed_new'] ?? 0),
        'fixed_provider_change' => (int)($data['fixed_provider_change'] ?? 0),
        'fixed_transfer' => (int)($data['fixed_transfer'] ?? 0),
        'home_router_new' => (int)($data['home_router_new'] ?? 0),
        'home_router_change' => (int)($data['home_router_change'] ?? 0),
        // ショップ用
        'visit_groups' => (int)($data['visit_groups'] ?? 0),
        'consultation_groups' => (int)($data['consultation_groups'] ?? 0),
        'mobile_acquisitions' => (int)($data['mobile_acquisitions'] ?? 0),
        'setup_support' => (int)($data['setup_support'] ?? 0),
        // 格安SIM
        'sim_mnp' => (int)($data['sim_mnp'] ?? 0),
        'sim_new' => (int)($data['sim_new'] ?? 0),
        'sim_change' => (int)($data['sim_change'] ?? 0),
        'sim_fixed' => (int)($data['sim_fixed'] ?? 0),
        'sim_router' => (int)($data['sim_router'] ?? 0),
        // イベント獲得内訳（数値）
        'ev_mnp'              => (int)($data['ev_mnp'] ?? 0),
        'ev_up'               => (int)($data['ev_up'] ?? 0),
        'ev_down'             => (int)($data['ev_down'] ?? 0),
        'ev_kihenkaku'        => (int)($data['ev_kihenkaku'] ?? 0),
        'ev_tenyo'            => (int)($data['ev_tenyo'] ?? 0),
        'ev_jihen'            => (int)($data['ev_jihen'] ?? 0),
        'ev_sb_hikari_1g_new' => (int)($data['ev_sb_hikari_1g_new'] ?? 0),
        'ev_sb_hikari_1g10'   => (int)($data['ev_sb_hikari_1g10'] ?? 0),
        'ev_bl_hikari_1g_new' => (int)($data['ev_bl_hikari_1g_new'] ?? 0),
        'ev_hikari_12g'       => (int)($data['ev_hikari_12g'] ?? 0),
        'ev_hikari_10g'       => (int)($data['ev_hikari_10g'] ?? 0),
        'ev_air_new'          => (int)($data['ev_air_new'] ?? 0),
        'ev_air_change'       => (int)($data['ev_air_change'] ?? 0),
        'ev_air_rental'       => (int)($data['ev_air_rental'] ?? 0),
        // イベント/ショップ 日報（nullable INT は $ni で空文字列→null に正規化）
        'catch_count'               => $ni('catch_count'),
        'event_seated'              => $ni('event_seated'),
        'event_proposals'           => $ni('event_proposals'),
        'event_negotiations'        => $ni('event_negotiations'),
        'event_contracts'           => $ni('event_contracts'),
        'event_acquisition_detail'  => $data['event_acquisition_detail'] ?? null,
        'personal_acquisition_detail' => $data['personal_acquisition_detail'] ?? null,
        'fixed_check_detail'        => $data['fixed_check_detail'] ?? null,
        'fixed_acquisition_detail'  => $data['fixed_acquisition_detail'] ?? null,
        'event_reflection'          => $data['event_reflection'] ?? null,
        // ショップ常勤日報
        'shop_visits'               => $ni('shop_visits'),
        'shop_proposals'            => $ni('shop_proposals'),
        'shop_negotiations'         => $ni('shop_negotiations'),
        'shop_contracts'            => $ni('shop_contracts'),
        'shop_acquisition_detail'   => $data['shop_acquisition_detail'] ?? null,
        'shop_fixed_check_detail'   => $data['shop_fixed_check_detail'] ?? null,
        'shop_comment'              => $data['shop_comment'] ?? null,
        // その他
        'note' => $data['note'] ?? null,
        'submitted_at' => date('Y-m-d H:i:s'),
    ];
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE sales_daily_reports SET $sets WHERE id = ? AND company_id = ?");
        $stmt->execute([...array_values($fields), $id, $companyId]);
        return $id;
    }
    // UNIQUE KEY (company_id, employee_name, work_date) 重複時は INSERT が失敗するため
    // 既存レコードを確認し、あれば UPDATE に切り替える
    $chk = $db->prepare("SELECT id FROM sales_daily_reports WHERE company_id = ? AND employee_name = ? AND work_date = ?");
    $chk->execute([$companyId, $fields['employee_name'], $fields['work_date']]);
    $existingId = (int)($chk->fetchColumn() ?: 0);
    if ($existingId) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $stmt = $db->prepare("UPDATE sales_daily_reports SET $sets WHERE id = ? AND company_id = ?");
        $stmt->execute([...array_values($fields), $existingId, $companyId]);
        return $existingId;
    }
    $fields['company_id'] = $companyId;
    $cols = implode(', ', array_keys($fields));
    $phs = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $db->prepare("INSERT INTO sales_daily_reports ($cols) VALUES ($phs)");
    $stmt->execute(array_values($fields));
    return (int)$db->lastInsertId();
}

function deleteDailyReport(int $id, int $companyId): bool {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM sales_daily_reports WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->rowCount() > 0;
}

function getDailyReportSummary(int $companyId, int $year, int $month, ?string $employeeName = null): array {
    $db = getDB();
    $sql = "SELECT employee_name,
        COUNT(*) as work_days,
        SUM(contacts) as total_contacts, SUM(consultations) as total_consultations, SUM(seated) as total_seated,
        SUM(sb_mnp) as sb_mnp, SUM(sb_new) as sb_new, SUM(sb_change) as sb_change, SUM(sb_upgrade) as sb_upgrade,
        SUM(ym_mnp) as ym_mnp, SUM(ym_new) as ym_new, SUM(ym_change) as ym_change, SUM(ym_downgrade) as ym_downgrade,
        SUM(sb_hikari) as sb_hikari, SUM(sb_air) as sb_air, SUM(ouchi_denwa) as ouchi_denwa,
        SUM(paypay_card) as paypay_card, SUM(ouchi_denki) as ouchi_denki,
        SUM(selection_amount) as selection_amount, SUM(acquisition_points) as acquisition_points,
        SUM(au_mnp) as au_mnp, SUM(au_new) as au_new, SUM(au_change) as au_change, SUM(au_upgrade) as au_upgrade,
        SUM(uq_mnp) as uq_mnp, SUM(uq_new) as uq_new, SUM(uq_change) as uq_change, SUM(uq_downgrade) as uq_downgrade
    FROM sales_daily_reports WHERE company_id = ? AND work_date BETWEEN ? AND ?";
    $params = [$companyId, sprintf('%04d-%02d-01', $year, $month), date('Y-m-t', mktime(0,0,0,$month,1,$year))];
    if ($employeeName !== null) { $sql .= " AND employee_name = ?"; $params[] = $employeeName; }
    $sql .= " GROUP BY employee_name ORDER BY employee_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
