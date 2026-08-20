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
// 取引先一覧の絞り込み（戦略会議のパートナー数と同じ数え方）
//
// 取引先一覧に出すのは「その年度に実際に取引がある会社」だけ。
// 戦略会議の「パートナー数」とまったく同じ条件で数えるため、
// 条件はここ1か所にまとめて両方の画面から使う。
// ----------------------------------------------------------------

/** 年度（9月始まり）の月範囲条件。パラメータは [$fy-1, $fy] を渡す */
const TRADE_FY_WHERE = "((sc.case_year = ? AND sc.case_month >= 9) OR (sc.case_year = ? AND sc.case_month <= 8))";

/** 年月から年度を求める（9〜12月は翌年度） */
function tradeFyOf(int $year, int $month): int {
    return $month >= 9 ? $year + 1 : $year;
}

/** 今日時点の年度 */
function tradeCurrentFy(): int {
    return tradeFyOf((int)date('Y'), (int)date('n'));
}

/** 年度の表示ラベル（例: 25.9-26.8）。戦略会議の表記に合わせる */
function tradeFyLabel(int $fy): string {
    return substr((string)($fy - 1), 2) . '.9-' . substr((string)$fy, 2) . '.8';
}

/**
 * 画面に出す年度の選択肢。案件データがある年度だけを新しい順で返す。
 * データが1件も無い場合は今年度だけを返す（ボタンが消えないように）
 */
function tradeFyOptions(int $companyId): array {
    $db = getDB();
    $fys = [];
    try {
        $stmt = $db->prepare("SELECT DISTINCT case_year, case_month FROM sales_cases
                              WHERE company_id = ? AND status = 'confirmed'");
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fys[tradeFyOf((int)$r['case_year'], (int)$r['case_month'])] = true;
        }
    } catch (PDOException $e) {
        error_log('[tradeFyOptions] ' . $e->getMessage());
    }
    $cur = tradeCurrentFy();
    $fys[$cur] = true;             // 今年度は案件が無くても選べるようにする
    $list = array_keys($fys);
    rsort($list);
    return $list;
}

/**
 * その年度に取引がある取引先か（戦略会議の「取引先」と同じ条件）
 *   確定案件 / その年度 / 営業担当が営業マン一覧の人
 * @return array{0:string,1:array} [EXISTS句, 追加パラメータ]
 */
function tradeClientHasCaseSql(int $fy, array $repNames): array {
    if (!$repNames) return ['0', []];   // 営業マンが1人もいなければ該当なし
    $ph  = implode(',', array_fill(0, count($repNames), '?'));
    $sql = "EXISTS (SELECT 1 FROM sales_cases sc
                    LEFT JOIN employees er ON er.id = sc.sales_rep_id AND er.company_id = sc.company_id
                    WHERE sc.company_id = sales_clients.company_id
                      AND sc.client_id  = sales_clients.id
                      AND sc.status = 'confirmed'
                      AND " . TRADE_FY_WHERE . "
                      AND COALESCE(er.name, sc.sales_rep) IN ({$ph}))";
    return [$sql, array_merge([$fy - 1, $fy], $repNames)];
}

/**
 * その年度に取引がある外注先か（戦略会議の「外注先」と同じ条件）
 *   確定案件 / その年度 / スタッフ区分がアライアンス / 管理者が営業マン一覧の人
 * @return array{0:string,1:array} [EXISTS句, 追加パラメータ]
 */
function tradeAllianceHasCaseSql(int $fy, array $repNames): array {
    if (!$repNames) return ['0', []];
    $ph  = implode(',', array_fill(0, count($repNames), '?'));
    $sql = "EXISTS (SELECT 1 FROM sales_cases sc
                    LEFT JOIN employees em ON em.id = sc.manager_id AND em.company_id = sc.company_id
                    WHERE sc.company_id  = sales_alliances.company_id
                      AND sc.alliance_id = sales_alliances.id
                      AND sc.status = 'confirmed'
                      AND sc.worker_type = 'アライアンス'
                      AND " . TRADE_FY_WHERE . "
                      AND COALESCE(em.name, sc.manager) IN ({$ph}))";
    return [$sql, array_merge([$fy - 1, $fy], $repNames)];
}

/**
 * 画面上部に出す件数のまとめ。
 * 合計は「同じ会社の取引先」で名寄せしたうえで重複を除くため、
 * 戦略会議のパートナー数とまったく同じ数字になる。
 *
 * @return array{clients:int, alliances:int, total:int, fy:int, fy_label:string}
 */
function tradeCompanySummary(PDO $db, int $companyId, int $fy, array $repNames): array {
    $clients = 0; $alliances = 0; $keys = [];

    if ($repNames) {
        [$clSql, $clParams] = tradeClientHasCaseSql($fy, $repNames);
        $st = $db->prepare("SELECT id FROM sales_clients
                            WHERE company_id = ? AND is_active = 1 AND {$clSql}");
        $st->execute(array_merge([$companyId], $clParams));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $clients++;
            $keys['C' . (int)$id] = true;
        }

        [$alSql, $alParams] = tradeAllianceHasCaseSql($fy, $repNames);
        $st = $db->prepare("SELECT id, client_id FROM sales_alliances
                            WHERE company_id = ? AND is_active = 1 AND {$alSql}");
        $st->execute(array_merge([$companyId], $alParams));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $alliances++;
            // 同じ会社の取引先が指定されていれば、その取引先と同じ1社として数える
            $linked = (int)($r['client_id'] ?? 0);
            $keys[$linked > 0 ? 'C' . $linked : 'A' . (int)$r['id']] = true;
        }
    }

    return [
        'clients'   => $clients,
        'alliances' => $alliances,
        'total'     => count($keys),
        'fy'        => $fy,
        'fy_label'  => tradeFyLabel($fy),
    ];
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
