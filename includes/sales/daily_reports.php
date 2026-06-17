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

function saveDailyReport(int $companyId, array $data): int {
    $db = getDB();
    $id = (int)($data['id'] ?? 0);
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
        // イベント日報
        'catch_count'               => $data['catch_count'] ?? null,
        'event_seated'              => $data['event_seated'] ?? null,
        'event_proposals'           => $data['event_proposals'] ?? null,
        'event_negotiations'        => $data['event_negotiations'] ?? null,
        'event_contracts'           => $data['event_contracts'] ?? null,
        'event_acquisition_detail'  => $data['event_acquisition_detail'] ?? null,
        'personal_acquisition_detail' => $data['personal_acquisition_detail'] ?? null,
        'fixed_check_detail'        => $data['fixed_check_detail'] ?? null,
        'fixed_acquisition_detail'  => $data['fixed_acquisition_detail'] ?? null,
        'event_reflection'          => $data['event_reflection'] ?? null,
        // ショップ常勤日報
        'shop_visits'               => $data['shop_visits'] ?? null,
        'shop_proposals'            => $data['shop_proposals'] ?? null,
        'shop_negotiations'         => $data['shop_negotiations'] ?? null,
        'shop_contracts'            => $data['shop_contracts'] ?? null,
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
