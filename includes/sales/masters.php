<?php
// ============================================================
// 売上管理: マスタ (クライアント/アライアンス/ブランド/エリア/ワーカー)
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

// ----------------------------------------------------------------
// 取引先の表示名
// ----------------------------------------------------------------

/**
 * ポータル内で表示する取引先名を返す（表記名 → なければ会社名）
 * CSV・請求書など社外向けは会社名(client_name)をそのまま使うこと
 *
 * @param array $row client_name / display_name（または client_display_name）を含む行
 */
function clientLabel(array $row): string {
    $disp = trim((string)($row['display_name'] ?? $row['client_display_name'] ?? ''));
    if ($disp !== '') return $disp;
    return trim((string)($row['client_name'] ?? ''));
}

/** SQL用: 表記名があれば表記名、なければ会社名を返す式 */
function clientLabelSql(string $alias = 'cl'): string {
    return "COALESCE(NULLIF(TRIM({$alias}.display_name), ''), {$alias}.client_name)";
}

// ----------------------------------------------------------------
// 外注先の表示名（取引先とまったく同じ考え方）
// ----------------------------------------------------------------

/**
 * ポータル内で表示する外注先名を返す（表記名 → なければ正式名称）
 * 請求書管理のアライアンスタブなど、正式名称で出す画面では alliance_name を直接使うこと
 *
 * @param array $row alliance_name / display_name（または alliance_display_name）を含む行
 */
function allianceLabel(array $row): string {
    $disp = trim((string)($row['display_name'] ?? $row['alliance_display_name'] ?? ''));
    if ($disp !== '') return $disp;
    return trim((string)($row['alliance_name'] ?? ''));
}

/** SQL用: 表記名があれば表記名、なければ正式名称を返す式 */
function allianceLabelSql(string $alias = 'al'): string {
    return "COALESCE(NULLIF(TRIM({$alias}.display_name), ''), {$alias}.alliance_name)";
}

// ----------------------------------------------------------------
// マスタ取得
// ----------------------------------------------------------------

