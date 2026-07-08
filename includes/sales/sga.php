<?php
// ============================================================
// 販管費管理: CRUD・集計
// ============================================================
// このファイルは includes/sales_functions.php から自動的に読み込まれます
// 直接 require しないでください (sales_functions.php 経由で参照する)

function getSgaExpenses(int $companyId, int $year, int $month): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sga_expenses
        WHERE company_id = ? AND target_year = ? AND target_month = ?
        ORDER BY id DESC');
    $stmt->execute([$companyId, $year, $month]);
    return $stmt->fetchAll();
}

function getSgaExpenseTotal(int $companyId, int $year, int $month): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM sga_expenses
        WHERE company_id = ? AND target_year = ? AND target_month = ?');
    $stmt->execute([$companyId, $year, $month]);
    return (int)$stmt->fetchColumn();
}

function getSgaExpense(int $id, int $companyId): array|false {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM sga_expenses WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch();
}

function createSgaExpense(int $companyId, array $data): int {
    $db = getDB();
    $note    = trim($data['note']    ?? '');
    $content = trim($data['content'] ?? '');
    $stmt = $db->prepare('INSERT INTO sga_expenses
        (company_id, target_year, target_month, category, content, amount, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $companyId,
        (int)$data['target_year'],
        (int)$data['target_month'],
        trim($data['category'] ?? ''),
        $content,
        max(0, (int)($data['amount'] ?? 0)),
        $note !== '' ? $note : null,
    ]);
    return (int)$db->lastInsertId();
}

function updateSgaExpense(int $id, int $companyId, array $data): void {
    $db = getDB();
    $note    = trim($data['note']    ?? '');
    $content = trim($data['content'] ?? '');
    $stmt = $db->prepare('UPDATE sga_expenses SET
        target_year = ?, target_month = ?, category = ?, content = ?, amount = ?, note = ?
        WHERE id = ? AND company_id = ?');
    $stmt->execute([
        (int)$data['target_year'],
        (int)$data['target_month'],
        trim($data['category'] ?? ''),
        $content,
        max(0, (int)($data['amount'] ?? 0)),
        $note !== '' ? $note : null,
        $id, $companyId,
    ]);
}

function getSgaOptions(int $companyId): array {
    $db = getDB();
    $fixed = ['家賃','通信費','水道光熱費','役員報酬','保険料','正社員給与','正社員交通費','経費'];

    $stmt = $db->prepare('SELECT DISTINCT category FROM sga_expenses
        WHERE company_id = ? AND category != "" ORDER BY category');
    $stmt->execute([$companyId]);
    $dbCats = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $extras = array_values(array_diff($dbCats, $fixed));
    $categories = array_merge($fixed, $extras);

    $stmt2 = $db->prepare('SELECT DISTINCT content FROM sga_expenses
        WHERE company_id = ? AND content != "" ORDER BY content');
    $stmt2->execute([$companyId]);
    $contents = $stmt2->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return ['categories' => $categories, 'contents' => $contents];
}

function deleteSgaExpense(int $id, int $companyId): void {
    $db = getDB();
    $db->prepare('DELETE FROM sga_expenses WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
}

// 総合ダッシュボードと同一の売上・粗利（getSalesDashboardKPIs）に
// 販管費合計・営業利益を合わせたサマリーを返す
function getSgaSummary(int $companyId, int $year, int $month): array {
    $kpis = getSalesDashboardKPIs($companyId, $year, $month);
    $sgaTotal = getSgaExpenseTotal($companyId, $year, $month);
    $operatingIncome = $kpis['profit'] - $sgaTotal;
    $operatingMargin = $kpis['revenue'] > 0 ? round($operatingIncome / $kpis['revenue'] * 100, 1) : 0;

    return [
        'year' => $year,
        'month' => $month,
        'revenue' => $kpis['revenue'],
        'profit' => $kpis['profit'],
        'margin' => $kpis['margin'],
        'sga_total' => $sgaTotal,
        'operating_income' => $operatingIncome,
        'operating_margin' => $operatingMargin,
    ];
}