function getSalesClients(int $companyId, bool $activeOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM sales_clients WHERE company_id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, client_name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getSalesClient(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_clients WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function getSalesAlliances(int $companyId, bool $activeOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM sales_alliances WHERE company_id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, alliance_name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getSalesAlliance(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_alliances WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function getSalesStoreBrands(int $companyId, bool $activeOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM sales_store_brands WHERE company_id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, brand_name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getSalesStoreBrand(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_store_brands WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function getSalesAreas(int $companyId, bool $activeOnly = true): array {
    $db = getDB();
    $sql = 'SELECT * FROM sales_areas WHERE company_id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order, area_name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

function getSalesArea(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sales_areas WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function getSalesWorkers(int $companyId, ?string $workerType = null, bool $activeOnly = true): array {
    $db = getDB();
    $sql = 'SELECT w.*, a.alliance_name FROM sales_workers w
            LEFT JOIN sales_alliances a ON w.alliance_id = a.id
            WHERE w.company_id = ?';
    $params = [$companyId];
    if ($activeOnly) $sql .= ' AND w.is_active = 1';
    if ($workerType) {
        $sql .= ' AND w.worker_type = ?';
        $params[] = $workerType;
    }
    $sql .= ' ORDER BY w.worker_name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getSalesWorker(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT w.*, a.alliance_name FROM sales_workers w
            LEFT JOIN sales_alliances a ON w.alliance_id = a.id
            WHERE w.id = ? AND w.company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

// ----------------------------------------------------------------
// マスタ CRUD
// ----------------------------------------------------------------

function createSalesClient(int $companyId, array $data): int {
    $db = getDB();
    // 表記名は未指定なら会社名と同じ値を入れる（画面表示が空欄になるのを防ぐ）
    $display = trim((string)($data['display_name'] ?? '')) !== ''
        ? $data['display_name'] : $data['client_name'];
    try {
        $stmt = $db->prepare('INSERT INTO sales_clients (company_id, client_name, display_name, client_code, contact_person, phone, note, sort_order) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$companyId, $data['client_name'], $display, $data['client_code'] ?? null, $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0)]);
    } catch (PDOException $e) {
        // display_name カラム未追加の環境でも動作するようフォールバック
        $stmt = $db->prepare('INSERT INTO sales_clients (company_id, client_name, client_code, contact_person, phone, note, sort_order) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$companyId, $data['client_name'], $data['client_code'] ?? null, $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0)]);
    }
    return (int)$db->lastInsertId();
}

function updateSalesClient(int $id, int $companyId, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE sales_clients SET client_name=?, client_code=?, contact_person=?, phone=?, note=?, sort_order=? WHERE id=? AND company_id=?');
    $stmt->execute([$data['client_name'], $data['client_code'] ?? null, $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0), $id, $companyId]);
}

function toggleSalesClient(int $id, int $companyId, bool $active): void {
    $db = getDB();
    $db->prepare('UPDATE sales_clients SET is_active=? WHERE id=? AND company_id=?')->execute([$active ? 1 : 0, $id, $companyId]);
}

/**
 * 外注先フォームの「同じ会社の取引先」の値を取り出す
 * 未選択・空欄なら NULL（紐づけなし）
 */
function allianceClientIdInput(array $data): ?int {
    $v = $data['client_id'] ?? '';
    if ($v === '' || $v === null) return null;
    $id = (int)$v;
    return $id > 0 ? $id : null;
}

function createSalesAlliance(int $companyId, array $data): int {
    $db = getDB();
    $clientId = allianceClientIdInput($data);
    // 表記名は未指定なら正式名称と同じ値を入れる（画面表示が空欄になるのを防ぐ）
    $display = trim((string)($data['display_name'] ?? '')) !== ''
        ? $data['display_name'] : $data['alliance_name'];
    try {
        $stmt = $db->prepare('INSERT INTO sales_alliances (company_id, alliance_name, display_name, alliance_type, contact_person, phone, note, sort_order, client_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$companyId, $data['alliance_name'], $display, $data['alliance_type'] ?? 'アライアンス', $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0), $clientId]);
    } catch (PDOException $e) {
        // client_id カラム未追加の環境でも動作するようフォールバック
        $stmt = $db->prepare('INSERT INTO sales_alliances (company_id, alliance_name, alliance_type, contact_person, phone, note, sort_order) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$companyId, $data['alliance_name'], $data['alliance_type'] ?? 'アライアンス', $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0)]);
    }
    return (int)$db->lastInsertId();
}

function updateSalesAlliance(int $id, int $companyId, array $data): void {
    $db = getDB();
    $clientId = allianceClientIdInput($data);
    $display = trim((string)($data['display_name'] ?? '')) !== ''
        ? $data['display_name'] : $data['alliance_name'];
    try {
        $stmt = $db->prepare('UPDATE sales_alliances SET alliance_name=?, display_name=?, alliance_type=?, contact_person=?, phone=?, note=?, sort_order=?, client_id=? WHERE id=? AND company_id=?');
        $stmt->execute([$data['alliance_name'], $display, $data['alliance_type'] ?? 'アライアンス', $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0), $clientId, $id, $companyId]);
    } catch (PDOException $e) {
        $stmt = $db->prepare('UPDATE sales_alliances SET alliance_name=?, alliance_type=?, contact_person=?, phone=?, note=?, sort_order=? WHERE id=? AND company_id=?');
        $stmt->execute([$data['alliance_name'], $data['alliance_type'] ?? 'アライアンス', $data['contact_person'] ?? null, $data['phone'] ?? null, $data['note'] ?? null, (int)($data['sort_order'] ?? 0), $id, $companyId]);
    }
}

function toggleSalesAlliance(int $id, int $companyId, bool $active): void {
    $db = getDB();
    $db->prepare('UPDATE sales_alliances SET is_active=? WHERE id=? AND company_id=?')->execute([$active ? 1 : 0, $id, $companyId]);
}

function createSalesStoreBrand(int $companyId, array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (?,?,?,?)');
    $stmt->execute([$companyId, $data['brand_name'], $data['brand_code'] ?? null, (int)($data['sort_order'] ?? 0)]);
    return (int)$db->lastInsertId();
}

function updateSalesStoreBrand(int $id, int $companyId, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE sales_store_brands SET brand_name=?, brand_code=?, sort_order=? WHERE id=? AND company_id=?');
    $stmt->execute([$data['brand_name'], $data['brand_code'] ?? null, (int)($data['sort_order'] ?? 0), $id, $companyId]);
}

function toggleSalesStoreBrand(int $id, int $companyId, bool $active): void {
    $db = getDB();
    $db->prepare('UPDATE sales_store_brands SET is_active=? WHERE id=? AND company_id=?')->execute([$active ? 1 : 0, $id, $companyId]);
}

function createSalesArea(int $companyId, array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO sales_areas (company_id, area_name, region, sort_order) VALUES (?,?,?,?)');
    $stmt->execute([$companyId, $data['area_name'], $data['region'] ?? null, (int)($data['sort_order'] ?? 0)]);
    return (int)$db->lastInsertId();
}

function updateSalesArea(int $id, int $companyId, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE sales_areas SET area_name=?, region=?, sort_order=? WHERE id=? AND company_id=?');
    $stmt->execute([$data['area_name'], $data['region'] ?? null, (int)($data['sort_order'] ?? 0), $id, $companyId]);
}

function toggleSalesArea(int $id, int $companyId, bool $active): void {
    $db = getDB();
    $db->prepare('UPDATE sales_areas SET is_active=? WHERE id=? AND company_id=?')->execute([$active ? 1 : 0, $id, $companyId]);
}

function createSalesWorker(int $companyId, array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO sales_workers (company_id, worker_name, worker_type, alliance_id, employee_id) VALUES (?,?,?,?,?)');
    $stmt->execute([$companyId, $data['worker_name'], $data['worker_type'] ?? '正社員', $data['alliance_id'] ?: null, $data['employee_id'] ?: null]);
    return (int)$db->lastInsertId();
}

function updateSalesWorker(int $id, int $companyId, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE sales_workers SET worker_name=?, worker_type=?, alliance_id=?, employee_id=? WHERE id=? AND company_id=?');
    $stmt->execute([$data['worker_name'], $data['worker_type'] ?? '正社員', $data['alliance_id'] ?: null, $data['employee_id'] ?: null, $id, $companyId]);
}

function toggleSalesWorker(int $id, int $companyId, bool $active): void {
    $db = getDB();
    $db->prepare('UPDATE sales_workers SET is_active=? WHERE id=? AND company_id=?')->execute([$active ? 1 : 0, $id, $companyId]);
}
